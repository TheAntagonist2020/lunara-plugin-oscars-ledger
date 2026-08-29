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
    const SNAPSHOT_SCHEMA = 'lunara.oscars.status.snapshot';
    const SNAPSHOT_VERSION = 1;
    const SNAPSHOT_OPTION = 'aat_lunara_status_snapshot_v1';
    const SNAPSHOT_TTL = 129600; // 36 hours; the owner heartbeat runs daily.
    const REQUIRED_CAPABILITY = 'manage_options';

    private static $request_cache = array();

    /**
     * Register refreshes only on existing owner-controlled lifecycle seams.
     */
    public static function register_refresh_hooks() {
        if (!function_exists('add_action')) {
            return;
        }
        add_action('aat_entity_graph_heartbeat', array(__CLASS__, 'refresh_from_owner_event'), 30, 0);
        add_action('aat_after_data_import', array(__CLASS__, 'refresh_from_owner_event'), 30, 0);
    }

    /**
     * Refresh callback for the owner heartbeat and completed owner imports.
     */
    public static function refresh_from_owner_event() {
        if (!defined('AAT_VERSION')) {
            return null;
        }
        return self::refresh_snapshot(AAT_VERSION);
    }

    /**
     * Ordinary registry read: one option lookup per request, no aggregates.
     */
    public static function get_status($plugin_version) {
        $cache_key = self::request_cache_key($plugin_version);
        if (!array_key_exists($cache_key, self::$request_cache)) {
            $snapshot = function_exists('get_option')
                ? get_option(self::SNAPSHOT_OPTION, null)
                : null;
            self::$request_cache[$cache_key] = self::read_snapshot($snapshot, $plugin_version);
        }

        $status = self::$request_cache[$cache_key];
        $status['destinations'] = self::get_destinations();
        return $status;
    }

    /**
     * Explicit owner refresh. This is the only path that runs full aggregates.
     */
    public static function refresh_snapshot($plugin_version) {
        $refresh_ready = false;
        $payload = self::build_refresh_payload($plugin_version, $refresh_ready);
        $now = time();
        $record = array(
            'schema' => self::SNAPSHOT_SCHEMA,
            'schema_version' => self::SNAPSHOT_VERSION,
            'plugin_version' => (string) $plugin_version,
            'generated_at' => $now,
            'expires_at' => $now + self::SNAPSHOT_TTL,
            'refresh_state' => $refresh_ready ? 'ready' : 'failed',
            'payload' => $payload,
        );

        if (function_exists('update_option')) {
            update_option(self::SNAPSHOT_OPTION, $record, false);
        } else {
            $record['refresh_state'] = 'failed';
        }

        $status = self::read_snapshot($record, $plugin_version);
        self::$request_cache[self::request_cache_key($plugin_version)] = $status;
        $status['destinations'] = self::get_destinations();
        return $status;
    }

    private static function request_cache_key($plugin_version) {
        return (string) $plugin_version;
    }

    private static function read_snapshot($snapshot, $plugin_version) {
        if (!is_array($snapshot)) {
            return self::unavailable_status($plugin_version, 'missing');
        }
        if (
            ($snapshot['schema'] ?? '') !== self::SNAPSHOT_SCHEMA
            || intval($snapshot['schema_version'] ?? 0) !== self::SNAPSHOT_VERSION
            || (string) ($snapshot['plugin_version'] ?? '') !== (string) $plugin_version
            || !is_array($snapshot['payload'] ?? null)
        ) {
            return self::unavailable_status($plugin_version, 'incompatible');
        }

        $generated_at = max(0, intval($snapshot['generated_at'] ?? 0));
        $expires_at = max(0, intval($snapshot['expires_at'] ?? 0));
        $refresh_state = ($snapshot['refresh_state'] ?? '') === 'ready' ? 'ready' : 'failed';
        $snapshot_state = 'fresh';
        if ($refresh_state === 'failed') {
            $snapshot_state = 'failed';
        } elseif ($expires_at === 0 || $expires_at < time()) {
            $snapshot_state = 'stale';
        }

        $status = self::normalize_payload($snapshot['payload'], $plugin_version);
        if ($snapshot_state === 'failed') {
            $status['state'] = $status['available'] ? 'needs_attention' : 'unavailable';
            $status['message'] = self::text('Oscar Ledger health snapshot refresh did not complete.');
        } elseif ($snapshot_state === 'stale') {
            $status['state'] = $status['available'] ? 'needs_attention' : 'unavailable';
            $status['message'] = self::text('Oscar Ledger health snapshot is stale.');
        }
        $status['snapshot'] = array(
            'state' => $snapshot_state,
            'generated_at' => $generated_at ?: null,
            'expires_at' => $expires_at ?: null,
        );
        return $status;
    }

    private static function unavailable_status($plugin_version, $snapshot_state) {
        return array(
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA_VERSION,
            'plugin_version' => (string) $plugin_version,
            'available' => false,
            'state' => 'unavailable',
            'label' => self::text('Oscar Ledger'),
            'message' => $snapshot_state === 'incompatible'
                ? self::text('Oscar Ledger health snapshot is incompatible.')
                : self::text('Oscar Ledger health snapshot is unavailable.'),
            'count' => 0,
            'updated_at' => null,
            'integrity' => array(
                'state' => 'unavailable',
                'award_rows' => null,
                'reporting_rows' => null,
                'reporting_gap' => null,
                'title_ids' => array('present' => null, 'missing' => null),
                'entity_ids' => array('rows' => null, 'missing' => null),
                'tables' => array('awards' => false, 'reporting' => false),
                'failure_counts' => array('insert' => 0, 'schema' => 0),
            ),
            'artwork' => array(
                'state' => 'unavailable',
                'mapped' => null,
                'linked' => null,
                'invalid_ids' => null,
                'missing_attachments' => null,
                'titles' => array(
                    'available' => false,
                    'mapped' => null,
                    'linked' => null,
                    'invalid_ids' => null,
                    'missing_attachments' => null,
                ),
                'people' => array(
                    'available' => false,
                    'eligible' => null,
                    'mapped' => null,
                    'missing' => null,
                ),
            ),
            'automation' => self::unavailable_automation(),
            'snapshot' => array(
                'state' => $snapshot_state,
                'generated_at' => null,
                'expires_at' => null,
            ),
        );
    }

    /**
     * Allowlist a stored snapshot so option corruption cannot expose extra data.
     */
    private static function normalize_payload($payload, $plugin_version) {
        $integrity = is_array($payload['integrity'] ?? null) ? $payload['integrity'] : array();
        $artwork = is_array($payload['artwork'] ?? null) ? $payload['artwork'] : array();
        $titles = is_array($artwork['titles'] ?? null) ? $artwork['titles'] : array();
        $people = is_array($artwork['people'] ?? null) ? $artwork['people'] : array();
        $automation = self::normalize_automation($payload['automation'] ?? array());
        $available = !empty($payload['available']);
        $integrity_state = self::allow_state($integrity['state'] ?? '', array('healthy', 'needs_attention', 'unavailable'), 'unavailable');
        $artwork_state = self::allow_state($artwork['state'] ?? '', array('healthy', 'needs_attention', 'unavailable'), 'unavailable');
        $state = self::overall_state($available, $integrity_state, $artwork_state, $automation['state']);
        $title_available = !empty($titles['available']);
        $people_available = !empty($people['available']);

        return array(
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA_VERSION,
            'plugin_version' => (string) $plugin_version,
            'available' => $available,
            'state' => $state,
            'label' => self::text('Oscar Ledger'),
            'message' => self::status_message($state),
            'count' => $available ? self::bounded_count($payload['count'] ?? 0) : 0,
            'updated_at' => self::safe_timestamp($payload['updated_at'] ?? null),
            'integrity' => array(
                'state' => $integrity_state,
                'award_rows' => self::nullable_count($integrity['award_rows'] ?? null),
                'reporting_rows' => self::nullable_count($integrity['reporting_rows'] ?? null),
                'reporting_gap' => self::nullable_count($integrity['reporting_gap'] ?? null),
                'title_ids' => array(
                    'present' => self::nullable_count($integrity['title_ids']['present'] ?? null),
                    'missing' => self::nullable_count($integrity['title_ids']['missing'] ?? null),
                ),
                'entity_ids' => array(
                    'rows' => self::nullable_count($integrity['entity_ids']['rows'] ?? null),
                    'missing' => self::nullable_count($integrity['entity_ids']['missing'] ?? null),
                ),
                'tables' => array(
                    'awards' => !empty($integrity['tables']['awards']),
                    'reporting' => !empty($integrity['tables']['reporting']),
                ),
                'failure_counts' => array(
                    'insert' => self::bounded_count($integrity['failure_counts']['insert'] ?? 0),
                    'schema' => self::bounded_count($integrity['failure_counts']['schema'] ?? 0),
                ),
            ),
            'artwork' => array(
                'state' => $artwork_state,
                'mapped' => $title_available ? self::bounded_count($titles['mapped'] ?? 0) : null,
                'linked' => $title_available ? self::bounded_count($titles['linked'] ?? 0) : null,
                'invalid_ids' => $title_available ? self::bounded_count($titles['invalid_ids'] ?? 0) : null,
                'missing_attachments' => $title_available ? self::bounded_count($titles['missing_attachments'] ?? 0) : null,
                'titles' => array(
                    'available' => $title_available,
                    'mapped' => $title_available ? self::bounded_count($titles['mapped'] ?? 0) : null,
                    'linked' => $title_available ? self::bounded_count($titles['linked'] ?? 0) : null,
                    'invalid_ids' => $title_available ? self::bounded_count($titles['invalid_ids'] ?? 0) : null,
                    'missing_attachments' => $title_available ? self::bounded_count($titles['missing_attachments'] ?? 0) : null,
                ),
                'people' => array(
                    'available' => $people_available,
                    'eligible' => $people_available ? self::bounded_count($people['eligible'] ?? 0) : null,
                    'mapped' => $people_available ? self::bounded_count($people['mapped'] ?? 0) : null,
                    'missing' => $people_available ? self::bounded_count($people['missing'] ?? 0) : null,
                ),
            ),
            'automation' => $automation,
        );
    }

    private static function normalize_automation($automation) {
        $automation = is_array($automation) ? $automation : array();
        $state = self::allow_state(
            $automation['state'] ?? '',
            array('unavailable', 'unscheduled', 'scheduled_idle', 'running', 'stale', 'error', 'healthy'),
            'unavailable'
        );
        $stage = self::allow_state(
            $automation['stage'] ?? '',
            array('idle', 'movies', 'people', 'studios', 'ledger', 'verify', 'done', 'unknown'),
            'unknown'
        );
        $messages = array(
            'unavailable' => self::text('Entity graph health is unavailable.'),
            'unscheduled' => self::text('Entity graph automation is not scheduled.'),
            'scheduled_idle' => self::text('Entity graph automation is scheduled and idle.'),
            'running' => self::text('Entity graph automation is running.'),
            'stale' => self::text('Entity graph automation has not completed recently.'),
            'error' => self::text('The last entity graph run reported an error.'),
            'healthy' => self::text('Entity graph automation completed recently.'),
        );
        return array(
            'available' => !empty($automation['available']),
            'state' => $state,
            'label' => self::text('Entity graph automation'),
            'message' => $messages[$state],
            'running' => !empty($automation['running']),
            'scheduled' => !empty($automation['scheduled']),
            'stage' => $stage,
            'updated_at' => self::nullable_count($automation['updated_at'] ?? null),
            'counts' => array(
                'total' => self::bounded_count($automation['counts']['total'] ?? 0),
                'processed' => self::bounded_count($automation['counts']['processed'] ?? 0),
            ),
        );
    }

    private static function build_refresh_payload($plugin_version, &$refresh_ready) {
        global $wpdb;

        $tables = array(
            'awards' => self::table_name('academy_awards'),
            'facts' => self::table_name('aat_award_facts'),
            'posters' => self::table_name('aat_posters'),
            'entities' => self::table_name('aat_entities'),
            'posts' => is_object($wpdb) && isset($wpdb->posts) ? (string) $wpdb->posts : self::table_name('posts'),
            'postmeta' => is_object($wpdb) && isset($wpdb->postmeta) ? (string) $wpdb->postmeta : self::table_name('postmeta'),
        );
        $table_health = array(
            'awards' => self::table_exists($tables['awards']),
            'reporting' => self::table_exists($tables['facts']),
            'artwork' => self::table_exists($tables['posters']),
            'people_artwork' => self::table_exists($tables['entities']),
            'posts' => self::table_exists($tables['posts']),
            'postmeta' => self::table_exists($tables['postmeta']),
        );

        $awards = array(
            'records_total' => 0,
            'updated_at' => null,
            'title_id_rows' => null,
            'missing_title_ids' => null,
            'nominee_rows' => null,
            'missing_nominee_ids' => null,
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

        $reporting_rows = null;
        $reporting_readable = false;
        if ($table_health['reporting'] && is_object($wpdb) && method_exists($wpdb, 'get_var')) {
            $raw_reporting_rows = $wpdb->get_var("SELECT COUNT(*) FROM {$tables['facts']}");
            if (is_numeric($raw_reporting_rows)) {
                $reporting_readable = true;
                $reporting_rows = self::bounded_count($raw_reporting_rows);
            }
        }

        $title_artwork = array(
            'records_total' => null,
            'linked_total' => null,
            'invalid_id_total' => null,
            'missing_attachment_total' => null,
        );
        $title_artwork_readable = false;
        if (
            $table_health['artwork']
            && $table_health['posts']
            && is_object($wpdb)
            && method_exists($wpdb, 'get_row')
        ) {
            $row = $wpdb->get_row(
                "SELECT COUNT(*) AS records_total,
                        COALESCE(SUM(posters.attachment_id > 0), 0) AS linked_total,
                        COALESCE(SUM(posters.imdb_id NOT REGEXP '^tt[0-9]{7,9}$'), 0) AS invalid_id_total,
                        COALESCE(SUM(posters.attachment_id > 0 AND posts.ID IS NULL), 0) AS missing_attachment_total
                 FROM {$tables['posters']} posters
                 LEFT JOIN {$tables['posts']} posts ON posts.ID = posters.attachment_id",
                defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A'
            );
            if (is_array($row)) {
                $title_artwork_readable = true;
                $title_artwork = array(
                    'records_total' => self::bounded_count($row['records_total'] ?? 0),
                    'linked_total' => self::bounded_count($row['linked_total'] ?? 0),
                    'invalid_id_total' => self::bounded_count($row['invalid_id_total'] ?? 0),
                    'missing_attachment_total' => self::bounded_count($row['missing_attachment_total'] ?? 0),
                );
            }
        }

        $people_artwork = array('eligible_total' => null, 'mapped_total' => null);
        $people_artwork_readable = false;
        if (
            $table_health['people_artwork']
            && $table_health['posts']
            && $table_health['postmeta']
            && is_object($wpdb)
            && method_exists($wpdb, 'get_row')
        ) {
            $row = $wpdb->get_row(
                "SELECT
                    (SELECT COUNT(*)
                     FROM {$tables['entities']} eligible
                     WHERE eligible.entity_type = 'name'
                       AND eligible.entity_id REGEXP '^nm[0-9]{7,9}$') AS eligible_total,
                    (SELECT COUNT(DISTINCT mapped.entity_id)
                     FROM {$tables['postmeta']} portrait_ids
                     INNER JOIN {$tables['posts']} portrait_posts
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
                $people_artwork_readable = true;
                $eligible = self::bounded_count($row['eligible_total'] ?? 0);
                $mapped = min($eligible, self::bounded_count($row['mapped_total'] ?? 0));
                $people_artwork = array('eligible_total' => $eligible, 'mapped_total' => $mapped);
            }
        }
        $people_artwork_missing = $people_artwork_readable
            ? max(0, $people_artwork['eligible_total'] - $people_artwork['mapped_total'])
            : null;

        $failure_counts = self::get_redacted_failure_counts();
        $reporting_gap = ($awards_readable && $reporting_readable)
            ? abs($awards['records_total'] - $reporting_rows)
            : null;
        $integrity_state = 'healthy';
        if (!$awards_readable) {
            $integrity_state = 'unavailable';
        } elseif (
            !$reporting_readable
            || $reporting_gap > 0
            || $awards['missing_title_ids'] > 0
            || $awards['missing_nominee_ids'] > 0
            || $failure_counts['insert'] > 0
            || $failure_counts['schema'] > 0
        ) {
            $integrity_state = 'needs_attention';
        }

        if (!$title_artwork_readable && !$people_artwork_readable) {
            $artwork_state = 'unavailable';
        } elseif (
            !$title_artwork_readable
            || !$people_artwork_readable
            || $title_artwork['invalid_id_total'] > 0
            || $title_artwork['missing_attachment_total'] > 0
            || $people_artwork_missing > 0
        ) {
            $artwork_state = 'needs_attention';
        } else {
            $artwork_state = 'healthy';
        }

        $automation = self::get_automation_health();
        $available = $awards_readable;
        $state = self::overall_state($available, $integrity_state, $artwork_state, $automation['state']);
        $refresh_ready = $awards_readable
            && $reporting_readable
            && $title_artwork_readable
            && $people_artwork_readable;

        return array(
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA_VERSION,
            'plugin_version' => (string) $plugin_version,
            'available' => $available,
            'state' => $state,
            'label' => self::text('Oscar Ledger'),
            'message' => self::status_message($state),
            'count' => $awards_readable ? $awards['records_total'] : 0,
            'updated_at' => $awards['updated_at'],
            'integrity' => array(
                'state' => $integrity_state,
                'award_rows' => $awards_readable ? $awards['records_total'] : null,
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
                'mapped' => $title_artwork['records_total'],
                'linked' => $title_artwork['linked_total'],
                'invalid_ids' => $title_artwork['invalid_id_total'],
                'missing_attachments' => $title_artwork['missing_attachment_total'],
                'titles' => array(
                    'available' => $title_artwork_readable,
                    'mapped' => $title_artwork['records_total'],
                    'linked' => $title_artwork['linked_total'],
                    'invalid_ids' => $title_artwork['invalid_id_total'],
                    'missing_attachments' => $title_artwork['missing_attachment_total'],
                ),
                'people' => array(
                    'available' => $people_artwork_readable,
                    'eligible' => $people_artwork['eligible_total'],
                    'mapped' => $people_artwork['mapped_total'],
                    'missing' => $people_artwork_missing,
                ),
            ),
            'automation' => $automation,
        );
    }

    private static function overall_state($available, $integrity_state, $artwork_state, $automation_state) {
        if (!$available) {
            return 'unavailable';
        }
        if (
            $integrity_state !== 'healthy'
            || $artwork_state !== 'healthy'
            || in_array($automation_state, array('unavailable', 'unscheduled', 'stale', 'error'), true)
        ) {
            return 'needs_attention';
        }
        return $automation_state === 'running' ? 'running' : 'healthy';
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

    private static function nullable_count($value) {
        return $value === null ? null : self::bounded_count($value);
    }

    private static function safe_timestamp($value) {
        $value = trim((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $value) ? $value : null;
    }

    private static function allow_state($value, $allowed, $fallback) {
        $value = (string) $value;
        return in_array($value, $allowed, true) ? $value : $fallback;
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
                    return self::normalize_automation($health);
                }
            } catch (Throwable $exception) {
                // Refresh failures degrade without exposing dependency details.
            }
        }
        return self::unavailable_automation();
    }

    private static function unavailable_automation() {
        return array(
            'available' => false,
            'state' => 'unavailable',
            'label' => self::text('Entity graph automation'),
            'message' => self::text('Entity graph health is unavailable.'),
            'running' => false,
            'scheduled' => false,
            'stage' => 'unknown',
            'updated_at' => null,
            'counts' => array('total' => 0, 'processed' => 0),
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
