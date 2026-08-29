<?php
/**
 * Read-only, redacted Oscars status DTO for owner-approved consumers.
 *
 * @package Academy_Awards_Table
 */

if (!defined('ABSPATH')) {
    exit;
}

final class AAT_Lunara_Status {

    const SCHEMA = 'lunara.oscars.status';
    const SCHEMA_VERSION = 1;
    const REQUIRED_CAPABILITY = 'manage_options';

    /**
     * Build a bounded health snapshot without running the detailed integrity audit.
     */
    public static function get_status($plugin_version) {
        global $wpdb;

        $tables = array(
            'awards' => self::table_name('academy_awards'),
            'facts' => self::table_name('aat_award_facts'),
            'posters' => self::table_name('aat_posters'),
            'entities' => self::table_name('aat_entities'),
        );
        $table_health = array(
            'awards' => self::table_exists($tables['awards']),
            'reporting' => self::table_exists($tables['facts']),
            'artwork' => self::table_exists($tables['posters']),
            'people_artwork' => self::table_exists($tables['entities']),
        );

        $awards = array(
            'records_total' => 0,
            'updated_at' => null,
            'title_id_rows' => 0,
            'missing_title_ids' => 0,
            'nominee_rows' => 0,
            'missing_nominee_ids' => 0,
        );
        $awards_readable = false;
        if ($table_health['awards'] && is_object($wpdb) && method_exists($wpdb, 'get_row')) {
            $row = $wpdb->get_row(
                "SELECT COUNT(*) AS records_total,
                        MAX(created_at) AS updated_at,
                        COALESCE(SUM(film_id REGEXP '(^|\\\\|)tt[0-9]{7,9}(\\\\||$)'), 0) AS title_id_rows,
                        COALESCE(SUM(film != '' AND (film_id IS NULL OR film_id = '')), 0) AS missing_title_ids,
                        COALESCE(SUM(nominees != ''), 0) AS nominee_rows,
                        COALESCE(SUM(nominees != '' AND (nominee_ids IS NULL OR nominee_ids = '')), 0) AS missing_nominee_ids
                 FROM {$tables['awards']}",
                defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A'
            );
            if (is_array($row)) {
                $awards_readable = true;
                $awards = array(
                    'records_total' => self::bounded_count($row['records_total'] ?? 0),
                    'updated_at' => self::safe_timestamp($row['updated_at'] ?? null),
                    'title_id_rows' => self::bounded_count($row['title_id_rows'] ?? 0),
                    'missing_title_ids' => self::bounded_count($row['missing_title_ids'] ?? 0),
                    'nominee_rows' => self::bounded_count($row['nominee_rows'] ?? 0),
                    'missing_nominee_ids' => self::bounded_count($row['missing_nominee_ids'] ?? 0),
                );
            }
        }

        $reporting_rows = 0;
        if ($table_health['reporting'] && is_object($wpdb) && method_exists($wpdb, 'get_var')) {
            $reporting_rows = self::bounded_count($wpdb->get_var("SELECT COUNT(*) FROM {$tables['facts']}"));
        }

        $artwork = array(
            'records_total' => 0,
            'linked_total' => 0,
            'invalid_id_total' => 0,
            'missing_attachment_total' => 0,
        );
        if ($table_health['artwork'] && is_object($wpdb) && method_exists($wpdb, 'get_row')) {
            $posts_table = isset($wpdb->posts) ? (string) $wpdb->posts : self::table_name('posts');
            $row = $wpdb->get_row(
                "SELECT COUNT(*) AS records_total,
                        COALESCE(SUM(posters.attachment_id > 0), 0) AS linked_total,
                        COALESCE(SUM(posters.imdb_id NOT REGEXP '^tt[0-9]{7,9}$'), 0) AS invalid_id_total,
                        COALESCE(SUM(posters.attachment_id > 0 AND posts.ID IS NULL), 0) AS missing_attachment_total
                 FROM {$tables['posters']} posters
                 LEFT JOIN {$posts_table} posts ON posts.ID = posters.attachment_id",
                defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A'
            );
            if (is_array($row)) {
                $artwork = array(
                    'records_total' => self::bounded_count($row['records_total'] ?? 0),
                    'linked_total' => self::bounded_count($row['linked_total'] ?? 0),
                    'invalid_id_total' => self::bounded_count($row['invalid_id_total'] ?? 0),
                    'missing_attachment_total' => self::bounded_count($row['missing_attachment_total'] ?? 0),
                );
            }
        }

        $people_artwork = array(
            'eligible_total' => 0,
            'mapped_total' => 0,
        );
        if ($table_health['people_artwork'] && is_object($wpdb) && method_exists($wpdb, 'get_row')) {
            $posts_table = isset($wpdb->posts) ? (string) $wpdb->posts : self::table_name('posts');
            $postmeta_table = isset($wpdb->postmeta) ? (string) $wpdb->postmeta : self::table_name('postmeta');
            $row = $wpdb->get_row(
                "SELECT
                    (SELECT COUNT(*)
                     FROM {$tables['entities']} eligible
                     WHERE eligible.entity_type = 'name'
                       AND eligible.entity_id REGEXP '^nm[0-9]{7,9}$') AS eligible_total,
                    (SELECT COUNT(DISTINCT mapped.entity_id)
                     FROM {$postmeta_table} portrait_ids
                     INNER JOIN {$posts_table} portrait_posts
                        ON portrait_posts.ID = portrait_ids.post_id
                       AND portrait_posts.post_status != 'trash'
                     INNER JOIN {$tables['entities']} mapped
                        ON LOWER(TRIM(mapped.entity_id)) = LOWER(TRIM(portrait_ids.meta_value))
                       AND mapped.entity_type = 'name'
                     WHERE portrait_ids.meta_key = '_aat_person_imdb_id'
                       AND portrait_ids.meta_value REGEXP '^nm[0-9]{7,9}$') AS mapped_total",
                defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A'
            );
            if (is_array($row)) {
                $people_artwork = array(
                    'eligible_total' => self::bounded_count($row['eligible_total'] ?? 0),
                    'mapped_total' => self::bounded_count($row['mapped_total'] ?? 0),
                );
            }
        }
        $people_artwork['mapped_total'] = min($people_artwork['eligible_total'], $people_artwork['mapped_total']);
        $people_artwork_missing = max(0, $people_artwork['eligible_total'] - $people_artwork['mapped_total']);

        $failure_counts = self::get_redacted_failure_counts();
        $reporting_gap = abs($awards['records_total'] - $reporting_rows);
        $integrity_state = 'healthy';
        if (!$awards_readable) {
            $integrity_state = 'unavailable';
        } elseif (
            !$table_health['reporting']
            || $reporting_gap > 0
            || $awards['missing_title_ids'] > 0
            || $awards['missing_nominee_ids'] > 0
            || $failure_counts['insert'] > 0
            || $failure_counts['schema'] > 0
        ) {
            $integrity_state = 'needs_attention';
        }

        $artwork_state = 'healthy';
        if (!$table_health['artwork']) {
            $artwork_state = 'unavailable';
        } elseif (
            !$table_health['people_artwork']
            || $artwork['invalid_id_total'] > 0
            || $artwork['missing_attachment_total'] > 0
            || $people_artwork_missing > 0
        ) {
            $artwork_state = 'needs_attention';
        }

        $automation = self::get_automation_health();
        $available = $awards_readable;
        $state = 'healthy';
        if (!$available) {
            $state = 'unavailable';
        } elseif (
            $integrity_state !== 'healthy'
            || $artwork_state !== 'healthy'
            || in_array($automation['state'], array('unavailable', 'unscheduled', 'stale', 'error'), true)
        ) {
            $state = 'needs_attention';
        } elseif ($automation['state'] === 'running') {
            $state = 'running';
        }

        return array(
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA_VERSION,
            'plugin_version' => (string) $plugin_version,
            'available' => $available,
            'state' => $state,
            'label' => self::text('Oscar Ledger'),
            'message' => self::status_message($state),
            'count' => $awards['records_total'],
            'updated_at' => $awards['updated_at'],
            'integrity' => array(
                'state' => $integrity_state,
                'award_rows' => $awards['records_total'],
                'reporting_rows' => $reporting_rows,
                'reporting_gap' => $reporting_gap,
                'title_ids' => array(
                    'present' => $awards['title_id_rows'],
                    'missing' => $awards['missing_title_ids'],
                ),
                'entity_ids' => array(
                    'rows' => $awards['nominee_rows'],
                    'missing' => $awards['missing_nominee_ids'],
                ),
                'tables' => array(
                    'awards' => $table_health['awards'],
                    'reporting' => $table_health['reporting'],
                ),
                'failure_counts' => $failure_counts,
            ),
            'artwork' => array(
                'state' => $artwork_state,
                'mapped' => $artwork['records_total'],
                'linked' => $artwork['linked_total'],
                'invalid_ids' => $artwork['invalid_id_total'],
                'missing_attachments' => $artwork['missing_attachment_total'],
                'titles' => array(
                    'mapped' => $artwork['records_total'],
                    'linked' => $artwork['linked_total'],
                    'invalid_ids' => $artwork['invalid_id_total'],
                    'missing_attachments' => $artwork['missing_attachment_total'],
                ),
                'people' => array(
                    'available' => $table_health['people_artwork'],
                    'eligible' => $people_artwork['eligible_total'],
                    'mapped' => $people_artwork['mapped_total'],
                    'missing' => $people_artwork_missing,
                ),
            ),
            'automation' => $automation,
            'destinations' => self::get_destinations(),
        );
    }

