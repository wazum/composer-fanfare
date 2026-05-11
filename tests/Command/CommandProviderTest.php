<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Tests\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wazum\ComposerFanfare\Command\CommandProvider;
use Wazum\ComposerFanfare\Command\PreviewCommand;

final class CommandProviderTest extends TestCase
{
    #[Test]
    public function exposesPreviewCommand(): void
    {
        $commands = (new CommandProvider())->getCommands();

        self::assertCount(1, $commands);
        self::assertInstanceOf(PreviewCommand::class, $commands[0]);
        self::assertSame('fanfare:preview', $commands[0]->getName());
    }
}
