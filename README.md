# poly

Counts how many wallets hold each side of Polymarket markets, on a schedule, and records how each market resolved. The goal is to compare the head-count majority with the winner at different times before the close, and to see where the crowd and the price disagree.

Built on [QuickDRY9](https://github.com/BenKucenski/QuickDRY9). It uses Polymarket's public read APIs, which need no key:

- **Gamma** (`gamma-api.polymarket.com/markets/keyset`): market list, prices, end dates, resolution.
- **Data API v2** (`data-api.polymarket.com/v2/holders`): every holder of a market, by outcome, in cursor pages of up to 1000 per outcome.

## Setup

1. `composer install`
2. Create the database: `mysql -u root -p < sql/schema.sql`
3. Copy `env.sample.php` to `env.php` and set `DATA_FOLDER` and the MySQL credentials. `DATA_FOLDER` must already exist and be writable.
4. Test it with a short run: `php tasks/collect.php -m300` (stops after 5 minutes).

## Scheduling

The script stops starting new work after `POLY_MAX_RUNTIME_SECONDS` (54 minutes by default). A lock file in `DATA_FOLDER` makes a new run exit at once if the previous one is still going. Also set a hard 55-minute kill in the scheduler as a backstop.

**Windows (PowerShell, as admin):**

```powershell
$a = New-ScheduledTaskAction -Execute "C:\php\php.exe" -Argument "tasks\collect.php -q" -WorkingDirectory "C:\sites\poly"
$t = New-ScheduledTaskTrigger -Once -At (Get-Date -Minute 0 -Second 0) -RepetitionInterval (New-TimeSpan -Hours 1) -RepetitionDuration (New-TimeSpan -Days 3650)
$s = New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Minutes 55) -MultipleInstances IgnoreNew -StartWhenAvailable
Register-ScheduledTask -TaskName "Poly Collector" -Action $a -Trigger $t -Settings $s -User "SYSTEM"
```

**Linux (cron):**

```
0 * * * * cd /var/www/poly && timeout 55m php tasks/collect.php -q
```

Each run writes a row to `run_log`, with snapshot counts, request counts, errors and how many markets were still due when it stopped. If `due_remaining` stays high, the collector can't keep up: raise `POLY_MIN_VOLUME` or lower `POLY_MAX_HOLDER_PAGES`.

## What a run does

1. **Discovery** (every `POLY_DISCOVERY_EVERY_MINUTES`): pages through all open markets with lifetime volume of at least `POLY_MIN_VOLUME` and upserts them into `market`. New markets are due right away. If discovery runs out of its share of the hour, it saves its cursor and resumes next run.
2. **Resolution:** closed markets without a winner are rechecked hourly. The winner is the outcome that settled at $1. A market that settles any other way (a 50/50 split, for example) keeps `winning_outcome_index` NULL and stops being checked after 30 days.
3. **Snapshots:** due markets are processed oldest-due first. For each batch, prices are refreshed from Gamma, then every holder page is read. One row goes into `snapshot`, and one row per outcome into `snapshot_outcome`. Markets that drop out of the open list go to the resolution check.

How often a market is counted depends on how long it has left:

| Time to end date | Counted every |
| --- | --- |
| more than 30 days, or no end date | 24 hours |
| 7–30 days | 12 hours |
| 2–7 days | 4 hours |
| 1–2 days | 2 hours |
| last day, and after the end date until it closes | 1 hour |
| more than 7 days past the end date, still open | 24 hours |

Every row has its capture timestamp, so you can bucket by time later.

## Tables

- `market`: one row per market (condition), with outcomes, tags, event, end date and, once settled, `winning_outcome_index`.
- `snapshot`: one row per count. `captured_at`, `seconds_to_end`, `unique_holders`, `multi_side_holders` (wallets holding more than one outcome), volume and best bid/ask. `is_complete = 0` means the page cap or time budget cut the walk short. `error` is set if the walk failed.
- `snapshot_outcome`: per outcome, `price`, `holder_count`, `total_shares`, wallets at 10/100/1,000/10,000+ shares, shares held by the top 10 wallets, and the largest wallet's holding.
- `snapshot_holder`: per-wallet rows, only when `POLY_STORE_HOLDER_ROWS` is not 0.
- `v_snapshot_vs_result`: for each snapshot, the head-count pick, the shares pick and the price pick next to the winner, with `*_correct` flags and `crowd_disagrees_with_price`.

## Starter queries

How often each kind of "majority" picked the winner, by days before close (binary markets, complete counts only):

```sql
SELECT FLOOR(hours_before_close / 24) AS days_out,
       COUNT(DISTINCT condition_id)   AS markets,
       AVG(headcount_correct)         AS headcount_acc,
       AVG(shares_correct)            AS shares_acc,
       AVG(price_correct)             AS price_acc
FROM v_snapshot_vs_result
WHERE winning_outcome_index IS NOT NULL AND is_complete = 1 AND outcome_count = 2
GROUP BY days_out
ORDER BY days_out;
```

When the most-held side is not the price favorite: if the crowd's pick wins more often than its average price, buying it would have paid.

```sql
SELECT COALESCE(sports_market_type, 'non-sports') AS kind,
       COUNT(DISTINCT condition_id)               AS markets,
       AVG(headcount_correct)                     AS crowd_win_rate,
       AVG(headcount_pick_price)                  AS avg_price_of_crowd_pick
FROM v_snapshot_vs_result
WHERE crowd_disagrees_with_price = 1 AND winning_outcome_index IS NOT NULL AND is_complete = 1
GROUP BY kind
ORDER BY markets DESC;
```

## Things to keep in mind

- **A holder is a wallet, not a person.** One person can use several wallets, and holder counts only include wallets still holding at capture time.
- **Snapshots of one market are not independent.** A market counted 100 times shouldn't weigh 100 times as much. Count `DISTINCT condition_id`, or take one snapshot per market per time bucket.
- **Multi-outcome events** (an election with 10 candidates, say) are stored as separate Yes/No markets. That's how Polymarket structures them (`neg_risk = 1`). To compare candidates, group by `event_id`.
- **Missing markets:** a market that comes back as neither open nor closed (paused or archived) is rechecked every 6 hours and dropped 30 days after its end date.
- **Data models:** none are generated yet. To get QuickDRY classes for the tables, run `php tasks_db/mysql2code.php -dpoly`.
