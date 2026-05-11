<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Animation;

use Composer\IO\IOInterface;

/**
 * @internal
 */
final readonly class IoAnimationDriver implements AnimationDriver
{
    public function __construct(private IOInterface $io)
    {
    }

    public function write(string $text): void
    {
        $this->io->writeRaw($text, false);
    }

    public function writeLine(string $text): void
    {
        $this->io->writeRaw($text, true);
    }

    public function sleep(int $microseconds): void
    {
        if ($microseconds > 0) {
            usleep($microseconds);
        }
    }
}
