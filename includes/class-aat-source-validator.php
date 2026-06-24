<?php

if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit;
}

class AAT_Source_Validator {
    private static $normalized_tables = array(
        'ceremonies',
        'categories',
        'films',
        'people',
        'nominations',
        'nomination_films',
        'nomination_people',
    );

    public static function inspect_normalized_sql($path) {
        $result = array(
            'path' => $path,
            'exists' => is_string($path) && file_exists($path),
            'source_table' => '',
            'tables' => array(),
            'insert_counts' => array(),
            'route_index_status' => 'missing',
            'plugin_mapping_status' => 'unknown',
            'mojibake_count' => 0,
            'mojibake_samples' => array(),
            'mojibake_repair' => array(
                'strategy' => 'not_needed',
                'samples' => array(),
            ),
            'id_shape' => array(
                'films' => array(),
                'people' => array(),
            ),
            'duplicate_primary_ids' => array(),
        );

        foreach (self::$normalized_tables as $table) {
            $result['insert_counts'][$table] = 0;
        }

        $seen_primary_ids = array();
        $duplicate_primary_ids = array();

        if (!$result['exists']) {
            return $result;
        }

        $handle = fopen($path, 'rb');
        if (!$handle) {
            return $result;
        }

        $has_route_index = false;

        while (($line = fgets($handle)) !== false) {
            if ($result['source_table'] === '' && preg_match('/generated from\s+([A-Za-z0-9_]+)/', $line, $matches)) {
                $result['source_table'] = $matches[1];
            }

            if (preg_match('/^CREATE TABLE\s+`?([A-Za-z0-9_]+)`?/i', $line, $matches)) {
                $result['tables'][] = $matches[1];
            }

            if (preg_match('/^INSERT INTO\s+`?([A-Za-z0-9_]+)`?/i', $line, $matches)) {
                $table = $matches[1];
                if (!isset($result['insert_counts'][$table])) {
                    $result['insert_counts'][$table] = 0;
                }
                $result['insert_counts'][$table]++;

                $insert = self::parse_sql_insert_line($line);
                if ($insert) {
                    self::inspect_sql_insert_shape($insert, $seen_primary_ids, $duplicate_primary_ids, $result);
                }
            }

            if (preg_match('/^\s*(KEY|INDEX|UNIQUE KEY|CONSTRAINT|FOREIGN KEY)\b/i', $line) || preg_match('/^\s*CREATE\s+INDEX\b/i', $line)) {
                $has_route_index = true;
            }

            if (preg_match('/(?:Ã|Â|â|�)/u', $line)) {
                $result['mojibake_count']++;
                if (count($result['mojibake_samples']) < 5) {
                    $result['mojibake_samples'][] = trim($line);
                }
                if (count($result['mojibake_repair']['samples']) < 5) {
                    $result['mojibake_repair']['samples'][] = array(
                        'original_preview' => trim($line),
                        'repaired_preview' => self::repair_mojibake_preview(trim($line)),
                    );
                }
            }
        }

        fclose($handle);

        $result['route_index_status'] = $has_route_index ? 'present' : 'missing';
        $result['plugin_mapping_status'] = self::tables_are_plugin_owned($result['tables']) ? 'plugin_owned' : 'external_source_only';
        $result['duplicate_primary_ids'] = $duplicate_primary_ids;
        if ($result['mojibake_count'] > 0) {
            $result['mojibake_repair']['strategy'] = 'repair_or_regenerate_before_import';
        }

        return $result;
    }

