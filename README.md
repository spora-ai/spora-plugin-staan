# spora-plugin-staan

Spora plugin: EU-hosted web search via [Staan](https://staan.ai), the
Qwant-powered search API. One tool, two operations — a fast ranked result
list, or the same search enriched with relevance-scored excerpts of the
actual page text.

The full reference (install, configuration, per-tool parameters, development)
lives on the docs site:

**[docs.spora-ai.com/develop/plugins/reference/staan](https://docs.spora-ai.com/develop/plugins/reference/staan)**

## At a glance

| | |
| --- | --- |
| Tool (LLM wire name) | `staan:search` |
| Operations | `search` (default), `enriched_search` |
| Endpoint | `POST https://api.staan.ai/v2/search/web` |
| Auth | `Authorization: Bearer <key>` — 1,000 requests/month free |
| Markets | `fr-fr` (default), `en-us`, `de-de` |
| Rate limit | 20 req/s; 10 results per page, max 4 pages (offset 0–30) |

Both operations hit the same endpoint — Staan switches on enrichment by
accepting `extra_snippets` in the payload, not by exposing a second URL.

## Translations

This plugin ships a German catalogue at [`locales/de.json`](locales/de.json),
declared through `translationsPaths()` in `src/StaanPlugin.php`. The host
merges it under core's own catalogue, so a German install reads the plugin's
operator copy in German.

The **key is the English source string**, byte for byte — editing the English
in the source changes the key, so the old entry stops matching and the new
English has no entry. The gate is this repository's own
`tests/Unit/LocaleCatalogTest.php`: it fails on an empty translation, on a
translation identical to its key, and on a key the source no longer produces.

**Translated**

- the tool's display name — `Staan Search`
- the six `#[ToolSetting]` descriptions
- the two approval prompts the tool returns from `describeAction()`
- the `plugin.json` description

The `market` setting's option labels name the languages and regions and stay
verbatim; they are option labels, and an option label is never translated.

**Never translated** — these strings are read by the model, so the operator
sees exactly what the model sees:

- `#[Tool].description`
- `#[ToolOperation].description`
- `#[ToolParameter].description`
- every `#[ToolSetting].label` and its option labels, including the labels of
  settings that carry `exposeToLlm: true` — `market` is one of them

An approval prompt is translatable only in the `%s` form. The host looks the
stored, already-rendered sentence up and matches it against the catalogue's
`%s` / `%d` templates, so the key is the `sprintf()` template —
`"Search the web via Staan for: '%s'"` — and never the rendered text. A
`{$query}` interpolation has no template to match and never resolves.

See the [plugin author guide → Translations](https://docs.spora-ai.com/develop/plugins/author-guide/translations).

## Local development

```bash
composer install
./vendor/bin/pest                                    # 69 tests
./vendor/bin/phpstan analyse --no-progress           # level 5, must be 0 errors
./vendor/bin/php-cs-fixer fix --dry-run --diff       # same ruleset as spora-core
```

## CI

`.github/workflows/ci.yml` runs Pest on PHP 8.4 + 8.5, PHPStan level 5, and
php-cs-fixer dry-run. A `coverage` job produces the clover report and a `sonar`
job uploads it to SonarCloud (project key `spora-ai_spora-plugin-staan`) so the
`new_coverage` metric is measurable per PR. The `sonar` job needs the
`SONAR_TOKEN` secret in the repo.

## Publishing

Tag the release; the runtime reads the version from the git tag via
`Composer\InstalledVersions::getPrettyVersion()`, so the tag is the single
source of truth.

```bash
git tag v0.1.0 && git push --tags
```

---

**API docs:** <https://docs.staan.ai/introduction> · **MIT**
