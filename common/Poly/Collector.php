<?php
declare(strict_types=1);

namespace common\Poly;

use RuntimeException;
use Throwable;

/**
 * One scheduled run:
 *   1. Discover open markets above the volume floor (every POLY_DISCOVERY_EVERY_MINUTES).
 *   2. Check pending resolutions (closed markets without a winner yet).
 *   3. Snapshot every market that is due, oldest-due first, until the time budget runs out.
 *
 * How often a market is due depends on how close it is to its end date
 * (see Interval()), so markets get counted more often as they near the finish.
 */
class Collector
{
    private const array MARKET_COLUMNS = [
        'condition_id', 'gamma_id', 'question', 'slug', 'event_id', 'event_slug', 'event_title',
        'category', 'tags', 'sports_market_type', 'neg_risk', 'outcome_count', 'outcomes', 'token_ids',
        'start_date', 'end_date', 'volume_num', 'liquidity_num', 'closed', 'closed_time',
        'uma_resolution_status', 'first_seen_at', 'last_seen_at', 'next_snapshot_at',
    ];

    // Columns refreshed whenever Gamma tells us about a market we already have.
    private const array MARKET_REFRESH = [
        'gamma_id', 'question', 'slug', 'event_id', 'event_slug', 'event_title', 'category', 'tags',
        'sports_market_type', 'neg_risk', 'outcome_count', 'outcomes', 'token_ids', 'start_date',
        'end_date', 'volume_num', 'liquidity_num', 'last_seen_at',
    ];

    private const int BATCH = 40;          // markets per Gamma lookup
    private const int GIVE_UP_DAYS = 30;   // stop waiting for a resolution after this long

    private int $deadline;
    private int $run_id = 0;
    private array $stats = ['discovered' => 0, 'resolved' => 0, 'snapshots' => 0];
    private array $notes = [];

    /**
     * @param PolyAPI $api
     * @param int $max_runtime seconds before the run stops starting new work
     * @param float $min_volume
     * @param int $discovery_every_minutes
     * @param int $max_holder_pages
     * @param int $store_holder_rows 0 none, N top N per outcome, -1 all
     * @param bool $verbose echo progress
     */
    public function __construct(
        private readonly PolyAPI $api,
        int                      $max_runtime,
        private readonly float   $min_volume,
        private readonly int     $discovery_every_minutes,
        private readonly int     $max_holder_pages,
        private readonly int     $store_holder_rows,
        private readonly bool    $verbose = true,
    )
    {
        $this->deadline = time() + $max_runtime;
    }

    /**
     * @return void
     */
    public function Run(): void
    {
        // A run that was killed (scheduler timeout, crash) never wrote its finish line.
        Store::Exec("UPDATE run_log SET status = 'killed' WHERE status = 'running'");

        $this->run_id = (int)Store::Exec(
            'INSERT INTO run_log (started_at) VALUES (:started_at)',
            ['started_at' => Store::Time()]
        )->last_id;
        $this->Say('Run ' . $this->run_id . ' started');

        $status = 'done';
        try {
            $this->Discover();
            $this->CheckPendingResolutions();
            $this->SnapshotDue();
        } catch (Throwable $e) {
            $status = 'error';
            $this->notes[] = get_class($e) . ': ' . $e->getMessage();
            $this->Say('ERROR ' . $e->getMessage());
        }

        $due = Store::Row(
            'SELECT COUNT(*) AS n FROM market WHERE tracking = 1 AND closed = 0 AND next_snapshot_at <= :now',
            ['now' => Store::Time()]
        );

        if ($status === 'done' && $this->TimeLeft() <= 0) {
            $status = 'out_of_time';
        }

        Store::Exec('
UPDATE run_log SET
    finished_at = :finished_at, status = :status, discovered = :discovered, resolved = :resolved,
    snapshots = :snapshots, due_remaining = :due_remaining, requests = :requests,
    http_errors = :http_errors, notes = :notes
WHERE id = :id
', [
            'finished_at'   => Store::Time(),
            'status'        => $status,
            'discovered'    => $this->stats['discovered'],
            'resolved'      => $this->stats['resolved'],
            'snapshots'     => $this->stats['snapshots'],
            'due_remaining' => (int)($due['n'] ?? 0),
            'requests'      => $this->api->requests,
            'http_errors'   => $this->api->errors,
            'notes'         => $this->notes ? substr(implode(PHP_EOL, $this->notes), 0, 60000) : null,
            'id'            => $this->run_id,
        ]);

