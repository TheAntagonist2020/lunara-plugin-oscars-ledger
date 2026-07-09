<?php
/**
 * Compare the bundled Oscars dataset with the public live data endpoint.
 *
 * The bundled TSV is normalized through the plugin's own import method before
 * comparison, so intentional display cleanup does not look like source drift.
 * This script is read-only: it neither imports data nor changes WordPress.
 *
 * Usage:
 *   php tools/verify-live-dataset.php [endpoint] [csv-path] [--strict-rows]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This verifier must run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
$arguments = array_slice($argv, 1);
$strict_rows = false;
$arguments = array_values(array_filter($arguments, static function ($argument) use (&$strict_rows) {
    if ($argument === '--strict-rows') {
        $strict_rows = true;
        return false;
    }
    return true;
}));
$endpoint = $arguments[0] ?? 'https://lunarafilm.com/wp-admin/admin-ajax.php';
$csv_path = $arguments[1] ?? $root . '/data/oscars.csv';

if (!is_readable($csv_path)) {
    fwrite(STDERR, "Bundled dataset is not readable: {$csv_path}\n");
    exit(1);
}

/*
 * Load the plugin without booting WordPress. The import normalizer only needs
 * these small WordPress-compatible helpers; constructor hooks are inert here.
 */
if (!defined('ABSPATH')) {
    define('ABSPATH', $root . '/');
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) {
        return dirname($file) . DIRECTORY_SEPARATOR;
    }
}

if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url() {
        return 'https://example.invalid/wp-content/plugins/academy-awards-table/';
    }
}

