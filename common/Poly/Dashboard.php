<?php
declare(strict_types=1);

namespace common\Poly;

/**
 * Read-only queries behind the homepage dashboard (pages/home).
 *
 * The accuracy numbers use the same picks as v_snapshot_vs_result (most
 * holders, most shares, highest price; a tie for first means no pick), but
 * only over resolved markets' complete, error-free snapshots, so they don't
 * pay for every open market's snapshots the way querying the view would.
 * Like the README's starter queries, rates are averaged over snapshots.
 */
class Dashboard
{
    /** Days-out buckets past this are folded into the last one. */
    public const int MAX_DAYS_OUT = 30;

    /**
     * The sections tasks/dashboard.php computes and the homepage reads, in
     * build order. Each one is stored as its own dashboard_cache row.
     *
     * @return array<string, callable>
     */
    public static function Sections(): array
    {
        return [
            'totals'        => static fn() => self::Totals(),
            'runs'          => static fn() => self::Runs(72),
            'cadence'       => static fn() => self::Cadence(),
            'accuracy'      => static fn() => self::AccuracyByDaysOut(),
            'crowd'         => static fn() => self::CrowdVsPrice(),
            'tags'          => static fn() => self::Tags(20),
            'closing_soon'  => static fn() => self::ClosingSoon(12),
            'resolved'      => static fn() => self::RecentlyResolved(12),
            'tables'        => static fn() => self::TableSizes(),
        ];
    }

