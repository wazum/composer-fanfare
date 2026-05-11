<?php

declare(strict_types=1);

namespace Wazum\ComposerFanfare\Preset;

use Composer\Factory;
use Composer\IO\IOInterface;

/**
 * Loads a fanfare banner template from disk after enforcing the
 * security gate (no stream wrappers, must resolve under the project
 * root, size-capped). Used by both the install/update plugin and the
 * `fanfare:preview` command.
 *
 * @internal
 */
final readonly class TemplateLoader
{
    private const STREAM_WRAPPER = '#^[a-z][a-z0-9+.\-]*://#i';
    private const WINDOWS_PATH = '#^[A-Za-z]:[\\\\/]#';
    private const MAX_TEMPLATE_BYTES = 16384;
    private const CONTROL_CHARS_RANGE = "\0..\37";

    public function __construct(private IOInterface $io)
    {
    }

    /**
     * @return list<string>|null
     */
    public function loadLines(string $template): ?array
    {
        $contents = $this->loadContents($template);
        if (null === $contents) {
            return null;
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $contents);
        if (str_ends_with($normalized, "\n")) {
            $normalized = substr($normalized, 0, -1);
        }

        return array_map(
            static fn (string $line): string => '' === trim($line) ? '' : $line,
            explode("\n", $normalized),
        );
    }

    private function loadContents(string $template): ?string
    {
        if (1 === preg_match(self::STREAM_WRAPPER, $template)) {
            $this->warn($template, 'outside project root');

            return null;
        }

        $rootReal = realpath($this->rootDir());
        if (false === $rootReal) {
            $this->warn($template, 'not found');

            return null;
        }

        $candidate = $this->isAbsolutePath($template)
            ? $template
            : $rootReal.\DIRECTORY_SEPARATOR.$template;
        $real = realpath($candidate);
        if (false === $real) {
            $this->warn($template, 'not found');

            return null;
        }

        if (!str_starts_with($real.\DIRECTORY_SEPARATOR, $rootReal.\DIRECTORY_SEPARATOR)) {
            $this->warn($template, 'outside project root');

            return null;
        }

        if (!is_file($real) || !is_readable($real)) {
            $this->warn($template, 'not found');

            return null;
        }

        $size = filesize($real);
        if (false === $size || $size > self::MAX_TEMPLATE_BYTES) {
            $this->warn($template, 'too large');

            return null;
        }

        $contents = @file_get_contents($real);
        if (false === $contents) {
            $this->warn($template, 'could not be read');

            return null;
        }

        return $contents;
    }

    private function warn(string $template, string $reason): void
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
