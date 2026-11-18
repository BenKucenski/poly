<?php
declare(strict_types=1);

namespace common\Poly;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * Read-only client for Polymarket's public APIs (no key needed).
 *
 * Uses Guzzle directly rather than QuickDRY\Connectors\Curl so every request
 * has a timeout: a request that hangs would otherwise hold the run until the
 * scheduler kills it.
 */
class PolyAPI
{
    public const string GAMMA = 'https://gamma-api.polymarket.com';
    public const string DATA = 'https://data-api.polymarket.com';

    public int $requests = 0;
    public int $errors = 0;

    private ?Client $client = null;

    /**
     * @param int $delay_ms pause before each request
     */
    public function __construct(private readonly int $delay_ms = 120)
    {
    }

    /**
     * One page of markets from Gamma's keyset endpoint.
     *
     * @param array $params query parameters; array values are sent as repeated keys
     * @return array{markets: array, next_cursor: ?string}
     */
    public function MarketsPage(array $params): array
    {
        $res = $this->GetJSON(self::GAMMA . '/markets/keyset', $params);

        return [
            'markets'     => $res['markets'] ?? [],
            'next_cursor' => $res['next_cursor'] ?? null,
        ];
    }

    /**
     * Markets by condition id (up to 100 per call).
     *
     * @param string[] $condition_ids
     * @param bool $closed Gamma only returns closed markets when true, open ones when false
     * @return array
     */
    public function MarketsByCondition(array $condition_ids, bool $closed): array
    {
        if (!$condition_ids) {
            return [];
        }

        $page = $this->MarketsPage([
            'condition_ids' => array_values($condition_ids),
            'closed'        => $closed ? 'true' : 'false',
            'include_tag'   => 'true',
            'limit'         => 100,
        ]);

        return $page['markets'];
    }

    /**
     * One page of a market's holders, grouped by outcome token.
     *
     * @param string $condition_id
     * @param string|null $cursor
     * @return array{data: array, has_more: bool, next_cursor: ?string}
     */
    public function HoldersPage(string $condition_id, ?string $cursor = null): array
    {
        $params = ['condition' => $condition_id];
        if ($cursor) {
            $params['cursor'] = $cursor;
        } else {
            $params['limit'] = 1000;
        }

        $res = $this->GetJSON(self::DATA . '/v2/holders', $params);

        return [
            'data'        => $res['data'] ?? [],
            'has_more'    => (bool)($res['pagination']['has_more'] ?? false),
            'next_cursor' => $res['pagination']['next_cursor'] ?? null,
        ];
    }

    /**
     * GET with retries on 429 / 5xx / network errors.
     *
     * @param string $url
     * @param array $params
     * @return array
     */
    protected function GetJSON(string $url, array $params): array
    {
        $full = $url . '?' . self::BuildQuery($params);

        $attempts = 0;
        while (true) {
            $attempts++;
            if ($this->delay_ms > 0) {
                usleep($this->delay_ms * 1000);
            }
            $this->requests++;

            try {
                $response = $this->Client()->get($full, ['http_errors' => false]);
            } catch (GuzzleException $e) {
                $this->errors++;
                if ($attempts >= 3) {
                    throw new RuntimeException('Request failed: ' . $full . ' ' . $e->getMessage());
                }
                sleep(5 * $attempts);
                continue;
            }

            $status = $response->getStatusCode();
            $body = (string)$response->getBody();

            if ($status === 200) {
                $json = json_decode($body, true);
                if (!is_array($json)) {
                    throw new RuntimeException('Response was not JSON: ' . $full . ' ' . substr($body, 0, 300));
                }
                return $json;
            }

            $this->errors++;
            $retryable = $status === 429 || $status >= 500;
            if (!$retryable || $attempts >= 4) {
                throw new RuntimeException('HTTP ' . $status . ': ' . $full . ' ' . substr($body, 0, 300));
            }

            $wait = (int)($response->getHeaderLine('Retry-After') ?: 0);
            sleep(max(2, min(60, $wait ?: 5 * $attempts)));
        }
    }

    /**
     * http_build_query, but arrays become repeated keys (a=1&a=2), which is what Gamma expects.
     *
     * @param array $params
     * @return string
     */
    public static function BuildQuery(array $params): string
    {
        $parts = [];
        foreach ($params as $key => $value) {
            if (is_null($value)) {
                continue;
            }
            foreach ((array)$value as $v) {
                $parts[] = rawurlencode((string)$key) . '=' . rawurlencode((string)$v);
            }
        }
        return implode('&', $parts);
    }

    /**
     * @return Client
     */
    private function Client(): Client
    {
        if (!$this->client) {
            $this->client = new Client([
                'timeout'         => 30,
                'connect_timeout' => 10,
                'headers'         => [
                    'Accept'     => 'application/json',
                    'User-Agent' => 'poly-headcount-collector/1.0',
                ],
            ]);
        }
        return $this->client;
    }
}
