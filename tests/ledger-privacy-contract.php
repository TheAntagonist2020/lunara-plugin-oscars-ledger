<?php
/**
 * Public-repository privacy contract (plan §4.14 item 8; work unit U00).
 *
 * The plugin repository is public. No tracked file may carry Wikidata QIDs, birth
 * years or life spans taken from Wikidata:
 *   (a) the forbidden column names appear only in the tests that name them as
 *       forbidden, the R3 API verifier, and the committed plan;
 *   (b) no Wikidata link and no Q-number appears anywhere outside the kept audit
 *       scripts, the redactor, this test and the bundle contract;
 *   (c) redact_evidence() is the identity on every evidence-bearing value
 *       (Ledger_Redaction::REDACT_KEYS), on every Markdown file under docs/database/
 *       and docs/design/ledger-2.8/, and on the evidence of the dump's
 *       ledger_corrections lines. Dataset values (before, after, appended row cells,
 *       labels, the dump's nomination rows) are the Academy's text and out of scope;
 *   (d) the dump's reference rows carry only (imdb_id, title) and
 *       (imdb_id, kind, name).
 * Plus: redact_evidence() unit cases, the schema's reference columns, the audit
 * labels, the reference-name TSV headers and IDs, and in-test mutations on
 * temporary copies.
 *
 * Usage: php tests/ledger-privacy-contract.php [--root=<tree>]
 * Files: `git ls-files` (tracked plus untracked, not ignored) when git is available,
 * else a walk of the tree excluding .git, node_modules and vendor. *.gz files are
 * scanned after gzdecode, the xl/**.xml parts of *.xlsx through ZipArchive (required
 * when CI is set); images and fonts are skipped and listed.
 */

require_once __DIR__ . '/tools/lib/redact-evidence.php';

$root = dirname(__DIR__);
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--root=') === 0) {
        $root = rtrim(substr($arg, 7), '/');
    }
}

