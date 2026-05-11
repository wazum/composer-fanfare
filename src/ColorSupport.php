<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare;

use Composer\IO\IOInterface;

/**
 * @internal
 */
enum ColorSupport: int
{
    case None = 0;
    case Sixteen = 1;
    case TwoFiveSix = 2;
    case TrueColor = 3;

    private const BASIC_TERMINAL_PATTERN = '/^(ansi|vt\d+|linux)$/';
    private const TWO_FIVE_SIX_PATTERN = '/-256(color)?$/';

    /**
     * Standard 16-color ANSI palette in approximate RGB. Used for nearest-neighbor
     * mapping when downgrading 24-bit colors to the 16-color set.
     *
     * @var list<array{int, int, int}>
     */
    private const ANSI_PALETTE = [
        [0, 0, 0],       // 0  black
        [205, 0, 0],     // 1  red
        [0, 205, 0],     // 2  green
        [205, 205, 0],   // 3  yellow
        [0, 0, 238],     // 4  blue
        [205, 0, 205],   // 5  magenta
        [0, 205, 205],   // 6  cyan
        [229, 229, 229], // 7  white
        [127, 127, 127], // 8  bright black
        [255, 0, 0],     // 9  bright red
        [0, 255, 0],     // 10 bright green
        [255, 255, 0],   // 11 bright yellow
        [92, 92, 255],   // 12 bright blue
        [255, 0, 255],   // 13 bright magenta
        [0, 255, 255],   // 14 bright cyan
        [255, 255, 255], // 15 bright white
    ];

    /**
     * xterm 6×6×6 color cube level steps along each channel.
     *
     * @var list<int>
     */
    private const CUBE_LEVELS = [0, 95, 135, 175, 215, 255];

    private const GRAYSCALE_TOLERANCE = 8;
    private const GRAYSCALE_MIN = 8;
    // The xterm grayscale ramp at indices 232..255 covers gray values
    // 8, 18, 28, ..., 238 in steps of 10 (24 steps total). Anything brighter
    // is approximated by the cube's near-white index instead.
    private const GRAYSCALE_MAX = 238;
    private const GRAYSCALE_BASE_INDEX = 232;
    private const GRAYSCALE_STEP = 10;
    private const CUBE_BASE_INDEX = 16;
    private const NEAR_BLACK_INDEX = 16;
    private const NEAR_WHITE_INDEX = 231;
    private const ANSI_FG_NORMAL_OFFSET = 30;
    private const ANSI_FG_BRIGHT_OFFSET = 90;
    private const ANSI_BRIGHT_THRESHOLD = 8;

    public static function detect(IOInterface $io): self
    {
        if (false !== getenv('NO_COLOR')) {
            return self::None;
        }
        if (!$io->isDecorated()) {
            return self::None;
        }

        $term = (string) getenv('TERM');
        if ('dumb' === $term) {
            return self::None;
        }

        // COLORTERM is the upgrade signal — modern terminals advertise both
        // `TERM=xterm-256color` (legacy baseline) and `COLORTERM=truecolor`,
        // and the truecolor advertisement must win over the 256-color hint.
        $colorTerm = strtolower((string) getenv('COLORTERM'));
        if ('truecolor' === $colorTerm || '24bit' === $colorTerm) {
            return self::TrueColor;
        }

        if (1 === preg_match(self::TWO_FIVE_SIX_PATTERN, $term)) {
            return self::TwoFiveSix;
        }

        if (1 === preg_match(self::BASIC_TERMINAL_PATTERN, $term)) {
            return self::Sixteen;
        }

        return self::TrueColor;
    }

    public function escape(int $red, int $green, int $blue): string
    {
        return match ($this) {
            self::None => '',
            self::TrueColor => sprintf("\033[38;2;%d;%d;%dm", $red, $green, $blue),
            self::TwoFiveSix => sprintf("\033[38;5;%dm", self::rgbToCubeIndex($red, $green, $blue)),
            self::Sixteen => self::ansiBasicEscape(self::rgbToBasicIndex($red, $green, $blue)),
        };
    }

    private static function rgbToCubeIndex(int $red, int $green, int $blue): int
    {
        if (
            abs($red - $green) < self::GRAYSCALE_TOLERANCE
            && abs($green - $blue) < self::GRAYSCALE_TOLERANCE
        ) {
            $gray = (int) round(($red + $green + $blue) / 3);
            if ($gray < self::GRAYSCALE_MIN) {
                return self::NEAR_BLACK_INDEX;
            }
            if ($gray > self::GRAYSCALE_MAX) {
                return self::NEAR_WHITE_INDEX;
            }

            return self::GRAYSCALE_BASE_INDEX + (int) round(($gray - self::GRAYSCALE_MIN) / self::GRAYSCALE_STEP);
        }

        $cubeR = self::quantizeChannel($red);
        $cubeG = self::quantizeChannel($green);
        $cubeB = self::quantizeChannel($blue);

        return self::CUBE_BASE_INDEX + 36 * $cubeR + 6 * $cubeG + $cubeB;
    }

    private static function quantizeChannel(int $value): int
    {
        $bestIndex = 0;
        $bestDistance = PHP_INT_MAX;
        foreach (self::CUBE_LEVELS as $index => $level) {
            $distance = abs($value - $level);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestIndex = $index;
            }
        }

        return $bestIndex;
    }

    private static function rgbToBasicIndex(int $red, int $green, int $blue): int
    {
        $bestIndex = 0;
        $bestDistance = PHP_INT_MAX;
        foreach (self::ANSI_PALETTE as $index => [$paletteR, $paletteG, $paletteB]) {
            $deltaR = $red - $paletteR;
            $deltaG = $green - $paletteG;
            $deltaB = $blue - $paletteB;
            $distance = $deltaR * $deltaR + $deltaG * $deltaG + $deltaB * $deltaB;
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestIndex = $index;
            }
        }

        return $bestIndex;
    }

    private static function ansiBasicEscape(int $paletteIndex): string
    {
        $code = $paletteIndex < self::ANSI_BRIGHT_THRESHOLD
            ? self::ANSI_FG_NORMAL_OFFSET + $paletteIndex
            : self::ANSI_FG_BRIGHT_OFFSET + ($paletteIndex - self::ANSI_BRIGHT_THRESHOLD);

        return sprintf("\033[%dm", $code);
    }
}
