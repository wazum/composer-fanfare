<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `composer fanfare:preview` — render the configured banner with one or all presets,
 * without running install/update.
 *
 * @internal
 */
final class PreviewCommand extends BaseCommand
{
    private const FALLBACK_BANNER = <<<'BANNER'
        ▗▞▀▘ ▄▄▄  ▄▄▄▄  ▄▄▄▄   ▄▄▄   ▄▄▄ ▗▞▀▚▖ ▄▄▄     ▗▞▀▀▘▗▞▀▜▌▄▄▄▄  ▗▞▀▀▘▗▞▀▜▌ ▄▄▄ ▗▞▀▚▖
        ▝▚▄▖█   █ █ █ █ █   █ █   █ ▀▄▄  ▐▛▀▀▘█        ▐▌   ▝▚▄▟▌█   █ ▐▌   ▝▚▄▟▌█    ▐▛▀▀▘
            ▀▄▄▄▀ █   █ █▄▄▄▀ ▀▄▄▄▀ ▄▄▄▀ ▝▚▄▄▖█        ▐▛▀▘      █   █ ▐▛▀▘      █    ▝▚▄▄▖
                        █                              ▐▌              ▐▌
                        ▀
        BANNER;

    private const PRESET_OPTION_NOT_PASSED = false;

    protected function configure(): void
    {
        $this->setName('fanfare:preview');
        $this->setDescription('Preview composer-fanfare presets without running install/update.');
        $this->addOption('gallery', null, InputOption::VALUE_NONE, 'Render every preset.');
        $this->addOption(
            'preset',
            null,
            InputOption::VALUE_OPTIONAL,
            'Render a specific preset, or list all preset names if no value is given.',
            self::PRESET_OPTION_NOT_PASSED,
        );
        $this->addOption(
            'direction',
            null,
            InputOption::VALUE_REQUIRED,
            'Gradient flow: vertical, horizontal, diagonal.',
            'vertical',
        );
        $this->addOption(
            'transform',
            null,
            InputOption::VALUE_REQUIRED,
            'Palette transform: reverse.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $renderer = new PreviewRenderer(
            $this->getIO(),
            $this->bannerLines(),
            $this->resolveDirection($input),
            $this->resolveReverse($input),
        );

        if ((bool) $input->getOption('gallery')) {
            $renderer->gallery();

            return self::SUCCESS;
        }

        $preset = $input->getOption('preset');
        if (self::PRESET_OPTION_NOT_PASSED === $preset) {
            $output->writeln('Use --gallery to render every preset, or --preset=NAME for a single preset.');
            $renderer->listPresets();

            return self::SUCCESS;
        }

        if (null === $preset) {
            $renderer->listPresets();

            return self::SUCCESS;
        }

        if (!is_string($preset)) {
            return self::FAILURE;
        }
        $exitCode = $renderer->preset($preset);

        return PreviewRenderer::EXIT_OK === $exitCode ? self::SUCCESS : self::FAILURE;
    }

    private function resolveDirection(InputInterface $input): Direction
    {
        $value = $input->getOption('direction');
        if (!is_string($value)) {
            return Direction::Vertical;
        }
        $direction = Direction::tryFrom(trim($value));
        if (null === $direction) {
            $this->getIO()->writeError(sprintf(
                '<warning>Unknown direction "%s" • available: %s</warning>',
                $value,
                implode(', ', Direction::names()),
            ));

            return Direction::Vertical;
        }

        return $direction;
    }

    private function resolveReverse(InputInterface $input): bool
    {
        $value = $input->getOption('transform');
        if (null === $value) {
            return false;
        }
        if (!is_string($value)) {
            return false;
        }
        $name = trim($value);
        if ('' === $name) {
            return false;
        }
        if ('reverse' === $name) {
            return true;
        }
        $this->getIO()->writeError(sprintf(
            '<warning>Unknown transform "%s" • available: reverse</warning>',
            $name,
        ));

        return false;
    }

    /**
     * @return list<string>
     */
    private function bannerLines(): array
    {
        $configured = $this->loadConfiguredBanner();
        if (null !== $configured) {
            return $configured;
        }

        return explode("\n", self::FALLBACK_BANNER);
    }

    /**
     * @return list<string>|null
     */
    private function loadConfiguredBanner(): ?array
    {
        $composer = $this->tryComposer();
        if (null === $composer) {
            return null;
        }

        $extra = $composer->getPackage()->getExtra();
        $config = $extra['fanfare'] ?? null;
        if (!is_array($config)) {
            return null;
        }
        $template = $config['template'] ?? null;
        if (!is_string($template)) {
            return null;
        }
        $template = trim($template);
        if ('' === $template) {
            return null;
        }

        return (new TemplateLoader($this->getIO()))->loadLines($template);
    }
}