const PRIVACY_COLUMN_PATTERN = '~wikidata_qid|birth_year|wikidata_birth_year|wikidata_release_year~';
const PRIVACY_COLUMN_EXEMPT = array(
    'tests/ledger-privacy-contract.php',
    'tests/ledger-ddl-contract.php',
    'tests/ledger-api-contract.php',
    'tests/ledger-json-schema-contract.php',
    'tests/ledger-serializer-runtime.php',
    'tools/verify-ledger-api.php', // plan-v5 errata E5: the R3 verifier asserts no response carries the key
    'docs/design/ledger-2.8/*.md',
);
const PRIVACY_QID_PATTERNS = array('~wikidata\.org/~', '~\bWikidata\s+Q\d+~', '~\bQ\d{2,}\b~');
const PRIVACY_QID_EXEMPT = array(
    'docs/database/tools/*.py',
    'tests/tools/lib/redact-evidence.php',
    'tests/ledger-privacy-contract.php',
    'tests/ledger-bundle-contract.php', // plan-v5 errata E5: its mutations append a Wikidata URL to copied evidence
);
const PRIVACY_SKIP_EXTENSIONS = array('png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'bmp', 'tif', 'tiff', 'woff', 'woff2', 'ttf', 'otf', 'eot');
const PRIVACY_REDACT_ROOTS = array('docs/database/', 'data/ledger/');
const PRIVACY_MARKDOWN_ROOTS = array('docs/database/', 'docs/design/ledger-2.8/');

$failures = array();
$assert = function ($condition, string $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

function privacy_matches_any(string $relpath, array $globs): bool
{
    foreach ($globs as $glob) {
        if (fnmatch($glob, $relpath, FNM_PATHNAME)) {
            return true;
        }
    }
    return false;
}

/** @return array{0: array<int, string>, 1: string} */
function privacy_list_files(string $root): array
{
    if (file_exists($root . '/.git')) {
        $cmd = 'git -C ' . escapeshellarg($root) . ' ls-files -z --cached --others --exclude-standard 2>/dev/null';
        $out = shell_exec($cmd);
        if (is_string($out) && $out !== '') {
            $files = array();
            foreach (explode("\0", $out) as $relpath) {
                if ($relpath !== '' && is_file($root . '/' . $relpath)) {
                    $files[$relpath] = true;
                }
            }
            $files = array_keys($files);
            sort($files, SORT_STRING);
            return array($files, 'git ls-files');
        }
    }
    $files = array();
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            function ($current) {
                return !($current->isDir() && in_array($current->getFilename(), array('.git', 'node_modules', 'vendor'), true));
            }
        )
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
    sort($files, SORT_STRING);
    return array($files, 'tree walk');
}

/**
 * The scannable parts of one file: array(label, content).
 *
 * @return array<int, array{0: string, 1: string}>
 */
function privacy_file_parts(string $root, string $relpath, array &$skipped, array &$failures): array
{
    $extension = strtolower(pathinfo($relpath, PATHINFO_EXTENSION));
    if (in_array($extension, PRIVACY_SKIP_EXTENSIONS, true)) {
        $skipped[] = $relpath;
        return array();
    }
    $bytes = file_get_contents($root . '/' . $relpath);
    if (!is_string($bytes)) {
        $failures[] = "{$relpath} cannot be read";
        return array();
    }
    if ($extension === 'gz') {
        $decoded = @gzdecode($bytes);
        if (!is_string($decoded)) {
            $failures[] = "{$relpath} does not gzdecode";
            return array();
        }
        return array(array($relpath, $decoded));
    }
    if ($extension === 'xlsx') {
        if (!class_exists('ZipArchive')) {
            if (getenv('CI')) {
                $failures[] = "{$relpath}: ZipArchive is missing, so the workbook cannot be scanned (required when CI is set)";
            } else {
                $skipped[] = "{$relpath} (ZipArchive missing; scanned only where the zip extension is loaded)";
            }
            return array();
        }
        $zip = new ZipArchive();
        if ($zip->open($root . '/' . $relpath) !== true) {
            $failures[] = "{$relpath} does not open as a zip archive";
            return array();
        }
        $parts = array();
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('~^xl/.+\.xml$~', $name)) {
                $parts[] = array("{$relpath}:{$name}", (string) $zip->getFromIndex($i));
            }
        }
        $zip->close();
        if (!$parts) {
            $failures[] = "{$relpath} has no xl/*.xml parts";
        }
        return $parts;
    }
    return array(array($relpath, $bytes));
}

/** Line numbers (1-based) of up to three matches of a pattern. */
function privacy_match_lines(string $pattern, string $content): string
{
    $count = preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);
    if (!$count) {
        return '';
    }
    $lines = array();
    foreach (array_slice($matches[0], 0, 3) as $match) {
        $lines[] = substr_count($content, "\n", 0, $match[1]) + 1;
    }
    return "{$count} match(es), first at line(s) " . implode(', ', $lines);
}

/** The relative name of an authored JSON file inside a REDACT_KEYS root, or null. */
function privacy_redact_relname(string $relpath): ?string
{
    foreach (PRIVACY_REDACT_ROOTS as $prefix) {
        if (strpos($relpath, $prefix) === 0) {
            $relname = substr($relpath, strlen($prefix));
            if (substr($relname, -5) === '.json' && isset(Ledger_Redaction::REDACT_KEYS[$relname])) {
                return $relname;
            }
        }
    }
    return null;
}

/**
 * Every rule over the given files of a tree. Returns the failures; $skipped receives
 * the skipped files.
 *
 * @param array<int, string> $files
 * @return array<int, string>
 */
