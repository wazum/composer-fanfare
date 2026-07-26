<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Tests\Animation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wazum\ComposerFanfare\Animation\Animation;
use Wazum\ComposerFanfare\Animation\DripAnimation;
use Wazum\ComposerFanfare\Animation\ShimmerAnimation;
use Wazum\ComposerFanfare\Animation\TypewriterAnimation;

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

    #[Test]
    public function onlyForwardWritingTypewriterDoesNotRedrawInPlace(): void
    {
        self::assertFalse(Animation::Typewriter->redrawsInPlace());
        self::assertTrue(Animation::Shimmer->redrawsInPlace());
        self::assertTrue(Animation::Drip->redrawsInPlace());
    }
}
