<?php
/**
 * Oscars Ledger bundle builder (plan-v5 §4.1; work unit U01). Not deployed: tests/ is
 * deployignored. CLI only.
 *
 * Generation:
 *   php tests/tools/build-ledger-bundle.php --baseline=legacy|<bundle_id>
 *       [--baseline-commit=<sha>] [--mode=dry_run|swap|rollback]
 *       [--reswap-of=<bundle_id>|--reswap-of=] [--dataset-version=YYYY.MM.DD-N]
 *       [--skip-workbook] [--root=<plugin tree>]
 * Check (CI, through tests/ledger-bundle-contract.php; needs only the checked-out tree):
 *   php tests/tools/build-ledger-bundle.php --check [--root=<plugin tree>]
 *
 * --baseline is required when generating. Read it from production first:
 * /wp-json/lunara-ledger/v1/status -> ingest.live.bundle_id (null means legacy; before
 * R1 is live, legacy). For a <bundle_id>, docs/database/baselines/<bundle_id>.json.gz
 * must exist.
 *
 * Steps:
 *   0. Redaction: redact_evidence() over the evidence-bearing values only
 *      (Ledger_Redaction::REDACT_KEYS) of docs/database/{corrections, additions,
 *      needs-review, accepted-drift}.json and docs/database/tools/{adjudications,
 *      editor_decisions}.json, written back through AAT_Ledger_Json::encode_shipped().
 *      docs/database/additions.json ([]) and accepted-drift.json (empty lists) are
 *      created when absent. --check rewrites nothing and fails with
 *      evidence_not_redacted when a file would change.
 *   1. The source codec (includes/class-aat-ledger-source.php) reads data/oscars.csv
 *      and the overlay exactly as shipped and refuses any codec violation.
 *   2. data/ledger/entities.tsv and titles.tsv are maintained: every referenced ID once,
 *      sorted, new IDs with an empty reference name, unreferenced rows dropped.
 *      name-overrides.tsv is created header-only when absent.
 *   3. Written: data/ledger/{corrections, additions, needs-review, accepted-drift}.json
 *      (byte copies of the redacted docs/database files), the TSVs, manifest.json;
 *      docs/database/oscars-corrected.tsv; tests/fixtures/ledger/decades.json;
 *      docs/database/baselines/<bundle_id>.json.gz (this bundle's summary, so a later
 *      bundle can use it as its baseline without git history). Every other file in
 *      docs/database/baselines/ except the recorded baseline's is deleted.
 *   4. docs/database/oscars-corrected.xlsx is regenerated with
 *      docs/database/tools/make_workbook.py when the overlay changed and openpyxl is
 *      importable (workbook_tool_missing when it is stale and cannot be rebuilt); its
 *      Corrections and full_data dimensions must match manifest.expected
 *      (workbook_stale). The step is skipped when the file is absent.
 *
 * --check regenerates everything in memory with the baseline recorded in the committed
 * manifest (never git history), compares every byte (a *.gz after gzdecode), checks the
 * baselines directory and the workbook dimensions, and exits 1 on any difference.
 *
 * Exit codes: 0 success; 1 refusal or check failure (each line names its code);
 * 2 usage.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

const LEDGER_BUILDER_USAGE = <<<'TXT'
Usage:
  php tests/tools/build-ledger-bundle.php --baseline=legacy|<bundle_id> [--baseline-commit=<sha>]
      [--mode=dry_run|swap|rollback] [--reswap-of=<bundle_id>|--reswap-of=]
      [--dataset-version=YYYY.MM.DD-N] [--skip-workbook] [--root=<plugin tree>]
  php tests/tools/build-ledger-bundle.php --check [--root=<plugin tree>]

--baseline is required when generating: read /wp-json/lunara-ledger/v1/status
ingest.live.bundle_id from production first (null or no ledger yet: legacy).
TXT;

/** Authored JSON files of step 0: docs path => REDACT_KEYS name. */
const LEDGER_AUTHORED = array(
    'docs/database/corrections.json' => 'corrections.json',
    'docs/database/additions.json' => 'additions.json',
    'docs/database/needs-review.json' => 'needs-review.json',
    'docs/database/accepted-drift.json' => 'accepted-drift.json',
    'docs/database/tools/adjudications.json' => 'tools/adjudications.json',
    'docs/database/tools/editor_decisions.json' => 'tools/editor_decisions.json',
);

/** The overlay files copied byte for byte into the bundle: docs path => bundle path. */
const LEDGER_COPIES = array(
    'docs/database/corrections.json' => 'data/ledger/corrections.json',
    'docs/database/additions.json' => 'data/ledger/additions.json',
    'docs/database/needs-review.json' => 'data/ledger/needs-review.json',
    'docs/database/accepted-drift.json' => 'data/ledger/accepted-drift.json',
);

const LEDGER_BASELINES_DIR = 'docs/database/baselines';
const LEDGER_CORRECTED_TSV = 'docs/database/oscars-corrected.tsv';
const LEDGER_WORKBOOK = 'docs/database/oscars-corrected.xlsx';
const LEDGER_DECADES = 'tests/fixtures/ledger/decades.json';
const LEDGER_SUMMARY_SCHEMA = 'lunara-oscars-ledger-baseline/1';
const LEDGER_DISPLAY_NAME_POLICY = 'credit_mode/1';

const LEDGER_LICENCE = array(
    'file' => 'data/LICENSE-oscar_data.txt',
    'spdx' => 'BSD-2-Clause',
    'copyright' => 'Copyright (c) 2022, David V. Lu!!',
    'upstream' => 'https://github.com/DLu/oscar_data',
);

