<?php
declare(strict_types=1);
// Copy this file to env.php (which is not committed) and fill it in.

const SESSION_NAME = 'poly';

// REQUIRED: absolute path of an existing, writable folder outside the web root.
// Error logs, the run lock file and run logs go here.
// e.g. 'C:/inetpub/poly-data' or '/var/lib/poly'
const DATA_FOLDER = '';

const SHOW_ERRORS = 1;

// QuickDRY reads this on every query result; the skeleton's env.php leaves it out.
const CONST_HALT_ON_MISSING_PARAMS = false;
const JS_VERSION = 1;
const MASTER_SECRET_KEY = null;
const URL_SECRET_KEY = null;

// Error logs replace string constant values with their names; these are safe to show.
const DEBUG_REDACT_SKIP = ['SESSION_NAME', 'MYSQL_HOST', 'MYSQL_BASE', 'POLY_*'];

// MySQL
const MYSQL_HOST = 'localhost';
const MYSQL_USER = 'poly';
const MYSQL_PASS = '';
const MYSQL_PORT = 3306;
const MYSQL_BASE = 'poly';

// ---- Collector settings (tasks/collect.php) -------------------------------

// The script stops starting new work after this many seconds, so it finishes
// before the scheduler's 55-minute kill. 3240 = 54 minutes.
const POLY_MAX_RUNTIME_SECONDS = 3240;

// Only track markets with at least this much lifetime volume (USDC).
// Lower it to track more markets; each tracked market costs API calls every snapshot.
const POLY_MIN_VOLUME = 5000;

// Re-scan Polymarket for new markets at most this often (minutes).
const POLY_DISCOVERY_EVERY_MINUTES = 120;

// Holder pages per market per snapshot. Each page is up to 1000 holders per outcome.
// A snapshot that hits this cap is saved with is_complete = 0.
const POLY_MAX_HOLDER_PAGES = 50;

// Pause between API requests (milliseconds), to stay under Polymarket's rate limits.
const POLY_REQUEST_DELAY_MS = 120;

// Per-wallet holder rows to keep per outcome per snapshot (table snapshot_holder):
//   0 = none (only the aggregate counts), N = the top N wallets, -1 = every wallet.
// -1 lets you study individual wallets later but grows the database fast.
const POLY_STORE_HOLDER_ROWS = 0;
