<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Tests\Rendering;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\StreamOutput;
use Wazum\ComposerFanfare\Animation\Animation;
use Wazum\ComposerFanfare\Preset\Direction;
use Wazum\ComposerFanfare\Rendering\Renderer;
use Wazum\ComposerFanfare\Tests\Support\InteractiveBufferIO;

final class RendererAnimationTest extends TestCase
{
    #[Test]
    public function animatedRenderProducesSameByteSequenceAsStaticRender(): void
    {
        $io = $this->interactiveIo();

        (new Renderer($io))->render(['ab'], ['#ff0000', '#00ff00'], null, Direction::Horizontal, Animation::Typewriter);

        // The animator emits chunks via writeRaw(no-newline) plus a trailing newline-only
        // writeLine, while the static path emits the whole line via one writeRaw with
        // a newline. Exact-byte match guards against any spurious newlines per chunk;
        // the only extra bytes allowed are the cursor hide/show wrapper.
        self::assertSame(
            "\n\033[?25l\033[38;2;255;0;0ma\033[38;2;0;255;0mb\033[0m\n\033[?25h\n",
            $io->getOutput(),
        );
    }

    #[Test]
    public function nonInteractiveIoFallsBackToStaticPathEvenWhenAnimationRequested(): void
    {
        $io = $this->nonInteractiveIo();

        (new Renderer($io))->render(['hi'], ['#ff0000'], null, Direction::Vertical, Animation::Typewriter);

        // Non-interactive path is identical byte-wise to a non-animated render —
        // ensures animation does not produce stray escapes when the IO can't honor it.
        self::assertSame(
            "\n\033[38;2;255;0;0mhi\033[0m\n\n",
            $io->getOutput(),
        );
    }

    #[Test]
    public function animatedRenderHidesCursorAndRestoresItAfterwards(): void
    {
        $io = $this->interactiveIo();

        (new Renderer($io))->render(['hi'], ['#ff0000'], null, Direction::Vertical, Animation::Typewriter);

        $output = $io->getOutput();
        $hidePosition = strpos($output, "\033[?25l");
        $showPosition = strpos($output, "\033[?25h");
        self::assertNotFalse($hidePosition);
        self::assertNotFalse($showPosition);
        self::assertLessThan($showPosition, $hidePosition);
        self::assertStringContainsString('hi', substr($output, $hidePosition, $showPosition - $hidePosition));
    }

    #[Test]
    public function staticRenderEmitsNoCursorVisibilityEscapes(): void
    {
        $io = $this->interactiveIo();

        (new Renderer($io))->render(['hi'], ['#ff0000'], null);

        self::assertStringNotContainsString("\033[?25", $io->getOutput());
    }

    protected function setUp(): void
    {
        putenv('COLORTERM=truecolor');
    }

    protected function tearDown(): void
    {
        putenv('COLORTERM');
    }

    private function interactiveIo(): InteractiveBufferIO
    {
        return new InteractiveBufferIO('', StreamOutput::VERBOSITY_NORMAL, new OutputFormatter(true));
    }

    private function nonInteractiveIo(): \Composer\IO\BufferIO
    {
        return new \Composer\IO\BufferIO('', StreamOutput::VERBOSITY_NORMAL, new OutputFormatter(true));
    }
}