/**
 * The manifest's human-authored sentinel facts (plan-v5 §4.6.5 V3). They name
 * identities and verbatim strings, never counts. The importer's sentinels() checks them
 * on the stage and live tables; the builder checks the ones the source layer can see.
 */
const LEDGER_ASSERTIONS = array(
    array('id' => 'name:nm0380965', 'imdb_id' => 'nm0380965', 'name' => 'Jean Hersholt'),
    array('id' => 'name:nm0604960', 'imdb_id' => 'nm0604960', 'name' => 'Ralph Morgan'),
    array('id' => 'name:nm0916990', 'imdb_id' => 'nm0916990', 'name' => 'Paul Francis Webster'),
    array('id' => 'name:nm0001053', 'imdb_id' => 'nm0001053', 'name' => 'Ethan Coen'),
    array('id' => 'name:nm0569222', 'imdb_id' => 'nm0569222', 'name' => 'Barney "Chick" McGill'),
    array('id' => 'title:tt2175842', 'imdb_id' => 'tt2175842', 'title' => 'Maggie Simpson in "The Longest Daycare"'),
    array('id' => 'slots:8165', 'source_row' => 8165, 'slots' => array(array('Roderick Jaynes', array('nm0001053', 'nm0001054')))),
    array('id' => 'slots:965', 'source_row' => 965, 'slots' => array(
        array('The Motion Picture Relief Fund', array()),
        array('Jean Hersholt', array('nm0380965')),
        array('Ralph Morgan', array('nm0604960')),
        array('Ralph Block', array('nm0088759')),
        array('Conrad Nagel', array('nm0619261')),
    )),
    array('id' => 'slots:526', 'source_row' => 526, 'slots' => array(
        array('United Artists', array('co0026841')),
        array('Thomas T. Moulton', array('nm0609771')),
    )),
    array('id' => 'slots:1814:2', 'source_row' => 1814, 'slot' => 2, 'imdb_id' => 'nm0916990'),
    array('id' => 'slots:2564:1', 'source_row' => 2564, 'slot' => 1, 'imdb_id' => 'nm0772834'),
    array('id' => 'title_entity:tt0019553', 'imdb_id' => 'tt0019553'),
    array('id' => 'label:ceremony:1', 'ceremony' => 1, 'year_label' => '1927/28'),
    array('id' => 'label:ceremony:6', 'ceremony' => 6, 'year_label' => '1932/33'),
    array('id' => 'note:12067', 'source_row' => 12067, 'note_prefix' => 'NOTE: NOTE:'),
    array('id' => 'citation_only:10475', 'source_row' => 10475, 'citation' => 'To all those who built and operated film laboratories, for over a century of service to the motion picture industry.'),
    array('id' => 'additions:is_added', 'is_added' => 1),
);

/** Accepted exceptions (plan-v5 §4.1): V2's duplicate-entity check allows exactly these. */
const LEDGER_ACCEPTED_EXCEPTIONS = array(
    array('rule' => 'duplicate_entity', 'source_row' => 4507, 'imdb_id' => 'co0007143', 'reason' => "MGM British and MGM share MGM's company ID"),
);

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------

final class Ledger_Builder_Refusal extends RuntimeException
{
    /** @var string */
    public $code_name;

    public function __construct(string $code_name, string $message)
    {
        parent::__construct($message);
        $this->code_name = $code_name;
    }
}

/** @return never */
function ledger_builder_refuse(string $code, string $message)
{
    throw new Ledger_Builder_Refusal($code, $message);
}

function ledger_builder_read(string $root, string $relpath): ?string
{
    $path = $root . '/' . $relpath;
    if (!is_file($path)) {
        return null;
    }
    $bytes = file_get_contents($path);
    return is_string($bytes) ? $bytes : null;
}

/**
 * Read one member of a zip archive: ZipArchive when loaded, else a small reader of the
 * central directory (stored and deflated members), so the workbook check runs wherever
 * zlib does.
 */
function ledger_zip_member(string $path, string $name): ?string
{
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $content = $zip->getFromName($name);
        $zip->close();
        return is_string($content) ? $content : null;
    }
    $bytes = file_get_contents($path);
    if (!is_string($bytes)) {
        return null;
    }
    $eocd = strrpos($bytes, "PK\x05\x06");
    if ($eocd === false) {
        return null;
    }
    $entries = unpack('ventries', substr($bytes, $eocd + 10, 2))['entries'];
    $offset = unpack('Voffset', substr($bytes, $eocd + 16, 4))['offset'];
    for ($i = 0; $i < $entries; $i++) {
        if (substr($bytes, $offset, 4) !== "PK\x01\x02") {
            return null;
        }
        $h = unpack('vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen', substr($bytes, $offset + 10, 26));
        $member = substr($bytes, $offset + 46, $h['nlen']);
        $local = unpack('Vlocal', substr($bytes, $offset + 42, 4))['local'];
        $offset += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
        if ($member !== $name) {
            continue;
        }
        $l = unpack('vnlen/velen', substr($bytes, $local + 26, 4));
        $data = substr($bytes, $local + 30 + $l['nlen'] + $l['elen'], $h['csize']);
        if ($h['method'] === 0) {
            return $data;
        }
        if ($h['method'] === 8) {
            $out = @gzinflate($data);
            return is_string($out) ? $out : null;
        }
        return null;
    }
    return null;
}

