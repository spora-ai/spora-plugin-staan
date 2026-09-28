<?php

declare(strict_types=1);

use Spora\Plugins\Staan\Tests\Support\StaanToolHarness;

const ENDPOINT = 'https://api.staan.ai/v2/search/web';

/**
 * @return array<string, mixed>
 */
function okBody(array $results = [], array $queryOverrides = []): array
{
    return [
        'search_id' => '01906c9e-7e3f-7000-8000-abc123def456',
        'query'     => array_merge(['q' => 'vector db', 'market' => 'fr-fr', 'count' => 10, 'offset' => 0], $queryOverrides),
        'web'       => ['results' => $results],
    ];
}

/**
 * @return array<string, mixed>
 */
function aResult(array $overrides = []): array
{
    return array_merge([
        'title'        => 'Comparing vector databases',
        'url'          => 'https://www.example.com/vector-dbs',
        'snippet'      => 'A deep dive into Pinecone, Weaviate, Qdrant...',
        'display_url'  => 'www.example.com > ai > vector-dbs',
        'hostname'     => 'www.example.com',
    ], $overrides);
}

function harness(array $settings = [], ?array $body = null, int $status = 200, string $raw = ''): StaanToolHarness
{
    return new StaanToolHarness(
        array_merge(['api_key' => 'staan_test_key'], $settings),
        $body ?? okBody([aResult()]),
        $status,
        $raw,
    );
}

/* ---------------------------------------------------------------- inputs -- */

it('fails when the query is empty', function () {
    $result = harness()->call(['query' => '   ']);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('query cannot be empty');
});

it('fails when the query is missing entirely', function () {
    $result = harness()->call([]);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('query cannot be empty');
});

it('fails when the api key is not configured', function () {
    $result = harness(['api_key' => ''])->call(['query' => 'vector db']);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('API key is not configured');
});

it('rejects an over-long query instead of truncating it', function () {
    $harness = harness();
    $long = str_repeat('a', 401);

    $result = $harness->call(['query' => $long]);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('400 characters')
        ->and($result->content)->toContain('got 401')
        ->and($harness->requests)->toBe([]);
});

it('accepts a query of exactly 400 characters', function () {
    $result = harness()->call(['query' => str_repeat('a', 400)]);

    expect($result->success)->toBeTrue();
});

it('fails on an unknown market and names the valid ones', function () {
    $harness = harness();
    $result = $harness->call(['query' => 'vector db', 'market' => 'es-es']);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Unsupported market')
        ->and($result->content)->toContain('fr-fr')
        ->and($result->content)->toContain('en-za')
        ->and($harness->requests)->toBe([]);
});

it('rejects an unknown operation', function () {
    $result = harness()->call(['query' => 'vector db', 'action' => 'deep_search']);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Unknown operation: deep_search');
});

/* -------------------------------------------------------------- requests -- */

it('posts to the web search endpoint with a bearer token', function () {
    $harness = harness();
    $harness->call(['query' => 'vector db']);

    expect($harness->requests)->toHaveCount(1)
        ->and($harness->requests[0]['method'])->toBe('POST')
        ->and($harness->requests[0]['url'])->toBe(ENDPOINT)
        ->and($harness->sentHeaders()['Authorization'])->toBe('Bearer staan_test_key')
        ->and($harness->sentHeaders()['Content-Type'])->toBe('application/json');
});

it('never puts the api key in the payload', function () {
    $harness = harness();
    $harness->call(['query' => 'vector db']);

    expect($harness->sentPayload())->not->toHaveKey('api_key');
});

it('omits every enrichment field on the default search operation', function () {
    $harness = harness();
    $harness->call(['query' => 'vector db']);

    $payload = $harness->sentPayload();

    expect($payload)->toBe([
        'q'      => 'vector db',
        'market' => 'fr-fr',
        'offset' => 0,
        'count'  => 10,
    ])
        ->and($payload)->not->toHaveKey('extra_snippets')
        ->and($payload)->not->toHaveKey('max_snippets')
        ->and($payload)->not->toHaveKey('min_score');
});

it('defaults to search when the action discriminator is absent', function () {
    $harness = harness();
    $harness->call(['query' => 'vector db']);

    expect($harness->sentPayload())->not->toHaveKey('extra_snippets');
});

