<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wazum\ComposerFanfare\AnimationContext;
use Wazum\ComposerFanfare\ColorSupport;
use Wazum\ComposerFanfare\Direction;
use Wazum\ComposerFanfare\DripAnimation;
use Wazum\ComposerFanfare\Tests\Support\RecordingAnimationDriver;

final class DripAnimationTest extends TestCase
{
    #[Test]
    public function preRendersBlankBannerAreaAndStatusBeforeAnyCellReveal(): void
    {
        $driver = new RecordingAnimationDriver();

        (new DripAnimation())->animate(
            $this->context(['ab', 'cd'], stops: [[255, 0, 0], [0, 0, 255]], statusLine: 'demo'),
            $driver,
        );

        // Before the cursor-save (which marks the reveal-anchor), the only
        // writeLine events should be the rowCount blanks + the status line.
        $linesBeforeSave = [];
        foreach ($driver->events as $event) {
            if ('write' === $event['op'] && "\033[s" === (string) $event['value']) {
                break;
            }
            if ('writeLine' === $event['op']) {
                $linesBeforeSave[] = (string) $event['value'];
            }
        }
        self::assertSame(['', '', 'demo'], $linesBeforeSave);
    }

    #[Test]
    public function everyVisibleCellIsEmittedExactlyOnce(): void
    {
        $driver = new RecordingAnimationDriver();

        (new DripAnimation())->animate(
            $this->context(['ab', 'cd'], stops: [[255, 0, 0], [0, 0, 255]]),
            $driver,
        );

        // Count writes that contain a `\033[38;2;…m<char>\033[0m` cell payload.
        $cellEmissions = 0;
        foreach ($driver->events as $event) {
            if ('write' !== $event['op']) {
                continue;
            }
            if (1 === preg_match('/\033\[38;2;\d+;\d+;\d+m.\033\[0m/u', (string) $event['value'])) {
                ++$cellEmissions;
            }
        }
        self::assertSame(4, $cellEmissions);
    }

    #[Test]
    public function eachRevealStartsWithCursorRestoreToTheAnchor(): void
    {
        $driver = new RecordingAnimationDriver();

        (new DripAnimation())->animate(
            $this->context(['ab', 'cd'], stops: [[255, 0, 0], [0, 0, 255]]),
            $driver,
        );

        $saveSeen = false;
        $restoreCount = 0;
        foreach ($driver->events as $event) {
            if ('write' !== $event['op']) {
                continue;
            }
            $value = (string) $event['value'];
            if ("\033[s" === $value) {
                $saveSeen = true;
                continue;
            }
            if ($saveSeen && "\033[u" === $value) {
                ++$restoreCount;
            }
        }
        // One restore per cell (4) + one trailing restore at end of animation.
        self::assertSame(5, $restoreCount);
    }

    #[Test]
    public function cellRevealOrderIsNotStrictlyLeftToRight(): void
    {
        // 2×2 banner, no status: rowCount=2, statusOffset=0.
        // Row 0 → linesUp=2, row 1 → linesUp=1. Source-order reveal would be
        // [(2,0), (2,1), (1,0), (1,1)]. Asserting the observed order differs
        // detects a missing shuffle (1/24 false-positive rate).
        $sequential = [[2, 0], [2, 1], [1, 0], [1, 1]];

        $driver = new RecordingAnimationDriver();
        (new DripAnimation())->animate(
            $this->context(['ab', 'cd'], stops: [[255, 0, 0], [0, 0, 255]]),
            $driver,
        );

        $observedOrder = $this->extractCellOrder($driver);
        self::assertCount(4, $observedOrder);
        self::assertNotSame($sequential, $observedOrder);
    }

    #[Test]
    public function bannerWithStatusLineMovesCursorUpByRowCountPlusOne(): void
    {
        // 3-row banner + status: statusOffset = 1, so for row 0 → linesUp = 4,
        // row 1 → 3, row 2 → 2. The cursor-previous-line escapes emitted must
        // be from {2,3,4}; mutations on `$hasStatus ? 1 : 0` would shift them
        // to {1,2,3} or {3,4,5}.
        $driver = new RecordingAnimationDriver();
        (new DripAnimation())->animate(
            $this->context(['a', 'b', 'c'], stops: [[255, 0, 0]], statusLine: 'demo'),
            $driver,
        );

        $linesUpValues = [];
        $saveSeen = false;
        foreach ($driver->events as $event) {
            if ('write' !== $event['op']) {
                continue;
            }
            $value = (string) $event['value'];
            if ("\033[s" === $value) {
                $saveSeen = true;
                continue;
            }
            if ($saveSeen && 1 === preg_match('/^\033\[(\d+)F$/', $value, $match)) {
                $linesUpValues[(int) $match[1]] = true;
            }
        }
        ksort($linesUpValues);
        self::assertSame([2, 3, 4], array_keys($linesUpValues));
    }

