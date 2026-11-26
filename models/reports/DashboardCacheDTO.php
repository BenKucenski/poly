<?php
declare(strict_types=1);

namespace models\reports;

use QuickDRY\Connectors\mysql\MySQL_A;
use QuickDRY\Utilities\APIHelper;
use QuickDRY\Utilities\strongType;

/**
 * One stored dashboard section (sql/dashboard_cache.sql). The homepage reads
 * only these; common\Poly\Dashboard::Build() writes them.
 */
class DashboardCacheDTO extends strongType
{
    public ?string $name = null; // "accuracy"
    public ?string $data = null; // JSON
    public ?string $built_at = null; // "2026-11-26 15:26:37" (UTC), null if never built
    public ?int $build_ms = null; // 14
    public ?string $error = null; // last rebuild's error, null if it worked
    public ?string $tried_at = null; // last rebuild attempt (UTC)

    /**
     * @return array<string, self> keyed by section name
     */
    public static function getReport(): array
    {
        $out = [];
        foreach (MySQL_A::QueryMap(APIHelper::getSQL(__DIR__ . '/sql/dashboard_cache.sql'), null, fn($row) => new self($row)) as $r) {
            $out[$r->name] = $r;
        }
        return $out;
    }

    /** The decoded JSON, or null if there's none. */
    public function Data(): mixed
    {
        return $this->data === null ? null : json_decode($this->data, true);
    }
}
