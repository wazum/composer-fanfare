<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wazum\ComposerFanfare\Animation;
use Wazum\ComposerFanfare\DripAnimation;
use Wazum\ComposerFanfare\ShimmerAnimation;
use Wazum\ComposerFanfare\TypewriterAnimation;

final class AnimationTest extends TestCase
{
    #[Test]
    public function namesListsEachEnumValue(): void
    {
        self::assertSame(['typewriter', 'shimmer', 'drip'], Animation::names());
    }

    #[Test]
    public function typewriterCaseMapsToTypewriterAnimation(): void
    {
        self::assertInstanceOf(TypewriterAnimation::class, Animation::Typewriter->newRenderer());
    }

    #[Test]
    public function shimmerCaseMapsToShimmerAnimation(): void
    {
        self::assertInstanceOf(ShimmerAnimation::class, Animation::Shimmer->newRenderer());
    }

    #[Test]
    public function dripCaseMapsToDripAnimation(): void
    {
        self::assertInstanceOf(DripAnimation::class, Animation::Drip->newRenderer());
    }
}
