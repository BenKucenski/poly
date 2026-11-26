# poly

Counts how many wallets hold each side of Polymarket markets, on a schedule, and records how each market resolved. The goal is to compare the head-count majority with the winner at different times before the close, and to see where the crowd and the price disagree.

Built on [QuickDRY9](https://github.com/BenKucenski/QuickDRY9). Requests go through its `Curl` class with a 30-second timeout and `Curl::$throw_exceptions` on, so a hung or failed request is retried instead of ending the run. It uses Polymarket's public read APIs, which need no key:

- **Gamma** (`gamma-api.polymarket.com/markets/keyset`): market list, prices, end dates, resolution.
- **Data API v2** (`data-api.polymarket.com/v2/holders`): every holder of a market, by outcome, in cursor pages of up to 1000 per outcome.
- **CLOB** (`clob.polymarket.com/books`): the order book for every outcome of a market, read once right after each count finishes.

## Setup

1. `composer install`
2. Create the database: `mysql -u root -p < sql/schema.sql`
3. Copy `env.sample.php` to `env.php` and set `DATA_FOLDER` and the MySQL credentials. `DATA_FOLDER` must already exist and be writable.
4. Test it with a short run: `php tasks/collect.php -m300` (stops after 5 minutes).
5. Build the dashboard: `php tasks/dashboard.php`.

## Dashboard

The homepage (`pages/home`) is a read-only dashboard of what's in the database: headline counts, snapshots per run, open markets by schedule band, how often the head-count / shares / price picks named the winner by days before close, the crowd-vs-price disagreement (at the midpoint and at the ask you could have bought at), what buying the crowd's pick when each count finished would have returned by the next count and by resolution, markets closing soonest, recently resolved markets, top tags, table sizes and the recent `run_log`. The layout is `masterpages/main.php`.

The page doesn't query the collected data itself. `tasks/dashboard.php` runs every dashboard query (`common/Poly/Dashboard.php`) and stores each section's results as JSON in `dashboard_cache`. A page view reads only that table, and the header shows when the numbers were computed. If a section fails to rebuild, it keeps its previous numbers, and the page lists the error above them.

```
php tasks/dashboard.php                  rebuild every section
php tasks/dashboard.php -q               quiet (errors only; exit code 1 if a section failed)
php tasks/dashboard.php -stotals,runs    rebuild only these sections
```

Sections: `totals`, `runs`, `cadence`, `accuracy`, `crowd`, `forward`, `tags`, `closing_soon`, `resolved`, `tables`.

Bootstrap and Chart.js load from jsDelivr. If the CDN is unreachable, the tables still show every number.

- **IIS:** rename `web.config.sample.txt` to `web.config` and point the site at the project folder.
- **Apache:** the included `.htaccess` routes everything through `index.php`.
- **Local:** `php -S localhost:8000 index.php`, then open http://localhost:8000.

The dashboard has no sign-in. Put it behind your own access control (Cloudflare Access, IIS authentication, a VPN) before it's reachable from the internet.

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

Schedule `tasks/dashboard.php` separately, every 15 minutes. It runs its own lock, so it never overlaps itself, and it's read-only, so it can run while the collector writes. The dashboard is then at most 15 minutes behind the data.

**Windows:**

```powershell
$a = New-ScheduledTaskAction -Execute "C:\php\php.exe" -Argument "tasks\dashboard.php -q" -WorkingDirectory "C:\sites\poly"
$t = New-ScheduledTaskTrigger -Once -At (Get-Date -Minute 0 -Second 0) -RepetitionInterval (New-TimeSpan -Minutes 15) -RepetitionDuration (New-TimeSpan -Days 3650)
$s = New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Minutes 14) -MultipleInstances IgnoreNew -StartWhenAvailable
Register-ScheduledTask -TaskName "Poly Dashboard" -Action $a -Trigger $t -Settings $s -User "SYSTEM"
```

**Linux:**

```
*/15 * * * * cd /var/www/poly && timeout 14m php tasks/dashboard.php -q
```

Each collector run writes a row to `run_log`, with snapshot counts, request counts, errors and how many markets were still due when it stopped. If `due_remaining` stays high, the collector can't keep up: raise `POLY_MIN_VOLUME` or lower `POLY_MAX_HOLDER_PAGES`.

## What a run does

1. **Discovery** (every `POLY_DISCOVERY_EVERY_MINUTES`): pages through all open markets with lifetime volume of at least `POLY_MIN_VOLUME` and upserts them into `market`. New markets are due right away. If discovery runs out of its share of the hour, it saves its cursor and resumes next run.
2. **Resolution:** closed markets without a winner are rechecked hourly. The winner is the outcome that settled at $1. A market that settles any other way (a 50/50 split, for example) keeps `winning_outcome_index` NULL and stops being checked after 30 days.
3. **Snapshots:** due markets are processed oldest-due first. For each batch, prices are refreshed from Gamma, then every holder page is read. When the walk finishes, the order books for the market's outcomes are read in one request, and the best bid and ask are saved with that moment's timestamp. The head count only exists once the walk is done, so that's the price you could actually have traded at on it. If the order-book read fails, the count is still saved and its `book_*` columns stay NULL. One row goes into `snapshot`, and one row per outcome into `snapshot_outcome`. Markets that drop out of the open list go to the resolution check.

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
- `market_tag`: one row per market per tag, indexed both ways, for filtering and grouping by tag. `market.tags` keeps the raw comma-separated list. The collector rewrites a market's rows every time it refreshes that market.
- `snapshot`: one row per count. `captured_at` is when the holder walk started. `completed_at` is when it finished, the moment the head count is known. `book_at` is when the order books were read, just after. Then `seconds_to_end`, `unique_holders`, `multi_side_holders` (wallets holding more than one outcome), volume, and Gamma's best bid/ask for the first outcome as of the batch lookup. `is_complete = 0` means the page cap or time budget cut the walk short. `error` is set if the walk failed.
- `snapshot_outcome`: per outcome, `price`, `holder_count`, `total_shares`, wallets at 10/100/1,000/10,000+ shares, shares held by the top 10 wallets, and the largest wallet's holding. `price` is Gamma's displayed price from the batch lookup, which can be minutes older than the count. `book_bid` / `book_ask` (with `book_bid_size` / `book_ask_size`) are the best CLOB bid and ask at `snapshot.book_at`: what you could sell and buy at once the count was known. They're NULL on snapshots from before migration 003, and for an outcome with no order book.
- `snapshot_holder`: per-wallet rows, only when `POLY_STORE_HOLDER_ROWS` is not 0.
- `dashboard_cache`: one row per dashboard section, holding its JSON, when it was built, how long it took, and the last rebuild error, if any. Written by `tasks/dashboard.php`.
- `v_snapshot_vs_result`: for each snapshot, the head-count pick, the shares pick and the price pick next to the winner, with `*_correct` flags and `crowd_disagrees_with_price`. The view is timed at `signal_at` (`completed_at`, or `captured_at` on older rows). The price pick and `headcount_pick_price` use the order-book midpoint at that moment, falling back to `price` on older rows. `headcount_pick_ask` is what buying the crowd's pick cost then, and `has_book` says whether the snapshot has order-book data.

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

When the most-held side is not the price favorite: if the crowd's pick wins more often than its average price, buying it would have paid. This version uses the midpoint, so it overstates what you could have made. The next query uses the ask.

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

The same, at a price you could actually have paid: the ask when the count finished. The return is per share, before fees; a winning share pays $1.

```sql
SELECT COALESCE(sports_market_type, 'non-sports')  AS kind,
       COUNT(DISTINCT condition_id)                AS markets,
       COUNT(*)                                    AS entries,
       AVG(headcount_correct)                      AS crowd_win_rate,
       AVG(headcount_pick_ask)                     AS avg_ask,
       AVG(headcount_correct - headcount_pick_ask) AS return_per_share
FROM v_snapshot_vs_result
WHERE crowd_disagrees_with_price = 1 AND winning_outcome_index IS NOT NULL AND is_complete = 1
  AND headcount_pick_ask > 0
GROUP BY kind
ORDER BY markets DESC;
```

Accuracy by tag (a market with several tags counts under each):

```sql
SELECT t.tag,
       COUNT(DISTINCT v.condition_id) AS markets,
       AVG(v.headcount_correct)       AS headcount_acc,
       AVG(v.price_correct)           AS price_acc
FROM v_snapshot_vs_result v
         JOIN market_tag t ON t.condition_id = v.condition_id
WHERE v.winning_outcome_index IS NOT NULL AND v.is_complete = 1
GROUP BY t.tag
HAVING markets >= 20
ORDER BY markets DESC;
```

## Upgrading an existing database

- `sql/migrations/001_market_tag.sql` adds `market_tag` and fills it from `market.tags`. It is safe to run more than once.
- `sql/migrations/002_dashboard_cache.sql` adds `dashboard_cache`. Then run `php tasks/dashboard.php` once. Until you do, the homepage asks you to.
- `sql/migrations/003_entry_prices.sql` adds `snapshot.completed_at` / `book_at` and the `snapshot_outcome.book_*` columns, and replaces `v_snapshot_vs_result`. Run it before deploying the new collector, because the collector writes those columns. Existing snapshots keep NULLs: their timing can't be recovered, so the tradable numbers start with the first run after the upgrade. Safe to run more than once.

## Things to keep in mind

- **A holder is a wallet, not a person.** One person can use several wallets, and holder counts only include wallets still holding at capture time.
- **Snapshots of one market are not independent.** A market counted 100 times shouldn't weigh 100 times as much. Count `DISTINCT condition_id`, or take one snapshot per market per time bucket.
- **Multi-outcome events** (an election with 10 candidates, say) are stored as separate Yes/No markets. That's how Polymarket structures them (`neg_risk = 1`). To compare candidates, group by `event_id`.
- **Missing markets:** a market that comes back as neither open nor closed (paused or archived) is rechecked every 6 hours and dropped 30 days after its end date.
- **Data models:** none are generated yet. To get QuickDRY classes for the tables, run `php tasks_db/mysql2code.php -dpoly`.
