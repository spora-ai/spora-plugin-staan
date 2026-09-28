<?php

declare(strict_types=1);

namespace Spora\Plugins\Staan\Tools;

use Psr\Log\LoggerInterface;
use Spora\Services\PrincipalContext;
use Spora\Services\ToolConfigService;
use Spora\Tools\AbstractTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\Exceptions\ToolHttpErrorException;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Web search via Staan (api.staan.ai) — Qwant's EU-hosted search index.
 *
 * One HTTP endpoint serves both modes; enrichment is switched on by adding
 * `extra_snippets` to the payload rather than by calling a different URL. The
 * two operations therefore share a single request path and a single formatter,
 * and differ only in the three enrichment fields plus latency.
 *
 * `search` is declared first on purpose: {@see \Spora\Tools\Traits\HasOperations}
 * falls back to the first operation when the LLM omits the `action`
 * discriminator, so an unadorned call lands on the fast, cheap path.
 *
 * The `recommendsSkills` argument is deliberately absent from the #[Tool]
 * attribute: it only exists in spora-core's unreleased branch, so declaring it
 * would raise `Error: Unknown named parameter` on boot against the released
 * v0.28.0. The bundled `staan-search` skill is still discovered through
 * `StaanPlugin::skillPaths()` and can be attached via the Skill tool's
 * `allowed_skills` picker. Re-add the argument once v0.29.0 ships.
 */
#[Tool(
    name: 'search',
    description: 'Search the web via Staan (api.staan.ai), a Qwant-powered search engine hosted in the EU. Two modes: `search` returns a fast ranked result list (title, URL, snippet, publication date). `enriched_search` additionally fetches each result page and returns relevance-scored excerpts of the actual page text, reranked by that relevance — slower, but the excerpts can be quoted directly. Both modes support Google-style `site:` / `-site:` operators inside the query.',
    displayName: 'Staan Search',
    category: 'research',
    icon: 'search',
)]
#[ToolOperation(
    name: 'search',
    description: 'Fast ranked web results — title, URL, snippet, publication date. Results are in raw search-engine order and no result page is fetched. Use this for lookups, current events, and "what does X say about Y". This is the default when `action` is omitted.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'enriched_search',
    description: 'Search with scored page excerpts — fetches each result page and returns relevance-scored excerpts of the page body. Order is RERANKED by excerpt relevance, not raw search-engine order, so result [1] is the most on-topic page rather than the top-ranked one. Slower than `search`, and pages behind anti-bot walls or timeouts fall back to their plain snippet. Use this for RAG, quoting, or whenever the snippet is too thin to answer from.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolSetting(
    key: 'api_key',
    label: 'Staan API Key',
    type: 'password',
    description: 'API key for api.staan.ai (from https://staan.ai). 1,000 requests/month are free.',
    required: true,
)]
#[ToolSetting(
    key: 'market',
    label: 'Market',
    type: 'select',
    description: 'Language and region for the search index. Sent to Staan unless the agent overrides it per call.',
    default: 'fr-fr',
    options: [
        'fr-fr' => 'French — France (fr-fr)',
        'en-us' => 'English — United States (en-us)',
        'de-de' => 'German — Germany (de-de)',
    ],
    exposeToLlm: true,
)]
#[ToolSetting(
    key: 'min_score',
    label: 'Minimum excerpt score',
    type: 'text',
    description: 'For enriched_search: drop excerpts scoring below this relevance (0-1, default 0.2). Raise it to cut noise, lower it for broader coverage.',
    default: '0.2',
)]
#[ToolSetting(
    key: 'max_snippets',
    label: 'Excerpts per page',
    type: 'text',
    description: 'For enriched_search: how many scored excerpts to keep per page (1-10, default 3). Each excerpt is truncated to 800 characters, so the worst case is result_limit x this x 800 characters of context.',
    default: '3',
)]
#[ToolSetting(
    key: 'result_limit',
    label: 'Results returned to the agent',
    type: 'text',
    description: 'Maximum number of results handed back to the agent (1-10, default 10). Staan always returns 10 per page; this truncates what the agent pays context for.',
    default: '10',
)]
#[ToolSetting(
    key: 'http_timeout',
    label: 'HTTP Timeout',
    type: 'text',
    description: 'Seconds before an HTTP request fails (default: 30). enriched_search fetches every result page, so give it more headroom than the docs\' 10s minimum.',
)]
#[ToolParameter(
    name: 'query',
    type: 'string',
    description: 'The search query. Max 400 characters — write keyword-style terms, not a full question. Supports Google-style domain operators: "vector database pricing site:qdrant.tech" to include a domain, "-site:reddit.com" to exclude one. Multiple inclusions chain with OR: "site:a.com OR site:b.com".',
    required: true,
)]
#[ToolParameter(
    name: 'market',
    type: 'string',
    description: 'Override the market for this call. Defaults to the operator-configured market shown in the tool settings.',
    required: false,
    enum: ['fr-fr', 'en-us', 'de-de'],
)]
#[ToolParameter(
    name: 'offset',
    type: 'integer',
    description: 'Pagination offset for results past the first 10. Allowed values 0, 10, 20, 30 — 30 is the API maximum (40 results total). Values are rounded down to the nearest page.',
    required: false,
    minimum: 0,
    maximum: 30,
)]
final class StaanSearchTool extends AbstractTool
{
    private const ENDPOINT = 'https://api.staan.ai/v2/search/web';

