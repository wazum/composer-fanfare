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
    private const CONFIG_KEY          = 'fanfare';
    private const HEX_PATTERN         = '/^#?[0-9a-fA-F]{6}$/';
    private const WINDOWS_PATH        = '#^[A-Za-z]:[\\\\/]#';
    private const STREAM_WRAPPER      = '#^[a-z][a-z0-9+.\-]*://#i';
    private const MAX_TEMPLATE_BYTES  = 16384;
    private const CONTROL_CHARS_RANGE = "\0..\37";
    private const RANDOM_KEYWORD      = 'random';

    private Composer $composer;
    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void {}

    public function uninstall(Composer $composer, IOInterface $io): void {}

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'onPostCmd',
            ScriptEvents::POST_UPDATE_CMD  => 'onPostCmd',
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
        if (!is_string($template) || $template === '') {
            return;
        }

        $contents = $this->loadTemplate($template);
        if ($contents === null) {
            return;
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $contents);
        if (str_ends_with($normalized, "\n")) {
            $normalized = substr($normalized, 0, -1);
        }
        $lines = explode("\n", $normalized);
        $colors = $this->resolveColors($config['colors'] ?? null);
        $direction = $this->resolveDirection($config['direction'] ?? null);
        $statusLine = ($config['footer'] ?? true) === false ? null : $this->buildStatusLine();

        (new Renderer($this->io))->render($lines, $colors, $statusLine, $direction);
    }

    private function resolveDirection(mixed $value): Direction
    {
        if ($value === null) {
            return Direction::Vertical;
        }
        if (is_string($value)) {
            $direction = Direction::tryFrom($value);
            if ($direction !== null) {
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
            if (preg_match(self::HEX_PATTERN,$value) === 1) {
                return [$value];
            }
            if ($value === self::RANDOM_KEYWORD) {
                $cases = Preset::cases();
                return $cases[random_int(0, count($cases) - 1)]->colors();
            }
            $preset = Preset::tryFrom($value);
            if ($preset === null) {
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
                if (is_string($entry) && preg_match(self::HEX_PATTERN,$entry) === 1) {
                    $hex[] = $entry;
                }
            }
            return $hex === [] ? null : $hex;
        }

        return null;
    }

    private function buildStatusLine(): string
    {
        $parts = [];

        $rootPackage = $this->composer->getPackage();
        $name = $rootPackage->getName();
        $version = $rootPackage->getPrettyVersion();
        if ($name !== '') {
            $parts[] = $version !== '' ? $name . ' ' . $version : $name;
        }

        $parts[] = 'PHP ' . PHP_VERSION;

        try {
            $locker = $this->composer->getLocker();
            if ($locker->isLocked()) {
                $count = count($locker->getLockedRepository()->getPackages());
                $parts[] = sprintf('%d %s', $count, $count === 1 ? 'package' : 'packages');
            }
        } catch (\Throwable) {
            // status line gracefully degrades without the package count
        }

        return implode(' · ', $parts);
    }

    private function loadTemplate(string $template): ?string
    {
        if (preg_match(self::STREAM_WRAPPER, $template) === 1) {
            $this->warnTemplate($template, 'outside project root');
            return null;
        }

        $rootReal = realpath($this->rootDir());
        if ($rootReal === false) {
            $this->warnTemplate($template, 'not found');
            return null;
        }

        $candidate = $this->isAbsolutePath($template)
            ? $template
            : $rootReal . DIRECTORY_SEPARATOR . $template;
        $real = realpath($candidate);
        if ($real === false) {
            $this->warnTemplate($template, 'not found');
            return null;
        }

        if (!str_starts_with($real . DIRECTORY_SEPARATOR, $rootReal . DIRECTORY_SEPARATOR)) {
            $this->warnTemplate($template, 'outside project root');
            return null;
        }

        if (!is_file($real) || !is_readable($real)) {
            $this->warnTemplate($template, 'not found');
            return null;
        }

        $size = filesize($real);
        if ($size === false || $size > self::MAX_TEMPLATE_BYTES) {
            $this->warnTemplate($template, 'too large');
            return null;
        }

        $contents = @file_get_contents($real);
        if ($contents === false) {
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

        return $absolute !== false ? dirname($absolute) : dirname($composerFile);
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        if ($path[0] === '/') {
            return true;
        }

        return preg_match(self::WINDOWS_PATH, $path) === 1;
    }
}
