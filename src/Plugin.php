<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

/**
 * @internal
 */
final class Plugin implements PluginInterface, EventSubscriberInterface
{
    private const CONFIG_KEY = 'fanfare';
    private const HEX_PATTERN = '/^#?[0-9a-fA-F]{6}$/';
    private const WINDOWS_PATH = '#^[A-Za-z]:[\\\\/]#';
    private const STREAM_WRAPPER = '#^[a-z][a-z0-9+.\-]*://#i';
    private const MAX_TEMPLATE_BYTES = 16384;
    private const CONTROL_CHARS_RANGE = "\0..\37";
    private const RANDOM_KEYWORD = 'random';
    private const NO_VERSION_PLACEHOLDER = 'no-version-set';

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

    public function onPostCmd(Event $event): void
    {
        try {
            $this->renderBanner();
        } catch (\Throwable) {
            // Banner output is purely cosmetic; never break composer install/update.
        }
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

        $contents = $this->loadTemplate($template);
        if (null === $contents) {
            return;
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $contents);
        if (str_ends_with($normalized, "\n")) {
            $normalized = substr($normalized, 0, -1);
        }
        $lines = explode("\n", $normalized);
        $colors = $this->resolveColors($config['colors'] ?? null);
        $colors = $this->applyTransform($colors, $config['transform'] ?? null);
        $direction = $this->resolveDirection($config['direction'] ?? null);
        $statusLine = ($config['footer'] ?? true) === false ? null : $this->buildStatusLine();

        (new Renderer($this->io))->render($lines, $colors, $statusLine, $direction);
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
                if (!is_string($entry)) {
                    continue;
                }
                $entry = trim($entry);
                if (1 === preg_match(self::HEX_PATTERN, $entry)) {
                    $hex[] = $entry;
                }
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

    private function loadTemplate(string $template): ?string
    {
        if (1 === preg_match(self::STREAM_WRAPPER, $template)) {
            $this->warnTemplate($template, 'outside project root');

            return null;
        }

        $rootReal = realpath($this->rootDir());
        if (false === $rootReal) {
            $this->warnTemplate($template, 'not found');

            return null;
        }

        $candidate = $this->isAbsolutePath($template)
            ? $template
            : $rootReal.DIRECTORY_SEPARATOR.$template;
        $real = realpath($candidate);
        if (false === $real) {
            $this->warnTemplate($template, 'not found');

            return null;
        }

        if (!str_starts_with($real.DIRECTORY_SEPARATOR, $rootReal.DIRECTORY_SEPARATOR)) {
            $this->warnTemplate($template, 'outside project root');

            return null;
        }

        if (!is_file($real) || !is_readable($real)) {
            $this->warnTemplate($template, 'not found');

            return null;
        }

        $size = filesize($real);
        if (false === $size || $size > self::MAX_TEMPLATE_BYTES) {
            $this->warnTemplate($template, 'too large');

            return null;
        }

        $contents = @file_get_contents($real);
        if (false === $contents) {
            $this->warnTemplate($template, 'could not be read');

            return null;
        }

        return $contents;
    }

    private function warnTemplate(string $template, string $reason): void
    {
        $this->io->writeError(sprintf(
            '<warning>Template "%s" %s</warning>',
            addcslashes($template, self::CONTROL_CHARS_RANGE),
            $reason,
        ));
    }

    private function rootDir(): string
    {
        $composerFile = Factory::getComposerFile();
        $absolute = realpath($composerFile);

        return false !== $absolute ? dirname($absolute) : dirname($composerFile);
    }

    private function isAbsolutePath(string $path): bool
    {
        if ('' === $path) {
            return false;
        }
        if ('/' === $path[0]) {
            return true;
        }

        return 1 === preg_match(self::WINDOWS_PATH, $path);
    }
}
