<?php
declare(strict_types=1);

namespace common\Poly;

use GuzzleHttp\Exception\GuzzleException;
use QuickDRY\Connectors\Curl;
use RuntimeException;

/**
 * Read-only client for Polymarket's public APIs (no key needed), over
 * QuickDRY's Curl with a timeout, so one hung request can't hold the run
 * until the scheduler kills it.
 */
class PolyAPI
{
    public const string GAMMA = 'https://gamma-api.polymarket.com';
    public const string DATA = 'https://data-api.polymarket.com';
    public const string CLOB = 'https://clob.polymarket.com';

    public int $requests = 0;
    public int $errors = 0;

    private const array HEADERS = [
        'Accept'     => 'application/json',
        'User-Agent' => 'poly-headcount-collector/1.0',
    ];

    /**
     * @param int $delay_ms pause before each request
     */
    public function __construct(private readonly int $delay_ms = 120)
    {
        Curl::$timeout = 30;
        Curl::$connect_timeout = 10;
        Curl::$throw_exceptions = true; // a failed request is retried, not fatal
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
     * The best bid and ask for each token right now, from the CLOB's public
     * order books (one request for all tokens).
     *
     * A token with no order book (a resolved or delisted outcome) comes back
     * with nulls. If the batch request is refused, each token is read on its
     * own so one bad token can't hide the others.
     *
     * @param string[] $token_ids
     * @return array<string, array{bid: ?float, ask: ?float, bid_size: ?float, ask_size: ?float}> keyed by token id
     */
    public function Books(array $token_ids): array
    {
        $token_ids = array_values(array_unique(array_filter(array_map('strval', $token_ids))));
        if (!$token_ids) {
            return [];
        }

        $out = array_fill_keys($token_ids, self::BestLevels([]));

        try {
            $books = $this->PostJSON(self::CLOB . '/books', array_map(static fn($t) => ['token_id' => $t], $token_ids));
        } catch (RuntimeException $e) {
            if (!str_starts_with($e->getMessage(), 'HTTP 4')) {
                throw $e;
            }
            $books = [];
            foreach ($token_ids as $t) {
                try {
                    $books[] = $this->GetJSON(self::CLOB . '/book', ['token_id' => $t]);
                } catch (RuntimeException $e2) {
                    if (!str_starts_with($e2->getMessage(), 'HTTP 4')) {
                        throw $e2;
                    }
                    // no order book for this token: leave its nulls
                }
            }
        }

        foreach ($books as $book) {
            $id = (string)($book['asset_id'] ?? '');
            if (isset($out[$id])) {
                $out[$id] = self::BestLevels($book);
            }
        }
        return $out;
    }

    /**
     * Highest bid and lowest ask in a book. The arrays are scanned rather than
     * trusted to be sorted: Polymarket's docs disagree about their order, and
     * a stale order can sit at either end.
     *
     * @param array $book an OrderBookSummary: bids/asks lists of {price, size}
     * @return array{bid: ?float, ask: ?float, bid_size: ?float, ask_size: ?float}
     */
    public static function BestLevels(array $book): array
    {
        $best = static function (array $levels, bool $highest): array {
            $price = null;
            $size = null;
            foreach ($levels as $l) {
                if (!is_array($l) || !isset($l['price']) || !is_numeric($l['price'])) {
                    continue;
                }
                $p = (float)$l['price'];
                $s = is_numeric($l['size'] ?? null) ? (float)$l['size'] : 0.0;
                if ($s <= 0) {
                    continue;
                }
                if ($price === null || ($highest ? $p > $price : $p < $price)) {
                    $price = $p;
                    $size = $s;
                } elseif ($p === $price) {
                    $size += $s; // the same price listed twice
                }
            }
            return [$price, $size];
        };

        [$bid, $bid_size] = $best(is_array($book['bids'] ?? null) ? $book['bids'] : [], true);
        [$ask, $ask_size] = $best(is_array($book['asks'] ?? null) ? $book['asks'] : [], false);

        return ['bid' => $bid, 'ask' => $ask, 'bid_size' => $bid_size, 'ask_size' => $ask_size];
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
        return $this->Request('GET', $url . '?' . self::BuildQuery($params));
    }

    /**
     * POST a JSON body, with the same retries.
     *
     * @param string $url
     * @param array $body
     * @return array
     */
    protected function PostJSON(string $url, array $body): array
    {
        return $this->Request('POST', $url, $body);
    }

    /**
     * @param string $method GET or POST
     * @param string $full full URL (with query string for GET)
     * @param array|null $body JSON body for POST
     * @return array
     */
    protected function Request(string $method, string $full, ?array $body = null): array
    {
        $attempts = 0;
        while (true) {
            $attempts++;
            if ($this->delay_ms > 0) {
                usleep($this->delay_ms * 1000);
            }
            $this->requests++;

            try {
                $response = $method === 'POST'
                    ? Curl::PostJSON($full, $body, self::HEADERS + ['Content-Type' => 'application/json'])
                    : Curl::Get($full, null, self::HEADERS);
            } catch (GuzzleException $e) {
                $this->errors++;
                if ($attempts >= 3) {
                    throw new RuntimeException('Request failed: ' . $full . ' ' . $e->getMessage());
                }
                sleep(5 * $attempts);
                continue;
            }

            $status = (int)$response->StatusCode;
            $text = (string)$response->Body;

            if ($status === 200) {
                $json = json_decode($text, true);
                if (!is_array($json)) {
                    throw new RuntimeException('Response was not JSON: ' . $full . ' ' . substr($text, 0, 300));
                }
                return $json;
            }

            $this->errors++;
            $retryable = $status === 429 || $status >= 500;
            if (!$retryable || $attempts >= 4) {
                throw new RuntimeException('HTTP ' . $status . ': ' . $full . ' ' . substr($text, 0, 300));
            }

            $wait = (int)($response->HeaderHash['Retry-After'] ?? $response->HeaderHash['retry-after'] ?? 0);
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
}
