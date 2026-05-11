# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] - 2026-05-11

### Added
- Three optional `animation` modes for the post-install/update banner: `typewriter` (per-character reveal), `shimmer` (gradient pulse), and `drip` (randomized cell reveal). Picked via `extra.fanfare.animation`; falls back to the static render when output isn't decorated and interactive.
- `COMPOSER_FANFARE=0` environment variable to silence the banner without editing `composer.json`. Useful for CI, scripts, and one-off invocations.
- Packagist, CI, PHP version, and license badges in the README.

### Changed
- Banner is now skipped silently when any line is wider than the terminal — no wrapped/broken output. Banner width is measured in real terminal columns (`mb_strwidth`), so full-width CJK glyphs and emoji count correctly.
- Whitespace-only lines are stripped and empty rows skip escape wrapping, so blank rows no longer carry stray color codes.
- Internal layout: classes regrouped under `Animation\`, `Command\`, `Preset\`, and `Rendering\` sub-namespaces. All classes are `@internal` — no impact for users configuring fanfare via `composer.json`.

### Fixed
- `COLORTERM=truecolor` is now honored even when `TERM=*-256color` is set — true-color terminals no longer get downgraded to the 256-color fallback.
- README example no longer references the non-existent `rainbow` preset.

## [1.2.0] - 2026-05-09

### Added
- `composer fanfare:preview` command to preview presets without running install/update. Supports `--gallery` (every preset), `--preset=NAME` (single preset, or list names when no value given), `--direction`, and `--transform`.
- `"transform": "reverse"` config (and `--transform=reverse` CLI flag) to flip any palette without redefining colors.
- 256-color and 16-color fallbacks for terminals that don't advertise true-color support — gradients degrade gracefully instead of dropping to plain text.

### Changed
- Horizontal and diagonal output compresses repeated ANSI escapes so adjacent characters in the same color share a single SGR sequence — smaller output, fewer bytes written to the terminal.

## [1.1.0] - 2026-05-09

### Added
- `"colors": "random"` rotates a random preset on each `composer install` / `composer update`. New presets added later auto-join the rotation.

### Changed
- Config values are trimmed before parsing — leading/trailing whitespace in `template`, `colors`, and `direction` (including hex array entries) no longer causes silent fallthrough or warnings.
- `/art/` is excluded from the Packagist dist tarball (~3.8 MB lighter for consumers).

### Fixed
- Footer no longer shows composer's `+no-version-set` placeholder for projects that don't declare a `version` in their `composer.json`. The project name appears alone instead.

## [1.0.0] - 2026-05-08

Initial stable release.

### Added
- `extra.fanfare` configuration in `composer.json` to display a colored ASCII banner after `composer install` / `composer update`.
- Color resolution from a hex string (`"#ff6ec7"`), an array of hex stops (`["#ff6ec7", "#7873f5"]`), or one of 15 named presets: `aurora`, `catppuccin`, `doom`, `dracula`, `fire`, `gruvbox`, `iceberg`, `matrix`, `monokai`, `nord`, `ocean`, `pride`, `solarized`, `sunset`, `synthwave`.
- Smooth RGB gradient interpolation between stops (no banding).
- `direction` config: `"vertical"` (default, one color per line), `"horizontal"` (per-character, left-to-right, whitespace stays uncolored), `"diagonal"` (aspect-normalized top-left to bottom-right flow).
- `footer` config (`true` default) to print or suppress a dim status line with project name + version, PHP version, and locked package count.
- Honors `NO_COLOR`, `--quiet`, and non-decorated output (falls back to plain text or skips entirely).
- Multi-byte / UTF-8 / emoji safe via `mb_str_split` and `mb_strlen`.
- CRLF and CR line endings normalized; trailing blank lines preserved.
- Hardened template path handling: stream wrappers rejected, paths must resolve under the project root, 16 KB size cap, control characters escaped in error messages.
- Plugin wraps post-install / post-update logic in `try`/`catch` so it can never break a composer command.

[1.3.0]: https://github.com/wazum/composer-fanfare/releases/tag/v1.3.0
[1.2.0]: https://github.com/wazum/composer-fanfare/releases/tag/v1.2.0
[1.1.0]: https://github.com/wazum/composer-fanfare/releases/tag/v1.1.0
[1.0.0]: https://github.com/wazum/composer-fanfare/releases/tag/v1.0.0
