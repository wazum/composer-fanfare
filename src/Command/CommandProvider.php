<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Command;

use Composer\Plugin\Capability\CommandProvider as ComposerCommandProvider;

/**
 * Registers `fanfare:preview` with composer's command-line interface.
 *
 * @internal
 */
final class CommandProvider implements ComposerCommandProvider
{
    /** @return list<\Symfony\Component\Console\Command\Command> */
    public function getCommands(): array
    {
        return [new PreviewCommand()];
    }
}
