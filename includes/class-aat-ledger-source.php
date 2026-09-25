<?php
/**
 * Oscars Ledger source codec (plan-v5 §4.1, §4.2, §4.5; work unit U01).
 *
 * Pure PHP. It reads the upstream CSV and the audited overlay exactly as shipped, and
 * refuses anything that breaks a codec rule:
 *   - data/oscars.csv: RFC 4180 parse with a tab delimiter, `"` enclosure and escaping
 *     disabled (a backslash is an ordinary character), then the backslash-quote decode;
 *   - data/ledger/corrections.json: an array of cell corrections applied in file order,
 *     each with its own before-check, so a cell corrected twice is checked twice;
 *   - data/ledger/additions.json: an array of {row, reason, evidence, verification}
 *     appended after the upstream rows, source_row implied by position, cells typed
 *     through addition_cell();
 *   - data/ledger/needs-review.json and accepted-drift.json;
 *   - data/ledger/entities.tsv, titles.tsv and name-overrides.tsv (unquoted TSV, read
 *     with explode() and a column-count check, never with a CSV reader).
 *
 * The codec rules are numbered as in plan-v5 §4.2. Every refusal is an
 * AAT_Ledger_Refusal whose $refusal_code is one of the importer's terminal codes
 * (§4.6.8): bundle_hash_mismatch, codec_violation, correction_invalid,
 * overlay_before_mismatch, addition_invalid, addition_cell_type, addition_year_mismatch,
 * addition_duplicates_upstream, correction_targets_addition, needs_review_stale,
 * review_resolution_unapplied, corrected_sha_mismatch, fold_unmapped_char,
 * privacy_violation, accepted_drift_invalid.
 *
 * This file calls no WordPress function and loads standalone with `require`. The fold
 * tables of codec rule 13 live in includes/class-aat-ledger.php (AAT_Ledger_Text),
 * which is loaded on demand from the same directory.
 */

/**
 * A codec refusal: one stable code plus a human-readable message and details.
 */
class AAT_Ledger_Refusal extends RuntimeException
{
    /** @var string */
    public $refusal_code;

    /** @var array<string, mixed> */
    public $details;

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $refusal_code, string $message, array $details = array())
    {
        parent::__construct($refusal_code . ': ' . $message);
        $this->refusal_code = $refusal_code;
        $this->details = $details;
    }
}

/**
 * The shipped JSON layout of the authored overlay files (plan-v5 §4.1).
 */
final class AAT_Ledger_Json
{
    const FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * One-space indentation, ": " between key and value, unescaped slashes and Unicode,
     * no trailing newline: json_encode with FLAGS, then every leading run of 4n spaces
     * becomes n spaces. Decoding and re-encoding a shipped file reproduces it byte for
     * byte.
     *
     * @param mixed $data
     */
    public static function encode_shipped($data): string
    {
        $json = json_encode($data, self::FLAGS);
        if (!is_string($json)) {
            throw new RuntimeException('AAT_Ledger_Json::encode_shipped: json_encode failed (' . json_last_error_msg() . ')');
        }
        $out = preg_replace_callback('~^(?: {4})+~m', function ($m) {
            return str_repeat(' ', intdiv(strlen($m[0]), 4));
        }, $json);
        if (!is_string($out)) {
            throw new RuntimeException('AAT_Ledger_Json::encode_shipped: re-indent failed');
        }
        return $out;
    }

    /**
     * Decode a JSON document to arrays, refusing with $code when it is not valid JSON.
     *
     * @return mixed
     */
    public static function decode(string $bytes, string $label, string $code)
    {
        $data = json_decode($bytes, true, 512, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new AAT_Ledger_Refusal($code, "{$label} is not valid JSON (" . json_last_error_msg() . ')');
        }
        return $data;
    }
}

/**
 * The source codec.
 */
final class AAT_Ledger_Source
{
    /** The 14 upstream columns, in file order. */
    const HEADER = array('Ceremony', 'Year', 'Class', 'CanonicalCategory', 'Category', 'Film', 'FilmId', 'Name', 'Nominees', 'NomineeIds', 'Winner', 'Detail', 'Note', 'Citation');

    const CODEC = 'tsv-rfc4180-noescape+backslash-quote/1';

    const BUNDLE_SCHEMA = 'lunara-oscars-ledger-bundle/3';

    const DRIFT_SCHEMA = 'lunara-oscars-ledger-drift/1';

    /** Codec rule 7: at most this many slots in any pipe list. */
    const MAX_SLOTS = 255;

    /** Bundle files, relative to the plugin root. */
    const FILES = array(
        'csv' => 'data/oscars.csv',
        'manifest' => 'data/ledger/manifest.json',
        'corrections' => 'data/ledger/corrections.json',
        'additions' => 'data/ledger/additions.json',
        'needs_review' => 'data/ledger/needs-review.json',
        'accepted_drift' => 'data/ledger/accepted-drift.json',
        'entities' => 'data/ledger/entities.tsv',
        'titles' => 'data/ledger/titles.tsv',
        'name_overrides' => 'data/ledger/name-overrides.tsv',
        'licence' => 'data/LICENSE-oscar_data.txt',
    );

    const ENTITIES_HEADER = array('imdb_id', 'kind', 'reference_name');
    const TITLES_HEADER = array('imdb_id', 'reference_title');
    const NAME_OVERRIDES_HEADER = array('imdb_id', 'display_name', 'reason');

    /** Entity kind by ID prefix. */
    const ENTITY_KINDS = array('nm' => 'person', 'co' => 'company');

    const CORRECTION_KEYS = array('nomination_id', 'field', 'before', 'after', 'reason', 'evidence', 'verification');
    const CORRECTION_OPTIONAL_KEYS = array('imdb_id');
    const ADDITION_KEYS = array('row', 'reason', 'evidence', 'verification');
    const NEEDS_REVIEW_KEYS = array('rows', 'imdb_id', 'label', 'question', 'tried');
    const NEEDS_REVIEW_OPTIONAL_KEYS = array('resolution');
    const DRIFT_KEYS = array('schema', 'items', 'id_pins', 'restored', 'retire');

    /** The simulation metrics an accepted-drift item may name (plan-v5 §4.1 simulation summary, §4.6.6). */
    const DRIFT_METRICS = array(
        'labels_changed',
        'title_labels_changed',
        'sourced_links_added',
        'sourced_links_removed',
        'entities_added',
        'entities_removed',
        'master_rows_changed',
        'film_entity_changes',
        'primary_entity_changes',
        'ids_preserved',
        'ids_new',
        'ids_retired',
    );

    /**
     * The evidence-redaction patterns of plan-v5 §4.14 item 6, verbatim from
     * tests/tools/lib/redact-evidence.php (Ledger_Redaction::PRIVACY_PATTERNS); the
     * bundle and privacy contracts assert the two lists are identical. Codec rule 14
     * refuses an evidence-bearing value or a reference name that any pattern matches.
     */
    const PRIVACY_PATTERNS = array(
        array('~https?://(www\.)?wikidata\.org/[^\s)\],;\'"|]+~u', 'Wikidata'),
        array('~ ?\bQ\d{2,}\b~u', ''),
        array('~\(\s*(b\.\s*|born\s+|c\.\s*)?(1[6-9]\d\d|20\d\d)\s*[-–]\s*((1[6-9]\d\d|20\d\d)\s*)?\)~u', '[years omitted]'),
        array('~(?<![:\d])\b(1[6-9]\d\d|20\d\d)\s*[-–]\s*(1[6-9]\d\d|20\d\d)\b~u', '[years omitted]'),
        array('~\b(born|b\.|died|d\.)\s+(in\s+)?(1[6-9]\d\d|20\d\d)\b~u', '[years omitted]'),
    );

