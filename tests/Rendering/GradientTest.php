<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Tests\Rendering;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wazum\ComposerFanfare\Rendering\Gradient;

final class GradientTest extends TestCase
{
    #[Test]
    public function singleStopAlwaysReturnsThatStop(): void
    {
        self::assertSame([255, 100, 0], Gradient::sample([[255, 100, 0]], 0.0));
        self::assertSame([255, 100, 0], Gradient::sample([[255, 100, 0]], 0.5));
        self::assertSame([255, 100, 0], Gradient::sample([[255, 100, 0]], 1.0));
    }

    #[Test]
    public function fractionZeroReturnsFirstStop(): void
    {
        self::assertSame([10, 20, 30], Gradient::sample([[10, 20, 30], [200, 100, 50]], 0.0));
    }

    #[Test]
    public function fractionOneReturnsLastStop(): void
    {
        self::assertSame([200, 100, 50], Gradient::sample([[10, 20, 30], [200, 100, 50]], 1.0));
    }

    #[Test]
    public function midpointInterpolatesEvenlyBetweenTwoStops(): void
    {
        self::assertSame([105, 60, 40], Gradient::sample([[10, 20, 30], [200, 100, 50]], 0.5));
    }

    #[Test]
    public function fractionOutsideZeroOneIsClamped(): void
    {
        $stops = [[10, 20, 30], [200, 100, 50]];
        self::assertSame([10, 20, 30], Gradient::sample($stops, -0.5));
        self::assertSame([200, 100, 50], Gradient::sample($stops, 1.5));
    }

    #[Test]
    public function multiStopGradientPicksTheRightSegment(): void
    {
        // 3 stops, so segments are 0..0.5 (first → second) and 0.5..1.0 (second → third).
        $stops = [[0, 0, 0], [100, 100, 100], [200, 200, 200]];
        // Sample at 0.25 → midpoint of first segment → halfway between stop 0 and 1 → [50,50,50].
        self::assertSame([50, 50, 50], Gradient::sample($stops, 0.25));
        // Sample at 0.75 → midpoint of second segment → halfway between stop 1 and 2 → [150,150,150].
        self::assertSame([150, 150, 150], Gradient::sample($stops, 0.75));
    }

    #[Test]
    public function lerpRgbReturnsStartWhenTIsZero(): void
    {
        self::assertSame([10, 20, 30], Gradient::lerpRgb([10, 20, 30], [200, 100, 50], 0.0));
    }

    #[Test]
    public function lerpRgbReturnsEndWhenTIsOne(): void
    {
        self::assertSame([200, 100, 50], Gradient::lerpRgb([10, 20, 30], [200, 100, 50], 1.0));
    }

    #[Test]
    public function lerpRgbInterpolatesAndRoundsToInteger(): void
    {
        // 0.4 between [10, 20, 30] and [20, 30, 40]: [14, 24, 34]
        self::assertSame([14, 24, 34], Gradient::lerpRgb([10, 20, 30], [20, 30, 40], 0.4));
    }
}