function privacy_check_files(string $root, array $files, array &$skipped): array
{
    $failures = array();
    $schema_path = $root . '/docs/database/schema.sql';
    $schema_columns = is_file($schema_path) ? ledger_schema_columns((string) file_get_contents($schema_path)) : array();
    $dump_ids = null;

    foreach ($files as $relpath) {
        $parts = privacy_file_parts($root, $relpath, $skipped, $failures);
        foreach ($parts as $part) {
            list($label, $content) = $part;

            // (a) forbidden column names.
            if (!privacy_matches_any($relpath, PRIVACY_COLUMN_EXEMPT)) {
                $hit = preg_match(PRIVACY_COLUMN_PATTERN, $content);
                if ($hit === false) {
                    $failures[] = "{$label}: column-name scan failed (" . preg_last_error_msg() . ')';
                } elseif ($hit) {
                    $failures[] = "{$label} names a removed Wikidata or birth-year column: " . privacy_match_lines(PRIVACY_COLUMN_PATTERN, $content);
                }
            }

            // (b) Wikidata links and Q-numbers.
            if (!privacy_matches_any($relpath, PRIVACY_QID_EXEMPT)) {
                foreach (PRIVACY_QID_PATTERNS as $pattern) {
                    $hit = preg_match($pattern, $content);
                    if ($hit === false) {
                        $failures[] = "{$label}: {$pattern} scan failed (" . preg_last_error_msg() . ')';
                    } elseif ($hit) {
                        $failures[] = "{$label} matches {$pattern}: " . privacy_match_lines($pattern, $content);
                    }
                }
            }

            // (c) evidence-bearing JSON values.
            $relname = privacy_redact_relname($relpath);
            if ($relname !== null) {
                $data = json_decode($content, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $failures[] = "{$label} is not valid JSON";
                } else {
                    foreach (Ledger_Redaction::REDACT_KEYS[$relname] as $selector) {
                        $copy = $data;
                        foreach (ledger_json_transform($copy, $selector, 'redact_evidence') as $change) {
                            $failures[] = "{$label}: {$change[0]} is not redacted (Wikidata link, Q-number or year span)";
                        }
                    }
                    foreach (Ledger_Redaction::AUDIT_LABELS[$relname] ?? array() as $selector) {
                        $labels = array();
                        if ($selector === '(keys)') {
                            $labels = is_array($data) ? array_map('strval', array_keys($data)) : array();
                        } else {
                            foreach (ledger_json_select($data, $selector) as $found) {
                                $labels[] = is_string($found[1]) ? $found[1] : '';
                            }
                        }
                        foreach ($labels as $audit_label) {
                            if (preg_match('~^Q\d~', $audit_label)) {
                                $failures[] = "{$label}: an audit review-item label still begins with Q (rename it to R)";
                            }
                        }
                    }
                }
            }

            // (c) Markdown under docs/database/ and docs/design/ledger-2.8/.
            if (substr($relpath, -3) === '.md') {
                foreach (PRIVACY_MARKDOWN_ROOTS as $prefix) {
                    if (strpos($relpath, $prefix) === 0 && redact_evidence($content) !== $content) {
                        $before = explode("\n", $content);
                        $after = explode("\n", redact_evidence($content));
                        $lines = array_keys(array_diff_assoc($before, $after));
                        $failures[] = "{$label} carries a Wikidata link, Q-number or year span on line(s) " . implode(', ', array_map(function ($i) {
                            return $i + 1;
                        }, array_slice($lines, 0, 5)));
                    }
                }
            }

            // (c) and (d) SQL dumps.
            $is_sql = preg_match('~\.sql(\.gz)?$~', $relpath) === 1;
            if ($is_sql && strpos($content, 'INSERT INTO ') !== false) {
                $ids = array();
                foreach (explode("\n", $content) as $n => $line) {
                    if (strpos($line, 'INSERT INTO ledger_') !== 0) {
                        continue;
                    }
                    try {
                        $insert = ledger_sql_parse_insert($line);
                        if ($insert === null) {
                            continue;
                        }
                        if (isset(Ledger_Redaction::REFERENCE_COLUMNS[$insert['table']])) {
                            $max = count(Ledger_Redaction::REFERENCE_COLUMNS[$insert['table']]);
                            foreach ($insert['rows'] as $row) {
                                if (count($row) > $max) {
                                    $failures[] = "{$label} line " . ($n + 1) . ": INSERT INTO {$insert['table']} row has " . count($row) . " values (at most {$max})";
                                }
                                if (isset($row[0]) && is_string($row[0]['value'])) {
                                    $ids[$row[0]['value']] = true;
                                }
                            }
                        }
                        $changes = array();
                        ledger_sql_redact_line($line, $schema_columns, $changes);
                        foreach ($changes as $change) {
                            $failures[] = "{$label} line " . ($n + 1) . ": the {$change[0]} value of INSERT INTO {$insert['table']} is not redacted";
                        }
                    } catch (Throwable $e) {
                        $failures[] = "{$label} line " . ($n + 1) . ': ' . $e->getMessage();
                    }
                }
                if ($relpath === 'docs/database/data.sql.gz') {
                    $dump_ids = $ids;
                }
            }
        }
    }

    // Schema: the reference tables carry no Wikidata or year columns.
    if (in_array('docs/database/schema.sql', $files, true) && is_file($schema_path)) {
        $schema = (string) file_get_contents($schema_path);
        if (preg_match('~wikidata_qid|birth_year|release_year~', $schema)) {
            $failures[] = 'docs/database/schema.sql still defines a Wikidata, birth-year or release-year column';
        }
        foreach (Ledger_Redaction::REFERENCE_COLUMNS as $table => $keep) {
            if (isset($schema_columns[$table]) && $schema_columns[$table] !== $keep) {
                $failures[] = "docs/database/schema.sql: {$table} columns are " . implode(', ', $schema_columns[$table]) . '; expected ' . implode(', ', $keep);
            }
        }
    }

    // Reference-name TSVs: exact headers; every ID is in the dump or has an empty name.
    $headers = array('data/ledger/entities.tsv' => "imdb_id\tkind\treference_name", 'data/ledger/titles.tsv' => "imdb_id\treference_title");
    foreach ($headers as $relpath => $header) {
        if (!in_array($relpath, $files, true)) {
            continue;
        }
        $lines = explode("\n", rtrim((string) file_get_contents($root . '/' . $relpath), "\n"));
        if ($lines[0] !== $header) {
            $failures[] = "{$relpath}: the header is not exactly " . str_replace("\t", '\t', $header);
        }
        if ($dump_ids === null) {
            continue;
        }
        $missing = 0;
        foreach (array_slice($lines, 1) as $line) {
            $cells = explode("\t", $line);
            $name = end($cells);
            if (!isset($dump_ids[$cells[0]]) && $name !== '') {
                $missing++;
            }
        }
        if ($missing) {
            $failures[] = "{$relpath}: {$missing} IDs with a reference name are not in docs/database/data.sql.gz";
        }
    }

    return $failures;
}

