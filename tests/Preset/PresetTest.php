<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Tests\Preset;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wazum\ComposerFanfare\Preset\Preset;

final class PresetTest extends TestCase
{
    #[Test]
    public function everyPresetReturnsValidHexPalette(): void
    {
        foreach (Preset::cases() as $preset) {
            $palette = $preset->colors();
            self::assertNotEmpty($palette, "Preset {$preset->value} has empty palette");
            foreach ($palette as $hex) {
                self::assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $hex);
            }
        }
    }

    #[Test]
    public function namesReturnsAllCaseValues(): void
    {
        self::assertSame(
            ['aurora', 'catppuccin', 'doom', 'dracula', 'fire', 'gruvbox', 'iceberg', 'matrix', 'monokai', 'nord', 'ocean', 'pride', 'solarized', 'sunset', 'synthwave'],
            Preset::names(),
        );
    }
}
