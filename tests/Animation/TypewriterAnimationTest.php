<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Tests\Animation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wazum\ComposerFanfare\Animation\AnimationContext;
use Wazum\ComposerFanfare\Animation\TypewriterAnimation;
use Wazum\ComposerFanfare\Preset\Direction;
use Wazum\ComposerFanfare\Rendering\ColorSupport;
use Wazum\ComposerFanfare\Tests\Support\RecordingAnimationDriver;

final class TypewriterAnimationTest extends TestCase
{
    #[Test]
    public function emitsEachVisibleCharacterFollowedByAPositiveSleep(): void
    {
        $driver = new RecordingAnimationDriver();

        (new TypewriterAnimation())->animate($this->context(['abc']), $driver);

        $writes = array_values(array_filter(
            $driver->events,
            static fn (array $event): bool => 'write' === $event['op'],
        ));
        self::assertSame(['a', 'b', 'c'], array_column($writes, 'value'));

        $sleeps = array_values(array_filter(
            $driver->events,
            static fn (array $event): bool => 'sleep' === $event['op'],
        ));
        self::assertCount(3, $sleeps);
        // Every sleep is positive (not skipped) and identical (constant rate per char).
        self::assertGreaterThan(0, (int) $sleeps[0]['value']);
        self::assertSame($sleeps[0]['value'], $sleeps[1]['value']);
        self::assertSame($sleeps[0]['value'], $sleeps[2]['value']);
    }

    #[Test]
    public function singleCharBannerProducesAPositiveSleep(): void
    {
        $driver = new RecordingAnimationDriver();

        (new TypewriterAnimation())->animate($this->context(['a']), $driver);

        $sleeps = array_values(array_filter(
            $driver->events,
            static fn (array $event): bool => 'sleep' === $event['op'],
        ));
        self::assertCount(1, $sleeps);
        self::assertGreaterThan(0, (int) $sleeps[0]['value']);
    }

    #[Test]
    public function longerBannerProducesShorterPerCharDelayThanShortBanner(): void
    {
        $shortDelay = $this->firstSleepFor(['a']);
        $longDelay = $this->firstSleepFor([str_repeat('a', 500)]);

        self::assertGreaterThan($longDelay, $shortDelay);
        self::assertGreaterThan(0, $longDelay);
    }

    #[Test]
    public function ansiEscapesEmittedAtomicallyAndDoNotConsumeASleep(): void
    {
        $driver = new RecordingAnimationDriver();

        (new TypewriterAnimation())->animate($this->context(["\033[38;2;255;0;0ma\033[0m"]), $driver);

        $textOnly = '';
        $sleepCount = 0;
        $firstWrite = null;
        foreach ($driver->events as $event) {
            if ('write' === $event['op']) {
                $textOnly .= (string) $event['value'];
                $firstWrite ??= $event['value'];
            } elseif ('sleep' === $event['op']) {
                ++$sleepCount;
            }
        }
        self::assertSame("\033[38;2;255;0;0ma\033[0m", $textOnly);
        self::assertSame(1, $sleepCount, 'sleep only after the visible char, not after escapes');
        self::assertSame("\033[38;2;255;0;0ma", $firstWrite, 'first chunk bundles escape with its char');
    }

    #[Test]
    public function eachLineEndsWithANewline(): void
    {
        $driver = new RecordingAnimationDriver();

        (new TypewriterAnimation())->animate($this->context(['ab', 'cd']), $driver);

        $lineBreaks = array_values(array_filter(
            $driver->events,
            static fn (array $event): bool => 'writeLine' === $event['op'] && '' === $event['value'],
        ));
        self::assertCount(2, $lineBreaks);
    }

    #[Test]
    public function multibyteCharactersTreatedAsSingleVisibleUnits(): void
    {
        $driver = new RecordingAnimationDriver();

        (new TypewriterAnimation())->animate($this->context(['█▀']), $driver);

        $writes = array_values(array_filter(
            $driver->events,
            static fn (array $event): bool => 'write' === $event['op'],
        ));
        self::assertSame(['█', '▀'], array_column($writes, 'value'));
    }