    private static function table_name($suffix) {
        global $wpdb;
        $prefix = is_object($wpdb) && isset($wpdb->prefix) ? (string) $wpdb->prefix : '';
        return $prefix . (string) $suffix;
    }

    private static function table_exists($table) {
        global $wpdb;
        if ($table === '' || !is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            return false;
        }
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private static function bounded_count($value) {
        return max(0, min(PHP_INT_MAX, intval($value)));
    }

    private static function safe_timestamp($value) {
        $value = trim((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $value) ? $value : null;
    }

    private static function text($value) {
        return function_exists('__') ? __($value, 'academy-awards-table') : $value;
    }

    private static function get_redacted_failure_counts() {
        if (!function_exists('get_option')) {
            return array('insert' => 0, 'schema' => 0);
        }
        $insert = get_option('aat_reporting_insert_failures', array());
        $schema = get_option('aat_reporting_schema_migration_failures', array());
        return array(
            'insert' => self::bounded_count(is_array($insert) ? ($insert['total'] ?? 0) : 0),
            'schema' => self::bounded_count(is_array($schema) ? count((array) ($schema['failures'] ?? array())) : 0),
        );
    }

    private static function get_automation_health() {
        if (class_exists('AAT_Entity_Graph_Builder') && method_exists('AAT_Entity_Graph_Builder', 'get_lunara_health')) {
            try {
                $health = AAT_Entity_Graph_Builder::get_lunara_health();
                if (is_array($health)) {
                    return $health;
                }
            } catch (Throwable $exception) {
                // A status read must degrade without exposing dependency details.
            }
        }
        return array(
            'available' => false,
            'state' => 'unavailable',
            'label' => self::text('Entity graph automation'),
            'message' => self::text('Entity graph health is unavailable.'),
            'running' => false,
            'scheduled' => false,
            'stage' => 'unknown',
            'updated_at' => null,
            'counts' => array(
                'total' => 0,
                'processed' => 0,
            ),
        );
    }

    private static function status_message($state) {
        $messages = array(
            'healthy' => self::text('Oscar records, artwork, and automation are healthy.'),
            'running' => self::text('Oscar records are available and entity automation is running.'),
            'needs_attention' => self::text('Oscar records are available, with one or more health items to review.'),
            'unavailable' => self::text('Oscar Ledger health is unavailable.'),
        );
        return $messages[$state] ?? $messages['unavailable'];
    }

    private static function get_destinations() {
        $can_manage = function_exists('current_user_can') && current_user_can(self::REQUIRED_CAPABILITY);
        $definitions = array(
            array('tracker', self::text('Awards Tracker'), self::text('Review prediction tiers and contender notes.'), 'academy-awards-tracker'),
            array('ceremony_writeups', self::text('Ceremony Write-Ups'), self::text('Open the ceremony editorial queue.'), 'academy-awards-ceremony-writeups'),
            array('poster_library', self::text('Poster Library'), self::text('Review mapped title art and coverage.'), 'academy-awards-posters'),
            array('person_artwork', self::text('Person Artwork'), self::text('Review portrait coverage and source states.'), 'academy-awards-person-portraits'),
            array('identity_audit', self::text('OMDb / Identity Audit'), self::text('Inspect title identity and poster audit results.'), 'academy-awards-omdb-audit'),
            array('entity_integrity', self::text('Entity Integrity'), self::text('Inspect entity graph timing and integrity health.'), 'aat-entity-graph'),
        );
        $destinations = array();
        foreach ($definitions as $definition) {
            $destinations[] = array(
                'id' => $definition[0],
                'label' => $definition[1],
                'description' => $definition[2],
                'url' => function_exists('admin_url') ? admin_url('admin.php?page=' . $definition[3]) : '',
                'capability' => self::REQUIRED_CAPABILITY,
                'available' => $can_manage,
                'state' => $can_manage ? 'available' : 'restricted',
                'danger' => false,
                'operation' => 'navigate',
            );
        }
        return $destinations;
    }
}
