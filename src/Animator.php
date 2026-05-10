<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare;

/**
 * @internal
 */
final readonly class Animator
{
    public const MAX_DELAY_MICROS = 800;
    public const TYPEWRITER_BUDGET_MICROS = 120_000;

    private const ESCAPE_BYTE = "\033";
    private const ESCAPE_TERMINATOR = 'm';

    public function __construct(private AnimationDriver $driver)
    {
    }

    /**
     * Per-character delay scaled so the whole reveal fits within
     * TYPEWRITER_BUDGET_MICROS, capped at MAX_DELAY_MICROS for short banners.
     */
    public static function typewriterDelayMicros(int $visibleCharCount): int
    {
        if ($visibleCharCount <= 0) {
            return 0;
        }

        return min(self::MAX_DELAY_MICROS, intdiv(self::TYPEWRITER_BUDGET_MICROS, $visibleCharCount));
    }

    /**
     * @param list<string> $lines
     */
    public function animate(array $lines, ?string $statusLine, Animation $animation): void
    {
        match ($animation) {
            Animation::Typewriter => $this->typewriter($lines),
        };
        if (null !== $statusLine && '' !== $statusLine) {
            $this->driver->writeLine($statusLine);
        }
    }

    /**
     * @param list<string> $lines
     */
    private function typewriter(array $lines): void
    {
        $delay = self::typewriterDelayMicros($this->countVisibleChars($lines));
        foreach ($lines as $line) {
            foreach ($this->chunkLine($line) as [$chunk, $isVisible]) {
                $this->driver->write($chunk);
                if ($isVisible) {
                    $this->driver->sleep($delay);
                }
            }
            $this->driver->writeLine('');
        }
    }

    /**
     * @param list<string> $lines
     */
    private function countVisibleChars(array $lines): int
    {
        $count = 0;
        foreach ($lines as $line) {
            foreach ($this->chunkLine($line) as [, $isVisible]) {
                if ($isVisible) {
                    ++$count;
                }
            }
        }

        return $count;
    }

    /**
     * @return list<array{0: string, 1: bool}>
     */
    private function chunkLine(string $line): array
    {
        $chunks = [];
        $length = strlen($line);
        $cursor = 0;
        $escapeBuffer = '';
        while ($cursor < $length) {
            if (self::ESCAPE_BYTE === $line[$cursor]) {
                $end = strpos($line, self::ESCAPE_TERMINATOR, $cursor);
                if (false === $end) {
                    break;
                }
                $escapeBuffer .= substr($line, $cursor, $end - $cursor + 1);
                $cursor = $end + 1;
                continue;
            }
            $charLength = $this->utf8CharLength($line[$cursor]);
            $chunks[] = [$escapeBuffer.substr($line, $cursor, $charLength), true];
            $escapeBuffer = '';
            $cursor += $charLength;
        }
        if ('' !== $escapeBuffer) {
            $chunks[] = [$escapeBuffer, false];
        }

        return $chunks;
    }

    private function utf8CharLength(string $byte): int
    {
        $value = ord($byte);
        if ($value < 0xC0) {
            return 1;
        }
        if ($value < 0xE0) {
            return 2;
        }
        if ($value < 0xF0) {
            return 3;
        }

        return 4;
    }
}
