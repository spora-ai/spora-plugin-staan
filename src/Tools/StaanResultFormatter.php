<?php

declare(strict_types=1);

namespace Spora\Plugins\Staan\Tools;

/**
 * Renders a decoded Staan response into the agent-facing text block.
 *
 * Separate from {@see StaanSearchTool} because rendering is pure and has a
 * different failure surface: the whole job is to survive whatever shape the
 * upstream returned and still tell the agent what it does and does not know.
 *
 * Two invariants this class exists to hold:
 *
 *  1. **Never claim to have looked at something it did not.** The footer's
 *     "N of M result pages" counts only the rows actually rendered, and only
 *     when the list was truncated does it reference the full upstream total.
 *  2. **Never leave a silent gap.** A page whose excerpts are missing, empty,
 *     or unrenderable gets a visible marker and a count — never a row that
 *     looks complete but carries no page text.
 */
final class StaanResultFormatter
{
    /**
     * Per-excerpt cap. Staan's chunks run 300-1800 characters; 800 keeps the
     * opening of a typical chunk and bounds the worst case at
     * result_limit x max_snippets x 800 characters of injected context.
     */
    private const MAX_CHUNK_CHARS = 800;

    private const NO_EXCERPT_NOTICE = '(no page excerpt available for this result — the plain snippet is shown instead)';

    private const NOTE_PREFIX = 'Note: ';

    /**
     * @param array<string, mixed> $data decoded response body
     */
    public function format(array $data, bool $enriched, StaanFormatContext $context): string
    {
        $results = $this->extractResults($data);

        // array_values: a string-keyed `results` object must still render rather
        // than blow up when the loop reaches `$index + 1`.
        $shown = array_slice(array_values($results), 0, $context->resultLimit);

        $heading = $enriched
            ? "Staan enriched results for '{$context->query}' (ranked by excerpt relevance):"
            : "Staan web results for '{$context->query}':";

        if ($shown === []) {
            return "{$heading}\n\nNo results found.\n";
        }

        $output = "{$heading}\n\n";
        $withoutExcerpts = 0;

        foreach ($shown as $index => $result) {
            // Whether a page has usable excerpts is decided by what actually
            // renders, not by whether `extra_snippets` happens to be a
            // non-empty array — a list of blank chunks has no more to offer
            // than a missing key, and must not be reported as extracted.
            $excerpts = $enriched ? $this->formatExcerpts($result) : '';
            if ($enriched && $excerpts === '') {
                $withoutExcerpts++;
                $excerpts = self::NO_EXCERPT_NOTICE . "\n";
            }

            $output .= $this->formatRow($result, $index + 1, $excerpts);
        }

        return $output . $this->formatFooter(
            $data,
            $enriched,
            $withoutExcerpts,
            count($shown),
            count($results),
            $context,
        );
    }

    /**
     * Deliberately not typed as a list: upstream may hand back a string-keyed
     * `results` object, and the caller is what reindexes it.
     *
     * @param  array<string, mixed> $data
     * @return array<array-key, mixed>
     */
    private function extractResults(array $data): array
    {
        $web = $data['web'] ?? null;
        if (!is_array($web) || !is_array($web['results'] ?? null)) {
            return [];
        }

        return $web['results'];
    }

    /**
     * @param mixed $result
     */
    private function formatRow(mixed $result, int $position, string $excerpts): string
    {
        $row = is_array($result) ? $result : [];

        $output = sprintf("[%d] %s\n", $position, $this->text($row['title'] ?? null) ?: '(untitled)');

        foreach ([
            'URL'      => $row['url'] ?? null,
            'Host'     => $row['hostname'] ?? null,
            'Published' => $row['published_date'] ?? null,
            'Snippet'  => $row['snippet'] ?? null,
        ] as $label => $value) {
            $text = $this->text($value);
            if ($text !== '') {
                $output .= "{$label}: {$text}\n";
            }
        }

        return $output . $excerpts . "\n";
    }

    /**
     * @param mixed $result
     */
    private function formatExcerpts(mixed $result): string
    {
        if (!is_array($result)) {
            return '';
        }

        $chunks = $result['extra_snippets'] ?? null;
        if (!is_array($chunks)) {
            return '';
        }

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
     */
    private function formatFooter(
        array $data,
        bool $enriched,
        int $withoutExcerpts,
        int $rendered,
        int $total,
        StaanFormatContext $context,
    ): string {
        $notes = [];

        $queryMeta = is_array($data['query'] ?? null) ? $data['query'] : [];
        $altered = $this->text($queryMeta['altered_query'] ?? null);
        if ($altered !== '' && $altered !== $context->query) {
            $notes[] = "The search engine rewrote the query to \"{$altered}\" — the results answer that, not the text you sent.";
        }

        if ($enriched && $withoutExcerpts > 0) {
            $notes[] = sprintf(
                '%d of %d result pages could not be extracted (anti-bot wall, timeout, or no excerpt above the minimum score); their plain snippet is shown instead. Consider a differently-worded query, or `search` for the raw result list.',
                $withoutExcerpts,
                $rendered,
            );
        }

        if ($total > $context->resultLimit) {
            $notes[] = sprintf(
                'Showing the first %d of %d results. Raise the "Results returned to the agent" setting or pass a higher `offset` to see the rest.',
                $context->resultLimit,
                $total,
            );
        }

        if ($context->offsetAdjusted) {
            // States the cap as a fact without claiming this call hit it, so
            // the same sentence is honest for both page-alignment and clamping.
            $notes[] = sprintf(
                'Offset was adjusted to page %d — Staan pages in blocks of 10, and the last page is 30 (40 results).',
                $context->offset,
            );
        }

        if ($notes === []) {
            return '';
        }

        return "\n" . implode("\n", array_map(
            static fn(string $note): string => self::NOTE_PREFIX . $note,
            $notes,
        )) . "\n";
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function truncate(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_CHUNK_CHARS) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, self::MAX_CHUNK_CHARS)) . '… [truncated]';
    }
}
