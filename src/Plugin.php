<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as ComposerCommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Symfony\Component\Console\Terminal;

/**
 * @internal
 */
final class Plugin implements PluginInterface, EventSubscriberInterface, Capable
{
    private const CONFIG_KEY = 'fanfare';
    private const HEX_PATTERN = '/^#?[0-9a-fA-F]{6}$/';
    private const RANDOM_KEYWORD = 'random';
    private const NO_VERSION_PLACEHOLDER = 'no-version-set';
    private const OPT_OUT_ENV = 'COMPOSER_FANFARE';
    private const OPT_OUT_ENV_VALUE = '0';

    private Composer $composer;
    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'onPostCmd',
            ScriptEvents::POST_UPDATE_CMD => 'onPostCmd',
        ];
    }

    /** @return array<class-string, class-string> */
    public function getCapabilities(): array
    {
        return [
            ComposerCommandProvider::class => CommandProvider::class,
        ];
    }

    public function onPostCmd(Event $event): void
    {
        if ($this->isOptedOut()) {
            return;
        }
        try {
            $this->renderBanner();
        } catch (\Throwable) {
            // Banner output is purely cosmetic; never break composer install/update.
        }
    }

    /**
     * @param list<string> $lines
     */
    private function exceedsTerminalWidth(array $lines): bool
    {
        $width = (new Terminal())->getWidth();
        foreach ($lines as $line) {
            if (mb_strlen($line) > $width) {
                return true;
            }
        }

        return false;
    }

    private function isOptedOut(): bool
    {
        $env = getenv(self::OPT_OUT_ENV);

        return false !== $env && self::OPT_OUT_ENV_VALUE === trim($env);
    }

    private function renderBanner(): void
    {
        $extra = $this->composer->getPackage()->getExtra();
        $config = $extra[self::CONFIG_KEY] ?? null;
        if (!is_array($config)) {
            return;
        }

        $template = $config['template'] ?? null;
        if (!is_string($template)) {
            return;
        }
        $template = trim($template);
        if ('' === $template) {
            return;
        }

        $lines = (new TemplateLoader($this->io))->loadLines($template);
        if (null === $lines) {
            return;
        }
        if ($this->exceedsTerminalWidth($lines)) {
            return;
        }

        $colors = $this->resolveColors($config['colors'] ?? null);
        $colors = $this->applyTransform($colors, $config['transform'] ?? null);
        $direction = $this->resolveDirection($config['direction'] ?? null);
        $animation = $this->resolveAnimation($config['animation'] ?? null);
        $statusLine = ($config['footer'] ?? true) === false ? null : $this->buildStatusLine();

        (new Renderer($this->io))->render($lines, $colors, $statusLine, $direction, $animation);
    }

    /**
     * @param list<string>|null $colors
     *
     * @return list<string>|null
     */
    private function applyTransform(?array $colors, mixed $value): ?array
    {
        if (null === $colors || !is_string($value)) {
            return $colors;
        }
        $name = trim($value);
        if ('' === $name) {
            return $colors;
        }
        if ('reverse' === $name) {
            return array_reverse($colors);
        }
        $this->io->writeError(sprintf(
            '<warning>Unknown transform "%s" • available: reverse</warning>',
            $name,
        ));

        return $colors;
    }

    private function resolveAnimation(mixed $value): ?Animation
    {
        if (null === $value) {
            return null;
        }
        if (!is_string($value)) {
            $this->io->writeError(sprintf(
                '<warning>Invalid animation type %s • expected one of: %s</warning>',
                get_debug_type($value),
                implode(', ', Animation::names()),
            ));

            return null;
        }
        $value = trim($value);
        if ('' === $value) {
            return null;
        }
        $animation = Animation::tryFrom($value);
        if (null === $animation) {
            $this->io->writeError(sprintf(
                '<warning>Unknown animation "%s" • available: %s</warning>',
                $value,
                implode(', ', Animation::names()),
            ));

            return null;
        }

        return $animation;
    }

    private function resolveDirection(mixed $value): Direction
    {
        if (null === $value) {
            return Direction::Vertical;
        }
        if (is_string($value)) {
            $value = trim($value);
            $direction = Direction::tryFrom($value);
            if (null !== $direction) {
                return $direction;
            }
            $this->io->writeError(sprintf(
                '<warning>Unknown direction "%s" • available: %s</warning>',
                $value,
                implode(', ', Direction::names()),
            ));
        }

        return Direction::Vertical;
    }

    /** @return list<string>|null */
    private function resolveColors(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = trim($value);
            if (1 === preg_match(self::HEX_PATTERN, $value)) {
                return [$value];
            }
            if (self::RANDOM_KEYWORD === $value) {
                $cases = Preset::cases();

                return $cases[random_int(0, count($cases) - 1)]->colors();
            }
            $preset = Preset::tryFrom($value);
            if (null === $preset) {
                $this->io->writeError(sprintf(
                    '<warning>Unknown preset "%s" • available: %s, %s</warning>',
                    $value,
                    implode(', ', Preset::names()),
                    self::RANDOM_KEYWORD,
                ));

                return null;
            }

            return $preset->colors();
        }

        if (is_array($value)) {
            $hex = [];
            foreach ($value as $entry) {
                if (is_string($entry)) {
                    $entry = trim($entry);
                    if (1 === preg_match(self::HEX_PATTERN, $entry)) {
                        $hex[] = $entry;
                        continue;
                    }
                    $shown = '"'.$entry.'"';
                } else {
                    $shown = get_debug_type($entry);
                }
                $this->io->writeError(sprintf(
                    '<warning>Invalid hex color %s • expected #RRGGBB</warning>',
                    $shown,
                ));
            }

            return [] === $hex ? null : $hex;
        }

        return null;
    }

    private function buildStatusLine(): string
    {
        $parts = [];

        $rootPackage = $this->composer->getPackage();
        $name = $rootPackage->getName();
        $version = $rootPackage->getPrettyVersion();
        if ('' !== $name) {
            $hasMeaningfulVersion = '' !== $version && !str_contains($version, self::NO_VERSION_PLACEHOLDER);
            $parts[] = $hasMeaningfulVersion ? $name.' '.$version : $name;
        }

        $parts[] = 'PHP '.PHP_VERSION;

        try {
            $locker = $this->composer->getLocker();
            if ($locker->isLocked()) {
                $count = count($locker->getLockedRepository()->getPackages());
                $parts[] = sprintf('%d %s', $count, 1 === $count ? 'package' : 'packages');
            }
        } catch (\Throwable) {
            // status line gracefully degrades without the package count
        }

        return implode(' · ', $parts);
    }
}
