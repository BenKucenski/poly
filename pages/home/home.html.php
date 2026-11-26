<?php
declare(strict_types=1);

namespace pages\home;

$t = home::$Totals;
$cvp = home::$CrowdVsPrice;
$last_run = home::$Runs[0] ?? null;
$has_data = (int)($t['markets'] ?? 0) > 0;

// Oldest-first runs for the chart.
$chart_runs = array_reverse(home::$Runs);

$chart_data = [
    'runs' => array_map(static fn($r) => [
        'id'        => (int)$r['id'],
        'started'   => gmdate('Y-m-d\TH:i:s\Z', strtotime($r['started_at'] . ' UTC')),
        'status'    => $r['status'],
        'snapshots' => (int)$r['snapshots'],
        'due'       => $r['due_remaining'] === null ? null : (int)$r['due_remaining'],
        'errors'    => (int)$r['http_errors'],
        'seconds'   => (int)$r['seconds'],
    ], $chart_runs),
    'cadence' => home::$Cadence,
    'accuracy' => array_map(static fn($a) => [
        'days'      => (int)$a['days_out'],
        'markets'   => (int)$a['markets'],
        'snapshots' => (int)$a['snapshots'],
        'headcount' => $a['headcount_acc'] === null ? null : (float)$a['headcount_acc'],
        'shares'    => $a['shares_acc'] === null ? null : (float)$a['shares_acc'],
        'price'     => $a['price_acc'] === null ? null : (float)$a['price_acc'],
    ], home::$Accuracy),
    'maxDaysOut' => \common\Poly\Dashboard::MAX_DAYS_OUT,
];
?>

<header class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-4">
    <div>
        <h1 class="h3 mb-1">poly <span class="text-body-secondary fw-normal">· Polymarket head counts</span></h1>
        <div class="text-body-secondary small">
            <?php if ($has_data && !empty($t['first_snapshot_ever'])) { ?>
                Snapshots from <?php echo home::Time($t['first_snapshot_ever']); ?>
                to <?php echo home::Time($t['last_snapshot_ever'], true); ?>
            <?php } else { ?>
                No snapshots yet
            <?php } ?>
        </div>
    </div>
    <div class="text-body-secondary small text-end">
        <?php if (home::$HasCache) { ?>
            Numbers as of <?php echo home::Time(home::$BuiltAt, true); ?>
            <div>computed by <code>tasks/dashboard.php</code> in <?php echo home::Num(home::$BuildMs / 1000, 1); ?> s</div>
        <?php } ?>
    </div>
</header>

<?php if (home::$DBError) { ?>
    <div class="alert alert-danger" role="alert">
        <strong>Couldn't read the database.</strong>
        Check the MySQL settings in <code>env.php</code> and that <code>sql/schema.sql</code> has been run.
        <pre class="mb-0 mt-2 small text-wrap"><?php echo home::H(home::$DBError); ?></pre>
    </div>
<?php } elseif (!home::$HasCache) { ?>
    <div class="alert alert-info" role="alert">
        The dashboard hasn't been computed yet. Run <code>php tasks/dashboard.php</code>, then schedule it
        every 15 minutes or so (see the README).
    </div>
<?php } elseif (!$has_data) { ?>
    <div class="alert alert-info" role="alert">
        The database is reachable but empty. Run <code>php tasks/collect.php -m300</code> for a short first collection,
        then schedule it hourly (see the README).
    </div>
<?php } ?>

<?php if (home::$StaleSections) { ?>
    <div class="alert alert-warning small" role="alert">
        <strong>Some sections failed to rebuild</strong> and still show their previous numbers:
        <ul class="mb-0 mt-1">
            <?php foreach (home::$StaleSections as $name => $error) { ?>
                <li><code><?php echo home::H($name); ?></code>: <?php echo home::H($error); ?></li>
            <?php } ?>
        </ul>
    </div>
<?php } ?>

