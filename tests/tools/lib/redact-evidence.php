<?php
/**
 * The one evidence redactor of the public Oscars Ledger repository (plan §4.14 item 6).
 *
 * redact_evidence() removes Wikidata links, Q-numbers and life-span years from
 * evidence-bearing text. Ledger_Redaction::REDACT_KEYS names, per file, the only
 * values it may touch. Dataset values (before, after, appended row cells, labels,
 * the dump's nomination rows) are the Academy's own text and are never redacted.
 *
 * Used by tests/tools/strip-reference-columns.php, the bundle builder and
 * tests/ledger-privacy-contract.php. Not deployed: tests/ is deployignored.
 * AAT_Ledger_Source::PRIVACY_PATTERNS and AAT_Ledger_Source::REDACT_KEYS repeat the
 * two tables below verbatim, and the bundle contract asserts that they are identical.
 *
 * Also here, because every user of the table needs them: the audit-label rename,
 * a selector walker for the JSON tables, the shipped JSON layout encoder, and a
 * reader for the dump's single-line INSERT statements.
 */

final class Ledger_Redaction
{
    const YEARS_OMITTED = '[years omitted]';

    /**
     * Applied in this order, each as one global preg_replace. Nothing else changes:
     * there is no global space collapse, so Markdown indentation and the Academy's
     * own double spaces survive.
     *
     * 1. a Wikidata URL becomes the word "Wikidata";
     * 2. a remaining Q-number is removed with one preceding space;
     * 3. a parenthesized year span, a bare year span (not a file:line range) and a
     *    "born/b./died/d. <year>" phrase become "[years omitted]".
     */
    const PRIVACY_PATTERNS = array(
        array('~https?://(www\.)?wikidata\.org/[^\s)\],;\'"|]+~u', 'Wikidata'),
        array('~ ?\bQ\d{2,}\b~u', ''),
        array('~\(\s*(b\.\s*|born\s+|c\.\s*)?(1[6-9]\d\d|20\d\d)\s*[-–]\s*((1[6-9]\d\d|20\d\d)\s*)?\)~u', '[years omitted]'),
        array('~(?<![:\d])\b(1[6-9]\d\d|20\d\d)\s*[-–]\s*(1[6-9]\d\d|20\d\d)\b~u', '[years omitted]'),
        array('~\b(born|b\.|died|d\.)\s+(in\s+)?(1[6-9]\d\d|20\d\d)\b~u', '[years omitted]'),
    );

    /**
     * The evidence-bearing values, per file. Paths are relative to docs/database/
     * (the four top-level JSON files are also byte-copied to data/ledger/).
     *
     * JSON selectors are dot-separated; '*' matches every key of an object or every
     * index of a list. 'data.sql.gz' selectors are table.column of its INSERT lines.
     * 'AUDIT-REPORT.md' is redacted as a whole text ('*').
     */
    const REDACT_KEYS = array(
        'corrections.json' => array('*.evidence', '*.verification'),
        'additions.json' => array('*.evidence', '*.verification'),
        'needs-review.json' => array('*.question', '*.tried', '*.resolution.evidence', '*.resolution.verification'),
        'accepted-drift.json' => array(
            'items.*.reason', 'items.*.evidence',
            'id_pins.*.reason', 'id_pins.*.evidence',
            'restored.*.reason', 'restored.*.evidence',
            'retire.*.reason', 'retire.*.evidence',
        ),
        'tools/adjudications.json' => array('*.note'),
        'tools/editor_decisions.json' => array('*.evidence', '*.verification'),
        'data.sql.gz' => array('ledger_corrections.evidence', 'ledger_corrections.verification'),
        'AUDIT-REPORT.md' => array('*'),
    );

    /**
     * The audit numbered its review items with the letter Q and three digits, which
     * the Q-number rule cannot tell from a QID. In the JSON records they are the
     * letter R with the same digits: the keys of tools/adjudications.json (the label
     * is the part before the first '|') and the items values of
     * tools/editor_decisions.json. '(keys)' selects the top-level object keys.
     */
    const AUDIT_LABELS = array(
        'tools/adjudications.json' => array('(keys)'),
        'tools/editor_decisions.json' => array('*.items.*'),
    );

    const AUDIT_LABEL_PATTERN = '~^Q(\d{3})(?=\||$)~';

