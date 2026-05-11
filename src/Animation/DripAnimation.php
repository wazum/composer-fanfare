<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Animation;

use Wazum\ComposerFanfare\Preset\Direction;
use Wazum\ComposerFanfare\Rendering\Gradient;
use Wazum\ComposerFanfare\Rendering\LineStyler;

/**
 * @internal
 */
final readonly class DripAnimation implements AnimationRenderer
{
    private const MIN_DELAY_MICROS = 1_000;
    private const MAX_DELAY_MICROS = 6_000;
    private const MAX_CHUNK_SIZE = 6;
    private const CELLS_PER_CHUNK_UNIT = 20;
    private const ANSI_RESET = "\033[0m";
    private const CURSOR_SAVE = "\033[s";
    private const CURSOR_RESTORE = "\033[u";
    private const CURSOR_PREVIOUS_LINE_FORMAT = "\033[%dF";
    private const CURSOR_FORWARD_FORMAT = "\033[%dC";

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
        $maxWidth = LineStyler::maxLineWidth($context->lines);
        $hasStatus = null !== $context->statusLine && '' !== $context->statusLine;

        // Reserve the banner area with blank lines + display the status line
        // immediately so the final layout is fixed before any cell appears.
        for ($i = 0; $i < $rowCount; ++$i) {
            $driver->writeLine('');
        }
        if ($hasStatus) {
            $driver->writeLine((string) $context->statusLine);
        }

        $cells = $this->collectCells($context, $stops, $rowCount, $maxWidth);
        if ([] === $cells) {
            return;
        }
        shuffle($cells);
        // Cells reveal in randomly-sized chunks: each tick the animation
        // emits 1..maxChunkSize cells back-to-back (no sleep between them,
        // so the terminal renders the burst as a "splash") and then sleeps
        // a random delay before the next splash. Larger banners get
        // proportionally larger chunks so the whole reveal stays snappy.
        $cellCount = count($cells);
        $maxChunkSize = max(1, min(self::MAX_CHUNK_SIZE, intdiv($cellCount, self::CELLS_PER_CHUNK_UNIT)));

        // Save cursor at the post-pre-render anchor; every reveal restores
        // back to it, navigates up to the target row, right to the target
        // column, prints the cell, and the next iteration jumps back.
        $driver->write(self::CURSOR_SAVE);
        $statusOffset = $hasStatus ? 1 : 0;

        $index = 0;
        while ($index < $cellCount) {
            $driver->sleep(random_int(self::MIN_DELAY_MICROS, self::MAX_DELAY_MICROS));
            $chunkEnd = min($cellCount, $index + random_int(1, $maxChunkSize));
            for (; $index < $chunkEnd; ++$index) {
                $cell = $cells[$index];
                $linesUp = $rowCount - $cell['row'] + $statusOffset;
                $driver->write(self::CURSOR_RESTORE);
                $driver->write(sprintf(self::CURSOR_PREVIOUS_LINE_FORMAT, $linesUp));
                if ($cell['col'] > 0) {
                    $driver->write(sprintf(self::CURSOR_FORWARD_FORMAT, $cell['col']));
                }
                $rgb = $cell['rgb'];
                $driver->write(
                    $context->colorSupport->escape($rgb[0], $rgb[1], $rgb[2]).$cell['char'].self::ANSI_RESET,
                );
            }
        }
        $driver->write(self::CURSOR_RESTORE);
    }

    /**
     * @param non-empty-list<array{int, int, int}> $stops
     *
     * @return list<array{row: int, col: int, char: string, rgb: array{int, int, int}}>
     */
    private function collectCells(AnimationContext $context, array $stops, int $rowCount, int $maxWidth): array
    {
        $cells = [];
        foreach ($context->lines as $row => $line) {
            $visibleCount = LineStyler::countVisibleChars($line);
            $visibleIndex = 0;
            foreach (mb_str_split($line) as $col => $char) {
                if (' ' === $char) {
                    continue;
                }
                $rowFraction = $rowCount > 1 ? $row / ($rowCount - 1) : 0.0;
                $columnFraction = $maxWidth > 1 ? $col / ($maxWidth - 1) : 0.0;
                $fraction = match ($context->direction) {
                    Direction::Vertical => $rowFraction,
                    Direction::Horizontal => $visibleCount > 1 ? $visibleIndex / ($visibleCount - 1) : 0.0,
                    Direction::Diagonal => ($rowFraction + $columnFraction) / 2,
                };
                $cells[] = [
                    'row' => $row,
                    'col' => $col,
                    'char' => $char,
                    'rgb' => Gradient::sample($stops, $fraction),
                ];
                ++$visibleIndex;
            }
        }

        return $cells;
    }
}