    public static function inspect_workbook($path) {
        $result = array(
            'path' => $path,
            'exists' => is_string($path) && file_exists($path),
            'full_data' => array('rows' => 0, 'cols' => 0),
            'ceremony_tab_count' => 0,
            'missing_ceremony_tabs' => array(),
            'duplicate_ceremony_tabs' => array(),
            'first_ceremony_tab' => '',
            'last_ceremony_tab' => '',
            'full_data_headers' => array(),
            'full_data_data_rows' => 0,
            'blank_full_data_rows' => 0,
            'full_data_duplicate_key_count' => 0,
            'full_data_duplicate_extra_rows' => 0,
            'id_shape' => array(
                'film_ids' => array(),
                'nominee_ids' => array(),
            ),
        );

        if (!$result['exists']) {
            return $result;
        }

        try {
            $archive = new PharData($path);
        } catch (Exception $exception) {
            $result['error'] = $exception->getMessage();
            return $result;
        }

        if (!isset($archive['xl/workbook.xml'])) {
            $result['error'] = 'Missing xl/workbook.xml';
            return $result;
        }

        $workbook_xml = self::xml_from_string($archive['xl/workbook.xml']->getContent());
        if (!$workbook_xml) {
            $result['error'] = 'Unable to parse xl/workbook.xml';
            return $result;
        }

        $sheet_names = array();
        $sheet_targets = self::get_workbook_sheet_targets($archive);

        foreach ($workbook_xml->sheets->sheet as $sheet) {
            $attrs = $sheet->attributes();
            $name = (string) $attrs['name'];
            $sheet_names[] = $name;

            if ($name === 'full_data') {
                $rid = self::get_sheet_relationship_id($sheet);
                if ($rid !== '' && isset($sheet_targets[$rid])) {
                    $result['full_data'] = self::get_worksheet_dimension($archive, $sheet_targets[$rid]);
                    $result = array_merge($result, self::inspect_full_data_sheet($archive, $sheet_targets[$rid]));
                }
            }
        }

        $seen = array();
        $ceremony_numbers = array();
        foreach ($sheet_names as $name) {
            if (preg_match('/^Ceremony_(\d+)$/', $name, $matches)) {
                $number = (int) $matches[1];
                if (isset($seen[$number])) {
                    $result['duplicate_ceremony_tabs'][] = $name;
                }
                $seen[$number] = true;
                $ceremony_numbers[] = $number;
            }
        }

        sort($ceremony_numbers, SORT_NUMERIC);
        $result['ceremony_tab_count'] = count($ceremony_numbers);
        $result['first_ceremony_tab'] = $ceremony_numbers ? 'Ceremony_' . $ceremony_numbers[0] : '';
        $result['last_ceremony_tab'] = $ceremony_numbers ? 'Ceremony_' . $ceremony_numbers[count($ceremony_numbers) - 1] : '';

        for ($i = 1; $i <= 98; $i++) {
            if (!isset($seen[$i])) {
                $result['missing_ceremony_tabs'][] = 'Ceremony_' . $i;
            }
        }

        return $result;
    }

    public static function compare_sources($sql, $workbook) {
        $sql_nominations = isset($sql['insert_counts']['nominations']) ? (int) $sql['insert_counts']['nominations'] : 0;
        $workbook_rows = isset($workbook['full_data']['rows']) ? (int) $workbook['full_data']['rows'] : 0;
        $workbook_data_rows = isset($workbook['full_data_data_rows']) ? (int) $workbook['full_data_data_rows'] : max(0, $workbook_rows - 1);
        $dimension_delta = abs($workbook_rows - $sql_nominations);
        $data_delta = abs($workbook_data_rows - $sql_nominations);

        $actions = array();
        if (!empty($sql['mojibake_count'])) {
            $actions[] = 'repair_sql_mojibake';
        }
        if (self::has_source_id_shape_warnings($sql, $workbook)) {
            $actions[] = 'review_source_id_shapes';
        }
        if (($sql['route_index_status'] ?? '') !== 'present') {
            $actions[] = 'add_route_indexes_or_import_to_indexed_tables';
        }
        if (($sql['plugin_mapping_status'] ?? '') !== 'plugin_owned') {
            $actions[] = 'map_external_tables_to_wp_aat';
        }
        if ($sql_nominations !== $workbook_rows) {
            $actions[] = 'reconcile_sql_workbook_row_delta';
        }
        if ($sql_nominations !== $workbook_data_rows) {
            $actions[] = 'regenerate_or_repair_normalized_sql_from_workbook';
        }

        return array(
            'nomination_row_delta' => $dimension_delta,
            'dimension_row_delta' => $dimension_delta,
            'data_row_delta' => $data_delta,
            'row_delta_explanation' => 'The raw workbook delta compares SQL nominations to the worksheet dimension, which includes the header row; excluding the header row leaves a ' . $data_delta . ' data-row delta.',
            'recommended_authoritative_path' => 'workbook_to_plugin_owned_projection',
            'mapping_path' => self::normalized_mapping_path(),
            'ready_for_authoritative_import' => empty($actions),
            'required_actions' => array_values(array_unique($actions)),
        );
    }

    private static function has_source_id_shape_warnings($sql, $workbook) {
        $sql_people = isset($sql['id_shape']['people']) ? $sql['id_shape']['people'] : array();
        $workbook_nominees = isset($workbook['id_shape']['nominee_ids']) ? $workbook['id_shape']['nominee_ids'] : array();

        if (!empty($sql_people['tt']) || !empty($sql_people['unknown']) || !empty($sql_people['other'])) {
            return true;
        }

        if (!empty($workbook_nominees['unknown']) || !empty($workbook_nominees['other']) || !empty($workbook_nominees['tt'])) {
            return true;
        }

        return false;
    }