    /** The columns the public dump keeps for the two reference tables (plan §4.14 items 2-3). */
    const REFERENCE_COLUMNS = array(
        'ledger_titles' => array('imdb_id', 'title'),
        'ledger_entities' => array('imdb_id', 'kind', 'name'),
    );
}

/**
 * Redact one evidence-bearing text. Idempotent: redact_evidence(redact_evidence($x))
 * === redact_evidence($x).
 */
function redact_evidence(string $text): string
{
    foreach (Ledger_Redaction::PRIVACY_PATTERNS as $rule) {
        $next = preg_replace($rule[0], $rule[1], $text);
        if (!is_string($next)) {
            throw new RuntimeException('redact_evidence: preg_replace failed (' . preg_last_error_msg() . ') on pattern ' . $rule[0]);
        }
        $text = $next;
    }
    return $text;
}

/** Rename one audit review-item label from its Q form to its R form (idempotent). */
function ledger_rename_audit_label(string $label): string
{
    $next = preg_replace(Ledger_Redaction::AUDIT_LABEL_PATTERN, 'R$1', $label);
    return is_string($next) ? $next : $label;
}

/**
 * The shipped JSON layout (plan §4.1): pretty print with unescaped slashes, Unicode
 * and line terminators, zero fractions kept, then every leading run of 4n spaces
 * becomes n spaces; no trailing newline. AAT_Ledger_Json::encode_shipped() (U01)
 * must produce the same bytes; ledger_shipped_encoder() prefers it when loaded.
 *
 * @param mixed $data
 */
function ledger_encode_shipped($data): string
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_PRESERVE_ZERO_FRACTION);
    if (!is_string($json)) {
        throw new RuntimeException('ledger_encode_shipped: json_encode failed (' . json_last_error_msg() . ')');
    }
    $out = preg_replace_callback('~^(?: {4})+~m', function ($m) {
        return str_repeat(' ', intdiv(strlen($m[0]), 4));
    }, $json);
    if (!is_string($out)) {
        throw new RuntimeException('ledger_encode_shipped: re-indent failed');
    }
    return $out;
}

/**
 * The encoder to write authored JSON with: AAT_Ledger_Json::encode_shipped() when the
 * plugin's source codec is present (U01 on), else ledger_encode_shipped().
 *
 * @return array{0: callable, 1: string}
 */
function ledger_shipped_encoder(string $plugin_root): array
{
    $codec = $plugin_root . '/includes/class-aat-ledger-source.php';
    if (!class_exists('AAT_Ledger_Json', false) && is_file($codec)) {
        require_once $codec;
    }
    if (class_exists('AAT_Ledger_Json', false) && method_exists('AAT_Ledger_Json', 'encode_shipped')) {
        return array(array('AAT_Ledger_Json', 'encode_shipped'), 'AAT_Ledger_Json::encode_shipped()');
    }
    return array('ledger_encode_shipped', 'ledger_encode_shipped() (tests/tools/lib/redact-evidence.php)');
}

/**
 * Every value a JSON selector reaches, as list of array(path segments, value).
 *
 * @param mixed $data
 * @return array<int, array{0: array<int, string|int>, 1: mixed}>
 */
function ledger_json_select($data, string $selector): array
{
    $found = array();
    ledger_json_select_walk($data, explode('.', $selector), array(), $found);
    return $found;
}

/**
 * @param mixed $node
 * @param array<int, string> $segments
 * @param array<int, string|int> $path
 * @param array<int, array{0: array<int, string|int>, 1: mixed}> $found
 */
function ledger_json_select_walk($node, array $segments, array $path, array &$found): void
{
    if (!$segments) {
        $found[] = array($path, $node);
        return;
    }
    if (!is_array($node)) {
        return;
    }
    $head = array_shift($segments);
    if ($head === '*') {
        foreach ($node as $key => $child) {
            ledger_json_select_walk($child, $segments, array_merge($path, array($key)), $found);
        }
        return;
    }
    if (array_key_exists($head, $node)) {
        ledger_json_select_walk($node[$head], $segments, array_merge($path, array($head)), $found);
    }
}

/**
 * Apply $fn to every string leaf at or below the nodes a selector reaches.
 * Returns the changes as list of array(path string, old, new).
 *
 * @param mixed $data
 * @return array<int, array{0: string, 1: string, 2: string}>
 */
