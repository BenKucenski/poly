<?php
declare(strict_types=1);

namespace models\reports;

use QuickDRY\Connectors\mysql\MySQL_A;
use QuickDRY\Utilities\APIHelper;
use QuickDRY\Utilities\strongType;

/**
 * How often each majority picked the winner, for one days-before-close
 * bucket (sql/accuracy_by_days_out.sql).
 */
class AccuracyByDaysOutDTO extends strongType
{
    /** Days past this are folded into the last bucket. */
    public const int MAX_DAYS_OUT = 30;

    public ?int $days_out = null; // 3
    public ?int $markets = null; // 34
    public ?int $snapshots = null; // 128
    public ?float $headcount_acc = null; // 0.7656
    public ?float $shares_acc = null; // 0.7109
    public ?float $price_acc = null; // 0.8047

    /**
     * @return self[] by days_out, ascending
     */
    public static function getReport(): array
    {
        return MySQL_A::QueryMap(
            APIHelper::getSQL(__DIR__ . '/sql/accuracy_by_days_out.sql'),
            ['max_days_out' => self::MAX_DAYS_OUT],
            fn($row) => new self($row)
        );
    }

    /** "3" or "30+" */
    public function Label(): string
    {
        return $this->days_out . ($this->days_out === self::MAX_DAYS_OUT ? '+' : '');
    }
}
