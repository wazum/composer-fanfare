<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Tests\Rendering;

use Composer\IO\BufferIO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\StreamOutput;
use Wazum\ComposerFanfare\Rendering\ColorSupport;

final class ColorSupportTest extends TestCase
{
    #[Test]
    public function noColorEnvDisablesEverything(): void
    {
        putenv('NO_COLOR=1');
        putenv('COLORTERM=truecolor');

        self::assertSame(ColorSupport::None, ColorSupport::detect($this->decoratedIo()));
    }

    #[Test]
    public function nonDecoratedIoDisablesEverything(): void
    {
        self::assertSame(ColorSupport::None, ColorSupport::detect($this->plainIo()));
    }

    #[Test]
    public function dumbTerminalDisablesEverything(): void
    {
        putenv('TERM=dumb');

        self::assertSame(ColorSupport::None, ColorSupport::detect($this->decoratedIo()));
    }

    #[Test]
    public function colortermTruecolorYieldsTrueColor(): void
    {
        putenv('COLORTERM=truecolor');

        self::assertSame(ColorSupport::TrueColor, ColorSupport::detect($this->decoratedIo()));
    }

    #[Test]
    public function colorterm24bitYieldsTrueColor(): void
    {
        putenv('COLORTERM=24bit');

        self::assertSame(ColorSupport::TrueColor, ColorSupport::detect($this->decoratedIo()));
    }

    #[Test]
    public function colortermTruecolorBeatsTerm256ColorWhenBothAreSet(): void
    {
        // Modern terminals advertise both `TERM=xterm-256color` (legacy
        // capabilities baseline) and `COLORTERM=truecolor` (true-colour
        // upgrade). The truecolor signal must win.
        putenv('TERM=xterm-256color');
        putenv('COLORTERM=truecolor');

        self::assertSame(ColorSupport::TrueColor, ColorSupport::detect($this->decoratedIo()));
    }

    #[Test]
    public function term256ColorYieldsTwoFiveSix(): void
    {
        putenv('TERM=xterm-256color');

        self::assertSame(ColorSupport::TwoFiveSix, ColorSupport::detect($this->decoratedIo()));
    }

    #[Test]
    public function basicAnsiTerminalYieldsSixteen(): void
    {
        putenv('TERM=ansi');

        self::assertSame(ColorSupport::Sixteen, ColorSupport::detect($this->decoratedIo()));
    }

    #[Test]
    public function unknownTerminalDefaultsToTrueColor(): void
    {
        putenv('TERM');
        putenv('COLORTERM');

        self::assertSame(ColorSupport::TrueColor, ColorSupport::detect($this->decoratedIo()));
    }

    #[Test]
    public function trueColorEscapeUsesTwentyFourBitFormat(): void
    {
        self::assertSame(
            "\033[38;2;255;107;53m",
            ColorSupport::TrueColor->escape(255, 107, 53),
        );
    }

    #[Test]
    public function twoFiveSixEscapeQuantizesPureRedToCubeIndex(): void
    {
        // Pure red (255, 0, 0) lands in the color cube at xterm index 196.
        self::assertSame(
            "\033[38;5;196m",
            ColorSupport::TwoFiveSix->escape(255, 0, 0),
        );
    }

    #[Test]
    public function twoFiveSixEscapeQuantizesGrayscaleToGrayRamp(): void
    {
        // Mid gray (128, 128, 128): (128-8)/10 = 12, ramp base 232 → 244.
        self::assertSame("\033[38;5;244m", ColorSupport::TwoFiveSix->escape(128, 128, 128));
    }

    #[Test]
    public function twoFiveSixEscapeMapsPureColorsToCubeIndices(): void
    {
        // 6×6×6 cube formula: 16 + 36·R + 6·G + B (each channel quantized 0-5).
        self::assertSame("\033[38;5;46m", ColorSupport::TwoFiveSix->escape(0, 255, 0), 'pure green');
        self::assertSame("\033[38;5;21m", ColorSupport::TwoFiveSix->escape(0, 0, 255), 'pure blue');
        self::assertSame("\033[38;5;226m", ColorSupport::TwoFiveSix->escape(255, 255, 0), 'yellow');
        self::assertSame("\033[38;5;51m", ColorSupport::TwoFiveSix->escape(0, 255, 255), 'cyan');
        self::assertSame("\033[38;5;201m", ColorSupport::TwoFiveSix->escape(255, 0, 255), 'magenta');
    }

    #[Test]
    public function twoFiveSixGrayscaleEdgeBoundaries(): void
    {
        // Below the ramp's first step (8) → cube black 16.
        self::assertSame("\033[38;5;16m", ColorSupport::TwoFiveSix->escape(7, 7, 7));
        // Exactly at the ramp's first step → ramp index 0 (palette 232).
        self::assertSame("\033[38;5;232m", ColorSupport::TwoFiveSix->escape(8, 8, 8));
        // Above the ramp's last step (238) → cube white 231.
        self::assertSame("\033[38;5;231m", ColorSupport::TwoFiveSix->escape(255, 255, 255));
        // Exactly at the ramp's last step → palette 255.
        self::assertSame("\033[38;5;255m", ColorSupport::TwoFiveSix->escape(238, 238, 238));
    }

    #[Test]
    public function twoFiveSixCubeFormulaCoefficientsArePinned(): void
    {
        // (155, 95, 0): channels quantize to (2, 1, 0). Index 16 + 36·2 + 6·1 + 0 = 94.
        // This pins all three coefficients (36, 6, +B) at their correct values.
        self::assertSame("\033[38;5;94m", ColorSupport::TwoFiveSix->escape(155, 95, 0));
    }

