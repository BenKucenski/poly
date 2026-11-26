<?php
declare(strict_types=1);

namespace pages\home;

use common\Poly\Dashboard;
use QuickDRY\Web\BasePage;
use Throwable;

/**
 * Homepage dashboard: what the collector has gathered so far.
 */
class home extends BasePage
{
    public static array $Totals = [];
    public static array $Runs = [];
    public static array $Cadence = [];
    public static array $Accuracy = [];
    public static array $CrowdVsPrice = [];
    public static array $Tags = [];
    public static array $ClosingSoon = [];
    public static array $Resolved = [];
    public static array $Tables = [];
    public static ?string $DBError = null;

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
            self::$Totals = Dashboard::Totals();
            self::$Runs = Dashboard::Runs(72);
            self::$Cadence = Dashboard::Cadence();
            self::$Accuracy = Dashboard::AccuracyByDaysOut();
            self::$CrowdVsPrice = Dashboard::CrowdVsPrice();
            self::$Tags = Dashboard::Tags(20);
            self::$ClosingSoon = Dashboard::ClosingSoon(12);
            self::$Resolved = Dashboard::RecentlyResolved(12);
            self::$Tables = Dashboard::TableSizes();
        } catch (Throwable $e) {
            self::$DBError = $e->getMessage();
        }
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

    /**
     * @param array $market a market row (needs outcomes)
     * @param int|null $index
     * @return string label
     */
    public static function OutcomeLabel(array $market, ?int $index): string
    {
        if ($index === null) {
            return 'tie';
        }
        $labels = json_decode((string)($market['outcomes'] ?? ''), true);
        return is_array($labels) && isset($labels[$index]) ? (string)$labels[$index] : '#' . $index;
    }

    /**
     * @param array $market
     * @return string Polymarket URL
     */
    public static function MarketURL(array $market): string
    {
        if (!empty($market['event_slug'])) {
            return 'https://polymarket.com/event/' . rawurlencode($market['event_slug']);
        }
        return 'https://polymarket.com/market/' . rawurlencode((string)($market['slug'] ?? ''));
    }
}