    /** Staan's documented hard limit; longer queries are rejected, not truncated. */
    private const MAX_QUERY_CHARS = 400;

    private const PAGE_SIZE = 10;
    private const MAX_OFFSET = 30;
    private const MAX_SNIPPETS_PER_PAGE = 10;

    /**
     * Per-excerpt cap. Staan's chunks run 300-1800 characters; truncating to
     * 800 keeps the opening of a typical chunk and bounds the worst case at
     * result_limit x max_snippets x 800 characters of injected context.
     */
    private const MAX_CHUNK_CHARS = 800;

    /** Longest error body echoed back to the LLM before truncation. */
    private const MAX_ERROR_BODY_CHARS = 300;

    private const MARKETS = ['fr-fr', 'en-us', 'de-de'];
    private const DEFAULT_MARKET = 'fr-fr';

    private const DEFAULT_MIN_SCORE = 0.2;
    private const DEFAULT_MAX_SNIPPETS = 3;
    private const DEFAULT_RESULT_LIMIT = 10;
    private const DEFAULT_TIMEOUT = 30;

    private const ERR_EMPTY_QUERY = 'The search query cannot be empty.';
    private const ERR_API_KEY_MISSING = 'Staan API key is not configured for this agent. Please edit the Staan Search settings.';

    private const LOG_HTTP_REQUEST = 'StaanSearchTool: HTTP request';
    private const LOG_HTTP_RESPONSE = 'StaanSearchTool: HTTP response';
    private const LOG_API_ERROR = 'Staan API error';
    private const LOG_EXCEPTION = 'StaanSearchTool exception';

    public function __construct(
        private readonly ToolConfigService $configService,
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        $operation = $this->getOperationName($arguments);

        return match ($operation) {
            'search'          => $this->run($arguments, $agentId, $userId, false),
            'enriched_search' => $this->run($arguments, $agentId, $userId, true),
            default           => ToolResult::fail("Unknown operation: {$operation}"),
        };
    }

    public function describeAction(array $arguments): string
    {
        $query = trim((string) ($arguments['query'] ?? ''));

        return $this->getOperationName($arguments) === 'enriched_search'
            ? "Search the web via Staan with scored page excerpts for: '{$query}'"
            : "Search the web via Staan for: '{$query}'";
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{query: string, market: string, offset: int, offset_capped: bool, settings: array<string, mixed>}|ToolResult
     */
    private function prepare(array $arguments, int $agentId, ?int $userId): array|ToolResult
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            return ToolResult::fail(self::ERR_EMPTY_QUERY);
        }

