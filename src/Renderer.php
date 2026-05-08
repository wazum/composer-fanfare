<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare;

use Composer\IO\IOInterface;

/**
 * @internal
 */
final readonly class Renderer
{
    private const ANSI_RESET            = "\033[0m";
    private const ANSI_DIM              = "\033[2m";
    private const ANSI_TRUECOLOR_FG_FMT = "\033[38;2;%d;%d;%dm";
    private const HEX_PATTERN           = '/^[0-9a-fA-F]{6}$/';

    public function __construct(private IOInterface $io) {}

    /**
     * @param list<string>      $lines      Banner lines (no trailing newlines).
     * @param list<string>|null $colors     Hex codes (with or without leading `#`); null = plain.
     * @param string|null       $statusLine Dim suffix below the banner; null/empty = none.
     * @param Direction         $direction  Vertical / Horizontal / Diagonal gradient flow.
     */
    public function render(
        array $lines,
        ?array $colors,
        ?string $statusLine,
        Direction $direction = Direction::Vertical,
    ): void {
        if ($lines === []) {
            return;
        }

        $stops = $this->resolveStops($colors);
        $useColor = $stops !== null && $this->supportsColor();

        $rowCount = count($lines);
        $maxWidth = $direction === Direction::Diagonal ? $this->maxLineWidth($lines) : 0;

        $this->io->writeRaw('');
        foreach ($lines as $row => $line) {
            if (!$useColor) {
                $this->io->writeRaw($line);
                continue;
            }
            $this->io->writeRaw(match ($direction) {
                Direction::Vertical   => $this->colorizeWholeLine($line, $this->sampleGradient($stops, $this->fraction($row, $rowCount))),
                Direction::Horizontal => $this->colorizeHorizontal($line, $stops),
                Direction::Diagonal   => $this->colorizeDiagonal($line, $row, $rowCount, $maxWidth, $stops),
            });
        }

        if ($statusLine !== null && $statusLine !== '') {
            $this->io->writeRaw($useColor ? self::ANSI_DIM . $statusLine . self::ANSI_RESET : $statusLine);
        }
        $this->io->writeRaw('');
    }

    private function supportsColor(): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }

        return $this->io->isDecorated();
    }

    /**
     * @param list<string>|null $colors
     * @return non-empty-list<array{int, int, int}>|null
     */
    private function resolveStops(?array $colors): ?array
    {
        if ($colors === null || $colors === []) {
            return null;
        }
        $stops = [];
        foreach ($colors as $hex) {
            $rgb = $this->hexToRgb($hex);
            if ($rgb !== null) {
                $stops[] = $rgb;
            }
        }

        return $stops === [] ? null : $stops;
    }

    /**
     * @param array{int, int, int} $rgb
     */
    private function colorizeWholeLine(string $text, array $rgb): string
    {
        [$r, $g, $b] = $rgb;

        return sprintf(self::ANSI_TRUECOLOR_FG_FMT, $r, $g, $b) . $text . self::ANSI_RESET;
    }

    /**
     * @param non-empty-list<array{int, int, int}> $stops
     */
    private function colorizeHorizontal(string $text, array $stops): string
    {
        if ($text === '') {
            return '';
        }

        $chars = mb_str_split($text);
        $visibleCount = 0;
        foreach ($chars as $char) {
            if ($char !== ' ') {
                ++$visibleCount;
            }
        }
        if ($visibleCount === 0) {
            return $text;
        }

        $output = '';
        $visibleIndex = 0;
        foreach ($chars as $char) {
            if ($char === ' ') {
                $output .= $char;
                continue;
            }
            [$r, $g, $b] = $this->sampleGradient($stops, $this->fraction($visibleIndex, $visibleCount));
            ++$visibleIndex;
            $output .= sprintf(self::ANSI_TRUECOLOR_FG_FMT, $r, $g, $b) . $char;
        }

        return $output . self::ANSI_RESET;
    }

    /**
     * @param non-empty-list<array{int, int, int}> $stops
     */
    private function colorizeDiagonal(string $text, int $row, int $rowCount, int $maxWidth, array $stops): string
    {
        if ($text === '') {
            return '';
        }

        $rowFraction = $rowCount > 1 ? $row / ($rowCount - 1) : 0.0;
        $output = '';
        foreach (mb_str_split($text) as $col => $char) {
            if ($char === ' ') {
                $output .= $char;
                continue;
            }
            $columnFraction = $maxWidth > 1 ? $col / ($maxWidth - 1) : 0.0;
            [$r, $g, $b] = $this->sampleGradient($stops, ($rowFraction + $columnFraction) / 2);
            $output .= sprintf(self::ANSI_TRUECOLOR_FG_FMT, $r, $g, $b) . $char;
        }

        return $output . self::ANSI_RESET;
    }

    private function fraction(int $index, int $total): float
    {
        return $total > 1 ? $index / ($total - 1) : 0.0;
    }

    /**
     * @param non-empty-list<array{int, int, int}> $stops
     * @return array{int, int, int}
     */
    private function sampleGradient(array $stops, float $f): array
    {
        $count = count($stops);
        if ($count === 1) {
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
        if (preg_match(self::HEX_PATTERN, $hex) !== 1) {
            return null;
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
