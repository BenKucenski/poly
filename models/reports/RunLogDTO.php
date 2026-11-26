<?php
declare(strict_types=1);

namespace models\reports;

use QuickDRY\Connectors\mysql\MySQL_A;
use QuickDRY\Utilities\APIHelper;
use QuickDRY\Utilities\strongType;

/**
 * One collector run (sql/run_log.sql): the 72 most recent, newest first.
 */
class RunLogDTO extends strongType
{
    public ?int $id = null; // 337
    public ?string $started_at = null; // "2026-11-26 14:55:04" (UTC)
    public ?string $finished_at = null; // null while running
    public ?string $status = null; // "done", "running", "out_of_time", "killed", "error"
    public ?int $discovered = null; // 28
    public ?int $resolved = null; // 2
    public ?int $snapshots = null; // 25
    public ?int $due_remaining = null; // 0 (null while running)
    public ?int $requests = null; // 1794
    public ?int $http_errors = null; // 0
    public ?string $notes = null; // per-market errors, one per line
    public ?int $seconds = null; // 863

    /**
     * @return self[]
     */
    public static function getReport(): array
    {
        return MySQL_A::QueryMap(APIHelper::getSQL(__DIR__ . '/sql/run_log.sql'), null, fn($row) => new self($row));
    }

    public function StartedTs(): int
    {
        return (int)strtotime($this->started_at . ' UTC');
    }
}