if (!function_exists('add_action')) {
    function add_action() {
        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter() {
        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) {
        return $value;
    }
}

if (!function_exists('add_shortcode')) {
    function add_shortcode() {
        return true;
    }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook() {
        return true;
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled() {
        return false;
    }
}

if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event() {
        return true;
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($value) {
        return strip_tags((string) $value);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value) {
        $value = wp_strip_all_tags((string) $value);
        $value = preg_replace('/[\r\n\t ]+/', ' ', $value);
        return trim((string) $value);
    }
}

if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($value) {
        $value = wp_strip_all_tags((string) $value);
        $value = str_replace(array("\r\n", "\r"), "\n", $value);
        return trim($value);
    }
}

require_once $root . '/academy-awards-table.php';

$plugin = Academy_Awards_Table::get_instance();
$reflection = new ReflectionClass($plugin);
$normalizer = $reflection->getMethod('build_import_db_row');
$normalizer->setAccessible(true);

$fields = array(
    'ceremony',
    'year',
    'class',
    'canonical_category',
    'category',
    'film',
    'film_id',
    'name',
    'nominees',
    'nominee_ids',
    'winner',
    'detail',
    'note',
    'citation',
);

$row_key = static function ($row) use ($fields) {
    $parts = array();
    foreach ($fields as $field) {
        $parts[] = trim((string) ($row[$field] ?? ''));
    }
    return hash('sha256', implode("\x1f", $parts));
};

$row_summary = static function ($row) {
    return array(
        'ceremony' => intval($row['ceremony'] ?? 0),
        'category' => (string) ($row['canonical_category'] ?? ''),
        'film' => (string) ($row['film'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
        'winner' => intval($row['winner'] ?? 0),
    );
};

$award_group_fields = array(
    'ceremony',
    'year',
    'class',
    'canonical_category',
    'category',
    'film',
    'film_id',
    'name',
    'nominees',
);

$award_group_key = static function ($row) use ($award_group_fields) {
    $parts = array();
    foreach ($award_group_fields as $field) {
        $parts[] = trim((string) ($row[$field] ?? ''));
    }
    $id_blob = (string) ($row['film_id'] ?? '') . ' ' . (string) ($row['nominee_ids'] ?? '');
    preg_match_all('/(?:tt|nm|co)\d{5,10}/i', strtolower($id_blob), $matches);
    $entity_ids = array_values(array_unique((array) ($matches[0] ?? array())));
    sort($entity_ids, SORT_STRING);
    $parts[] = implode(',', $entity_ids);
    $parts[] = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', (string) ($row['detail'] ?? '')));
    return hash('sha256', implode("\x1f", $parts));
};

$award_group_summary = static function ($row) {
    return array(
        'ceremony' => intval($row['ceremony'] ?? 0),
        'year' => (string) ($row['year'] ?? ''),
        'class' => (string) ($row['class'] ?? ''),
        'canonical_category' => (string) ($row['canonical_category'] ?? ''),
        'category' => (string) ($row['category'] ?? ''),
        'film' => (string) ($row['film'] ?? ''),
        'film_id' => (string) ($row['film_id'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
        'nominees' => (string) ($row['nominees'] ?? ''),
        'nominee_ids' => (string) ($row['nominee_ids'] ?? ''),
        'detail' => (string) ($row['detail'] ?? ''),
    );
};

$record_award_group = static function (&$groups, &$samples, $row) use ($award_group_key, $award_group_summary) {
    $key = $award_group_key($row);
    if (!isset($groups[$key])) {
        $groups[$key] = array('rows' => 0, 'winners' => 0);
        $samples[$key] = $award_group_summary($row);
    }
    $groups[$key]['rows']++;
    $groups[$key]['winners'] += !empty($row['winner']) ? 1 : 0;
};

$local_counts = array();
$local_samples = array();
$local_award_groups = array();
$local_award_group_samples = array();
$local_rows = 0;
$local_winners = 0;
$handle = fopen($csv_path, 'rb');
$headers = fgetcsv($handle, 0, "\t");

if (!is_array($headers) || empty($headers)) {
    fwrite(STDERR, "Bundled dataset header could not be read.\n");
    exit(1);
}

while (($values = fgetcsv($handle, 0, "\t")) !== false) {
    if ($values === array(null)) {
        continue;
    }

    if (count($values) < count($headers)) {
        $values = array_pad($values, count($headers), '');
    }
    if (count($values) !== count($headers)) {
        fwrite(STDERR, 'Unexpected TSV width at data row ' . ($local_rows + 1) . ".\n");
        exit(1);
    }

    $source_row = array_combine($headers, $values);
    $normalized = $normalizer->invoke($plugin, $source_row);
    $key = $row_key($normalized);
    $local_counts[$key] = ($local_counts[$key] ?? 0) + 1;
    $local_samples[$key] = $row_summary($normalized);
    $record_award_group($local_award_groups, $local_award_group_samples, $normalized);
    $local_winners += !empty($normalized['winner']) ? 1 : 0;
    $local_rows++;
}
fclose($handle);

$post_form = static function ($url, $body) {
    $encoded = http_build_query($body, '', '&');
    $context = stream_context_create(array(
        'http' => array(
            'method' => 'POST',
            'header' => implode("\r\n", array(
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: Lunara-Dataset-Verifier/1.0',
                'Content-Length: ' . strlen($encoded),
            )),
            'content' => $encoded,
            'ignore_errors' => true,
            'timeout' => 60,
        ),
    ));

    $json = @file_get_contents($url, false, $context);
    if ($json === false) {
        throw new RuntimeException('Live endpoint request failed.');
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
        throw new RuntimeException('Live endpoint returned an invalid response.');
    }

    return $decoded;
};

$ca_candidates = array_filter(array(
    ini_get('curl.cainfo'),
    ini_get('openssl.cafile'),
    getenv('CURL_CA_BUNDLE'),
    getenv('SSL_CERT_FILE'),
    'C:\\Program Files\\Git\\usr\\ssl\\certs\\ca-bundle.crt',
    'C:\\Program Files\\Git\\mingw64\\ssl\\certs\\ca-bundle.crt',
    '/etc/ssl/certs/ca-certificates.crt',
    '/etc/pki/tls/certs/ca-bundle.crt',
));
$curl_ca_bundle = '';
foreach ($ca_candidates as $candidate) {
    if (is_readable($candidate)) {
        $curl_ca_bundle = $candidate;
        break;
    }
}

$post_forms = static function ($url, $bodies) use ($curl_ca_bundle) {
    if (!function_exists('curl_multi_init')) {
        throw new RuntimeException('Parallel verification requires the PHP cURL extension.');
    }
    if ($curl_ca_bundle === '') {
        throw new RuntimeException('Parallel verification requires a readable CA bundle; configure curl.cainfo or CURL_CA_BUNDLE.');
    }

    $multi = curl_multi_init();
    $handles = array();
    foreach ($bodies as $key => $body) {
        $handle = curl_init($url);
        curl_setopt_array($handle, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($body, '', '&'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'Lunara-Dataset-Verifier/1.0',
            CURLOPT_HTTPHEADER => array('Content-Type: application/x-www-form-urlencoded'),
            CURLOPT_CAINFO => $curl_ca_bundle,
        ));
        curl_multi_add_handle($multi, $handle);
        $handles[$key] = $handle;
    }

    do {
        $status = curl_multi_exec($multi, $active);
        if ($active && $status === CURLM_OK) {
            curl_multi_select($multi, 1.0);
        }
    } while ($active && $status === CURLM_OK);

    $responses = array();
    foreach ($handles as $key => $handle) {
        $json = curl_multi_getcontent($handle);
        $http_status = intval(curl_getinfo($handle, CURLINFO_RESPONSE_CODE));
        $error = curl_error($handle);
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);

        if ($json === false || $http_status < 200 || $http_status >= 300) {
            throw new RuntimeException("Live endpoint request failed for page {$key}: HTTP {$http_status} {$error}");
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
            throw new RuntimeException("Live endpoint returned an invalid response for page {$key}.");
        }
        $responses[$key] = $decoded;
    }
    curl_multi_close($multi);

    return $responses;
};

$live_counts = array();
$live_samples = array();
$live_award_groups = array();
$live_award_group_samples = array();
$live_ids = array();
$missing_live_ids = 0;
$duplicate_live_ids = 0;
$live_rows = 0;
$live_winners = 0;
$reported_total = null;
$page_size = 200;
$parallel_requests = 4;

$request_body = static function ($start) use ($page_size) {
    return array(
        'action' => 'aat_get_awards_datatable',
        'draw' => 1 + intdiv($start, $page_size),
        'start' => $start,
        'length' => $page_size,
        'category' => '',
        'class' => '',
        'year' => '',
        'ceremony' => 0,
        'winners_only' => 'false',
        'order' => array(array('column' => 1, 'dir' => 'asc')),
    );
};

$record_response = static function ($response) use (
    &$live_counts,
    &$live_samples,
    &$live_award_groups,
    &$live_award_group_samples,
    &$live_ids,
    &$missing_live_ids,
    &$duplicate_live_ids,
    &$live_rows,
    &$live_winners,
    $row_key,
    $row_summary,
    $record_award_group
) {
    foreach ($response['data'] as $row) {
        $source_id = intval($row['id'] ?? 0);
        if ($source_id <= 0) {
            $missing_live_ids++;
        } elseif (isset($live_ids[$source_id])) {
            $duplicate_live_ids++;
        } else {
            $live_ids[$source_id] = true;
        }

        $key = $row_key($row);
        $live_counts[$key] = ($live_counts[$key] ?? 0) + 1;
        $live_samples[$key] = $row_summary($row);
        $record_award_group($live_award_groups, $live_award_group_samples, $row);
        $live_winners += !empty($row['winner']) ? 1 : 0;
        $live_rows++;
    }
};

try {
    $first_response = $post_form($endpoint, $request_body(0));
    $reported_total = intval($first_response['recordsTotal'] ?? 0);
    $record_response($first_response);
    fwrite(STDERR, "Fetched {$live_rows} of {$reported_total} live rows...\n");

    $starts = range($page_size, max($page_size, $reported_total - 1), $page_size);
    foreach (array_chunk($starts, $parallel_requests) as $batch_starts) {
        $bodies = array();
        foreach ($batch_starts as $start) {
            if ($start < $reported_total) {
                $bodies[$start] = $request_body($start);
            }
        }
        if (empty($bodies)) {
            continue;
        }

        $responses = $post_forms($endpoint, $bodies);
        foreach ($responses as $response) {
            $record_response($response);
        }
        fwrite(STDERR, "Fetched {$live_rows} of {$reported_total} live rows...\n");
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

$differences = array();
$difference_groups = 0;
$all_keys = array_unique(array_merge(array_keys($local_counts), array_keys($live_counts)));
foreach ($all_keys as $key) {
    $local_count = intval($local_counts[$key] ?? 0);
    $live_count = intval($live_counts[$key] ?? 0);
    if ($local_count === $live_count) {
        continue;
    }

    $difference_groups++;
    if (count($differences) < 20) {
        $differences[] = array(
            'local_count' => $local_count,
            'live_count' => $live_count,
            'local_row' => $local_samples[$key] ?? null,
            'live_row' => $live_samples[$key] ?? null,
        );
    }
}

$award_group_differences = array();
$award_group_difference_count = 0;
$winner_group_difference_count = 0;
$all_award_group_keys = array_unique(array_merge(array_keys($local_award_groups), array_keys($live_award_groups)));
foreach ($all_award_group_keys as $key) {
    $local_group = $local_award_groups[$key] ?? array('rows' => 0, 'winners' => 0);
    $live_group = $live_award_groups[$key] ?? array('rows' => 0, 'winners' => 0);
    if ($local_group === $live_group) {
        continue;
    }

    $award_group_difference_count++;
    if ($local_group['winners'] !== $live_group['winners']) {
        $winner_group_difference_count++;
    }

    if (count($award_group_differences) < 30) {
        $award_group_differences[] = array(
            'group' => $local_award_group_samples[$key] ?? $live_award_group_samples[$key] ?? null,
            'local' => $local_group,
            'live' => $live_group,
        );
    }
}

$result = array(
    'endpoint' => $endpoint,
    'dataset_sha256' => hash_file('sha256', $csv_path),
    'local_rows' => $local_rows,
    'local_winners' => $local_winners,
    'reported_live_rows' => intval($reported_total),
    'fetched_live_rows' => $live_rows,
    'stable_live_row_ids' => $missing_live_ids === 0 && $duplicate_live_ids === 0 && count($live_ids) === $live_rows,
    'missing_live_row_ids' => $missing_live_ids,
    'duplicate_live_row_ids' => $duplicate_live_ids,
    'live_winners' => $live_winners,
    'winner_delta' => $live_winners - $local_winners,
    'different_exact_row_groups' => $difference_groups,
    'exact_row_difference_samples' => $differences,
    'different_award_groups' => $award_group_difference_count,
    'different_winner_groups' => $winner_group_difference_count,
    'award_group_difference_samples' => $award_group_differences,
    'strict_exact_rows' => $strict_rows,
);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

$has_award_drift = $local_rows !== $reported_total
    || $local_rows !== $live_rows
    || $missing_live_ids > 0
    || $duplicate_live_ids > 0
    || $local_winners !== $live_winners
    || $award_group_difference_count > 0;

if ($has_award_drift || ($strict_rows && $difference_groups > 0)) {
    exit(1);
}

if ($difference_groups > 0) {
    echo "Live award groups and winner flags match; exact credit/display rows contain normalized differences.\n";
} else {
    echo "Live dataset matches the normalized bundled dataset exactly.\n";
}
