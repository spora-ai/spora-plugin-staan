<?php

declare(strict_types=1);

namespace Spora\Plugins\Staan;

use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\Staan\Tools\StaanSearchTool;

/**
 * Plugin entry point for the Staan web search integration.
 */
final class StaanPlugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'Staan';
    }

    /** @return array<class-string<\Spora\Tools\ToolInterface>> */
    public function tools(): array
    {
        return [
            StaanSearchTool::class,
        ];
    }

    /**
     * Ships the `staan-search` skill, which teaches the agent when to reach for
     * `enriched_search` over `search` and how to render the returned URLs.
     *
     * @return string[]
     */
    public function skillPaths(): array
    {
        return [
            __DIR__ . '/../skills',
        ];
    }
}
