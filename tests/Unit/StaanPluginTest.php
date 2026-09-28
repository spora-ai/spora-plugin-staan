<?php

declare(strict_types=1);

namespace Spora\Plugins\Staan\Tests\Unit;

use Spora\Plugins\Staan\StaanPlugin;
use Spora\Plugins\Staan\Tools\StaanSearchTool;

it('is named Staan', function () {
    expect((new StaanPlugin())->getName())->toBe('Staan');
});

it('registers only the search tool', function () {
    expect((new StaanPlugin())->tools())->toBe([StaanSearchTool::class]);
});

it('points the skill scanner at the bundled skills directory', function () {
    $paths = (new StaanPlugin())->skillPaths();

    expect($paths)->toHaveCount(1)
        ->and(is_dir($paths[0]))->toBeTrue()
        ->and(is_file($paths[0] . '/staan-search/SKILL.md'))->toBeTrue();
});

it('ships no agent templates', function () {
    expect((new StaanPlugin())->agentTemplatePaths())->toBe([]);
});
