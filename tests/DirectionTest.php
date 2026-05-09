<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wazum\ComposerFanfare\Direction;

final class DirectionTest extends TestCase
{
    #[Test]
    public function namesReturnsAllCaseValues(): void
    {
        self::assertSame(['vertical', 'horizontal', 'diagonal'], Direction::names());
    }
}
