<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Tests;

use PHPUnit\Framework\TestCase;
use Wazum\ComposerFanfare\Preset;

final class PresetTest extends TestCase
{
    public function testEveryPresetReturnsValidHexPalette(): void
    {
        foreach (Preset::cases() as $preset) {
            $palette = $preset->colors();
            self::assertNotEmpty($palette, "Preset {$preset->value} has empty palette");
            foreach ($palette as $hex) {
                self::assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $hex);
            }
        }
    }

    public function testNamesReturnsAllCaseValues(): void
    {
        self::assertSame(
            ['aurora', 'catppuccin', 'doom', 'dracula', 'fire', 'gruvbox', 'iceberg', 'matrix', 'monokai', 'nord', 'ocean', 'pride', 'solarized', 'sunset', 'synthwave'],
            Preset::names(),
        );
    }
}