    #[Test]
    public function tinyBannerHasOneSleepPerCellBecauseChunkingDegeneratesToSingles(): void
    {
        // 4 cells → maxChunkSize = max(1, min(MAX_CHUNK, intdiv(4, 20))) = 1.
        // With chunkSize forced to 1, the loop is one cell per sleep.
        $driver = new RecordingAnimationDriver();

        (new DripAnimation())->animate(
            $this->context(['ab', 'cd'], stops: [[255, 0, 0], [0, 0, 255]]),
            $driver,
        );

        $sleeps = array_filter(
            $driver->events,
            static fn (array $event): bool => 'sleep' === $event['op'],
        );
        self::assertSame(4, count($sleeps));
    }

    #[Test]
    public function largeBannerReducesSleepsViaChunkedReveals(): void
    {
        // 60 cells (10×6) crosses the chunking threshold, so maxChunkSize ≥ 2
        // and each sleep covers multiple cells — sleep count must drop below
        // the cell count.
        $driver = new RecordingAnimationDriver();

        (new DripAnimation())->animate(
            $this->context(
                array_fill(0, 6, str_repeat('x', 10)),
                stops: [[255, 0, 0], [0, 0, 255]],
            ),
            $driver,
        );

        $sleepCount = count(array_filter(
            $driver->events,
            static fn (array $event): bool => 'sleep' === $event['op'],
        ));
        self::assertLessThan(60, $sleepCount);
        self::assertGreaterThan(0, $sleepCount);
    }

    #[Test]
    public function withoutColorsFallsBackToStaticLineDumpAndStillEmitsStatus(): void
    {
        $driver = new RecordingAnimationDriver();

        (new DripAnimation())->animate(
            $this->context(['hello'], stops: null, useColor: false, statusLine: 'demo'),
            $driver,
        );

        // No cursor save/restore at all when we're in fallback.
        $cursorOps = array_filter(
            $driver->events,
            static fn (array $event): bool => 'write' === $event['op']
                && in_array((string) $event['value'], ["\033[s", "\033[u"], true),
        );
        self::assertSame([], array_values($cursorOps));

        // Banner line + status line must both be emitted.
        $writtenLines = array_values(array_filter(
            array_map(
                static fn (array $event): mixed => 'writeLine' === $event['op'] ? $event['value'] : null,
                $driver->events,
            ),
            static fn (mixed $line): bool => null !== $line,
        ));
        self::assertContains('hello', $writtenLines);
        self::assertContains('demo', $writtenLines);
    }

    #[Test]
    public function nullStatusLineSuppressesAnyStatusLineEmission(): void
    {
        // Catches `&&` → `||` on the hasStatus check: with the mutation, a
        // missing statusLine still triggers the writeLine, producing an
        // extra empty line.
        $driver = new RecordingAnimationDriver();
        (new DripAnimation())->animate(
            $this->context(['a'], stops: [[255, 0, 0]]),
            $driver,
        );

        $writeLines = array_filter(
            $driver->events,
            static fn (array $event): bool => 'writeLine' === $event['op'],
        );
        self::assertCount(1, $writeLines, 'banner row only — no status writeLine when statusLine is null');
    }

    #[Test]
    public function cellsAtNonZeroColumnEmitCursorForwardEscapeWithNonZeroDistance(): void
    {
        // The `$cell['col'] > 0` guard is what positions cells past column 0;
        // flipping the comparison so col=0 also emits `\033[0C` (no-op) is
        // visually equivalent, but flipping it so col>0 *doesn't* emit
        // collapses everything onto column 0 — checking for at least one
        // forward escape with a non-zero distance catches both directions
        // worth catching.
        $driver = new RecordingAnimationDriver();
        (new DripAnimation())->animate(
            $this->context(['abc'], stops: [[255, 0, 0]]),
            $driver,
        );

        $nonZeroForwardEscapes = array_filter(
            $driver->events,
            static fn (array $event): bool => 'write' === $event['op']
                && 1 === preg_match('/^\033\[[1-9]\d*C$/', (string) $event['value']),
        );
        self::assertGreaterThan(0, count($nonZeroForwardEscapes));
    }

