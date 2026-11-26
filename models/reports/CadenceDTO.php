<?php
declare(strict_types=1);

namespace models\reports;

use QuickDRY\Connectors\mysql\MySQL_A;
use QuickDRY\Utilities\APIHelper;
use QuickDRY\Utilities\strongType;

/**
 * Open markets in one schedule band (sql/cadence.sql), with how often the
 * collector counts markets in it (Collector::Interval()).
 */
class CadenceDTO extends strongType
{
    /** Band => how often it's counted, in display order. */
    public const array BANDS = [
        'last day / past end' => 'every hour',
        '1-2 days'            => 'every 2 hours',
        '2-7 days'            => 'every 4 hours',
        '7-30 days'           => 'every 12 hours',
        '30+ days'            => 'every 24 hours',
        'no end date'         => 'every 24 hours',
        '7+ days past end'    => 'every 24 hours',
    ];

    public ?string $band = null; // "2-7 days"
    public ?int $markets = null; // 28
    public ?string $every = null; // "every 4 hours"

    /**
     * Every band in display order, including empty ones.
     *
     * @return self[]
     */
    public static function getReport(): array
    {
        $counts = [];
        foreach (MySQL_A::QueryMap(APIHelper::getSQL(__DIR__ . '/sql/cadence.sql'), null, fn($row) => $row) as $row) {
            $counts[$row['band']] = (int)$row['markets'];
        }

        $out = [];
        foreach (self::BANDS as $band => $every) {
            $out[] = new self(['band' => $band, 'markets' => $counts[$band] ?? 0, 'every' => $every]);
        }
        return $out;
    }
}
