<?php
declare(strict_types=1);

namespace pages\home;

use common\Poly\Dashboard;
use models\reports\AccuracyByDaysOutDTO;
use models\reports\CadenceDTO;
use models\reports\CrowdVsPriceDTO;
use models\reports\DashboardCacheDTO;
use models\reports\DashboardTotalsDTO;
use models\reports\ForwardReturnDTO;
use models\reports\LatestCountDTO;
use models\reports\RunLogDTO;
use models\reports\TableSizeDTO;
use models\reports\TagDTO;
use QuickDRY\Web\BasePage;
use Throwable;

/**
 * Homepage dashboard: what the collector has gathered so far.
 *
 * Reads the report objects tasks/dashboard.php stored in dashboard_cache
 * (see common\Poly\Dashboard) and turns them back into the same strongType
 * DTOs, so a page view runs no heavy queries.
 */
class home extends BasePage
{
    public static ?DashboardTotalsDTO $Totals = null;
    /** @var RunLogDTO[] newest first */
    public static array $Runs = [];
    /** @var CadenceDTO[] */
    public static array $Cadence = [];
    /** @var AccuracyByDaysOutDTO[] */
    public static array $Accuracy = [];
    public static ?CrowdVsPriceDTO $CrowdVsPrice = null;
    /** @var array<string, ForwardReturnDTO> keyed 'disagree', 'agree', 'all' */
    public static array $Forward = [];
    /** @var TagDTO[] */
    public static array $Tags = [];
    /** @var LatestCountDTO[] */
    public static array $ClosingSoon = [];
    /** @var LatestCountDTO[] */
    public static array $Resolved = [];
    /** @var TableSizeDTO[] */
    public static array $Tables = [];
    public static ?string $DBError = null;

    /** Whether tasks/dashboard.php has stored anything yet. */
    public static bool $HasCache = false;
    /** The oldest section's build time (UTC), i.e. how fresh the page is at worst. */
    public static ?string $BuiltAt = null;
    /** Total query time of the stored build, in milliseconds. */
    public static int $BuildMs = 0;
    /** Sections whose last rebuild failed (name => error); they show their previous data. */
    public static array $StaleSections = [];

    /**
     * @return void
     */
    public static function Init(): void
    {
        parent::Init();
        self::setMasterPage('main.php');
        self::setMetaTitle('poly · dashboard');
    }

    /**
     * @return void
     */
    public static function Get(): void
    {
        if (self::$DBError = Dashboard::ConnectionError()) {
            return;
        }
        try {
            $cache = DashboardCacheDTO::getReport();
        } catch (Throwable $e) {
            self::$DBError = $e->getMessage();
            return;
        }

        foreach ($cache as $name => $section) {
            if (!isset(Dashboard::Sections()[$name])) {
                continue; // a section this version no longer builds
            }
            if ($section->error) {
                self::$StaleSections[$name] = $section->error;
            }
            if ($section->built_at && (!self::$BuiltAt || $section->built_at < self::$BuiltAt)) {
                self::$BuiltAt = $section->built_at;
            }
            self::$BuildMs += (int)$section->build_ms;

            $data = $section->Data();
            if (!is_array($data)) {
                continue;
            }
            $list = static fn(string $class) => array_map(static fn($row) => new $class($row), $data);
            match ($name) {
                'totals'       => self::$Totals = new DashboardTotalsDTO($data),
                'runs'         => self::$Runs = $list(RunLogDTO::class),
                'cadence'      => self::$Cadence = $list(CadenceDTO::class),
                'accuracy'     => self::$Accuracy = $list(AccuracyByDaysOutDTO::class),
                'crowd'        => self::$CrowdVsPrice = new CrowdVsPriceDTO($data),
                'forward'      => self::$Forward = $list(ForwardReturnDTO::class),
                'tags'         => self::$Tags = $list(TagDTO::class),
                'closing_soon' => self::$ClosingSoon = array_map(static fn($row) => LatestCountDTO::fromCache($row), $data),
                'resolved'     => self::$Resolved = array_map(static fn($row) => LatestCountDTO::fromCache($row), $data),
                'tables'       => self::$Tables = $list(TableSizeDTO::class),
            };
        }
        self::$HasCache = self::$BuiltAt !== null;
    }

    // ------------------------------------------------------------ view helpers

