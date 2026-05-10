<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare;

/**
 * @internal
 */
final readonly class AnimationContext
{
    /**
     * @param list<string>                              $lines       raw banner rows (no color escapes)
     * @param list<string>                              $styledLines pre-colored rows produced by the static path
     * @param non-empty-list<array{int, int, int}>|null $stops       gradient RGB stops, or null when uncolored
     */
    public function __construct(
        public array $lines,
        public array $styledLines,
        public ?array $stops,
        public Direction $direction,
        public ColorSupport $colorSupport,
        public bool $useColor,
        public ?string $statusLine,
    ) {
    }
}