// ---------------------------------------------------------------------------
// (2) redact_evidence() unit cases on synthetic strings.
// ---------------------------------------------------------------------------
$years = Ledger_Redaction::YEARS_OMITTED;
$cases = array(
    array('see https://www.wikidata.org/wiki/Q98765 for the label', 'see Wikidata for the label'),
    array('https://wikidata.org/wiki/Q98765: a company', 'Wikidata a company'),
    array('fetched Special:EntityData/Q98765.json today', 'fetched Special:EntityData/.json today'),
    array('the Wikidata Q98765 item', 'the Wikidata item'),
    array('part of the series Q98765, first aired', 'part of the series, first aired'),
    array('A Company (Wikidata Q98765, 1801-1899, P345 = co0000001)', "A Company (Wikidata, {$years}, P345 = co0000001)"),
    array('Somebody (1801-1899) was credited', "Somebody {$years} was credited"),
    array('(1801-1899)', $years),
    array('(1801 – 1899)', $years),
    array('(1950–)', $years),
    array('(b. 1966)', "({$years})"),
    array('b. 1966', $years),
    array('died 1974', $years),
    array('who died in 1974.', "who {$years}."),
    array('born 1901 in Ohio', "{$years} in Ohio"),
    array('1801-1899', $years),
    array('main:1668-1690', 'main:1668-1690'),
    array('1932/33', '1932/33'),
    array('1927/28', '1927/28'),
    array('https://www.oscars.org/oscars/ceremonies/1937', 'https://www.oscars.org/oscars/ceremonies/1937'),
    array('row 526', 'row 526'),
    array('a run of  two spaces', 'a run of  two spaces'),
    array('    an indented Markdown line', '    an indented Markdown line'),
    array('the 1950 ceremony, 97th overall', 'the 1950 ceremony, 97th overall'),
    array('1983-84 and 1968-71', '1983-84 and 1968-71'),
    array('IMDb https://www.imdb.com/title/tt0000001/ and Wikipedia', 'IMDb https://www.imdb.com/title/tt0000001/ and Wikipedia'),
);
foreach ($cases as $case) {
    list($input, $expected) = $case;
    $got = redact_evidence($input);
    $assert($got === $expected, "redact_evidence('{$input}') gave '{$got}', expected '{$expected}'");
    $assert(redact_evidence($got) === $got, "redact_evidence is not idempotent on '{$input}'");
}