function ledger_json_transform(&$data, string $selector, callable $fn): array
{
    $changes = array();
    ledger_json_transform_walk($data, explode('.', $selector), array(), $fn, $changes);
    return $changes;
}

/**
 * @param mixed $node
 * @param array<int, string> $segments
 * @param array<int, string|int> $path
 * @param array<int, array{0: string, 1: string, 2: string}> $changes
 */
function ledger_json_transform_walk(&$node, array $segments, array $path, callable $fn, array &$changes): void
{
    if (!$segments) {
        ledger_json_transform_leaves($node, $path, $fn, $changes);
        return;
    }
    if (!is_array($node)) {
        return;
    }
    $head = array_shift($segments);
    if ($head === '*') {
        foreach (array_keys($node) as $key) {
            ledger_json_transform_walk($node[$key], $segments, array_merge($path, array($key)), $fn, $changes);
        }
        return;
    }
    if (array_key_exists($head, $node)) {
        ledger_json_transform_walk($node[$head], $segments, array_merge($path, array($head)), $fn, $changes);
    }
}

/**
 * @param mixed $node
 * @param array<int, string|int> $path
 * @param array<int, array{0: string, 1: string, 2: string}> $changes
 */
function ledger_json_transform_leaves(&$node, array $path, callable $fn, array &$changes): void
{
    if (is_string($node)) {
        $next = $fn($node);
        if ($next !== $node) {
            $changes[] = array(implode('.', $path), $node, $next);
            $node = $next;
        }
        return;
    }
    if (is_array($node)) {
        foreach (array_keys($node) as $key) {
            ledger_json_transform_leaves($node[$key], array_merge($path, array($key)), $fn, $changes);
        }
    }
}

/**
 * Redact one decoded authored JSON document in place: its REDACT_KEYS values through
 * redact_evidence() and its audit labels through ledger_rename_audit_label().
 *
 * @param mixed $data
 * @return array{evidence: array<int, array{0: string, 1: string, 2: string}>, labels: array<int, array{0: string, 1: string, 2: string}>}
 */
function ledger_redact_json_document(string $relname, &$data): array
{
    $evidence = array();
    foreach (Ledger_Redaction::REDACT_KEYS[$relname] ?? array() as $selector) {
        $evidence = array_merge($evidence, ledger_json_transform($data, $selector, 'redact_evidence'));
    }
    $labels = array();
    foreach (Ledger_Redaction::AUDIT_LABELS[$relname] ?? array() as $selector) {
        if ($selector === '(keys)') {
            if (!is_array($data)) {
                continue;
            }
            $renamed = array();
            foreach ($data as $key => $value) {
                $new_key = is_string($key) ? ledger_rename_audit_label($key) : $key;
                if ($new_key !== $key) {
                    $labels[] = array('(key)', (string) $key, (string) $new_key);
                }
                if (array_key_exists($new_key, $renamed)) {
                    throw new RuntimeException("{$relname}: renaming label {$key} collides with an existing key {$new_key}");
                }
                $renamed[$new_key] = $value;
            }
            $data = $renamed;
            continue;
        }
        $labels = array_merge($labels, ledger_json_transform($data, $selector, 'ledger_rename_audit_label'));
    }
    return array('evidence' => $evidence, 'labels' => $labels);
}

/** Quote one value the way the audit's build.py did: NULL, or '…' with \ doubled and ' doubled. */
function ledger_sql_quote(?string $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    return "'" . str_replace(array('\\', "'"), array('\\\\', "''"), $value) . "'";
}

/**
 * Parse one single-line INSERT statement:
 *   INSERT INTO <table> [(<col>, …)] VALUES (<v>, …)[, (<v>, …)]*;
 * Values are quoted strings ('' and \\ escapes), NULL, numbers or bare expressions
 * such as NOW(). Returns null when the line is not an INSERT; throws on a malformed one.
 *
 * @return array{table: string, columns: array<int, string>|null, head: string, rows: array<int, array<int, array{raw: string, value: string|null, quoted: bool}>>}|null
 */
