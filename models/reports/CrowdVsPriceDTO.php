<?php
declare(strict_types=1);

namespace models\reports;

use QuickDRY\Connectors\mysql\MySQL_A;
use QuickDRY\Utilities\APIHelper;
use QuickDRY\Utilities\strongType;

/**
 * Pick accuracy on resolved markets and the crowd-vs-price disagreement
 * (sql/crowd_vs_price.sql). One row.
 */
class CrowdVsPriceDTO extends strongType
{
    public ?int $markets = null; // 34
    public ?int $snapshots = null; // 770
    public ?float $headcount_acc = null; // 0.8518
    public ?float $shares_acc = null; // 0.8039
    public ?float $price_acc = null; // 0.8726
    public ?int $disagree_markets = null; // 25
    public ?int $disagree_snapshots = null; // 82
    public ?float $crowd_win_rate = null; // 0.4024
    public ?float $crowd_pick_price = null; // 0.4109 (midpoint / Gamma price)
    public ?int $book_snapshots = null; // 36 (disagreeing counts with an order book)
    public ?int $book_markets = null; // 11
    public ?float $book_crowd_win_rate = null; // 0.3889
    public ?float $crowd_pick_ask = null; // 0.4189

    public static function getReport(): self
    {
        $rows = MySQL_A::QueryMap(APIHelper::getSQL(__DIR__ . '/sql/crowd_vs_price.sql'), null, fn($row) => new self($row));
        return $rows[0] ?? new self();
    }

    /** Win rate minus the ask paid, on counts with an order book (null without any). */
    public function EdgeAtAsk(): ?float
    {
        return $this->book_crowd_win_rate !== null && $this->crowd_pick_ask !== null
            ? $this->book_crowd_win_rate - $this->crowd_pick_ask : null;
    }

    /** Win rate minus the midpoint price; not something you could have traded. */
    public function EdgeAtMid(): ?float
    {
        return $this->crowd_win_rate !== null && $this->crowd_pick_price !== null
            ? $this->crowd_win_rate - $this->crowd_pick_price : null;
    }
}
