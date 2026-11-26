<?php
declare(strict_types=1);

namespace models\reports;

use QuickDRY\Connectors\mysql\MySQL_A;
use QuickDRY\Utilities\APIHelper;
use QuickDRY\Utilities\strongType;

/**
 * Dashboard headline numbers (sql/dashboard_totals.sql).
 */
class DashboardTotalsDTO extends strongType
{
    public ?int $markets = null; // 240
    public ?int $open_tracking = null; // 204
    public ?int $closed = null; // 36
    public ?int $resolved = null; // 34
    public ?int $awaiting_result = null; // 2
    public ?int $due_now = null; // 10
    public ?int $new_24h = null; // 0
    public ?int $snapshots = null; // 4580
    public ?int $events = null; // 92
    public ?float $open_volume = null; // 393609378.00
    public ?int $snapshots_24h = null; // 464
    public ?int $incomplete_24h = null; // 22
    public ?int $errors_24h = null; // 5
    public ?string $first_snapshot_ever = null; // "2026-11-12 15:05:04" (UTC)
    public ?string $last_snapshot_ever = null; // "2026-11-26 14:58:17" (UTC)
    public ?string $last_discovery_at = null; // "2026-11-26 13:35:04" (UTC)
    public ?string $discovery_cursor = null; // null = the last discovery pass finished

    public static function getReport(): self
    {
        $now = time();
        $rows = MySQL_A::QueryMap(APIHelper::getSQL(__DIR__ . '/sql/dashboard_totals.sql'), [
            'now_utc'   => gmdate('Y-m-d H:i:s', $now),
            'since_24h' => gmdate('Y-m-d H:i:s', $now - 86400),
        ], fn($row) => new self($row));
        return $rows[0] ?? new self();
    }
}
