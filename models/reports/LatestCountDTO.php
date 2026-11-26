<?php
declare(strict_types=1);

namespace models\reports;

use common\Poly\Picks;
use QuickDRY\Connectors\mysql\MySQL_A;
use QuickDRY\Utilities\APIHelper;
use QuickDRY\Utilities\strongType;

/**
 * A market with one of its counts and that count's outcomes:
 *
 *   getClosingSoon()       the open markets ending soonest, latest count
 *                          (sql/closing_soon.sql)
 *   getRecentlyResolved()  the latest resolved markets, last complete
 *                          count before the close (sql/recently_resolved.sql)
 *
 * The SQL returns one row per outcome; this groups them per market and
 * works out the picks with Picks::Compute(), the same rules snapshot_pick
 * uses.
 */
class LatestCountDTO extends strongType
{
    // market
    public ?string $condition_id = null; // "0x0c2a…86bc"
    public ?string $question = null; // "Will … happen by Nov 26?"
    public ?string $event_title = null;
    public ?string $slug = null;
    public ?string $event_slug = null;
    public ?string $end_date = null; // "2026-11-26 15:05:04" (UTC)
    public ?string $closed_time = null; // (UTC) null until closed
    public ?float $volume_num = null; // 3994310.00
    public ?int $snapshot_count = null; // 23
    public ?string $outcomes = null; // '["Yes","No"]'
    public ?int $winning_outcome_index = null; // 0, null until resolved
    public ?string $winning_outcome = null; // "Yes"

    // the count (all null if the market hasn't been counted)
    public ?int $snapshot_id = null; // 2929
    public ?string $captured_at = null; // (UTC) walk started
    public ?string $completed_at = null; // (UTC) walk finished, null before migration 003
    public ?int $unique_holders = null; // 2160
    public ?int $is_complete = null; // 1

    /** @var OutcomeDTO[] in outcome_index order */
    public array $outcome_rows = [];

    // picks for this count (null on a tie or without a count)
    public ?int $head_pick = null;
    public ?int $share_pick = null;
    public ?int $price_pick = null;

    /** @return self[] */
    public static function getClosingSoon(): array
    {
        return self::fromRows(MySQL_A::QueryMap(APIHelper::getSQL(__DIR__ . '/sql/closing_soon.sql'), null, fn($row) => $row));
    }

    /** @return self[] */
    public static function getRecentlyResolved(): array
    {
        return self::fromRows(MySQL_A::QueryMap(APIHelper::getSQL(__DIR__ . '/sql/recently_resolved.sql'), null, fn($row) => $row));
    }

    /**
     * Rebuilds the objects from their stored JSON (dashboard_cache).
     *
     * @param array $data
     * @return self
     */
    public static function fromCache(array $data): self
    {
        $rows = $data['outcome_rows'] ?? [];
        unset($data['outcome_rows']);
        $item = new self($data);
        $item->outcome_rows = array_map(fn($o) => new OutcomeDTO($o), $rows);
        return $item;
    }

    /**
     * @param array $rows one per market outcome, grouped by market in order
     * @return self[]
     */
    private static function fromRows(array $rows): array
    {
        $market_cols = array_flip(array_keys(get_class_vars(self::class)));
        $outcome_cols = array_flip(array_keys(get_class_vars(OutcomeDTO::class)));

        /** @var self[] $out */
        $out = [];
        foreach ($rows as $row) {
            $cid = $row['condition_id'];
            if (!isset($out[$cid])) {
                $out[$cid] = new self(array_intersect_key($row, $market_cols));
            }
            if ($row['outcome_index'] !== null) {
                $out[$cid]->outcome_rows[] = new OutcomeDTO(array_intersect_key($row, $outcome_cols));
            }
        }

        foreach ($out as $item) {
            if ($item->outcome_rows) {
                $p = Picks::Compute(array_map(fn(OutcomeDTO $o) => $o->toArray(), $item->outcome_rows));
                $item->head_pick = $p['head_pick'];
                $item->share_pick = $p['share_pick'];
                $item->price_pick = $p['price_pick'];
            }
        }
        return array_values($out);
    }

    public function Outcome(?int $index): ?OutcomeDTO
    {
        foreach ($this->outcome_rows as $o) {
            if ($o->outcome_index === $index) {
                return $o;
            }
        }
        return null;
    }

    /** The outcome's label, "tie" for no pick. */
    public function OutcomeLabel(?int $index): string
    {
        if ($index === null) {
            return 'tie';
        }
        $labels = json_decode((string)$this->outcomes, true);
        return is_array($labels) && isset($labels[$index]) ? (string)$labels[$index] : '#' . $index;
    }

    /** The head-count pick's share of all wallets at this count. */
    public function HeadShare(): ?float
    {
        $all = array_sum(array_map(fn(OutcomeDTO $o) => (int)$o->holder_count, $this->outcome_rows));
        $o = $this->Outcome($this->head_pick);
        return $o && $all > 0 ? $o->holder_count / $all : null;
    }

    public function CrowdDisagrees(): bool
    {
        return $this->head_pick !== null && $this->price_pick !== null && $this->head_pick !== $this->price_pick;
    }

    public function Url(): string
    {
        return $this->event_slug
            ? 'https://polymarket.com/event/' . rawurlencode($this->event_slug)
            : 'https://polymarket.com/market/' . rawurlencode((string)$this->slug);
    }

    /** When the count's signal existed: completed_at, or captured_at on older rows. */
    public function SignalAt(): ?string
    {
        return $this->completed_at ?? $this->captured_at;
    }
}
