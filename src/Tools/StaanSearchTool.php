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
 * two operations therefore share a single request path and differ only in
 * three payload fields plus latency. Rendering lives in
 * {@see StaanResultFormatter}.
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
    name: 'staan_search',
    // Slug-prefixed like every other search tool in the ecosystem
    // (serper_search, tavily_search, worldnews_search, scholar_search, …).
    // A bare `search` was the only one of its kind, and the name→class map that
    // resolves it is last-wins with no collision check — so a second plugin
    // declaring `search` would silently re-point a skill's declaration at the
    // wrong tool with nothing reporting an error. The operation below stays bare
    // `search`, which is the convention: prefixed tool, bare operation.
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
        'fr-fr' => 'French — France',
        'de-de' => 'German — Germany',
        'en-us' => 'English — United States',
        'en-gb' => 'English — United Kingdom',
        'en-ie' => 'English — Ireland',
        'en-fr' => 'English — France',
        'en-ca' => 'English — Canada',
        'en-au' => 'English — Australia',
        'en-nz' => 'English — New Zealand',
        'en-in' => 'English — India',
        'en-sg' => 'English — Singapore',
        'en-za' => 'English — South Africa',
    ],
    exposeToLlm: true,
)]
#[ToolSetting(
    key: 'min_score',
    label: 'Minimum excerpt score',
    type: 'text',
    description: 'For enriched_search: drop excerpts scoring below this relevance (0-1, default 0.2). Raise it to cut noise, lower it for broader coverage. The agent cannot override this — a lower floor means more context.',
    default: '0.2',
)]
#[ToolSetting(
    key: 'max_snippets',
    label: 'Excerpts per page (ceiling)',
    type: 'text',
    description: 'For enriched_search: the most scored excerpts kept per page (1-10, default 3). This is a ceiling — the agent may ask for fewer on a call to save context, but never more. Each excerpt is truncated to 800 characters, so the worst case is result_limit x this x 800 characters.',
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
    description: 'Seconds before an HTTP request fails (default: 30, or SPORA_TOOL_HTTP_TIMEOUT when set). enriched_search fetches every result page, so keep headroom above the 10s the docs recommend.',
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
    description: 'Override the market for this call. Defaults to the operator-configured market shown in the tool settings. Only pass it when the operator\'s default is wrong for this question — e.g. ask for en-gb over en-us for UK sources, or en-fr for English-language pages hosted in France.',
    required: false,
    enum: [
        'fr-fr', 'de-de', 'en-us', 'en-gb', 'en-ie', 'en-fr',
        'en-ca', 'en-au', 'en-nz', 'en-in', 'en-sg', 'en-za',
    ],
)]
#[ToolParameter(
    name: 'offset',
    type: 'integer',
    description: 'Pagination offset for results past the first 10. Allowed values 0, 10, 20, 30 — 30 is the API maximum (40 results total). Values are rounded down to the nearest page.',
    required: false,
    minimum: 0,
    maximum: 30,
)]
#[ToolParameter(
    name: 'max_snippets',
    type: 'integer',
    description: 'Fewer scored excerpts to keep per page, 1-10. Use this to save context when you already have enough (for example 1 when you only need to quote the single best passage). This can only LOWER the operator\'s configured ceiling — asking for more is capped, never granted. Omit it to use the configured value.',
    required: false,
    minimum: 1,
    maximum: 10,
)]
final class StaanSearchTool extends AbstractTool
{
    private const ENDPOINT = 'https://api.staan.ai/v2/search/web';

    private const CONTENT_TYPE_JSON = 'application/json';

    /** Staan's documented hard limit; longer queries are rejected, not truncated. */
    private const MAX_QUERY_CHARS = 400;

    private const PAGE_SIZE = 10;
    private const MAX_OFFSET = 30;
    private const MAX_SNIPPETS_PER_PAGE = 10;

    private const DEFAULT_MIN_SCORE = 0.2;
    private const DEFAULT_MAX_SNIPPETS = 3;
    private const DEFAULT_RESULT_LIMIT = 10;
    private const DEFAULT_TIMEOUT = 30;
    private const MAX_TIMEOUT = 300;

    /** Longest error body echoed back to the LLM before truncation. */
    private const MAX_ERROR_BODY_CHARS = 300;

