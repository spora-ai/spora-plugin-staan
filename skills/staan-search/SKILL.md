---
name: staan-search
description: Use the Staan web search plugin — one tool, two operations, `search` for a fast ranked result list and `enriched_search` for relevance-scored excerpts of the actual page text. Use when the user asks to "search", "look up", "find online", "check the web", "what's the latest on", or anything that needs a current fact, a source to cite, or a quotation from a real page.
license: MIT
compatibility: spora>=0.7 spora-plugin-staan>=0.1
metadata:
  author: spora-ai
  version: "1.0"
allowed-tools: staan_search
---

# Staan search

Staan returns a numbered list per call. Your job is to render each row by
turning its `URL:` line into a markdown link and keeping every other line
verbatim. Two operations differ in what you get back and in what you may
claim from it.

## Calling

```
search(action: "search", query: "<query>")
search(action: "enriched_search", query: "<query>")
```

`query` is the only required parameter. Pick the operation deliberately.

| Need | Operation | What you get |
|------|-----------|--------------|
| A fact, a link, "who/when/where" | `search` | title, URL, snippet, date |
| A **quotation**, a number to cite, a summary of a specific page | `enriched_search` | the above **plus** scored excerpts of the page body |
| Niche or long-tail topic where snippets read as teasers | `enriched_search` | excerpts usually settle it |

**Default to `search`.** It is a plain SERP, it is fast, and it is cheap. Reach
for `enriched_search` only when you intend to actually quote or synthesise from
page text — it fetches every result page, so it costs more and takes longer.

`action` is optional; omitting it gives you `search`. Pass
`action: "enriched_search"` explicitly.

### Optional parameters

- `market` — `fr-fr` · `de-de` · `en-us` · `en-gb` · `en-ie` · `en-fr` ·
  `en-ca` · `en-au` · `en-nz` · `en-in` · `en-sg` · `en-za`. Only pass it when
  the operator's configured market is actually wrong for the question — e.g.
  `en-gb` instead of `en-us` for UK sources, or `en-fr` for English-language
  pages hosted in France. Do not pass it to restate the configured value.
- `offset` — `0` · `10` · `20` · `30` for results past the first ten. `30` is
  the hard ceiling (40 results). Use it only after the first page was not
  enough; do not page speculatively.
- `max_snippets` — fewer scored excerpts per page, `1`–`10`. **This can only
  lower the operator's configured ceiling, never raise it.** Use it when
  context is tight and you only need the best passage: pass `1` when you plan
  to quote a single quote per page, `2` for a couple. Asking for more than the
  ceiling is silently capped, so do not bother trying — and do not report that
  you got more than you asked for.

### Writing the query

- **Keyword-style, not a sentence.** The tool rejects anything over 400
  characters. `"vector database pricing"` beats `"Could you please suggest a
  good vector database for a small team with a limited budget?"`.
- **Use the `site:` operators** — the tool passes them straight through, and
  they are the cheapest way to ground a query:
  - include: `"qdrant vs weaviate site:qdrant.tech OR site:weaviate.io"`
  - exclude: `"best practices -site:reddit.com -site:pinterest.com"`

## What the two operations mean for your claims

This is the part that matters for correctness.

- **`search`** returns results in **raw search-engine order**. `[1]` is the
  top-ranked page. The `Snippet:` line is the search provider's preview — it is
  *not* a quotation from the page. Do not put it in quotation marks or
  attribute it as something the author wrote.

- **`enriched_search`** returns results in **reranked order**: every page is
  fetched, split into chunks, scored against the query, and the pages with the
  best chunks are promoted. `[1]` is the most on-topic page, **not** the
  top-ranked one. Say so — never describe `[1]` as "the top result".

- Scored excerpt lines look like `  [0.91] <text>`. The number is a relevance
  score in `[0, 1]`, **not** a confidence percentage. Do not render it as
  "91% confident". You may quote excerpt text and attribute it to that row's
  `URL:` — that is the one thing `enriched_search` is for.

- A row carrying `(no page excerpt available for this result — the plain
  snippet is shown instead)` could not be extracted: anti-bot wall, timeout, or
  nothing above the minimum score. The `Snippet:` on that row is still the
  provider preview, still not a quotation. A trailing `Note:` line reports how
  many pages failed this way — if most of them did, the query was probably too
  narrow; reword it or fall back to `search`.

- A `Note:` line saying the engine **rewrote the query** means the results
  answer a different string than the one you sent. Mention the rewrite if it
  changes the answer; otherwise ignore it.

- A `Note:` line saying `Showing the first N of M results` means more results
  exist. Say that the list is truncated. Only page further with `offset` if the
  user actually asked for more.

## Markdown surface — what renders, what doesn't

Spora's chat surface is a plain markdown renderer.

- **Renders:** `[label](https://url)`, `![alt](https://image-url)`, bare
  `https://url`, bullet / numbered lists, headings, fenced code, blockquotes.
- **Stripped or silently dropped:** `<iframe>`, `<script>`, `data:` URLs,
  `javascript:` URLs, raw HTML attributes.

Never emit a stripped form, and never synthesise a host-specific embed URL
(YouTube `embed/`, Vimeo `player/`) from a watch URL — different hosts have
different schemes and a wrong one drops silently.

## Worked example — `enriched_search`

Tool output:

```
Staan enriched results for 'qdrant vs weaviate' (ranked by excerpt relevance):

[1] Comparing vector databases in 2026
URL: https://www.example.com/vector-dbs
Host: www.example.com
Published: 2026-04-10
Snippet: A deep dive into Pinecone, Weaviate, Qdrant...
  [0.91] Qdrant is fully self-hosted and exposes a full-text index.
  [0.78] Weaviate ships a managed cloud tier and an open-source kernel.

[2] Qdrant documentation
URL: https://qdrant.tech/documentation
Host: qdrant.tech
Snippet: Vector similarity search engine...
  (no page excerpt available for this result — the plain snippet is shown instead)
```

Rendered:

```markdown
### Qdrant vs Weaviate

**1. [Comparing vector databases in 2026](https://www.example.com/vector-dbs)**
www.example.com · 2026-04-10
> Qdrant is fully self-hosted and exposes a full-text index.
> Weaviate ships a managed cloud tier and an open-source kernel.

**2. [Qdrant documentation](https://qdrant.tech/documentation)**
qdrant.tech — page text unavailable, showing the search preview only
```

The two operations render identically; only `enriched_search` rows carry
blockquote quotes and a documentation-style line instead of a raw snippet.

## Rules

- **Only cite URLs the tool returned.** Never construct, shorten, or rewrite
  one. Never downgrade `https://` to `http://` or swap the host.
- **Quote excerpts, not snippets.** A `Snippet:` line is a search-provider
  preview; an excerpt line is page text. Only the second may be quoted or
  paraphrased as something the source says.
- **Preserve the `[N]` row numbers.** The user refers back to them ("tell me
  more about [3]"). Don't renumber, merge, or drop rows.
- **Don't paraphrase the numbers.** `score`, `Published:` dates, and result
  counts are reported as returned.
- **`No results found.` is the answer.** Relay it and stop — don't invent
  follow-up queries on your own initiative.
- **One search per user request** unless the user asked for several angles.
  Each call costs quota, and Staan allows only 20 requests/second.
- **Trim the excerpt count when you already have enough.** `max_snippets: 1`
  or `2` on a follow-up `enriched_search` is cheaper than re-reading a full
  result set you have already seen.
- **Never claim a fact the results do not contain.** If the excerpts do not
  answer the question, say the search came up short and offer a different
  query — do not fill the gap from memory without labelling it as your own
  knowledge.
