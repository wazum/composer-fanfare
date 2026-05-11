<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wazum\ComposerFanfare\AnimationContext;
use Wazum\ComposerFanfare\ColorSupport;
use Wazum\ComposerFanfare\Direction;
use Wazum\ComposerFanfare\ShimmerAnimation;
use Wazum\ComposerFanfare\Tests\Support\RecordingAnimationDriver;

final class ShimmerAnimationTest extends TestCase
{
    #[Test]
    public function shimmerOnlyEmitsColorsThatAppearInTheStaticRender(): void
    {
        // 3 rows × 3 stops: static render lands on each stop exactly. Shimmer
        // must rotate those exact colors among rows, never sampling between.
        $stops = [[255, 0, 0], [0, 255, 0], [0, 0, 255]];

        $driver = new RecordingAnimationDriver();
        (new ShimmerAnimation())->animate(
            $this->context(['a', 'b', 'c'], stops: $stops),
            $driver,
        );

        $staticColors = [
            "\033[38;2;255;0;0m",
            "\033[38;2;0;255;0m",
            "\033[38;2;0;0;255m",
        ];
        foreach ($driver->events as $event) {
            if (!in_array($event['op'], ['write', 'writeLine'], true)) {
                continue;
            }
            if (preg_match_all('/\033\[38;2;\d+;\d+;\d+m/', (string) $event['value'], $matches)) {
                foreach ($matches[0] as $escape) {
                    self::assertContains(
                        $escape,
                        $staticColors,
                        "shimmer emitted {$escape} which never appears in the static render",
                    );
                }
            }
        }
    }

    #[Test]
    public function fullBannerAndStatusVisibleBeforeAnyCursorUpToAvoidFlicker(): void
    {
        $driver = new RecordingAnimationDriver();

        (new ShimmerAnimation())->animate(
            $this->context(['ab', 'cd'], stops: [[255, 0, 0], [0, 0, 255]], statusLine: 'demo · PHP'),
            $driver,
        );

        $linesBeforeFirstCursorUp = 0;
        $statusBeforeFirstCursorUp = false;
        foreach ($driver->events as $event) {
            if ('write' === $event['op'] && 1 === preg_match('/^\033\[\d+F$/', (string) $event['value'])) {
                break;
            }
            if ('writeLine' === $event['op']) {
                $value = (string) $event['value'];
                if (str_contains($value, 'demo · PHP')) {
                    $statusBeforeFirstCursorUp = true;
                }
                ++$linesBeforeFirstCursorUp;
            }
        }
        // 2 banner rows + 1 status line all written before the first cursor-up.
        self::assertSame(3, $linesBeforeFirstCursorUp);
        self::assertTrue($statusBeforeFirstCursorUp);
    }

    #[Test]
    public function emitsAtLeastTwoFramesOfTheBannerArea(): void
    {
        $driver = new RecordingAnimationDriver();

        (new ShimmerAnimation())->animate($this->context(['ab', 'cd'], stops: [[255, 0, 0], [0, 0, 255]]), $driver);

        $cursorUps = array_filter(
            $driver->events,
            static fn (array $event): bool => 'write' === $event['op']
                && str_starts_with((string) $event['value'], "\033[")
                && str_ends_with((string) $event['value'], 'F'),
        );
        self::assertGreaterThanOrEqual(1, count($cursorUps), 'shimmer must redraw at least once');
    }

    #[Test]
    public function cursorUpMovesByRowCountPlusOneWhenStatusLineIsPresent(): void
    {
        $driver = new RecordingAnimationDriver();

        // 3-line banner + status: between frames the cursor must move up 4 rows
        // to reach the top of the banner without trampling the status line.
        (new ShimmerAnimation())->animate(
            $this->context(
                ['a', 'b', 'c'],
                stops: [[255, 0, 0], [0, 255, 0]],
                statusLine: 'demo · PHP',
            ),
            $driver,
        );

        $cursorUps = array_values(array_filter(
            $driver->events,
            static fn (array $event): bool => 'write' === $event['op']
                && 1 === preg_match('/^\033\[\d+F$/', (string) $event['value']),
        ));
        self::assertNotEmpty($cursorUps);
        // First cursor-up after status: must lift the cursor over the status row too.
        self::assertSame("\033[4F", $cursorUps[0]['value']);
        // Subsequent cursor-ups stay within the banner area only.
        if (count($cursorUps) > 1) {
            self::assertSame("\033[3F", $cursorUps[1]['value']);
        }
    }

    #[Test]
    public function frameNearEndOfAnimationStillUsesShiftedPhaseNotTheSettleFallback(): void
    {
        // 5-row vertical, frameCount=6. The settle check is `$frame === 5`.
        // Mutation INC `-1 → -2` makes it `=== 4` instead, which would drop
        // frame 4 to phase 0 (static) instead of its expected shift-of-4.
        // Row 0 at shift 4 lands on static row 4 → blue. Static row 0 is red.
        $driver = new RecordingAnimationDriver();
        (new ShimmerAnimation())->animate(
            $this->context(
                ['a', 'b', 'c', 'd', 'e'],
                stops: [[255, 0, 0], [0, 0, 255]],
            ),
            $driver,
        );

        self::assertStringStartsWith("\033[38;2;0;0;255m", $this->frameNthFirstLine($driver, 4));
    }

    #[Test]
    public function horizontalFrameCountScalesWithVisibleCountAboveTheMinimumFloor(): void
    {
        // 5-char single-line horizontal: maxVisibleCount=5, cycleLength=5,
        // frameCount=6 → 5 cursor-ups. Mutation `$visible = -1` in
        // maxVisibleCount drops the count by 1, giving frameCount=5 → 4
        // cursor-ups. Catches the line-224 DEC.
        $driver = new RecordingAnimationDriver();
        (new ShimmerAnimation())->animate(
            $this->context(
                ['abcde'],
                stops: [[255, 0, 0], [0, 0, 255]],
                direction: Direction::Horizontal,
            ),
            $driver,
        );

        $cursorUps = array_filter(
            $driver->events,
            static fn (array $event): bool => 'write' === $event['op']
                && 1 === preg_match('/^\033\[\d+F$/', (string) $event['value']),
        );
        self::assertSame(5, count($cursorUps));
    }

