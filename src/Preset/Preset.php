<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Preset;

/**
 * @internal
 */
enum Preset: string
{
    case Aurora = 'aurora';
    case Catppuccin = 'catppuccin';
    case Doom = 'doom';
    case Dracula = 'dracula';
    case Fire = 'fire';
    case Gruvbox = 'gruvbox';
    case Iceberg = 'iceberg';
    case Matrix = 'matrix';
    case Monokai = 'monokai';
    case Nord = 'nord';
    case Ocean = 'ocean';
    case Pride = 'pride';
    case Solarized = 'solarized';
    case Sunset = 'sunset';
    case Synthwave = 'synthwave';

    /** @return list<string> */
    public function colors(): array
    {
        return match ($this) {
            self::Aurora => ['#3eed5e', '#26d9ff', '#9d6cff', '#ff6cb6'],
            self::Catppuccin => ['#f5c2e7', '#cba6f7', '#89b4fa', '#94e2d5', '#a6e3a1', '#f9e2af', '#fab387'],
            self::Doom => ['#ff6c6b', '#da8548', '#ecbe7b', '#98be65', '#46d9ff', '#51afef', '#c678dd'],
            self::Dracula => ['#ff79c6', '#bd93f9', '#8be9fd'],
            self::Fire => ['#3a0000', '#ff5400', '#ffd700'],
            self::Gruvbox => ['#fb4934', '#fe8019', '#fabd2f', '#b8bb26', '#83a598'],
            self::Iceberg => ['#84a0c6', '#89b8c2', '#b4be82', '#e2a478', '#e27878', '#a093c7'],
            self::Matrix => ['#003b00', '#00ff41', '#88ff88'],
            self::Monokai => ['#f92672', '#fd971f', '#e6db74', '#a6e22e', '#66d9ef', '#ae81ff'],
            self::Nord => ['#5e81ac', '#88c0d0', '#8fbcbb'],
            self::Ocean => ['#0077be', '#00a8e8', '#00d2ff', '#7ec8e3'],
            self::Pride => ['#e40303', '#ff8c00', '#ffed00', '#008026', '#004dff', '#750787'],
            self::Solarized => ['#dc322f', '#cb4b16', '#b58900', '#2aa198', '#268bd2'],
            self::Sunset => ['#ff6b35', '#f7931e', '#fdc830', '#f37335'],
            self::Synthwave => ['#ff6ec7', '#9d4edd', '#7873f5', '#5a189a'],
        };
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }
}