    #[Test]
    public function twoFiveSixTieBreakerPicksFirstChannelLevel(): void
    {
        // value=155 is equidistant from cube levels 135 and 175 (distance 20 each).
        // Strict `<` keeps the earlier level (index 2 = 135). (155,0,0) → 16 + 36·2 = 88.
        self::assertSame("\033[38;5;88m", ColorSupport::TwoFiveSix->escape(155, 0, 0));
    }

    #[Test]
    public function colortermComparisonIsCaseInsensitive(): void
    {
        // TERM=ansi would otherwise downgrade to Sixteen — only the case-insensitive
        // COLORTERM check pulls it back up to TrueColor.
        putenv('TERM=ansi');
        putenv('COLORTERM=TRUECOLOR');

        self::assertSame(ColorSupport::TrueColor, ColorSupport::detect($this->decoratedIo()));
    }

    #[Test]
    public function grayscaleToleranceUsesStrictLessThan(): void
    {
        // R/G diff = 8 (exactly at tolerance) → must not enter the gray branch.
        // Cube channels for (155, 147, 151): each maps to 2; 16 + 36·2 + 6·2 + 2 = 102.
        self::assertSame("\033[38;5;102m", ColorSupport::TwoFiveSix->escape(155, 147, 151));
        // G/B diff = 8 (exactly at tolerance) → must not enter the gray branch.
        // Cube channels for (155, 151, 159): 2, 2, 3 → 16 + 72 + 12 + 3 = 103.
        self::assertSame("\033[38;5;103m", ColorSupport::TwoFiveSix->escape(155, 151, 159));
    }

    #[Test]
    public function grayscaleAverageRoundsHalfUp(): void
    {
        // sum=23 → 23/3 ≈ 7.67. round → 8 (gray ramp index 232); floor → 7 (< MIN, returns 16).
        self::assertSame("\033[38;5;232m", ColorSupport::TwoFiveSix->escape(7, 8, 8));
        // sum=37 → 37/3 ≈ 12.33. round → 12; ceil → 13. Round at 12 puts ramp at 232.
        self::assertSame("\033[38;5;232m", ColorSupport::TwoFiveSix->escape(12, 12, 13));
    }

    #[Test]
    public function grayscaleRampUsesRoundedStepIndex(): void
    {
        // gray=12 → (12-8)/10 = 0.4. round → 0 → 232 (kills ceil → 233).
        self::assertSame("\033[38;5;232m", ColorSupport::TwoFiveSix->escape(12, 12, 12));
        // gray=14 → (14-8)/10 = 0.6. round → 1 → 233 (kills floor → 232).
        self::assertSame("\033[38;5;233m", ColorSupport::TwoFiveSix->escape(14, 14, 14));
    }

    #[Test]
    public function sixteenColorTieBreakerKeepsEarliestPaletteEntry(): void
    {
        // (230, 0, 0) is equidistant from palette[1] (dim red 205,0,0) and palette[9]
        // (bright red 255,0,0) — squared distance 625 each. Strict `<` keeps the
        // earlier match (dim red) and emits \033[31m, not \033[91m.
        self::assertSame("\033[31m", ColorSupport::Sixteen->escape(230, 0, 0));
    }

    #[Test]
    public function sixteenEscapeMapsPureRedToAnsiBrightRed(): void
    {
        // Pure red (255, 0, 0) maps to ANSI 9 (bright red) → escape \033[91m.
        self::assertSame("\033[91m", ColorSupport::Sixteen->escape(255, 0, 0));
    }

    #[Test]
    public function sixteenEscapeMapsBlackToAnsiBlack(): void
    {
        self::assertSame("\033[30m", ColorSupport::Sixteen->escape(0, 0, 0));
    }

    #[Test]
    public function sixteenEscapesCoverFullPalette(): void
    {
        // Spot-check each of the 16 ANSI palette entries to pin the
        // 30/90 base offsets and the 8-index bright threshold.
        self::assertSame("\033[31m", ColorSupport::Sixteen->escape(205, 0, 0), 'dim red → 1');
        self::assertSame("\033[32m", ColorSupport::Sixteen->escape(0, 205, 0), 'dim green → 2');
        self::assertSame("\033[33m", ColorSupport::Sixteen->escape(205, 205, 0), 'dim yellow → 3');
        self::assertSame("\033[34m", ColorSupport::Sixteen->escape(0, 0, 238), 'dim blue → 4');
        self::assertSame("\033[35m", ColorSupport::Sixteen->escape(205, 0, 205), 'dim magenta → 5');
        self::assertSame("\033[36m", ColorSupport::Sixteen->escape(0, 205, 205), 'dim cyan → 6');
        self::assertSame("\033[37m", ColorSupport::Sixteen->escape(229, 229, 229), 'dim white → 7');
        self::assertSame("\033[90m", ColorSupport::Sixteen->escape(127, 127, 127), 'bright black → 8');
        self::assertSame("\033[92m", ColorSupport::Sixteen->escape(0, 255, 0), 'bright green → 10');
        self::assertSame("\033[93m", ColorSupport::Sixteen->escape(255, 255, 0), 'bright yellow → 11');
        self::assertSame("\033[94m", ColorSupport::Sixteen->escape(92, 92, 255), 'bright blue → 12');
        self::assertSame("\033[95m", ColorSupport::Sixteen->escape(255, 0, 255), 'bright magenta → 13');
        self::assertSame("\033[96m", ColorSupport::Sixteen->escape(0, 255, 255), 'bright cyan → 14');
        self::assertSame("\033[97m", ColorSupport::Sixteen->escape(255, 255, 255), 'bright white → 15');
    }

    #[Test]
    public function noneEscapeIsEmpty(): void
    {
        self::assertSame('', ColorSupport::None->escape(255, 0, 0));
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