    #[Test]
    public function diagonalMultibyteUsesGlyphCountForColumnFractionNotByteCount(): void
    {
        // 2×2 multibyte banner. Original maxWidth=2 (mb_strlen) → cell colours
        // cover {red, mid, blue}. Mutation strlen (line 208) → maxWidth=6,
        // column fractions shift to [0, 0.2], cells never reach pure blue.
        $driver = new RecordingAnimationDriver();
        (new ShimmerAnimation())->animate(
            $this->context(
                ['█▀', '▄░'],
                stops: [[255, 0, 0], [0, 0, 255]],
                direction: Direction::Diagonal,
            ),
            $driver,
        );

        $firstFrameEscapes = $this->firstFrameColorEscapes($driver);
        self::assertContains("\033[38;2;0;0;255m", $firstFrameEscapes);
    }

    #[Test]
    public function frameCountScalesWithCycleLengthAboveTheMinimumFloor(): void
    {
        // 5-row vertical: cycleLength=5 > MIN_FRAMES=4, so frameCount = 6 →
        // 5 cursor-ups (loop runs frame=1..5). The +1 in `min(MAX, cycle) + 1`
        // is what makes this scale; replacing it with -1 gives 4 frames →
        // 3 cursor-ups, which this test catches.
        $driver = new RecordingAnimationDriver();
        (new ShimmerAnimation())->animate(
            $this->context(
                ['a', 'b', 'c', 'd', 'e'],
                stops: [[255, 0, 0], [0, 0, 255]],
            ),
            $driver,
        );

        $cursorUps = array_filter(
            $driver->events,
            static fn (array $event): bool => 'write' === $event['op']
                && 1 === preg_match('/^\033\[\d+F$/', (string) $event['value']),
        );
        self::assertGreaterThan(4, count($cursorUps));
    }

    #[Test]
    public function totalSleepBudgetIsApproximatelyTheConfiguredAnimationDuration(): void
    {
        // The sum of all per-frame sleeps should land in a tight window around
        // the budget. Mutations on `$frameCount - 1` (denominator of the
        // intdiv) shift the per-frame slice and the sum drifts outside the
        // window.
        $driver = new RecordingAnimationDriver();
        (new ShimmerAnimation())->animate(
            $this->context(
                ['a', 'b', 'c', 'd', 'e'],
                stops: [[255, 0, 0], [0, 0, 255]],
            ),
            $driver,
        );

        $totalMicros = 0;
        foreach ($driver->events as $event) {
            if ('sleep' === $event['op']) {
                $totalMicros += (int) $event['value'];
            }
        }
        // Generous bounds: budget is 400_000, so allow 350k–450k.
        self::assertGreaterThan(350_000, $totalMicros);
        self::assertLessThan(450_000, $totalMicros);
    }

    #[Test]
    public function cursorUpMovesByExactlyTheBannerHeight(): void
    {
        $driver = new RecordingAnimationDriver();

        // 3-line banner → between frames cursor must move up exactly 3 lines.
        (new ShimmerAnimation())->animate(
            $this->context(['a', 'b', 'c'], stops: [[255, 0, 0], [0, 255, 0]]),
            $driver,
        );

        foreach ($driver->events as $event) {
            if ('write' === $event['op'] && 1 === preg_match('/^\033\[(\d+)F$/', (string) $event['value'], $match)) {
                self::assertSame('3', $match[1]);

                return;
            }
        }
        self::fail('expected at least one cursor-up escape');
    }

    #[Test]
    public function differentFramesEmitDifferentColors(): void
    {
        $driver = new RecordingAnimationDriver();

        (new ShimmerAnimation())->animate(
            $this->context(['ab', 'cd'], stops: [[255, 0, 0], [0, 0, 255]]),
            $driver,
        );

        $colorEscapes = [];
        foreach ($driver->events as $event) {
            if ('write' === $event['op'] || 'writeLine' === $event['op']) {
                if (1 === preg_match_all('/\033\[38;2;\d+;\d+;\d+m/', (string) $event['value'], $matches)) {
                    foreach ($matches[0] as $escape) {
                        $colorEscapes[] = $escape;
                    }
                }
            }
        }
        // At least two distinct color escapes across frames — otherwise nothing is shimmering.
        self::assertGreaterThan(1, count(array_unique($colorEscapes)));
    }

    #[Test]
    public function firstFrameInVerticalProducesPerRowGradientColors(): void
    {
        $driver = new RecordingAnimationDriver();

        (new ShimmerAnimation())->animate(
            $this->context(['a', 'b'], stops: [[255, 0, 0], [0, 0, 255]]),
            $driver,
        );

        $firstFrameEscapes = $this->firstFrameColorEscapes($driver);
        // 2 rows: row 0 → fraction 0 → first stop; row 1 → fraction 1 → last stop.
        self::assertContains("\033[38;2;255;0;0m", $firstFrameEscapes);
        self::assertContains("\033[38;2;0;0;255m", $firstFrameEscapes);
    }

    #[Test]
    public function firstFrameInHorizontalProducesPerCharGradientColors(): void
    {
        $driver = new RecordingAnimationDriver();

        (new ShimmerAnimation())->animate(
            $this->context(
                ['ab'],
                stops: [[255, 0, 0], [0, 0, 255]],
                direction: Direction::Horizontal,
            ),
            $driver,
        );

        $firstFrameEscapes = $this->firstFrameColorEscapes($driver);
        // 2 chars: char 0 → fraction 0 → first stop; char 1 → fraction 1 → last stop.
        self::assertContains("\033[38;2;255;0;0m", $firstFrameEscapes);
        self::assertContains("\033[38;2;0;0;255m", $firstFrameEscapes);
    }

