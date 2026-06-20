<?php
/**
 * Ceremony write-up parsing and schema helpers.
 *
 * @package AcademyAwardsTable
 */

if (!class_exists('AAT_Ceremony_Writeups')) {
    class AAT_Ceremony_Writeups {
        const STATUS_DRAFT = 'draft';
        const STATUS_NEEDS_REVIEW = 'needs_review';
        const STATUS_APPROVED = 'approved';
        const STATUS_HIDDEN = 'hidden';

        public static function get_statuses() {
            return array(
                self::STATUS_DRAFT        => 'Draft',
                self::STATUS_NEEDS_REVIEW => 'Needs Review',
                self::STATUS_APPROVED     => 'Approved',
                self::STATUS_HIDDEN       => 'Hidden',
            );
        }

        public static function sanitize_status($status) {
            $status = strtolower(trim((string) $status));
            $statuses = self::get_statuses();

            return isset($statuses[$status]) ? $status : self::STATUS_DRAFT;
        }

        public static function parser_is_available() {
            return class_exists('ZipArchive') || class_exists('PharData');
        }

        public static function get_create_table_sql($table_name, $charset_collate) {
            $table_name = trim((string) $table_name);
            $charset_collate = trim((string) $charset_collate);

            return "CREATE TABLE IF NOT EXISTS $table_name (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                ceremony_number int(3) NOT NULL,
                ceremony_label varchar(64) NOT NULL DEFAULT '',
                source_doc varchar(255) NOT NULL DEFAULT '',
                source_hash char(64) NOT NULL DEFAULT '',
                headline varchar(255) NOT NULL DEFAULT '',
                dek text,
                body longtext,
                source_notes longtext,
                status varchar(32) NOT NULL DEFAULT 'draft',
                created_by bigint(20) unsigned NOT NULL DEFAULT 0,
                updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY ceremony_number (ceremony_number),
                KEY status (status),
                KEY source_hash (source_hash),
                KEY updated_at (updated_at)
            ) $charset_collate;";
        }

        public static function parse_docx($docx_path) {
            $docx_path = (string) $docx_path;
            if ($docx_path === '' || !is_readable($docx_path)) {
                throw new RuntimeException('The ceremony guide DOCX could not be read.');
            }

            if (!function_exists('simplexml_load_string')) {
                throw new RuntimeException('The PHP SimpleXML extension is required to parse ceremony guide DOCX files.');
            }

            $document_xml = self::read_docx_entry($docx_path, 'word/document.xml');
            $styles_xml = self::read_docx_entry($docx_path, 'word/styles.xml');
            $style_names = self::parse_style_names($styles_xml);
            $paragraphs = self::parse_paragraphs($document_xml, $style_names);
            $source_hash = hash_file('sha256', $docx_path);
            $records = self::build_records($paragraphs, $source_hash, basename($docx_path));
            $numbers = array_keys($records);
            sort($numbers, SORT_NUMERIC);

            $duplicates = array();
            $seen = array();
            foreach ($records as $record) {
                $number = (int) $record['ceremony_number'];
                if (isset($seen[$number])) {
                    $duplicates[] = $number;
                }
                $seen[$number] = true;
            }

            $missing = array();
            for ($i = 1; $i <= 98; $i++) {
                if (!isset($records[$i])) {
                    $missing[] = $i;
                }
            }

            return array(
                'source_hash' => $source_hash,
                'source_doc'  => basename($docx_path),
                'summary'     => array(
                    'detected'      => count($records),
                    'first'         => !empty($numbers) ? (int) reset($numbers) : 0,
                    'last'          => !empty($numbers) ? (int) end($numbers) : 0,
                    'missing'       => $missing,
                    'duplicates'    => array_values(array_unique($duplicates)),
                    'source_hash'   => $source_hash,
                    'heading_style' => self::detect_heading_style($records),
                ),
                'records'     => $records,
            );
        }

        private static function read_docx_entry($docx_path, $entry_name) {
            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                $open = $zip->open($docx_path);
                if ($open !== true) {
                    throw new RuntimeException('The ceremony guide DOCX could not be opened as a ZIP archive.');
                }
                $contents = $zip->getFromName($entry_name);
                $zip->close();

                if ($contents === false) {
                    throw new RuntimeException('The ceremony guide DOCX is missing ' . $entry_name . '.');
                }

                return $contents;
            }

            if (class_exists('PharData')) {
                try {
                    $phar = new PharData($docx_path);
                    if (!isset($phar[$entry_name])) {
                        throw new RuntimeException('The ceremony guide DOCX is missing ' . $entry_name . '.');
                    }

                    return $phar[$entry_name]->getContent();
                } catch (Exception $exception) {
                    throw new RuntimeException('The ceremony guide DOCX could not be opened: ' . $exception->getMessage());
                }
            }

            throw new RuntimeException('The PHP ZipArchive or PharData extension is required to parse ceremony guide DOCX files.');
        }

        private static function parse_style_names($styles_xml) {
            $styles = simplexml_load_string($styles_xml);
            if ($styles === false) {
                throw new RuntimeException('The ceremony guide styles XML could not be parsed.');
            }

            $styles->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $out = array();
            foreach ((array) $styles->xpath('//w:style') as $style) {
                $attrs = $style->attributes('w', true);
                $style_id = isset($attrs['styleId']) ? (string) $attrs['styleId'] : '';
                if ($style_id === '') {
                    continue;
                }

                $name_nodes = $style->xpath('w:name');
                $name = $style_id;
                if (!empty($name_nodes[0])) {
                    $name_attrs = $name_nodes[0]->attributes('w', true);
                    $name = isset($name_attrs['val']) ? (string) $name_attrs['val'] : $style_id;
                }
                $out[$style_id] = $name;
            }

            return $out;
        }

        private static function parse_paragraphs($document_xml, $style_names) {
            $document = simplexml_load_string($document_xml);
            if ($document === false) {
                throw new RuntimeException('The ceremony guide document XML could not be parsed.');
            }

            $document->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $paragraphs = array();

            foreach ((array) $document->xpath('//w:body/w:p') as $paragraph) {
                $text_parts = array();
                foreach ((array) $paragraph->xpath('.//w:t') as $text_node) {
                    $text_parts[] = (string) $text_node;
                }

                $text = self::normalize_text(implode('', $text_parts));
                if ($text === '') {
                    continue;
                }

                $style_id = '';
                $style_nodes = $paragraph->xpath('w:pPr/w:pStyle');
                if (!empty($style_nodes[0])) {
                    $style_attrs = $style_nodes[0]->attributes('w', true);
                    $style_id = isset($style_attrs['val']) ? (string) $style_attrs['val'] : '';
                }

                $paragraphs[] = array(
                    'text'       => $text,
                    'style_id'   => $style_id,
                    'style_name' => isset($style_names[$style_id]) ? $style_names[$style_id] : $style_id,
                );
            }

            return $paragraphs;
        }

        private static function build_records($paragraphs, $source_hash, $source_doc) {
            $records = array();
            $current = null;
            $source_mode = false;

            foreach ($paragraphs as $paragraph) {
                $text = (string) ($paragraph['text'] ?? '');
                $heading = self::parse_heading($text);

                if ($heading) {
                    if ($current) {
                        self::finalize_record($current);
                        $records[(int) $current['ceremony_number']] = $current;
                    }

                    $source_mode = false;
                    $current = array(
                        'ceremony_number' => (int) $heading['number'],
                        'ceremony_label'  => $heading['label'],
                        'source_doc'      => $source_doc,
                        'source_hash'     => $source_hash,
                        'headline'        => $heading['label'],
                        'dek'             => $heading['date'],
                        'body'            => '',
                        'source_notes'    => '',
                        'status'          => self::STATUS_DRAFT,
                        '_body_parts'     => array(),
                        '_source_parts'   => array(),
                        '_markers'        => array(),
                        '_heading_style'  => (string) ($paragraph['style_name'] ?? ''),
                    );
                    continue;
                }

                if (!$current) {
                    continue;
                }

                if (self::looks_like_source_line($text)) {
                    $source_mode = true;
                }

                if ($source_mode) {
                    $current['_source_parts'][] = $text;
                    continue;
                }

                if (preg_match_all('/\[(\d+)\]/', $text, $matches)) {
                    foreach ($matches[0] as $marker) {
                        $current['_markers'][$marker] = true;
                    }
                }

                $public_text = self::strip_source_markers($text);
                if ($public_text !== '') {
                    $current['_body_parts'][] = $public_text;
                }
            }

            if ($current) {
                self::finalize_record($current);
                $records[(int) $current['ceremony_number']] = $current;
            }

            ksort($records, SORT_NUMERIC);

            return $records;
        }

        private static function finalize_record(&$record) {
            $record['body'] = trim(implode("\n\n", $record['_body_parts']));

            $source_notes = array();
            if (!empty($record['_markers'])) {
                $source_notes[] = 'Source markers: ' . implode(' ', array_keys($record['_markers']));
            }
            if (!empty($record['_source_parts'])) {
                $source_notes[] = implode("\n", $record['_source_parts']);
            }
            $record['source_notes'] = trim(implode("\n\n", $source_notes));

            unset($record['_body_parts'], $record['_source_parts'], $record['_markers']);
        }

        private static function parse_heading($text) {
            if (!preg_match('/^(\d{1,3})(st|nd|rd|th)\s+Academy\s+Awards\s+[\x{2014}-]\s+(.+)$/u', $text, $matches)) {
                return null;
            }

            return array(
                'number' => (int) $matches[1],
                'label'  => $text,
                'date'   => trim((string) $matches[3]),
            );
        }

        private static function looks_like_source_line($text) {
            $text = trim((string) $text);
            if ($text === '') {
                return false;
            }

            return (bool) preg_match('/https?:\/\/|\[\d+\]\s*\[\d+\]|\[\d+\]\s+https?:\/\//i', $text);
        }

        private static function strip_source_markers($text) {
            $text = preg_replace('/\s*\[\d+\]/', '', (string) $text);

            return self::normalize_text($text);
        }

        private static function normalize_text($text) {
            $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $text = preg_replace('/[ \t]+/u', ' ', $text);

            return trim($text);
        }

        private static function detect_heading_style($records) {
            $styles = array();
            foreach ($records as $record) {
                $style = (string) ($record['_heading_style'] ?? '');
                if ($style !== '') {
                    $styles[$style] = true;
                }
            }

            return implode(', ', array_keys($styles));
        }
    }
}