    private static function inspect_sql_insert_shape($insert, &$seen_primary_ids, &$duplicate_primary_ids, &$result) {
        $table = $insert['table'];
        $values = $insert['values'];

        if (in_array($table, array('ceremonies', 'categories', 'films', 'people', 'nominations'), true) && isset($values[0])) {
            if (!isset($seen_primary_ids[$table])) {
                $seen_primary_ids[$table] = array();
            }
            $id = (string) $values[0];
            if (isset($seen_primary_ids[$table][$id])) {
                if (!isset($duplicate_primary_ids[$table])) {
                    $duplicate_primary_ids[$table] = 0;
                }
                $duplicate_primary_ids[$table]++;
            }
            $seen_primary_ids[$table][$id] = true;
        }

        if ($table === 'films' && array_key_exists(1, $values)) {
            self::increment_shape($result['id_shape']['films'], self::classify_source_id($values[1]));
        }

        if ($table === 'people' && array_key_exists(1, $values)) {
            self::increment_shape($result['id_shape']['people'], self::classify_source_id($values[1]));
        }
    }

    private static function parse_sql_insert_line($line) {
        if (!preg_match('/^INSERT INTO\s+`?([A-Za-z0-9_]+)`?\s*\(([^)]*)\)\s+VALUES\s+\((.*)\);$/i', trim($line), $matches)) {
            return null;
        }

        $columns = array_map(function ($column) {
            return trim($column, " \t\n\r\0\x0B`");
        }, explode(',', $matches[2]));

        return array(
            'table' => $matches[1],
            'columns' => $columns,
            'values' => self::parse_sql_value_list($matches[3]),
        );
    }

    private static function parse_sql_value_list($source) {
        $values = array();
        $current = '';
        $in_quote = false;
        $escaped = false;
        $length = strlen($source);

        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];

