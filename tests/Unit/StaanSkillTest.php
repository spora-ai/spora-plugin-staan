<?php

declare(strict_types=1);

use Spora\Plugins\Staan\StaanPlugin;

function staanSkillFile(): string
{
    return (new StaanPlugin())->skillPaths()[0] . '/staan-search/SKILL.md';
}

/**
 * @return array<string, string>
 */
function staanSkillFrontmatter(string $file): array
{
    $contents = (string) file_get_contents($file);
    if (!preg_match('/\A---\R(.*?)\R---/s', $contents, $matches)) {
        return [];
    }

    $fields = [];
    foreach (explode("\n", $matches[1]) as $line) {
        if (preg_match('/^([a-z-]+):\s*(.*)$/', $line, $pair) === 1) {
            $fields[$pair[1]] = trim($pair[2], " \t\"'");
        }
    }

    return $fields;
}

it('ships the skill on disk', function () {
    expect(is_file(staanSkillFile()))->toBeTrue();
});

it('declares a frontmatter name matching its directory', function () {
    $frontmatter = staanSkillFrontmatter(staanSkillFile());

    expect($frontmatter['name'] ?? null)->toBe('staan-search');
});

it('carries a description long enough for skill discovery', function () {
    $description = staanSkillFrontmatter(staanSkillFile())['description'] ?? '';

    expect(mb_strlen($description))->toBeGreaterThan(80)
        ->and($description)->toContain('search')
        ->and($description)->toContain('enriched_search');
});

it('scopes allowed-tools to the search tool only', function () {
    $allowed = staanSkillFrontmatter(staanSkillFile())['allowed-tools'] ?? '';

    expect($allowed)->toBe('search');
});

it('documents both operations and the site operators', function () {
    $skill = (string) file_get_contents(staanSkillFile());

    expect($skill)->toContain('action: "search"')
        ->and($skill)->toContain('action: "enriched_search"')
        ->and($skill)->toContain('site:');
});
