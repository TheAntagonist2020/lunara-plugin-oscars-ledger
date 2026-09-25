<?php
/**
 * Extract the committed reference names from the audit's SQL dump (plan §4.14 item 1).
 *
 * Reads the ledger_entities INSERTs (imdb_id, kind, name) and the ledger_titles
 * INSERTs (imdb_id, title) of docs/database/data.sql.gz and writes
 * data/ledger/entities.tsv (header imdb_id, kind, reference_name) and
 * data/ledger/titles.tsv (header imdb_id, reference_title), sorted by imdb_id.
 * Every other column of those INSERTs is discarded. It ran once, before
 * tests/tools/strip-reference-columns.php, and is kept for reproducibility: from
 * then on the bundle builder maintains the two files.
 *
 * Usage:
 *   php tests/tools/extract-reference-names.php [--dump=<path> | --rev=<git-rev>] [--force]
 *       Write the two TSV files. Refuses to overwrite them without --force.
 *   php tests/tools/extract-reference-names.php --verify [--dump=<path> | --rev=<git-rev>]
 *       Compare the committed TSV files with the dump, row by row; exit 0 when every
 *       row equals its INSERT and the row counts equal the INSERT counts.
 *
 * --rev reads docs/database/data.sql.gz from a git revision (for example the commit
 * before the strip) instead of the working tree. Nothing here is deployed.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "extract-reference-names must run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/lib/redact-evidence.php';

$root = dirname(__DIR__, 2);
$options = array('verify' => false, 'force' => false, 'dump' => null, 'rev' => null);
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--verify') {
        $options['verify'] = true;
    } elseif ($arg === '--force') {
        $options['force'] = true;
    } elseif (strpos($arg, '--dump=') === 0) {
        $options['dump'] = substr($arg, 7);
    } elseif (strpos($arg, '--rev=') === 0) {
        $options['rev'] = substr($arg, 6);
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\nUsage: php tests/tools/extract-reference-names.php [--verify] [--dump=<path> | --rev=<git-rev>] [--force]\n");
        exit(2);
    }
}
if ($options['dump'] !== null && $options['rev'] !== null) {
    fwrite(STDERR, "Pass --dump or --rev, not both.\n");
    exit(2);
}

const ENTITIES_HEADER = array('imdb_id', 'kind', 'reference_name');
const TITLES_HEADER = array('imdb_id', 'reference_title');

try {
    if ($options['dump'] !== null) {
        $bytes = is_file($options['dump']) ? file_get_contents($options['dump']) : false;
        $source_label = $options['dump'];
    } else {
        $bytes = ledger_read_repo_file($root, 'docs/database/data.sql.gz', $options['rev']);
        $source_label = ($options['rev'] !== null ? $options['rev'] . ':' : '') . 'docs/database/data.sql.gz';
    }
    if (!is_string($bytes)) {
        throw new RuntimeException("Cannot read {$source_label}");
    }
    $sql = ledger_gzdecode_or_fail($bytes, $source_label);

    $entities = array();
    $titles = array();
    $insert_counts = array('ledger_entities' => 0, 'ledger_titles' => 0);
    foreach (explode("\n", $sql) as $line) {
        if (strpos($line, 'INSERT INTO ledger_entities ') !== 0 && strpos($line, 'INSERT INTO ledger_titles ') !== 0) {
            continue;
        }
        $insert = ledger_sql_parse_insert($line);
        if ($insert === null) {
            throw new RuntimeException('Unparsed line: ' . substr($line, 0, 80));
        }
        foreach ($insert['rows'] as $row) {
            $insert_counts[$insert['table']]++;
            $minimum = count(Ledger_Redaction::REFERENCE_COLUMNS[$insert['table']]);
            if (count($row) < $minimum) {
                throw new RuntimeException("INSERT INTO {$insert['table']} row with fewer than {$minimum} values");
            }
            foreach (array_slice($row, 0, $minimum) as $cell) {
                if (!$cell['quoted'] || !is_string($cell['value'])) {
                    throw new RuntimeException("INSERT INTO {$insert['table']}: a reference value is not a quoted string");
                }
                if (preg_match('~[\t\r\n]~', $cell['value'])) {
                    throw new RuntimeException("INSERT INTO {$insert['table']}: a value contains a tab or a line break, which an unquoted TSV cannot hold");
                }
            }
            $id = $row[0]['value'];
            if ($insert['table'] === 'ledger_entities') {
                $kind = $row[1]['value'];
                $expected_kind = strpos($id, 'nm') === 0 ? 'person' : 'company';
                if (!preg_match('~^(nm|co)\d{7,8}$~', $id) || $kind !== $expected_kind) {
                    throw new RuntimeException("Unexpected entity row {$id} ({$kind})");
                }
                if (isset($entities[$id])) {
                    throw new RuntimeException("Duplicate entity {$id}");
                }
                $entities[$id] = array($id, $kind, $row[2]['value']);
            } else {
                if (!preg_match('~^tt\d{7,8}$~', $id)) {
                    throw new RuntimeException("Unexpected title row {$id}");
                }
                if (isset($titles[$id])) {
                    throw new RuntimeException("Duplicate title {$id}");
                }
                $titles[$id] = array($id, $row[1]['value']);
            }
        }
    }
    uksort($entities, 'strcmp');
    uksort($titles, 'strcmp');

    echo "Read {$source_label}: {$insert_counts['ledger_entities']} ledger_entities rows, {$insert_counts['ledger_titles']} ledger_titles rows.\n";

    $targets = array(
        'data/ledger/entities.tsv' => array(ENTITIES_HEADER, $entities, $insert_counts['ledger_entities']),
        'data/ledger/titles.tsv' => array(TITLES_HEADER, $titles, $insert_counts['ledger_titles']),
    );

    if (!$options['verify']) {
        foreach ($targets as $relpath => $target) {
            if (is_file($root . '/' . $relpath) && !$options['force']) {
                throw new RuntimeException("{$relpath} exists; the bundle builder maintains it now. Pass --force to overwrite.");
            }
        }
        if (!is_dir($root . '/data/ledger') && !mkdir($root . '/data/ledger', 0755, true)) {
            throw new RuntimeException('Cannot create data/ledger');
        }
        foreach ($targets as $relpath => $target) {
            list($header, $rows) = $target;
            $out = implode("\t", $header) . "\n";
            foreach ($rows as $row) {
                $out .= implode("\t", $row) . "\n";
            }
            if (file_put_contents($root . '/' . $relpath, $out) !== strlen($out)) {
                throw new RuntimeException("Cannot write {$relpath}");
            }
            echo "Wrote {$relpath}: " . count($rows) . " rows.\n";
        }
        exit(0);
    }

    $failures = array();
    foreach ($targets as $relpath => $target) {
        list($header, $rows, $insert_count) = $target;
        $content = ledger_read_repo_file($root, $relpath);
        if ($content === null) {
            $failures[] = "{$relpath} is missing";
            continue;
        }
        if ($content === '' || substr($content, -1) !== "\n") {
            $failures[] = "{$relpath} must end with a newline";
        }
        $lines = explode("\n", rtrim($content, "\n"));
        $first = array_shift($lines);
        if ($first !== implode("\t", $header)) {
            $failures[] = "{$relpath} header is not exactly " . implode('\\t', $header);
        }
        if (count($lines) !== $insert_count) {
            $failures[] = "{$relpath} has " . count($lines) . " rows; the dump has {$insert_count} INSERT rows";
        }
        $seen = array();
        foreach ($lines as $k => $line) {
            $cells = explode("\t", $line);
            if (count($cells) !== count($header)) {
                $failures[] = "{$relpath} row " . ($k + 2) . ' has ' . count($cells) . ' cells';
                continue;
            }
            $id = $cells[0];
            $seen[$id] = true;
            if (!isset($rows[$id])) {
                $failures[] = "{$relpath}: {$id} is not in the dump";
                continue;
            }
            if ($cells !== $rows[$id]) {
                $failures[] = "{$relpath}: {$id} differs from the dump";
            }
        }
        foreach (array_keys($rows) as $id) {
            if (!isset($seen[$id])) {
                $failures[] = "{$relpath}: {$id} from the dump is missing";
            }
        }
        echo "Checked {$relpath}: " . count($lines) . " rows against {$insert_count} INSERT rows.\n";
    }
    if ($failures) {
        fwrite(STDERR, "Reference-name verification FAILED:\n- " . implode("\n- ", array_slice($failures, 0, 50)) . "\n");
        exit(1);
    }
    echo "Reference names verified: every row equals its dump value.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'extract-reference-names: ' . $e->getMessage() . "\n");
    exit(1);
}