        $this->Say('Run ' . $this->run_id . ' ' . $status . ': ' . json_encode($this->stats)
            . ', still due ' . (int)($due['n'] ?? 0) . ', requests ' . $this->api->requests);
    }

    // ---------------------------------------------------------------- discovery

    /**
     * Walk every open market above the volume floor and upsert it.
     * If the time share runs out, the cursor is saved and the next run resumes there.
     *
     * @return void
     */
    private function Discover(): void
    {
        $cursor = Store::GetState('discovery_cursor');
        $last = Store::GetState('discovery_finished_at');
        if (!$cursor && $last && strtotime($last . ' UTC') > time() - $this->discovery_every_minutes * 60) {
            return;
        }

        // Leave most of the hour for snapshots.
        $stop_at = time() + (int)(($this->deadline - time()) * 0.35);
        $this->Say('Discovery ' . ($cursor ? 'resuming' : 'starting'));

        while (time() < $stop_at) {
            $params = [
                'closed'         => 'false',
                'volume_num_min' => $this->min_volume,
                'include_tag'    => 'true',
                'limit'          => 100,
            ];
            if ($cursor) {
                $params['after_cursor'] = $cursor;
            }

            $page = $this->api->MarketsPage($params);
            $rows = [];
            foreach ($page['markets'] as $m) {
                if (($m['archived'] ?? false) || !($m['active'] ?? true)) {
                    continue;
                }
                $row = self::MarketRow($m);
                if ($row) {
                    $rows[] = $row;
                }
            }
            $this->UpsertMarkets($rows);
            $this->stats['discovered'] += count($rows);

            $cursor = $page['next_cursor'];
            if (!$cursor) {
                Store::SetState('discovery_cursor', null);
                Store::SetState('discovery_finished_at', Store::Time());
                $this->Say('Discovery finished, ' . $this->stats['discovered'] . ' markets');
                return;
            }
        }

        Store::SetState('discovery_cursor', $cursor);
        $this->Say('Discovery paused (will resume next run), ' . $this->stats['discovered'] . ' markets so far');
    }

    /**
     * @param array $rows from MarketRow()
     * @return void
     */
    private function UpsertMarkets(array $rows): void
    {
        if (!$rows) {
            return;
        }
        $now = Store::Time();
        foreach ($rows as &$row) {
            $row['first_seen_at'] = $now;
            $row['last_seen_at'] = $now;
            $row['next_snapshot_at'] = $now; // only used for new rows: count them right away
        }
        unset($row);

        $update = [];
        foreach (self::MARKET_REFRESH as $c) {
            $update[] = '`' . $c . '` = VALUES(`' . $c . '`)';
        }

        Store::InsertMany('market', self::MARKET_COLUMNS, $rows,
            'ON DUPLICATE KEY UPDATE ' . implode(', ', $update), 100);

        self::SyncTags($rows);
    }

    /**
     * Rewrite market_tag for these markets from their tags column, so it
     * always matches the raw list in market.tags.
     *
     * @param array $rows market rows with condition_id and tags
     * @return void
     */
    public static function SyncTags(array $rows): void
    {
        if (!$rows) {
            return;
        }

        $params = [];
        $in = [];
        $tag_rows = [];
        foreach (array_values($rows) as $i => $row) {
            $cid = $row['condition_id'];
            $in[] = ':cid' . $i;
            $params['cid' . $i] = $cid;

            $seen = [];
            foreach (explode(',', (string)($row['tags'] ?? '')) as $tag) {
                $tag = mb_substr(trim($tag), 0, 255);
                $key = mb_strtolower($tag); // the column's collation is case-insensitive
                if ($tag === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $tag_rows[] = ['condition_id' => $cid, 'tag' => $tag];
            }
        }

        Store::Exec('DELETE FROM market_tag WHERE condition_id IN (' . implode(', ', $in) . ')', $params);
        Store::InsertMany('market_tag', ['condition_id', 'tag'], $tag_rows, '', 300, true);
    }

    /**
     * Gamma market JSON -> market table row. Null if it can't be used.
     *
     * @param array $m
     * @return array|null
     */
    public static function MarketRow(array $m): ?array
    {
        $condition_id = $m['conditionId'] ?? null;
        $outcomes = self::JsonList($m['outcomes'] ?? null);
        $tokens = self::JsonList($m['clobTokenIds'] ?? null);

        if (!$condition_id || count($outcomes) < 2 || count($tokens) !== count($outcomes)) {
            return null;
        }

        $event = $m['events'][0] ?? [];
        $tags = [];
        foreach (($m['tags'] ?? $event['tags'] ?? []) as $t) {
            if (!empty($t['label'])) {
                $tags[] = str_replace(',', ' ', (string)$t['label']);
            }
        }

        return [
            'condition_id'          => $condition_id,
            'gamma_id'              => isset($m['id']) ? (string)$m['id'] : null,
            'question'              => self::Cut($m['question'] ?? null, 1000),
            'slug'                  => self::Cut($m['slug'] ?? null, 255),
            'event_id'              => isset($event['id']) ? (string)$event['id'] : null,
            'event_slug'            => self::Cut($event['slug'] ?? null, 255),
            'event_title'           => self::Cut($event['title'] ?? null, 1000),
            'category'              => self::Cut($m['category'] ?? $event['category'] ?? null, 255),
            'tags'                  => self::Cut($tags ? implode(',', array_unique($tags)) : null, 2000),
            'sports_market_type'    => self::Cut($m['sportsMarketType'] ?? null, 64),
            'neg_risk'              => (bool)($event['negRisk'] ?? $m['negRisk'] ?? false),
            'outcome_count'         => count($outcomes),
            'outcomes'              => json_encode(array_values($outcomes)),
            'token_ids'             => json_encode(array_map('strval', array_values($tokens))),
            'start_date'            => Store::ParseTime($m['startDate'] ?? null),
            'end_date'              => Store::ParseTime($m['endDate'] ?? null),
            'volume_num'            => self::Num($m['volumeNum'] ?? $m['volume'] ?? null),
            'liquidity_num'         => self::Num($m['liquidityNum'] ?? $m['liquidity'] ?? null),
            'closed'                => (bool)($m['closed'] ?? false),
            'closed_time'           => Store::ParseTime($m['closedTime'] ?? null),
            'uma_resolution_status' => self::Cut($m['umaResolutionStatus'] ?? null, 64),
        ];
    }

    // ---------------------------------------------------------------- resolution

    /**
     * Closed markets we haven't seen a winner for yet, checked at most hourly.
     *
     * @return void
     */
    private function CheckPendingResolutions(): void
    {
        $rows = Store::Rows('
SELECT condition_id FROM market
WHERE tracking = 1 AND closed = 1 AND winning_outcome_index IS NULL
  AND (resolution_checked_at IS NULL OR resolution_checked_at < :cutoff)
ORDER BY resolution_checked_at
LIMIT 2000
', ['cutoff' => Store::Time(time() - 3300)]);

        foreach (array_chunk(array_column($rows, 'condition_id'), self::BATCH) as $ids) {
            if ($this->TimeLeft() < 600) {
                return;
            }
            $this->CheckResolution($ids);
        }
    }

    /**
     * Look the markets up as closed markets and record any winners.
     *
     * @param string[] $ids
     * @return void
     */
    private function CheckResolution(array $ids): void
    {
        $found = [];
        foreach ($this->api->MarketsByCondition($ids, true) as $m) {
            $row = self::MarketRow($m);
            if (!$row) {
                continue;
            }
            $found[$row['condition_id']] = true;

            $prices = array_map('floatval', self::JsonList($m['outcomePrices'] ?? null));
            $outcomes = self::JsonList($m['outcomes'] ?? null);
            $winner = null;
            foreach ($prices as $i => $p) {
                if ($p >= 0.99) {
                    $winner = $i;
                }
            }

            Store::Exec('
UPDATE market SET
    closed = 1,
    closed_time = COALESCE(:closed_time, closed_time),
    uma_resolution_status = :uma,
    final_prices = :final_prices,
    winning_outcome_index = :winner,
    winning_outcome = :winning_outcome,
    volume_num = COALESCE(:volume_num, volume_num),
    resolution_checked_at = :checked,
    tracking = IF(:winner_set = 1, 0, tracking),
    next_snapshot_at = NULL
WHERE condition_id = :condition_id
', [
                'closed_time'     => $row['closed_time'],
                'uma'             => $row['uma_resolution_status'],
                'final_prices'    => $prices ? json_encode($prices) : null,
                'winner'          => $winner,
                'winning_outcome' => is_null($winner) ? null : self::Cut((string)($outcomes[$winner] ?? ''), 255),
                'volume_num'      => $row['volume_num'],
                'checked'         => Store::Time(),
                'winner_set'      => is_null($winner) ? 0 : 1,
                'condition_id'    => $row['condition_id'],
            ]);

            if (!is_null($winner)) {
                $this->stats['resolved']++;
            }
        }

        // Not returned as open or as closed (paused, archived or removed). Look again in
        // 6 hours; stop once it is well past its end date.
        $missing = array_values(array_diff($ids, array_keys($found)));
        if ($missing) {
            $params = [
                'checked'  => Store::Time(),
                'next_at'  => Store::Time(time() + 6 * 3600),
                'give_up'  => Store::Time(time() - self::GIVE_UP_DAYS * 86400),
            ];
            $in = [];
            foreach ($missing as $i => $id) {
                $in[] = ':cid' . $i;
                $params['cid' . $i] = $id;
            }
            Store::Exec('
UPDATE market SET
    resolution_checked_at = :checked,
    next_snapshot_at = IF(closed = 0, :next_at, NULL),
    tracking = IF(COALESCE(end_date, last_seen_at) < :give_up, 0, tracking)
WHERE condition_id IN (' . implode(', ', $in) . ')
', $params);
        }

        // Closed for a long time with no clear winner (e.g. a 50/50 split): stop checking.
        Store::Exec('
UPDATE market SET tracking = 0
WHERE tracking = 1 AND closed = 1 AND winning_outcome_index IS NULL
  AND COALESCE(closed_time, end_date, last_seen_at) < :give_up
', ['give_up' => Store::Time(time() - self::GIVE_UP_DAYS * 86400)]);
    }

    // ---------------------------------------------------------------- snapshots

    /**
     * @return void
     */
    private function SnapshotDue(): void
    {
        while ($this->TimeLeft() > 60) {
            $due = Store::Rows('
SELECT * FROM market
WHERE tracking = 1 AND closed = 0 AND next_snapshot_at <= :now
ORDER BY next_snapshot_at, end_date
LIMIT ' . self::BATCH . '
', ['now' => Store::Time()]);

            if (!$due) {
                $this->Say('Nothing left due');
                return;
            }

            // Fresh prices and volume for the whole batch in one call.
            $fresh = [];
            foreach ($this->api->MarketsByCondition(array_column($due, 'condition_id'), false) as $m) {
                if (!empty($m['conditionId'])) {
                    $fresh[$m['conditionId']] = $m;
                }
            }

            $gone = [];
            foreach ($due as $market) {
                if ($this->TimeLeft() <= 60) {
                    return;
                }
                $m = $fresh[$market['condition_id']] ?? null;
                if (!$m || ($m['closed'] ?? false)) {
                    $gone[] = $market['condition_id'];
                    continue;
                }
                $this->SnapshotMarket($market, $m);
            }

            if ($gone) {
                $this->CheckResolution($gone);
            }
        }
    }

    /**
     * Count every holder of one market and save the snapshot.
     *
     * @param array $market market table row
     * @param array $gamma fresh Gamma market JSON
     * @return void
     */
    private function SnapshotMarket(array $market, array $gamma): void
    {
        $captured = time();
        $cid = $market['condition_id'];

        $row = self::MarketRow($gamma) ?? [];
        $tokens = self::JsonList($row['token_ids'] ?? $market['token_ids']);
        $outcomes = self::JsonList($row['outcomes'] ?? $market['outcomes']);
        $prices = self::JsonList($gamma['outcomePrices'] ?? null);
        $end = $row['end_date'] ?? $market['end_date'];

        $token_index = [];
        foreach ($tokens as $i => $t) {
            $token_index[(string)$t] = $i;
        }

        $holders = []; // outcome_index => [wallet => shares]
        $pages = 0;
        $complete = false;
        $error = null;
        $cursor = null;

        try {
            while (true) {
                if ($pages >= $this->max_holder_pages || $this->TimeLeft() <= 30) {
                    break;
                }
                $page = $this->api->HoldersPage($cid, $cursor);
                $pages++;

                foreach ($page['data'] as $group) {
                    $idx = $token_index[(string)($group['token_id'] ?? '')] ?? null;
                    foreach (($group['holders'] ?? []) as $h) {
                        $i = $idx ?? (($h['outcome_index'] ?? 999) < 999 ? (int)$h['outcome_index'] : null);
                        $amount = (float)($h['amount'] ?? 0);
                        $wallet = strtolower((string)($h['proxy_wallet'] ?? ''));
                        if (is_null($i) || $amount <= 0 || $wallet === '') {
                            continue;
                        }
                        // Assign rather than add: the walk can repeat a row across a data refresh.
                        $holders[$i][$wallet] = $amount;
                    }
                }

                $cursor = $page['next_cursor'];
                if (!$page['has_more'] || !$cursor) {
                    $complete = true;
                    break;
                }
            }
        } catch (RuntimeException $e) {
            $error = substr($e->getMessage(), 0, 1000);
            $this->notes[] = $cid . ': ' . $error;
        }

        // Distinct wallets, and wallets holding more than one side.
        $seen = [];
        foreach ($holders as $wallets) {
            foreach ($wallets as $w => $_) {
                $seen[$w] = ($seen[$w] ?? 0) + 1;
            }
        }
        $multi = count(array_filter($seen, fn($n) => $n > 1));

        $snapshot_id = (int)Store::Exec('
INSERT INTO snapshot (run_id, condition_id, captured_at, seconds_to_end, is_complete, pages,
    unique_holders, multi_side_holders, volume_num, liquidity_num, best_bid, best_ask, last_trade_price, spread, error)
VALUES (:run_id, :condition_id, :captured_at, :seconds_to_end, :is_complete, :pages,
    :unique_holders, :multi_side_holders, :volume_num, :liquidity_num, :best_bid, :best_ask, :last_trade_price, :spread, :error)
', [
            'run_id'             => $this->run_id,
            'condition_id'       => $cid,
            'captured_at'        => Store::Time($captured),
            'seconds_to_end'     => $end ? strtotime($end . ' UTC') - $captured : null,
            'is_complete'        => $complete ? 1 : 0,
            'pages'              => $pages,
            'unique_holders'     => count($seen),
            'multi_side_holders' => $multi,
            'volume_num'         => $row['volume_num'] ?? null,
            'liquidity_num'      => $row['liquidity_num'] ?? null,
            'best_bid'           => self::Num($gamma['bestBid'] ?? null),
            'best_ask'           => self::Num($gamma['bestAsk'] ?? null),
            'last_trade_price'   => self::Num($gamma['lastTradePrice'] ?? null),
            'spread'             => self::Num($gamma['spread'] ?? null),
            'error'              => $error,
        ])->last_id;

        if (!$error) {
            $this->SaveOutcomes($snapshot_id, $outcomes, $tokens, $prices, $holders);
        }

        // Refresh the market row and schedule the next count.
        $next = $captured + self::Interval($end ? strtotime($end . ' UTC') - $captured : null) - 600;
        if ($error) {
            $next = $captured + 3000;
        }

        $refresh = $row ? array_intersect_key($row, array_flip(self::MARKET_REFRESH)) : [];
        $sets = [];
        $params = [
            'last_snapshot_at' => Store::Time($captured),
            'next_snapshot_at' => Store::Time($next),
            'condition_id'     => $cid,
            'inc'              => $error ? 0 : 1,
        ];
        foreach ($refresh as $c => $v) {
            if ($c === 'last_seen_at') {
                continue;
            }
            $sets[] = '`' . $c . '` = :m_' . $c;
            $params['m_' . $c] = is_bool($v) ? (int)$v : $v;
        }
        $sets[] = '`last_seen_at` = :last_snapshot_at';

        Store::Exec('
UPDATE market SET ' . implode(', ', $sets) . ',
    last_snapshot_at = :last_snapshot_at,
    next_snapshot_at = :next_snapshot_at,
    snapshot_count = snapshot_count + :inc
WHERE condition_id = :condition_id
', $params);

        if ($row) {
            self::SyncTags([$row]);
        }

        if (!$error) {
            $this->stats['snapshots']++;
        }
        $this->Say(sprintf(
            '%s %s | %s holders%s | %s',
            $error ? 'FAIL' : 'ok  ',
            substr($cid, 0, 10),
            number_format(count($seen)),
            $complete ? '' : ' (partial, ' . $pages . ' pages)',
            substr((string)($row['question'] ?? $market['question']), 0, 70)
        ));
    }

    /**
     * @param int $snapshot_id
     * @param array $outcomes labels
     * @param array $tokens token ids
     * @param array $prices current prices
     * @param array $holders outcome_index => [wallet => shares]
     * @return void
     */
    private function SaveOutcomes(int $snapshot_id, array $outcomes, array $tokens, array $prices, array $holders): void
    {
        $rows = [];
        $holder_rows = [];

        foreach ($outcomes as $i => $label) {
            $wallets = $holders[$i] ?? [];
            arsort($wallets);
            $shares = array_values($wallets);

            $rows[] = [
                'snapshot_id'       => $snapshot_id,
                'outcome_index'     => $i,
                'outcome'           => self::Cut((string)$label, 255),
                'token_id'          => isset($tokens[$i]) ? (string)$tokens[$i] : null,
                'price'             => self::Num($prices[$i] ?? null),
                'holder_count'      => count($shares),
                'total_shares'      => round(array_sum($shares), 6),
                'holders_ge_10'     => count(array_filter($shares, fn($s) => $s >= 10)),
                'holders_ge_100'    => count(array_filter($shares, fn($s) => $s >= 100)),
                'holders_ge_1000'   => count(array_filter($shares, fn($s) => $s >= 1000)),
                'holders_ge_10000'  => count(array_filter($shares, fn($s) => $s >= 10000)),
                'top10_shares'      => round(array_sum(array_slice($shares, 0, 10)), 6),
                'max_holder_shares' => $shares[0] ?? 0,
            ];

            if ($this->store_holder_rows !== 0) {
                $keep = $this->store_holder_rows < 0 ? $wallets : array_slice($wallets, 0, $this->store_holder_rows, true);
                foreach ($keep as $wallet => $amount) {
                    $holder_rows[] = [
                        'snapshot_id'   => $snapshot_id,
                        'outcome_index' => $i,
                        'proxy_wallet'  => substr((string)$wallet, 0, 42),
                        'shares'        => round($amount, 6),
                    ];
                }
            }
        }

        Store::InsertMany('snapshot_outcome', array_keys($rows[0]), $rows);

        if ($holder_rows) {
            Store::InsertMany('snapshot_holder', ['snapshot_id', 'outcome_index', 'proxy_wallet', 'shares'], $holder_rows, '', 250);
        }
    }

    /**
     * Seconds until the next count, by time left before the market's end date.
     *
     * @param int|null $seconds_to_end
     * @return int
     */
    public static function Interval(?int $seconds_to_end): int
    {
        $h = 3600;
        return match (true) {
            is_null($seconds_to_end)      => 24 * $h,
            $seconds_to_end < -7 * 86400  => 24 * $h, // long past its end date and still open
            $seconds_to_end <= 86400      => $h,      // last day, and while waiting to close
            $seconds_to_end <= 2 * 86400  => 2 * $h,
            $seconds_to_end <= 7 * 86400  => 4 * $h,
            $seconds_to_end <= 30 * 86400 => 12 * $h,
            default                       => 24 * $h,
        };
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Gamma sends outcomes, outcomePrices and clobTokenIds as JSON-encoded strings.
     *
     * @param mixed $v
     * @return array
     */
    public static function JsonList(mixed $v): array
    {
        if (is_array($v)) {
            return array_values($v);
        }
        if (is_string($v) && $v !== '') {
            $d = json_decode($v, true);
            return is_array($d) ? array_values($d) : [];
        }
        return [];
    }

    /**
     * @param mixed $v
     * @return float|null
     */
    private static function Num(mixed $v): ?float
    {
        return is_numeric($v) ? (float)$v : null;
    }

    /**
     * @param mixed $v
     * @param int $len
     * @return string|null
     */
    private static function Cut(mixed $v, int $len): ?string
    {
        if (is_null($v) || $v === '') {
            return null;
        }
        return mb_substr((string)$v, 0, $len);
    }

    /**
     * @return int
     */
    private function TimeLeft(): int
    {
        return $this->deadline - time();
    }

    /**
     * @param string $msg
     * @return void
     */
    private function Say(string $msg): void
    {
        if ($this->verbose) {
            echo gmdate('H:i:s') . ' ' . $msg . PHP_EOL;
        }
    }
}
