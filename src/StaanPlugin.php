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
     * makes a copy edit detectable: the key changes and the old entry
     * orphans. The gate is this plugin's own `LocaleCatalogTest`, not the
     * host's `i18n:check` — that command harvests core's `app/` and reads
     * core's `locales/en.json`, so run against a plugin repo it reports
     * every catalogue key as an orphan.
     *
     * **Only operator copy belongs in here.** A string the model reads is
     * never translated, which for this plugin means `#[Tool].description`,
     * every `#[ToolOperation].description`, every `#[ToolParameter].description`,
     * every `#[ToolSetting].label` and its `options`, and all `ToolResult`
     * content. `locales/de.json` holds the `#[Tool].displayName`, the six
     * `#[ToolSetting].description` values, the two approval prompts and
     * the manifest description, and nothing else.
     *
     * The approval prompts are keyed on their `sprintf()` templates, not
     * on the rendered sentences: the host looks the stored,
     * already-rendered string up and matches it against the catalogue's
     * templates, so `"Search the web via Staan for: '%s'"` is the key. A
     * `{$query}` interpolation has no template to match and never resolves.
     *
     * Requires `spora-core >= 0.31.0` for the catalogue to be loaded. On an
     * older core the host never calls this hook and the plugin renders in
     * English.
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
