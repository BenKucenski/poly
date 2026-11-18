<?php
declare(strict_types=1);

// Hourly collector. Schedule it to start every hour; it stops starting new
// work after POLY_MAX_RUNTIME_SECONDS (54 minutes by default) and exits.
//
//   php tasks/collect.php            normal run
//   php tasks/collect.php -q         quiet (no progress output)
//   php tasks/collect.php -m300      run for at most 300 seconds (testing)

use common\Poly\Collector;
use common\Poly\PolyAPI;
use QuickDRY\Utilities\DataFolder;

require_once __DIR__ . '/../modules.php';

$options = getopt('qm::');
$quiet = isset($options['q']);

$max_runtime = isset($options['m']) && (int)$options['m'] > 0
    ? (int)$options['m']
    : (defined('POLY_MAX_RUNTIME_SECONDS') ? (int)POLY_MAX_RUNTIME_SECONDS : 3240);

// One run at a time: if last hour's run is somehow still going, skip this one.
$lock = fopen(DataFolder::File('poly-collect.lock'), 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo gmdate('H:i:s') . ' Another run is still going; exiting.' . PHP_EOL;
    exit(0);
}

set_time_limit($max_runtime + 300);
ini_set('memory_limit', '1024M');

$collector = new Collector(
    new PolyAPI(defined('POLY_REQUEST_DELAY_MS') ? (int)POLY_REQUEST_DELAY_MS : 120),
    $max_runtime,
    defined('POLY_MIN_VOLUME') ? (float)POLY_MIN_VOLUME : 5000,
    defined('POLY_DISCOVERY_EVERY_MINUTES') ? (int)POLY_DISCOVERY_EVERY_MINUTES : 120,
    defined('POLY_MAX_HOLDER_PAGES') ? (int)POLY_MAX_HOLDER_PAGES : 50,
    defined('POLY_STORE_HOLDER_ROWS') ? (int)POLY_STORE_HOLDER_ROWS : 0,
    !$quiet
);

$collector->Run();

flock($lock, LOCK_UN);
fclose($lock);
