<?php
declare(strict_types=1);

namespace models\reports;

use QuickDRY\Connectors\mysql\MySQL_A;
use QuickDRY\Utilities\APIHelper;
use QuickDRY\Utilities\strongType;

/**
 * One of the 20 tags with the most markets (sql/tags.sql).
 */
class TagDTO extends strongType
{
    public ?string $tag = null; // "Politics"
    public ?int $markets = null; // 49
    public ?int $open_markets = null; // 35
    public ?int $resolved = null; // 13
    public ?int $snapshots = null; // 788
    public ?float $volume = null; // 81550116.00
    public ?float $headcount_acc = null; // 0.7802 (null until a market with the tag resolves)
    public ?float $price_acc = null; // 0.8285

    /**
     * @return self[] most markets first
     */
    public static function getReport(): array
    {
        return MySQL_A::QueryMap(APIHelper::getSQL(__DIR__ . '/sql/tags.sql'), null, fn($row) => new self($row));
    }
}