    #[Test]
    public function diagonalShiftsTheWholeGradientPlaneNotJustOneAxis(): void
    {
        $driver = new RecordingAnimationDriver();

        // 2 rows × 4 cols, stops red→blue. The leading cell (0,0) starts at the
        // gradient origin (red) and as the plane slides diagonally its color
        // must change away from red — otherwise we're only animating one axis.
        (new ShimmerAnimation())->animate(
            $this->context(
                ['abcd', 'efgh'],
                stops: [[255, 0, 0], [0, 0, 255]],
                direction: Direction::Diagonal,
            ),
            $driver,
        );

        $frame1FirstLine = $this->frameOneFirstLine($driver);
        self::assertStringStartsWith(
            "\033[38;2;",
            $frame1FirstLine,
            'frame 1 must open with a foreground color escape on the first cell',
        );
        self::assertStringNotContainsString(
            "\033[38;2;255;0;0m",
            substr($frame1FirstLine, 0, 30),
            'first cell must have moved off the gradient origin (no longer pure red)',
        );
    }

    #[Test]
    public function frameAfterFirstShowsPerRowShiftedColorsInVertical(): void
    {
        $driver = new RecordingAnimationDriver();

        // 5-row vertical, red→blue. Frame 1 (phase=1/5) shifts every row by 1:
        //   row 0 → sampleIndex 1 → fraction 0.25 → (191, 0, 64)
        //   row 4 → sampleIndex 0 (mod 5) → fraction 0     → (255, 0, 0)
        // Pinning these guards the rotation arithmetic against direction
        // flips, modulus → multiplication, ÷ → ×, and rounding swaps.
        (new ShimmerAnimation())->animate(
            $this->context(
                ['a', 'b', 'c', 'd', 'e'],
                stops: [[255, 0, 0], [0, 0, 255]],
            ),
            $driver,
        );

        $framesEscapes = $this->collectColorEscapesPerFrame($driver);
        self::assertGreaterThanOrEqual(2, count($framesEscapes));
        self::assertContains("\033[38;2;191;0;64m", $framesEscapes[1]);
        self::assertContains("\033[38;2;128;0;128m", $framesEscapes[1]);
        self::assertContains("\033[38;2;255;0;0m", $framesEscapes[1]);
    }

    #[Test]
    public function frameAfterFirstShowsPerCharShiftedColorsInHorizontal(): void
    {
        $driver = new RecordingAnimationDriver();

        // Single 5-char line, red→blue, horizontal. Frame 1 shifts every visible
        // char by 1, mirroring the vertical row-shift case.
        (new ShimmerAnimation())->animate(
            $this->context(
                ['abcde'],
                stops: [[255, 0, 0], [0, 0, 255]],
                direction: Direction::Horizontal,
            ),
            $driver,
        );

        $framesEscapes = $this->collectColorEscapesPerFrame($driver);
        self::assertGreaterThanOrEqual(2, count($framesEscapes));
        self::assertContains("\033[38;2;191;0;64m", $framesEscapes[1]);
        self::assertContains("\033[38;2;255;0;0m", $framesEscapes[1]);
    }

    #[Test]
    public function frameAfterFirstShowsAllCellsShiftedInDiagonal(): void
    {
        $driver = new RecordingAnimationDriver();

        // 5×5 diagonal, red→blue. cycleLength = MAX_FRAMES = 16, frameCount = 17,
        // frame 1 phase = 1/16. Cell (0,0) base fraction 0 → shifted 0.0625 →
        // lerp(red, blue, 0.0625) = (239, 0, 16).
        (new ShimmerAnimation())->animate(
            $this->context(
                ['aaaaa', 'bbbbb', 'ccccc', 'ddddd', 'eeeee'],
                stops: [[255, 0, 0], [0, 0, 255]],
                direction: Direction::Diagonal,
            ),
            $driver,
        );

        $framesEscapes = $this->collectColorEscapesPerFrame($driver);
        self::assertGreaterThanOrEqual(2, count($framesEscapes));
        self::assertContains("\033[38;2;239;0;16m", $framesEscapes[1]);
    }

    #[Test]
    public function rotationDirectionIsAdditiveNotSubtractive(): void
    {
        $driver = new RecordingAnimationDriver();

        // 4-row vertical, stops red→blue. With +1 shift, row 0 lands on static
        // row 1 (fraction 1/3 → (170, 0, 85)). With -1 shift, row 0 lands on
        // static row 3 (fraction 1.0 → blue). Asserting the row-0 escape pins
        // the rotation direction.
        (new ShimmerAnimation())->animate(
            $this->context(
                ['a', 'b', 'c', 'd'],
                stops: [[255, 0, 0], [0, 0, 255]],
            ),
            $driver,
        );

        self::assertStringStartsWith("\033[38;2;170;0;85m", $this->frameOneFirstLine($driver));
    }

    #[Test]
    public function verticalPreRenderEmitsEscapeBeforeLineContentAndResetAfter(): void
    {
        // Pins the exact `escape . $line . ANSI_RESET` composition. Kills the
        // ConcatOperandRemoval / Concat-swap mutations on the return line.
        $driver = new RecordingAnimationDriver();
        (new ShimmerAnimation())->animate(
            $this->context(['hello'], stops: [[255, 0, 0]]),
            $driver,
        );

        self::assertSame("\033[38;2;255;0;0mhello\033[0m", $this->frameZeroFirstLine($driver));
    }

