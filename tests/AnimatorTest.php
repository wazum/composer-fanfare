<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wazum\ComposerFanfare\Animation;
use Wazum\ComposerFanfare\Animator;
use Wazum\ComposerFanfare\Tests\Support\RecordingAnimationDriver;

final class AnimatorTest extends TestCase
{
    #[Test]
    public function typewriterEmitsEachVisibleCharacterFollowedByASleep(): void
    {
        $driver = new RecordingAnimationDriver();

        (new Animator($driver))->animate(['abc'], null, Animation::Typewriter);

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
        self::assertSame(Animator::typewriterDelayMicros(3), $sleeps[0]['value']);
    }

    #[Test]
    public function singleCharBannerUsesMaxDelay(): void
    {
        $driver = new RecordingAnimationDriver();

        (new Animator($driver))->animate(['a'], null, Animation::Typewriter);

        $sleeps = array_values(array_filter(
            $driver->events,
            static fn (array $event): bool => 'sleep' === $event['op'],
        ));
        self::assertCount(1, $sleeps);
        self::assertSame(Animator::MAX_DELAY_MICROS, $sleeps[0]['value']);
    }

    #[Test]
    public function typewriterDelayCapsAtMaxForShortBanners(): void
    {
        // Few characters → budget per char is large → delay capped at MAX_DELAY_MICROS.
        self::assertSame(Animator::MAX_DELAY_MICROS, Animator::typewriterDelayMicros(10));
    }

    #[Test]
    public function typewriterDelayScalesDownForLongBanners(): void
    {
        // Many characters → budget per char shrinks below MAX_DELAY_MICROS, so total
        // animation time stays within TYPEWRITER_BUDGET_MICROS.
        $charCount = 1_000;
        $delay = Animator::typewriterDelayMicros($charCount);

        self::assertLessThan(Animator::MAX_DELAY_MICROS, $delay);
        self::assertLessThanOrEqual(Animator::TYPEWRITER_BUDGET_MICROS, $delay * $charCount);
    }

    #[Test]
    public function typewriterDelayIsZeroForEmptyInput(): void
    {
        self::assertSame(0, Animator::typewriterDelayMicros(0));
    }

    #[Test]
    public function longerBannerProducesShorterPerCharDelay(): void
    {
        $driver = new RecordingAnimationDriver();

        (new Animator($driver))->animate([str_repeat('a', 500)], null, Animation::Typewriter);

        $sleeps = array_values(array_filter(
            $driver->events,
            static fn (array $event): bool => 'sleep' === $event['op'],
        ));
        self::assertCount(500, $sleeps);
        self::assertLessThan(Animator::MAX_DELAY_MICROS, (int) $sleeps[0]['value']);
    }

    #[Test]
    public function typewriterEmitsAnsiEscapesAtomicallyAndDoesNotSleepBetweenThem(): void
    {
        $driver = new RecordingAnimationDriver();

        (new Animator($driver))->animate(["\033[38;2;255;0;0ma\033[0m"], null, Animation::Typewriter);

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
    public function typewriterEndsEachLineWithANewline(): void
    {
        $driver = new RecordingAnimationDriver();

        (new Animator($driver))->animate(['ab', 'cd'], null, Animation::Typewriter);

        $lineBreaks = array_values(array_filter(
            $driver->events,
            static fn (array $event): bool => 'writeLine' === $event['op'] && '' === $event['value'],
        ));
        self::assertCount(2, $lineBreaks);
    }

    #[Test]
    public function typewriterHandlesMultiByteCharactersAsSingleVisibleUnits(): void
    {
        $driver = new RecordingAnimationDriver();

        (new Animator($driver))->animate(['█▀'], null, Animation::Typewriter);

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

        (new Animator($driver))->animate(['ab'], 'project · PHP 8.2', Animation::Typewriter);

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

        // No `m` terminator — Animator must abort the line cleanly, not infinite loop.
        (new Animator($driver))->animate(["\033[38;2;255;0;0a"], null, Animation::Typewriter);

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

        (new Animator($driver))->animate(["\033[1m\033[38;2;255;0;0ma"], null, Animation::Typewriter);

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

        // `é` = 0xC3 0xA9 (2 bytes). Trailing `x` ensures the parser stops at the
        // correct byte — a wrong char-length picks up `x`'s bytes too.
        (new Animator($driver))->animate(['éx'], null, Animation::Typewriter);

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

        // `🎉` = 0xF0 0x9F 0x8E 0x89 (4 bytes). Trailing `z` traps off-by-one mistakes
        // — without it, substr() silently clamps and a wrong length still passes.
        (new Animator($driver))->animate(['🎉z'], null, Animation::Typewriter);

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

        // U+0800 (ࠀ) encodes as 0xE0 0xA0 0x80 — exercises the lead-byte = 0xE0
        // boundary that other 3-byte chars (e.g. █ = 0xE2…) don't reach.
        (new Animator($driver))->animate(['ࠀy'], null, Animation::Typewriter);

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

        (new Animator($driver))->animate([''], null, Animation::Typewriter);

        $sleeps = array_values(array_filter(
            $driver->events,
            static fn (array $event): bool => 'sleep' === $event['op'],
        ));
        self::assertCount(0, $sleeps);
        self::assertSame("\n", $driver->emittedText());
    }
}