    /**
     * @param mixed $v
     * @return string HTML-escaped
     */
    public static function H(mixed $v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param mixed $v
     * @param int $decimals
     * @return string
     */
    public static function Num(mixed $v, int $decimals = 0): string
    {
        return $v === null || $v === '' ? '–' : number_format((float)$v, $decimals);
    }

    /**
     * @param mixed $v 0..1
     * @return string
     */
    public static function Pct(mixed $v): string
    {
        return $v === null || $v === '' ? '–' : number_format((float)$v * 100, 1) . '%';
    }

    /**
     * @param mixed $v price 0..1
     * @return string e.g. 42¢
     */
    public static function Cents(mixed $v): string
    {
        return $v === null || $v === '' ? '–' : number_format((float)$v * 100, 1) . '¢';
    }

    /**
     * A signed per-share amount or rate, with an arrow so the sign never
     * rides on color alone.
     *
     * @param mixed $v
     * @param string $unit 'cents' (price units shown as ¢) or 'pct'
     * @return string HTML
     */
    public static function Signed(mixed $v, string $unit = 'cents'): string
    {
        if ($v === null || $v === '') {
            return '<span class="text-body-secondary">–</span>';
        }
        $x = (float)$v * 100;
        $text = ($x > 0 ? '+' : ($x < 0 ? '−' : '')) . number_format(abs($x), 1) . ($unit === 'pct' ? '%' : '¢');
        if (abs($x) < 0.05) {
            return '<span>' . $text . '</span>';
        }
        return '<span class="status ' . ($x > 0 ? 'status-good' : 'status-critical') . '"><span aria-hidden="true">'
            . ($x > 0 ? '▲' : '▼') . '</span> ' . $text . '</span>';
    }

    /**
     * @param mixed $v
     * @return string compact dollars, e.g. $1.2M
     */
    public static function Money(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '–';
        }
        $v = (float)$v;
        foreach ([[1e9, 'B'], [1e6, 'M'], [1e3, 'K']] as [$div, $suffix]) {
            if (abs($v) >= $div) {
                return '$' . number_format($v / $div, 1) . $suffix;
            }
        }
        return '$' . number_format($v);
    }

    /**
     * @param mixed $bytes
     * @return string
     */
    public static function Bytes(mixed $bytes): string
    {
        $b = (float)$bytes;
        foreach (['GB' => 1 << 30, 'MB' => 1 << 20, 'KB' => 1 << 10] as $unit => $size) {
            if ($b >= $size) {
                return number_format($b / $size, 1) . ' ' . $unit;
            }
        }
        return number_format($b) . ' B';
    }

    /**
     * A UTC DATETIME as a <time> element; the page's script shows it in the
     * viewer's own time zone.
     *
     * @param string|null $utc
     * @param bool $relative also show "3 h ago"
     * @return string HTML
     */
    public static function Time(?string $utc, bool $relative = false): string
    {
        if (!$utc) {
            return '<span class="text-body-secondary">–</span>';
        }
        $ts = strtotime($utc . ' UTC');
        $iso = gmdate('Y-m-d\TH:i:s\Z', $ts);
        $html = '<time class="ts" datetime="' . $iso . '" title="' . self::H($utc) . ' UTC">' . self::H(substr($utc, 0, 16)) . ' UTC</time>';
        if ($relative) {
            $html .= ' <span class="text-body-secondary small">' . self::H(self::Ago($ts)) . '</span>';
        }
        return $html;
    }

    /**
     * @param int $ts
     * @return string "5 min ago" / "in 3 h"
     */
    public static function Ago(int $ts): string
    {
        $d = time() - $ts;
        $future = $d < 0;
        $d = abs($d);
        $text = match (true) {
            $d < 90        => $d . ' s',
            $d < 5400      => round($d / 60) . ' min',
            $d < 129600    => round($d / 3600) . ' h',
            default        => round($d / 86400) . ' d',
        };
        return $future ? 'in ' . $text : $text . ' ago';
    }

    /**
     * @param mixed $seconds
     * @return string "12m 40s"
     */
    public static function Duration(mixed $seconds): string
    {
        if ($seconds === null) {
            return '–';
        }
        $s = (int)$seconds;
        return $s >= 3600
            ? intdiv($s, 3600) . 'h ' . intdiv($s % 3600, 60) . 'm'
            : intdiv($s, 60) . 'm ' . ($s % 60) . 's';
    }

    /**
     * A run_log status as an icon + label badge (never color alone).
     *
     * @param string|null $status
     * @return string HTML
     */
    public static function StatusBadge(?string $status): string
    {
        [$class, $icon, $label] = match ($status) {
            'done'        => ['status-good', '✓', 'done'],
            'running'     => ['status-info', '●', 'running'],
            'out_of_time' => ['status-warning', '⏱', 'out of time'],
            'killed'      => ['status-serious', '■', 'killed'],
            'error'       => ['status-critical', '✕', 'error'],
            default       => ['status-info', '?', (string)$status],
        };
        return '<span class="status ' . $class . '"><span aria-hidden="true">' . $icon . '</span> ' . self::H($label) . '</span>';
    }

    /**
     * @param bool|null $correct
     * @return string HTML
     */
    public static function Verdict(?bool $correct): string
    {
        if ($correct === null) {
            return '<span class="text-body-secondary" title="tie, no pick">tie</span>';
        }
        return $correct
            ? '<span class="status status-good"><span aria-hidden="true">✓</span> right</span>'
            : '<span class="status status-critical"><span aria-hidden="true">✕</span> wrong</span>';
    }


}