    /**
     * The evidence-bearing values per file (plan-v5 §4.14 item 6), verbatim from
     * Ledger_Redaction::REDACT_KEYS. Everything else (before, after, appended row cells,
     * labels, drift keys and values) is a dataset value and never tested or redacted.
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
        'data.sql' . '.gz' => array('ledger_corrections.evidence', 'ledger_corrections.verification'),
        'AUDIT-REPORT.md' => array('*'),
    );

    const UNOFFICIAL_PATTERN = '/NOT AN OFFICIAL NOMINATION/i';

    // ------------------------------------------------------------------
    // Pure helpers
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $details
     * @return never
     */
    private static function refuse(string $code, string $message, array $details = array())
    {
        throw new AAT_Ledger_Refusal($code, $message, $details);
    }

    /**
     * RFC 4180 parse: tab delimiter, `"` enclosure, `""` inside an enclosed field is one
     * quote, escaping disabled (a backslash is an ordinary character). Records end at LF.
     * Text after a closing quote other than a delimiter or LF is a codec violation.
     *
     * @return array<int, array<int, string>> list of records, each a list of cells
     */
    public static function parse_rfc_tsv(string $bytes): array
    {
        $rows = array();
        $row = array();
        $len = strlen($bytes);
        $pos = 0;
        $record = 1;
        if ($len === 0) {
            return $rows;
        }
        while (true) {
            if ($pos < $len && $bytes[$pos] === '"') {
                $pos++;
                $value = '';
                while (true) {
                    $quote = strpos($bytes, '"', $pos);
                    if ($quote === false) {
                        self::refuse('codec_violation', "record {$record}: unterminated quoted cell");
                    }
                    $value .= substr($bytes, $pos, $quote - $pos);
                    if ($quote + 1 < $len && $bytes[$quote + 1] === '"') {
                        $value .= '"';
                        $pos = $quote + 2;
                        continue;
                    }
                    $pos = $quote + 1;
                    break;
                }
            } else {
                $run = strcspn($bytes, "\t\n", $pos);
                $value = (string) substr($bytes, $pos, $run);
                $pos += $run;
            }
            $row[] = $value;
            if ($pos >= $len) {
                $rows[] = $row;
                break;
            }
            $char = $bytes[$pos];
            if ($char === "\t") {
                $pos++;
                if ($pos >= $len) {
                    $row[] = '';
                    $rows[] = $row;
                    break;
                }
                continue;
            }
            if ($char === "\n") {
                $pos++;
                $rows[] = $row;
                $row = array();
                $record++;
                if ($pos >= $len) {
                    break;
                }
                continue;
            }
            self::refuse('codec_violation', "record {$record}: text after a closing quote");
        }
        return $rows;
    }

    /**
     * The one conversion of an appended row's JSON value into a cell (plan-v5 §4.1):
     * null → ''; a string → itself; Ceremony only: an integer 1–999 → its digits;
     * Winner only: true → 'True', false → ''. Anything else refuses with
     * addition_cell_type, and a string Winner must be 'True' or empty.
     *
     * @param mixed $value
     */
    public static function addition_cell(string $header, $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($header === 'Winner') {
            if ($value === true) {
                return 'True';
            }
            if ($value === false) {
                return '';
            }
            if (is_string($value) && ($value === 'True' || $value === '')) {
                return $value;
            }
            self::refuse('addition_cell_type', 'Winner must be true, false, null, "True" or "" (got ' . self::describe($value) . ')');
        }
        if (is_string($value)) {
            return $value;
        }
        if ($header === 'Ceremony' && is_int($value) && $value >= 1 && $value <= 999) {
            return (string) $value;
        }
        self::refuse('addition_cell_type', "{$header} cannot be " . self::describe($value));
    }