it('sends the enrichment fields on enriched_search', function () {
    $harness = harness(['min_score' => '0.3', 'max_snippets' => '6']);
    $harness->call(['query' => 'vector db', 'action' => 'enriched_search']);

    expect($harness->sentPayload())->toMatchArray([
        'extra_snippets' => true,
        'max_snippets'   => 6,
        'min_score'      => 0.3,
    ]);
});

it('falls back to the documented enrichment defaults when settings are unset', function () {
    $harness = harness(['min_score' => null, 'max_snippets' => null]);
    $harness->call(['query' => 'vector db', 'action' => 'enriched_search']);

    expect($harness->sentPayload())->toMatchArray([
        'max_snippets' => 3,
        'min_score'    => 0.2,
    ]);
});

it('clamps out-of-range operator tuning instead of failing the call', function () {
    $harness = harness(['min_score' => '9', 'max_snippets' => '99']);
    $harness->call(['query' => 'vector db', 'action' => 'enriched_search']);

    expect($harness->sentPayload())->toMatchArray([
        'max_snippets' => 10,
        'min_score'    => 1.0,
    ]);
});

it('ignores non-numeric operator tuning rather than coercing it to zero', function () {
    $harness = harness(['min_score' => 'high', 'max_snippets' => 'lots']);
    $harness->call(['query' => 'vector db', 'action' => 'enriched_search']);

    expect($harness->sentPayload())->toMatchArray([
        'max_snippets' => 3,
        'min_score'    => 0.2,
    ]);
});

/* ---------------------------------------------------------------- market -- */

it('uses the configured market', function () {
    $harness = harness(['market' => 'de-de']);
    $harness->call(['query' => 'vektordatenbank']);

    expect($harness->sentPayload()['market'])->toBe('de-de');
});

it('lets the agent override the configured market per call', function () {
    $harness = harness(['market' => 'de-de']);
    $harness->call(['query' => 'vector db', 'market' => 'en-us']);

    expect($harness->sentPayload()['market'])->toBe('en-us');
});

it('falls back to the api default market when nothing is configured', function () {
    $harness = harness(['market' => null]);
    $harness->call(['query' => 'vector db']);

    expect($harness->sentPayload()['market'])->toBe('fr-fr');
});

it('accepts every market in the api reference, not just the three in the prose guides', function (string $market) {
    $harness = harness();
    $result = $harness->call(['query' => 'vector db', 'market' => $market]);

    expect($result->success)->toBeTrue()
        ->and($harness->sentPayload()['market'])->toBe($market);
})->with([
    'fr-fr', 'de-de', 'en-us', 'en-gb', 'en-ie', 'en-fr',
    'en-ca', 'en-au', 'en-nz', 'en-in', 'en-sg', 'en-za',
]);

it('names every supported market when the value is not recognised', function () {
    $result = harness()->call(['query' => 'vector db', 'market' => 'es-es']);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('fr-fr, de-de, en-us, en-gb, en-ie, en-fr')
        ->and($result->content)->toContain('en-ca, en-au, en-nz, en-in, en-sg, en-za');
});

/* ------------------------------------------------------ excerpt ceiling -- */

it('uses the configured ceiling when the agent does not ask', function () {
    $harness = harness(['max_snippets' => '5']);
    $harness->call(['query' => 'vector db', 'action' => 'enriched_search']);

    expect($harness->sentPayload()['max_snippets'])->toBe(5);
});

it('lets the agent lower the excerpt count to save context', function () {
    $harness = harness(['max_snippets' => '5']);
    $harness->call(['query' => 'vector db', 'action' => 'enriched_search', 'max_snippets' => 1]);

    expect($harness->sentPayload()['max_snippets'])->toBe(1);
});

it('caps an agent asking for more than the operator ceiling', function () {
    $harness = harness(['max_snippets' => '3']);
    $harness->call(['query' => 'vector db', 'action' => 'enriched_search', 'max_snippets' => 10]);

    expect($harness->sentPayload()['max_snippets'])->toBe(3);
});

it('never lets the agent push past the api maximum even with a raised ceiling', function () {
    $harness = harness(['max_snippets' => '50']);
    $harness->call(['query' => 'vector db', 'action' => 'enriched_search', 'max_snippets' => 10]);

    expect($harness->sentPayload()['max_snippets'])->toBe(10);
});

it('ignores the excerpt parameter entirely on the plain search operation', function () {
    $harness = harness(['max_snippets' => '5']);
    $harness->call(['query' => 'vector db', 'max_snippets' => 1]);

    expect($harness->sentPayload())->not->toHaveKey('max_snippets');
});

