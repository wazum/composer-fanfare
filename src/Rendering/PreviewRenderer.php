<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Rendering;

use Composer\IO\IOInterface;
use Wazum\ComposerFanfare\Preset\Direction;
use Wazum\ComposerFanfare\Preset\Preset;

/**
 * Renders preset previews to an IOInterface — used by the
 * `composer fanfare:preview` command and unit-tested in isolation.
 *
 * @internal
 */
final readonly class PreviewRenderer
{
    public const EXIT_OK = 0;
    public const EXIT_UNKNOWN_PRESET = 1;

    /**
     * @param list<string> $bannerLines
     */
    public function __construct(
        private IOInterface $io,
        private array $bannerLines,
        private Direction $direction = Direction::Vertical,
        private bool $reverse = false,
    ) {
    }

    public function gallery(): void
    {
        foreach (Preset::cases() as $preset) {
            $this->renderLabel($preset->value);
            (new Renderer($this->io))->render(
                $this->bannerLines,
                $this->paletteFor($preset),
                null,
                $this->direction,
            );
        }
    }

    public function preset(string $name): int
    {
        $name = trim($name);
        $preset = Preset::tryFrom($name);
        if (null === $preset) {
            $this->io->writeError(sprintf(
                '<warning>Unknown preset "%s" • available: %s</warning>',
                $name,
                implode(', ', Preset::names()),
            ));

            return self::EXIT_UNKNOWN_PRESET;
        }
        (new Renderer($this->io))->render(
            $this->bannerLines,
            $this->paletteFor($preset),
            null,
            $this->direction,
        );

        return self::EXIT_OK;
    }

    /**
     * @return list<string>
     */
    private function paletteFor(Preset $preset): array
    {
        $colors = $preset->colors();

        return $this->reverse ? array_reverse($colors) : $colors;
    }

    public function listPresets(): void
    {
        $this->io->write(implode(', ', Preset::names()));
    }

    private function renderLabel(string $label): void
    {
        $this->io->write('<info>'.$label.'</info>');
    }
}
