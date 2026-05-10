<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Tests\Support;

use Wazum\ComposerFanfare\AnimationDriver;

/**
 * @internal
 */
final class RecordingAnimationDriver implements AnimationDriver
{
    /** @var list<array{op: string, value: string|int}> */
    public array $events = [];

    public function write(string $text): void
    {
        $this->events[] = ['op' => 'write', 'value' => $text];
    }

    public function writeLine(string $text): void
    {
        $this->events[] = ['op' => 'writeLine', 'value' => $text];
    }

    public function sleep(int $microseconds): void
    {
        $this->events[] = ['op' => 'sleep', 'value' => $microseconds];
    }

    public function totalSleepMicros(): int
    {
        $total = 0;
        foreach ($this->events as $event) {
            if ('sleep' === $event['op']) {
                $total += (int) $event['value'];
            }
        }

        return $total;
    }

    public function emittedText(): string
    {
        $text = '';
        foreach ($this->events as $event) {
            if ('write' === $event['op']) {
                $text .= (string) $event['value'];
            } elseif ('writeLine' === $event['op']) {
                $text .= (string) $event['value']."\n";
            }
        }

        return $text;
    }
}