<?php if (!home::$DBError && home::$HasCache) { ?>

<!-- Headline numbers -->
<section class="row g-3 mb-4" aria-label="Headline numbers">
    <div class="col-6 col-md-4 col-xl-2">
        <div class="tile">
            <div class="tile-label">Open markets tracked</div>
            <div class="tile-value"><?php echo home::Num($t['open_tracking'] ?? 0); ?></div>
            <div class="tile-sub"><?php echo home::Num($t['due_now'] ?? 0); ?> due now · <?php echo home::Num($t['new_24h'] ?? 0); ?> new in 24 h</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="tile">
            <div class="tile-label">Resolved with a winner</div>
            <div class="tile-value"><?php echo home::Num($t['resolved'] ?? 0); ?></div>
            <div class="tile-sub"><?php echo home::Num($t['awaiting_result'] ?? 0); ?> closed, awaiting result</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="tile">
            <div class="tile-label">Snapshots</div>
            <div class="tile-value"><?php echo home::Num($t['snapshots'] ?? 0); ?></div>
            <div class="tile-sub">
                <?php echo home::Num($t['snapshots_24h'] ?? 0); ?> in 24 h
                · <?php echo home::Num($t['incomplete_24h'] ?? 0); ?> incomplete
                · <?php echo home::Num($t['errors_24h'] ?? 0); ?> errors
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="tile">
            <div class="tile-label">Markets · events</div>
            <div class="tile-value"><?php echo home::Num($t['markets'] ?? 0); ?></div>
            <div class="tile-sub">in <?php echo home::Num($t['events'] ?? 0); ?> events · <?php echo home::Money($t['open_volume'] ?? 0); ?> open volume</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="tile">
            <div class="tile-label">Last run</div>
            <?php if ($last_run) { ?>
                <div class="tile-value tile-value-sm"><?php echo home::StatusBadge($last_run['status']); ?></div>
                <div class="tile-sub">
                    #<?php echo (int)$last_run['id']; ?> · started <?php echo home::H(home::Ago(strtotime($last_run['started_at'] . ' UTC'))); ?>
                    · <?php echo home::Duration($last_run['seconds']); ?>
                </div>
            <?php } else { ?>
                <div class="tile-value tile-value-sm text-body-secondary">none yet</div>
            <?php } ?>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="tile">
            <div class="tile-label">Last discovery</div>
            <?php if (!empty($t['last_discovery_at'])) { ?>
                <div class="tile-value tile-value-sm"><?php echo home::H(home::Ago(strtotime($t['last_discovery_at'] . ' UTC'))); ?></div>
            <?php } else { ?>
                <div class="tile-value tile-value-sm text-body-secondary">not finished yet</div>
            <?php } ?>
            <div class="tile-sub"><?php echo !empty($t['discovery_cursor']) ? 'partway through; resumes next run' : 'full pass complete'; ?></div>
        </div>
    </div>
</section>

<!-- Collector health -->
<section class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="panel h-100">
            <h2 class="panel-title">Snapshots per run</h2>
            <p class="panel-note">The last <?php echo count(home::$Runs); ?> hourly runs. Markers sit on runs that ended in error or out of time; hover a bar for details.</p>
            <div class="chart-box"><canvas id="chart-runs" aria-label="Snapshots per collector run" role="img"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="panel h-100">
            <h2 class="panel-title">Open markets by schedule</h2>
            <p class="panel-note">How long each open market has left, which sets how often it's counted.</p>
            <div class="chart-box chart-box-sm"><canvas id="chart-cadence" aria-label="Open markets by time to end" role="img"></canvas></div>
        </div>
    </div>
</section>

<!-- Accuracy -->
<section class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="panel h-100">
            <h2 class="panel-title">Which majority picked the winner, by days before close</h2>
            <p class="panel-note">
                Resolved binary markets, complete snapshots only, timed from when each count finished. Rates are averaged over snapshots; the table counts distinct markets.
                Days past <?php echo \common\Poly\Dashboard::MAX_DAYS_OUT; ?> are folded into the last point.
            </p>
            <?php if (home::$Accuracy) { ?>
                <div class="chart-box"><canvas id="chart-accuracy" aria-label="Pick accuracy by days before close" role="img"></canvas></div>
                <details class="mt-2">
                    <summary class="small">Show as a table</summary>
                    <div class="table-responsive mt-2">
                        <table class="table table-sm table-numeric mb-0">
                            <thead>
                            <tr><th>Days out</th><th>Markets</th><th>Snapshots</th><th>Head count</th><th>Shares</th><th>Price</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach (home::$Accuracy as $a) { ?>
                                <tr>
                                    <td><?php echo (int)$a['days_out'] . ((int)$a['days_out'] === \common\Poly\Dashboard::MAX_DAYS_OUT ? '+' : ''); ?></td>
                                    <td><?php echo home::Num($a['markets']); ?></td>
                                    <td><?php echo home::Num($a['snapshots']); ?></td>
                                    <td><?php echo home::Pct($a['headcount_acc']); ?></td>
                                    <td><?php echo home::Pct($a['shares_acc']); ?></td>
                                    <td><?php echo home::Pct($a['price_acc']); ?></td>
                                </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            <?php } else { ?>
                <div class="empty">No resolved markets with complete snapshots yet. This fills in as markets you've counted settle.</div>
            <?php } ?>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="panel h-100">
            <h2 class="panel-title">When the crowd and the price disagree</h2>
            <p class="panel-note">Snapshots where the side held by the most wallets wasn't the price favorite. If the crowd's pick wins more often than its average price, buying it would have paid.</p>
            <?php if (!empty($cvp['snapshots'])) {
                $edge_ask = ($cvp['book_crowd_win_rate'] !== null && $cvp['crowd_pick_ask'] !== null)
                    ? (float)$cvp['book_crowd_win_rate'] - (float)$cvp['crowd_pick_ask'] : null;
                $edge_mid = ($cvp['crowd_win_rate'] !== null && $cvp['crowd_pick_price'] !== null)
                    ? (float)$cvp['crowd_win_rate'] - (float)$cvp['crowd_pick_price'] : null; ?>
                <h3 class="h6 mb-2">At the ask when the count finished</h3>
                <?php if ((int)$cvp['book_snapshots'] > 0) { ?>
                    <dl class="stat-list">
                        <div><dt>Disagreeing snapshots</dt><dd><?php echo home::Num($cvp['book_snapshots']); ?> <span class="text-body-secondary small">in <?php echo home::Num($cvp['book_markets']); ?> markets</span></dd></div>
                        <div><dt>Crowd's pick won</dt><dd><?php echo home::Pct($cvp['book_crowd_win_rate']); ?></dd></div>
                        <div><dt>Average ask for crowd's pick</dt><dd><?php echo home::Cents($cvp['crowd_pick_ask']); ?></dd></div>
                        <div><dt>Edge (win rate − ask)</dt><dd><?php echo home::Signed($edge_ask); ?></dd></div>
                    </dl>
                <?php } else { ?>
                    <p class="small text-body-secondary">No order-book data on resolved markets yet. This starts with counts taken after migration 003.</p>
                <?php } ?>
                <hr>
                <h3 class="h6 mb-2">At the midpoint, all snapshots <span class="fw-normal text-body-secondary small">(not tradable)</span></h3>
                <dl class="stat-list">
                    <div><dt>Disagreeing snapshots</dt><dd><?php echo home::Num($cvp['disagree_snapshots']); ?> <span class="text-body-secondary small">in <?php echo home::Num($cvp['disagree_markets']); ?> markets</span></dd></div>
                    <div><dt>Crowd's pick won</dt><dd><?php echo home::Pct($cvp['crowd_win_rate']); ?></dd></div>
                    <div><dt>Average price of crowd's pick</dt><dd><?php echo home::Cents($cvp['crowd_pick_price']); ?></dd></div>
                    <div><dt>Edge (win rate − price)</dt><dd><?php echo home::Signed($edge_mid); ?></dd></div>
                </dl>
                <hr>
                <h3 class="h6 mb-2">All resolved snapshots</h3>
                <dl class="stat-list">
                    <div><dt>Head-count pick right</dt><dd><?php echo home::Pct($cvp['headcount_acc']); ?></dd></div>
                    <div><dt>Shares pick right</dt><dd><?php echo home::Pct($cvp['shares_acc']); ?></dd></div>
                    <div><dt>Price pick right</dt><dd><?php echo home::Pct($cvp['price_acc']); ?></dd></div>
                    <div><dt>Base</dt><dd><?php echo home::Num($cvp['snapshots']); ?> snapshots, <?php echo home::Num($cvp['markets']); ?> markets</dd></div>
                </dl>
            <?php } else { ?>
                <div class="empty">Waiting for resolved markets.</div>
            <?php } ?>
        </div>
    </div>
</section>

<!-- Forward returns -->
<section class="panel mb-4">
    <h2 class="panel-title">If you'd bought the crowd's pick when each count finished</h2>
    <p class="panel-note">
        Bought at the ask the moment the head count was known. Sold at the bid at the market's next count, or held until it settled (pays $1 or $0).
        Per share, before fees. Only counts with an order book (from migration 003 on); open markets count toward the next-count columns.
    </p>
    <?php
    $fw = array_column(array_map(static fn($r) => $r + ['key' => $r['disagree'] === null ? 'all' : ($r['disagree'] ? 'disagree' : 'agree')], home::$Forward), null, 'key');
    if ($fw) { ?>
        <div class="table-responsive">
            <table class="table table-sm table-numeric align-middle mb-0">
                <thead>
                <tr>
                    <th rowspan="2" class="align-bottom">When</th>
                    <th rowspan="2" class="align-bottom">Entries</th>
                    <th rowspan="2" class="align-bottom">Avg ask</th>
                    <th colspan="4" class="text-center border-start">Sold at the next count</th>
                    <th colspan="3" class="text-center border-start">Held to resolution</th>
                </tr>
                <tr>
                    <th class="border-start">Trades</th><th>Avg hold</th><th>Return/share</th><th>Return</th>
                    <th class="border-start">Trades</th><th>Return/share</th><th>Return</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach (['disagree' => 'Crowd ≠ price favorite', 'agree' => 'Crowd = price favorite', 'all' => 'Every head-count pick'] as $key => $label) {
                    $r = $fw[$key] ?? null;
                    if (!$r) { continue; } ?>
                    <tr<?php echo $key === 'all' ? ' class="fw-semibold"' : ''; ?>>
                        <td><?php echo home::H($label); ?></td>
                        <td><?php echo home::Num($r['entries']); ?> <span class="text-body-secondary small fw-normal">/ <?php echo home::Num($r['markets']); ?> mkts</span></td>
                        <td><?php echo home::Cents($r['entry_ask']); ?></td>
                        <td class="border-start"><?php echo home::Num($r['next_n']); ?></td>
                        <td><?php echo $r['next_hours'] === null ? '–' : home::Num($r['next_hours'], 1) . ' h'; ?></td>
                        <td><?php echo home::Signed($r['next_return']); ?></td>
                        <td><?php echo home::Signed($r['next_roi'], 'pct'); ?></td>
                        <td class="border-start"><?php echo home::Num($r['res_n']); ?></td>
                        <td><?php echo home::Signed($r['res_return']); ?></td>
                        <td><?php echo home::Signed($r['res_roi'], 'pct'); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } else { ?>
        <div class="empty">No counts with order-book prices yet. They start with the first collector run after migration 003.</div>
    <?php } ?>
</section>

<!-- Closing soon -->
<section class="panel mb-4">
    <h2 class="panel-title">Closing soonest</h2>
    <p class="panel-note">Open markets with the nearest end dates and their latest count. Head-count share is the leading side's share of wallets; "ask" is what buying it cost when the count finished. The price favorite uses the order-book midpoint.</p>
    <?php if (home::$ClosingSoon) { ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                <tr>
                    <th>Market</th><th>Ends</th><th class="text-end">Volume</th><th class="text-end">Wallets</th>
                    <th>Most held</th><th>Price favorite</th><th>Last counted</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach (home::$ClosingSoon as $m) {
                    $o = array_column($m['outcome_rows'], null, 'outcome_index');
                    $hp = $m['picks']['headcount'];
                    $pp = $m['picks']['price'];
                    $all = array_sum(array_column($m['outcome_rows'], 'holder_count'));
                    $disagree = $hp !== null && $pp !== null && $hp !== $pp; ?>
                    <tr>
                        <td class="market-cell">
                            <a href="<?php echo home::H(home::MarketURL($m)); ?>" target="_blank" rel="noopener"><?php echo home::H($m['question']); ?></a>
                            <?php if ($disagree) { ?><span class="flag" title="The most-held side isn't the price favorite">crowd ≠ price</span><?php } ?>
                        </td>
                        <td class="text-nowrap"><?php echo home::Time($m['end_date']); ?><div class="small text-body-secondary"><?php echo home::H(home::Ago(strtotime($m['end_date'] . ' UTC'))); ?></div></td>
                        <td class="text-end"><?php echo home::Money($m['volume_num']); ?></td>
                        <td class="text-end"><?php echo $m['snapshot'] ? home::Num($m['snapshot']['unique_holders']) : '–'; ?></td>
                        <td class="text-nowrap">
                            <?php if ($m['snapshot']) { ?>
                                <?php echo home::H(home::OutcomeLabel($m, $hp)); ?>
                                <?php if ($hp !== null && $all > 0) { ?><span class="text-body-secondary small"><?php echo home::Pct($o[$hp]['holder_count'] / $all); ?></span><?php } ?>
                                <?php if ($hp !== null && $o[$hp]['book_ask'] !== null) { ?><div class="small text-body-secondary">ask <?php echo home::Cents($o[$hp]['book_ask']); ?></div><?php } ?>
                            <?php } else { ?>–<?php } ?>
                        </td>
                        <td class="text-nowrap">
                            <?php if ($m['snapshot']) { ?>
                                <?php echo home::H(home::OutcomeLabel($m, $pp)); ?>
                                <?php if ($pp !== null) {
                                    $mid = $o[$pp]['book_bid'] !== null && $o[$pp]['book_ask'] !== null
                                        ? ((float)$o[$pp]['book_bid'] + (float)$o[$pp]['book_ask']) / 2 : $o[$pp]['price']; ?>
                                    <span class="text-body-secondary small"><?php echo home::Cents($mid); ?></span>
                                <?php } ?>
                            <?php } else { ?>–<?php } ?>
                        </td>
                        <td class="text-nowrap small"><?php echo $m['snapshot'] ? home::H(home::Ago(strtotime($m['snapshot']['captured_at'] . ' UTC'))) : 'never'; ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } else { ?>
        <div class="empty">No open markets being tracked.</div>
    <?php } ?>
</section>

<!-- Recently resolved -->
<section class="panel mb-4">
    <h2 class="panel-title">Recently resolved</h2>
    <p class="panel-note">Each market's last complete count before it closed, against how it settled.</p>
    <?php if (home::$Resolved) { ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                <tr>
                    <th>Market</th><th>Closed</th><th>Winner</th><th>Last count</th>
                    <th>Head count</th><th>Shares</th><th>Price</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach (home::$Resolved as $m) {
                    $win = (int)$m['winning_outcome_index'];
                    $has = (bool)$m['snapshot']; ?>
                    <tr>
                        <td class="market-cell"><a href="<?php echo home::H(home::MarketURL($m)); ?>" target="_blank" rel="noopener"><?php echo home::H($m['question']); ?></a></td>
                        <td class="text-nowrap"><?php echo home::Time($m['closed_time'] ?? $m['end_date']); ?></td>
                        <td><strong><?php echo home::H($m['winning_outcome'] ?? home::OutcomeLabel($m, $win)); ?></strong></td>
                        <td class="text-nowrap small">
                            <?php if ($has) {
                                $before = strtotime(($m['closed_time'] ?? $m['end_date']) . ' UTC') - strtotime($m['snapshot']['captured_at'] . ' UTC');
                                echo home::H(home::Duration(max(0, $before))) . ' before close';
                            } else { ?>no complete count<?php } ?>
                        </td>
                        <?php foreach (['headcount', 'shares', 'price'] as $kind) { ?>
                            <td class="text-nowrap">
                                <?php if ($has) {
                                    $p = $m['picks'][$kind];
                                    echo home::H(home::OutcomeLabel($m, $p)) . ' ' . home::Verdict($p === null ? null : $p === $win);
                                } else { ?>–<?php } ?>
                            </td>
                        <?php } ?>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } else { ?>
        <div class="empty">No markets have resolved with a single winner yet.</div>
    <?php } ?>
</section>

<!-- Tags + tables -->
<section class="row g-3 mb-4">
    <div class="col-xl-7">
        <div class="panel h-100">
            <h2 class="panel-title">Top tags</h2>
            <p class="panel-note">A market with several tags counts under each. Accuracy uses resolved markets' complete snapshots.</p>
            <?php if (home::$Tags) { ?>
                <div class="table-responsive">
                    <table class="table table-sm table-numeric mb-0">
                        <thead>
                        <tr><th>Tag</th><th>Markets</th><th>Open</th><th>Resolved</th><th>Snapshots</th><th>Volume</th><th>Head count right</th><th>Price right</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach (home::$Tags as $tag) { ?>
                            <tr>
                                <td><?php echo home::H($tag['tag']); ?></td>
                                <td><?php echo home::Num($tag['markets']); ?></td>
                                <td><?php echo home::Num($tag['open_markets']); ?></td>
                                <td><?php echo home::Num($tag['resolved']); ?></td>
                                <td><?php echo home::Num($tag['snapshots']); ?></td>
                                <td><?php echo home::Money($tag['volume']); ?></td>
                                <td><?php echo home::Pct($tag['headcount_acc']); ?></td>
                                <td><?php echo home::Pct($tag['price_acc']); ?></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } else { ?>
                <div class="empty">No tags yet. On an older database, run <code>sql/migrations/001_market_tag.sql</code>.</div>
            <?php } ?>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="panel h-100">
            <h2 class="panel-title">Database</h2>
            <p class="panel-note">Row counts are InnoDB estimates.</p>
            <div class="table-responsive">
                <table class="table table-sm table-numeric mb-0">
                    <thead><tr><th>Table</th><th>≈ Rows</th><th>Size</th></tr></thead>
                    <tbody>
                    <?php foreach (home::$Tables as $tb) { ?>
                        <tr>
                            <td><code><?php echo home::H($tb['name']); ?></code><?php if ($tb['type'] === 'VIEW') { ?> <span class="text-body-secondary small">view</span><?php } ?></td>
                            <td><?php echo $tb['type'] === 'VIEW' ? '–' : home::Num($tb['approx_rows']); ?></td>
                            <td><?php echo $tb['type'] === 'VIEW' ? '–' : home::Bytes($tb['bytes']); ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<!-- Run log -->
<section class="panel mb-4">
    <h2 class="panel-title">Recent runs</h2>
    <p class="panel-note">From <code>run_log</code>. If "still due" stays high, the collector can't keep up: raise <code>POLY_MIN_VOLUME</code> or lower <code>POLY_MAX_HOLDER_PAGES</code>.</p>
    <?php if (home::$Runs) { ?>
        <div class="table-responsive">
            <table class="table table-sm table-numeric mb-0">
                <thead>
                <tr><th>Run</th><th>Started</th><th>Status</th><th>Took</th><th>Discovered</th><th>Resolved</th><th>Snapshots</th><th>Still due</th><th>Requests</th><th>HTTP errors</th></tr>
                </thead>
                <tbody>
                <?php foreach (array_slice(home::$Runs, 0, 24) as $r) { ?>
                    <tr>
                        <td>#<?php echo (int)$r['id']; ?></td>
                        <td><?php echo home::Time($r['started_at']); ?></td>
                        <td><?php echo home::StatusBadge($r['status']); ?><?php if ($r['notes']) { ?> <span class="text-body-secondary small" title="<?php echo home::H($r['notes']); ?>">ⓘ</span><?php } ?></td>
                        <td><?php echo home::Duration($r['seconds']); ?></td>
                        <td><?php echo home::Num($r['discovered']); ?></td>
                        <td><?php echo home::Num($r['resolved']); ?></td>
                        <td><?php echo home::Num($r['snapshots']); ?></td>
                        <td><?php echo home::Num($r['due_remaining']); ?></td>
                        <td><?php echo home::Num($r['requests']); ?></td>
                        <td><?php echo (int)$r['http_errors'] ? '<strong>' . home::Num($r['http_errors']) . '</strong>' : '0'; ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } else { ?>
        <div class="empty">The collector hasn't run yet.</div>
    <?php } ?>
</section>

<script type="application/json" id="dashboard-data"><?php echo json_encode($chart_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES); ?></script>
<script><?php readfile(__DIR__ . '/home.js'); ?></script>

<?php } ?>
