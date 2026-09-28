<?php

declare(strict_types=1);

namespace Spora\Plugins\Staan\Tests\Support;

use Mockery as M;
use Spora\Plugins\Staan\Tools\StaanSearchTool;
use Spora\Services\ToolConfigService;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds a StaanSearchTool wired to a canned HTTP response.
 *
 * `MockHttpClient` matches on the request URL only, so the recorded
 * `$request` closure is where payload assertions live — the tool builds one
 * request per call, so the array always has exactly one element.
 */
final class StaanToolHarness
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    public array $requests = [];

    public ToolConfigService $config;

    public StaanSearchTool $tool;

    /**
     * @param array<string, mixed> $settings    effective settings returned by ToolConfigService
     * @param array<string, mixed>|null $body    decoded JSON response body, or null to build an error
     * @param int $status                        HTTP status; 2xx ignores $body
     * @param string $rawBody                    raw error body when $status >= 400
     */
    public function __construct(
        array $settings,
        ?array $body = null,
        int $status = 200,
        string $rawBody = '',
    ) {
        $this->config = M::mock(ToolConfigService::class);
        $this->config->allows('getEffectiveSettings')
            ->with(StaanSearchTool::class, M::any(), M::any())
            ->andReturn($settings);

        $json = $body === null ? [] : $body;
        $this->tool = new StaanSearchTool(
            $this->config,
            $this->client($status, $rawBody, $json),
        );
    }

    /**
     * @return array<string, mixed>|null the single recorded request's decoded JSON payload
     */
    public function sentPayload(): ?array
    {
        $body = $this->requests[0]['options']['body'] ?? null;
        if (!is_string($body) || $body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Symfony normalises request options before the response factory sees them,
     * so headers arrive flattened as `"Name: value"` strings.
     *
     * @return array<string, string>
     */
    public function sentHeaders(): array
    {
        $headers = $this->requests[0]['options']['headers'] ?? [];
        if (!is_array($headers)) {
            return [];
        }

        $parsed = [];
        foreach ($headers as $line) {
            if (!is_string($line) || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $parsed[trim($name)] = trim($value);
        }

        return $parsed;
    }

    public function sentTimeout(): ?float
    {
        $timeout = $this->requests[0]['options']['timeout'] ?? null;

        return is_int($timeout) || is_float($timeout) ? (float) $timeout : null;
    }

    public function call(array $arguments): ToolResult
    {
        return $this->tool->execute($arguments, 1);
    }

    /**
     * @param array<string, mixed> $json
     */
    private function client(int $status, string $rawBody, array $json): HttpClientInterface
    {
        $capture = $this;

        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use ($status, $rawBody, $json, $capture): MockResponse {
            $capture->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            if ($status >= 400) {
                return new MockResponse($rawBody, ['http_code' => $status]);
            }

            return new MockResponse(
                json_encode($json, JSON_THROW_ON_ERROR),
                ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']],
            );
        });

        return $mock;
    }
}
