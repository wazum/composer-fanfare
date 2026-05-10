<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare;

use Composer\IO\IOInterface;

/**
 * @internal
 */
final readonly class Renderer
{
    private const ANSI_RESET = "\033[0m";
    private const ANSI_DIM = "\033[2m";
    private const HEX_PATTERN = '/^[0-9a-fA-F]{6}$/';

    public function __construct(private IOInterface $io)
    {
    }

    /**
     * @param list<string>      $lines      banner lines (no trailing newlines)
     * @param list<string>|null $colors     hex codes (with or without leading `#`); null = plain
     * @param string|null       $statusLine dim suffix below the banner; null/empty = none
     * @param Direction         $direction  vertical / Horizontal / Diagonal gradient flow
     */
    public function render(
        array $lines,
        ?array $colors,
        ?string $statusLine,
        Direction $direction = Direction::Vertical,
        ?Animation $animation = null,
    ): void {
        if ([] === $lines) {
            return;
        }

        $stops = $this->resolveStops($colors);
        $colorSupport = ColorSupport::detect($this->io);
        $useColor = null !== $stops && ColorSupport::None !== $colorSupport;

        $styledLines = $this->buildStyledLines($lines, $stops, $useColor, $direction, $colorSupport);
        $styledStatusLine = $this->buildStyledStatusLine($statusLine, $useColor);

        $this->io->writeRaw('');
        if (null !== $animation && $this->canAnimate()) {
            $animation->newRenderer()->animate(
                new AnimationContext(
                    lines: $lines,
                    styledLines: $styledLines,
                    stops: $stops,
                    direction: $direction,
                    colorSupport: $colorSupport,
                    useColor: $useColor,
                    statusLine: $styledStatusLine,
                ),
                new IoAnimationDriver($this->io),
            );
        } else {
            foreach ($styledLines as $styled) {
                $this->io->writeRaw($styled);
            }
            if (null !== $styledStatusLine) {
                $this->io->writeRaw($styledStatusLine);
            }
        }
        $this->io->writeRaw('');
    }

    /**
     * @param list<string>                              $lines
     * @param non-empty-list<array{int, int, int}>|null $stops
     *
     * @return list<string>
     */
    private function buildStyledLines(array $lines, ?array $stops, bool $useColor, Direction $direction, ColorSupport $colorSupport): array
    {
        $rowCount = count($lines);
        $maxWidth = Direction::Diagonal === $direction ? $this->maxLineWidth($lines) : 0;

        $output = [];
        foreach ($lines as $row => $line) {
            if (!$useColor || null === $stops || '' === $line) {
                $output[] = $line;
                continue;
            }
            $output[] = match ($direction) {
                Direction::Vertical => $this->colorizeWholeLine($line, $this->sampleGradient($stops, $this->fraction($row, $rowCount)), $colorSupport),
                Direction::Horizontal => $this->colorizeHorizontal($line, $stops, $colorSupport),
                Direction::Diagonal => $this->colorizeDiagonal($line, $row, $rowCount, $maxWidth, $stops, $colorSupport),
            };
        }

        return $output;
    }

    private function buildStyledStatusLine(?string $statusLine, bool $useColor): ?string
    {
        if (null === $statusLine || '' === $statusLine) {
            return null;
        }

        return $useColor ? self::ANSI_DIM.$statusLine.self::ANSI_RESET : $statusLine;
    }

    private function canAnimate(): bool
    {
        return $this->io->isDecorated() && $this->io->isInteractive();
    }

    /**
     * @param list<string>|null $colors
     *
     * @return non-empty-list<array{int, int, int}>|null
     */
    private function resolveStops(?array $colors): ?array
    {
        if (null === $colors || [] === $colors) {
            return null;
        }
        $stops = [];
        foreach ($colors as $hex) {
            $rgb = $this->hexToRgb($hex);
            if (null !== $rgb) {
                $stops[] = $rgb;
            }
        }

        return [] === $stops ? null : $stops;
    }

    /**
     * @param array{int, int, int} $rgb
     */
    private function colorizeWholeLine(string $text, array $rgb, ColorSupport $colorSupport): string
    {
        return $colorSupport->escape($rgb[0], $rgb[1], $rgb[2]).$text.self::ANSI_RESET;
    }

    /**
     * @param non-empty-list<array{int, int, int}> $stops
     */
    private function colorizeHorizontal(string $text, array $stops, ColorSupport $colorSupport): string
    {
        if ('' === $text) {
            return '';
        }

        $chars = mb_str_split($text);
        $visibleCount = 0;
        foreach ($chars as $char) {
            if (' ' !== $char) {
                ++$visibleCount;
            }
        }
        if (0 === $visibleCount) {
            return $text;
        }

        $output = '';
        $visibleIndex = 0;
        $previousRgb = null;
        foreach ($chars as $char) {
            if (' ' === $char) {
                $output .= $char;
                continue;
            }
            $rgb = $this->sampleGradient($stops, $this->fraction($visibleIndex, $visibleCount));
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
     * @param non-empty-list<array{int, int, int}> $stops
     */
    private function colorizeDiagonal(string $text, int $row, int $rowCount, int $maxWidth, array $stops, ColorSupport $colorSupport): string
    {
        if ('' === $text) {
            return '';
        }

        $rowFraction = $rowCount > 1 ? $row / ($rowCount - 1) : 0.0;
        $output = '';
        $previousRgb = null;
        foreach (mb_str_split($text) as $col => $char) {
            if (' ' === $char) {
                $output .= $char;
                continue;
            }
            $columnFraction = $maxWidth > 1 ? $col / ($maxWidth - 1) : 0.0;
            $rgb = $this->sampleGradient($stops, ($rowFraction + $columnFraction) / 2);
            if ($rgb !== $previousRgb) {
                $output .= $colorSupport->escape($rgb[0], $rgb[1], $rgb[2]);
                $previousRgb = $rgb;
            }
            $output .= $char;
        }

        return $output.self::ANSI_RESET;
    }

    private function fraction(int $index, int $total): float
    {
        return $total > 1 ? $index / ($total - 1) : 0.0;
    }

    /**
     * @param non-empty-list<array{int, int, int}> $stops
     *
     * @return array{int, int, int}
     */
    private function sampleGradient(array $stops, float $f): array
    {
        $count = count($stops);
        if (1 === $count) {
            return $stops[0];
        }
        $f = max(0.0, min(1.0, $f));
        $segments = $count - 1;
        $scaled = $f * $segments;
        $idx = (int) floor($scaled);
        if ($idx >= $segments) {
            return $stops[$count - 1];
        }
        $t = $scaled - $idx;

        return $this->lerpRgb($stops[$idx], $stops[$idx + 1], $t);
    }

    /**
     * @param array{int, int, int} $a
     * @param array{int, int, int} $b
     *
     * @return array{int, int, int}
     */
    private function lerpRgb(array $a, array $b, float $t): array
    {
        return [
            (int) round($a[0] + ($b[0] - $a[0]) * $t),
            (int) round($a[1] + ($b[1] - $a[1]) * $t),
            (int) round($a[2] + ($b[2] - $a[2]) * $t),
        ];
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
     * @return array{int, int, int}|null
     */
    private function hexToRgb(string $hex): ?array
    {
        $hex = ltrim($hex, '#');
        if (1 !== preg_match(self::HEX_PATTERN, $hex)) {
            return null;
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
