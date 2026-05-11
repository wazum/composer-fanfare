<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Animation;

/**
 * @internal
 */
enum Animation: string
{
    case Typewriter = 'typewriter';
    case Shimmer = 'shimmer';
    case Drip = 'drip';

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function newRenderer(): AnimationRenderer
    {
        return match ($this) {
            self::Typewriter => new TypewriterAnimation(),
            self::Shimmer => new ShimmerAnimation(),
            self::Drip => new DripAnimation(),
        };
    }
}
