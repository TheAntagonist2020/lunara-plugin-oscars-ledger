<?php
/**
 * DRY RUN nominee/person portrait audit.
 *
 * Usage:
 * wp eval-file tools/person-portrait-batch-audit.php -- --csv="E:\nominees and their ids - Sheet1.csv" --batch-size=50 --offset=0 --state=needs_attention --format=json
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "person-portrait-batch-audit must run inside WordPress, usually through wp eval-file.\n");
    exit(1);
}

global $wpdb;

$raw_args = isset($args) && is_array($args) ? $args : array();
$options = array(
    'csv' => '',
    'batch-size' => 50,
    'offset' => 0,
    'state' => 'all',
    'format' => 'table',
    'refresh-tmdb' => false,
);

for ($i = 0; $i < count($raw_args); $i++) {
    $arg = (string) $raw_args[$i];
    if (preg_match('/^--([^=]+)=(.*)$/', $arg, $matches)) {
        $options[$matches[1]] = $matches[2];
        continue;
    }
    if (strpos($arg, '--') === 0) {
        $key = substr($arg, 2);
        $next = isset($raw_args[$i + 1]) ? (string) $raw_args[$i + 1] : '';
        if ($next !== '' && strpos($next, '--') !== 0) {
            $options[$key] = $next;
            $i++;
        } else {
            $options[$key] = true;
        }
    }
}

$batch_size = max(1, min(500, intval($options['batch-size'])));
$offset = max(0, intval($options['offset']));
$state_filter = sanitize_key((string) $options['state']);
$format = sanitize_key((string) $options['format']);
$refresh_tmdb = !empty($options['refresh-tmdb']) && !in_array((string) $options['refresh-tmdb'], array('0', 'false', 'no'), true);
$allowed_states = array('all', 'ready', 'candidate_external', 'needs_attention');
if (!in_array($state_filter, $allowed_states, true)) {
    $state_filter = 'all';
}
if (!in_array($format, array('table', 'json'), true)) {
    $format = 'table';
}

$normalize_int = function($value) {
    return intval(preg_replace('/[^0-9-]/', '', (string) $value));
};

$read_csv_roster = function($path) use ($normalize_int) {
    $path = trim((string) $path, "\"' ");
    if ($path === '' || !is_readable($path)) {
        return array();
    }

    $handle = fopen($path, 'r');
    if (!$handle) {
        return array();
    }

    $headers = fgetcsv($handle);
    if (!is_array($headers)) {
        fclose($handle);
        return array();
    }
    if (!empty($headers[0])) {
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
    }

    $roster = array();
    while (($row = fgetcsv($handle)) !== false) {
        $item = array();
        foreach ($headers as $index => $header) {
            $item[(string) $header] = isset($row[$index]) ? trim((string) $row[$index]) : '';
        }

        $person_id = strtolower(trim((string) ($item['NomineeId'] ?? '')));
        if (!preg_match('/^nm\d{7,9}$/', $person_id)) {
            continue;
        }

        if (!isset($roster[$person_id])) {
            $roster[$person_id] = array(
                'person_id' => $person_id,
                'label' => trim((string) ($item['Nominee'] ?? '')),
                'display_names' => array(),
                'nomination_rows' => 0,
                'wins' => 0,
                'first_ceremony' => 0,
                'first_year' => 0,
                'sample_categories' => array(),
                'sample_films' => array(),
            );
        }

        $display_name = trim((string) ($item['Display Name in Source'] ?? ''));
        if ($display_name !== '') {
            $roster[$person_id]['display_names'][$display_name] = $display_name;
        }

        $nomination_rows = $normalize_int($item['Nomination Rows'] ?? 0);
        $wins = $normalize_int($item['Wins'] ?? 0);
        $first_ceremony = $normalize_int($item['First Ceremony'] ?? 0);
        $first_year = $normalize_int($item['First Year'] ?? 0);
        $sample_category = trim((string) ($item['Sample Category'] ?? ''));
        $sample_film = trim((string) ($item['Sample Film'] ?? ''));

        $roster[$person_id]['nomination_rows'] += max(0, $nomination_rows);
        $roster[$person_id]['wins'] += max(0, $wins);
        if ($first_ceremony > 0 && ($roster[$person_id]['first_ceremony'] === 0 || $first_ceremony < $roster[$person_id]['first_ceremony'])) {
            $roster[$person_id]['first_ceremony'] = $first_ceremony;
        }
        if ($first_year > 0 && ($roster[$person_id]['first_year'] === 0 || $first_year < $roster[$person_id]['first_year'])) {
            $roster[$person_id]['first_year'] = $first_year;
        }
        if ($sample_category !== '') {
            $roster[$person_id]['sample_categories'][$sample_category] = $sample_category;
        }
        if ($sample_film !== '') {
            $roster[$person_id]['sample_films'][$sample_film] = $sample_film;
        }
    }
    fclose($handle);

    $rows = array_values($roster);
    usort($rows, function($left, $right) {
        $label_cmp = strcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        if ($label_cmp !== 0) {
            return $label_cmp;
        }
        return strcmp((string) ($left['person_id'] ?? ''), (string) ($right['person_id'] ?? ''));
    });

    return $rows;
};

$read_database_roster = function() use ($wpdb) {
    $entities_table = $wpdb->prefix . 'aat_entities';
    $rows = $wpdb->get_results(
        "SELECT entity_id AS person_id, label
         FROM {$entities_table}
         WHERE entity_type = 'name'
         ORDER BY label ASC, entity_id ASC",
        ARRAY_A
    );
    if (!is_array($rows)) {
        return array();
    }
    return array_map(function($row) {
        return array(
            'person_id' => strtolower(trim((string) ($row['person_id'] ?? ''))),
            'label' => trim((string) ($row['label'] ?? '')),
            'display_names' => array(),
            'nomination_rows' => 0,
            'wins' => 0,
            'first_ceremony' => 0,
            'first_year' => 0,
            'sample_categories' => array(),
            'sample_films' => array(),
        );
    }, $rows);
};

$csv_path = trim((string) $options['csv'], "\"' ");
$roster = $csv_path !== '' ? $read_csv_roster($csv_path) : array();
$source = !empty($roster) ? 'csv' : 'wordpress';
if (empty($roster)) {
    $roster = $read_database_roster();
}

$aat = class_exists('Academy_Awards_Table') ? Academy_Awards_Table::get_instance() : null;
$resolve_local = null;
if ($aat) {
    try {
        $resolve_local = new ReflectionMethod($aat, 'resolve_profile_attachment_for_person');
        $resolve_local->setAccessible(true);
    } catch (ReflectionException $e) {
        $resolve_local = null;
    }
}

$audit_row = function($item) use ($aat, $resolve_local, $refresh_tmdb) {
    $person_id = strtolower(trim((string) ($item['person_id'] ?? '')));
    $label = trim((string) ($item['label'] ?? ''));
    $result = array(
        'person_id' => $person_id,
        'label' => $label,
        'state' => 'needs_attention',
        'visual_source' => 'none',
        'local_attachment_id' => 0,
        'tmdb_has_profile' => false,
        'tmdb_has_context_backdrop' => false,
        'nomination_rows' => intval($item['nomination_rows'] ?? 0),
        'wins' => intval($item['wins'] ?? 0),
        'first_ceremony' => intval($item['first_ceremony'] ?? 0),
        'first_year' => intval($item['first_year'] ?? 0),
        'sample_category' => implode(' | ', array_slice(array_values((array) ($item['sample_categories'] ?? array())), 0, 3)),
        'sample_film' => implode(' | ', array_slice(array_values((array) ($item['sample_films'] ?? array())), 0, 3)),
        'profile_url' => '',
        'notes' => array(),
    );

    if (!preg_match('/^nm\d{7,9}$/', $person_id)) {
        $result['notes'][] = 'Invalid IMDb person ID.';
        return $result;
    }

    if ($aat && method_exists($aat, 'build_entity_url_from_id')) {
        $result['profile_url'] = (string) $aat->build_entity_url_from_id($person_id);
    }

    $local = array();
    if ($resolve_local) {
        $local = $resolve_local->invoke($aat, $person_id, $label);
    }
    $attachment_id = intval($local['attachment_id'] ?? 0);
    if ($attachment_id > 0) {
        $result['state'] = 'ready';
        $result['visual_source'] = 'local-media-library';
        $result['local_attachment_id'] = $attachment_id;
        $result['notes'][] = 'Local verified portrait is already connected.';
        return $result;
    }

    $tmdb = get_transient('aat_tmdb_person_v2_' . $person_id);
    if ($refresh_tmdb && $aat && method_exists($aat, 'get_tmdb_person_data_for_imdb_id')) {
        $tmdb = $aat->get_tmdb_person_data_for_imdb_id($person_id);
    }
    if (is_array($tmdb) && !empty($tmdb['profile_full'])) {
        $result['state'] = 'candidate_external';
        $result['visual_source'] = 'tmdb-person-profile';
        $result['tmdb_has_profile'] = true;
        $result['notes'][] = 'TMDb profile image candidate exists; review before import.';
        return $result;
    }
    if (is_array($tmdb) && !empty($tmdb['backdrop_full'])) {
        $result['tmdb_has_context_backdrop'] = true;
        $result['notes'][] = 'TMDb only has title/backdrop context; not acceptable as a portrait.';
    } else {
        $result['notes'][] = 'No local portrait or cached external portrait candidate.';
    }

    return $result;
};

$roster_batch = array_slice($roster, $offset, $batch_size);
$audited = array();
foreach ($roster_batch as $item) {
    $row = $audit_row($item);
    if ($state_filter !== 'all' && $row['state'] !== $state_filter) {
        continue;
    }
    $audited[] = $row;
}

$summary = array(
    'mode' => 'DRY RUN',
    'source' => $source,
    'csv' => $csv_path,
    'state_filter' => $state_filter,
    'offset' => $offset,
    'batch_size' => $batch_size,
    'total_roster' => count($roster),
    'batch_roster_count' => count($roster_batch),
    'matching_in_batch' => count($audited),
    'returned' => count($audited),
    'refresh_tmdb' => $refresh_tmdb,
);

if ($format === 'json') {
    echo wp_json_encode(array('summary' => $summary, 'rows' => $audited), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    return;
}

echo "person-portrait-batch-audit / DRY RUN\n";
foreach ($summary as $key => $value) {
    if (is_bool($value)) {
        $value = $value ? 'true' : 'false';
    }
    echo $key . ': ' . $value . "\n";
}
echo "\n";
foreach ($audited as $row) {
    echo implode("\t", array(
        $row['state'],
        $row['person_id'],
        $row['label'],
        $row['visual_source'],
        $row['local_attachment_id'],
        $row['nomination_rows'],
        $row['wins'],
        $row['first_year'],
        $row['sample_category'],
        $row['sample_film'],
        $row['profile_url'],
        implode(' ', $row['notes']),
    )) . "\n";
}
