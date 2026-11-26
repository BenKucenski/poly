<?php
declare(strict_types=1);

namespace common\Poly;

use QuickDRY\Connectors\mysql\MySQL_A;
use QuickDRY\Utilities\APIHelper;

/**
 * Keeps snapshot_pick up to date: each error-free snapshot's picks, worked
 * out once, plus the bid for its head-count pick at the market's next count.
 *
 * Why: the dashboard's accuracy, crowd, tag and forward-return numbers all
 * need per-snapshot picks. Working them out in SQL meant window functions
 * over every snapshot_outcome row, materialized in on-disk temporary tables,
 * four times per build, and the cost grew with every hour of data. This
 * handles only snapshots added since the last build.
 *
 * Locking: the collector's tables are only read, with plain SELECTs (InnoDB
 * consistent reads take no locks). Picks are computed in PHP and written to
 * snapshot_pick, which nothing else writes. No INSERT ... SELECT from the
 * collector's tables: that would lock the rows it reads, and the collector
 * could wait on it.
 *
 * The reads are SQL files in sql/, loaded with QuickDRY's APIHelper.
 */
class Picks
{
    /** collector_state key: highest snapshot id already handled. */
    public const string STATE = 'picks_last_snapshot_id';

    /** Snapshots per batch (also the LIMIT in sql/picks_snapshots.sql). */
    public const int BATCH = 5000;

    /**
     * A snapshot without outcome rows yet is probably being written right now
     * (the collector inserts the snapshot, then its outcomes). Wait for it this
     * long before giving up on it.
     */
    public const int PENDING_SECONDS = 7200;

    private const array COLUMNS = [
        'snapshot_id', 'condition_id', 'signal_at', 'is_complete', 'has_book', 'all_holders',
        'head_pick', 'head_pick_holders', 'head_pick_price', 'head_pick_ask',
        'share_pick', 'price_pick', 'price_pick_price',
        'next_snapshot_id', 'next_signal_at', 'next_head_bid',
    ];

    /**
     * Processes new snapshots in batches until caught up or out of time.
     *
     * @param callable|null $say progress output
     * @param int $max_seconds stop starting new batches after this long (a backfill continues next run)
     * @return array{snapshots: int, picks: int, batches: int, caught_up: bool, last_id: int, ms: int}
     */
    public static function Update(?callable $say = null, int $max_seconds = 600): array
    {
        $say ??= static fn(string $msg) => null;
        $start = microtime(true);
        $last = (int)(Store::GetState(self::STATE) ?? 0);
        $stats = ['snapshots' => 0, 'picks' => 0, 'batches' => 0, 'caught_up' => false, 'last_id' => $last, 'ms' => 0];

        while (microtime(true) - $start < $max_seconds) {
            [$handled, $written, $new_last, $stalled] = self::Batch($last);
            if ($new_last > $last) {
                Store::SetState(self::STATE, (string)$new_last);
                $last = $new_last;
            }
            $stats['snapshots'] += $handled;
            $stats['picks'] += $written;
            $stats['batches']++;
            if ($handled) {
                $say(sprintf('picks: %s snapshots up to #%d', number_format($stats['snapshots']), $last));
            }
            if ($handled < self::BATCH || $stalled) {
                $stats['caught_up'] = true;
                break;
            }
        }

        $stats['last_id'] = $last;
        $stats['ms'] = (int)round((microtime(true) - $start) * 1000);
        return $stats;
    }

