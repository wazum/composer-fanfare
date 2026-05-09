<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Tests;

use Composer\IO\BufferIO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\StreamOutput;
use Wazum\ComposerFanfare\Direction;
use Wazum\ComposerFanfare\Renderer;

final class RendererTest extends TestCase
{
    #[Test]
    public function emptyLinesProducesNoOutput(): void
    {
        $io = $this->decoratedIo();
        (new Renderer($io))->render([], ['#ff0000'], 'PHP 8.2.30');

        self::assertSame('', $io->getOutput());
    }

    #[Test]
    public function plainOutputWhenColorsIsNull(): void
    {
        $io = $this->decoratedIo();
        (new Renderer($io))->render(['hello'], null, null);

        $output = $io->getOutput();
        self::assertStringContainsString('hello', $output);
        self::assertStringNotContainsString("\033[", $output);
    }

    #[Test]
    public function singleHexColor(): void
    {
        $io = $this->decoratedIo();
        (new Renderer($io))->render(['line1', 'line2'], ['#ff0000'], null);

        $output = $io->getOutput();
        self::assertStringContainsString("\033[38;2;255;0;0mline1\033[0m", $output);
        self::assertStringContainsString("\033[38;2;255;0;0mline2\033[0m", $output);
    }

    #[Test]
    public function hexWithoutLeadingHash(): void
    {
        $io = $this->decoratedIo();
        (new Renderer($io))->render(['x'], ['00ff00'], null);

        self::assertStringContainsString("\033[38;2;0;255;0mx\033[0m", $io->getOutput());
    }

    #[Test]
    public function invalidHexFallsThroughUncolored(): void
    {
        $io = $this->decoratedIo();
        (new Renderer($io))->render(['x'], ['notahex'], null);

        $output = $io->getOutput();
        self::assertStringContainsString('x', $output);
        self::assertStringNotContainsString("\033[38;", $output);
    }

    #[Test]
    public function verticalInterpolatesAcrossLines(): void
    {
        $io = $this->decoratedIo();
        (new Renderer($io))->render(
            ['a', 'b', 'c', 'd'],
            ['#ff0000', '#00ff00'],
            null,
        );

        $output = $io->getOutput();
        self::assertStringContainsString("\033[38;2;255;0;0ma\033[0m", $output);
        self::assertStringContainsString("\033[38;2;170;85;0mb\033[0m", $output);
        self::assertStringContainsString("\033[38;2;85;170;0mc\033[0m", $output);
        self::assertStringContainsString("\033[38;2;0;255;0md\033[0m", $output);
    }

    #[Test]
    public function noColorEnvDisablesColor(): void
    {
        putenv('NO_COLOR=1');
        $io = $this->decoratedIo();
        (new Renderer($io))->render(['hello'], ['#ff0000'], null);

        $output = $io->getOutput();
        self::assertStringContainsString('hello', $output);
        self::assertStringNotContainsString("\033[", $output);
    }

    #[Test]
    public function nonDecoratedIoDisablesColor(): void
    {
        $io = $this->plainIo();
        (new Renderer($io))->render(['hello'], ['#ff0000'], null);

        $output = $io->getOutput();
        self::assertStringContainsString('hello', $output);
        self::assertStringNotContainsString("\033[", $output);
    }

    #[Test]
    public function statusLineDimWhenColored(): void
    {
        $io = $this->decoratedIo();
        (new Renderer($io))->render(['x'], ['#ff0000'], 'PHP 8.2.30 · 12 packages');

        self::assertStringContainsString("\033[2mPHP 8.2.30 · 12 packages\033[0m", $io->getOutput());
    }

    #[Test]
    public function statusLinePlainWhenNotColored(): void
    {
        $io = $this->plainIo();
        (new Renderer($io))->render(['x'], null, 'PHP 8.2.30');

        $output = $io->getOutput();
        self::assertStringContainsString('PHP 8.2.30', $output);
        self::assertStringNotContainsString("\033[", $output);
    }

    #[Test]
    public function statusLineOmittedWhenNull(): void
    {
        $io = $this->plainIo();
        (new Renderer($io))->render(['x'], null, null);

        self::assertStringNotContainsString('PHP', $io->getOutput());
    }

