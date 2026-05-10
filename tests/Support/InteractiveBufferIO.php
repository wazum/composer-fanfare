<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Tests\Support;

use Composer\IO\BufferIO;

/**
 * BufferIO that reports as interactive — lets us exercise the animation path
 * without spinning up a real TTY.
 *
 * @internal
 */
final class InteractiveBufferIO extends BufferIO
{
    public function isInteractive(): bool
    {
        return true;
    }
}