it('clamps a nonsense excerpt request instead of failing the call', function () {
    $harness = harness(['max_snippets' => '5']);
    $harness->call(['query' => 'vector db', 'action' => 'enriched_search', 'max_snippets' => 0]);

    expect($harness->sentPayload()['max_snippets'])->toBe(1);
});

/* ---------------------------------------------------------------- offset -- */

it('rounds an arbitrary offset down to the nearest page', function (int $given, int $expected) {
    $harness = harness();
    $harness->call(['query' => 'vector db', 'offset' => $given]);

    expect($harness->sentPayload()['offset'])->toBe($expected);
})->with([
    'below range'   => [-5, 0],
    'mid page'      => [12, 10],
    'mid page high' => [25, 20],
    'at the cap'    => [30, 30],
    'above the cap' => [45, 30],
]);

it('sends offset 0 when none is supplied', function () {
    $harness = harness();
    $harness->call(['query' => 'vector db']);

    expect($harness->sentPayload()['offset'])->toBe(0);
});

it('explains that the offset was adjusted to a page boundary', function () {
    $result = harness()->call(['query' => 'vector db', 'offset' => 45]);

    expect($result->content)->toContain('Note: Offset was adjusted to page 30')
        ->and($result->content)->toContain('blocks of 10')
        ->and($result->content)->toContain('the last page is 30 (40 results)');
});

it('explains a mid-page offset without claiming the ceiling was reached', function () {
    $result = harness()->call(['query' => 'vector db', 'offset' => 12]);

    expect($result->content)->toContain('Note: Offset was adjusted to page 10')
        ->and($result->content)->toContain('the last page is 30');
});

it('stays silent about a non-numeric offset instead of blaming the api ceiling', function (mixed $given) {
    $result = harness()->call(['query' => 'vector db', 'offset' => $given]);

    expect($result->content)->not->toContain('Note:');
})->with([
    'empty string' => '',
    'words'        => 'abc',
    'array'        => [[]],
    'boolean'      => true,
]);

it('stays quiet about the offset when it was already valid', function () {
    $result = harness()->call(['query' => 'vector db', 'offset' => 10]);

    expect($result->content)->not->toContain('rounded to page')
        ->and($result->content)->not->toContain('adjusted to page');
});

/* ------------------------------------------------------------ formatting -- */

it('renders a numbered row with url, host, date and snippet', function () {
    $harness = harness([], okBody([aResult(['published_date' => '2024-09-10T00:00:00.000Z'])]));
    $result = $harness->call(['query' => 'vector db']);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain("[1] Comparing vector databases\n")
        ->and($result->content)->toContain('URL: https://www.example.com/vector-dbs')
        ->and($result->content)->toContain('Host: www.example.com')
        ->and($result->content)->toContain('Published: 2024-09-10T00:00:00.000Z')
        ->and($result->content)->toContain('Snippet: A deep dive into Pinecone');
});

it('exposes search_id, market, offset and mode as structured data', function () {
    $result = harness()->call(['query' => 'vector db', 'offset' => 20]);

    expect($result->data)->toBe([
        'search_id' => '01906c9e-7e3f-7000-8000-abc123def456',
        'market'    => 'fr-fr',
        'offset'    => 20,
        'enriched'  => false,
    ]);
});

it('reports an empty result set', function () {
    $result = harness([], okBody([]))->call(['query' => 'vector db']);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('No results found.');
});

it('reports a modified body without a results key instead of crashing', function () {
    $result = harness([], ['query' => ['q' => 'x']])->call(['query' => 'vector db']);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('No results found.');
});

it('truncates the result list to the configured limit and says so', function () {
    $results = array_map(static fn(int $i): array => aResult(['title' => "Page {$i}"]), range(1, 10));

    $result = harness(['result_limit' => '3'], okBody($results))->call(['query' => 'vector db']);

    expect($result->content)->toContain("[1] Page 1")
        ->and($result->content)->toContain("[3] Page 3")
        ->and($result->content)->not->toContain('[4] Page 4')
        ->and($result->content)->toContain('Note: Showing the first 3 of 10 results.');
});

it('does not warn about truncation when every result fits', function () {
    $result = harness(['result_limit' => '10'])->call(['query' => 'vector db']);

    expect($result->content)->not->toContain('Note:');
});

/* ------------------------------------------------------------- enrichment -- */

