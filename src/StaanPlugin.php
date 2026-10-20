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

    /**
     * Translation catalogues this plugin ships, declared through
     * {@see \Spora\Plugins\PluginInterface::translationsPaths()}.
     *
     * Keys are the English source text — `de.json` reads
     * `{"Staan Search": "Staan-Suche"}`, not `{"tool": "…"}`. That is what
     * makes the host's `i18n:check` able to fail when a string is edited
     * without being re-translated: the key changes and the old entry
     * orphans.
     *
     * **Only operator copy belongs in here.** A string the model reads is
     * never translated, which for this plugin means `#[Tool].description`,
     * every `#[ToolOperation].description`, every `#[ToolParameter].description`,
     * every `#[ToolSetting].label` and its `options`, and all `ToolResult`
     * content. `locales/de.json` holds the `#[Tool].displayName` and the
     * six `#[ToolSetting].description` values and nothing else.
     *
     * Requires `spora-core >= 0.31.0` at runtime; older versions fail to
     * resolve the method when the loader is strict.
     *
     * @return string[]
     */
    public function translationsPaths(): array
    {
        return [
            __DIR__ . '/../locales',
        ];
    }
}
