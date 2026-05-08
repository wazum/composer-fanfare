# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.0.0]: https://github.com/wazum/composer-fanfare/releases/tag/v1.0.0