    #[Test]
    public function emptyColorsArrayTreatedAsPlain(): void
    {
        $io = $this->decoratedIo();
        (new Renderer($io))->render(['hello'], [], null);

        self::assertStringNotContainsString("\033[", $io->getOutput());
    }

    #[Test]
    public function horizontalInterpolatesAcrossCharacters(): void
    {
        $io = $this->decoratedIo();
        (new Renderer($io))->render(
            ['abc'],
            ['#ff0000', '#00ff00'],
            null,
            Direction::Horizontal,
        );

        $output = $io->getOutput();
        self::assertStringContainsString("\033[38;2;255;0;0ma", $output);
        self::assertStringContainsString("\033[38;2;128;128;0mb", $output);
        self::assertStringContainsString("\033[38;2;0;255;0mc", $output);
    }

    #[Test]
    public function diagonalFlowsTopLeftToBottomRight(): void
    {
        $io = $this->decoratedIo();
        (new Renderer($io))->render(
            ['ab', 'cd'],
            ['#ff0000', '#0000ff'],
            null,
            Direction::Diagonal,
        );

        $output = $io->getOutput();
        self::assertStringContainsString("\033[38;2;255;0;0ma", $output);
        self::assertStringContainsString("\033[38;2;128;0;128mb", $output);
        self::assertStringContainsString("\033[38;2;128;0;128mc", $output);
        self::assertStringContainsString("\033[38;2;0;0;255md", $output);
    }

    #[Test]
    public function diagonalLeavesSpacesUncolored(): void
    {
        $io = $this->decoratedIo();
        (new Renderer($io))->render(
            ['a b', 'c d'],
            ['#ff0000', '#0000ff'],
            null,
            Direction::Diagonal,
        );

        $output = $io->getOutput();
        // Aspect-normalized diagonal: corners 0/1, axis midpoints 0.5; spaces emit literally
        self::assertStringContainsString("\033[38;2;255;0;0ma", $output);
        self::assertStringContainsString("\033[38;2;128;0;128mb", $output);
        self::assertStringContainsString("\033[38;2;128;0;128mc", $output);
        self::assertStringContainsString("\033[38;2;0;0;255md", $output);
        self::assertStringNotContainsString("\033[38;2;255;0;0m ", $output);
    }

    #[Test]
    public function horizontalSkipsSpacesForGradientStepping(): void
    {
        $io = $this->decoratedIo();
        (new Renderer($io))->render(
            ['a b'],
            ['#ff0000', '#00ff00'],
            null,
            Direction::Horizontal,
        );

        $output = $io->getOutput();
        self::assertStringContainsString("\033[38;2;255;0;0ma", $output);
        self::assertStringContainsString("\033[38;2;0;255;0mb", $output);
        self::assertStringNotContainsString("\033[38;2;0;255;0m ", $output);
        self::assertStringNotContainsString("\033[38;2;255;0;0m ", $output);
    }

    #[Test]
    public function horizontalLeavesLeadingSpacesUncolored(): void
    {
        $io = $this->decoratedIo();
        (new Renderer($io))->render(
            ['  ab'],
            ['#ff0000', '#00ff00'],
            null,
            Direction::Horizontal,
        );

        $output = $io->getOutput();
        self::assertStringContainsString("  \033[38;2;255;0;0ma", $output);
        self::assertStringContainsString("\033[38;2;0;255;0mb", $output);
    }

    #[Test]
    public function horizontalHandlesMultibyteCharacters(): void
    {
        $io = $this->decoratedIo();
        (new Renderer($io))->render(
            ['★🎉ä'],
            ['#ff0000', '#00ff00', '#0000ff'],
            null,
            Direction::Horizontal,
        );

        $output = $io->getOutput();
        self::assertStringContainsString("\033[38;2;255;0;0m★", $output);
        self::assertStringContainsString("\033[38;2;0;255;0m🎉", $output);
        self::assertStringContainsString("\033[38;2;0;0;255mä", $output);
    }

    #[Test]
    public function horizontalSkipsEmptyLineWithoutEscapes(): void
    {
        $io = $this->decoratedIo();
        (new Renderer($io))->render(
            ['', 'x'],
            ['#ff0000'],
            null,
            Direction::Horizontal,
        );

        $output = $io->getOutput();
        self::assertStringContainsString("\033[38;2;255;0;0mx", $output);
    }