function ledger_sql_parse_insert(string $line): ?array
{
    if (!preg_match('~^INSERT INTO ([A-Za-z0-9_]+)\s*(\(([^)]*)\))?\s*VALUES\s*~', $line, $m)) {
        return null;
    }
    $table = $m[1];
    $columns = null;
    if (isset($m[3]) && $m[2] !== '') {
        $columns = array_map('trim', explode(',', $m[3]));
    }
    $head = $m[0];
    $pos = strlen($head);
    $len = strlen($line);
    $rows = array();
    while (true) {
        if ($pos >= $len || $line[$pos] !== '(') {
            throw new RuntimeException("Malformed INSERT INTO {$table}: expected '(' at byte {$pos}");
        }
        $pos++;
        $row = array();
        while (true) {
            $start = $pos;
            if ($pos < $len && $line[$pos] === "'") {
                $pos++;
                $value = '';
                while (true) {
                    $run = strcspn($line, "'\\", $pos);
                    if ($run > 0) {
                        $value .= substr($line, $pos, $run);
                        $pos += $run;
                    }
                    if ($pos >= $len) {
                        throw new RuntimeException("Unterminated string in INSERT INTO {$table}");
                    }
                    $c = $line[$pos];
                    if ($c === '\\') {
                        if ($pos + 1 >= $len || $line[$pos + 1] !== '\\') {
                            throw new RuntimeException("Unexpected backslash escape in INSERT INTO {$table} at byte {$pos}");
                        }
                        $value .= '\\';
                        $pos += 2;
                        continue;
                    }
                    if ($c === "'") {
                        if ($pos + 1 < $len && $line[$pos + 1] === "'") {
                            $value .= "'";
                            $pos += 2;
                            continue;
                        }
                        $pos++;
                        break;
                    }
                    $value .= $c;
                    $pos++;
                }
                $row[] = array('raw' => substr($line, $start, $pos - $start), 'value' => $value, 'quoted' => true);
            } else {
                $depth = 0;
                while ($pos < $len) {
                    $c = $line[$pos];
                    if ($c === '(') {
                        $depth++;
                    } elseif ($c === ')') {
                        if ($depth === 0) {
                            break;
                        }
                        $depth--;
                    } elseif ($c === ',' && $depth === 0) {
                        break;
                    }
                    $pos++;
                }
                $raw = substr($line, $start, $pos - $start);
                $row[] = array('raw' => $raw, 'value' => strtoupper(trim($raw)) === 'NULL' ? null : trim($raw), 'quoted' => false);
            }
            if ($pos >= $len) {
                throw new RuntimeException("Truncated INSERT INTO {$table}");
            }
            if ($line[$pos] === ',') {
                $pos++;
                continue;
            }
            if ($line[$pos] === ')') {
                $pos++;
                break;
            }
            throw new RuntimeException("Malformed INSERT INTO {$table} at byte {$pos}");
        }
        $rows[] = $row;
        if ($pos < $len && $line[$pos] === ',') {
            $pos++;
            continue;
        }
        break;
    }
    if (substr($line, $pos) !== ';') {
        throw new RuntimeException("INSERT INTO {$table} does not end with ');'");
    }
    return array('table' => $table, 'columns' => $columns, 'head' => $head, 'rows' => $rows);
}

/**
 * Column order of every CREATE TABLE in a schema file, for INSERTs without a column list.
 *
 * @return array<string, array<int, string>>
 */
function ledger_schema_columns(string $schema_sql): array
{
    $tables = array();
    $current = null;
    foreach (preg_split('~\R~', $schema_sql) as $line) {
        if (preg_match('~^CREATE TABLE\s+`?([A-Za-z0-9_]+)`?\s*\(~', $line, $m)) {
            $current = $m[1];
            $tables[$current] = array();
            continue;
        }
        if ($current === null) {
            continue;
        }
        if (preg_match('~^\)~', $line)) {
            $current = null;
            continue;
        }
        // A column definition: an identifier followed by a SQL type (continuation lines
        // of a CHECK constraint and key definitions do not qualify).
        if (preg_match('~^\s+`?([A-Za-z_][A-Za-z0-9_]*)`?\s+(TINYINT|SMALLINT|MEDIUMINT|INT|INTEGER|BIGINT|DECIMAL|NUMERIC|FLOAT|DOUBLE|CHAR|VARCHAR|TINYTEXT|TEXT|MEDIUMTEXT|LONGTEXT|ENUM|SET|DATE|DATETIME|TIMESTAMP|TIME|YEAR|BOOLEAN|BOOL|BIT|BINARY|VARBINARY|BLOB|JSON)\b~i', $line, $m)
            && !in_array(strtoupper($m[1]), array('PRIMARY', 'KEY', 'UNIQUE', 'CONSTRAINT', 'INDEX', 'FULLTEXT', 'FOREIGN', 'CHECK'), true)) {
            $tables[$current][] = $m[1];
        }
    }
    return $tables;
}

