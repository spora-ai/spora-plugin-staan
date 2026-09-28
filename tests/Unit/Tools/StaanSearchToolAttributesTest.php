<?php

declare(strict_types=1);

use Mockery as M;
use Spora\Plugins\Staan\Tools\StaanSearchTool;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Guards the wire contract the LLM and the operator UI depend on.
 *
 * Read via reflection rather than `ToolParameterSchemaBuilder` so the suite
 * stays independent of which spora-core version is installed.
 */
function staanAttributes(string $attribute): array
{
    return array_map(
        static fn(ReflectionAttribute $found): object => $found->newInstance(),
        (new ReflectionClass(StaanSearchTool::class))->getAttributes($attribute),
    );
}

function staanAttribute(string $attribute, string $key): mixed
{
    $match = $attribute === ToolSetting::class ? 'key' : 'name';

    foreach (staanAttributes($attribute) as $instance) {
        if ($instance->{$match} === $key) {
            return $instance;
        }
    }

    return null;
}

function staanTool(): StaanSearchTool
{
    return new StaanSearchTool(
        M::mock(ToolConfigService::class),
        M::mock(HttpClientInterface::class),
    );
}

/**
 * The prose guides advertise only fr-fr / en-us / de-de. The v2 API reference
 * enum is wider and the docs site collapses its tail behind "show 4 more", so
 * this list was recovered from the reference page source.
 */
const STAAN_MARKETS = [
    'fr-fr', 'de-de',
    'en-us', 'en-gb', 'en-ie', 'en-fr',
    'en-ca', 'en-au', 'en-nz', 'en-in', 'en-sg', 'en-za',
];

it('exposes exactly two operations, with search as the fallback', function () {
    $names = array_map(static fn(object $op): string => $op->name, staanAttributes(ToolOperation::class));

    expect(staanTool()->getOperationName([]))->toBe('search')
        ->and(staanTool()->getOperationName(['action' => 'enriched_search']))->toBe('enriched_search')
        ->and($names)->toBe(['search', 'enriched_search']);
});

it('runs both operations without approval and enables them by default', function () {
    foreach (['search', 'enriched_search'] as $name) {
        $op = staanAttribute(ToolOperation::class, $name);

        expect($op)->toBeInstanceOf(ToolOperation::class)
            ->and($op->enabledByDefault)->toBeTrue()
            ->and($op->requiresApprovalByDefault)->toBeFalse()
            ->and($op->discriminatorKey)->toBe('action');
    }
});

it('does not declare a hand-rolled action parameter', function () {
    expect(staanAttribute(ToolParameter::class, 'action'))->toBeNull();
});

it('names the tool search so the wire name is staan:search', function () {
    $tool = staanAttributes(Tool::class)[0];

    expect($tool->name)->toBe('search')
        ->and($tool->displayName)->toBe('Staan Search')
        ->and($tool->category)->toBe('research')
        ->and($tool->icon)->toBe('search');
});

it('declares exactly the six settings in ui order', function () {
    $keys = array_map(static fn(object $s): string => $s->key, staanAttributes(ToolSetting::class));

    expect($keys)->toBe(['api_key', 'market', 'min_score', 'max_snippets', 'result_limit', 'http_timeout']);
});

it('requires only the api key', function () {
    expect(staanAttribute(ToolSetting::class, 'api_key')->required)->toBeTrue()
        ->and(staanAttribute(ToolSetting::class, 'api_key')->type)->toBe('password');

    foreach (['market', 'min_score', 'max_snippets', 'result_limit', 'http_timeout'] as $key) {
        expect(staanAttribute(ToolSetting::class, $key)->required)->toBeFalse();
    }
});

it('offers the market dropdown over every market in the api reference', function () {
    $market = staanAttribute(ToolSetting::class, 'market');

    expect($market->type)->toBe('select')
        ->and(array_keys($market->options))->toBe(STAAN_MARKETS)
        ->and($market->default)->toBe('fr-fr')
        ->and($market->exposeToLlm)->toBeTrue();
});

it('never exposes a credential to the llm', function () {
    expect(staanAttribute(ToolSetting::class, 'api_key')->exposeToLlm)->toBeFalse();
});

it('ships the retrieval-tuning dials with the documented defaults', function () {
    expect(staanAttribute(ToolSetting::class, 'min_score')->default)->toBe('0.2')
        ->and(staanAttribute(ToolSetting::class, 'max_snippets')->default)->toBe('3')
        ->and(staanAttribute(ToolSetting::class, 'result_limit')->default)->toBe('10')
        ->and(staanAttribute(ToolSetting::class, 'http_timeout')->default)->toBeNull();
});

it('declares query as the only required parameter, in payload order', function () {
    $names = array_map(static fn(object $p): string => $p->name, staanAttributes(ToolParameter::class));

    expect($names)->toBe(['query', 'market', 'offset', 'max_snippets'])
        ->and(staanAttribute(ToolParameter::class, 'query')->required)->toBeTrue();

    foreach (['market', 'offset', 'max_snippets'] as $name) {
        expect(staanAttribute(ToolParameter::class, $name)->required)->toBeFalse();
    }
});

it('mirrors the market enum on the parameter and the setting', function () {
    expect(staanAttribute(ToolParameter::class, 'market')->enum)->toBe(STAAN_MARKETS)
        ->and(array_keys(staanAttribute(ToolSetting::class, 'market')->options))->toBe(STAAN_MARKETS);
});

it('tells the agent the excerpt parameter can only lower the ceiling', function () {
    $description = staanAttribute(ToolParameter::class, 'max_snippets')->description;

    expect(staanAttribute(ToolParameter::class, 'max_snippets')->type)->toBe('integer')
        ->and(staanAttribute(ToolParameter::class, 'max_snippets')->minimum)->toBe(1)
        ->and(staanAttribute(ToolParameter::class, 'max_snippets')->maximum)->toBe(10)
        ->and($description)->toContain('only LOWER')
        ->and($description)->toContain('never granted');
});

it('marks the excerpt setting as a ceiling, not a fixed value', function () {
    $setting = staanAttribute(ToolSetting::class, 'max_snippets');

    expect($setting->label)->toContain('ceiling')
        ->and($setting->description)->toContain('ceiling')
        ->and($setting->description)->toContain('never more');
});

it('bounds the offset parameter to the api page window', function () {
    $offset = staanAttribute(ToolParameter::class, 'offset');

    expect($offset->type)->toBe('integer')
        ->and($offset->minimum)->toBe(0)
        ->and($offset->maximum)->toBe(30);
});

it('documents the site operators and the length limit in the query description', function () {
    $description = staanAttribute(ToolParameter::class, 'query')->description;

    expect($description)->toContain('site:')
        ->and($description)->toContain('-site:')
        ->and($description)->toContain('400');
});

it('tells the agent that enriched results are reranked, not serp-ordered', function () {
    $description = staanAttribute(ToolOperation::class, 'enriched_search')->description;

    expect($description)->toContain('RERANKED')
        ->and($description)->toContain('not raw search-engine order')
        ->and($description)->toContain('anti-bot');
});