    #[Test]
    public function statusLineWrittenAsSingleLineAfterAnimation(): void
    {
        $driver = new RecordingAnimationDriver();

        (new TypewriterAnimation())->animate($this->context(['ab'], 'project · PHP 8.2'), $driver);

        $statusEvents = array_values(array_filter(
            $driver->events,
            static fn (array $event): bool => 'writeLine' === $event['op']
                && str_contains((string) $event['value'], 'project'),
        ));
        self::assertCount(1, $statusEvents);
    }

    #[Test]
    public function malformedEscapeWithoutTerminatorBreaksWithoutHanging(): void
    {
        $driver = new RecordingAnimationDriver();

        (new TypewriterAnimation())->animate($this->context(["\033[38;2;255;0;0a"]), $driver);

        $writes = array_filter(
            $driver->events,
            static fn (array $event): bool => 'write' === $event['op'],
        );
        self::assertSame([], array_column($writes, 'value'));
    }

    #[Test]
    public function multipleEscapesPrecedingASingleCharBundleTogether(): void
    {
        $driver = new RecordingAnimationDriver();

        (new TypewriterAnimation())->animate($this->context(["\033[1m\033[38;2;255;0;0ma"]), $driver);

        $writes = array_values(array_filter(
            $driver->events,
            static fn (array $event): bool => 'write' === $event['op'],
        ));
        self::assertCount(1, $writes);
        self::assertSame("\033[1m\033[38;2;255;0;0ma", $writes[0]['value']);
    }

    #[Test]
    public function twoByteUtf8CharacterIsBoundaryCorrect(): void
    {
        $driver = new RecordingAnimationDriver();

        (new TypewriterAnimation())->animate($this->context(['éx']), $driver);

        $writes = array_values(array_filter(
            $driver->events,
            static fn (array $event): bool => 'write' === $event['op'],
        ));
        self::assertSame(['é', 'x'], array_column($writes, 'value'));
    }

    #[Test]
    public function fourByteUtf8CharacterIsBoundaryCorrect(): void
    {
        $driver = new RecordingAnimationDriver();

        (new TypewriterAnimation())->animate($this->context(['🎉z']), $driver);

        $writes = array_values(array_filter(
            $driver->events,
            static fn (array $event): bool => 'write' === $event['op'],
        ));
        self::assertSame(['🎉', 'z'], array_column($writes, 'value'));
    }

    #[Test]
    public function threeByteUtf8WithLeadByteAt0xE0EmittedAtomically(): void
    {
        $driver = new RecordingAnimationDriver();

        (new TypewriterAnimation())->animate($this->context(['ࠀy']), $driver);

        $writes = array_values(array_filter(
            $driver->events,
            static fn (array $event): bool => 'write' === $event['op'],
        ));
        self::assertSame(['ࠀ', 'y'], array_column($writes, 'value'));
    }

    #[Test]
    public function emptyLineProducesOnlyANewline(): void
    {
        $driver = new RecordingAnimationDriver();

        (new TypewriterAnimation())->animate($this->context(['']), $driver);

        $sleeps = array_values(array_filter(
            $driver->events,
            static fn (array $event): bool => 'sleep' === $event['op'],
        ));
        self::assertCount(0, $sleeps);

        $emitted = '';
        foreach ($driver->events as $event) {
            if ('write' === $event['op']) {
                $emitted .= (string) $event['value'];
            } elseif ('writeLine' === $event['op']) {
                $emitted .= (string) $event['value']."\n";
            }
        }
        self::assertSame("\n", $emitted);
    }

    /**
     * @param list<string> $styledLines
     */
    private function firstSleepFor(array $styledLines): int
    {
        $driver = new RecordingAnimationDriver();
        (new TypewriterAnimation())->animate($this->context($styledLines), $driver);
        foreach ($driver->events as $event) {
            if ('sleep' === $event['op']) {
                return (int) $event['value'];
            }
        }

        return 0;
    }

    /**
     * @param list<string> $styledLines
     */
    private function context(array $styledLines, ?string $statusLine = null): AnimationContext
    {
        return new AnimationContext(
            lines: $styledLines,
            styledLines: $styledLines,
            stops: null,
            direction: Direction::Vertical,
            colorSupport: ColorSupport::TrueColor,
            useColor: false,
            statusLine: $statusLine,
        );
    }
}