    #[Test]
    public function horizontalPreRenderConcatenatesEscapesCharsAndReset(): void
    {
        // Pins the per-char concat in styleHorizontal — particularly the
        // trailing ANSI_RESET that the operand-removal mutation drops.
        $driver = new RecordingAnimationDriver();
        (new ShimmerAnimation())->animate(
            $this->context(
                ['ab'],
                stops: [[255, 0, 0], [0, 0, 255]],
                direction: Direction::Horizontal,
            ),
            $driver,
        );

        self::assertSame(
            "\033[38;2;255;0;0ma\033[38;2;0;0;255mb\033[0m",
            $this->frameZeroFirstLine($driver),
        );
    }

    #[Test]
    public function horizontalAllSpacesLineEmitsBareSpacesWithNoEscapesOrReset(): void
    {
        // visibleCount === 0 path: returns the line unchanged. The DEC mutation
        // (-1 === $visibleCount) bypasses the early return and tacks on a
        // trailing ANSI_RESET; this exact-string assertion catches the drift.
        $driver = new RecordingAnimationDriver();
        (new ShimmerAnimation())->animate(
            $this->context(
                ['   '],
                stops: [[255, 0, 0], [0, 0, 255]],
                direction: Direction::Horizontal,
            ),
            $driver,
        );

        self::assertSame('   ', $this->frameZeroFirstLine($driver));
    }

    #[Test]
    public function diagonalPreRenderEndsWithResetOnEachLine(): void
    {
        // Each diagonal line ends with ANSI_RESET. Mutation drops the reset.
        $driver = new RecordingAnimationDriver();
        (new ShimmerAnimation())->animate(
            $this->context(
                ['ab', 'cd'],
                stops: [[255, 0, 0], [0, 0, 255]],
                direction: Direction::Diagonal,
            ),
            $driver,
        );

        $line = $this->frameZeroFirstLine($driver);
        self::assertStringEndsWith("\033[0m", $line);
    }

    #[Test]
    public function diagonalWrapAroundSubtractsOneNotZeroAndNotAddsOne(): void
    {
        // 5×5 diagonal red→blue. Frame 1 phase = 1/16 = 0.0625.
        // Only cell (4, 4) hits the > 1.0 wrap branch (combined = 1.0625).
        // Original wraps: 1.0625 - 1.0 = 0.0625 → near red (239, 0, 16).
        // Mutation `combined - 0.0`: stays 1.0625, clamps to *pure blue* (0,0,255).
        // Mutation `combined + 1.0`: 2.0625, also clamps to pure blue.
        // No other cell at this phase emits pure blue (closest is (3,4) at
        // fraction 15/16 → (16, 0, 239)), so its absence is the discriminator.
        $driver = new RecordingAnimationDriver();
        (new ShimmerAnimation())->animate(
            $this->context(
                ['aaaaa', 'bbbbb', 'ccccc', 'ddddd', 'eeeee'],
                stops: [[255, 0, 0], [0, 0, 255]],
                direction: Direction::Diagonal,
            ),
            $driver,
        );

        $framesEscapes = $this->collectColorEscapesPerFrame($driver);
        self::assertGreaterThanOrEqual(2, count($framesEscapes));
        self::assertNotContains("\033[38;2;0;0;255m", $framesEscapes[1]);
    }

    #[Test]
    public function maxVisibleCountFeedsHorizontalCycleLengthForMultibyteBanner(): void
    {
        // styleHorizontal's cycleLength comes from maxVisibleCount, which
        // walks each line via mb_str_split. With str_split (mutation), the
        // 2-glyph multibyte banner is treated as 6 single-byte cells, so
        // cycleLength=6 → frameCount=7. Original gives cycleLength=2 →
        // frameCount=4 (clamped at MIN_FRAMES). Cursor-up count = frameCount-1.
        $driver = new RecordingAnimationDriver();
        (new ShimmerAnimation())->animate(
            $this->context(
                ['█▀'],
                stops: [[255, 0, 0], [0, 0, 255]],
                direction: Direction::Horizontal,
            ),
            $driver,
        );

        $cursorUps = array_filter(
            $driver->events,
            static fn (array $event): bool => 'write' === $event['op']
                && 1 === preg_match('/^\033\[\d+F$/', (string) $event['value']),
        );
        self::assertSame(3, count($cursorUps));
    }

    #[Test]
    public function frameTwoStillUsesShiftedPhaseNotTheSettleFallback(): void
    {
        // 5-row vertical: frameCount = 6, frame 2 has phase = 2/5 → shift = 2.
        // If the "is last frame" check were ever to misfire on frame 2, the
        // settle path would render row 0 in the static colour (red) instead
        // of the shift-2 colour (128, 0, 128).
        $driver = new RecordingAnimationDriver();
        (new ShimmerAnimation())->animate(
            $this->context(
                ['a', 'b', 'c', 'd', 'e'],
                stops: [[255, 0, 0], [0, 0, 255]],
            ),
            $driver,
        );

        $framesEscapes = $this->collectColorEscapesPerFrame($driver);
        self::assertGreaterThanOrEqual(3, count($framesEscapes));
        self::assertContains("\033[38;2;128;0;128m", $framesEscapes[2]);
    }

    #[Test]
    public function twoRowVerticalBannerRotatesUsingRoundedShift(): void
    {
        // cycleLength = 2 < MIN_FRAMES, so frameCount = 4. Phases [0, 1/3,
        // 2/3, 0] times rowCount=2 give [0, 0.667, 1.333, 0] — non-integer
        // products where round / floor / ceil all differ. Original shifts =
        // [0, 1, 1, 0]; row 0 of frames 1 & 2 must therefore land on blue
        // (the static row-1 colour). floor would leave frame 1 on red; ceil
        // would leave frame 2 on red.
        $driver = new RecordingAnimationDriver();
        (new ShimmerAnimation())->animate(
            $this->context(['a', 'b'], stops: [[255, 0, 0], [0, 0, 255]]),
            $driver,
        );

        self::assertStringStartsWith("\033[38;2;0;0;255m", $this->frameNthFirstLine($driver, 1));
        self::assertStringStartsWith("\033[38;2;0;0;255m", $this->frameNthFirstLine($driver, 2));
    }