/**
 * The positions of the evidence-bearing columns of one parsed INSERT, per the
 * 'data.sql.gz' entries of REDACT_KEYS. Throws when a named column cannot be located.
 *
 * @param array{table: string, columns: array<int, string>|null} $insert
 * @param array<string, array<int, string>> $schema_columns
 * @return array<string, int> column => 0-based position
 */
function ledger_sql_redact_positions(array $insert, array $schema_columns): array
{
    $wanted = array();
    foreach (Ledger_Redaction::REDACT_KEYS['data.sql.gz'] as $selector) {
        list($table, $column) = explode('.', $selector, 2);
        if ($table === $insert['table']) {
            $wanted[] = $column;
        }
    }
    if (!$wanted) {
        return array();
    }
    $columns = $insert['columns'] ?? ($schema_columns[$insert['table']] ?? null);
    if ($columns === null) {
        throw new RuntimeException("INSERT INTO {$insert['table']} has no column list and the schema does not declare the table");
    }
    $positions = array();
    foreach ($wanted as $column) {
        $at = array_search($column, $columns, true);
        if ($at === false) {
            throw new RuntimeException("INSERT INTO {$insert['table']} has no {$column} column");
        }
        $positions[$column] = (int) $at;
    }
    return $positions;
}

/**
 * Redact the evidence-bearing values of one dump line. Returns the line unchanged when
 * nothing in it is evidence-bearing or nothing changes. $changes receives
 * array(column, old, new) per changed value.
 *
 * @param array<string, array<int, string>> $schema_columns
 * @param array<int, array{0: string, 1: string, 2: string}> $changes
 */
function ledger_sql_redact_line(string $line, array $schema_columns, array &$changes): string
{
    $insert = ledger_sql_parse_insert($line);
    if ($insert === null) {
        return $line;
    }
    $positions = ledger_sql_redact_positions($insert, $schema_columns);
    if (!$positions) {
        return $line;
    }
    $changed = false;
    $tuples = array();
    foreach ($insert['rows'] as $row) {
        $raws = array();
        foreach ($row as $i => $cell) {
            $raws[$i] = $cell['raw'];
        }
        foreach ($positions as $column => $at) {
            if (!isset($row[$at]) || !$row[$at]['quoted']) {
                continue;
            }
            $old = (string) $row[$at]['value'];
            $new = redact_evidence($old);
            if ($new !== $old) {
                $raws[$at] = ledger_sql_quote($new);
                $changes[] = array($column, $old, $new);
                $changed = true;
            }
        }
        $tuples[] = '(' . implode(',', $raws) . ')';
    }
    return $changed ? $insert['head'] . implode(',', $tuples) . ';' : $line;
}

/**
 * Read a file of this repository, from the working tree or from a git revision.
 * Returns null when it does not exist there.
 */
function ledger_read_repo_file(string $root, string $relpath, ?string $rev = null): ?string
{
    if ($rev === null) {
        $path = $root . '/' . $relpath;
        if (!is_file($path)) {
            return null;
        }
        $content = file_get_contents($path);
        return is_string($content) ? $content : null;
    }
    $cmd = 'git -C ' . escapeshellarg($root) . ' show ' . escapeshellarg($rev . ':' . $relpath) . ' 2>/dev/null';
    $proc = proc_open($cmd, array(1 => array('pipe', 'w')), $pipes);
    if (!is_resource($proc)) {
        return null;
    }
    $content = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $status = proc_close($proc);
    return ($status === 0 && is_string($content)) ? $content : null;
}

/** gzdecode that throws instead of returning false. */
function ledger_gzdecode_or_fail(string $bytes, string $label): string
{
    $sql = @gzdecode($bytes);
    if (!is_string($sql)) {
        throw new RuntimeException("{$label} does not gzdecode");
    }
    return $sql;
}
