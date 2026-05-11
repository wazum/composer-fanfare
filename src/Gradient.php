<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare;

/**
 * @internal
 */
final readonly class Gradient
{
    /**
     * @param non-empty-list<array{int, int, int}> $stops
     *
     * @return array{int, int, int}
     */
    public static function sample(array $stops, float $fraction): array
    {
        $count = count($stops);
        if (1 === $count) {
            return $stops[0];
        }
        $fraction = max(0.0, min(1.0, $fraction));
        $segments = $count - 1;
        $scaled = $fraction * $segments;
        $idx = (int) floor($scaled);
        if ($idx >= $segments) {
            return $stops[$count - 1];
        }
        $t = $scaled - $idx;

        return self::lerpRgb($stops[$idx], $stops[$idx + 1], $t);
    }

    /**
     * @param array{int, int, int} $a
     * @param array{int, int, int} $b
     *
     * @return array{int, int, int}
     */
    public static function lerpRgb(array $a, array $b, float $t): array
    {
        return [
            (int) round($a[0] + ($b[0] - $a[0]) * $t),
            (int) round($a[1] + ($b[1] - $a[1]) * $t),
            (int) round($a[2] + ($b[2] - $a[2]) * $t),
        ];
    }
}