    /**
     * Computes every section and saves it to dashboard_cache. A section that
     * fails keeps its last good data and records the error, so one bad query
     * doesn't blank the whole page.
     *
     * @param string[]|null $only section names to rebuild (null = all)
     * @param callable|null $say progress output, e.g. fn(string $msg) => print($msg)
     * @return array{built: int, failed: int, ms: int}
     */
    public static function Build(?array $only = null, ?callable $say = null): array
    {
        $say ??= static fn(string $msg) => null;
        $start = microtime(true);
        $built = 0;
        $failed = 0;

        foreach (self::Sections() as $name => $fn) {
            if ($only !== null && !in_array($name, $only, true)) {
                continue;
            }

            $t = microtime(true);
            try {
                $json = json_encode($fn(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                $ms = (int)round((microtime(true) - $t) * 1000);
                Store::Exec('
INSERT INTO dashboard_cache (name, data, built_at, build_ms, error, tried_at)
VALUES (:name, :data, :built_at, :build_ms, NULL, :tried_at)
ON DUPLICATE KEY UPDATE data = VALUES(data), built_at = VALUES(built_at), build_ms = VALUES(build_ms),
                        error = NULL, tried_at = VALUES(tried_at)
', ['name' => $name, 'data' => $json, 'built_at' => Store::Time(), 'build_ms' => $ms, 'tried_at' => Store::Time()]);
                $built++;
                $say(str_pad($name, 14) . ' ' . $ms . ' ms, ' . number_format(strlen($json)) . ' bytes');
            } catch (\Throwable $e) {
                $failed++;
                $error = mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 1000);
                $say(str_pad($name, 14) . ' FAILED: ' . $error);
                try {
                    // Keep the last good data; just record the failure.
                    Store::Exec('
INSERT INTO dashboard_cache (name, data, built_at, build_ms, error, tried_at)
VALUES (:name, NULL, NULL, 0, :error, :tried_at)
ON DUPLICATE KEY UPDATE error = VALUES(error), tried_at = VALUES(tried_at)
', ['name' => $name, 'error' => $error, 'tried_at' => Store::Time()]);
                } catch (\Throwable) {
                    // the database itself is gone; nothing more to record
                }
            }
        }

        return ['built' => $built, 'failed' => $failed, 'ms' => (int)round((microtime(true) - $start) * 1000)];
    }

    /**
     * Reads every stored section. Runs one primary-key scan of a small table;
     * none of the heavy queries.
     *
     * @return array<string, array{data: mixed, built_at: ?string, build_ms: int, error: ?string, tried_at: ?string}>
     */
    public static function Load(): array
    {
        $out = [];
        foreach (Store::Rows('SELECT name, data, built_at, build_ms, error, tried_at FROM dashboard_cache') as $row) {
            $out[$row['name']] = [
                'data'     => $row['data'] === null ? null : json_decode($row['data'], true),
                'built_at' => $row['built_at'],
                'build_ms' => (int)$row['build_ms'],
                'error'    => $row['error'],
                'tried_at' => $row['tried_at'],
            ];
        }
        return $out;
    }

    /**
     * Tries the MySQL settings from env.php before QuickDRY connects, because
     * QuickDRY's connector die()s with a debug dump on a failed connect. A web
     * page should say "can't connect" instead.
     *
     * @return string|null the error, or null when the connection works
     */
    public static function ConnectionError(): ?string
    {
        foreach (['MYSQL_HOST', 'MYSQL_USER', 'MYSQL_PASS', 'MYSQL_PORT', 'MYSQL_BASE'] as $const) {
            if (!defined($const)) {
                return $const . ' is not set in env.php';
            }
        }

        mysqli_report(MYSQLI_REPORT_OFF);
        try {
            $db = @mysqli_connect((string)MYSQL_HOST, (string)MYSQL_USER, (string)MYSQL_PASS, (string)MYSQL_BASE, (int)MYSQL_PORT);
            if (!$db) {
                return 'MySQL connect error ' . mysqli_connect_errno() . ': ' . mysqli_connect_error();
            }
            mysqli_close($db);
            return null;
        } finally {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // PHP 8.1+ default
        }
    }

    /**
     * Headline counts.
     *
     * @return array
     */
    public static function Totals(): array
    {
        $now = Store::Time();
        $m = Store::Row("
SELECT COUNT(*)                                                         AS markets,
       SUM(tracking = 1 AND closed = 0)                                 AS open_tracking,
       SUM(closed = 1)                                                  AS closed,
       SUM(winning_outcome_index IS NOT NULL)                           AS resolved,
       SUM(closed = 1 AND winning_outcome_index IS NULL AND tracking = 1) AS awaiting_result,
       SUM(tracking = 1 AND closed = 0 AND next_snapshot_at <= :now_due) AS due_now,
       SUM(first_seen_at >= :since_new)                                 AS new_24h,
       SUM(snapshot_count)                                              AS snapshots,
       COUNT(DISTINCT event_id)                                         AS events,
       SUM(IF(tracking = 1 AND closed = 0, volume_num, 0))              AS open_volume
FROM market
", ['now_due' => $now, 'since_new' => Store::Time(time() - 86400)]) ?? [];

        $s = Store::Row('
SELECT COUNT(*)                AS snapshots_24h,
       SUM(is_complete = 0)    AS incomplete_24h,
       SUM(error IS NOT NULL)  AS errors_24h,
       MIN(captured_at)        AS first_snapshot_at,
       MAX(captured_at)        AS last_snapshot_at
FROM snapshot
WHERE captured_at >= :since_snap
', ['since_snap' => Store::Time(time() - 86400)]) ?? [];

        $range = Store::Row('SELECT MIN(captured_at) AS first_at, MAX(captured_at) AS last_at FROM snapshot') ?? [];

        return array_merge($m, $s, [
            'first_snapshot_ever' => $range['first_at'] ?? null,
            'last_snapshot_ever'  => $range['last_at'] ?? null,
            'last_discovery_at'   => Store::GetState('discovery_finished_at'),
            'discovery_cursor'    => Store::GetState('discovery_cursor'),
        ]);
    }

    /**
     * The most recent runs, newest first.
     *
     * @param int $limit
     * @return array
     */
    public static function Runs(int $limit = 72): array
    {
        return Store::Rows('
SELECT id, started_at, finished_at, status, discovered, resolved, snapshots, due_remaining, requests, http_errors, notes,
       TIMESTAMPDIFF(SECOND, started_at, COALESCE(finished_at, UTC_TIMESTAMP())) AS seconds
FROM run_log
ORDER BY id DESC
LIMIT ' . max(1, $limit));
    }

    /**
     * Open markets grouped by how often the collector counts them
     * (the same bands as Collector::Interval()).
     *
     * @return array
     */
    public static function Cadence(): array
    {
        $rows = Store::Rows("
SELECT CASE
           WHEN end_date IS NULL                                   THEN 'no end date'
           WHEN end_date < UTC_TIMESTAMP() - INTERVAL 7 DAY        THEN '7+ days past end'
           WHEN end_date <= UTC_TIMESTAMP() + INTERVAL 1 DAY       THEN 'last day / past end'
           WHEN end_date <= UTC_TIMESTAMP() + INTERVAL 2 DAY       THEN '1-2 days'
           WHEN end_date <= UTC_TIMESTAMP() + INTERVAL 7 DAY       THEN '2-7 days'
           WHEN end_date <= UTC_TIMESTAMP() + INTERVAL 30 DAY      THEN '7-30 days'
           ELSE '30+ days'
       END      AS band,
       COUNT(*) AS markets
FROM market
WHERE tracking = 1 AND closed = 0
GROUP BY band
");
        $order = [
            'last day / past end' => 'every hour',
            '1-2 days'            => 'every 2 hours',
            '2-7 days'            => 'every 4 hours',
            '7-30 days'           => 'every 12 hours',
            '30+ days'            => 'every 24 hours',
            'no end date'         => 'every 24 hours',
            '7+ days past end'    => 'every 24 hours',
        ];
        $by = array_column($rows, 'markets', 'band');
        $out = [];
        foreach ($order as $band => $every) {
            $out[] = ['band' => $band, 'every' => $every, 'markets' => (int)($by[$band] ?? 0)];
        }
        return $out;
    }

    /**
     * Per-snapshot picks for resolved markets, as a SQL fragment shared by
     * the accuracy queries. Columns: snapshot_id, condition_id, outcome_count,
     * hours_before_close, winning_outcome_index, head_pick, head_pick_price,
     * share_pick, price_pick (each pick NULL on a tie).
     *
     * @return string
     */
    private static function PicksSQL(): string
    {
        return '
SELECT a.snapshot_id,
       a.condition_id,
       a.outcome_count,
       a.hours_before_close,
       a.winning_outcome_index,
       IF(a.head_ties = 1, a.head_pick, NULL)        AS head_pick,
       IF(a.head_ties = 1, a.head_pick_price, NULL)  AS head_pick_price,
       IF(a.share_ties = 1, a.share_pick, NULL)      AS share_pick,
       IF(a.price_ties = 1, a.price_pick, NULL)      AS price_pick
FROM (SELECT r.snapshot_id,
             r.condition_id,
             r.outcome_count,
             r.hours_before_close,
             r.winning_outcome_index,
             SUM(r.holder_count = r.max_holders)                            AS head_ties,
             MIN(IF(r.holder_count = r.max_holders, r.outcome_index, NULL)) AS head_pick,
             MAX(IF(r.holder_count = r.max_holders, r.price, NULL))         AS head_pick_price,
             SUM(r.total_shares = r.max_shares)                             AS share_ties,
             MIN(IF(r.total_shares = r.max_shares, r.outcome_index, NULL))  AS share_pick,
             SUM(r.price = r.max_price)                                     AS price_ties,
             MIN(IF(r.price = r.max_price, r.outcome_index, NULL))          AS price_pick
      FROM (SELECT so.snapshot_id,
                   s.condition_id,
                   m.outcome_count,
                   m.winning_outcome_index,
                   TIMESTAMPDIFF(MINUTE, s.captured_at, COALESCE(m.closed_time, m.end_date)) / 60.0 AS hours_before_close,
                   so.outcome_index,
                   so.holder_count,
                   so.total_shares,
                   so.price,
                   MAX(so.holder_count) OVER (PARTITION BY so.snapshot_id) AS max_holders,
                   MAX(so.total_shares) OVER (PARTITION BY so.snapshot_id) AS max_shares,
                   MAX(so.price) OVER (PARTITION BY so.snapshot_id)        AS max_price
            FROM market m
                     JOIN snapshot s ON s.condition_id = m.condition_id
                     JOIN snapshot_outcome so ON so.snapshot_id = s.id
            WHERE m.winning_outcome_index IS NOT NULL
              AND s.is_complete = 1
              AND s.error IS NULL) r
      GROUP BY r.snapshot_id, r.condition_id, r.outcome_count, r.hours_before_close, r.winning_outcome_index) a
';
    }

    /**
     * How often each kind of majority picked the winner, by days before close
     * (binary markets, complete snapshots only).
     *
     * @return array
     */
    public static function AccuracyByDaysOut(): array
    {
        return Store::Rows('
SELECT LEAST(GREATEST(FLOOR(p.hours_before_close / 24), 0), ' . self::MAX_DAYS_OUT . ') AS days_out,
       COUNT(DISTINCT p.condition_id)                       AS markets,
       COUNT(*)                                             AS snapshots,
       AVG(p.head_pick = p.winning_outcome_index)           AS headcount_acc,
       AVG(p.share_pick = p.winning_outcome_index)          AS shares_acc,
       AVG(p.price_pick = p.winning_outcome_index)          AS price_acc
FROM (' . self::PicksSQL() . ') p
WHERE p.outcome_count = 2
GROUP BY days_out
ORDER BY days_out
');
    }

    /**
     * Overall accuracy, plus the snapshots where the most-held side was not the
     * price favorite: if the crowd's pick won more often than its average
     * price, buying it would have paid.
     *
     * @return array
     */
    public static function CrowdVsPrice(): array
    {
        return Store::Row('
SELECT COUNT(DISTINCT p.condition_id)                                              AS markets,
       COUNT(*)                                                                    AS snapshots,
       AVG(p.head_pick = p.winning_outcome_index)                                  AS headcount_acc,
       AVG(p.share_pick = p.winning_outcome_index)                                 AS shares_acc,
       AVG(p.price_pick = p.winning_outcome_index)                                 AS price_acc,
       COUNT(DISTINCT IF(p.head_pick <> p.price_pick, p.condition_id, NULL))       AS disagree_markets,
       SUM(p.head_pick <> p.price_pick)                                            AS disagree_snapshots,
       AVG(IF(p.head_pick <> p.price_pick, p.head_pick = p.winning_outcome_index, NULL)) AS crowd_win_rate,
       AVG(IF(p.head_pick <> p.price_pick, p.head_pick_price, NULL))               AS crowd_pick_price
FROM (' . self::PicksSQL() . ') p
') ?? [];
    }

    /**
     * Market counts per tag, with head-count and price accuracy where there are
     * resolved markets (a market with several tags counts under each).
     *
     * @param int $limit
     * @return array
     */
    public static function Tags(int $limit = 20): array
    {
        $tags = Store::Rows('
SELECT t.tag,
       COUNT(*)                                     AS markets,
       SUM(m.tracking = 1 AND m.closed = 0)         AS open_markets,
       SUM(m.winning_outcome_index IS NOT NULL)     AS resolved,
       SUM(m.snapshot_count)                        AS snapshots,
       SUM(m.volume_num)                            AS volume
FROM market_tag t
         JOIN market m ON m.condition_id = t.condition_id
GROUP BY t.tag
ORDER BY markets DESC, t.tag
LIMIT ' . max(1, $limit));

        if (!$tags) {
            return [];
        }

        $acc = Store::Rows('
SELECT t.tag,
       AVG(p.head_pick = p.winning_outcome_index)  AS headcount_acc,
       AVG(p.price_pick = p.winning_outcome_index) AS price_acc
FROM (' . self::PicksSQL() . ') p
         JOIN market_tag t ON t.condition_id = p.condition_id
GROUP BY t.tag
');
        $acc = array_column($acc, null, 'tag');

        foreach ($tags as &$t) {
            $t['headcount_acc'] = $acc[$t['tag']]['headcount_acc'] ?? null;
            $t['price_acc'] = $acc[$t['tag']]['price_acc'] ?? null;
        }
        return $tags;
    }

    /**
     * Open markets with the soonest end dates, with their latest count.
     *
     * @param int $limit
     * @return array
     */
    public static function ClosingSoon(int $limit = 12): array
    {
        $markets = Store::Rows('
SELECT condition_id, question, event_title, slug, event_slug, end_date, volume_num, snapshot_count,
       last_snapshot_at, next_snapshot_at, outcomes
FROM market
WHERE tracking = 1 AND closed = 0 AND end_date >= UTC_TIMESTAMP() - INTERVAL 1 DAY
ORDER BY end_date
LIMIT ' . max(1, $limit));
        return self::WithLatestSnapshot($markets, false);
    }

    /**
     * The most recently resolved markets, with the last complete count taken
     * before they closed and whether each pick got it right.
     *
     * @param int $limit
     * @return array
     */
    public static function RecentlyResolved(int $limit = 12): array
    {
        $markets = Store::Rows('
SELECT condition_id, question, event_title, slug, event_slug, end_date, closed_time, volume_num, snapshot_count,
       winning_outcome_index, winning_outcome, outcomes
FROM market
WHERE winning_outcome_index IS NOT NULL
ORDER BY COALESCE(closed_time, end_date) DESC
LIMIT ' . max(1, $limit));
        return self::WithLatestSnapshot($markets, true);
    }

    /**
     * Adds 'snapshot' (the latest one, or the latest complete one before the
     * close when $before_close), its 'outcomes', and the three picks.
     *
     * @param array $markets
     * @param bool $before_close
     * @return array
     */
    private static function WithLatestSnapshot(array $markets, bool $before_close): array
    {
        if (!$markets) {
            return [];
        }

        $params = [];
        $ph = [];
        foreach (array_values($markets) as $i => $m) {
            $ph[] = ':cid' . $i;
            $params['cid' . $i] = $m['condition_id'];
        }

        $where = $before_close
            ? 'AND s.is_complete = 1 AND s.captured_at <= COALESCE(m.closed_time, m.end_date)'
            : '';

        $snaps = Store::Rows('
SELECT s.*
FROM snapshot s
         JOIN (SELECT s.condition_id, MAX(s.captured_at) AS captured_at
               FROM snapshot s
                        JOIN market m ON m.condition_id = s.condition_id
               WHERE s.condition_id IN (' . implode(', ', $ph) . ') AND s.error IS NULL ' . $where . '
               GROUP BY s.condition_id) l ON l.condition_id = s.condition_id AND l.captured_at = s.captured_at
WHERE s.error IS NULL
', $params);
        $snaps = array_column($snaps, null, 'condition_id');

        $outcomes = [];
        if ($snaps) {
            $ids = array_map('intval', array_column($snaps, 'id'));
            foreach (Store::Rows('
SELECT snapshot_id, outcome_index, outcome, price, holder_count, total_shares
FROM snapshot_outcome
WHERE snapshot_id IN (' . implode(', ', $ids) . ')
ORDER BY snapshot_id, outcome_index
') as $o) {
                $outcomes[(int)$o['snapshot_id']][] = $o;
            }
        }

        foreach ($markets as &$m) {
            $s = $snaps[$m['condition_id']] ?? null;
            $m['snapshot'] = $s;
            $m['outcome_rows'] = $s ? ($outcomes[(int)$s['id']] ?? []) : [];
            $m['picks'] = self::Picks($m['outcome_rows']);
        }
        return $markets;
    }

    /**
     * The outcome index with the most holders, most shares and highest price
     * (NULL when two outcomes tie for first), as in v_snapshot_vs_result.
     *
     * @param array $outcomes snapshot_outcome rows
     * @return array{headcount: ?int, shares: ?int, price: ?int}
     */
    public static function Picks(array $outcomes): array
    {
        $pick = static function (string $col) use ($outcomes): ?int {
            if (!$outcomes) {
                return null;
            }
            $values = array_map(static fn($o) => $o[$col] === null ? null : (float)$o[$col], $outcomes);
            $max = max($values);
            if ($max === null) {
                return null;
            }
            $hits = array_keys(array_filter($values, static fn($v) => $v === $max));
            return count($hits) === 1 ? (int)$outcomes[$hits[0]]['outcome_index'] : null;
        };

        return [
            'headcount' => $pick('holder_count'),
            'shares'    => $pick('total_shares'),
            'price'     => $pick('price'),
        ];
    }

    /**
     * Approximate row counts and sizes from information_schema (InnoDB row
     * counts are estimates; exact COUNT(*) on the big tables is too slow for a
     * page load).
     *
     * @return array
     */
    public static function TableSizes(): array
    {
        return Store::Rows("
SELECT table_name                    AS name,
       table_type                    AS type,
       table_rows                    AS approx_rows,
       data_length + index_length    AS bytes,
       update_time
FROM information_schema.tables
WHERE table_schema = DATABASE()
ORDER BY table_type, (data_length + index_length) DESC
");
    }
}
