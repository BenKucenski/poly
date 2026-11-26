<?php
declare(strict_types=1);

namespace models\reports;

use QuickDRY\Connectors\mysql\MySQL_A;
use QuickDRY\Utilities\APIHelper;
use QuickDRY\Utilities\strongType;

/**
 * One table of this database with its size (sql/table_sizes.sql).
 */
class TableSizeDTO extends strongType
{
    public ?string $name = null; // "snapshot_outcome"
    public ?string $type = null; // "BASE TABLE"
    public ?int $approx_rows = null; // 9006 (InnoDB estimate)
    public ?int $bytes = null; // 1589248 (data + indexes)
    public ?string $update_time = null; // "2026-11-26 09:05:11"

    /**
     * @return self[]
     */
    public static function getReport(): array
    {
        return MySQL_A::QueryMap(APIHelper::getSQL(__DIR__ . '/sql/table_sizes.sql'), null, fn($row) => new self($row));
    }
}
