<?php
declare(strict_types=1);

namespace common\Poly;

use models\reports\AccuracyByDaysOutDTO;
use models\reports\CadenceDTO;
use models\reports\CrowdVsPriceDTO;
use models\reports\DashboardTotalsDTO;
use models\reports\ForwardReturnDTO;
use models\reports\LatestCountDTO;
use models\reports\RunLogDTO;
use models\reports\TableSizeDTO;
use models\reports\TagDTO;

/**
 * Builds the homepage dashboard into dashboard_cache (tasks/dashboard.php).
 *
 * Each section is a report in models/reports: a SQL file in
 * models/reports/sql, loaded with QuickDRY's APIHelper::getSQL(), whose rows
 * become strongType DTOs. The DTOs are stored as JSON, one row per section,
 * and the homepage turns them back into the same DTOs.
 *
 * The accuracy, crowd, tag and forward-return reports read snapshot_pick,
 * which Picks::Update() brings up to date at the start of every build.
 */
class Dashboard
{
    /**
     * The sections in build order: name => report. Each is stored as its own
     * dashboard_cache row.
     *
     * @return array<string, callable>
     */
    public static function Sections(): array
    {
        return [
            'totals'       => static fn() => DashboardTotalsDTO::getReport(),
            'runs'         => static fn() => RunLogDTO::getReport(),
            'cadence'      => static fn() => CadenceDTO::getReport(),
            'accuracy'     => static fn() => AccuracyByDaysOutDTO::getReport(),
            'crowd'        => static fn() => CrowdVsPriceDTO::getReport(),
            'forward'      => static fn() => ForwardReturnDTO::getReport(),
            'tags'         => static fn() => TagDTO::getReport(),
            'closing_soon' => static fn() => LatestCountDTO::getClosingSoon(),
            'resolved'     => static fn() => LatestCountDTO::getRecentlyResolved(),
            'tables'       => static fn() => TableSizeDTO::getReport(),
        ];
    }

    /**
     * Computes every section and saves it to dashboard_cache. A section that
     * fails keeps its last good data and records the error, so one bad query
     * doesn't blank the whole page.
     *
     * @param string[]|null $only section names to rebuild (null = all)
     * @param callable|null $say progress output, e.g. fn(string $msg) => print($msg)
     * @return array{built: int, failed: int, ms: int}
     */
    public static function Build(?array $only = null, ?callable $say = null): array
    {
        $say ??= static fn(string $msg) => null;
        $start = microtime(true);
        $built = 0;
        $failed = 0;

        // Bring snapshot_pick up to date first; the aggregates read it.
        try {
            $p = Picks::Update($say);
            $say(sprintf('picks          %d ms, %s new snapshots%s', $p['ms'], number_format($p['snapshots']),
                $p['caught_up'] ? '' : ' (backfill continues next run)'));
        } catch (\Throwable $e) {
            $failed++;
            $say('picks          FAILED: ' . mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 1000));
        }

        foreach (self::Sections() as $name => $fn) {
            if ($only !== null && !in_array($name, $only, true)) {
                continue;
            }

            $t = microtime(true);
            try {
                $json = json_encode($fn(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                $ms = (int)round((microtime(true) - $t) * 1000);
                Store::Exec('
INSERT INTO dashboard_cache (name, data, built_at, build_ms, error, tried_at)
VALUES (:name, :data, :built_at, :build_ms, NULL, :tried_at)
ON DUPLICATE KEY UPDATE data = VALUES(data), built_at = VALUES(built_at), build_ms = VALUES(build_ms),
                        error = NULL, tried_at = VALUES(tried_at)
', ['name' => $name, 'data' => $json, 'built_at' => Store::Time(), 'build_ms' => $ms, 'tried_at' => Store::Time()]);
                $built++;
                $say(str_pad($name, 14) . ' ' . $ms . ' ms, ' . number_format(strlen($json)) . ' bytes');
            } catch (\Throwable $e) {
                $failed++;
                $error = mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 1000);
                $say(str_pad($name, 14) . ' FAILED: ' . $error);
                try {
                    // Keep the last good data; just record the failure.
                    Store::Exec('
INSERT INTO dashboard_cache (name, data, built_at, build_ms, error, tried_at)
VALUES (:name, NULL, NULL, 0, :error, :tried_at)
ON DUPLICATE KEY UPDATE error = VALUES(error), tried_at = VALUES(tried_at)
', ['name' => $name, 'error' => $error, 'tried_at' => Store::Time()]);
                } catch (\Throwable) {
                    // the database itself is gone; nothing more to record
                }
            }
        }

        return ['built' => $built, 'failed' => $failed, 'ms' => (int)round((microtime(true) - $start) * 1000)];
    }

    /**
     * Tries the MySQL settings from env.php before QuickDRY connects, because
     * QuickDRY's connector die()s with a debug dump on a failed connect. A web
     * page should say "can't connect" instead.
     *
     * @return string|null the error, or null when the connection works
     */
    public static function ConnectionError(): ?string
    {
        foreach (['MYSQL_HOST', 'MYSQL_USER', 'MYSQL_PASS', 'MYSQL_PORT', 'MYSQL_BASE'] as $const) {
            if (!defined($const)) {
                return $const . ' is not set in env.php';
            }
        }

        mysqli_report(MYSQLI_REPORT_OFF);
        try {
            $db = @mysqli_connect((string)MYSQL_HOST, (string)MYSQL_USER, (string)MYSQL_PASS, (string)MYSQL_BASE, (int)MYSQL_PORT);
            if (!$db) {
                return 'MySQL connect error ' . mysqli_connect_errno() . ': ' . mysqli_connect_error();
            }
            mysqli_close($db);
            return null;
        } finally {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // PHP 8.1+ default
        }
    }
}