/**
 * Data-row counts of the named sheets, from each sheet's <dimension ref>, resolved
 * through xl/workbook.xml and xl/_rels/workbook.xml.rels. The header row is excluded.
 *
 * @param array<int, string> $sheets
 * @return array<string, int|null>
 */
function ledger_workbook_rows(string $path, array $sheets): array
{
    $out = array_fill_keys($sheets, null);
    $workbook = ledger_zip_member($path, 'xl/workbook.xml');
    $rels = ledger_zip_member($path, 'xl/_rels/workbook.xml.rels');
    if ($workbook === null || $rels === null) {
        return $out;
    }
    $targets = array();
    if (preg_match_all('~<Relationship\b[^>]*>~', $rels, $m)) {
        foreach ($m[0] as $tag) {
            if (preg_match('~\bId="([^"]+)"~', $tag, $id) && preg_match('~\bTarget="([^"]+)"~', $tag, $target)) {
                $targets[$id[1]] = $target[1];
            }
        }
    }
    if (preg_match_all('~<(?:\w+:)?sheet\b[^>]*>~', $workbook, $m)) {
        foreach ($m[0] as $tag) {
            if (!preg_match('~\bname="([^"]+)"~', $tag, $name) || !preg_match('~\br:id="([^"]+)"~', $tag, $rid)) {
                continue;
            }
            $sheet = html_entity_decode($name[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            if (!array_key_exists($sheet, $out) || !isset($targets[$rid[1]])) {
                continue;
            }
            $target = $targets[$rid[1]];
            $member = strpos($target, '/') === 0 ? substr($target, 1) : 'xl/' . $target;
            $xml = ledger_zip_member($path, $member);
            if ($xml !== null && preg_match('~<dimension ref="[A-Z]+\d+(?::[A-Z]+(\d+))?"~', $xml, $dim)) {
                $out[$sheet] = isset($dim[1]) ? (int) $dim[1] - 1 : 0;
            }
        }
    }
    return $out;
}

function ledger_python_has_openpyxl(): bool
{
    $out = array();
    $status = 1;
    @exec('python3 -c "import openpyxl" 2>/dev/null', $out, $status);
    return $status === 0;
}

// ---------------------------------------------------------------------------
// The build
// ---------------------------------------------------------------------------

/**
 * Plan the bundle: every file the tree should hold, in memory.
 *
 * @param array<string, mixed> $opts
 * @return array<string, mixed>
 */
function ledger_plan_bundle(string $root, array $opts): array
{
    $check = !empty($opts['check']);
    $problems = array();
    $notes = array();
    $files = array();

    $committed_bytes = ledger_builder_read($root, AAT_Ledger_Source::FILES['manifest']);
    $committed = null;
    if ($committed_bytes !== null) {
        $committed = json_decode($committed_bytes, true);
        if (!is_array($committed)) {
            ledger_builder_refuse('manifest_invalid', 'data/ledger/manifest.json is not valid JSON');
        }
    } elseif ($check) {
        ledger_builder_refuse('manifest_missing', 'data/ledger/manifest.json is missing, so --check has no recorded baseline');
    }

    // The recorded baseline (fail early, before any heavy work).
    if ($check) {
        $baseline = $committed['simulation']['baseline'] ?? null;
        if (!is_array($baseline) || !in_array($baseline['kind'] ?? null, array('legacy', 'bundle'), true)) {
            ledger_builder_refuse('baseline_invalid', 'manifest.simulation.baseline is missing or has no valid kind');
        }
    } else {
        $baseline = ledger_resolve_baseline($root, (string) $opts['baseline'], $opts['baseline_commit'] ?? null, $committed);
    }
    if ($baseline['kind'] === 'bundle') {
        $summary_bytes = ledger_builder_read($root, (string) $baseline['summary']);
        if ($summary_bytes === null) {
            ledger_builder_refuse('baseline_missing', "the recorded baseline summary {$baseline['summary']} is absent");
        }
        $summary_json = @gzdecode($summary_bytes);
        if (!is_string($summary_json) || !hash_equals((string) $baseline['summary_sha256'], hash('sha256', $summary_json))) {
            $problems[] = array('baseline_summary_mismatch', "{$baseline['summary']} does not match manifest.simulation.baseline.summary_sha256");
        }
    }

    // Step 0: redaction of the authored JSON files, in the shipped layout.
    $authored = array();
    $flag = function (string $code, string $message, string $note) use ($check, &$problems, &$notes) {
        if ($check) {
            $problems[] = array($code, $message);
        } else {
            $notes[] = $note;
        }
    };
    foreach (LEDGER_AUTHORED as $relpath => $relname) {
        $bytes = ledger_builder_read($root, $relpath);
        if ($bytes === null) {
            if ($relname === 'additions.json') {
                $authored[$relpath] = AAT_Ledger_Json::encode_shipped(array());
                $flag('check_mismatch', "{$relpath} is missing", "created {$relpath} as []");
                continue;
            }
            if ($relname === 'accepted-drift.json') {
                $authored[$relpath] = AAT_Ledger_Json::encode_shipped(array('schema' => AAT_Ledger_Source::DRIFT_SCHEMA, 'items' => array(), 'id_pins' => array(), 'restored' => array(), 'retire' => array()));
                $flag('check_mismatch', "{$relpath} is missing", "created {$relpath} with empty lists");
                continue;
            }
            if (strpos($relname, 'tools/') === 0) {
                continue;
            }
            ledger_builder_refuse('input_missing', "{$relpath} is missing");
        }
        $data = json_decode($bytes, true, 512, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE) {
            ledger_builder_refuse('json_invalid', "{$relpath} is not valid JSON (" . json_last_error_msg() . ')');
        }
        $changes = array();
        foreach (Ledger_Redaction::REDACT_KEYS[$relname] as $selector) {
            $changes = array_merge($changes, ledger_json_transform($data, $selector, 'redact_evidence'));
        }
        $encoded = AAT_Ledger_Json::encode_shipped($data);
        $authored[$relpath] = $encoded;
        if ($changes && $check) {
            foreach ($changes as $change) {
                $problems[] = array('evidence_not_redacted', "{$relpath} {$change[0]} is not in redacted form");
            }
        } elseif ($changes) {
            $notes[] = 'step 0: redacted ' . count($changes) . " evidence value(s) in {$relpath}";
        } elseif ($encoded !== $bytes) {
            $flag('authored_layout_mismatch', "{$relpath} is not in the shipped JSON layout", "step 0: rewrote {$relpath} in the shipped JSON layout");
        }
    }
    foreach ($authored as $relpath => $bytes) {
        $files[$relpath] = $bytes;
    }

    // Step 1: the source codec.
    $csv = ledger_builder_read($root, AAT_Ledger_Source::FILES['csv']);
    if ($csv === null) {
        ledger_builder_refuse('input_missing', 'data/oscars.csv is missing');
    }
    $result = AAT_Ledger_Source::load(array(
        'csv' => $csv,
        'corrections' => $authored['docs/database/corrections.json'],
        'additions' => $authored['docs/database/additions.json'],
        'needs_review' => $authored['docs/database/needs-review.json'],
        'accepted_drift' => $authored['docs/database/accepted-drift.json'],
    ));
    ledger_check_assertions($result);

    // Step 2: the maintained reference files.
    $referenced = AAT_Ledger_Source::referenced_ids($result['rows']);
    $entities = AAT_Ledger_Source::maintain_reference_tsv('entities', ledger_builder_read($root, AAT_Ledger_Source::FILES['entities']), $referenced['entities']);
    $titles = AAT_Ledger_Source::maintain_reference_tsv('titles', ledger_builder_read($root, AAT_Ledger_Source::FILES['titles']), $referenced['titles']);
    $overrides = ledger_builder_read($root, AAT_Ledger_Source::FILES['name_overrides']);
    if ($overrides === null) {
        $overrides = implode("\t", AAT_Ledger_Source::NAME_OVERRIDES_HEADER) . "\n";
    }
    AAT_Ledger_Source::check_references($result, $entities, $titles, $overrides);

    $licence = ledger_builder_read($root, LEDGER_LICENCE['file']);
    if ($licence === null || strpos($licence, 'BSD 2-Clause License') !== 0 || strpos($licence, LEDGER_LICENCE['copyright']) === false) {
        ledger_builder_refuse('licence_invalid', LEDGER_LICENCE['file'] . " must hold the DLu/oscar_data BSD 2-Clause notice verbatim");
    }

    // Step 3: bundle files.
    foreach (LEDGER_COPIES as $from => $to) {
        $files[$to] = $authored[$from];
    }
    $files[AAT_Ledger_Source::FILES['entities']] = $entities;
    $files[AAT_Ledger_Source::FILES['titles']] = $titles;
    $files[AAT_Ledger_Source::FILES['name_overrides']] = $overrides;
    $files[LEDGER_CORRECTED_TSV] = $result['corrected_tsv'];
    $files[LEDGER_DECADES] = AAT_Ledger_Json::encode_shipped(ledger_decades($result['rows']));

    $sha = function (string $relpath) use (&$files) {
        return hash('sha256', $files[$relpath]);
    };
    $by_reason = new stdClass();
    foreach ($result['corrections']['by_reason'] as $pair) {
        $by_reason->{$pair[0]} = $pair[1];
    }
    $manifest = array(
        'schema' => AAT_Ledger_Source::BUNDLE_SCHEMA,
        'bundle_id' => '',
        'dataset_version' => '',
        'mode' => '',
        'source' => array(
            'file' => AAT_Ledger_Source::FILES['csv'],
            'sha256' => hash('sha256', $csv),
            'codec' => AAT_Ledger_Source::CODEC,
            'rows' => $result['upstream_rows'],
            'header' => AAT_Ledger_Source::HEADER,
        ),
        'overlay' => array(
            'corrections' => array(
                'file' => AAT_Ledger_Source::FILES['corrections'],
                'sha256' => $sha(AAT_Ledger_Source::FILES['corrections']),
                'count' => $result['corrections']['count'],
                'cells' => $result['corrections']['cells'],
                'multi_corrected_cells' => $result['corrections']['multi_corrected_cells'],
                'by_reason' => $by_reason,
            ),
            'additions' => array(
                'file' => AAT_Ledger_Source::FILES['additions'],
                'sha256' => $sha(AAT_Ledger_Source::FILES['additions']),
                'count' => $result['additions']['count'],
                'first_source_row' => $result['additions']['first_source_row'],
                'last_source_row' => $result['additions']['last_source_row'],
                'citation_only' => $result['additions']['citation_only'],
            ),
        ),
        'corrected_sha256' => $result['corrected_sha256'],
        'reference' => array(
            'entities' => array('file' => AAT_Ledger_Source::FILES['entities'], 'sha256' => $sha(AAT_Ledger_Source::FILES['entities'])) + $result['reference']['entities'],
            'titles' => array('file' => AAT_Ledger_Source::FILES['titles'], 'sha256' => $sha(AAT_Ledger_Source::FILES['titles'])) + $result['reference']['titles'],
            'name_overrides' => array('file' => AAT_Ledger_Source::FILES['name_overrides'], 'sha256' => $sha(AAT_Ledger_Source::FILES['name_overrides'])) + $result['reference']['name_overrides'],
            'needs_review' => array(
                'file' => AAT_Ledger_Source::FILES['needs_review'],
                'sha256' => $sha(AAT_Ledger_Source::FILES['needs_review']),
                'items' => $result['needs_review']['items'],
                'unresolved_items' => $result['needs_review']['unresolved_items'],
                'flagged_pairs' => $result['needs_review']['flagged_pairs'],
            ),
            'accepted_drift' => array('file' => AAT_Ledger_Source::FILES['accepted_drift'], 'sha256' => $sha(AAT_Ledger_Source::FILES['accepted_drift'])) + $result['accepted_drift'],
        ),
        'licence' => array('file' => LEDGER_LICENCE['file'], 'sha256' => hash('sha256', $licence), 'spdx' => LEDGER_LICENCE['spdx'], 'copyright' => LEDGER_LICENCE['copyright'], 'upstream' => LEDGER_LICENCE['upstream']),
        'display_name_policy' => LEDGER_DISPLAY_NAME_POLICY,
        'expected' => $result['expected'],
        'simulation' => array('baseline' => $baseline),
        'change_bounds' => array(
            'max_ids_new' => $result['additions']['count'] + $result['accepted_drift']['restored'],
            'max_ids_retired' => $result['accepted_drift']['retire'],
            'allow_shrink' => false,
        ),
        'assertions' => LEDGER_ASSERTIONS,
        'accepted_exceptions' => LEDGER_ACCEPTED_EXCEPTIONS,
    );
    $bundle_id = AAT_Ledger_Source::bundle_id($manifest);
    $manifest['bundle_id'] = $bundle_id;

    // Mode, dataset_version and reswap_of: kept from the committed manifest unless set.
    $modes = array('dry_run', 'swap', 'rollback');
    $mode = $check ? ($committed['mode'] ?? null) : ($opts['mode'] ?? ($committed['mode'] ?? 'dry_run'));
    if (!in_array($mode, $modes, true)) {
        ledger_builder_refuse('mode_invalid', 'mode must be one of ' . implode(', ', $modes));
    }
    $manifest['mode'] = $mode;
    $committed_version = is_string($committed['dataset_version'] ?? null) ? $committed['dataset_version'] : null;
    if ($check) {
        $version = (string) $committed_version;
    } elseif (isset($opts['dataset_version'])) {
        $version = $opts['dataset_version'];
    } elseif ($committed_version !== null && ($committed['bundle_id'] ?? null) === $bundle_id) {
        $version = $committed_version;
    } else {
        $today = gmdate('Y.m.d');
        $n = 1;
        if ($committed_version !== null && preg_match('~^' . preg_quote($today, '~') . '-(\d+)$~', $committed_version, $m)) {
            $n = (int) $m[1] + 1;
        }
        $version = $today . '-' . $n;
    }
    if (!preg_match('~^\d{4}\.\d{2}\.\d{2}-\d+$~', $version)) {
        ledger_builder_refuse('dataset_version_invalid', "dataset_version '{$version}' is not YYYY.MM.DD-N");
    }
    $manifest['dataset_version'] = $version;
    $reswap = null;
    if ($check) {
        $reswap = $committed['reswap_of'] ?? null;
    } elseif (array_key_exists('reswap_of', $opts)) {
        $reswap = $opts['reswap_of'] === '' ? null : $opts['reswap_of'];
    } elseif (($committed['reswap_of'] ?? null) === $bundle_id) {
        $reswap = $bundle_id;
    }
    if ($reswap !== null) {
        if ($reswap !== $bundle_id) {
            $message = "reswap_of must equal this bundle's own bundle_id ({$bundle_id}); a different bundle imports without it";
            if (!$check) {
                ledger_builder_refuse('reswap_of_mismatch', $message);
            }
            $problems[] = array('reswap_of_mismatch', $message);
        }
        $manifest['reswap_of'] = $reswap;
    }
    $files[AAT_Ledger_Source::FILES['manifest']] = AAT_Ledger_Json::encode_shipped($manifest);

    // This bundle's derivation summary, and the baselines directory.
    $own_summary = LEDGER_BASELINES_DIR . '/' . $bundle_id . '.json.gz';
    $files[$own_summary] = gzencode(ledger_summary($bundle_id, $result), 9);
    $keep = array($own_summary => true);
    if ($baseline['kind'] === 'bundle') {
        $keep[(string) $baseline['summary']] = true;
    }
    $delete = array();
    if (is_dir($root . '/' . LEDGER_BASELINES_DIR)) {
        foreach (scandir($root . '/' . LEDGER_BASELINES_DIR) as $entry) {
            $relpath = LEDGER_BASELINES_DIR . '/' . $entry;
            if ($entry === '.' || $entry === '..' || isset($keep[$relpath])) {
                continue;
            }
            $delete[] = $relpath;
        }
    }

    // Every file in data/ledger/ must be one the manifest records.
    $listed = array(AAT_Ledger_Source::FILES['manifest'] => true);
    foreach (AAT_Ledger_Source::manifest_file_hashes($manifest) as $pair) {
        $listed[$pair[0]] = true;
    }
    if (is_dir($root . '/data/ledger')) {
        foreach (scandir($root . '/data/ledger') as $entry) {
            if ($entry !== '.' && $entry !== '..' && !isset($listed['data/ledger/' . $entry])) {
                $message = "data/ledger/{$entry} is not a bundle file the manifest records";
                if (!$check) {
                    ledger_builder_refuse('bundle_unlisted_file', $message);
                }
                $problems[] = array('bundle_unlisted_file', $message);
            }
        }
    }

    return array(
        'files' => $files,
        'delete' => $delete,
        'manifest' => $manifest,
        'committed' => $committed,
        'result' => $result,
        'problems' => $problems,
        'notes' => $notes,
    );
}

/**
 * @param array<string, mixed>|null $committed
 * @return array<string, mixed>
 */
function ledger_resolve_baseline(string $root, string $value, ?string $commit, ?array $committed): array
{
    if ($value === 'legacy') {
        return array('kind' => 'legacy');
    }
    if (!preg_match('~^[0-9a-f]{64}$~', $value)) {
        ledger_builder_refuse('baseline_invalid', "--baseline must be legacy or a 64-hex bundle_id (got '{$value}')");
    }
    $summary = LEDGER_BASELINES_DIR . '/' . $value . '.json.gz';
    $bytes = ledger_builder_read($root, $summary);
    if ($bytes === null) {
        ledger_builder_refuse('baseline_missing', "{$summary} is absent: a bundle baseline needs its committed derivation summary");
    }
    $json = @gzdecode($bytes);
    $data = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($data) || ($data['bundle_id'] ?? null) !== $value) {
        ledger_builder_refuse('baseline_invalid', "{$summary} is not the derivation summary of bundle {$value}");
    }
    if ($commit === null) {
        $recorded = $committed['simulation']['baseline'] ?? array();
        $commit = (($recorded['bundle_id'] ?? null) === $value) ? ($recorded['commit'] ?? null) : null;
    }
    return array('kind' => 'bundle', 'bundle_id' => $value, 'commit' => $commit, 'summary' => $summary, 'summary_sha256' => hash('sha256', $json));
}

/**
 * {decade: [ceremony…]}: decade = floor(film_year_start / 10) × 10 of each ceremony's
 * year label (the first Year per Ceremony), the hubs' rule (plan-v5 §6.3).
 *
 * @param array<int, array<int, string>> $rows
 * @return array<string, array<int, int>>
 */
function ledger_decades(array $rows): array
{
    $labels = array();
    foreach ($rows as $cells) {
        $ceremony = (int) $cells[0];
        if (!isset($labels[$ceremony])) {
            $labels[$ceremony] = $cells[1];
        }
    }
    ksort($labels, SORT_NUMERIC);
    $decades = array();
    foreach ($labels as $ceremony => $label) {
        $decade = intdiv((int) substr($label, 0, 4), 10) * 10 . 's';
        $decades[$decade][] = $ceremony;
    }
    ksort($decades, SORT_STRING);
    return $decades;
}

/**
 * This bundle's derivation summary (plan-v5 §4.1), what a later bundle diffs against
 * when this one is its baseline. From the source layer: per nomination (source_row,
 * nomination_key); per linked entity (imdb_id, kind); the sorted linked
 * (source_row, imdb_id) pairs, flagged needs-review pairs excluded. The deriver (U03)
 * adds the display name, master-row sha1, film_entity_id and primary_entity_id.
 *
 * @param array<string, mixed> $result
 */
function ledger_summary(string $bundle_id, array $result): string
{
    $flagged = array();
    foreach ($result['needs_review']['flagged'] as $pair) {
        $flagged[$pair[0] . "\x1f" . $pair[1]] = true;
    }
    $nominations = array();
    $linked = array();
    $entities = array();
    foreach ($result['rows'] as $i => $cells) {
        $source_row = $i + 1;
        $nominations[] = array($source_row, $result['keys'][$i]);
        $ids = array();
        if ($cells[6] !== '') {
            foreach (explode('|', $cells[6]) as $token) {
                if ($token !== '?') {
                    $ids[$token] = 'title';
                }
            }
        }
        if ($cells[9] !== '') {
            foreach (explode('|', $cells[9]) as $slot) {
                foreach (explode(',', $slot) as $token) {
                    if ($token !== '?' && !isset($flagged[$source_row . "\x1f" . $token])) {
                        $ids[$token] = AAT_Ledger_Source::ENTITY_KINDS[substr($token, 0, 2)];
                    }
                }
            }
        }
        $row_ids = array_map('strval', array_keys($ids));
        sort($row_ids, SORT_STRING);
        foreach ($row_ids as $id) {
            $linked[] = array($source_row, $id);
            $entities[$id] = $ids[$id];
        }
    }
    ksort($entities, SORT_STRING);
    $entity_rows = array();
    foreach ($entities as $id => $kind) {
        $entity_rows[] = array((string) $id, $kind);
    }
    $summary = array(
        'schema' => LEDGER_SUMMARY_SCHEMA,
        'bundle_id' => $bundle_id,
        'corrected_sha256' => $result['corrected_sha256'],
        'columns' => array(
            'nominations' => array('source_row', 'nomination_key'),
            'entities' => array('imdb_id', 'kind'),
            'linked' => array('source_row', 'imdb_id'),
        ),
        'nominations' => $nominations,
        'entities' => $entity_rows,
        'linked' => $linked,
    );
    $json = json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        ledger_builder_refuse('summary_invalid', 'the derivation summary does not encode');
    }
    return $json . "\n";
}

