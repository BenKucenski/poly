<?php
declare(strict_types=1);

// Precomputes the homepage dashboard into the dashboard_cache table, so a page
// view only reads stored results. Schedule it every 15 minutes or so; it takes
// seconds, and each run replaces the previous numbers.
//
//   php tasks/dashboard.php                      rebuild every section
//   php tasks/dashboard.php -q                   quiet (errors only)
//   php tasks/dashboard.php -stotals,runs        rebuild only these sections
//
// Sections: totals, runs, cadence, accuracy, crowd, tags, closing_soon, resolved, tables
// Exits with code 1 if any section failed (its last good data stays on the page).

use common\Poly\Dashboard;
use QuickDRY\Utilities\DataFolder;

require_once __DIR__ . '/../modules.php';

$options = getopt('qs:');
$quiet = isset($options['q']);
$only = isset($options['s'])
    ? array_values(array_filter(array_map('trim', explode(',', (string)$options['s']))))
    : null;

if ($only) {
    $unknown = array_diff($only, array_keys(Dashboard::Sections()));
    if ($unknown) {
        fwrite(STDERR, 'Unknown section(s): ' . implode(', ', $unknown) . PHP_EOL
            . 'Sections: ' . implode(', ', array_keys(Dashboard::Sections())) . PHP_EOL);
        exit(2);
    }
}

if ($error = Dashboard::ConnectionError()) {
    fwrite(STDERR, $error . PHP_EOL);
    exit(1);
}

// One build at a time.
$lock = fopen(DataFolder::File('poly-dashboard.lock'), 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo gmdate('H:i:s') . ' Another dashboard build is still going; exiting.' . PHP_EOL;
    exit(0);
}

set_time_limit(900);
ini_set('memory_limit', '1024M');

$say = $quiet
    ? static function (string $msg): void {
        if (str_contains($msg, 'FAILED')) {
            fwrite(STDERR, $msg . PHP_EOL);
        }
    }
    : static function (string $msg): void {
        echo gmdate('H:i:s') . ' ' . $msg . PHP_EOL;
    };

$result = Dashboard::Build($only, $say);

if (!$quiet) {
    echo gmdate('H:i:s') . ' Done: ' . $result['built'] . ' built, ' . $result['failed'] . ' failed, ' . $result['ms'] . ' ms' . PHP_EOL;
}

flock($lock, LOCK_UN);
fclose($lock);

exit($result['failed'] ? 1 : 0);
