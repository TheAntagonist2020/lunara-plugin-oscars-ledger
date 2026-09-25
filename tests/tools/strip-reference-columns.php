<?php
/**
 * One-time privacy rewrite of the audit's public files (plan §4.14 items 2, 3 and 6).
 *
 * - docs/database/data.sql.gz: every ledger_titles INSERT keeps (imdb_id, title) and
 *   every ledger_entities INSERT keeps (imdb_id, kind, name); every other value of
 *   those rows is dropped. The evidence-bearing values of the ledger_corrections
 *   INSERTs go through redact_evidence(). No other statement changes. The output is
 *   gzencode($sql, 9).
 * - docs/database/schema.sql: the two tables keep only those columns; every other
 *   line stays byte-identical.
 * - The authored JSON files: their REDACT_KEYS values go through redact_evidence(),
 *   the audit review-item labels of tools/*.json are renamed from Q to R, and a file
 *   that changed is rewritten in the shipped JSON layout. Dataset values (before,
 *   after, row cells, labels, slots, cells) are never touched.
 * - docs/database/AUDIT-REPORT.md: redacted as a whole text.
 *
 * Run tests/tools/extract-reference-names.php first. From U03 on the bundle builder
 * regenerates data.sql.gz from the ledger; this tool is kept for reproducibility.
 *
 * Usage:
 *   php tests/tools/strip-reference-columns.php
 *       Rewrite the files in place (idempotent) and print the counts.
 *   php tests/tools/strip-reference-columns.php --verify-redaction [--before=<git-rev>]
 *       Compare the working tree with the files of <git-rev> (default HEAD): every
 *       change must be exactly the rewrite above. Exit 0 when it is.
 *   php tests/tools/strip-reference-columns.php --expected-dump [--before=<git-rev>]
 *       Print the SQL the rewrite makes from <git-rev>'s dump, for
 *       diff <(php … --expected-dump --before=<rev>) <(zcat docs/database/data.sql.gz)
 *
 * Nothing here is deployed.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "strip-reference-columns must run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/lib/redact-evidence.php';

$root = dirname(__DIR__, 2);
$mode = 'rewrite';
$before_rev = 'HEAD';
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--verify-redaction') {
        $mode = 'verify';
    } elseif ($arg === '--expected-dump') {
        $mode = 'expected-dump';
    } elseif (strpos($arg, '--before=') === 0 && strlen($arg) > 9) {
        $before_rev = substr($arg, 9);
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\nUsage: php tests/tools/strip-reference-columns.php [--verify-redaction | --expected-dump] [--before=<git-rev>]\n");
        exit(2);
    }
}

const DUMP = 'docs/database/data.sql.gz';
const SCHEMA = 'docs/database/schema.sql';
const AUDIT_REPORT = 'docs/database/AUDIT-REPORT.md';
const AUTHORED_JSON = array(
    'corrections.json',
    'additions.json',
    'needs-review.json',
    'accepted-drift.json',
    'tools/adjudications.json',
    'tools/editor_decisions.json',
);

/**
 * The rewrite of one dump text.
 *
 * @param array<string, array<int, string>> $schema_columns column order of the dump's own schema
 * @return array{sql: string, counts: array<string, int>}
 */