it('renders scored excerpts and labels the reranked ordering', function () {
    $body = okBody([aResult([
        'extra_snippets' => [
            ['chunk' => 'Qdrant is fully self-hosted.', 'score' => 0.91234],
            ['chunk' => 'Weaviate ships a managed tier.', 'score' => 0.78],
        ],
    ])]);

    $result = harness([], $body)->call(['query' => 'vector db', 'action' => 'enriched_search']);

    expect($result->content)->toContain("Staan enriched results for 'vector db' (ranked by excerpt relevance):")
        ->and($result->content)->toContain('  [0.91] Qdrant is fully self-hosted.')
        ->and($result->content)->toContain('  [0.78] Weaviate ships a managed tier.')
        ->and($result->data['enriched'])->toBeTrue();
});

it('truncates an oversized excerpt so one result cannot flood the context', function () {
    $body = okBody([aResult(['extra_snippets' => [
        ['chunk' => str_repeat('x', 2000), 'score' => 0.9],
    ]])]);

    $result = harness([], $body)->call(['query' => 'vector db', 'action' => 'enriched_search']);

    expect($result->content)->toContain('[truncated]')
        ->and(mb_strlen($result->content))->toBeLessThan(2000);
});

it('falls back to the plain snippet when a page could not be extracted', function () {
    $body = okBody([
        aResult(['extra_snippets' => [['chunk' => 'Scored text.', 'score' => 0.9]]]),
        aResult(['title' => 'Blocked page', 'extra_snippets' => []]),
    ]);

    $result = harness([], $body)->call(['query' => 'vector db', 'action' => 'enriched_search']);

    expect($result->content)->toContain('[2] Blocked page')
        ->and($result->content)->toContain('Snippet: A deep dive into Pinecone')
        ->and($result->content)->toContain('(no page excerpt available for this result')
        ->and($result->content)->toContain('Note: 1 of 2 result pages could not be extracted');
});

it('drops the fallback notice entirely when every page was extracted', function () {
    $body = okBody([aResult(['extra_snippets' => [['chunk' => 'Scored.', 'score' => 0.9]]])]);

    $result = harness([], $body)->call(['query' => 'vector db', 'action' => 'enriched_search']);

    expect($result->content)->not->toContain('could not be extracted');
});

it('tolerates a missing extra_snippets key entirely', function () {
    $result = harness([], okBody([aResult()]))
        ->call(['query' => 'vector db', 'action' => 'enriched_search']);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('(no page excerpt available')
        ->and($result->content)->toContain('Note: 1 of 1 result pages could not be extracted');
});

/* ------------------------------------------------------------------ notes -- */

it('reports a rewritten query', function () {
    $body = okBody([aResult()], ['altered_query' => 'vector database comparison']);

    $result = harness([], $body)->call(['query' => 'vector db']);

    expect($result->content)->toContain('Note: The search engine rewrote the query to "vector database comparison"');
});

it('stays quiet when the rewritten query is identical to the request', function () {
    $body = okBody([aResult()], ['altered_query' => 'vector db']);

    $result = harness([], $body)->call(['query' => 'vector db']);

    expect($result->content)->not->toContain('rewrote the query');
});

/* ----------------------------------------------------------------- errors -- */

it('surfaces an upstream 5xx as a failure with the status', function () {
    $result = harness([], null, 503, 'upstream down')->call(['query' => 'vector db']);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('HTTP 503')
        ->and($result->content)->toContain('Staan said: upstream down');
});

it('points an auth failure at the tool settings', function () {
    $result = harness([], null, 401, '{"error":"invalid key"}')->call(['query' => 'vector db']);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('rejected the API key (HTTP 401)')
        ->and($result->content)->toContain('tool settings');
});

it('explains a 429 in terms of the documented rate limit', function () {
    $result = harness([], null, 429, '')->call(['query' => 'vector db']);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('HTTP 429')
        ->and($result->content)->toContain('20 requests/second')
        ->and($result->content)->toContain('not fire several searches in parallel');
});

it('flattens and truncates a noisy error body', function () {
    $result = harness([], null, 400, str_repeat('detail ', 200))->call(['query' => 'vector db']);

    expect($result->content)->toContain('Staan said: detail detail')
        ->and(mb_strlen($result->content))->toBeLessThan(600);
});

it('omits the detail clause when the error body is empty', function () {
    $result = harness([], null, 400, '   ')->call(['query' => 'vector db']);

    expect($result->content)->toContain('Staan rejected the request (HTTP 400).')
        ->and($result->content)->not->toContain('Staan said');
});

