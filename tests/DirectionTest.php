<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Tests;

use PHPUnit\Framework\TestCase;
use Wazum\ComposerFanfare\Direction;

final class DirectionTest extends TestCase
{
    public function testNamesReturnsAllCaseValues(): void
    {
        self::assertSame(['vertical', 'horizontal', 'diagonal'], Direction::names());
    }
}