    #[Test]
    public function twoCharHorizontalBannerRotatesUsingRoundedShift(): void
    {
        // Same non-integer phase-product trap as the vertical case, applied
        // to styleHorizontal's shift calculation on line 145.
        $driver = new RecordingAnimationDriver();
        (new ShimmerAnimation())->animate(
            $this->context(
                ['ab'],
                stops: [[255, 0, 0], [0, 0, 255]],
                direction: Direction::Horizontal,
            ),
            $driver,
        );

        self::assertStringStartsWith("\033[38;2;0;0;255m", $this->frameNthFirstLine($driver, 1));
        self::assertStringStartsWith("\033[38;2;0;0;255m", $this->frameNthFirstLine($driver, 2));
    }

    #[Test]
    public function firstRowOfFrameOneShiftsToTheNextStaticPositionInVertical(): void
    {
        // 5-row vertical, stops red→blue, frame 1 shift = round(1/5 * 5) = 1.
        // Row 0 must therefore render the static row-1 colour (191, 0, 64),
        // not the static row-0 colour (255, 0, 0). This pins the
        // multiplication, rounding, modulus and division on the shift /
        // sampleIndex / fraction calculation.
        $driver = new RecordingAnimationDriver();
        (new ShimmerAnimation())->animate(
            $this->context(
                ['a', 'b', 'c', 'd', 'e'],
                stops: [[255, 0, 0], [0, 0, 255]],
            ),
            $driver,
        );

        self::assertStringStartsWith("\033[38;2;191;0;64m", $this->frameOneFirstLine($driver));
    }

    #[Test]
    public function firstCharOfFrameOneShiftsToTheNextStaticPositionInHorizontal(): void
    {
        // Same logic as the vertical case, applied to a 5-char single-line
        // horizontal banner.
        $driver = new RecordingAnimationDriver();
        (new ShimmerAnimation())->animate(
            $this->context(
                ['abcde'],
                stops: [[255, 0, 0], [0, 0, 255]],
                direction: Direction::Horizontal,
            ),
            $driver,
        );

        self::assertStringStartsWith("\033[38;2;191;0;64m", $this->frameOneFirstLine($driver));
    }

    #[Test]
    public function firstCellOfFrameOneAdvancesAlongTheDiagonalPhase(): void
    {
        // 5×5 diagonal, stops red→blue, frame 1 phase = 1/16 = 0.0625. Cell
        // (0, 0) base fraction 0 → shifted 0.0625 → (239, 0, 16). This pins
        // the phase formula and the fmod-based plane shift.
        $driver = new RecordingAnimationDriver();
        (new ShimmerAnimation())->animate(
            $this->context(
                ['aaaaa', 'bbbbb', 'ccccc', 'ddddd', 'eeeee'],
                stops: [[255, 0, 0], [0, 0, 255]],
                direction: Direction::Diagonal,
            ),
            $driver,
        );

        self::assertStringStartsWith("\033[38;2;239;0;16m", $this->frameOneFirstLine($driver));
    }

    #[Test]
    public function diagonalPreRenderIncludesTheBottomRightCornerStop(): void
    {
        // 2×2 diagonal, red→blue. The bottom-right cell has aspect-normalized
        // fraction (1 + 1) / 2 = 1.0 — i.e. the *last* stop, blue. The pre-render
        // (phase 0) must reproduce the static gradient exactly, including this
        // boundary cell. Naive fmod($combined, 1.0) wraps 1.0 → 0.0 and emits
        // red here instead of blue — the bug we're guarding against.
        $driver = new RecordingAnimationDriver();
        (new ShimmerAnimation())->animate(
            $this->context(
                ['ab', 'cd'],
                stops: [[255, 0, 0], [0, 0, 255]],
                direction: Direction::Diagonal,
            ),
            $driver,
        );

        $firstFrameEscapes = $this->firstFrameColorEscapes($driver);
        self::assertContains("\033[38;2;255;0;0m", $firstFrameEscapes);
        self::assertContains("\033[38;2;0;0;255m", $firstFrameEscapes);
    }

    #[Test]
    public function firstFrameInDiagonalUsesAspectNormalizedFraction(): void
    {
        $driver = new RecordingAnimationDriver();

        (new ShimmerAnimation())->animate(
            $this->context(
                ['ab', 'cd'],
                stops: [[255, 0, 0], [0, 0, 255]],
                direction: Direction::Diagonal,
            ),
            $driver,
        );

        $firstFrameEscapes = $this->firstFrameColorEscapes($driver);
        // (0/2 + 0/2) / 2 = 0 for top-left → first stop.
        self::assertContains("\033[38;2;255;0;0m", $firstFrameEscapes);
    }

    #[Test]
    public function lastFrameSettlesForThreeRowVerticalBannerEvenWhenCycleDoesNotDivideFrameCount(): void
    {
        $driver = new RecordingAnimationDriver();

        // Regression: with rowCount=3 and the previous "shift = frame % cycle"
        // formula, frame counts that aren't multiples of 3 left the last frame
        // rotated instead of static. The pre-render and last frame must match.
        (new ShimmerAnimation())->animate(
            $this->context(['a', 'b', 'c'], stops: [[255, 0, 0], [0, 255, 0]]),
            $driver,
        );

        $framesEscapes = $this->collectColorEscapesPerFrame($driver);
        self::assertGreaterThanOrEqual(2, count($framesEscapes));
        self::assertSame($framesEscapes[0], $framesEscapes[count($framesEscapes) - 1]);
    }