/**
 * The sentinel facts the source layer can see (names and titles are the deriver's).
 *
 * @param array<string, mixed> $result
 */
function ledger_check_assertions(array $result): void
{
    $rows = $result['rows'];
    $cell = function (int $source_row, int $column) use ($rows) {
        return $rows[$source_row - 1][$column] ?? null;
    };
    foreach (LEDGER_ASSERTIONS as $assertion) {
        $ok = true;
        if (isset($assertion['slots'])) {
            $labels = explode('|', (string) $cell($assertion['source_row'], 8));
            $slots = explode('|', (string) $cell($assertion['source_row'], 9));
            $ok = count($labels) === count($assertion['slots']) && count($slots) === count($assertion['slots']);
            foreach ($assertion['slots'] as $k => $slot) {
                $ids = array_values(array_filter(explode(',', $slots[$k] ?? ''), function ($t) {
                    return $t !== '?' && $t !== '';
                }));
                $ok = $ok && ($labels[$k] ?? null) === $slot[0] && $ids === $slot[1];
            }
        } elseif (isset($assertion['slot'])) {
            $slots = explode('|', (string) $cell($assertion['source_row'], 9));
            $ok = ($slots[$assertion['slot'] - 1] ?? null) === $assertion['imdb_id'];
        } elseif (isset($assertion['year_label'])) {
            $ok = ($result['ceremony_labels'][$assertion['ceremony']] ?? null) === $assertion['year_label'];
        } elseif (isset($assertion['note_prefix'])) {
            $ok = strpos((string) $cell($assertion['source_row'], 12), $assertion['note_prefix']) === 0;
        } elseif (isset($assertion['citation'])) {
            $ok = $cell($assertion['source_row'], 5) === '' && $cell($assertion['source_row'], 7) === '' && $cell($assertion['source_row'], 8) === ''
                && $cell($assertion['source_row'], 13) === $assertion['citation'];
        } elseif (strpos($assertion['id'], 'title_entity:') === 0) {
            $ok = false;
            foreach ($rows as $cells) {
                if (in_array($assertion['imdb_id'], explode('|', $cells[6]), true)) {
                    $ok = true;
                    break;
                }
            }
        }
        if (!$ok) {
            ledger_builder_refuse('assertion_failed', "the manifest sentinel {$assertion['id']} does not hold on the corrected rows");
        }
    }
    foreach (LEDGER_ACCEPTED_EXCEPTIONS as $exception) {
        $ids = explode('|', (string) $cell($exception['source_row'], 9));
        if (count(array_keys($ids, $exception['imdb_id'], true)) < 2) {
            ledger_builder_refuse('assertion_failed', "the accepted exception at source_row {$exception['source_row']} no longer exists");
        }
    }
}

