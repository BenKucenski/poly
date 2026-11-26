<?php
declare(strict_types=1);

namespace models\reports;

use QuickDRY\Connectors\mysql\MySQL_A;
use QuickDRY\Utilities\APIHelper;
use QuickDRY\Utilities\strongType;

/**
 * Returns from buying the crowd's pick at the ask when each count finished
 * (sql/forward_returns.sql): one row each for agree / disagree, and a total.
 */
class ForwardReturnDTO extends strongType
{
    public const array LABELS = [
        'disagree' => 'Crowd ≠ price favorite',
        'agree'    => 'Crowd = price favorite',
        'all'      => 'Every head-count pick',
    ];

    public ?int $disagree = null; // 1, 0, or null for the total row
    public ?int $entries = null; // 429
    public ?int $markets = null; // 125
    public ?float $entry_ask = null; // 0.4336
    public ?int $next_n = null; // 384
    public ?float $next_hours = null; // 10.54
    public ?float $next_return = null; // -0.0245 (per share)
    public ?float $next_roi = null; // -0.0572
    public ?float $next_up_share = null; // 0.2266
    public ?int $res_n = null; // 36
    public ?float $res_return = null; // -0.03 (per share)
    public ?float $res_roi = null; // -0.1208

    /**
     * @return array<string, self> keyed 'disagree', 'agree', 'all'
     */
    public static function getReport(): array
    {
        $out = [];
        foreach (MySQL_A::QueryMap(APIHelper::getSQL(__DIR__ . '/sql/forward_returns.sql'), null, fn($row) => new self($row)) as $r) {
            $out[$r->Key()] = $r;
        }
        return $out;
    }

    public function Key(): string
    {
        return $this->disagree === null ? 'all' : ($this->disagree ? 'disagree' : 'agree');
    }
}
