<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare;

/**
 * @internal
 */
enum Direction: string
{
    case Vertical = 'vertical';
    case Horizontal = 'horizontal';
    case Diagonal = 'diagonal';

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(static fn (self $d): string => $d->value, self::cases());
    }
}