    #[Test]
    public function lastFrameMatchesFirstFrameSoTheAnimationSettlesCleanly(): void
    {
        $driver = new RecordingAnimationDriver();

        (new ShimmerAnimation())->animate(
            $this->context(['ab', 'cd'], stops: [[255, 0, 0], [0, 255, 0]]),
            $driver,
        );

        $framesEscapes = $this->collectColorEscapesPerFrame($driver);
        self::assertGreaterThanOrEqual(2, count($framesEscapes));
        self::assertSame(
            $framesEscapes[0],
            $framesEscapes[count($framesEscapes) - 1],
            'last frame must reproduce the first frame so the banner is left in a stable, static-equivalent state',
        );
    }

    #[Test]
    public function statusLineWrittenExactlyOnceAfterAllFrames(): void
    {
        $driver = new RecordingAnimationDriver();

        (new ShimmerAnimation())->animate(
            $this->context(['x'], stops: [[255, 0, 0], [0, 255, 0]], statusLine: 'demo · PHP 8.2'),
            $driver,
        );

        $statusEvents = array_filter(
            $driver->events,
            static fn (array $event): bool => 'writeLine' === $event['op']
                && str_contains((string) $event['value'], 'demo'),
        );
        self::assertCount(1, $statusEvents);
    }

    #[Test]
    public function withoutColorsFallsBackToStaticOutput(): void
    {
        $driver = new RecordingAnimationDriver();

        (new ShimmerAnimation())->animate(
            $this->context(['hello'], stops: null, useColor: false),
            $driver,
        );

        $cursorUps = array_filter(
            $driver->events,
            static fn (array $event): bool => 'write' === $event['op']
                && str_starts_with((string) $event['value'], "\033[")
                && str_ends_with((string) $event['value'], 'F'),
        );
        self::assertCount(0, $cursorUps, 'no shimmer redraw without colors');
    }

    #[Test]
    public function fallbackPathStillEmitsLinesAndStatus(): void
    {
        $driver = new RecordingAnimationDriver();

        (new ShimmerAnimation())->animate(
            $this->context(['hello'], stops: null, useColor: false, statusLine: 'demo · PHP'),
            $driver,
        );

        $writeLines = array_values(array_filter(
            $driver->events,
            static fn (array $event): bool => 'writeLine' === $event['op'],
        ));
        $values = array_column($writeLines, 'value');
        self::assertContains('hello', $values);
        self::assertContains('demo · PHP', $values);
    }

    #[Test]
    public function useColorTrueButNullStopsFallsBackToStatic(): void
    {
        $driver = new RecordingAnimationDriver();

        (new ShimmerAnimation())->animate(
            $this->context(['hi'], stops: null, useColor: true),
            $driver,
        );

        $cursorUps = array_filter(
            $driver->events,
            static fn (array $event): bool => 'write' === $event['op']
                && str_starts_with((string) $event['value'], "\033[")
                && str_ends_with((string) $event['value'], 'F'),
        );
        self::assertCount(0, $cursorUps, 'no shimmer redraw when stops are missing');
    }

    #[Test]
    public function emptyLinesAreNotColorWrappedDuringAnimation(): void
    {
        $driver = new RecordingAnimationDriver();

        (new ShimmerAnimation())->animate(
            $this->context(['a', '', 'b'], stops: [[255, 0, 0], [0, 255, 0]]),
            $driver,
        );

        $bareNewlines = array_values(array_filter(
            $driver->events,
            static fn (array $event): bool => 'writeLine' === $event['op'] && '' === $event['value'],
        ));
        self::assertGreaterThan(0, count($bareNewlines), 'empty rows must keep their bare newline across frames');
    }

    #[Test]
    public function horizontalDirectionShiftsColorAcrossCharacters(): void
    {
        $driver = new RecordingAnimationDriver();

        (new ShimmerAnimation())->animate(
            $this->context(
                ['ab'],
                stops: [[255, 0, 0], [0, 255, 0]],
                direction: Direction::Horizontal,
            ),
            $driver,
        );

        $framesEscapes = $this->collectColorEscapesPerFrame($driver);
        // Horizontal shimmer must emit at least two distinct foreground escapes
        // *within a single frame* (per-char gradient) — vertical mode would only
        // emit one escape per row.
        $maxDistinctPerFrame = 0;
        foreach ($framesEscapes as $frame) {
            $maxDistinctPerFrame = max($maxDistinctPerFrame, count(array_unique($frame)));
        }
        self::assertGreaterThan(1, $maxDistinctPerFrame);
    }

    #[Test]
    public function diagonalDirectionAppliesPerCellGradientAcrossFrames(): void
    {
        $driver = new RecordingAnimationDriver();

        (new ShimmerAnimation())->animate(
            $this->context(
                ['ab', 'cd'],
                stops: [[255, 0, 0], [0, 255, 0]],
                direction: Direction::Diagonal,
            ),
            $driver,
        );

        $framesEscapes = $this->collectColorEscapesPerFrame($driver);
        // Diagonal: each cell has its own (row + col) fraction, so a single
        // frame produces multiple escapes; across frames the colors shift.
        $allEscapes = array_merge(...$framesEscapes);
        self::assertGreaterThan(2, count(array_unique($allEscapes)));
    }

    #[Test]
    public function horizontalBannerWithMultibyteCharactersSplitsCorrectly(): void
    {
        // mb_str_split must handle multibyte chars as single visible units; a
        // bare str_split would chunk each byte separately and produce wrong
        // shifts. Pre-render of '█▀' (each char 3 bytes, both visible) should
        // emit exactly two foreground escapes — one per visible glyph.
        $driver = new RecordingAnimationDriver();
        (new ShimmerAnimation())->animate(
            $this->context(
                ['█▀'],
                stops: [[255, 0, 0], [0, 0, 255]],
                direction: Direction::Horizontal,
            ),
            $driver,
        );

        $firstFrameEscapes = $this->firstFrameColorEscapes($driver);
        self::assertCount(2, $firstFrameEscapes, 'two glyphs → two escapes; byte-level splitting would produce more');
    }

