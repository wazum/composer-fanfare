<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Rendering;

use Composer\IO\IOInterface;
use Wazum\ComposerFanfare\Animation\Animation;
use Wazum\ComposerFanfare\Animation\AnimationContext;
use Wazum\ComposerFanfare\Animation\IoAnimationDriver;
use Wazum\ComposerFanfare\Preset\Direction;

/**
 * @internal
 */
final readonly class Renderer
{
    private const ANSI_RESET = "\033[0m";
    private const ANSI_DIM = "\033[2m";
    private const ANSI_CURSOR_HIDE = "\033[?25l";
    private const ANSI_CURSOR_SHOW = "\033[?25h";
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
            $driver = new IoAnimationDriver($this->io);
            $driver->write(self::ANSI_CURSOR_HIDE);
            try {
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
                    $driver,
                );
            } finally {
                $driver->write(self::ANSI_CURSOR_SHOW);
            }
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
        $maxWidth = Direction::Diagonal === $direction ? LineStyler::maxLineWidth($lines) : 0;

        $output = [];
        foreach ($lines as $row => $line) {
            if (!$useColor || null === $stops || '' === $line) {
                $output[] = $line;
                continue;
            }
            $output[] = match ($direction) {
                Direction::Vertical => LineStyler::wholeLine($line, Gradient::sample($stops, $this->fraction($row, $rowCount)), $colorSupport),
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
     * @param non-empty-list<array{int, int, int}> $stops
     */
    private function colorizeHorizontal(string $text, array $stops, ColorSupport $colorSupport): string
    {
        $visibleCount = LineStyler::countVisibleChars($text);
        if (0 === $visibleCount) {
            return $text;
        }
        $rgbs = [];
        for ($i = 0; $i < $visibleCount; ++$i) {
            $rgbs[] = Gradient::sample($stops, $this->fraction($i, $visibleCount));
        }

        return LineStyler::byVisibleChar($text, $rgbs, $colorSupport);
    }

    /**
     * @param non-empty-list<array{int, int, int}> $stops
     */
    private function colorizeDiagonal(string $text, int $row, int $rowCount, int $maxWidth, array $stops, ColorSupport $colorSupport): string
    {
        $rowFraction = $this->fraction($row, $rowCount);
        $rgbs = [];
        for ($col = 0; $col < $maxWidth; ++$col) {
            $columnFraction = $maxWidth > 1 ? $col / ($maxWidth - 1) : 0.0;
            $rgbs[] = Gradient::sample($stops, ($rowFraction + $columnFraction) / 2);
        }

        return LineStyler::byColumn($text, $rgbs, $colorSupport);
    }

    private function fraction(int $index, int $total): float
    {
        return $total > 1 ? $index / ($total - 1) : 0.0;
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