// The codec repeats the tables verbatim (from U01 on).
$codec = $root . '/includes/class-aat-ledger-source.php';
if (is_file($codec)) {
    require_once $codec;
    if (class_exists('AAT_Ledger_Source', false)) {
        $assert(defined('AAT_Ledger_Source::PRIVACY_PATTERNS') && AAT_Ledger_Source::PRIVACY_PATTERNS === Ledger_Redaction::PRIVACY_PATTERNS, 'AAT_Ledger_Source::PRIVACY_PATTERNS must equal the pattern list of tests/tools/lib/redact-evidence.php');
        $assert(defined('AAT_Ledger_Source::REDACT_KEYS') && AAT_Ledger_Source::REDACT_KEYS === Ledger_Redaction::REDACT_KEYS, 'AAT_Ledger_Source::REDACT_KEYS must equal Ledger_Redaction::REDACT_KEYS');
    }
}

// ---------------------------------------------------------------------------
// (1) The tree.
// ---------------------------------------------------------------------------
list($files, $listing) = privacy_list_files($root);
$assert(count($files) > 0, 'No files found to scan');
$skipped = array();
$failures = array_merge($failures, privacy_check_files($root, $files, $skipped));
$assert(in_array('docs/database/data.sql.gz', $files, true), 'docs/database/data.sql.gz should be scanned');
foreach (array('data/ledger/entities.tsv', 'data/ledger/titles.tsv') as $required) {
    $assert(in_array($required, $files, true), "{$required} should exist: it is the committed reference-name source");
}

// ---------------------------------------------------------------------------
// (9) Mutations on temporary copies.
// ---------------------------------------------------------------------------
$tmp_base = rtrim(sys_get_temp_dir(), '/') . '/ledger-privacy-' . getmypid() . '-' . bin2hex(random_bytes(4));
$tmp_dirs = array();
$make_copy = function (array $relpaths) use ($root, $tmp_base, &$tmp_dirs) {
    $dir = $tmp_base . '-' . count($tmp_dirs);
    $tmp_dirs[] = $dir;
    foreach ($relpaths as $relpath) {
        if (!is_dir(dirname($dir . '/' . $relpath))) {
            mkdir(dirname($dir . '/' . $relpath), 0700, true);
        }
        if (is_file($root . '/' . $relpath)) {
            copy($root . '/' . $relpath, $dir . '/' . $relpath);
        }
    }
    return $dir;
};
$check_copy = function (string $dir, array $relpaths) {
    $ignored = array();
    return privacy_check_files($dir, $relpaths, $ignored);
};
$edit_json = function (string $dir, string $relpath, callable $edit) {
    $data = json_decode((string) file_get_contents($dir . '/' . $relpath), true);
    $edit($data);
    file_put_contents($dir . '/' . $relpath, ledger_encode_shipped($data));
};

