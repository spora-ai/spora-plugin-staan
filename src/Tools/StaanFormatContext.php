<?php

declare(strict_types=1);

namespace Spora\Plugins\Staan\Tools;

/**
 * Everything the formatter needs to know about the originating call, minus the
 * response itself. Keeps the formatter's signatures free of the tool's
 * prepared-state array shape, so a change to request building does not ripple
 * into rendering.
 */
final readonly class StaanFormatContext
{
    public function __construct(
        public string $query,
        public int $offset,
        public bool $offsetAdjusted,
        public int $resultLimit,
    ) {}
}