/**
 * The workbook's dimension check against manifest.expected (plan-v5 §4.1).
 *
 * @param array<string, mixed> $expected
 * @return array<int, array{0: string, 1: string}> problems
 */
function ledger_check_workbook(string $root, array $expected, string $which): array
{
    $path = $root . '/' . LEDGER_WORKBOOK;
    if (!is_file($path)) {
        return array();
    }
    $rows = ledger_workbook_rows($path, array('Corrections', 'full_data'));
    $problems = array();
    $want = array('Corrections' => $expected['corrections'] ?? null, 'full_data' => $expected['nominations'] ?? null);
    foreach ($want as $sheet => $count) {
        if ($rows[$sheet] === null) {
            $problems[] = array('workbook_stale', LEDGER_WORKBOOK . " has no readable {$sheet} sheet dimension");
        } elseif ($rows[$sheet] !== $count) {
            $problems[] = array('workbook_stale', LEDGER_WORKBOOK . " {$sheet} has {$rows[$sheet]} data rows; {$which} expects " . json_encode($count));
        }
    }
    return $problems;
}

// ---------------------------------------------------------------------------
// CLI
// ---------------------------------------------------------------------------

function ledger_builder_main(array $argv): int
{
    $opts = array();
    $root = dirname(__DIR__, 2);
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--check') {
            $opts['check'] = true;
        } elseif ($arg === '--skip-workbook') {
            $opts['skip_workbook'] = true;
        } elseif (preg_match('~^--(baseline|baseline-commit|mode|reswap-of|dataset-version|root)=(.*)$~s', $arg, $m)) {
            if ($m[1] === 'root') {
                $root = rtrim($m[2], '/');
            } else {
                $opts[str_replace('-', '_', $m[1])] = $m[2];
            }
        } else {
            fwrite(STDERR, "build-ledger-bundle: unknown argument {$arg}\n\n" . LEDGER_BUILDER_USAGE . "\n");
            return 2;
        }
    }
    $check = !empty($opts['check']);
    if ($check && (isset($opts['baseline']) || isset($opts['mode']) || isset($opts['reswap_of']) || isset($opts['dataset_version']) || isset($opts['baseline_commit']))) {
        fwrite(STDERR, "build-ledger-bundle: --check uses the committed manifest; it takes no generation option\n\n" . LEDGER_BUILDER_USAGE . "\n");
        return 2;
    }
    if (!$check && (!isset($opts['baseline']) || $opts['baseline'] === '')) {
        fwrite(STDERR, "build-ledger-bundle: --baseline is required when generating\n\n" . LEDGER_BUILDER_USAGE . "\n");
        return 2;
    }

    require_once __DIR__ . '/lib/redact-evidence.php';
    $codec = $root . '/includes/class-aat-ledger-source.php';
    if (!is_file($codec)) {
        fwrite(STDERR, "build-ledger-bundle: REFUSED input_missing: {$codec} is missing\n");
        return 1;
    }
    require_once $codec;

    try {
        $plan = ledger_plan_bundle($root, $opts);
    } catch (AAT_Ledger_Refusal $e) {
        fwrite(STDERR, 'build-ledger-bundle: REFUSED ' . $e->getMessage() . "\n");
        return 1;
    } catch (Ledger_Builder_Refusal $e) {
        fwrite(STDERR, "build-ledger-bundle: REFUSED {$e->code_name}: " . $e->getMessage() . "\n");
        return 1;
    }

    $manifest = $plan['manifest'];
    $problems = $plan['problems'];

    if ($check) {
        foreach ($plan['files'] as $relpath => $bytes) {
            $committed = ledger_builder_read($root, $relpath);
            if ($committed === null) {
                $problems[] = array('check_mismatch', "{$relpath} is missing");
                continue;
            }
            if (substr($relpath, -3) === '.gz') {
                $committed = @gzdecode($committed);
                $bytes = gzdecode($bytes);
            }
            if ($committed !== $bytes) {
                $problems[] = array('check_mismatch', "{$relpath} differs from its regeneration");
            }
        }
        foreach ($plan['delete'] as $relpath) {
            $problems[] = array('check_mismatch', "{$relpath} should not exist (docs/database/baselines/ holds only the recorded baseline and this bundle's summary)");
        }
        $problems = array_merge($problems, ledger_check_workbook($root, (array) ($plan['committed']['expected'] ?? array()), 'the committed manifest'));
        if ($problems) {
            foreach ($problems as $problem) {
                fwrite(STDERR, "build-ledger-bundle: CHECK FAILED {$problem[0]}: {$problem[1]}\n");
            }
            return 1;
        }
        echo "build-ledger-bundle --check OK: bundle {$manifest['bundle_id']} ({$manifest['dataset_version']}, {$manifest['mode']}), " . count($plan['files']) . " files reproduced byte for byte, baseline {$manifest['simulation']['baseline']['kind']}.\n";
        return 0;
    }

    $written = array();
    foreach ($plan['files'] as $relpath => $bytes) {
        $path = $root . '/' . $relpath;
        $current = is_file($path) ? file_get_contents($path) : null;
        if (is_string($current) && substr($relpath, -3) === '.gz' && @gzdecode($current) === gzdecode($bytes)) {
            continue;
        }
        if ($current === $bytes) {
            continue;
        }
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $bytes);
        $written[] = $relpath;
    }
    foreach ($plan['delete'] as $relpath) {
        unlink($root . '/' . $relpath);
        $written[] = "{$relpath} (deleted)";
    }

    // Step 4: the workbook.
    $workbook = $root . '/' . LEDGER_WORKBOOK;
    if (is_file($workbook)) {
        $committed = $plan['committed'];
        $changed = $committed === null;
        foreach (array(array('overlay', 'corrections', 'sha256'), array('overlay', 'additions', 'sha256'), array('reference', 'needs_review', 'sha256')) as $path) {
            $old = $committed[$path[0]][$path[1]][$path[2]] ?? null;
            $new = $manifest[$path[0]][$path[1]][$path[2]];
            $changed = $changed || $old !== $new;
        }
        $changed = $changed || ($committed['corrected_sha256'] ?? null) !== $manifest['corrected_sha256'];
        $stale = ledger_check_workbook($root, $manifest['expected'], 'the manifest');
        if ($committed === null && !$stale) {
            $changed = false; // first manifest: the committed workbook already reflects this overlay
        }
        if (($changed || $stale) && empty($opts['skip_workbook'])) {
            if (ledger_python_has_openpyxl()) {
                $cmd = 'python3 ' . escapeshellarg($root . '/docs/database/tools/make_workbook.py') . ' '
                    . implode(' ', array_map('escapeshellarg', array(
                        $root . '/' . LEDGER_CORRECTED_TSV,
                        $root . '/docs/database/corrections.json',
                        $root . '/docs/database/needs-review.json',
                        $workbook,
                        $root . '/docs/database/additions.json',
                    )));
                $out = array();
                $status = 1;
                exec($cmd . ' 2>&1', $out, $status);
                if ($status !== 0) {
                    fwrite(STDERR, 'build-ledger-bundle: REFUSED workbook_stale: make_workbook.py failed: ' . implode("\n", $out) . "\n");
                    return 1;
                }
                $written[] = LEDGER_WORKBOOK . ' (make_workbook.py)';
                $stale = ledger_check_workbook($root, $manifest['expected'], 'the manifest');
            } elseif ($stale) {
                fwrite(STDERR, "build-ledger-bundle: REFUSED workbook_tool_missing: the workbook is stale and python3 cannot import openpyxl\n");
                return 1;
            } else {
                $plan['notes'][] = 'the overlay changed but openpyxl is not importable; the workbook dimensions still match, so it was left as is';
            }
        }
        foreach ($stale as $problem) {
            fwrite(STDERR, "build-ledger-bundle: REFUSED {$problem[0]}: {$problem[1]}\n");
            return 1;
        }
    }

    foreach ($plan['notes'] as $note) {
        echo "- {$note}\n";
    }
    echo 'Wrote ' . count($written) . ' file(s)' . ($written ? ":\n  " . implode("\n  ", $written) : '') . "\n";
    echo "bundle_id {$manifest['bundle_id']}\ndataset_version {$manifest['dataset_version']}\nmode {$manifest['mode']}\n";
    echo "corrected_sha256 {$manifest['corrected_sha256']}\n";
    echo 'expected ' . json_encode($manifest['expected']) . "\n";
    echo 'baseline ' . json_encode($manifest['simulation']['baseline']) . "\n";
    return 0;
}

exit(ledger_builder_main($argv));
