<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare;

/**
 * @internal
 */
interface AnimationRenderer
{
    public function animate(AnimationContext $context, AnimationDriver $driver): void;
}
