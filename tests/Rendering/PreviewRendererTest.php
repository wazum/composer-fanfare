<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Tests\Rendering;

use Composer\IO\BufferIO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\StreamOutput;
use Wazum\ComposerFanfare\Preset\Direction;
use Wazum\ComposerFanfare\Preset\Preset;
use Wazum\ComposerFanfare\Rendering\PreviewRenderer;

final class PreviewRendererTest extends TestCase
{
    private const SAMPLE_BANNER = ['hello'];

    #[Test]
    public function galleryLabelsEveryPreset(): void
    {
        $io = $this->decoratedIo();

        (new PreviewRenderer($io, self::SAMPLE_BANNER))->gallery();

        $output = $io->getOutput();
        foreach (Preset::cases() as $preset) {
            self::assertStringContainsString($preset->value, $output, "label for {$preset->value} missing");
        }
    }

    #[Test]
    public function galleryRendersFirstStopOfEveryPreset(): void
    {
        $io = $this->decoratedIo();

        (new PreviewRenderer($io, self::SAMPLE_BANNER))->gallery();

        $output = $io->getOutput();
        foreach (Preset::cases() as $preset) {
            $firstStop = ltrim($preset->colors()[0], '#');
            $rgb = sscanf($firstStop, '%02x%02x%02x');
            self::assertIsArray($rgb);
            [$red, $green, $blue] = $rgb;
            $expected = sprintf("\033[38;2;%d;%d;%dm", $red, $green, $blue);
            self::assertStringContainsString(
                $expected,
                $output,
                "first-stop escape for {$preset->value} missing",
            );
        }
    }

    #[Test]
    public function presetReturnsZeroWhenKnown(): void
    {
        $io = $this->decoratedIo();

        $exitCode = (new PreviewRenderer($io, self::SAMPLE_BANNER))->preset('fire');

        self::assertSame(0, $exitCode);
    }

    #[Test]
    public function presetRendersWhenKnown(): void
    {
        $io = $this->decoratedIo();

        (new PreviewRenderer($io, self::SAMPLE_BANNER))->preset('fire');

        // fire = ['#3a0000', ...] → first stop on a single-line banner.
        self::assertStringContainsString("\033[38;2;58;0;0mhello\033[0m", $io->getOutput());
    }

    #[Test]
    public function presetReturnsNonZeroWhenUnknown(): void
    {
        $io = $this->decoratedIo();

        $exitCode = (new PreviewRenderer($io, self::SAMPLE_BANNER))->preset('neon');

        self::assertNotSame(0, $exitCode);
    }

    #[Test]
    public function presetWarnsWhenUnknown(): void
    {
        $io = $this->decoratedIo();

        (new PreviewRenderer($io, self::SAMPLE_BANNER))->preset('neon');

        $output = $io->getOutput();
        self::assertStringContainsString('Unknown preset "neon"', $output);
        self::assertStringContainsString('available: aurora, catppuccin', $output);
    }

    #[Test]
    public function presetTrimsWhitespaceInName(): void
    {
        $io = $this->decoratedIo();

        $exitCode = (new PreviewRenderer($io, self::SAMPLE_BANNER))->preset("  fire \n");

        self::assertSame(0, $exitCode);
        self::assertStringNotContainsString('Unknown', $io->getOutput());
    }

    #[Test]
    public function presetUsesProvidedDirection(): void
    {
        $io = $this->decoratedIo();

        // 2x2 banner with fire (3 stops). Diagonal puts 'b' and 'c' on the
        // half-blend (255,84,0). Vertical would give 'b' = first stop and 'c'
        // = last stop — different colors. So this asserts diagonal was used.
        (new PreviewRenderer($io, ['ab', 'cd'], Direction::Diagonal))->preset('fire');

        $output = $io->getOutput();
        self::assertStringContainsString("\033[38;2;255;84;0mb", $output);
        self::assertStringContainsString("\033[38;2;255;84;0mc", $output);
    }

    #[Test]
    public function presetAppliesReverseTransform(): void
    {
        $io = $this->decoratedIo();

        // fire = ['#3a0000', '#ff5400', '#ffd700']; reversed → first stop (#ffd700).
        (new PreviewRenderer($io, ['x'], Direction::Vertical, reverse: true))->preset('fire');

        self::assertStringContainsString("\033[38;2;255;215;0mx\033[0m", $io->getOutput());
    }

    #[Test]
    public function galleryUsesProvidedDirection(): void
    {
        $io = $this->decoratedIo();

        (new PreviewRenderer($io, ['ab', 'cd'], Direction::Diagonal))->gallery();

        $output = $io->getOutput();
        // fire's diagonal blend at the (0,1) cell is the middle stop (255,84,0).
        self::assertStringContainsString("\033[38;2;255;84;0mb", $output);
    }

    #[Test]
    public function galleryAppliesReverseTransform(): void
    {
        $io = $this->decoratedIo();

        (new PreviewRenderer($io, ['x'], Direction::Vertical, reverse: true))->gallery();

        // fire reversed → first stop (#ffd700) on a single-line banner.
        self::assertStringContainsString("\033[38;2;255;215;0mx\033[0m", $io->getOutput());
    }

    #[Test]
    public function listPresetsWritesCommaSeparatedNames(): void
    {
        $io = $this->decoratedIo();

        (new PreviewRenderer($io, self::SAMPLE_BANNER))->listPresets();

        self::assertStringContainsString(implode(', ', Preset::names()), $io->getOutput());
    }

    private function decoratedIo(): BufferIO
    {
        return new BufferIO('', StreamOutput::VERBOSITY_NORMAL, new OutputFormatter(true));
    }
}