    #[Test]
    public function bannerWithEmbeddedSpacesEmitsOnlyVisibleCells(): void
    {
        // 'a b' has 2 visible cells. Replacing the `continue` after a space
        // with `break` (Continue_ mutation) would stop collection at the
        // first space, yielding only 1 cell per line.
        $driver = new RecordingAnimationDriver();
        (new DripAnimation())->animate(
            $this->context(['a b', 'c d'], stops: [[255, 0, 0]]),
            $driver,
        );

        $cellEmissions = 0;
        foreach ($driver->events as $event) {
            if ('write' !== $event['op']) {
                continue;
            }
            if (1 === preg_match('/\033\[38;2;\d+;\d+;\d+m.\033\[0m/u', (string) $event['value'])) {
                ++$cellEmissions;
            }
        }
        self::assertSame(4, $cellEmissions);
    }

    #[Test]
    public function verticalDripSamplesEachRowAtItsOwnGradientFraction(): void
    {
        // 3-row vertical, red→blue. Row 1 → fraction 1/2 → mid (128,0,128).
        // The middle stop nails the line-104 Division mutation (`/` → `*`
        // would clamp row 1 to fraction 2.0 → blue).
        $driver = new RecordingAnimationDriver();
        (new DripAnimation())->animate(
            $this->context(['a', 'b', 'c'], stops: [[255, 0, 0], [0, 0, 255]]),
            $driver,
        );

        $cellEscapes = $this->collectCellEscapes($driver);
        self::assertContains("\033[38;2;255;0;0m", $cellEscapes);
        self::assertContains("\033[38;2;128;0;128m", $cellEscapes);
        self::assertContains("\033[38;2;0;0;255m", $cellEscapes);
    }

    #[Test]
    public function verticalDripWithSingleRowBannerSamplesAtZeroFraction(): void
    {
        // Single-row vertical takes the `: 0.0` ternary branch on line 104.
        // OneZeroFloat mutation (0.0 → 1.0) would emit the last stop instead.
        $driver = new RecordingAnimationDriver();
        (new DripAnimation())->animate(
            $this->context(['abc'], stops: [[255, 0, 0], [0, 0, 255]]),
            $driver,
        );

        $cellEscapes = $this->collectCellEscapes($driver);
        self::assertContains("\033[38;2;255;0;0m", $cellEscapes);
        self::assertNotContains("\033[38;2;0;0;255m", $cellEscapes);
    }

    #[Test]
    public function horizontalDripSamplesEachVisibleCharAtItsOwnGradientFraction(): void
    {
        // 3 visible chars, red→blue: char 0 → red, char 1 → mid, char 2 → blue.
        $driver = new RecordingAnimationDriver();
        (new DripAnimation())->animate(
            $this->context(
                ['abc'],
                stops: [[255, 0, 0], [0, 0, 255]],
                direction: Direction::Horizontal,
            ),
            $driver,
        );

        $cellEscapes = $this->collectCellEscapes($driver);
        self::assertContains("\033[38;2;255;0;0m", $cellEscapes);
        self::assertContains("\033[38;2;128;0;128m", $cellEscapes);
        self::assertContains("\033[38;2;0;0;255m", $cellEscapes);
    }

    #[Test]
    public function horizontalDripWithSingleVisibleCharSamplesAtZeroFraction(): void
    {
        // Hits the `: 0.0` branch of the horizontal ternary on line 108.
        $driver = new RecordingAnimationDriver();
        (new DripAnimation())->animate(
            $this->context(
                ['x'],
                stops: [[255, 0, 0], [0, 0, 255]],
                direction: Direction::Horizontal,
            ),
            $driver,
        );

        $cellEscapes = $this->collectCellEscapes($driver);
        self::assertContains("\033[38;2;255;0;0m", $cellEscapes);
        self::assertNotContains("\033[38;2;0;0;255m", $cellEscapes);
    }

    #[Test]
    public function diagonalDripSamplesEachCellAtItsAspectNormalizedFraction(): void
    {
        // 3×3 diagonal red→blue. Cell (1, 2) at base = (0.5 + 1)/2 = 0.75 →
        // (64, 0, 191). With Division mutated on line 105 (col / (maxWidth-1)
        // → col * (maxWidth-1)) the column fraction at col=2 jumps to 4.0
        // and the cell clamps to blue — no (64, 0, 191) in the output.
        $driver = new RecordingAnimationDriver();
        (new DripAnimation())->animate(
            $this->context(
                ['abc', 'def', 'ghi'],
                stops: [[255, 0, 0], [0, 0, 255]],
                direction: Direction::Diagonal,
            ),
            $driver,
        );

        $cellEscapes = $this->collectCellEscapes($driver);
        self::assertContains("\033[38;2;255;0;0m", $cellEscapes);
        self::assertContains("\033[38;2;0;0;255m", $cellEscapes);
        self::assertContains("\033[38;2;64;0;191m", $cellEscapes);
    }