    #[Test]
    public function diagonalBannerWithMultibyteCharactersTreatsThemAsSingleCells(): void
    {
        // Same multibyte concern for the diagonal pass — both mb_str_split
        // *and* mb_strlen must be used so columnFraction and maxWidth land
        // on visible-glyph counts, not byte counts.
        $driver = new RecordingAnimationDriver();
        (new ShimmerAnimation())->animate(
            $this->context(
                ['█▀', '▄░'],
                stops: [[255, 0, 0], [0, 0, 255]],
                direction: Direction::Diagonal,
            ),
            $driver,
        );

        $firstFrameEscapes = $this->firstFrameColorEscapes($driver);
        // 2×2 grid → 4 cells emit 4 colour escapes total. Byte-level splitting
        // would iterate 3 bytes per glyph (12 cells), and even with ANSI-run
        // compression the count stays well above 4 — this guards line 180.
        self::assertCount(4, $firstFrameEscapes);
    }

    #[Test]
    public function horizontalBannerWithEmbeddedSpacePreservesBothSides(): void
    {
        // Banner with an embedded space ('a b') exercises both the
        // `$output .= $char` for the space (Assignment mutation: `=` would
        // clobber 'a's escape) AND the `continue` (mutation to `break`
        // would drop 'b' entirely). The pre-render line must contain
        // colored 'a' AND colored 'b'.
        $driver = new RecordingAnimationDriver();
        (new ShimmerAnimation())->animate(
            $this->context(
                ['a b'],
                stops: [[255, 0, 0], [0, 0, 255]],
                direction: Direction::Horizontal,
            ),
            $driver,
        );

        $line = $this->frameZeroFirstLine($driver);
        self::assertStringContainsString("\033[38;2;255;0;0ma", $line);
        self::assertStringContainsString("\033[38;2;0;0;255mb", $line);
    }

    #[Test]
    public function diagonalBannerWithEmbeddedSpacePreservesCharsOnEitherSide(): void
    {
        // Exercises the `if (' ' === $char) { $output .= $char; continue; }`
        // arm of styleDiagonal. Assignment mutation (`$output = $char`)
        // clobbers the leading 'a'; Continue_ mutation (`break`) drops the
        // trailing 'b'. Asserting that both visible characters survive kills
        // both.
        $driver = new RecordingAnimationDriver();
        (new ShimmerAnimation())->animate(
            $this->context(
                ['a b'],
                stops: [[255, 0, 0], [0, 0, 255]],
                direction: Direction::Diagonal,
            ),
            $driver,
        );

        $line = $this->frameZeroFirstLine($driver);
        self::assertStringContainsString("\033[38;2;255;0;0ma", $line);
        self::assertStringContainsString('b', $line);
    }

    #[Test]
    public function singleRowDiagonalBannerSamplesAtZeroRowFraction(): void
    {
        // rowCount = 1 takes the `: 0.0` branch on line 177. Pre-render of
        // 'abc' with red→blue, diagonal: cells (0, c) → fraction
        // (0 + c/2) / 2. (0,0) = 0 (red), (0,2) = 0.5 (mid). The mutations on
        // the ternary all land here.
        $driver = new RecordingAnimationDriver();
        (new ShimmerAnimation())->animate(
            $this->context(
                ['abc'],
                stops: [[255, 0, 0], [0, 0, 255]],
                direction: Direction::Diagonal,
            ),
            $driver,
        );

        $firstFrameEscapes = $this->firstFrameColorEscapes($driver);
        self::assertContains("\033[38;2;255;0;0m", $firstFrameEscapes);
        self::assertContains("\033[38;2;128;0;128m", $firstFrameEscapes);
    }

    #[Test]
    public function singleColumnDiagonalBannerSamplesAtZeroColumnFraction(): void
    {
        // maxWidth = 1 takes the `: 0.0` branch on line 185. Pre-render of
        // ['a','b','c'] (3 rows × 1 col), red→blue, diagonal: cells (r, 0) →
        // fraction (r/2 + 0) / 2. (0,0) = 0 (red), (2,0) = 0.5 (mid).
        $driver = new RecordingAnimationDriver();
        (new ShimmerAnimation())->animate(
            $this->context(
                ['a', 'b', 'c'],
                stops: [[255, 0, 0], [0, 0, 255]],
                direction: Direction::Diagonal,
            ),
            $driver,
        );

        $firstFrameEscapes = $this->firstFrameColorEscapes($driver);
        self::assertContains("\033[38;2;255;0;0m", $firstFrameEscapes);
        self::assertContains("\033[38;2;128;0;128m", $firstFrameEscapes);
    }

    #[Test]
    public function diagonalMidCellUsesAspectNormalizedAverageOfRowAndColumnFractions(): void
    {
        // Pre-render of a 3×3 banner red→blue diagonal pins the mid cell
        // (1, 1): rowFrac = colFrac = 0.5, base = (0.5 + 0.5) / 2 = 0.5,
        // shifted = 0.5 → mid (128, 0, 128). Mutations on the diagonal
        // formula (Plus → Minus, /2 → /1, /2 → *2, /2 → /3) all change
        // this exact value.
        $driver = new RecordingAnimationDriver();
        (new ShimmerAnimation())->animate(
            $this->context(
                ['abc', 'def', 'ghi'],
                stops: [[255, 0, 0], [0, 0, 255]],
                direction: Direction::Diagonal,
            ),
            $driver,
        );

        $firstFrameEscapes = $this->firstFrameColorEscapes($driver);
        self::assertContains("\033[38;2;128;0;128m", $firstFrameEscapes);
    }