        // Never truncate an over-long query: a silently shortened query returns
        // results for a different question and the agent reasons about fiction.
        $length = mb_strlen($query);
        if ($length > self::MAX_QUERY_CHARS) {
            return ToolResult::fail(sprintf(
                'Staan rejects queries longer than %d characters (got %d). Shorten it to the keywords that matter.',
                self::MAX_QUERY_CHARS,
                $length,
            ));
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        if ($apiKey === '') {
            return ToolResult::fail(self::ERR_API_KEY_MISSING);
        }
        $settings['api_key'] = $apiKey;

        $market = $this->resolveMarket($arguments['market'] ?? null, $settings['market'] ?? null);
        if ($market === null) {
            return ToolResult::fail(sprintf(
                'Unsupported market. Staan supports: %s.',
                implode(', ', self::MARKETS),
            ));
        }

        $requestedOffset = $arguments['offset'] ?? null;
        $offset = $this->resolveOffset($requestedOffset);

        return [
            'query'         => $query,
            'market'        => $market,
            'offset'        => $offset,
            'offset_capped' => $requestedOffset !== null && $offset !== $this->toIntOrNull($requestedOffset),
            'settings'      => $settings,
        ];
    }

    /**
     * LLM-supplied values are rejected, not coerced: a market the API would
     * reject should surface as a correctable error, not silent fallback.
     */
    private function resolveMarket(mixed $requested, mixed $configured): ?string
    {
        $candidate = trim((string) ($requested ?? $configured ?? ''));
        if ($candidate === '') {
            return self::DEFAULT_MARKET;
        }

        return in_array($candidate, self::MARKETS, true) ? $candidate : null;
    }

    private function resolveOffset(mixed $requested): int
    {
        $numeric = $this->toIntOrNull($requested);
        if ($numeric === null) {
            return 0;
        }

        $clamped = max(0, min(self::MAX_OFFSET, $numeric));

        return intdiv($clamped, self::PAGE_SIZE) * self::PAGE_SIZE;
    }