    /**
     * The full market list from the v2 API reference. The prose guides only
     * advertise fr-fr / en-us / de-de, and the reference page collapses the
     * tail of its enum behind a "show 4 more" control.
     */
    private const MARKETS = [
        'fr-fr', 'de-de',
        'en-us', 'en-gb', 'en-ie', 'en-fr',
        'en-ca', 'en-au', 'en-nz', 'en-in', 'en-sg', 'en-za',
    ];
    private const DEFAULT_MARKET = 'fr-fr';

    private const ERR_EMPTY_QUERY = 'The search query cannot be empty.';
    private const ERR_API_KEY_MISSING = 'Staan API key is not configured for this agent. Please edit the Staan Search settings.';

    private const LOG_HTTP_REQUEST = 'StaanSearchTool: HTTP request';
    private const LOG_HTTP_RESPONSE = 'StaanSearchTool: HTTP response';
    private const LOG_API_ERROR = 'Staan API error';
    private const LOG_EXCEPTION = 'StaanSearchTool exception';

    private readonly StaanResultFormatter $formatter;

    public function __construct(
        private readonly ToolConfigService $configService,
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
        ?StaanResultFormatter $formatter = null,
    ) {
        $this->formatter = $formatter ?? new StaanResultFormatter();
    }

    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        $operation = $this->getOperationName($arguments);