    /**
     * @param int $after highest snapshot id already handled
     * @return array{0: int, 1: int, 2: int, 3: bool} [snapshots handled, pick rows written, new watermark, stopped at a pending snapshot]
     */
    private static function Batch(int $after): array
    {
        $snaps = MySQL_A::QueryMap(APIHelper::getSQL(__DIR__ . '/sql/picks_snapshots.sql'), ['after_id' => $after], fn($row) => $row);

        if (!$snaps) {
            return [0, 0, $after, false];
        }

        $first = (int)$snaps[0]['id'];
        $max = (int)$snaps[count($snaps) - 1]['id'];

        $outcomes = [];
        $range = ['first_id' => $first, 'last_id' => $max];
        foreach (MySQL_A::QueryMap(APIHelper::getSQL(__DIR__ . '/sql/picks_outcomes.sql'), $range, fn($row) => $row) as $o) {
            $outcomes[(int)$o['snapshot_id']][] = $o;
        }

        // Each market's latest pick from before this batch, to link forward.
        $prev = [];
        foreach (MySQL_A::QueryMap(APIHelper::getSQL(__DIR__ . '/sql/picks_latest_before.sql'), $range, fn($row) => $row) as $r) {
            $prev[$r['condition_id']] = $r;
        }

        $rows = [];      // snapshot_id => pick row to write
        $handled = 0;
        $watermark = $after;
        $stalled = false;
        $cutoff = time() - self::PENDING_SECONDS;

        foreach ($snaps as $s) {
            $id = (int)$s['id'];

            if ($s['error'] === null && empty($outcomes[$id])) {
                $at = strtotime(($s['completed_at'] ?? $s['captured_at']) . ' UTC');
                if ($at > $cutoff) {
                    $stalled = true; // probably mid-write: stop here, retry next build
                    break;
                }
                // never got outcomes: nothing to pick
            } elseif ($s['error'] === null) {
                $row = self::Compute($outcomes[$id]) + [
                    'snapshot_id'      => $id,
                    'condition_id'     => $s['condition_id'],
                    'signal_at'        => $s['completed_at'] ?? $s['captured_at'],
                    'is_complete'      => (int)$s['is_complete'],
                    'next_snapshot_id' => null,
                    'next_signal_at'   => null,
                    'next_head_bid'    => null,
                ];

                // Fill in the previous count's "next" fields for this market.
                $p = $prev[$s['condition_id']] ?? null;
                if ($p) {
                    $p['next_snapshot_id'] = $id;
                    $p['next_signal_at'] = $row['signal_at'];
                    $p['next_head_bid'] = null;
                    if ($p['head_pick'] !== null) {
                        foreach ($outcomes[$id] as $o) {
                            if ((int)$o['outcome_index'] === (int)$p['head_pick']) {
                                $p['next_head_bid'] = $o['book_bid'];
                            }
                        }
                    }
                    $rows[(int)$p['snapshot_id']] = $p;
                }

                $rows[$id] = $row;
                $prev[$s['condition_id']] = $row;
            }

            $handled++;
            $watermark = $id;
        }

        if ($rows) {
            $update = [];
            foreach (array_slice(self::COLUMNS, 1) as $c) {
                $update[] = '`' . $c . '` = VALUES(`' . $c . '`)';
            }
            Store::InsertMany('snapshot_pick', self::COLUMNS, array_map(
                static fn(array $r) => array_intersect_key($r, array_flip(self::COLUMNS)),
                array_values($rows)
            ), 'ON DUPLICATE KEY UPDATE ' . implode(', ', $update), 50); // QuickDRY's escaper slows down sharply on bigger statements
        }

        return [$handled, count($rows), $watermark, $stalled];
    }

    /**
     * The picks for one snapshot's outcome rows: most holders, most shares, highest price (order-book midpoint when both
     * sides were recorded, else the Gamma price); NULL when two outcomes tie
     * for first.
     *
     * @param array $outcomes snapshot_outcome rows (outcome_index, price, holder_count, total_shares, book_bid, book_ask)
     * @return array
     */
    public static function Compute(array $outcomes): array
    {
        $num = static fn($v) => $v === null || $v === '' ? null : (float)$v;

        $o = [];
        $has_book = 0;
        foreach ($outcomes as $r) {
            $bid = $num($r['book_bid'] ?? null);
            $ask = $num($r['book_ask'] ?? null);
            if ($ask !== null) {
                $has_book = 1;
            }
            $sig = ($bid !== null && $ask !== null) ? ($bid + $ask) / 2 : $num($r['price'] ?? null);
            $o[] = [
                'i'       => (int)$r['outcome_index'],
                'holders' => (int)$r['holder_count'],
                'shares'  => round((float)$r['total_shares'], 6),
                'sig'     => $sig === null ? null : round($sig, 7), // DECIMAL-like equality for ties
                'ask'     => $ask,
            ];
        }

        $pick = static function (string $key) use ($o): ?array {
            $vals = array_filter(array_column($o, $key), static fn($v) => $v !== null);
            if (!$vals) {
                return null;
            }
            $max = max($vals);
            $hits = array_values(array_filter($o, static fn($r) => $r[$key] !== null && $r[$key] === $max));
            return count($hits) === 1 ? $hits[0] : null;
        };

        $head = $pick('holders');
        $share = $pick('shares');
        $price = $pick('sig');

        return [
            'has_book'          => $has_book,
            'all_holders'       => array_sum(array_column($o, 'holders')),
            'head_pick'         => $head['i'] ?? null,
            'head_pick_holders' => $head['holders'] ?? null,
            'head_pick_price'   => isset($head['sig']) ? round($head['sig'], 6) : null,
            'head_pick_ask'     => $head['ask'] ?? null,
            'share_pick'        => $share['i'] ?? null,
            'price_pick'        => $price['i'] ?? null,
            'price_pick_price'  => isset($price['sig']) ? round($price['sig'], 6) : null,
        ];
    }
}
