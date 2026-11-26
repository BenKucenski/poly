/* Homepage dashboard charts (Chart.js 4). Data comes from #dashboard-data. */
(function () {
    'use strict';

    const data = JSON.parse(document.getElementById('dashboard-data').textContent);
    const charts = [];

    // Show <time> elements in the viewer's time zone.
    const fmt = new Intl.DateTimeFormat(undefined, {month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'});
    document.querySelectorAll('time.ts').forEach(function (el) {
        const d = new Date(el.getAttribute('datetime'));
        if (!isNaN(d)) {
            el.textContent = fmt.format(d);
        }
    });

    if (typeof Chart === 'undefined') {
        return; // CDN blocked: the tables still carry the numbers
    }

    function css(name) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    }

    const pct = (v) => v === null || v === undefined ? '–' : (v * 100).toFixed(1) + '%';
    const runTime = new Intl.DateTimeFormat(undefined, {weekday: 'short', hour: 'numeric'});

    // Writes each series' name at its last point (direct labels, <= 4 series).
    const endLabels = {
        id: 'endLabels',
        afterDatasetsDraw(chart, args, opts) {
            if (!opts || !opts.enabled) return;
            const ctx = chart.ctx;
            ctx.save();
            ctx.font = '12px ' + Chart.defaults.font.family;
            ctx.fillStyle = css('--viz-text');
            ctx.textBaseline = 'middle';
            // The rightmost drawn point of each series (the x axis may be reversed).
            const ends = [];
            chart.data.datasets.forEach(function (ds, i) {
                const meta = chart.getDatasetMeta(i);
                if (meta.hidden) return;
                let end = null;
                meta.data.forEach(function (pt, j) {
                    if (ds.data[j] === null || ds.data[j] === undefined) return;
                    if (!end || pt.x > end.x) end = pt;
                });
                if (end) ends.push({label: ds.label, x: end.x, y: end.y});
            });
            // Top to bottom, nudged apart so labels never overlap.
            ends.sort((a, b) => a.y - b.y);
            let prev = -Infinity;
            ends.forEach(function (e) {
                const y = Math.max(e.y, prev + 14);
                prev = y;
                ctx.fillText(e.label, e.x + 10, y);
            });
            ctx.restore();
        }
    };

    function build() {
        charts.splice(0).forEach((c) => c.destroy());

        const text = css('--viz-text');
        const muted = css('--viz-muted');
        const grid = css('--viz-grid');
        Chart.defaults.color = muted;
        Chart.defaults.borderColor = grid;
        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        Chart.defaults.plugins.tooltip.backgroundColor = css('--viz-tooltip-bg');
        Chart.defaults.plugins.tooltip.titleColor = css('--viz-tooltip-text');
        Chart.defaults.plugins.tooltip.bodyColor = css('--viz-tooltip-text');
        Chart.defaults.plugins.tooltip.padding = 10;
        Chart.defaults.plugins.legend.labels.color = text;

        const s1 = css('--series-1'), s2 = css('--series-2'), s3 = css('--series-3');

        // ---- Snapshots per run
        const runs = data.runs;
        const runsEl = document.getElementById('chart-runs');
        if (runsEl && runs.length) {
            const max = Math.max(1, ...runs.map((r) => r.snapshots));
            const marker = (status) => runs.map((r) => r.status === status ? r.snapshots + max * 0.06 : null);
            charts.push(new Chart(runsEl, {
                data: {
                    labels: runs.map((r) => '#' + r.id),
                    datasets: [
                        {
                            type: 'bar', label: 'Snapshots', data: runs.map((r) => r.snapshots),
                            backgroundColor: s1, borderRadius: {topLeft: 4, topRight: 4}, borderSkipped: 'bottom',
                            barPercentage: 0.85, categoryPercentage: 1, order: 2
                        },
                        {
                            type: 'line', label: 'Ended in error', data: marker('error'), showLine: false,
                            pointStyle: 'crossRot', pointRadius: 6, pointHoverRadius: 7, borderWidth: 2.5,
                            borderColor: css('--status-critical'), backgroundColor: css('--status-critical'), order: 1
                        },
                        {
                            type: 'line', label: 'Out of time', data: marker('out_of_time'), showLine: false,
                            pointStyle: 'triangle', pointRadius: 5, pointHoverRadius: 6, borderWidth: 2,
                            borderColor: css('--status-warning-ink'), backgroundColor: css('--status-warning'), order: 1
                        }
                    ]
                },
                options: {
                    maintainAspectRatio: false, animation: false,
                    interaction: {mode: 'index', intersect: false},
                    plugins: {
                        legend: {position: 'top', align: 'end', labels: {usePointStyle: true, boxWidth: 8, boxHeight: 8}},
                        tooltip: {
                            filter: (item) => item.datasetIndex === 0,
                            callbacks: {
                                title: (items) => {
                                    const r = runs[items[0].dataIndex];
                                    return 'Run #' + r.id + ' · ' + runTime.format(new Date(r.started));
                                },
                                label: (item) => {
                                    const r = runs[item.dataIndex];
                                    return [
                                        r.snapshots + ' snapshots',
                                        'status: ' + r.status.replace(/_/g, ' '),
                                        'took ' + Math.round(r.seconds / 60) + ' min',
                                        'still due: ' + (r.due === null ? '–' : r.due),
                                        'HTTP errors: ' + r.errors
                                    ];
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {display: false},
                            ticks: {
                                autoSkip: true, maxRotation: 0, maxTicksLimit: 8,
                                callback: (v, i) => runTime.format(new Date(runs[i].started))
                            }
                        },
                        y: {beginAtZero: true, border: {display: false}, ticks: {precision: 0}, title: {display: true, text: 'snapshots'}}
                    }
                }
            }));
        }

        // ---- Open markets by schedule band
        const cadEl = document.getElementById('chart-cadence');
        if (cadEl) {
            const cad = data.cadence;
            charts.push(new Chart(cadEl, {
                type: 'bar',
                data: {
                    labels: cad.map((c) => c.band),
                    datasets: [{
                        label: 'Open markets', data: cad.map((c) => c.markets), backgroundColor: s1,
                        borderRadius: {topRight: 4, bottomRight: 4}, borderSkipped: 'left', barPercentage: 0.7
                    }]
                },
                options: {
                    indexAxis: 'y', maintainAspectRatio: false, animation: false,
                    plugins: {
                        legend: {display: false},
                        tooltip: {callbacks: {label: (item) => item.raw + ' markets · counted ' + cad[item.dataIndex].every}}
                    },
                    scales: {
                        x: {beginAtZero: true, border: {display: false}, ticks: {precision: 0}},
                        y: {grid: {display: false}, ticks: {color: text}}
                    }
                }
            }));
        }

        // ---- Accuracy by days out
        const accEl = document.getElementById('chart-accuracy');
        if (accEl && data.accuracy.length) {
            const acc = data.accuracy;
            const line = (label, key, color) => ({
                label: label, data: acc.map((a) => a[key]), borderColor: color, backgroundColor: color,
                borderWidth: 2, pointRadius: 4, pointHoverRadius: 6, pointBorderColor: css('--viz-surface'),
                pointBorderWidth: 2, tension: 0, spanGaps: true
            });
            charts.push(new Chart(accEl, {
                type: 'line',
                data: {
                    labels: acc.map((a) => a.days === data.maxDaysOut ? a.days + '+' : String(a.days)),
                    datasets: [
                        line('Head count', 'headcount', s1),
                        line('Shares', 'shares', s2),
                        line('Price', 'price', s3)
                    ]
                },
                plugins: [endLabels],
                options: {
                    maintainAspectRatio: false, animation: false,
                    layout: {padding: {right: 78}},
                    interaction: {mode: 'index', intersect: false},
                    plugins: {
                        endLabels: {enabled: true},
                        legend: {position: 'top', align: 'end', labels: {usePointStyle: true, boxWidth: 8, boxHeight: 8}},
                        tooltip: {
                            callbacks: {
                                title: (items) => {
                                    const a = acc[items[0].dataIndex];
                                    return a.days + (a.days === data.maxDaysOut ? '+' : '') + ' days before close · ' + a.markets + ' markets, ' + a.snapshots + ' snapshots';
                                },
                                label: (item) => ' ' + item.dataset.label + ': ' + pct(item.raw)
                            }
                        }
                    },
                    scales: {
                        x: {reverse: true, grid: {display: false}, title: {display: true, text: 'days before close (close is on the right)'}},
                        y: {
                            min: 0, max: 1, border: {display: false},
                            ticks: {callback: (v) => Math.round(v * 100) + '%', stepSize: 0.25},
                            title: {display: true, text: 'picked the winner'}
                        }
                    }
                }
            }));
        }
    }

    build();
    const mq = window.matchMedia('(prefers-color-scheme: dark)');
    if (mq.addEventListener) {
        mq.addEventListener('change', function () { setTimeout(build, 0); });
    }
})();