            if ($in_quote) {
                if ($escaped) {
                    if ($char === 'n') {
                        $current .= "\n";
                    } elseif ($char === 'r') {
                        $current .= "\r";
                    } elseif ($char === 't') {
                        $current .= "\t";
                    } else {
                        $current .= $char;
                    }
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($char === "'") {
                    if ($i + 1 < $length && $source[$i + 1] === "'") {
                        $current .= "'";
                        $i++;
                        continue;
                    }
                    $in_quote = false;
                    continue;
                }

                $current .= $char;
                continue;
            }

            if ($char === "'") {
                $in_quote = true;
                continue;
            }

            if ($char === ',') {
                $values[] = self::normalize_sql_value($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $values[] = self::normalize_sql_value($current);
        return $values;
    }

    private static function normalize_sql_value($value) {
        $value = trim($value);
        if (strcasecmp($value, 'NULL') === 0) {
            return null;
        }
        if (preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }
        return $value;
    }

    private static function inspect_full_data_sheet($archive, $entry) {
        $details = array(
            'full_data_headers' => array(),
            'full_data_data_rows' => 0,
            'blank_full_data_rows' => 0,
            'full_data_duplicate_key_count' => 0,
            'full_data_duplicate_extra_rows' => 0,
            'id_shape' => array(
                'film_ids' => array(),
                'nominee_ids' => array(),
            ),
        );

        if (!isset($archive[$entry])) {
            return $details;
        }

        $sheet = self::xml_from_string($archive[$entry]->getContent());
        if (!$sheet || !isset($sheet->sheetData)) {
            return $details;
        }

        $shared_strings = self::get_shared_strings($archive);
        $headers = array();
        $header_index = array();
        $duplicate_keys = array();

        foreach ($sheet->sheetData->row as $row) {
            $row_values = self::get_worksheet_row_values($row, $shared_strings);
            $row_number = self::get_worksheet_row_number($row);

            if ($row_number === 1) {
                $headers = array_values($row_values);
                $details['full_data_headers'] = $headers;
                foreach ($headers as $index => $header) {
                    $header_index[$header] = $index;
                }
                continue;
            }

            if (!self::row_has_value($row_values)) {
                $details['blank_full_data_rows']++;
                continue;
            }

            $details['full_data_data_rows']++;
            self::inspect_workbook_id_shapes($row_values, $header_index, $details);
            $key = self::build_workbook_duplicate_key($row_values, $header_index);
            if (!isset($duplicate_keys[$key])) {
                $duplicate_keys[$key] = 0;
            }
            $duplicate_keys[$key]++;
        }

        foreach ($duplicate_keys as $count) {
            if ($count > 1) {
                $details['full_data_duplicate_key_count']++;
                $details['full_data_duplicate_extra_rows'] += $count - 1;
            }
        }

        return $details;
    }

    private static function get_shared_strings($archive) {
        $strings = array();
        if (!isset($archive['xl/sharedStrings.xml'])) {
            return $strings;
        }

        $xml = self::xml_from_string($archive['xl/sharedStrings.xml']->getContent());
        if (!$xml) {
            return $strings;
        }

        foreach ($xml->si as $item) {
            if (isset($item->t)) {
                $strings[] = (string) $item->t;
                continue;
            }

            $text = '';
            foreach ($item->r as $run) {
                if (isset($run->t)) {
                    $text .= (string) $run->t;
                }
            }
            $strings[] = $text;
        }

        return $strings;
    }

    private static function get_worksheet_row_values($row, $shared_strings) {
        $values = array();
        foreach ($row->c as $cell) {
            $attrs = $cell->attributes();
            $ref = isset($attrs['r']) ? (string) $attrs['r'] : '';
            $column = $ref !== '' ? self::cell_ref_to_zero_index($ref) : count($values);
            $values[$column] = self::get_worksheet_cell_value($cell, $shared_strings);
        }

        if (!$values) {
            return array();
        }

        $max = max(array_keys($values));
        for ($i = 0; $i <= $max; $i++) {
            if (!array_key_exists($i, $values)) {
                $values[$i] = '';
            }
        }
        ksort($values);

        return $values;
    }

    private static function get_worksheet_cell_value($cell, $shared_strings) {
        $attrs = $cell->attributes();
        $type = isset($attrs['t']) ? (string) $attrs['t'] : '';

        if ($type === 'inlineStr' && isset($cell->is->t)) {
            return self::normalize_workbook_value((string) $cell->is->t);
        }

        $value = isset($cell->v) ? (string) $cell->v : '';
        if ($type === 's') {
            $index = (int) $value;
            return isset($shared_strings[$index]) ? self::normalize_workbook_value($shared_strings[$index]) : '';
        }

        if ($type === 'b') {
            return $value === '1' ? '1' : '0';
        }

        return self::normalize_workbook_value($value);
    }

    private static function get_worksheet_row_number($row) {
        $attrs = $row->attributes();
        return isset($attrs['r']) ? (int) $attrs['r'] : 0;
    }

    private static function cell_ref_to_zero_index($ref) {
        if (!preg_match('/^([A-Z]+)/', strtoupper($ref), $matches)) {
            return 0;
        }
        return self::column_to_number($matches[1]) - 1;
    }

    private static function row_has_value($row_values) {
        foreach ($row_values as $value) {
            if (self::normalize_workbook_value($value) !== '') {
                return true;
            }
        }
        return false;
    }

    private static function inspect_workbook_id_shapes($row_values, $header_index, &$details) {
        foreach (self::split_workbook_ids(self::get_row_value_by_header($row_values, $header_index, 'FilmId')) as $id) {
            self::increment_shape($details['id_shape']['film_ids'], self::classify_source_id($id));
        }

        foreach (self::split_workbook_ids(self::get_row_value_by_header($row_values, $header_index, 'NomineeIds')) as $id) {
            self::increment_shape($details['id_shape']['nominee_ids'], self::classify_source_id($id));
        }
    }

    private static function build_workbook_duplicate_key($row_values, $header_index) {
        $parts = array(
            self::get_row_value_by_header($row_values, $header_index, 'Ceremony'),
            self::get_row_value_by_header($row_values, $header_index, 'CanonicalCategory'),
            self::get_row_value_by_header($row_values, $header_index, 'Category'),
            self::get_row_value_by_header($row_values, $header_index, 'FilmId'),
            self::get_row_value_by_header($row_values, $header_index, 'NomineeIds'),
            self::get_row_value_by_header($row_values, $header_index, 'Winner'),
            self::get_row_value_by_header($row_values, $header_index, 'Detail'),
        );

        return implode("\x1f", array_map(array('self', 'normalize_workbook_value'), $parts));
    }

    private static function get_row_value_by_header($row_values, $header_index, $header) {
        if (!isset($header_index[$header])) {
            return '';
        }
        $index = $header_index[$header];
        return array_key_exists($index, $row_values) ? $row_values[$index] : '';
    }

    private static function split_workbook_ids($value) {
        $value = self::normalize_workbook_value($value);
        if ($value === '') {
            return array();
        }

        $parts = array();
        foreach (explode('|', $value) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $parts[] = $part;
            }
        }
        return $parts;
    }

    private static function normalize_workbook_value($value) {
        if ($value === null) {
            return '';
        }

        $value = trim((string) $value);
        if (preg_match('/^-?\d+\.0$/', $value)) {
            $value = (string) (int) $value;
        }

        return $value;
    }

    private static function classify_source_id($value) {
        $value = self::normalize_workbook_value($value);
        if ($value === '') {
            return 'blank';
        }
        if (strpos($value, 'tt') === 0) {
            return 'tt';
        }
        if (strpos($value, 'nm') === 0) {
            return 'nm';
        }
        if (strpos($value, 'co') === 0) {
            return 'co';
        }
        if (strpos($value, 'lnm-') === 0) {
            return 'local_person';
        }
        if ($value === '?') {
            return 'unknown';
        }
        return 'other';
    }

    private static function increment_shape(&$shape, $key) {
        if (!isset($shape[$key])) {
            $shape[$key] = 0;
        }
        $shape[$key]++;
    }

    private static function repair_mojibake_preview($text) {
        if (!function_exists('mb_convert_encoding') || !function_exists('mb_check_encoding')) {
            return $text;
        }

        $candidate = @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        if (is_string($candidate) && mb_check_encoding($candidate, 'UTF-8')) {
            return $candidate;
        }

        return $text;
    }

    private static function normalized_mapping_path() {
        return array(
            'ceremonies' => 'wp_aat_ceremonies',
            'categories' => 'wp_aat_categories',
            'films' => 'wp_aat_entities (entity_type=title)',
            'people' => 'wp_aat_entities (entity_type=person/company/local_person)',
            'nominations' => 'wp_aat_award_nominees + wp_aat_award_facts',
            'nomination_films' => 'wp_aat_award_nominees title links',
            'nomination_people' => 'wp_aat_award_nominees person/company links',
            'stats' => 'wp_aat_ceremony_stats + wp_aat_category_stats + wp_aat_entity_stats',
        );
    }

    private static function get_workbook_sheet_targets($archive) {
        $targets = array();
        if (!isset($archive['xl/_rels/workbook.xml.rels'])) {
            return $targets;
        }

        $rels = self::xml_from_string($archive['xl/_rels/workbook.xml.rels']->getContent());
        if (!$rels) {
            return $targets;
        }

        foreach ($rels->Relationship as $relationship) {
            $attrs = $relationship->attributes();
            $id = (string) $attrs['Id'];
            $target = (string) $attrs['Target'];
            if ($id === '' || $target === '') {
                continue;
            }
            $target = ltrim($target, '/');
            $targets[$id] = strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
        }

        return $targets;
    }

    private static function get_sheet_relationship_id($sheet) {
        $namespaces = $sheet->getNamespaces(true);
        if (!isset($namespaces['r'])) {
            return '';
        }
        $attrs = $sheet->attributes($namespaces['r']);
        return isset($attrs['id']) ? (string) $attrs['id'] : '';
    }

    private static function get_worksheet_dimension($archive, $entry) {
        if (!isset($archive[$entry])) {
            return array('rows' => 0, 'cols' => 0);
        }

        $sheet = self::xml_from_string($archive[$entry]->getContent());
        if (!$sheet || !isset($sheet->dimension)) {
            return array('rows' => 0, 'cols' => 0);
        }

        $attrs = $sheet->dimension->attributes();
        $ref = isset($attrs['ref']) ? (string) $attrs['ref'] : '';
        return self::dimension_to_counts($ref);
    }

    private static function dimension_to_counts($ref) {
        if (!preg_match('/^([A-Z]+)(\d+)(?::([A-Z]+)(\d+))?$/', $ref, $matches)) {
            return array('rows' => 0, 'cols' => 0);
        }

        $start_col = self::column_to_number($matches[1]);
        $start_row = (int) $matches[2];
        $end_col = isset($matches[3]) && $matches[3] !== '' ? self::column_to_number($matches[3]) : $start_col;
        $end_row = isset($matches[4]) && $matches[4] !== '' ? (int) $matches[4] : $start_row;

        return array(
            'rows' => max(0, $end_row - $start_row + 1),
            'cols' => max(0, $end_col - $start_col + 1),
        );
    }

    private static function column_to_number($letters) {
        $number = 0;
        $letters = strtoupper($letters);
        for ($i = 0; $i < strlen($letters); $i++) {
            $number = ($number * 26) + (ord($letters[$i]) - 64);
        }
        return $number;
    }

    private static function tables_are_plugin_owned($tables) {
        if (!$tables) {
            return false;
        }
        foreach ($tables as $table) {
            if (strpos($table, 'wp_aat_') !== 0 && strpos($table, 'aat_') !== 0) {
                return false;
            }
        }
        return true;
    }

    private static function xml_from_string($xml) {
        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $parsed;
    }
}