it('never lets a transport exception escape', function () {
    $config = Mockery::mock(Spora\Services\ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $client = Mockery::mock(Symfony\Contracts\HttpClient\HttpClientInterface::class);
    $client->allows('request')->andThrow(new RuntimeException('connection reset'));

    $result = (new Spora\Plugins\Staan\Tools\StaanSearchTool($config, $client))
        ->execute(['query' => 'vector db'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Search tool error: connection reset');
});

/* ------------------------------------------------- review regression guards -- */

it('falls back to the default timeout when the setting is zero or negative', function (string $given) {
    $harness = harness(['http_timeout' => $given]);
    $harness->call(['query' => 'vector db']);

    expect($harness->sentTimeout())->toBe(30.0);
})->with(['zero' => '0', 'negative' => '-3']);

it('honours a real timeout setting', function () {
    $harness = harness(['http_timeout' => '45']);
    $harness->call(['query' => 'vector db']);

    expect($harness->sentTimeout())->toBe(45.0);
});

it('caps an absurd timeout setting at five minutes', function () {
    $harness = harness(['http_timeout' => '99999']);
    $harness->call(['query' => 'vector db']);

    expect($harness->sentTimeout())->toBe(300.0);
});

it('falls back to the configured market when the agent sends an empty string', function () {
    $harness = harness(['market' => 'de-de']);
    $harness->call(['query' => 'vektordatenbank', 'market' => '']);

    expect($harness->sentPayload()['market'])->toBe('de-de');
});

it('counts only the pages it actually rendered in the footer', function () {
    $results = array_map(static fn(int $i): array => aResult([
        'title'          => "Page {$i}",
        'extra_snippets' => $i === 1 ? [['chunk' => 'Extracted text.', 'score' => 0.9]] : [],
    ]), range(1, 5));

    // result_limit 2 means rows 3-5 are never fetched, so the footer must not
    // claim knowledge of them.
    $result = harness(['result_limit' => '2'], okBody($results))
        ->call(['query' => 'vector db', 'action' => 'enriched_search']);

    expect($result->content)->toContain('Note: 1 of 2 result pages could not be extracted')
        ->and($result->content)->not->toContain('1 of 5');
});

it('renders a string-keyed results object instead of failing the search', function () {
    $body = ['web' => ['results' => [
        'first'  => aResult(['title' => 'First']),
        'second' => aResult(['title' => 'Second']),
    ]]];

    $result = harness([], $body)->call(['query' => 'vector db']);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('[1] First')
        ->and($result->content)->toContain('[2] Second');
});

it('marks a page whose excerpts are all blank as unextracted', function () {
    $body = okBody([aResult(['extra_snippets' => [['chunk' => '   ']]])]);

    $result = harness([], $body)->call(['query' => 'vector db', 'action' => 'enriched_search']);

    expect($result->content)->toContain('(no page excerpt available for this result')
        ->and($result->content)->toContain('Note: 1 of 1 result pages could not be extracted');
});

it('treats a malformed extra_snippets shape as unextracted rather than extracted', function () {
    $body = okBody([aResult(['extra_snippets' => ['k' => 'v']])]);

    $result = harness([], $body)->call(['query' => 'vector db', 'action' => 'enriched_search']);

    expect($result->content)->toContain('(no page excerpt available for this result')
        ->and($result->content)->toContain('Note: 1 of 1 result pages could not be extracted');
});

it('never puts the api key in the structured result data', function () {
    $result = harness(['api_key' => 'staan_secret_key'])->call(['query' => 'vector db']);

    expect($result->data)->not->toContain('staan_secret_key')
        ->and($result->content)->not->toContain('staan_secret_key');
});

it('rejects a non-scalar query as empty instead of searching for the literal string "array"', function () {
    $harness = harness();
    $result = $harness->call(['query' => ['a']]);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('query cannot be empty')
        ->and($harness->requests)->toBe([]);
});

/* ---------------------------------------------------------------- describe -- */

it('describes each operation differently', function () {
    $harness = harness();

    expect($harness->tool->describeAction(['query' => 'vector db']))
        ->toBe("Search the web via Staan for: 'vector db'")
        ->and($harness->tool->describeAction(['query' => 'vector db', 'action' => 'enriched_search']))
        ->toBe("Search the web via Staan with scored page excerpts for: 'vector db'");
});
