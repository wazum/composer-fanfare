<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare;

/**
 * @internal
 */
final readonly class ShimmerAnimation implements AnimationRenderer
{
    private const TOTAL_BUDGET_MICROS = 400_000;
    private const MIN_FRAMES = 4;
    private const MAX_FRAMES = 16;
    private const ANSI_RESET = "\033[0m";
    private const CURSOR_UP_FORMAT = "\033[%dF";

    public function animate(AnimationContext $context, AnimationDriver $driver): void
    {
        $stops = $context->stops;
        if (!$context->useColor || null === $stops) {
            foreach ($context->styledLines as $line) {
                $driver->writeLine($line);
            }
            if (null !== $context->statusLine && '' !== $context->statusLine) {
                $driver->writeLine($context->statusLine);
            }

            return;
        }

        $rowCount = count($context->lines);
        $maxWidth = $this->maxLineWidth($context->lines);
        $frameCount = $this->frameCount($context, $rowCount);
        $perFrameMicros = intdiv(self::TOTAL_BUDGET_MICROS, $frameCount - 1);
        $hasStatus = null !== $context->statusLine && '' !== $context->statusLine;

        // Show the complete banner + footer immediately so the user sees the
        // final layout straight away — animation only redraws the banner area.
        $this->writeFrame($driver, $context, $rowCount, $maxWidth, $stops, 0.0);
        if ($hasStatus) {
            $driver->writeLine((string) $context->statusLine);
        }

        // Phase ramps 0 → 1 across frames; the last frame snaps back to 0 so
        // the banner always settles in the same state as the pre-render.
        $cursorUpRows = $hasStatus ? $rowCount + 1 : $rowCount;
        for ($frame = 1; $frame < $frameCount; ++$frame) {
            $driver->sleep($perFrameMicros);
            $driver->write(sprintf(self::CURSOR_UP_FORMAT, $cursorUpRows));
            $cursorUpRows = $rowCount;
            $phase = ($frame === $frameCount - 1) ? 0.0 : $frame / ($frameCount - 1);
            $this->writeFrame($driver, $context, $rowCount, $maxWidth, $stops, $phase);
        }
    }

    private function frameCount(AnimationContext $context, int $rowCount): int
    {
        $cycleLength = match ($context->direction) {
            Direction::Vertical => max(1, $rowCount),
            Direction::Horizontal => max(1, $this->maxVisibleCount($context->lines)),
            // Diagonal uses continuous phase, so the cycle is purely a frame budget.
            Direction::Diagonal => self::MAX_FRAMES,
        };

        return max(self::MIN_FRAMES, min(self::MAX_FRAMES, $cycleLength) + 1);
    }

    /**
     * @param non-empty-list<array{int, int, int}> $stops
     */
    private function writeFrame(
        AnimationDriver $driver,
        AnimationContext $context,
        int $rowCount,
        int $maxWidth,
        array $stops,
        float $phase,
    ): void {
        foreach ($context->lines as $row => $line) {
            $driver->writeLine($this->styleLine(
                $line,
                $row,
                $rowCount,
                $maxWidth,
                $stops,
                $context->direction,
                $context->colorSupport,
                $phase,
            ));
        }
    }

    /**
     * @param non-empty-list<array{int, int, int}> $stops
     */
    private function styleLine(
        string $line,
        int $row,
        int $rowCount,
        int $maxWidth,
        array $stops,
        Direction $direction,
        ColorSupport $colorSupport,
        float $phase,
    ): string {
        if ('' === $line) {
            return '';
        }

        return match ($direction) {
            Direction::Vertical => $this->styleVertical($line, $row, $rowCount, $stops, $colorSupport, $phase),
            Direction::Horizontal => $this->styleHorizontal($line, $stops, $colorSupport, $phase),
            Direction::Diagonal => $this->styleDiagonal($line, $row, $rowCount, $maxWidth, $stops, $colorSupport, $phase),
        };
    }

    /**
     * @param non-empty-list<array{int, int, int}> $stops
     */
    private function styleVertical(string $line, int $row, int $rowCount, array $stops, ColorSupport $colorSupport, float $phase): string
    {
        $shift = (int) round($phase * $rowCount);
        $sampleIndex = ($row + $shift) % max(1, $rowCount);
        $rgb = Gradient::sample($stops, $rowCount > 1 ? $sampleIndex / ($rowCount - 1) : 0.0);

        return $colorSupport->escape($rgb[0], $rgb[1], $rgb[2]).$line.self::ANSI_RESET;
    }

    /**
     * @param non-empty-list<array{int, int, int}> $stops
     */
    private function styleHorizontal(string $line, array $stops, ColorSupport $colorSupport, float $phase): string
    {
        $chars = mb_str_split($line);
        $visibleCount = 0;
        foreach ($chars as $char) {
            if (' ' !== $char) {
                ++$visibleCount;
            }
        }
        if (0 === $visibleCount) {
            return $line;
        }
        $shift = (int) round($phase * $visibleCount);

        $output = '';
        $visibleIndex = 0;
        $previousRgb = null;
        foreach ($chars as $char) {
            if (' ' === $char) {
                $output .= $char;
                continue;
            }
            $sampleIndex = ($visibleIndex + $shift) % $visibleCount;
            $rgb = Gradient::sample($stops, $visibleCount > 1 ? $sampleIndex / ($visibleCount - 1) : 0.0);
            ++$visibleIndex;
            if ($rgb !== $previousRgb) {
                $output .= $colorSupport->escape($rgb[0], $rgb[1], $rgb[2]);
                $previousRgb = $rgb;
            }
            $output .= $char;
        }

        return $output.self::ANSI_RESET;
    }

    /**
     * Continuous phase shift on the aspect-normalized fraction. The whole
     * gradient plane slides as one — no axis-rate mismatch — at the cost of
     * a small amount of color interpolation between the static positions.
     *
     * @param non-empty-list<array{int, int, int}> $stops
     */
    private function styleDiagonal(string $line, int $row, int $rowCount, int $maxWidth, array $stops, ColorSupport $colorSupport, float $phase): string
    {
        $rowFraction = $rowCount > 1 ? $row / ($rowCount - 1) : 0.0;
        $output = '';
        $previousRgb = null;
        foreach (mb_str_split($line) as $col => $char) {
            if (' ' === $char) {
                $output .= $char;
                continue;
            }
            $columnFraction = $maxWidth > 1 ? $col / ($maxWidth - 1) : 0.0;
            $combined = ($rowFraction + $columnFraction) / 2 + $phase;
            // Wrap > 1 only — `<=` keeps an exact 1.0 boundary on the last
            // stop instead of folding it back to the first.
            $shifted = $combined <= 1.0 ? $combined : $combined - 1.0;
            $rgb = Gradient::sample($stops, $shifted);
            if ($rgb !== $previousRgb) {
                $output .= $colorSupport->escape($rgb[0], $rgb[1], $rgb[2]);
                $previousRgb = $rgb;
            }
            $output .= $char;
        }

        return $output.self::ANSI_RESET;
    }

    /**
     * @param list<string> $lines
     */
    private function maxLineWidth(array $lines): int
    {
        $max = 0;
        foreach ($lines as $line) {
            $width = mb_strlen($line);
            if ($width > $max) {
                $max = $width;
            }
        }

        return $max;
    }

    /**
     * @param list<string> $lines
     */
    private function maxVisibleCount(array $lines): int
    {
        $max = 0;
        foreach ($lines as $line) {
            $visible = 0;
            foreach (mb_str_split($line) as $char) {
                if (' ' !== $char) {
                    ++$visible;
                }
            }
            if ($visible > $max) {
                $max = $visible;
            }
        }

        return $max;
    }
}
