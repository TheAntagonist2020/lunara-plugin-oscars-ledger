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
        );

        foreach (self::$normalized_tables as $table) {
            $result['insert_counts'][$table] = 0;
        }

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
            }

            if (preg_match('/^\s*(KEY|INDEX|UNIQUE KEY|CONSTRAINT|FOREIGN KEY)\b/i', $line) || preg_match('/^\s*CREATE\s+INDEX\b/i', $line)) {
                $has_route_index = true;
            }

            if (preg_match('/(?:Ã|Â|â|�)/u', $line)) {
                $result['mojibake_count']++;
                if (count($result['mojibake_samples']) < 5) {
                    $result['mojibake_samples'][] = trim($line);
                }
            }
        }

        fclose($handle);

        $result['route_index_status'] = $has_route_index ? 'present' : 'missing';
        $result['plugin_mapping_status'] = self::tables_are_plugin_owned($result['tables']) ? 'plugin_owned' : 'external_source_only';

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

        $actions = array();
        if (!empty($sql['mojibake_count'])) {
            $actions[] = 'repair_sql_mojibake';
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

        return array(
            'nomination_row_delta' => abs($workbook_rows - $sql_nominations),
            'ready_for_authoritative_import' => empty($actions),
            'required_actions' => $actions,
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