    #[Test]
    public function spaceCharactersInHorizontalLineRemainUncolored(): void
    {
        $driver = new RecordingAnimationDriver();

        (new ShimmerAnimation())->animate(
            $this->context(
                ['a b'],
                stops: [[255, 0, 0], [0, 255, 0]],
                direction: Direction::Horizontal,
            ),
            $driver,
        );

        // Concatenate every emitted writeLine value and ensure no escape
        // immediately precedes the literal space.
        $emitted = '';
        foreach ($driver->events as $event) {
            if ('writeLine' === $event['op']) {
                $emitted .= (string) $event['value']."\n";
            }
        }
        self::assertStringNotContainsString("\033[38;2;255;0;0m ", $emitted);
        self::assertStringNotContainsString("\033[38;2;0;255;0m ", $emitted);
    }

    #[Test]
    public function singleVisibleCharLineInHorizontalUsesFirstStop(): void
    {
        $driver = new RecordingAnimationDriver();

        (new ShimmerAnimation())->animate(
            $this->context(
                ['x'],
                stops: [[255, 0, 0], [0, 255, 0]],
                direction: Direction::Horizontal,
            ),
            $driver,
        );

        // First frame (phase 0): the only visible char samples at fraction 0 → first stop (255,0,0).
        $framesEscapes = $this->collectColorEscapesPerFrame($driver);
        self::assertNotEmpty($framesEscapes[0]);
        self::assertSame("\033[38;2;255;0;0m", $framesEscapes[0][0]);
    }

    #[Test]
    public function horizontalLineWithOnlySpacesIsLeftUntouched(): void
    {
        $driver = new RecordingAnimationDriver();

        (new ShimmerAnimation())->animate(
            $this->context(
                ['   '],
                stops: [[255, 0, 0], [0, 255, 0]],
                direction: Direction::Horizontal,
            ),
            $driver,
        );

        $framesEscapes = $this->collectColorEscapesPerFrame($driver);
        // No foreground escapes in any frame — the line has no visible chars to color.
        foreach ($framesEscapes as $frame) {
            self::assertSame([], $frame);
        }
    }

    #[Test]
    public function sleepsBetweenFramesButNotAfterLast(): void
    {
        $driver = new RecordingAnimationDriver();

        (new ShimmerAnimation())->animate(
            $this->context(['a'], stops: [[255, 0, 0], [0, 255, 0]]),
            $driver,
        );

        $sleepCount = count(array_filter(
            $driver->events,
            static fn (array $event): bool => 'sleep' === $event['op'],
        ));
        $cursorUpCount = count(array_filter(
            $driver->events,
            static fn (array $event): bool => 'write' === $event['op']
                && str_starts_with((string) $event['value'], "\033[")
                && str_ends_with((string) $event['value'], 'F'),
        ));
        // One fewer sleep and one fewer cursor-up than total frames — both gates
        // are between consecutive frames, not before the first or after the last.
        self::assertSame($cursorUpCount, $sleepCount);
    }

    /**
     * @return list<string>
     */
    private function firstFrameColorEscapes(RecordingAnimationDriver $driver): array
    {
        return $this->collectColorEscapesPerFrame($driver)[0];
    }

    private function frameOneFirstLine(RecordingAnimationDriver $driver): string
    {
        $passedCursorUp = false;
        foreach ($driver->events as $event) {
            if ('write' === $event['op'] && 1 === preg_match('/^\033\[\d+F$/', (string) $event['value'])) {
                $passedCursorUp = true;
                continue;
            }
            if ($passedCursorUp && 'writeLine' === $event['op']) {
                return (string) $event['value'];
            }
        }

        return '';
    }

    private function frameZeroFirstLine(RecordingAnimationDriver $driver): string
    {
        foreach ($driver->events as $event) {
            if ('writeLine' === $event['op']) {
                return (string) $event['value'];
            }
        }

        return '';
    }

    private function frameNthFirstLine(RecordingAnimationDriver $driver, int $frame): string
    {
        $cursorUpCount = 0;
        foreach ($driver->events as $event) {
            if ('write' === $event['op'] && 1 === preg_match('/^\033\[\d+F$/', (string) $event['value'])) {
                ++$cursorUpCount;
                continue;
            }
            if ($cursorUpCount === $frame && 'writeLine' === $event['op']) {
                return (string) $event['value'];
            }
        }

        return '';
    }

    /**
     * @return list<list<string>>
     */
    private function collectColorEscapesPerFrame(RecordingAnimationDriver $driver): array
    {
        $frames = [];
        $current = [];
        foreach ($driver->events as $event) {
            if (!in_array($event['op'], ['write', 'writeLine'], true)) {
                continue;
            }
            $value = (string) $event['value'];
            if ('write' === $event['op'] && 1 === preg_match('/^\033\[\d+F$/', $value)) {
                $frames[] = $current;
                $current = [];
                continue;
            }
            if (preg_match_all('/\033\[38;2;\d+;\d+;\d+m/', $value, $matches)) {
                foreach ($matches[0] as $escape) {
                    $current[] = $escape;
                }
            }
        }
        $frames[] = $current;

        return $frames;
    }

    /**
     * @param list<string>                              $lines
     * @param non-empty-list<array{int, int, int}>|null $stops
     */
    private function context(
        array $lines,
        ?array $stops = null,
        bool $useColor = true,
        ?string $statusLine = null,
        Direction $direction = Direction::Vertical,
    ): AnimationContext {
        return new AnimationContext(
            lines: $lines,
            styledLines: $lines,
            stops: $stops,
            direction: $direction,
            colorSupport: ColorSupport::TrueColor,
            useColor: $useColor,
            statusLine: $statusLine,
        );
    }
}
