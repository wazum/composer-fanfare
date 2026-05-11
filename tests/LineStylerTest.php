<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wazum\ComposerFanfare\ColorSupport;
use Wazum\ComposerFanfare\LineStyler;

final class LineStylerTest extends TestCase
{
    #[Test]
    public function wholeLineWrapsLineInForegroundEscapeAndReset(): void
    {
        self::assertSame(
            "\033[38;2;255;0;0mhello\033[0m",
            LineStyler::wholeLine('hello', [255, 0, 0], ColorSupport::TrueColor),
        );
    }

    #[Test]
    public function wholeLineReturnsEmptyForEmptyInputWithNoEscapeOrReset(): void
    {
        self::assertSame('', LineStyler::wholeLine('', [255, 0, 0], ColorSupport::TrueColor));
    }

    #[Test]
    public function wholeLinePreservesMultibyteContent(): void
    {
        self::assertSame(
            "\033[38;2;0;255;0m█▀\033[0m",
            LineStyler::wholeLine('█▀', [0, 255, 0], ColorSupport::TrueColor),
        );
    }

    #[Test]
    public function byVisibleCharEmitsOneEscapePerVisibleCharacter(): void
    {
        self::assertSame(
            "\033[38;2;255;0;0ma\033[38;2;0;0;255mb\033[0m",
            LineStyler::byVisibleChar('ab', [[255, 0, 0], [0, 0, 255]], ColorSupport::TrueColor),
        );
    }

    #[Test]
    public function byVisibleCharPreservesSpacesVerbatim(): void
    {
        self::assertSame(
            "\033[38;2;255;0;0ma \033[38;2;0;0;255mb\033[0m",
            LineStyler::byVisibleChar('a b', [[255, 0, 0], [0, 0, 255]], ColorSupport::TrueColor),
        );
    }

    #[Test]
    public function byVisibleCharCollapsesRepeatedColorEscapes(): void
    {
        // All chars same colour → single escape at start.
        self::assertSame(
            "\033[38;2;255;0;0mabc\033[0m",
            LineStyler::byVisibleChar('abc', [[255, 0, 0], [255, 0, 0], [255, 0, 0]], ColorSupport::TrueColor),
        );
    }

    #[Test]
    public function byVisibleCharDoesNotReEmitTheSameEscapeAcrossSpaces(): void
    {
        // Same colour on both sides of a space → only one escape total.
        self::assertSame(
            "\033[38;2;255;0;0ma b\033[0m",
            LineStyler::byVisibleChar('a b', [[255, 0, 0], [255, 0, 0]], ColorSupport::TrueColor),
        );
    }

    #[Test]
    public function byVisibleCharReturnsLineUnchangedWhenRgbArrayIsEmpty(): void
    {
        // No visible chars to colour → return the raw line, no escapes, no reset.
        self::assertSame('   ', LineStyler::byVisibleChar('   ', [], ColorSupport::TrueColor));
    }

    #[Test]
    public function byVisibleCharReturnsEmptyForEmptyInput(): void
    {
        self::assertSame('', LineStyler::byVisibleChar('', [[255, 0, 0]], ColorSupport::TrueColor));
    }

    #[Test]
    public function byVisibleCharTreatsMultibyteAsSingleVisibleUnits(): void
    {
        self::assertSame(
            "\033[38;2;255;0;0m█\033[38;2;0;0;255m▀\033[0m",
            LineStyler::byVisibleChar('█▀', [[255, 0, 0], [0, 0, 255]], ColorSupport::TrueColor),
        );
    }

    #[Test]
    public function byColumnIndexesRgbsByColumnPositionNotVisibleIndex(): void
    {
        // 'a b c' has visible chars at columns 0, 2, 4. byColumn must pick
        // rgbs[0], rgbs[2], rgbs[4] — not 0, 1, 2.
        $rgbs = [
            [255, 0, 0],   // col 0 → 'a'
            [0, 255, 0],   // col 1 → ' '
            [0, 0, 255],   // col 2 → 'b'
            [128, 128, 0], // col 3 → ' '
            [128, 0, 128], // col 4 → 'c'
        ];
        self::assertSame(
            "\033[38;2;255;0;0ma \033[38;2;0;0;255mb \033[38;2;128;0;128mc\033[0m",
            LineStyler::byColumn('a b c', $rgbs, ColorSupport::TrueColor),
        );
    }

    #[Test]
    public function byColumnPreservesSpacesAndEndsWithReset(): void
    {
        $rgbs = [[255, 0, 0], [0, 0, 0], [0, 0, 255]];
        self::assertSame(
            "\033[38;2;255;0;0ma \033[38;2;0;0;255mc\033[0m",
            LineStyler::byColumn('a c', $rgbs, ColorSupport::TrueColor),
        );
    }

    #[Test]
    public function byColumnCollapsesRepeatedColorEscapes(): void
    {
        $rgbs = [[255, 0, 0], [255, 0, 0], [255, 0, 0]];
        self::assertSame(
            "\033[38;2;255;0;0mabc\033[0m",
            LineStyler::byColumn('abc', $rgbs, ColorSupport::TrueColor),
        );
    }

    #[Test]
    public function byColumnReturnsEmptyForEmptyInput(): void
    {
        self::assertSame('', LineStyler::byColumn('', [[255, 0, 0]], ColorSupport::TrueColor));
    }

    #[Test]
    public function countVisibleCharsExcludesSpaces(): void
    {
        self::assertSame(3, LineStyler::countVisibleChars('a b c'));
    }

    #[Test]
    public function countVisibleCharsTreatsMultibyteAsOneUnit(): void
    {
        self::assertSame(3, LineStyler::countVisibleChars('█ ▀ █'));
    }

    #[Test]
    public function countVisibleCharsZeroForEmptyLine(): void
    {
        self::assertSame(0, LineStyler::countVisibleChars(''));
    }

    #[Test]
    public function countVisibleCharsZeroForAllSpacesLine(): void
    {
        self::assertSame(0, LineStyler::countVisibleChars('     '));
    }

    #[Test]
    public function maxLineWidthReturnsTheLongestLineByVisibleCharacterCount(): void
    {
        self::assertSame(5, LineStyler::maxLineWidth(['abc', 'abcde', 'ab']));
    }

    #[Test]
    public function maxLineWidthTreatsMultibyteAsSingleUnits(): void
    {
        self::assertSame(3, LineStyler::maxLineWidth(['ab', '█▀█']));
    }

    #[Test]
    public function maxLineWidthZeroForEmptyArray(): void
    {
        self::assertSame(0, LineStyler::maxLineWidth([]));
    }

    #[Test]
    public function maxVisibleCountReturnsLineWithMostNonSpaceCharacters(): void
    {
        self::assertSame(3, LineStyler::maxVisibleCount(['a b', 'abc', '  a']));
    }

    #[Test]
    public function maxVisibleCountZeroForEmptyArray(): void
    {
        self::assertSame(0, LineStyler::maxVisibleCount([]));
    }
}
