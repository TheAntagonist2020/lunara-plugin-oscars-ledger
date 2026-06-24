<?php

$root = dirname(__DIR__);
$deployignore_path = $root . '/.deployignore';
$failures = array();

$assert = function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(file_exists($deployignore_path), '.deployignore should exist for WordPress.com Simple deployments.');

$contents = file_exists($deployignore_path) ? file_get_contents($deployignore_path) : '';
$assert(is_string($contents), '.deployignore should be readable.');
$lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $contents)), function ($line) {
    return $line !== '' && strpos($line, '#') !== 0;
}));

$required_exclusions = array(
    '.git',
    '.github',
    'docs',
    'docs/**',
    'tests',
    'tests/**',
    'README.md',
    'LIVE_DEPLOY_CHECKLIST.md',
    '*.zip',
    '.env',
    '.env.*',
);

foreach ($required_exclusions as $needle) {
    $assert(in_array($needle, $lines, true), ".deployignore should exclude {$needle}.");
}

$required_runtime_paths = array(
    'academy-awards-table.php',
    'assets',
    'data',
    'includes',
    'templates',
    'readme.txt',
);

foreach ($required_runtime_paths as $needle) {
    $assert(!in_array($needle, $lines, true), ".deployignore should not exclude runtime path {$needle}.");
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "WordPress.com deployignore contract OK.\n";
