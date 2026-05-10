<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare;

/**
 * @internal
 */
interface AnimationDriver
{
    public function write(string $text): void;

    public function writeLine(string $text): void;

    public function sleep(int $microseconds): void;
}
