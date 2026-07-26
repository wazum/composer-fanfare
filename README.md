# composer-fanfare

[![Latest Version](https://img.shields.io/packagist/v/wazum/composer-fanfare.svg)](https://packagist.org/packages/wazum/composer-fanfare)
[![CI](https://github.com/wazum/composer-fanfare/actions/workflows/ci.yml/badge.svg)](https://github.com/wazum/composer-fanfare/actions/workflows/ci.yml)
[![PHP Version](https://img.shields.io/packagist/php-v/wazum/composer-fanfare.svg)](https://packagist.org/packages/wazum/composer-fanfare)
[![License](https://img.shields.io/packagist/l/wazum/composer-fanfare.svg)](LICENSE)

Display a colored ASCII banner after `composer install` / `composer update`.

![composer-fanfare in action](art/demo.gif?v=7)[^1]

Every `composer install` ends the same boring way: autoload files generated, then silence.

`composer-fanfare` replaces that with a project-specific full-stop — a colored ASCII banner that confirms which project just finished and lets you convey your own message: "Made with ❤️ by the maintainers".

Useful when you're proud of your work, juggle multiple repos, or just want a little fun after the thousandth `composer install`.

Stays respectful: silent in CI, plain in non-TTY shells, suppressed under `--quiet`.

## Install

```bash
composer require wazum/composer-fanfare
```

> [!IMPORTANT]
> Composer 2.2+ asks for plugin approval on first run. Interactive shells prompt you; in CI or non-interactive setups, allow it explicitly:
>
> ```bash
> composer config allow-plugins.wazum/composer-fanfare true
> ```

## Configure

Drop a plain-text template anywhere in your project (e.g. `art/banner.txt`) and reference it from `composer.json`:

```json
{
    "extra": {
        "fanfare": {
            "template": "art/banner.txt",
            "colors": "sunset"
        }
    }
}
```

- `template` — path to the template, relative to the project root.
- `colors` — one of:
  - a hex string (`"#ff6ec7"`)
  - an array of hex stops (`["#ff6ec7", "#7873f5"]`) — colors interpolate smoothly between stops
  - a preset name: `aurora`, `catppuccin`, `doom`, `dracula`, `fire`, `gruvbox`, `iceberg`, `matrix`, `monokai`, `nord`, `ocean`, `pride`, `solarized`, `sunset`, `synthwave`
  - `"random"` — picks a random preset on each install/update
  - omit for plain output
- `direction` — controls how the gradient flows:
  - `"vertical"` (default) — one color per line, top to bottom
  - `"horizontal"` — color per character, left to right (whitespace stays uncolored)
  - `"diagonal"` — flows top-left to bottom-right across the whole banner
- `transform` — optional palette transform applied after color resolution:
  - `"reverse"` — reverses the stop order (e.g. `sunset` reversed → sunrise)
- `animation` — optional reveal animation:
  - `"typewriter"` — types each visible character with a small per-character delay
  - `"shimmer"` — slides the gradient across the banner; vertical/horizontal rotate the static colors, diagonal does a smooth phase shift across the whole plane
  - `"drip"` — reveals cells in random order in small bursts, like raindrops landing on the banner
  - omit for an instant render. Skipped automatically in non-TTY / non-decorated terminals. `shimmer` and `drip` also fall back to an instant render when the banner is taller than the terminal.
- `footer` — `true` (default) prints a dim line below the banner with project name + version, PHP version, and locked package count. Set to `false` to hide.

> [!NOTE]
> The banner falls back to plain text when `NO_COLOR` is set or output isn't a TTY. Composer's `--quiet` mode suppresses it entirely via the IO layer. If any banner line is wider than the current terminal, the banner is skipped silently to avoid wrapping artefacts.

### Disabling

Skip the banner for a single run by setting `COMPOSER_FANFARE=0` inline:

```bash
COMPOSER_FANFARE=0 composer install
```

Or disable it globally for the current shell or CI environment:

```bash
export COMPOSER_FANFARE=0
```

### Setting values from the CLI

If you'd rather not hand-edit `composer.json`, every key can be set with `composer config`:

```bash
composer config extra.fanfare.template art/banner.txt
composer config extra.fanfare.colors synthwave
composer config extra.fanfare.direction diagonal
composer config extra.fanfare.transform reverse
```

Booleans and arrays need `--json` so the value is stored with the right type:

```bash
composer config --json extra.fanfare.colors '["#ff6ec7","#7873f5"]'
composer config --json extra.fanfare.footer false
```

Remove a key with `--unset`:

```bash
composer config --unset extra.fanfare.transform
```

### Color depth

Truecolor escapes are emitted by default. The renderer downgrades automatically based on `COLORTERM` and `TERM`:

| Detected support | Trigger | Output |
|---|---|---|
| 24-bit | `COLORTERM=truecolor` / `24bit`, or any modern terminal | `\033[38;2;R;G;Bm` |
| 256-color | `TERM` matching `*-256color` | `\033[38;5;Nm` (xterm 6×6×6 cube + 24-step grayscale ramp) |
| 16-color | `TERM=ansi` / `vt100` / `linux` | nearest-neighbor mapping to the standard ANSI palette |
| Plain | `NO_COLOR=1`, `TERM=dumb`, or non-TTY | banner content rendered without escapes |

### Preset gallery

<table>
  <tr>
    <td align="center"><strong>plain</strong><br><img src="art/presets/plain.png?v=4" alt="plain (no colors)"></td>
    <td align="center"><strong>aurora</strong><br><img src="art/presets/aurora.png?v=4" alt="aurora"></td>
    <td align="center"><strong>catppuccin</strong><br><img src="art/presets/catppuccin.png?v=4" alt="catppuccin"></td>
    <td align="center"><strong>doom</strong><br><img src="art/presets/doom.png?v=4" alt="doom"></td>
  </tr>
  <tr>
    <td align="center"><strong>dracula</strong><br><img src="art/presets/dracula.png?v=4" alt="dracula"></td>
    <td align="center"><strong>fire</strong><br><img src="art/presets/fire.png?v=4" alt="fire"></td>
    <td align="center"><strong>gruvbox</strong><br><img src="art/presets/gruvbox.png?v=4" alt="gruvbox"></td>
    <td align="center"><strong>iceberg</strong><br><img src="art/presets/iceberg.png?v=4" alt="iceberg"></td>
  </tr>
  <tr>
    <td align="center"><strong>matrix</strong><br><img src="art/presets/matrix.png?v=4" alt="matrix"></td>
    <td align="center"><strong>monokai</strong><br><img src="art/presets/monokai.png?v=4" alt="monokai"></td>
    <td align="center"><strong>nord</strong><br><img src="art/presets/nord.png?v=4" alt="nord"></td>
    <td align="center"><strong>ocean</strong><br><img src="art/presets/ocean.png?v=4" alt="ocean"></td>
  </tr>
  <tr>
    <td align="center"><strong>pride</strong><br><img src="art/presets/pride.png?v=4" alt="pride"></td>
    <td align="center"><strong>solarized</strong><br><img src="art/presets/solarized.png?v=4" alt="solarized"></td>
    <td align="center"><strong>sunset</strong><br><img src="art/presets/sunset.png?v=4" alt="sunset"></td>
    <td align="center"><strong>synthwave</strong><br><img src="art/presets/synthwave.png?v=4" alt="synthwave"></td>
  </tr>
</table>

## Preview

Pick a preset without running an install:

```bash
composer fanfare:preview --gallery                                           # render every preset
composer fanfare:preview --preset=fire                                       # render just one
composer fanfare:preview --preset                                            # list preset names
composer fanfare:preview --preset=fire --animation=drip                      # try an animation
composer fanfare:preview --gallery --direction=diagonal --transform=reverse  # combine
```

`--direction`, `--transform` and `--animation` accept the same values as the matching `extra.fanfare` keys. The preview uses your project's configured `extra.fanfare.template` if there is one, otherwise it falls back to a small built-in banner.

## Creating a template

The template is any plain-text file. For large ASCII letters from a word, the classic tool is [figlet](http://www.figlet.org/):

```bash
brew install figlet                  # macOS
sudo apt-get install -y figlet       # Debian / Ubuntu

figlet -f standard "your project" > art/banner.txt
```

Try other fonts with `-f slant`, `-f big`, `-f small`, etc.; `figlist` lists what's installed. Prefer a browser? [patorjk.com/software/taag](https://patorjk.com/software/taag/) is a popular generator with the same fonts.

Another option is [toilet](https://caca.zoy.org/wiki/toilet), which offers its own font set and can be useful for compact banner styles:

```bash
brew install toilet                  # macOS
sudo apt-get install -y toilet       # Debian / Ubuntu

toilet -f pagga "composer-fanfare" > art/banner.txt
```

> [!NOTE]
> Banner output relies on the font your terminal renders monospaced text in. Block-shading glyphs (`█▀▄░` from `pagga`/`smblock`/`block`) and box-drawing glyphs (`┏━┓┃` from `future`/`emboss`) are covered by every modern terminal font (JetBrains Mono, Fira Code, Cascadia, Hack, Menlo). Sparser ranges like braille (`⡠⢀⣀` from `smbraille`) are not — if a glyph shows up as `□`, switch to a font with wider Unicode coverage (DejaVu Sans Mono, Cascadia Code, Iosevka).

## License

MIT.

[^1]: The company names, project names, and email addresses shown in the demo (Atelier Prime, harbor sync, Neon District, meridian labs, lumen forge, etc.) are fictional — invented to illustrate the kind of personal banner you might write for your own project.