function strip_dump(string $sql, array $schema_columns): array
{
    $counts = array(
        'ledger_entities rows' => 0,
        'ledger_titles rows' => 0,
        'ledger_entities rows stripped' => 0,
        'ledger_titles rows stripped' => 0,
        'ledger_corrections lines' => 0,
        'ledger_corrections evidence values redacted' => 0,
        'ledger_corrections verification values redacted' => 0,
        'other lines (unchanged)' => 0,
    );
    $out = array();
    foreach (explode("\n", $sql) as $line) {
        $is_reference = false;
        foreach (Ledger_Redaction::REFERENCE_COLUMNS as $table => $keep) {
            if (strpos($line, "INSERT INTO {$table} ") === 0) {
                $is_reference = $table;
            }
        }
        if ($is_reference !== false) {
            $insert = ledger_sql_parse_insert($line);
            $keep = Ledger_Redaction::REFERENCE_COLUMNS[$is_reference];
            $columns = $insert['columns'] ?? ($schema_columns[$is_reference] ?? null);
            if ($columns === null) {
                throw new RuntimeException("The schema does not declare {$is_reference}");
            }
            $positions = array();
            foreach ($keep as $column) {
                $at = array_search($column, $columns, true);
                if ($at === false) {
                    throw new RuntimeException("{$is_reference} has no {$column} column");
                }
                $positions[] = (int) $at;
            }
            $tuples = array();
            foreach ($insert['rows'] as $row) {
                $counts["{$is_reference} rows"]++;
                if (count($row) !== count($columns)) {
                    throw new RuntimeException("{$is_reference} row with " . count($row) . ' values for ' . count($columns) . ' columns');
                }
                if (count($row) > count($keep)) {
                    $counts["{$is_reference} rows stripped"]++;
                }
                $raws = array();
                foreach ($positions as $at) {
                    $raws[] = $row[$at]['raw'];
                }
                $tuples[] = '(' . implode(',', $raws) . ')';
            }
            $head = $insert['columns'] === null
                ? "INSERT INTO {$is_reference} VALUES "
                : "INSERT INTO {$is_reference} (" . implode(', ', $keep) . ') VALUES ';
            $out[] = $head . implode(',', $tuples) . ';';
            continue;
        }
        if (strpos($line, 'INSERT INTO ledger_corrections ') === 0 || strpos($line, 'INSERT INTO ledger_corrections(') === 0) {
            $counts['ledger_corrections lines']++;
            $changes = array();
            $out[] = ledger_sql_redact_line($line, $schema_columns, $changes);
            foreach ($changes as $change) {
                $counts["ledger_corrections {$change[0]} values redacted"] = ($counts["ledger_corrections {$change[0]} values redacted"] ?? 0) + 1;
            }
            continue;
        }
        $counts['other lines (unchanged)']++;
        $out[] = $line;
    }
    return array('sql' => implode("\n", $out), 'counts' => $counts);
}

/**
 * Drop every column of the two reference tables except REFERENCE_COLUMNS.
 *
 * @return array{sql: string, removed: array<int, string>}
 */
function strip_schema(string $schema): array
{
    $lines = explode("\n", $schema);
    $out = array();
    $removed = array();
    $current = null;
    foreach ($lines as $line) {
        if (preg_match('~^CREATE TABLE\s+`?([A-Za-z0-9_]+)`?\s*\(~', $line, $m)) {
            $current = $m[1];
            $out[] = $line;
            continue;
        }
        if ($current !== null && preg_match('~^\)~', $line)) {
            $current = null;
        }
        if ($current !== null && isset(Ledger_Redaction::REFERENCE_COLUMNS[$current])) {
            $one = ledger_schema_columns("CREATE TABLE {$current} (\n{$line}\n)");
            $column = $one[$current][0] ?? null;
            if ($column !== null && !in_array($column, Ledger_Redaction::REFERENCE_COLUMNS[$current], true)) {
                $removed[] = $line;
                continue;
            }
        }
        $out[] = $line;
    }
    return array('sql' => implode("\n", $out), 'removed' => $removed);
}

/**
 * @param mixed $data
 * @return array<string, mixed> path => leaf value
 */
function json_leaves($data, string $prefix = ''): array
{
    if (!is_array($data) || $data === array()) {
        return array($prefix => $data);
    }
    $leaves = array();
    foreach ($data as $key => $value) {
        $leaves += json_leaves($value, $prefix === '' ? (string) $key : $prefix . "\x1f" . $key);
    }
    return $leaves;
}

/** Whether a flattened path lies at or below a REDACT_KEYS selector. */
function path_selected(string $path, string $selector): bool
{
    $segments = explode("\x1f", $path);
    $wanted = explode('.', $selector);
    if (count($segments) < count($wanted)) {
        return false;
    }
    foreach ($wanted as $i => $segment) {
        if ($segment !== '*' && (string) $segments[$i] !== $segment) {
            return false;
        }
    }
    return true;
}

