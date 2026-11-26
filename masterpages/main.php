<?php
declare(strict_types=1);

/* Master page. Web::Exec() requires this from inside the Web object, so
 * $this is the QuickDRY\Web\Web instance and $this->HTML is the page's view. */

/** @var QuickDRY\Web\Web $this */
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($this->MetaTitle ?? 'poly', ENT_QUOTES, 'UTF-8'); ?></title>
    <script>
        // Follow the OS light/dark setting (Bootstrap 5.3 color modes).
        (function () {
            var mq = window.matchMedia('(prefers-color-scheme: dark)');
            function apply() { document.documentElement.setAttribute('data-bs-theme', mq.matches ? 'dark' : 'light'); }
            apply();
            if (mq.addEventListener) mq.addEventListener('change', apply);
        })();
    </script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js" crossorigin="anonymous"></script>
    <style>
        :root, [data-bs-theme="light"] {
            --viz-surface: #ffffff;
            --viz-text: #191919;
            --viz-muted: #666666;
            --viz-grid: #e6e6e6;
            --viz-tooltip-bg: #191919;
            --viz-tooltip-text: #ffffff;
            --series-1: #257de0;  /* blue: head count / single series */
            --series-2: #f47230;  /* orange: shares */
            --series-3: #00b894;  /* aqua: price */
            --status-good: #0aac23;
            --status-good-ink: #006c10;
            --status-warning: #fbb315;
            --status-warning-ink: #8a5d00;
            --status-serious: #f18a59;
            --status-serious-ink: #a8461a;
            --status-critical: #dc3c3d;
            --status-critical-ink: #b42526;
            --status-info-ink: #1b64be;
            --page-bg: #f6f7f9;
        }
        [data-bs-theme="dark"] {
            --viz-surface: #191919;
            --viz-text: #efefef;
            --viz-muted: #9c9c9c;
            --viz-grid: #2e2e2e;
            --viz-tooltip-bg: #efefef;
            --viz-tooltip-text: #191919;
            --series-1: #338ced;
            --series-2: #e76624;
            --series-3: #00a888;
            --status-good: #0aac23;
            --status-good-ink: #3fd157;
            --status-warning: #fbb315;
            --status-warning-ink: #fbb315;
            --status-serious: #f18a59;
            --status-serious-ink: #f18a59;
            --status-critical: #dc3c3d;
            --status-critical-ink: #f07071;
            --status-info-ink: #6aabf1;
            --page-bg: #111111;
        }
        body { background: var(--page-bg); font-variant-numeric: tabular-nums; }
        .tile, .panel {
            background: var(--viz-surface);
            border: 1px solid var(--bs-border-color-translucent);
            border-radius: .75rem;
        }
        .tile { padding: .9rem 1rem; height: 100%; }
        .tile-label { font-size: .78rem; color: var(--viz-muted); text-transform: uppercase; letter-spacing: .03em; }
        .tile-value { font-size: 1.75rem; font-weight: 600; line-height: 1.2; margin: .2rem 0; color: var(--viz-text); }
        .tile-value-sm { font-size: 1.15rem; padding: .35rem 0; }
        .tile-sub { font-size: .8rem; color: var(--viz-muted); }
        .panel { padding: 1.1rem 1.25rem; }
        .panel-title { font-size: 1.05rem; font-weight: 600; margin-bottom: .2rem; }
        .panel-note { font-size: .82rem; color: var(--viz-muted); margin-bottom: .9rem; }
        .chart-box { position: relative; height: 300px; }
        .chart-box-sm { height: 260px; }
        .empty { color: var(--viz-muted); font-size: .9rem; padding: 1.5rem 0; text-align: center; }
        .table { --bs-table-bg: transparent; font-size: .875rem; }
        .table thead th { font-weight: 600; color: var(--viz-muted); font-size: .78rem; white-space: nowrap; }
        .table-numeric th:not(:first-child), .table-numeric td:not(:first-child) { text-align: right; }
        .market-cell { min-width: 18rem; max-width: 34rem; }
        .market-cell a { color: var(--viz-text); text-decoration: none; }
        .market-cell a:hover { text-decoration: underline; }
        .status { font-weight: 600; white-space: nowrap; }
        .status-good { color: var(--status-good-ink); }
        .status-warning { color: var(--status-warning-ink); }
        .status-serious { color: var(--status-serious-ink); }
        .status-critical { color: var(--status-critical-ink); }
        .status-info { color: var(--status-info-ink); }
        .flag {
            display: inline-block; margin-left: .4rem; padding: 0 .4rem; font-size: .72rem; border-radius: .3rem;
            border: 1px solid var(--bs-border-color); color: var(--viz-muted); white-space: nowrap;
        }
        .stat-list { margin: 0; }
        .stat-list > div { display: flex; justify-content: space-between; gap: 1rem; padding: .3rem 0; border-bottom: 1px dashed var(--bs-border-color-translucent); }
        .stat-list > div:last-child { border-bottom: 0; }
        .stat-list dt { font-weight: normal; color: var(--viz-muted); }
        .stat-list dd { margin: 0; font-weight: 600; text-align: right; }
    </style>
</head>
<body>
<main class="container-xxl py-4">
    <?php echo $this->HTML; ?>
</main>
</body>
</html>