    #[Test]
    public function diagonalDripWithSingleColumnBannerUsesZeroColumnFraction(): void
    {
        // maxWidth=1 takes the `: 0.0` branch on line 105. (0,0) → fraction 0
        // → red; (1,0) → fraction (1+0)/2 = 0.5 → mid (128,0,128).
        // OneZeroFloat (0.0 → 1.0) would push columnFraction to 1.0,
        // shifting (0,0) to mid and (1,0) to blue.
        $driver = new RecordingAnimationDriver();
        (new DripAnimation())->animate(
            $this->context(
                ['a', 'b'],
                stops: [[255, 0, 0], [0, 0, 255]],
                direction: Direction::Diagonal,
            ),
            $driver,
        );

        $cellEscapes = $this->collectCellEscapes($driver);
        self::assertContains("\033[38;2;255;0;0m", $cellEscapes);
        self::assertContains("\033[38;2;128;0;128m", $cellEscapes);
    }

    #[Test]
    public function multibyteBannerProducesOneCellPerGlyphNotPerByte(): void
    {
        // Catches mb_str_split → str_split: bytes would be treated as cells
        // and inflate the count.
        $driver = new RecordingAnimationDriver();
        (new DripAnimation())->animate(
            $this->context(['█▀'], stops: [[255, 0, 0]]),
            $driver,
        );

        $cellEmissions = 0;
        foreach ($driver->events as $event) {
            if ('write' !== $event['op']) {
                continue;
            }
            if (1 === preg_match('/\033\[38;2;\d+;\d+;\d+m.\033\[0m/u', (string) $event['value'])) {
                ++$cellEmissions;
            }
        }
        self::assertSame(2, $cellEmissions);
    }

    #[Test]
    public function useColorTrueButNullStopsFallsBackToStatic(): void
    {
        $driver = new RecordingAnimationDriver();

        (new DripAnimation())->animate(
            $this->context(['hello'], stops: null, useColor: true),
            $driver,
        );

        $cursorSaves = array_filter(
            $driver->events,
            static fn (array $event): bool => 'write' === $event['op']
                && "\033[s" === (string) $event['value'],
        );
        self::assertSame([], array_values($cursorSaves));
    }

    #[Test]
    public function allSpacesBannerProducesNoRevealsAndNoCursorSave(): void
    {
        $driver = new RecordingAnimationDriver();

        (new DripAnimation())->animate(
            $this->context(['   ', '   '], stops: [[255, 0, 0]]),
            $driver,
        );

        $saveCount = count(array_filter(
            $driver->events,
            static fn (array $event): bool => 'write' === $event['op'] && "\033[s" === (string) $event['value'],
        ));
        $sleepCount = count(array_filter(
            $driver->events,
            static fn (array $event): bool => 'sleep' === $event['op'],
        ));
        self::assertSame(0, $saveCount);
        self::assertSame(0, $sleepCount);
    }

    /**
     * @return list<string>
     */
    private function collectCellEscapes(RecordingAnimationDriver $driver): array
    {
        $escapes = [];
        foreach ($driver->events as $event) {
            if ('write' !== $event['op']) {
                continue;
            }
            if (1 === preg_match('/(\033\[38;2;\d+;\d+;\d+m).\033\[0m/u', (string) $event['value'], $match)) {
                $escapes[] = $match[1];
            }
        }

        return $escapes;
    }

    /**
     * @return list<array{int, int}> list of (row, col) pairs in reveal order
     */
    private function extractCellOrder(RecordingAnimationDriver $driver): array
    {
        $order = [];
        $saveSeen = false;
        $pendingLinesUp = null;
        $pendingCol = 0;
        foreach ($driver->events as $event) {
            if ('write' !== $event['op']) {
                continue;
            }
            $value = (string) $event['value'];
            if ("\033[s" === $value) {
                $saveSeen = true;
                continue;
            }
            if (!$saveSeen) {
                continue;
            }
            if (1 === preg_match('/^\033\[(\d+)F$/', $value, $matches)) {
                $pendingLinesUp = (int) $matches[1];
                $pendingCol = 0;
                continue;
            }
            if (1 === preg_match('/^\033\[(\d+)C$/', $value, $matches)) {
                $pendingCol = (int) $matches[1];
                continue;
            }
            if (null !== $pendingLinesUp
                && 1 === preg_match('/\033\[38;2;\d+;\d+;\d+m.\033\[0m/u', $value)) {
                $order[] = [$pendingLinesUp, $pendingCol];
                $pendingLinesUp = null;
                $pendingCol = 0;
            }
        }

        return $order;
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
