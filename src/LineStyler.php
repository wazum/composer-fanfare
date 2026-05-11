<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare;

/**
 * @internal
 */
final readonly class LineStyler
{
    private const ANSI_RESET = "\033[0m";

    /**
     * @param array{int, int, int} $rgb
     */
    public static function wholeLine(string $line, array $rgb, ColorSupport $colorSupport): string
    {
        if ('' === $line) {
            return '';
        }

        return $colorSupport->escape($rgb[0], $rgb[1], $rgb[2]).$line.self::ANSI_RESET;
    }

    /**
     * @param list<array{int, int, int}> $rgbPerVisibleIndex indexed 0..visibleCount-1
     */
    public static function byVisibleChar(string $line, array $rgbPerVisibleIndex, ColorSupport $colorSupport): string
    {
        if ('' === $line || [] === $rgbPerVisibleIndex) {
            return $line;
        }

        $output = '';
        $visibleIndex = 0;
        $previousRgb = null;
        foreach (mb_str_split($line) as $char) {
            if (' ' === $char) {
                $output .= $char;
                continue;
            }
            $rgb = $rgbPerVisibleIndex[$visibleIndex];
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
     * @param list<array{int, int, int}> $rgbPerCol indexed by 0..maxWidth-1
     */
    public static function byColumn(string $line, array $rgbPerCol, ColorSupport $colorSupport): string
    {
        if ('' === $line) {
            return '';
        }

        $output = '';
        $previousRgb = null;
        foreach (mb_str_split($line) as $col => $char) {
            if (' ' === $char) {
                $output .= $char;
                continue;
            }
            $rgb = $rgbPerCol[$col];
            if ($rgb !== $previousRgb) {
                $output .= $colorSupport->escape($rgb[0], $rgb[1], $rgb[2]);
                $previousRgb = $rgb;
            }
            $output .= $char;
        }

        return $output.self::ANSI_RESET;
    }

    public static function countVisibleChars(string $line): int
    {
        $count = 0;
        foreach (mb_str_split($line) as $char) {
            if (' ' !== $char) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param list<string> $lines
     */
    public static function maxLineWidth(array $lines): int
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
    public static function maxVisibleCount(array $lines): int
    {
        $max = 0;
        foreach ($lines as $line) {
            $visible = self::countVisibleChars($line);
            if ($visible > $max) {
                $max = $visible;
            }
        }

        return $max;
    }
}