    /** @param mixed $value */
    private static function describe($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return 'the integer ' . $value;
        }
        if (is_float($value)) {
            return 'the number ' . var_export($value, true);
        }
        if (is_array($value)) {
            return 'an array or object';
        }
        return gettype($value);
    }

    /** sha1 of the 14 cells joined by 0x1f (plan-v5 §4.5). */
    public static function nomination_key(array $cells): string
    {
        return sha1(implode("\x1f", $cells));
    }

    /** The backslash-quote decode of codec rule 4. */
    public static function decode_backslash_quotes(string $cell): string
    {
        return strpos($cell, '\\') === false ? $cell : str_replace('\\"', '"', $cell);
    }

    /**
     * The corrected sheet: header, rows in source_row order, cells joined by a tab,
     * every line ending with LF.
     *
     * @param array<int, array<int, string>> $rows
     */
    public static function serialize_tsv(array $rows): string
    {
        $out = implode("\t", self::HEADER) . "\n";
        foreach ($rows as $cells) {
            $out .= implode("\t", $cells) . "\n";
        }
        return $out;
    }

    /** True when any PRIVACY_PATTERNS pattern matches (codec rule 14). */
    public static function matches_privacy(string $value): bool
    {
        foreach (self::PRIVACY_PATTERNS as $rule) {
            $hit = preg_match($rule[0], $value);
            if ($hit === false) {
                self::refuse('privacy_violation', 'a privacy pattern failed to run (' . preg_last_error_msg() . ')');
            }
            if ($hit) {
                return true;
            }
        }
        return false;
    }

    /**
     * Every string leaf at or below the nodes a REDACT_KEYS selector reaches, as list of
     * array(path, value). '*' matches every key of an object or index of a list.
     *
     * @param mixed $data
     * @return array<int, array{0: string, 1: string}>
     */
    public static function evidence_values(string $relname, $data): array
    {
        $found = array();
        foreach (self::REDACT_KEYS[$relname] ?? array() as $selector) {
            self::select_leaves($data, explode('.', $selector), array(), $found);
        }
        return $found;
    }

    /**
     * @param mixed $node
     * @param array<int, string> $segments
     * @param array<int, string|int> $path
     * @param array<int, array{0: string, 1: string}> $found
     */
    private static function select_leaves($node, array $segments, array $path, array &$found): void
    {
        if (!$segments) {
            if (is_string($node)) {
                $found[] = array(implode('.', $path), $node);
            } elseif (is_array($node)) {
                foreach ($node as $key => $child) {
                    self::select_leaves($child, array(), array_merge($path, array($key)), $found);
                }
            }
            return;
        }
        if (!is_array($node)) {
            return;
        }
        $head = array_shift($segments);
        if ($head === '*') {
            foreach ($node as $key => $child) {
                self::select_leaves($child, $segments, array_merge($path, array($key)), $found);
            }
            return;
        }
        if (array_key_exists($head, $node)) {
            self::select_leaves($node[$head], $segments, array_merge($path, array($head)), $found);
        }
    }

    /**
     * The fold tables (AAT_Ledger_Text in includes/class-aat-ledger.php).
     *
     * @return array{0: array<string, string>, 1: array<int, string>}
     */
    public static function fold_tables(): array
    {
        if (!class_exists('AAT_Ledger_Text', false)) {
            $file = __DIR__ . '/class-aat-ledger.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
        if (!class_exists('AAT_Ledger_Text', false) || !defined('AAT_Ledger_Text::FOLD_MAP') || !defined('AAT_Ledger_Text::FOLD_PASSTHROUGH')) {
            self::refuse('fold_unmapped_char', 'AAT_Ledger_Text::FOLD_MAP is not available (includes/class-aat-ledger.php)');
        }
        return array(AAT_Ledger_Text::FOLD_MAP, AAT_Ledger_Text::FOLD_PASSTHROUGH);
    }

    /**
     * Codec rule 13 over a list of (where, text): every non-ASCII character is a FOLD_MAP
     * key or in FOLD_PASSTHROUGH.
     *
     * @param array<int, array{0: string, 1: string}> $values
     */
    public static function check_fold_coverage(array $values): void
    {
        list($map, $passthrough) = self::fold_tables();
        $allowed = array();
        foreach (array_keys($map) as $char) {
            $allowed[$char] = true;
        }
        foreach ($passthrough as $char) {
            $allowed[$char] = true;
        }
        foreach ($values as $entry) {
            list($where, $text) = $entry;
            if (!preg_match('/[\x80-\xff]/', $text)) {
                continue;
            }
            if (!preg_match_all('/[^\x00-\x7f]/u', $text, $m)) {
                self::refuse('codec_violation', "{$where} is not valid UTF-8");
            }
            foreach ($m[0] as $char) {
                if (!isset($allowed[$char])) {
                    self::refuse('fold_unmapped_char', sprintf('%s contains U+%04X, which is neither a FOLD_MAP key nor in FOLD_PASSTHROUGH', $where, mb_ord($char, 'UTF-8')), array('where' => $where, 'char' => $char));
                }
            }
        }
    }

    /** True when the value is a JSON list (array with keys 0..n-1). */
    private static function is_list($value): bool
    {
        return is_array($value) && ($value === array() || array_keys($value) === range(0, count($value) - 1));
    }

    /** True when the value is a JSON object (non-list array, or an empty array). */
    private static function is_object_like($value): bool
    {
        return is_array($value) && ($value === array() || !self::is_list($value));
    }

    private static function is_nonempty_string($value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private static function is_sha1($value): bool
    {
        return is_string($value) && preg_match('~^[0-9a-f]{40}$~', $value) === 1;
    }

    // ------------------------------------------------------------------
    // Loading
    // ------------------------------------------------------------------

    /**
     * Read and check the source and the overlay (codec rules 2–7, 9, 12–16). $inputs holds
     * the file contents: csv, corrections, additions, needs_review, accepted_drift. The
     * reference files are checked separately by check_references() (rules 8, 13, 14),
     * so the bundle builder can maintain them from this result first.
     *
     * @param array<string, string> $inputs
     * @return array<string, mixed> the corrected dataset and its counts
     */
    public static function load(array $inputs): array
    {
        foreach (array('csv', 'corrections', 'additions', 'needs_review', 'accepted_drift') as $key) {
            if (!isset($inputs[$key]) || !is_string($inputs[$key])) {
                self::refuse('bundle_hash_mismatch', "the {$key} input is missing");
            }
        }

        // Rule 9: display-name rules 4 and 6 use mb_strtolower (folding does not).
        if (!function_exists('mb_strtolower') || !function_exists('mb_ord')) {
            self::refuse('codec_violation', 'mb_strtolower is not available (the mbstring extension is required)');
        }

        // Rules 2 and 3 on the upstream file.
        $records = self::parse_rfc_tsv($inputs['csv']);
        if (!$records) {
            self::refuse('codec_violation', 'data/oscars.csv is empty');
        }
        $header = array_shift($records);
        if ($header !== self::HEADER) {
            self::refuse('codec_violation', 'the header of data/oscars.csv is not the 14 expected columns');
        }
        $width = count(self::HEADER);
        $field_index = array_flip(self::HEADER);
        $upstream = count($records);
        $keys = array();
        $seen_keys = array();
        foreach ($records as $i => $cells) {
            $source_row = $i + 1;
            if (count($cells) !== $width) {
                self::refuse('codec_violation', "source_row {$source_row} has " . count($cells) . " cells, not {$width}");
            }
            foreach ($cells as $c => $cell) {
                if (strpbrk($cell, "\t\r\n") !== false) {
                    self::refuse('codec_violation', "source_row {$source_row} {$header[$c]} contains a tab, CR or LF");
                }
                if (strpos($cell, '\\') !== false && preg_match('~\\\\(?!")~', $cell)) {
                    self::refuse('codec_violation', "source_row {$source_row} {$header[$c]} has a backslash that does not precede a quote");
                }
            }
            // §4.5: upstream keys are over the decoded cells, before the cell overlay.
            $key = sha1(self::decode_backslash_quotes(implode("\x1f", $cells)));
            if (isset($seen_keys[$key])) {
                self::refuse('codec_violation', "source_row {$source_row} has the same nomination_key as source_row {$seen_keys[$key]}");
            }
            $seen_keys[$key] = $source_row;
            $keys[] = $key;
        }
        $rows = $records;
        unset($records);

        // Additions: shape first, so a correction aimed at an addition is recognised.
        $additions = AAT_Ledger_Json::decode($inputs['additions'], 'additions.json', 'addition_invalid');
        if (!self::is_list($additions)) {
            self::refuse('addition_invalid', 'additions.json must be a JSON array');
        }
        $addition_rows = array();
        foreach ($additions as $k => $element) {
            $source_row = $upstream + 1 + $k;
            if (!self::is_object_like($element) || $element === array()) {
                self::refuse('addition_invalid', "additions.json element {$k} is not an object");
            }
            $element_keys = array_map('strval', array_keys($element));
            $wanted_keys = self::ADDITION_KEYS;
            sort($element_keys, SORT_STRING);
            sort($wanted_keys, SORT_STRING);
            if ($element_keys !== $wanted_keys) {
                self::refuse('addition_invalid', "additions.json element {$k} must have exactly the keys row, reason, evidence and verification (has " . implode(', ', array_keys($element)) . ')');
            }
            foreach (array('reason', 'evidence', 'verification') as $text_key) {
                if (!self::is_nonempty_string($element[$text_key])) {
                    self::refuse('addition_invalid', "additions.json element {$k} {$text_key} must be a non-empty string");
                }
            }
            $row = $element['row'];
            if (!self::is_object_like($row) || $row === array()) {
                self::refuse('addition_invalid', "additions.json element {$k} row must be an object");
            }
            $row_keys = array_map('strval', array_keys($row));
            $sorted_row_keys = $row_keys;
            sort($sorted_row_keys, SORT_STRING);
            $expected_keys = self::HEADER;
            sort($expected_keys, SORT_STRING);
            if ($sorted_row_keys !== $expected_keys) {
                self::refuse('addition_invalid', "additions.json element {$k} row must have exactly the 14 header keys");
            }
            $cells = array();
            foreach (self::HEADER as $column) {
                try {
                    $cell = self::addition_cell($column, $row[$column]);
                } catch (AAT_Ledger_Refusal $e) {
                    self::refuse($e->refusal_code, "additions.json element {$k} (source_row {$source_row}): " . substr($e->getMessage(), strlen($e->refusal_code) + 2));
                }
                // Rule 3: no backslash at all in an addition cell.
                if (strpos($cell, '\\') !== false) {
                    self::refuse('codec_violation', "addition source_row {$source_row} {$column} contains a backslash");
                }
                if (strpbrk($cell, "\t\r\n") !== false) {
                    self::refuse('codec_violation', "addition source_row {$source_row} {$column} contains a tab, CR or LF");
                }
                $cells[] = $cell;
            }
            $addition_rows[] = $cells;
        }
        $addition_count = count($addition_rows);

        // Rules 4 and 16: corrections in file order, each before-checked.
        $corrections = AAT_Ledger_Json::decode($inputs['corrections'], 'corrections.json', 'correction_invalid');
        if (!self::is_list($corrections)) {
            self::refuse('correction_invalid', 'corrections.json must be a JSON array');
        }
        $required = self::CORRECTION_KEYS;
        $allowed = array_flip(array_merge(self::CORRECTION_KEYS, self::CORRECTION_OPTIONAL_KEYS));
        $cell_hits = array();
        $by_reason = array();
        foreach ($corrections as $n => $entry) {
            if (!self::is_object_like($entry) || $entry === array()) {
                self::refuse('correction_invalid', "corrections.json entry {$n} is not an object");
            }
            foreach ($required as $key) {
                if (!array_key_exists($key, $entry)) {
                    self::refuse('correction_invalid', "corrections.json entry {$n} lacks {$key}");
                }
            }
            foreach (array_keys($entry) as $key) {
                if (!isset($allowed[$key])) {
                    self::refuse('correction_invalid', "corrections.json entry {$n} has an unknown key {$key}");
                }
            }
            $nid = $entry['nomination_id'];
            if (!is_int($nid) || $nid < 1) {
                self::refuse('correction_invalid', "corrections.json entry {$n} nomination_id must be a positive JSON integer");
            }
            if (!is_string($entry['field']) || !isset($field_index[$entry['field']])) {
                self::refuse('correction_invalid', "corrections.json entry {$n} field must be one of the 14 header names");
            }
            if (!is_string($entry['before']) || !is_string($entry['after'])) {
                self::refuse('correction_invalid', "corrections.json entry {$n} before and after must be strings");
            }
            foreach (array('reason', 'evidence', 'verification') as $key) {
                if (!self::is_nonempty_string($entry[$key])) {
                    self::refuse('correction_invalid', "corrections.json entry {$n} {$key} must be a non-empty string");
                }
            }
            if (array_key_exists('imdb_id', $entry) && $entry['imdb_id'] !== null && !is_string($entry['imdb_id'])) {
                self::refuse('correction_invalid', "corrections.json entry {$n} imdb_id must be a string or null");
            }
            if ($nid > $upstream) {
                if ($nid <= $upstream + $addition_count) {
                    self::refuse('correction_targets_addition', "corrections.json entry {$n} targets source_row {$nid}, an addition (an addition is corrected by editing it in place)");
                }
                self::refuse('correction_invalid', "corrections.json entry {$n} targets source_row {$nid}, beyond the last upstream row {$upstream}");
            }
            $f = $field_index[$entry['field']];
            $cell_key = $nid . "\x1f" . $entry['field'];
            $step = ($cell_hits[$cell_key] ?? 0) + 1;
            $current = $rows[$nid - 1][$f];
            if ($current !== $entry['before']) {
                self::refuse('overlay_before_mismatch', "corrections.json entry {$n} (source_row {$nid} {$entry['field']}, correction {$step} of that cell): before does not equal the " . ($step > 1 ? "value the previous correction left" : 'upstream value'), array('entry' => $n, 'source_row' => $nid, 'field' => $entry['field'], 'step' => $step));
            }
            $rows[$nid - 1][$f] = $entry['after'];
            $cell_hits[$cell_key] = $step;
            $reason = $entry['reason'];
            if (!isset($by_reason['r:' . $reason])) {
                $by_reason['r:' . $reason] = array($reason, 0);
            }
            $by_reason['r:' . $reason][1]++;
        }
        $multi = 0;
        foreach ($cell_hits as $hits) {
            if ($hits > 1) {
                $multi++;
            }
        }
        $reason_pairs = array_values($by_reason);
        usort($reason_pairs, function ($a, $b) {
            return strcmp($a[0], $b[0]);
        });

        // Rule 4: the global backslash-quote decode; no backslash may remain.
        foreach ($rows as $i => $cells) {
            foreach ($cells as $c => $cell) {
                if (strpos($cell, '\\') === false) {
                    continue;
                }
                $decoded = str_replace('\\"', '"', $cell);
                if (strpos($decoded, '\\') !== false) {
                    self::refuse('codec_violation', 'source_row ' . ($i + 1) . " {$header[$c]} keeps a backslash after the overlay and the backslash-quote decode");
                }
                $rows[$i][$c] = $decoded;
            }
            foreach ($cells as $c => $cell) {
                if (strpbrk($rows[$i][$c], "\t\r\n") !== false) {
                    self::refuse('codec_violation', 'source_row ' . ($i + 1) . " {$header[$c]} contains a tab, CR or LF after the overlay");
                }
            }
        }

        // Ceremony labels and category classes from the corrected upstream rows.
        $labels = array();
        $max_ceremony = 0;
        foreach ($rows as $cells) {
            $ceremony = (int) $cells[0];
            if (!isset($labels[$ceremony])) {
                $labels[$ceremony] = $cells[1];
            }
            $max_ceremony = max($max_ceremony, $ceremony);
        }

        // Rule 5 and §4.1: additions are appended; Year, key and category checks.
        $addition_citation_only = 0;
        foreach ($addition_rows as $k => $cells) {
            $source_row = $upstream + 1 + $k;
            $ceremony = $cells[0];
            if (preg_match('~^\d{1,3}$~', $ceremony) && isset($labels[(int) $ceremony])) {
                if ($cells[1] !== $labels[(int) $ceremony]) {
                    self::refuse('addition_year_mismatch', "addition source_row {$source_row}: Year '{$cells[1]}' is not ceremony {$ceremony}'s label '{$labels[(int) $ceremony]}'");
                }
            } elseif (preg_match('~^\d{1,3}$~', $ceremony) && (int) $ceremony <= $max_ceremony) {
                self::refuse('addition_year_mismatch', "addition source_row {$source_row}: ceremony {$ceremony} has no upstream rows and is not after the last upstream ceremony");
            }
            $key = self::nomination_key($cells);
            if (isset($seen_keys[$key])) {
                if ($seen_keys[$key] <= $upstream) {
                    self::refuse('addition_duplicates_upstream', "addition source_row {$source_row} has the same nomination_key as upstream source_row {$seen_keys[$key]}");
                }
                self::refuse('addition_invalid', "addition source_row {$source_row} duplicates addition source_row {$seen_keys[$key]}");
            }
            $seen_keys[$key] = $source_row;
            $keys[] = $key;
            if ($cells[5] === '' && $cells[7] === '' && $cells[8] === '') {
                $addition_citation_only++;
            }
            $rows[] = $cells;
        }
        unset($seen_keys);

        // Rules 6 and 7 over every corrected row, and one class per category.
        $category_class = array();
        $winners = 0;
        $unofficial = 0;
        $citation_only = 0;
        $ceremonies = array();
        $classes = array();
        foreach ($rows as $i => $cells) {
            $source_row = $i + 1;
            self::check_row_shape($source_row, $cells);
            $category = 'c:' . $cells[3];
            if (!isset($category_class[$category])) {
                $category_class[$category] = $cells[2];
            } elseif ($category_class[$category] !== $cells[2]) {
                self::refuse('codec_violation', "source_row {$source_row}: category '{$cells[3]}' has a second class '{$cells[2]}'");
            }
            if ($cells[10] === 'True') {
                $winners++;
            }
            if (preg_match(self::UNOFFICIAL_PATTERN, $cells[12])) {
                $unofficial++;
            }
            if ($cells[5] === '' && $cells[7] === '' && $cells[8] === '') {
                $citation_only++;
            }
            $ceremonies['c:' . $cells[0]] = true;
            $classes['c:' . $cells[2]] = true;
        }

        // Rule 12: needs-review.
        $review = self::check_needs_review($inputs['needs_review'], $rows);

        // Rule 15: accepted drift.
        $drift = self::check_accepted_drift($inputs['accepted_drift'], $upstream);

        // Rule 14: privacy over every evidence-bearing value of the four overlay files.
        $documents = array(
            'corrections.json' => $corrections,
            'additions.json' => $additions,
            'needs-review.json' => $review['data'],
            'accepted-drift.json' => $drift['data'],
        );
        foreach ($documents as $relname => $data) {
            foreach (self::evidence_values($relname, $data) as $found) {
                if (self::matches_privacy($found[1])) {
                    self::refuse('privacy_violation', "{$relname} {$found[0]} matches a privacy pattern (Wikidata link, Q-number or year span)", array('file' => $relname, 'path' => $found[0]));
                }
            }
        }

        // Rule 13: fold coverage over every corrected cell.
        $texts = array();
        foreach ($rows as $i => $cells) {
            foreach ($cells as $c => $cell) {
                if ($cell !== '' && preg_match('/[\x80-\xff]/', $cell)) {
                    $texts[] = array('source_row ' . ($i + 1) . ' ' . $header[$c], $cell);
                }
            }
        }
        self::check_fold_coverage($texts);

        $tsv = self::serialize_tsv($rows);
        $by_reason_out = array();
        foreach ($reason_pairs as $pair) {
            $by_reason_out[] = $pair;
        }

        return array(
            'header' => self::HEADER,
            'upstream_rows' => $upstream,
            'rows' => $rows,
            'keys' => $keys,
            'ceremony_labels' => $labels,
            'corrections' => array(
                'count' => count($corrections),
                'cells' => count($cell_hits),
                'multi_corrected_cells' => $multi,
                'by_reason' => $by_reason_out,
            ),
            'additions' => array(
                'count' => $addition_count,
                'first_source_row' => $addition_count > 0 ? $upstream + 1 : null,
                'last_source_row' => $addition_count > 0 ? $upstream + $addition_count : null,
                'citation_only' => $addition_citation_only,
            ),
            'needs_review' => array(
                'items' => $review['items'],
                'unresolved_items' => $review['unresolved_items'],
                'flagged_pairs' => $review['flagged_pairs'],
                'flagged' => $review['flagged'],
            ),
            'accepted_drift' => array(
                'items' => $drift['items'],
                'id_pins' => $drift['id_pins'],
                'restored' => $drift['restored'],
                'retire' => $drift['retire'],
            ),
            'expected' => array(
                'nominations' => count($rows),
                'winners' => $winners,
                'unofficial' => $unofficial,
                'ceremonies' => count($ceremonies),
                'categories' => count($category_class),
                'classes' => count($classes),
                'citation_only' => $citation_only,
                'corrections' => count($corrections) + $addition_count,
            ),
            'corrected_tsv' => $tsv,
            'corrected_sha256' => hash('sha256', $tsv),
            'reference' => null,
        );
    }

    /**
     * Rules 6 and 7 for one corrected row.
     *
     * @param array<int, string> $cells
     */
    private static function check_row_shape(int $source_row, array $cells): void
    {
        if (!preg_match('~^\d{1,3}$~', $cells[0])) {
            self::refuse('codec_violation', "source_row {$source_row}: Ceremony '{$cells[0]}' is not 1–3 digits");
        }
        if (!preg_match('~^\d{4}(/\d{2})?$~', $cells[1])) {
            self::refuse('codec_violation', "source_row {$source_row}: Year '{$cells[1]}' is not YYYY or YYYY/YY");
        }
        if ($cells[10] !== 'True' && $cells[10] !== '') {
            self::refuse('codec_violation', "source_row {$source_row}: Winner must be 'True' or empty");
        }
        foreach (array(5, 8, 11) as $c) {
            if (substr_count($cells[$c], '|') + 1 > self::MAX_SLOTS) {
                self::refuse('codec_violation', "source_row {$source_row}: " . self::HEADER[$c] . ' has more than ' . self::MAX_SLOTS . ' slots');
            }
        }
        if ($cells[6] !== '') {
            foreach (explode('|', $cells[6]) as $token) {
                if ($token !== '?' && !preg_match('~^tt\d{7,10}$~', $token)) {
                    self::refuse('codec_violation', "source_row {$source_row}: FilmId token '{$token}' is not a tt ID or ?");
                }
            }
            if (substr_count($cells[6], '|') !== substr_count($cells[5], '|')) {
                self::refuse('codec_violation', "source_row {$source_row}: FilmId and Film have different slot counts");
            }
        }
        if ($cells[9] !== '') {
            foreach (explode('|', $cells[9]) as $slot) {
                foreach (explode(',', $slot) as $token) {
                    if ($token !== '?' && !preg_match('~^(nm|co)\d{7,10}$~', $token)) {
                        self::refuse('codec_violation', "source_row {$source_row}: NomineeIds token '{$token}' is not an nm or co ID or ?");
                    }
                }
            }
            if (substr_count($cells[9], '|') !== substr_count($cells[8], '|')) {
                self::refuse('codec_violation', "source_row {$source_row}: NomineeIds and Nominees have different slot counts");
            }
        }
    }

    /**
     * The IDs of one corrected row's NomineeIds, as list of (slot, id).
     *
     * @param array<int, string> $cells
     * @return array<int, string>
     */
    private static function nominee_tokens(array $cells): array
    {
        $ids = array();
        if ($cells[9] === '') {
            return $ids;
        }
        foreach (explode('|', $cells[9]) as $slot) {
            foreach (explode(',', $slot) as $token) {
                if ($token !== '?') {
                    $ids[] = $token;
                }
            }
        }
        return $ids;
    }

    /**
     * Rule 12 (plan-v5 §4.1 needs-review format).
     *
     * @param array<int, array<int, string>> $rows
     * @return array<string, mixed>
     */
    private static function check_needs_review(string $bytes, array $rows): array
    {
        $data = AAT_Ledger_Json::decode($bytes, 'needs-review.json', 'codec_violation');
        if (!self::is_list($data)) {
            self::refuse('codec_violation', 'needs-review.json must be a JSON array');
        }
        $allowed = array_flip(array_merge(self::NEEDS_REVIEW_KEYS, self::NEEDS_REVIEW_OPTIONAL_KEYS));
        $total = count($rows);
        $unresolved = 0;
        $flagged = array();
        foreach ($data as $n => $item) {
            if (!self::is_object_like($item) || $item === array()) {
                self::refuse('codec_violation', "needs-review.json item {$n} is not an object");
            }
            foreach (self::NEEDS_REVIEW_KEYS as $key) {
                if (!array_key_exists($key, $item)) {
                    self::refuse('codec_violation', "needs-review.json item {$n} lacks {$key}");
                }
            }
            foreach (array_keys($item) as $key) {
                if (!isset($allowed[$key])) {
                    self::refuse('codec_violation', "needs-review.json item {$n} has an unknown key {$key}");
                }
            }
            if (!is_string($item['imdb_id']) || !preg_match('~^(nm|co)\d{7,10}$~', $item['imdb_id'])) {
                self::refuse('codec_violation', "needs-review.json item {$n} imdb_id must be an nm or co ID");
            }
            if (!self::is_list($item['rows']) || $item['rows'] === array()) {
                self::refuse('codec_violation', "needs-review.json item {$n} rows must be a non-empty array");
            }
            foreach ($item['rows'] as $row) {
                if (!is_int($row) || $row < 1 || $row > $total) {
                    self::refuse('codec_violation', "needs-review.json item {$n} rows must be source_rows between 1 and {$total}");
                }
            }
            foreach (array('label', 'question', 'tried') as $key) {
                if (!is_string($item[$key])) {
                    self::refuse('codec_violation', "needs-review.json item {$n} {$key} must be a string");
                }
            }
            $status = null;
            if (array_key_exists('resolution', $item)) {
                $resolution = $item['resolution'];
                if (!self::is_object_like($resolution) || !isset($resolution['status']) || !in_array($resolution['status'], array('confirmed', 'corrected'), true)) {
                    self::refuse('codec_violation', "needs-review.json item {$n} resolution.status must be confirmed or corrected");
                }
                foreach (array('evidence', 'verification') as $key) {
                    if (!self::is_nonempty_string($resolution[$key] ?? null)) {
                        self::refuse('codec_violation', "needs-review.json item {$n} resolution.{$key} must be a non-empty string");
                    }
                }
                $status = $resolution['status'];
            }
            foreach ($item['rows'] as $row) {
                $present = in_array($item['imdb_id'], self::nominee_tokens($rows[$row - 1]), true);
                if ($status === null && !$present) {
                    self::refuse('needs_review_stale', "needs-review.json item {$n}: source_row {$row} no longer carries {$item['imdb_id']} in its corrected NomineeIds");
                }
                if ($status === 'corrected' && $present) {
                    self::refuse('review_resolution_unapplied', "needs-review.json item {$n} is resolved as corrected, but source_row {$row} still carries {$item['imdb_id']}");
                }
                if ($status === null) {
                    $flagged[$row . "\x1f" . $item['imdb_id']] = array($row, $item['imdb_id']);
                }
            }
            if ($status === null) {
                $unresolved++;
            }
        }
        $pairs = array_values($flagged);
        usort($pairs, function ($a, $b) {
            return $a[0] <=> $b[0] ?: strcmp($a[1], $b[1]);
        });
        return array(
            'data' => $data,
            'items' => count($data),
            'unresolved_items' => $unresolved,
            'flagged_pairs' => count($pairs),
            'flagged' => $pairs,
        );
    }

    /**
     * Rule 15 (plan-v5 §4.2, §4.6.6).
     *
     * @return array<string, mixed>
     */
    private static function check_accepted_drift(string $bytes, int $upstream): array
    {
        $data = AAT_Ledger_Json::decode($bytes, 'accepted-drift.json', 'accepted_drift_invalid');
        $fail = function (string $message) {
            self::refuse('accepted_drift_invalid', 'accepted-drift.json ' . $message);
        };
        if (!self::is_object_like($data) || $data === array()) {
            $fail('must be a JSON object');
        }
        $keys = array_keys($data);
        if (count($keys) !== count(self::DRIFT_KEYS) || array_diff(self::DRIFT_KEYS, $keys) || array_diff($keys, self::DRIFT_KEYS)) {
            $fail('must have exactly the keys ' . implode(', ', self::DRIFT_KEYS));
        }
        if ($data['schema'] !== self::DRIFT_SCHEMA) {
            $fail('schema must be ' . self::DRIFT_SCHEMA);
        }
        foreach (array('items', 'id_pins', 'restored', 'retire') as $list) {
            if (!self::is_list($data[$list])) {
                $fail("{$list} must be an array");
            }
            foreach ($data[$list] as $n => $entry) {
                if (!self::is_object_like($entry) || $entry === array()) {
                    $fail("{$list}[{$n}] must be an object");
                }
                foreach (array('reason', 'evidence') as $key) {
                    if (!self::is_nonempty_string($entry[$key] ?? null)) {
                        $fail("{$list}[{$n}] needs a non-empty {$key}");
                    }
                }
            }
        }
        foreach ($data['items'] as $n => $item) {
            if (!self::is_nonempty_string($item['class'] ?? null)) {
                $fail("items[{$n}] needs a non-empty class");
            }
            if (!in_array($item['metric'] ?? null, self::DRIFT_METRICS, true)) {
                $fail("items[{$n}] metric must be one of " . implode(', ', self::DRIFT_METRICS));
            }
            if (!self::is_sha1($item['prod_sha1'] ?? null)) {
                $fail("items[{$n}] prod_sha1 must be 40 lower-case hex digits");
            }
            $key = $item['key'] ?? null;
            if (!(is_int($key) && $key > 0) && !self::is_nonempty_string($key)) {
                $fail("items[{$n}] key must be a source_row or an IMDb ID");
            }
        }
        $pinned_rows = array();
        $pinned_ids = array();
        foreach ($data['id_pins'] as $n => $pin) {
            $row = $pin['source_row'] ?? null;
            $live = $pin['live_id'] ?? null;
            if (!is_int($row) || $row < 1 || $row > $upstream) {
                $fail("id_pins[{$n}] source_row must be an upstream source_row (1 to {$upstream})");
            }
            if (!is_int($live) || $live < 1) {
                $fail("id_pins[{$n}] live_id must be a positive integer");
            }
            if (isset($pinned_rows[$row])) {
                $fail("id_pins[{$n}] names source_row {$row} a second time");
            }
            if (isset($pinned_ids[$live])) {
                $fail("id_pins[{$n}] names live_id {$live} a second time");
            }
            if (!self::is_sha1($pin['prod_sha1'] ?? null)) {
                $fail("id_pins[{$n}] prod_sha1 must be 40 lower-case hex digits");
            }
            $pinned_rows[$row] = true;
            $pinned_ids[$live] = true;
        }
        $restored_rows = array();
        foreach ($data['restored'] as $n => $entry) {
            $row = $entry['source_row'] ?? null;
            if (!is_int($row) || $row < 1 || $row > $upstream) {
                $fail("restored[{$n}] source_row must be an upstream source_row (1 to {$upstream})");
            }
            if (isset($pinned_rows[$row])) {
                $fail("restored[{$n}] names source_row {$row}, which a pin names");
            }
            if (isset($restored_rows[$row])) {
                $fail("restored[{$n}] names source_row {$row} a second time");
            }
            $restored_rows[$row] = true;
        }
        $retired = array();
        foreach ($data['retire'] as $n => $entry) {
            $live = $entry['live_id'] ?? null;
            if (!is_int($live) || $live < 1) {
                $fail("retire[{$n}] live_id must be a positive integer");
            }
            if (isset($pinned_ids[$live])) {
                $fail("retire[{$n}] names live_id {$live}, which a pin names");
            }
            if (isset($retired[$live])) {
                $fail("retire[{$n}] names live_id {$live} a second time");
            }
            if (($entry['class'] ?? null) !== 'production_only') {
                $fail("retire[{$n}] class must be production_only");
            }
            if (!self::is_sha1($entry['prod_sha1'] ?? null)) {
                $fail("retire[{$n}] prod_sha1 must be 40 lower-case hex digits");
            }
            $retired[$live] = true;
        }
        return array(
            'data' => $data,
            'items' => count($data['items']),
            'id_pins' => count($data['id_pins']),
            'restored' => count($data['restored']),
            'retire' => count($data['retire']),
        );
    }

    // ------------------------------------------------------------------
    // Reference files (rule 8, and rules 13 and 14 for names)
    // ------------------------------------------------------------------

    /**
     * Every ID the corrected rows reference: tt from FilmId, nm/co from NomineeIds
     * (flagged needs-review IDs included). Sorted by byte order.
     *
     * @param array<int, array<int, string>> $rows
     * @return array{titles: array<int, string>, entities: array<int, string>}
     */
    public static function referenced_ids(array $rows): array
    {
        $titles = array();
        $entities = array();
        foreach ($rows as $cells) {
            if ($cells[6] !== '') {
                foreach (explode('|', $cells[6]) as $token) {
                    if ($token !== '?') {
                        $titles[$token] = true;
                    }
                }
            }
            foreach (self::nominee_tokens($cells) as $token) {
                $entities[$token] = true;
            }
        }
        $titles = array_map('strval', array_keys($titles));
        $entities = array_map('strval', array_keys($entities));
        sort($titles, SORT_STRING);
        sort($entities, SORT_STRING);
        return array('titles' => $titles, 'entities' => $entities);
    }

    /**
     * Parse an unquoted TSV reference file: explode on LF and tab with a column-count
     * check, never a CSV reader. Returns the data rows as lists of strings.
     *
     * @param array<int, string> $header
     * @return array<int, array<int, string>>
     */
    public static function parse_reference_tsv(string $bytes, array $header, string $label): array
    {
        if ($bytes === '' || substr($bytes, -1) !== "\n") {
            self::refuse('codec_violation', "{$label} must end with a newline");
        }
        if (strpos($bytes, "\r") !== false) {
            self::refuse('codec_violation', "{$label} contains a CR");
        }
        $lines = explode("\n", rtrim($bytes, "\n"));
        if (explode("\t", $lines[0]) !== $header) {
            self::refuse('codec_violation', "{$label}: the header is not exactly " . implode('\t', $header));
        }
        $rows = array();
        $width = count($header);
        foreach (array_slice($lines, 1) as $n => $line) {
            $cells = explode("\t", $line);
            if (count($cells) !== $width) {
                self::refuse('codec_violation', "{$label} line " . ($n + 2) . " has " . count($cells) . " columns, not {$width}");
            }
            $rows[] = $cells;
        }
        return $rows;
    }

    /**
     * The maintained reference TSV (plan-v5 §4.1): every referenced ID once, in byte
     * order, keeping the committed reference name; a new ID gets an empty name (and,
     * for entities, the kind of its prefix); an unreferenced row is dropped.
     *
     * @param array<int, string> $referenced sorted IDs
     */
    public static function maintain_reference_tsv(string $which, ?string $existing, array $referenced): string
    {
        $header = $which === 'entities' ? self::ENTITIES_HEADER : self::TITLES_HEADER;
        $names = array();
        if ($existing !== null) {
            foreach (self::parse_reference_tsv($existing, $header, "data/ledger/{$which}.tsv") as $cells) {
                $names['i:' . $cells[0]] = end($cells);
            }
        }
        $out = implode("\t", $header) . "\n";
        foreach ($referenced as $id) {
            $name = $names['i:' . $id] ?? '';
            if ($which === 'entities') {
                $out .= $id . "\t" . self::ENTITY_KINDS[substr($id, 0, 2)] . "\t" . $name . "\n";
            } else {
                $out .= $id . "\t" . $name . "\n";
            }
        }
        return $out;
    }

    /**
     * Rules 8, 13 and 14 for the reference files, and their counts. Fills
     * $result['reference'].
     *
     * @param array<string, mixed> $result from load()
     */
    public static function check_references(array &$result, string $entities, string $titles, string $name_overrides): void
    {
        $referenced = self::referenced_ids($result['rows']);
        $texts = array();
        $counts = array();
        foreach (array('entities' => array($entities, self::ENTITIES_HEADER, '~^(nm|co)\d{7,10}$~'), 'titles' => array($titles, self::TITLES_HEADER, '~^tt\d{7,10}$~')) as $which => $spec) {
            list($bytes, $header, $pattern) = $spec;
            $label = "data/ledger/{$which}.tsv";
            $rows = self::parse_reference_tsv($bytes, $header, $label);
            $ids = array();
            $without = 0;
            $previous = null;
            foreach ($rows as $n => $cells) {
                $id = $cells[0];
                if (!preg_match($pattern, $id)) {
                    self::refuse('codec_violation', "{$label} line " . ($n + 2) . ": '{$id}' is not a valid ID for this file");
                }
                if (isset($ids['i:' . $id])) {
                    self::refuse('codec_violation', "{$label}: {$id} has more than one row (every referenced ID has exactly one)");
                }
                if ($previous !== null && strcmp($previous, $id) > 0) {
                    self::refuse('codec_violation', "{$label}: rows are not sorted by imdb_id ({$previous} before {$id})");
                }
                if ($which === 'entities' && $cells[1] !== self::ENTITY_KINDS[substr($id, 0, 2)]) {
                    self::refuse('codec_violation', "{$label}: {$id} has kind '{$cells[1]}'");
                }
                $ids['i:' . $id] = true;
                $previous = $id;
                $name = end($cells);
                if ($name === '') {
                    $without++;
                } else {
                    if (self::matches_privacy($name)) {
                        self::refuse('privacy_violation', "{$label}: the reference name of {$id} matches a privacy pattern");
                    }
                    $texts[] = array("{$label} {$id}", $name);
                }
            }
            $missing = array();
            foreach ($referenced[$which] as $id) {
                if (!isset($ids['i:' . $id])) {
                    $missing[] = $id;
                }
                unset($ids['i:' . $id]);
            }
            if ($missing) {
                self::refuse('codec_violation', "{$label} lacks " . count($missing) . ' referenced ID(s), e.g. ' . implode(', ', array_slice($missing, 0, 5)), array('missing' => $missing));
            }
            if ($ids) {
                $extra = array_map(function ($k) {
                    return substr($k, 2);
                }, array_keys($ids));
                self::refuse('codec_violation', "{$label} has " . count($extra) . ' unreferenced row(s), e.g. ' . implode(', ', array_slice($extra, 0, 5)), array('extra' => $extra));
            }
            $counts[$which] = array('rows' => count($rows), 'without_reference' => $without);
        }

        $overrides = self::parse_reference_tsv($name_overrides, self::NAME_OVERRIDES_HEADER, 'data/ledger/name-overrides.tsv');
        $all = array_flip(array_merge($referenced['titles'], $referenced['entities']));
        $seen = array();
        foreach ($overrides as $n => $cells) {
            list($id, $display, $reason) = $cells;
            if (!isset($all[$id])) {
                self::refuse('codec_violation', "data/ledger/name-overrides.tsv line " . ($n + 2) . ": {$id} is not referenced by the corrected rows");
            }
            if (isset($seen[$id])) {
                self::refuse('codec_violation', "data/ledger/name-overrides.tsv: {$id} has more than one row");
            }
            if (trim($display) === '' || trim($reason) === '') {
                self::refuse('codec_violation', "data/ledger/name-overrides.tsv line " . ($n + 2) . ': display_name and reason must be non-empty');
            }
            $seen[$id] = true;
            $texts[] = array("data/ledger/name-overrides.tsv {$id}", $display);
        }
        $counts['name_overrides'] = array('rows' => count($overrides));

        self::check_fold_coverage($texts);
        $result['reference'] = $counts;
    }

    // ------------------------------------------------------------------
    // The bundle as deployed (rule 1 and the manifest)
    // ------------------------------------------------------------------

    /**
     * Every {file, sha256} pair the manifest records, wherever it sits.
     *
     * @param array<string, mixed> $manifest
     * @return array<int, array{0: string, 1: string}>
     */
    public static function manifest_file_hashes(array $manifest): array
    {
        $found = array();
        $walk = function ($node) use (&$walk, &$found) {
            if (!is_array($node)) {
                return;
            }
            if (isset($node['file'], $node['sha256']) && is_string($node['file']) && is_string($node['sha256'])) {
                $found[] = array($node['file'], $node['sha256']);
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($manifest);
        return $found;
    }

    /**
     * sha256 of a file's content; a *.gz file is hashed after gzdecode.
     */
    public static function content_sha256(string $path): string
    {
        $bytes = @file_get_contents($path);
        if (!is_string($bytes)) {
            self::refuse('bundle_hash_mismatch', basename($path) . ' cannot be read');
        }
        if (substr($path, -3) === '.gz') {
            $decoded = @gzdecode($bytes);
            if (!is_string($decoded)) {
                self::refuse('bundle_hash_mismatch', basename($path) . ' does not gzdecode');
            }
            $bytes = $decoded;
        }
        return hash('sha256', $bytes);
    }

    /**
     * bundle_id (plan-v5 §4.1): sha256 over the sorted (data/ledger file, sha256) pairs
     * the manifest records (the manifest itself excluded) and the identity values
     * corrected_sha256, derived_sha256, deriver_revision, display_name_policy,
     * year_policy and fold_policy (each when present). mode, reswap_of, dataset_version
     * and every count are excluded. One line per input, "name\tvalue", LF-joined.
     *
     * @param array<string, mixed> $manifest
     */
    public static function bundle_id(array $manifest): string
    {
        $files = array();
        foreach (self::manifest_file_hashes($manifest) as $pair) {
            if (strpos($pair[0], 'data/ledger/') === 0 && $pair[0] !== self::FILES['manifest']) {
                $files[$pair[0]] = $pair[1];
            }
        }
        ksort($files, SORT_STRING);
        $lines = array();
        foreach ($files as $file => $sha) {
            $lines[] = $file . "\t" . $sha;
        }
        foreach (array('corrected_sha256', 'derived_sha256', 'deriver_revision', 'display_name_policy', 'year_policy', 'fold_policy') as $key) {
            if (array_key_exists($key, $manifest)) {
                $lines[] = $key . "\t" . (is_scalar($manifest[$key]) ? (string) $manifest[$key] : json_encode($manifest[$key]));
            }
        }
        return hash('sha256', implode("\n", $lines));
    }

    /**
     * Load the deployed bundle under $root and check it against its manifest: rule 1
     * (every recorded hash), then load(), check_references() and verify_manifest().
     *
     * @param array<string, mixed>|null $manifest null reads data/ledger/manifest.json
     * @return array<string, mixed>
     */
    public static function load_bundle(string $root, ?array $manifest = null): array
    {
        $root = rtrim($root, '/');
        if ($manifest === null) {
            $bytes = @file_get_contents($root . '/' . self::FILES['manifest']);
            if (!is_string($bytes)) {
                self::refuse('bundle_hash_mismatch', 'data/ledger/manifest.json cannot be read');
            }
            $manifest = AAT_Ledger_Json::decode($bytes, 'data/ledger/manifest.json', 'bundle_hash_mismatch');
            if (!is_array($manifest)) {
                self::refuse('bundle_hash_mismatch', 'data/ledger/manifest.json is not an object');
            }
        }
        foreach (self::manifest_file_hashes($manifest) as $pair) {
            list($file, $sha) = $pair;
            if (strpos($file, 'data/') !== 0 || strpos($file, '..') !== false) {
                continue; // docs/ artifacts are not deployed; the bundle contract checks them.
            }
            if (!hash_equals($sha, self::content_sha256($root . '/' . $file))) {
                self::refuse('bundle_hash_mismatch', "{$file} does not match its manifest sha256");
            }
        }
        $inputs = array();
        foreach (array('csv', 'corrections', 'additions', 'needs_review', 'accepted_drift', 'entities', 'titles', 'name_overrides') as $key) {
            $bytes = @file_get_contents($root . '/' . self::FILES[$key]);
            if (!is_string($bytes)) {
                self::refuse('bundle_hash_mismatch', self::FILES[$key] . ' cannot be read');
            }
            $inputs[$key] = $bytes;
        }
        $result = self::load($inputs);
        self::check_references($result, $inputs['entities'], $inputs['titles'], $inputs['name_overrides']);
        self::verify_manifest($manifest, $result, $inputs['csv']);
        return $result;
    }

    /**
     * Compare what the manifest records with what the bundle gives.
     *
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $result from load() and check_references()
     */
    public static function verify_manifest(array $manifest, array $result, string $csv_bytes): void
    {
        $mismatch = function (string $what, $recorded, $actual) {
            self::refuse('codec_violation', "manifest {$what} records " . json_encode($recorded) . ', the bundle gives ' . json_encode($actual));
        };
        if (($manifest['schema'] ?? null) !== self::BUNDLE_SCHEMA) {
            $mismatch('schema', $manifest['schema'] ?? null, self::BUNDLE_SCHEMA);
        }
        $source = $manifest['source'] ?? array();
        if (!hash_equals((string) ($source['sha256'] ?? ''), hash('sha256', $csv_bytes))) {
            self::refuse('bundle_hash_mismatch', 'data/oscars.csv does not match manifest.source.sha256');
        }
        if (($source['header'] ?? null) !== self::HEADER) {
            $mismatch('source.header', $source['header'] ?? null, self::HEADER);
        }
        if (($source['codec'] ?? null) !== self::CODEC) {
            $mismatch('source.codec', $source['codec'] ?? null, self::CODEC);
        }
        if (($source['rows'] ?? null) !== $result['upstream_rows']) {
            $mismatch('source.rows', $source['rows'] ?? null, $result['upstream_rows']);
        }
        if (!hash_equals((string) ($manifest['corrected_sha256'] ?? ''), $result['corrected_sha256'])) {
            self::refuse('corrected_sha_mismatch', 'the corrected sheet does not hash to manifest.corrected_sha256');
        }
        $corrections = $manifest['overlay']['corrections'] ?? array();
        foreach (array('count', 'cells', 'multi_corrected_cells') as $key) {
            if (($corrections[$key] ?? null) !== $result['corrections'][$key]) {
                $mismatch("overlay.corrections.{$key}", $corrections[$key] ?? null, $result['corrections'][$key]);
            }
        }
        $by_reason = array();
        foreach ($result['corrections']['by_reason'] as $pair) {
            $by_reason[(string) $pair[0]] = $pair[1];
        }
        $recorded = array();
        foreach ((array) ($corrections['by_reason'] ?? array()) as $reason => $count) {
            $recorded[(string) $reason] = $count;
        }
        ksort($by_reason, SORT_STRING);
        ksort($recorded, SORT_STRING);
        if ($recorded !== $by_reason) {
            $mismatch('overlay.corrections.by_reason', $recorded, $by_reason);
        }
        $additions = $manifest['overlay']['additions'] ?? array();
        foreach (array('count', 'first_source_row', 'last_source_row', 'citation_only') as $key) {
            if (($additions[$key] ?? null) !== $result['additions'][$key]) {
                $mismatch("overlay.additions.{$key}", $additions[$key] ?? null, $result['additions'][$key]);
            }
        }
        $reference = $manifest['reference'] ?? array();
        foreach (array('entities', 'titles') as $which) {
            foreach (array('rows', 'without_reference') as $key) {
                if (($reference[$which][$key] ?? null) !== $result['reference'][$which][$key]) {
                    $mismatch("reference.{$which}.{$key}", $reference[$which][$key] ?? null, $result['reference'][$which][$key]);
                }
            }
        }
        if (($reference['name_overrides']['rows'] ?? null) !== $result['reference']['name_overrides']['rows']) {
            $mismatch('reference.name_overrides.rows', $reference['name_overrides']['rows'] ?? null, $result['reference']['name_overrides']['rows']);
        }
        foreach (array('items', 'unresolved_items', 'flagged_pairs') as $key) {
            if (($reference['needs_review'][$key] ?? null) !== $result['needs_review'][$key]) {
                $mismatch("reference.needs_review.{$key}", $reference['needs_review'][$key] ?? null, $result['needs_review'][$key]);
            }
        }
        foreach (array('items', 'id_pins', 'restored', 'retire') as $key) {
            if (($reference['accepted_drift'][$key] ?? null) !== $result['accepted_drift'][$key]) {
                $mismatch("reference.accepted_drift.{$key}", $reference['accepted_drift'][$key] ?? null, $result['accepted_drift'][$key]);
            }
        }
        foreach ($result['expected'] as $key => $value) {
            if (($manifest['expected'][$key] ?? null) !== $value) {
                $mismatch("expected.{$key}", $manifest['expected'][$key] ?? null, $value);
            }
        }
        if (($manifest['bundle_id'] ?? null) !== self::bundle_id($manifest)) {
            self::refuse('bundle_hash_mismatch', 'manifest.bundle_id does not equal the bundle identity of its recorded hashes');
        }
    }
}