try {
    list($encode, $encoder_name) = ledger_shipped_encoder($root);

    if ($mode === 'expected-dump') {
        $before_dump = ledger_read_repo_file($root, DUMP, $before_rev);
        $before_schema = ledger_read_repo_file($root, SCHEMA, $before_rev);
        if ($before_dump === null || $before_schema === null) {
            throw new RuntimeException("Cannot read the dump and schema of {$before_rev}");
        }
        $result = strip_dump(ledger_gzdecode_or_fail($before_dump, "{$before_rev}:" . DUMP), ledger_schema_columns($before_schema));
        echo $result['sql'];
        exit(0);
    }

    if ($mode === 'rewrite') {
        foreach (array('data/ledger/entities.tsv', 'data/ledger/titles.tsv') as $required) {
            if (!is_file($root . '/' . $required)) {
                throw new RuntimeException("{$required} is missing: run tests/tools/extract-reference-names.php first, so no reference name is lost.");
            }
        }
        $report = array();

        $schema = file_get_contents($root . '/' . SCHEMA);
        $schema_columns = ledger_schema_columns($schema);
        $dump_bytes = file_get_contents($root . '/' . DUMP);
        $sql = ledger_gzdecode_or_fail($dump_bytes, DUMP);
        $result = strip_dump($sql, $schema_columns);
        if ($result['sql'] !== $sql) {
            file_put_contents($root . '/' . DUMP, gzencode($result['sql'], 9));
            $report[] = DUMP . ': rewritten';
        } else {
            $report[] = DUMP . ': already stripped and redacted';
        }
        foreach ($result['counts'] as $label => $n) {
            $report[] = "  {$label}: {$n}";
        }

        $stripped = strip_schema($schema);
        if ($stripped['sql'] !== $schema) {
            file_put_contents($root . '/' . SCHEMA, $stripped['sql']);
        }
        $report[] = SCHEMA . ': ' . count($stripped['removed']) . ' column lines removed';

        foreach (AUTHORED_JSON as $relname) {
            $path = $root . '/docs/database/' . $relname;
            if (!is_file($path)) {
                $report[] = "docs/database/{$relname}: absent";
                continue;
            }
            $raw = file_get_contents($path);
            $data = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException("docs/database/{$relname} is not valid JSON");
            }
            if (call_user_func($encode, $data) !== $raw) {
                throw new RuntimeException("docs/database/{$relname} is not in the shipped JSON layout; refusing to rewrite it");
            }
            $changes = ledger_redact_json_document($relname, $data);
            if ($changes['evidence'] || $changes['labels']) {
                file_put_contents($path, call_user_func($encode, $data));
            }
            $by_key = array();
            foreach ($changes['evidence'] as $change) {
                $leaf = preg_replace('~^.*\.~', '', $change[0]);
                $by_key[$leaf] = ($by_key[$leaf] ?? 0) + 1;
            }
            $parts = array();
            foreach ($by_key as $leaf => $n) {
                $parts[] = "{$n} {$leaf}";
            }
            $report[] = "docs/database/{$relname}: values redacted: " . ($parts ? implode(', ', $parts) : '0') . '; audit labels renamed: ' . count($changes['labels']);
        }

        $audit = file_get_contents($root . '/' . AUDIT_REPORT);
        $redacted = redact_evidence($audit);
        $old_lines = explode("\n", $audit);
        $new_lines = explode("\n", $redacted);
        $changed_lines = count($old_lines) === count($new_lines) ? count(array_diff_assoc($old_lines, $new_lines)) : -1;
        if ($redacted !== $audit) {
            file_put_contents($root . '/' . AUDIT_REPORT, $redacted);
        }
        $report[] = AUDIT_REPORT . ": {$changed_lines} lines redacted";
        $report[] = 'Dataset values changed: 0 (only REDACT_KEYS values and audit labels are written)';
        $report[] = "JSON encoder: {$encoder_name}";
        echo implode("\n", $report) . "\n";
        exit(0);
    }

    // --verify-redaction
    $failures = array();
    $report = array("Comparing the working tree with {$before_rev}.");

    $before_schema = ledger_read_repo_file($root, SCHEMA, $before_rev);
    $after_schema = ledger_read_repo_file($root, SCHEMA);
    $before_dump = ledger_read_repo_file($root, DUMP, $before_rev);
    $after_dump = ledger_read_repo_file($root, DUMP);
    if ($before_schema === null || $after_schema === null || $before_dump === null || $after_dump === null) {
        throw new RuntimeException("Cannot read the schema and dump of {$before_rev} and of the working tree");
    }

    // Dump.
    $before_sql = ledger_gzdecode_or_fail($before_dump, "{$before_rev}:" . DUMP);
    $after_sql = ledger_gzdecode_or_fail($after_dump, DUMP);
    $expected = strip_dump($before_sql, ledger_schema_columns($before_schema));
    $before_lines = explode("\n", $before_sql);
    $after_lines = explode("\n", $after_sql);
    $expected_lines = explode("\n", $expected['sql']);
    if (count($after_lines) !== count($before_lines)) {
        $failures[] = DUMP . ' has ' . count($after_lines) . ' lines; ' . $before_rev . ' has ' . count($before_lines);
    }
    $mismatch = 0;
    $untouched_differ = 0;
    foreach ($before_lines as $i => $line) {
        $now = $after_lines[$i] ?? null;
        if ($now !== $expected_lines[$i]) {
            $mismatch++;
            if (count($failures) < 20) {
                $failures[] = DUMP . ' line ' . ($i + 1) . ' is not the rewrite of its pre-change line';
            }
        }
        $is_touched = preg_match('~^INSERT INTO (ledger_entities|ledger_titles|ledger_corrections)[ (]~', $line) === 1;
        if (!$is_touched && $now !== $line) {
            $untouched_differ++;
        }
    }
    $after_counts = array('ledger_entities' => 0, 'ledger_titles' => 0);
    $wide_rows = 0;
    foreach ($after_lines as $line) {
        foreach (Ledger_Redaction::REFERENCE_COLUMNS as $table => $keep) {
            if (strpos($line, "INSERT INTO {$table} ") === 0) {
                $insert = ledger_sql_parse_insert($line);
                foreach ($insert['rows'] as $row) {
                    $after_counts[$table]++;
                    if (count($row) > count($keep)) {
                        $wide_rows++;
                    }
                }
            }
        }
    }
    foreach (array('ledger_entities', 'ledger_titles') as $table) {
        $before_n = $expected['counts']["{$table} rows"];
        $report[] = DUMP . ": {$table} INSERT rows {$before_rev} {$before_n}, now {$after_counts[$table]}";
        if ($before_n !== $after_counts[$table]) {
            $failures[] = DUMP . ": {$table} row count changed";
        }
    }
    if ($wide_rows > 0) {
        $failures[] = DUMP . ": {$wide_rows} reference rows still carry more than their kept columns";
    }
    if ($untouched_differ > 0) {
        $failures[] = DUMP . ": {$untouched_differ} lines outside ledger_entities, ledger_titles and ledger_corrections differ";
    }
    $report[] = DUMP . ': lines not equal to the rewrite of their pre-change line: ' . $mismatch;
    $report[] = DUMP . ': other lines byte-identical: ' . ($untouched_differ === 0 ? 'yes' : 'NO');
    $report[] = DUMP . ': ledger_corrections evidence values redacted: ' . ($expected['counts']['ledger_corrections evidence values redacted'] ?? 0)
        . ', verification values redacted: ' . ($expected['counts']['ledger_corrections verification values redacted'] ?? 0);

    // Schema.
    $stripped = strip_schema($before_schema);
    if ($stripped['sql'] !== $after_schema) {
        $failures[] = SCHEMA . ' is not the pre-change schema minus the reference-table columns';
    }
    $after_columns = ledger_schema_columns($after_schema);
    foreach (Ledger_Redaction::REFERENCE_COLUMNS as $table => $keep) {
        if (($after_columns[$table] ?? null) !== $keep) {
            $failures[] = SCHEMA . ": {$table} columns are not exactly " . implode(', ', $keep);
        }
    }
    $report[] = SCHEMA . ': ' . count($stripped['removed']) . ' column lines removed; every other line unchanged: ' . ($stripped['sql'] === $after_schema ? 'yes' : 'NO');

    // Authored JSON.
    $dataset_changed = 0;
    foreach (AUTHORED_JSON as $relname) {
        $relpath = 'docs/database/' . $relname;
        $old_raw = ledger_read_repo_file($root, $relpath, $before_rev);
        $new_raw = ledger_read_repo_file($root, $relpath);
        if ($old_raw === null && $new_raw === null) {
            $report[] = "{$relpath}: absent";
            continue;
        }
        if ($old_raw === null || $new_raw === null) {
            $failures[] = "{$relpath} exists on one side only";
            continue;
        }
        $old = json_decode($old_raw, true);
        $new = json_decode($new_raw, true);
        if (!is_array($old) || !is_array($new)) {
            $failures[] = "{$relpath} does not decode";
            continue;
        }
        if (call_user_func($encode, $old) !== $old_raw) {
            $failures[] = "{$before_rev}:{$relpath} is not in the shipped JSON layout";
        }
        if (call_user_func($encode, $new) !== $new_raw) {
            $failures[] = "{$relpath} does not round-trip through {$encoder_name}";
        }
        $expected_doc = $old;
        $changes = ledger_redact_json_document($relname, $expected_doc);
        if (call_user_func($encode, $expected_doc) !== $new_raw) {
            $failures[] = "{$relpath} is not its pre-change content with only REDACT_KEYS values redacted and audit labels renamed";
        }
        foreach ($changes['evidence'] as $change) {
            if (redact_evidence($change[2]) !== $change[2]) {
                $failures[] = "{$relpath}: redaction of {$change[0]} is not idempotent";
            }
        }
        // Independent leaf walk: every changed leaf must sit under a REDACT_KEYS selector.
        // Rename the audit labels on a copy without redacting anything, so that the
        // only leaves left to differ are the redacted ones.
        $renamed_only = $old;
        foreach (Ledger_Redaction::AUDIT_LABELS[$relname] ?? array() as $selector) {
            if ($selector === '(keys)') {
                $renamed = array();
                foreach ($renamed_only as $key => $value) {
                    $renamed[is_string($key) ? ledger_rename_audit_label($key) : $key] = $value;
                }
                $renamed_only = $renamed;
            } else {
                ledger_json_transform($renamed_only, $selector, 'ledger_rename_audit_label');
            }
        }
        $old_leaves = json_leaves($renamed_only);
        $new_leaves = json_leaves($new);
        if (array_keys($old_leaves) !== array_keys($new_leaves)) {
            $failures[] = "{$relpath}: the structure changed (keys or list lengths)";
        }
        $evidence_changed = 0;
        foreach ($new_leaves as $path => $value) {
            if (!array_key_exists($path, $old_leaves) || $old_leaves[$path] === $value) {
                continue;
            }
            $selected = false;
            foreach (Ledger_Redaction::REDACT_KEYS[$relname] ?? array() as $selector) {
                if (path_selected($path, $selector)) {
                    $selected = true;
                }
            }
            if ($selected && is_string($old_leaves[$path]) && redact_evidence($old_leaves[$path]) === $value) {
                $evidence_changed++;
            } else {
                $dataset_changed++;
                $failures[] = "{$relpath}: a value outside REDACT_KEYS changed at " . str_replace("\x1f", '.', $path);
            }
        }
        $report[] = "{$relpath}: {$evidence_changed} evidence values redacted, " . count($changes['labels']) . ' audit labels renamed, shipped layout round-trip: ' . (call_user_func($encode, $new) === $new_raw ? 'yes' : 'NO');
    }

    // AUDIT-REPORT.md.
    $old_audit = ledger_read_repo_file($root, AUDIT_REPORT, $before_rev);
    $new_audit = ledger_read_repo_file($root, AUDIT_REPORT);
    if ($old_audit === null || $new_audit === null) {
        $failures[] = AUDIT_REPORT . ' is missing on one side';
    } else {
        if (redact_evidence($old_audit) !== $new_audit) {
            $failures[] = AUDIT_REPORT . ' is not redact_evidence() of its pre-change text';
        }
        $a = explode("\n", $old_audit);
        $b = explode("\n", $new_audit);
        $report[] = AUDIT_REPORT . ': ' . (count($a) === count($b) ? count(array_diff_assoc($a, $b)) : 'line count changed;') . ' lines redacted';
    }
    $report[] = "Dataset values changed: {$dataset_changed}";
    $report[] = "JSON encoder: {$encoder_name}";

    echo implode("\n", $report) . "\n";
    if ($failures) {
        fwrite(STDERR, "Redaction verification FAILED:\n- " . implode("\n- ", $failures) . "\n");
        exit(1);
    }
    echo "Redaction verified.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'strip-reference-columns: ' . $e->getMessage() . "\n");
    exit(1);
}