// Source row 11336's Note, read from the upstream CSV: the Academy's own text, which
// carries a life span in parentheses.
$note_11336 = null;
$csv_lines = @file($root . '/data/oscars.csv', FILE_IGNORE_NEW_LINES);
if (is_array($csv_lines) && isset($csv_lines[11336])) {
    $header = str_getcsv($csv_lines[0], "\t", '"', '');
    $cells = str_getcsv($csv_lines[11336], "\t", '"', '');
    $note_at = array_search('Note', $header, true);
    $note_11336 = ($note_at !== false && isset($cells[$note_at])) ? $cells[$note_at] : null;
}
$assert(is_string($note_11336) && $note_11336 !== '' && redact_evidence($note_11336) !== $note_11336, "source_row 11336's Note should exist and match the year-span patterns (the scope case would be vacuous otherwise)");

$wikidata_url = 'https://www.wikidata.org/wiki/Q98765';
$mutations = array(
    array('entities.tsv row whose reference_name is a bare Q-number', 'fail', array('data/ledger/entities.tsv'), function ($dir) {
        $lines = explode("\n", (string) file_get_contents($dir . '/data/ledger/entities.tsv'));
        $cells = explode("\t", $lines[1]);
        $cells[2] = 'Q' . '42';
        $lines[1] = implode("\t", $cells);
        file_put_contents($dir . '/data/ledger/entities.tsv', implode("\n", $lines));
    }),
    array('Wikidata URL appended to a corrections.json evidence', 'fail', array('docs/database/corrections.json'), function ($dir) use ($edit_json, $wikidata_url) {
        $edit_json($dir, 'docs/database/corrections.json', function (&$data) use ($wikidata_url) {
            $data[0]['evidence'] .= ' ' . $wikidata_url;
        });
    }),
    array("'(1801-1899)' appended to a needs-review.json question", 'fail', array('docs/database/needs-review.json'), function ($dir) use ($edit_json) {
        $edit_json($dir, 'docs/database/needs-review.json', function (&$data) {
            $data[0]['question'] .= ' (1801-1899)';
        });
    }),
    array('audit label reverted to its Q form in tools/editor_decisions.json', 'fail', array('docs/database/tools/editor_decisions.json'), function ($dir) use ($edit_json) {
        $edit_json($dir, 'docs/database/tools/editor_decisions.json', function (&$data) {
            foreach ($data as $k => $decision) {
                if (!empty($decision['items'])) {
                    $data[$k]['items'][0] = preg_replace('~^R~', 'Q', $decision['items'][0]);
                    return;
                }
            }
        });
    }),
    array('audit label reverted to its Q form in a tools/adjudications.json key', 'fail', array('docs/database/tools/adjudications.json'), function ($dir) use ($edit_json) {
        $edit_json($dir, 'docs/database/tools/adjudications.json', function (&$data) {
            $out = array();
            $done = false;
            foreach ($data as $key => $value) {
                if (!$done && preg_match('~^R\d~', $key)) {
                    $key = 'Q' . substr($key, 1);
                    $done = true;
                }
                $out[$key] = $value;
            }
            $data = $out;
        });
    }),
    array("correction whose before and after carry source_row 11336's Note verbatim", 'pass', array('docs/database/corrections.json'), function ($dir) use ($edit_json, $note_11336) {
        $edit_json($dir, 'docs/database/corrections.json', function (&$data) use ($note_11336) {
            $data[0]['before'] = (string) $note_11336;
            $data[0]['after'] = (string) $note_11336;
        });
    }),
    array("source_row 11336's Note placed in a correction's evidence", 'fail', array('docs/database/corrections.json'), function ($dir) use ($edit_json, $note_11336) {
        $edit_json($dir, 'docs/database/corrections.json', function (&$data) use ($note_11336) {
            $data[0]['evidence'] .= ' ' . $note_11336;
        });
    }),
    array('addition whose Citation holds a parenthesized year range', 'pass', array('docs/database/additions.json'), function ($dir) use ($edit_json) {
        $edit_json($dir, 'docs/database/additions.json', function (&$data) {
            $data[0]['row']['Citation'] = (string) $data[0]['row']['Citation'] . ' (1801-1899)';
        });
    }),
    array('addition whose evidence holds a parenthesized year range', 'fail', array('docs/database/additions.json'), function ($dir) use ($edit_json) {
        $edit_json($dir, 'docs/database/additions.json', function (&$data) {
            $data[0]['evidence'] .= ' (1801-1899)';
        });
    }),
    array('dump with a five-value ledger_entities row', 'fail', array('docs/database/data.sql.gz'), function ($dir) {
        file_put_contents($dir . '/docs/database/data.sql.gz', gzencode("INSERT INTO ledger_entities VALUES ('nm0000001','person','A Name',NULL,NULL);\n", 9));
    }),
    array('dump with an unredacted ledger_corrections evidence', 'fail', array('docs/database/data.sql.gz', 'docs/database/schema.sql'), function ($dir) {
        $line = 'INSERT INTO ledger_corrections (nomination_id, field, before_value, after_value, reason, evidence, verification) VALUES '
            . "(1,'Note','a','b','wrong_id'," . ledger_sql_quote('Somebody (1801-1899)') . ",'rule');\n";
        file_put_contents($dir . '/docs/database/data.sql.gz', gzencode($line, 9));
    }),
    array("dump whose ledger_nominations row carries source_row 11336's Note", 'pass', array('docs/database/data.sql.gz'), function ($dir) use ($note_11336) {
        $line = 'INSERT INTO ledger_nominations VALUES (11336,1,1,' . "'X',0,1,NULL,NULL," . ledger_sql_quote((string) $note_11336) . ",NULL);\n";
        file_put_contents($dir . '/docs/database/data.sql.gz', gzencode($line, 9));
    }),
);
$untouched = array('data/ledger/entities.tsv', 'data/ledger/titles.tsv', 'docs/database/corrections.json', 'docs/database/additions.json', 'docs/database/needs-review.json', 'docs/database/tools/editor_decisions.json', 'docs/database/tools/adjudications.json');
try {
    $dir = $make_copy($untouched);
    $got = $check_copy($dir, $untouched);
    $assert($got === array(), 'The untouched copies should pass: ' . implode('; ', $got));
    foreach ($mutations as $mutation) {
        list($name, $expect, $relpaths, $mutate) = $mutation;
        $dir = $make_copy($relpaths);
        $mutate($dir);
        $got = $check_copy($dir, $relpaths);
        if ($expect === 'fail') {
            $assert($got !== array(), "Mutation should fail the contract: {$name}");
        } else {
            $assert($got === array(), "Mutation should pass (dataset values are out of scope): {$name}: " . implode('; ', $got));
        }
    }
} finally {
    foreach ($tmp_dirs as $dir) {
        if (is_dir($dir)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($dir);
        }
    }
}

if ($skipped) {
    echo 'Skipped (images, fonts' . (class_exists('ZipArchive') ? '' : ', workbooks without ZipArchive') . '): ' . implode(', ', $skipped) . "\n";
}
if ($failures) {
    $shown = array_slice($failures, 0, 150);
    $more = count($failures) - count($shown);
    fwrite(STDERR, "Ledger privacy contract FAILED:\n- " . implode("\n- ", $shown) . "\n" . ($more > 0 ? "… and {$more} more\n" : ''));
    exit(1);
}
echo 'Ledger privacy contract OK: ' . count($files) . " files ({$listing}), " . count($cases) . ' redaction cases, ' . count($mutations) . " mutations.\n";