        try {
            return match ($operation) {
                'search'          => $this->run($arguments, $agentId, $userId, false),
                'enriched_search' => $this->run($arguments, $agentId, $userId, true),
                default           => ToolResult::fail("Unknown operation: {$operation}"),
            };
        } catch (Throwable $e) {
            // Reached only for a settings-layer fault (DB or key decryption) —
            // everything downstream is already a ToolResult.
            $this->logger?->error(self::LOG_EXCEPTION, ['exception' => $e]);

            return ToolResult::fail('Search tool error: ' . $e->getMessage());
        }
    }

    public function describeAction(array $arguments): string
    {
        $query = $this->text($arguments['query'] ?? null);

        return $this->getOperationName($arguments) === 'enriched_search'
            ? "Search the web via Staan with scored page excerpts for: '{$query}'"
            : "Search the web via Staan for: '{$query}'";
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
                $this->formatter->format($data, $enriched, new StaanFormatContext(
                    query: $prepared['query'],
                    offset: $prepared['offset'],
                    offsetAdjusted: $prepared['offset_adjusted'],
                    resultLimit: $this->clampInt(
                        $prepared['settings']['result_limit'] ?? null,
                        self::DEFAULT_RESULT_LIMIT,
                        1,
                        self::PAGE_SIZE,
                    ),
                )),
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
     * @param  array<string, mixed> $arguments
     * @return array{query: string, market: string, offset: int, offset_adjusted: bool, excerpts: int, settings: array<string, mixed>}|ToolResult
     */
    private function prepare(array $arguments, int $agentId, ?int $userId): array|ToolResult
    {
        $query = $this->text($arguments['query'] ?? null);

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $settings['api_key'] = $this->text($settings['api_key'] ?? null);

        $invalid = $this->validate($query, $settings);
        if ($invalid !== null) {
            return ToolResult::fail($invalid);
        }

        $market = $this->resolveMarket($arguments['market'] ?? null, $settings['market'] ?? null);
        if ($market === null) {
            return ToolResult::fail(sprintf(
                'Unsupported market. Staan supports: %s.',
                implode(', ', self::MARKETS),
            ));
        }

        [$offset, $offsetAdjusted] = $this->resolveOffset($arguments['offset'] ?? null);

        return [
            'query'           => $query,
            'market'          => $market,
            'offset'          => $offset,
            'offset_adjusted' => $offsetAdjusted,
            'excerpts'        => $this->resolveExcerpts($arguments['max_snippets'] ?? null, $settings['max_snippets'] ?? null),
            'settings'        => $settings,
        ];
    }

    /**
     * First failure message, or null when the call is well formed. The query
     * is checked before the key so the agent gets the actionable fix rather
     * than a credentials complaint.
     *
     * @param array<string, mixed> $settings
     */
    private function validate(string $query, array $settings): ?string
    {
        $queryError = $this->queryError($query);
        if ($queryError !== null) {
            return $queryError;
        }

        return $settings['api_key'] === '' ? self::ERR_API_KEY_MISSING : null;
    }

    private function queryError(string $query): ?string
    {
        if ($query === '') {
            return self::ERR_EMPTY_QUERY;
        }

        // Never truncate an over-long query: a silently shortened query returns
        // results for a different question and the agent reasons about fiction.
        $length = mb_strlen($query);
        if ($length > self::MAX_QUERY_CHARS) {
            return sprintf(
                'Staan rejects queries longer than %d characters (got %d). Shorten it to the keywords that matter.',
                self::MAX_QUERY_CHARS,
                $length,
            );
        }

        return null;
    }

    /**
     * Each side is resolved independently: an agent that sends `market: ""`
     * must fall through to the operator's setting, not short-circuit past it.
     * A value that is present but wrong is rejected rather than coerced.
     */
    private function resolveMarket(mixed $requested, mixed $configured): ?string
    {
        $candidate = $this->text($requested);
        if ($candidate === '') {
            $candidate = $this->text($configured);
        }
        if ($candidate === '') {
            return self::DEFAULT_MARKET;
        }

        return in_array($candidate, self::MARKETS, true) ? $candidate : null;
    }

    /**
     * @return array{int, bool} the page-aligned offset, and whether it had to move
     */
    private function resolveOffset(mixed $requested): array
    {
        $numeric = $this->toIntOrNull($requested);
        if ($numeric === null) {
            return [0, false];
        }

        $page = intdiv(max(0, min(self::MAX_OFFSET, $numeric)), self::PAGE_SIZE) * self::PAGE_SIZE;

        return [$page, $page !== $numeric];
    }

    /**
     * The operator's `max_snippets` is a ceiling, not a fixed value: the agent
     * may ask for fewer excerpts to save context, but never more.
     *
     * An over-ask is capped silently rather than rejected. The operator's budget
     * is protected either way, and a hard failure would only teach the model
     * that the parameter is a trap.
     */
    private function resolveExcerpts(mixed $requested, mixed $configured): int
    {
        $ceiling = $this->clampInt(
            $configured,
            self::DEFAULT_MAX_SNIPPETS,
            1,
            self::MAX_SNIPPETS_PER_PAGE,
        );

        $asked = $this->toIntOrNull($requested);

        return $asked === null ? $ceiling : max(1, min($ceiling, $asked));
    }

    private function toIntOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array{query: string, market: string, offset: int, offset_adjusted: bool, excerpts: int, settings: array<string, mixed>} $prepared
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
            $payload['extra_snippets'] = true;
            $payload['max_snippets'] = $prepared['excerpts'];
            $payload['min_score'] = $this->clampFloat(
                $prepared['settings']['min_score'] ?? null,
                self::DEFAULT_MIN_SCORE,
                0.0,
                1.0,
            );
        }

        return $payload;
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
            'headers' => ['Authorization' => 'Bearer ***', 'Content-Type' => self::CONTENT_TYPE_JSON],
            'payload' => $payload,
            'timeout' => $timeout,
        ]);

        $response = $this->httpClient->request('POST', self::ENDPOINT, [
            'headers' => [
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type'  => self::CONTENT_TYPE_JSON,
                'Accept'        => self::CONTENT_TYPE_JSON,
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
     * The setting is tested before it is clamped, so `0` and a negative value
     * fall through to the env var and the 30s default rather than collapsing to
     * a one-second timeout. Operator-set numerics are otherwise clamped rather
     * than rejected: a typo should degrade the call, not break it.
     *
     * @param array<string, mixed> $settings
     */
    private function effectiveTimeout(array $settings): int
    {
        $configured = $this->positiveIntOrNull($settings['http_timeout'] ?? null);
        if ($configured !== null) {
            return min(self::MAX_TIMEOUT, $configured);
        }

        $env = $this->positiveIntOrNull($_ENV['SPORA_TOOL_HTTP_TIMEOUT'] ?? getenv('SPORA_TOOL_HTTP_TIMEOUT') ?: null);

        return $env === null ? self::DEFAULT_TIMEOUT : min(self::MAX_TIMEOUT, $env);
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        $int = $this->toIntOrNull($value);

        return ($int !== null && $int > 0) ? $int : null;
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

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