    private function toIntOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array{query: string, market: string, offset: int, offset_capped: bool, settings: array<string, mixed>} $prepared
     * @return array<string, mixed>
     */
    private function buildPayload(array $prepared, bool $enriched): array
    {
        $payload = [
            'q'      => $prepared['query'],
            'market' => $prepared['market'],
            'offset' => $prepared['offset'],
            'count'  => self::PAGE_SIZE,
        ];

        if ($enriched) {
            $settings = $prepared['settings'];
            $payload['extra_snippets'] = true;
            $payload['max_snippets'] = $this->clampInt(
                $settings['max_snippets'] ?? null,
                self::DEFAULT_MAX_SNIPPETS,
                1,
                self::MAX_SNIPPETS_PER_PAGE,
            );
            $payload['min_score'] = $this->clampFloat(
                $settings['min_score'] ?? null,
                self::DEFAULT_MIN_SCORE,
                0.0,
                1.0,
            );
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function run(array $arguments, int $agentId, ?int $userId, bool $enriched): ToolResult
    {
        $prepared = $this->prepare($arguments, $agentId, $userId);
        if ($prepared instanceof ToolResult) {
            return $prepared;
        }

        try {
            $data = $this->request(
                $this->buildPayload($prepared, $enriched),
                (string) $prepared['settings']['api_key'],
                $this->effectiveTimeout($prepared['settings']),
            );

            return new ToolResult(
                true,
                $this->formatResults($data, $enriched, $prepared),
                [
                    'search_id' => is_string($data['search_id'] ?? null) ? $data['search_id'] : null,
                    'market'    => $prepared['market'],
                    'offset'    => $prepared['offset'],
                    'enriched'  => $enriched,
                ],
            );
        } catch (Throwable $e) {
            $this->logger?->error(self::LOG_EXCEPTION, ['exception' => $e]);

            return ToolResult::fail('Search tool error: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function request(array $payload, string $apiKey, int $timeout): array
    {
        $this->logger?->debug(self::LOG_HTTP_REQUEST, [
            'method'  => 'POST',
            'url'     => self::ENDPOINT,
            'headers' => ['Authorization' => 'Bearer ***', 'Content-Type' => 'application/json'],
            'payload' => $payload,
            'timeout' => $timeout,
        ]);

        $response = $this->httpClient->request('POST', self::ENDPOINT, [
            'headers' => [
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'json'    => $payload,
            'timeout' => $timeout,
        ]);

        $statusCode = $response->getStatusCode();
        $this->logger?->debug(self::LOG_HTTP_RESPONSE, [
            'status_code' => $statusCode,
            'url'         => self::ENDPOINT,
        ]);

        if ($statusCode >= 400) {
            $body = $response->getContent(false);
            $this->logger?->error(self::LOG_API_ERROR, [
                'status' => $statusCode,
                'body'   => $body,
            ]);

            throw new ToolHttpErrorException($this->httpErrorMessage($statusCode, $body));
        }

        return $response->toArray(false);
    }

    private function httpErrorMessage(int $status, string $body): string
    {
        $detail = $this->summariseErrorBody($body);

        return match (true) {
            $status === 401 || $status === 403
                => "Staan rejected the API key (HTTP {$status}). Check the Staan API key in the tool settings.{$detail}",
            $status === 429
                => 'Staan rate limit reached (HTTP 429 — the API allows 20 requests/second). Wait a moment and retry. ' . "Do not fire several searches in parallel.{$detail}",
            $status === 400
                => "Staan rejected the request (HTTP 400).{$detail}",
            default
            => "Staan search failed with HTTP {$status}.{$detail}",
        };
    }

    private function summariseErrorBody(string $body): string
    {
        $flat = trim(preg_replace('/\s+/', ' ', $body) ?? '');
        if ($flat === '') {
            return '';
        }

        $truncated = mb_strlen($flat) > self::MAX_ERROR_BODY_CHARS;
        $clipped = mb_substr($flat, 0, self::MAX_ERROR_BODY_CHARS);

        return ' Staan said: ' . $clipped . ($truncated ? '…' : '');
    }

    /**
     * @param array<string, mixed> $data
     * @param array{query: string, market: string, offset: int, offset_capped: bool, settings: array<string, mixed>} $prepared
     */
    private function formatResults(array $data, bool $enriched, array $prepared): string
    {
        $web = $data['web'] ?? [];
        $results = is_array($web) && is_array($web['results'] ?? null) ? $web['results'] : [];

        $limit = $this->clampInt(
            $prepared['settings']['result_limit'] ?? null,
            self::DEFAULT_RESULT_LIMIT,
            1,
            self::PAGE_SIZE,
        );
        $shown = array_slice($results, 0, $limit);

        $heading = $enriched
            ? "Staan enriched results for '{$prepared['query']}' (ranked by excerpt relevance):"
            : "Staan web results for '{$prepared['query']}':";

        if ($shown === []) {
            return "{$heading}\n\nNo results found.\n";
        }

        $output = "{$heading}\n\n";
        $unfetched = 0;

        foreach ($shown as $index => $result) {
            if (is_array($result) && $enriched && !$this->hasChunks($result)) {
                $unfetched++;
            }
            $output .= $this->formatResult(is_array($result) ? $result : [], $index + 1, $enriched);
        }

        return $output . $this->formatFooter($data, $enriched, $unfetched, $prepared, count($results), $limit);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function hasChunks(array $result): bool
    {
        $chunks = $result['extra_snippets'] ?? null;

        return is_array($chunks) && $chunks !== [];
    }

    /**
     * @param array<string, mixed> $result
     */
    private function formatResult(array $result, int $position, bool $enriched): string
    {
        $output = sprintf("[%d] %s\n", $position, $this->text($result['title'] ?? null) ?: '(untitled)');

        $url = $this->text($result['url'] ?? null);
        if ($url !== '') {
            $output .= "URL: {$url}\n";
        }

        $host = $this->text($result['hostname'] ?? null);
        if ($host !== '') {
            $output .= "Host: {$host}\n";
        }

        $published = $this->text($result['published_date'] ?? null);
        if ($published !== '') {
            $output .= "Published: {$published}\n";
        }

        $snippet = $this->text($result['snippet'] ?? null);
        if ($snippet !== '') {
            $output .= "Snippet: {$snippet}\n";
        }

        if ($enriched) {
            $output .= $this->hasChunks($result)
                ? $this->formatChunks($result)
                : "(no page excerpt available for this result — the plain snippet is shown instead)\n";
        }

        return $output . "\n";
    }

    /**
     * @param array<string, mixed> $result
     */
    private function formatChunks(array $result): string
    {
        $chunks = $result['extra_snippets'];
        $output = '';

        foreach ($chunks as $chunk) {
            if (!is_array($chunk)) {
                continue;
            }
            $text = $this->truncate($this->text($chunk['chunk'] ?? null));
            if ($text === '') {
                continue;
            }
            $score = is_numeric($chunk['score'] ?? null) ? number_format((float) $chunk['score'], 2) : '?';
            $output .= "  [{$score}] {$text}\n";
        }

        return $output;
    }

    /**
     * @param array<string, mixed> $data
     * @param array{query: string, market: string, offset: int, offset_capped: bool, settings: array<string, mixed>} $prepared
     */
    private function formatFooter(
        array $data,
        bool $enriched,
        int $unfetched,
        array $prepared,
        int $total,
        int $limit,
    ): string {
        $notes = [];
        $queryMeta = is_array($data['query'] ?? null) ? $data['query'] : [];

        $altered = $this->text($queryMeta['altered_query'] ?? null);
        if ($altered !== '' && $altered !== $prepared['query']) {
            $notes[] = "The search engine rewrote the query to \"{$altered}\" — the results answer that, not the text you sent.";
        }

        if ($enriched && $unfetched > 0) {
            $notes[] = "{$unfetched} of {$total} result pages could not be extracted (anti-bot wall, timeout, or no excerpt above the minimum score); their plain snippet is shown instead. Consider a differently-worded query, or `search` for the raw result list.";
        }

        if ($total > $limit) {
            $notes[] = "Showing the first {$limit} of {$total} results. Raise the \"Results returned to the agent\" setting or pass a higher `offset` to see the rest.";
        }

        if ($prepared['offset_capped']) {
            $notes[] = "Offset was rounded to page {$prepared['offset']} — Staan's maximum is " . self::MAX_OFFSET . ' (40 results).';
        }

        return $notes === [] ? '' : "\n" . implode("\n", array_map(
            static fn(string $note): string => "Note: {$note}",
            $notes,
        )) . "\n";
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function truncate(string $text, int $limit = self::MAX_CHUNK_CHARS): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $limit)) . '… [truncated]';
    }

    /**
     * Operator-set numerics are clamped rather than rejected: a typo in a
     * settings field should degrade the retrieval quality, not break the call.
     *
     * @param array<string, mixed> $settings
     */
    private function effectiveTimeout(array $settings): int
    {
        $configured = $this->clampInt($settings['http_timeout'] ?? null, 0, 1, 300);
        if ($configured > 0) {
            return $configured;
        }

        $env = (int) ($_ENV['SPORA_TOOL_HTTP_TIMEOUT'] ?? getenv('SPORA_TOOL_HTTP_TIMEOUT') ?: 0);

        return $env > 0 ? $env : self::DEFAULT_TIMEOUT;
    }

    private function clampInt(mixed $value, int $default, int $min, int $max): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    private function clampFloat(mixed $value, float $default, float $min, float $max): float
    {
        if (!is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (float) $value));
    }
}