    #[Test]
    public function verticalIsDefaultAndWrapsWholeLine(): void
    {
        $io = $this->decoratedIo();
        (new Renderer($io))->render(['hello'], ['#ff0000'], null);

        self::assertStringContainsString("\033[38;2;255;0;0mhello\033[0m", $io->getOutput());
    }

    #[Test]
    public function horizontalRowEndsWithReset(): void
    {
        $io = $this->decoratedIo();
        (new Renderer($io))->render(['x'], ['#ff0000'], null, Direction::Horizontal);

        self::assertMatchesRegularExpression(
            '/\033\[38;2;255;0;0mx\033\[0m\n/',
            $io->getOutput(),
        );
    }

    #[Test]
    public function diagonalRowEndsWithReset(): void
    {
        $io = $this->decoratedIo();
        (new Renderer($io))->render(['xy', 'zw'], ['#ff0000', '#0000ff'], null, Direction::Diagonal);

        $output = $io->getOutput();
        self::assertMatchesRegularExpression('/\033\[38;2;128;0;128my\033\[0m\n/', $output);
        self::assertMatchesRegularExpression('/\033\[38;2;0;0;255mw\033\[0m\n/', $output);
    }

    #[Test]
    public function diagonalSingleRowBannerInterpolatesAcrossColumns(): void
    {
        $io = $this->decoratedIo();
        (new Renderer($io))->render(['ab'], ['#ff0000', '#0000ff'], null, Direction::Diagonal);

        $output = $io->getOutput();
        // Single row → rowFraction = 0; columnFraction at col 0 = 0, col 1 = 1; (0 + f) / 2
        self::assertStringContainsString("\033[38;2;255;0;0ma", $output);
        self::assertStringContainsString("\033[38;2;128;0;128mb", $output);
    }

    #[Test]
    public function diagonalHandlesMultibyteCharacters(): void
    {
        $io = $this->decoratedIo();
        (new Renderer($io))->render(
            ['★ä', '🎉∞'],
            ['#ff0000', '#0000ff'],
            null,
            Direction::Diagonal,
        );

        $output = $io->getOutput();
        self::assertStringContainsString("\033[38;2;255;0;0m★", $output);
        self::assertStringContainsString("\033[38;2;128;0;128mä", $output);
        self::assertStringContainsString("\033[38;2;128;0;128m🎉", $output);
        self::assertStringContainsString("\033[38;2;0;0;255m∞", $output);
    }

    #[Test]
    public function horizontalLineWithOnlySpacesEmitsPlain(): void
    {
        $io = $this->decoratedIo();
        (new Renderer($io))->render(['   '], ['#ff0000', '#00ff00'], null, Direction::Horizontal);

        $output = $io->getOutput();
        self::assertStringContainsString('   ', $output);
        // No escapes at all — not even a trailing RESET — when the row has no visible chars
        self::assertStringNotContainsString("\033[", $output);
    }

    #[Test]
    public function diagonalSingleCellBannerUsesFirstStop(): void
    {
        $io = $this->decoratedIo();
        (new Renderer($io))->render(['x'], ['#ff0000', '#0000ff'], null, Direction::Diagonal);

        // 1×1 banner → both rowFraction and columnFraction collapse to 0.0 → first stop
        self::assertStringContainsString("\033[38;2;255;0;0mx", $io->getOutput());
    }

    #[Test]
    public function horizontalCollapsesRepeatedColorEscapes(): void
    {
        $io = $this->decoratedIo();
        // Single-stop gradient → every visible char gets the same RGB.
        (new Renderer($io))->render(['abc'], ['#ff0000'], null, Direction::Horizontal);

        $output = $io->getOutput();
        self::assertSame(
            1,
            substr_count($output, "\033[38;2;255;0;0m"),
            'Repeated identical RGB should emit a single foreground escape, not one per character.',
        );
    }

