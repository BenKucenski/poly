<?php
declare(strict_types=1);

namespace models\reports;

use QuickDRY\Utilities\strongType;

/**
 * One outcome of a count, as part of a LatestCountDTO.
 */
class OutcomeDTO extends strongType
{
    public ?int $outcome_index = null; // 0
    public ?string $outcome = null; // "Yes"
    public ?float $price = null; // 0.269 (Gamma price, from the batch lookup)
    public ?int $holder_count = null; // 883
    public ?float $total_shares = null; // 82119.0
    public ?float $book_bid = null; // 0.25 (null before order books were recorded)
    public ?float $book_ask = null; // 0.29

    /** Order-book midpoint when both sides were recorded, else the Gamma price. */
    public function Mid(): ?float
    {
        return $this->book_bid !== null && $this->book_ask !== null
            ? ($this->book_bid + $this->book_ask) / 2
            : $this->price;
    }
}
