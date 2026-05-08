# composer-fanfare

Display a colored ASCII banner after `composer install` / `composer update`.

![composer-fanfare in action](art/demo.gif?v=4)

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
            "colors": "rainbow"
        }
    }
}
```

- `template` — path to the template, relative to the project root.
- `colors` — one of:
  - a hex string (`"#ff6ec7"`)
  - an array of hex stops (`["#ff6ec7", "#7873f5"]`) — colors interpolate smoothly between stops
  - a preset name: `aurora`, `catppuccin`, `doom`, `dracula`, `fire`, `gruvbox`, `iceberg`, `matrix`, `monokai`, `nord`, `ocean`, `pride`, `solarized`, `sunset`, `synthwave`
  - omit for plain output
- `direction` — controls how the gradient flows:
  - `"vertical"` (default) — one color per line, top to bottom
  - `"horizontal"` — color per character, left to right (whitespace stays uncolored)
  - `"diagonal"` — flows top-left to bottom-right across the whole banner
- `footer` — `true` (default) prints a dim line below the banner with project name + version, PHP version, and locked package count. Set to `false` to hide.

> [!NOTE]
> The banner falls back to plain text when `NO_COLOR` is set or output isn't a TTY. Composer's `--quiet` mode suppresses it entirely via the IO layer.

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

## License

MIT.