    #[Test]
    public function diagonalCollapsesRepeatedColorEscapes(): void
    {
        $io = $this->decoratedIo();
        (new Renderer($io))->render(['abc'], ['#ff0000'], null, Direction::Diagonal);

        $output = $io->getOutput();
        self::assertSame(
            1,
            substr_count($output, "\033[38;2;255;0;0m"),
            'Diagonal mode should also collapse repeated identical RGB into a single escape.',
        );
    }

    #[Test]
    public function horizontalDoesNotReEmitEscapeAcrossSpaces(): void
    {
        $io = $this->decoratedIo();
        // 'a a' with single stop → both visible chars are the same color across a space gap.
        (new Renderer($io))->render(['a a'], ['#ff0000'], null, Direction::Horizontal);

        $output = $io->getOutput();
        self::assertSame(
            1,
            substr_count($output, "\033[38;2;255;0;0m"),
            'Spaces between same-colored chars should not trigger a redundant re-emit of the escape.',
        );
    }

    #[Test]
    public function diagonalThreeRowBannerInterpolatesByRow(): void
    {
        $io = $this->decoratedIo();
        (new Renderer($io))->render(
            ['ab', 'cd', 'ef'],
            ['#ff0000', '#0000ff'],
            null,
            Direction::Diagonal,
        );

        $output = $io->getOutput();
        // row 1 (rowFraction 0.5):
        //   col 0: (0.5 + 0) / 2 = 0.25 → lerp(red, blue, 0.25) = (191, 0, 64)
        //   col 1: (0.5 + 1) / 2 = 0.75 → lerp(red, blue, 0.75) = (64, 0, 191)
        self::assertStringContainsString("\033[38;2;191;0;64mc", $output);
        self::assertStringContainsString("\033[38;2;64;0;191md", $output);
    }

    #[Test]
    public function multipleUncoloredLinesAreAllRendered(): void
    {
        $io = $this->plainIo();
        (new Renderer($io))->render(['first', 'second', 'third'], ['#ff0000'], null);

        $output = $io->getOutput();
        self::assertStringContainsString('first', $output);
        self::assertStringContainsString('second', $output);
        self::assertStringContainsString('third', $output);
    }

    #[Test]
    public function rendersTwoFiveSixEscapesWhenTerminalSupports256(): void
    {
        putenv('TERM=xterm-256color');
        $io = $this->decoratedIo();
        (new Renderer($io))->render(['x'], ['#ff0000'], null);

        $output = $io->getOutput();
        self::assertStringContainsString("\033[38;5;", $output);
        self::assertStringNotContainsString("\033[38;2;", $output);
    }

    #[Test]
    public function rendersSixteenColorEscapesOnBasicTerminal(): void
    {
        putenv('TERM=ansi');
        $io = $this->decoratedIo();
        (new Renderer($io))->render(['x'], ['#ff0000'], null);

        $output = $io->getOutput();
        self::assertStringContainsString("\033[91m", $output);
        self::assertStringNotContainsString("\033[38;2;", $output);
        self::assertStringNotContainsString("\033[38;5;", $output);
    }

    #[Test]
    public function rendersPlainOnDumbTerminal(): void
    {
        putenv('TERM=dumb');
        $io = $this->decoratedIo();
        (new Renderer($io))->render(['x'], ['#ff0000'], null);

        $output = $io->getOutput();
        self::assertStringContainsString('x', $output);
        self::assertStringNotContainsString("\033[", $output);
    }

    #[Test]
    public function bannerHasLeadingAndTrailingBlankLines(): void
    {
        $io = $this->decoratedIo();
        (new Renderer($io))->render(['hello'], ['#ff0000'], null);

        $output = $io->getOutput();
        // Output structure: \n + colored "hello" + \n + \n  (leading + content + trailing)
        self::assertStringStartsWith("\n", $output);
        self::assertStringEndsWith("\n\n", $output);
    }

    protected function tearDown(): void
    {
        putenv('NO_COLOR');
        putenv('TERM');
        putenv('COLORTERM');
    }

    private function decoratedIo(): BufferIO
    {
        return new BufferIO('', StreamOutput::VERBOSITY_NORMAL, new OutputFormatter(true));
    }

    private function plainIo(): BufferIO
    {
        return new BufferIO('', StreamOutput::VERBOSITY_NORMAL, new OutputFormatter(false));
    }
}
