Output art/demo.gif

Set Theme { "background": "#0d1117", "foreground": "#c9d1d9", "black": "#484f58", "red": "#c9d1d9", "green": "#c9d1d9", "yellow": "#c9d1d9", "blue": "#c9d1d9", "magenta": "#c9d1d9", "cyan": "#c9d1d9", "white": "#c9d1d9", "brightBlack": "#6e7681", "brightRed": "#f0f6fc", "brightGreen": "#f0f6fc", "brightYellow": "#f0f6fc", "brightBlue": "#f0f6fc", "brightMagenta": "#f0f6fc", "brightCyan": "#f0f6fc", "brightWhite": "#f0f6fc" }
Set FontSize 22
Set Width 1320
Set Height 540
Set Padding 22
Set TypingSpeed 55ms
Set Framerate 30
Set Shell "bash"

# Initial setup: random palette rotates per install, no animation, diagonal.
Hide
Type "export PS1='$ ' && cd demo && export TERM=xterm-256color && export COLORTERM=truecolor && composer config extra.fanfare.colors random >/dev/null 2>&1 && clear"
Enter
Sleep 400ms

# 1. pagga · diagonal · drip (solid-block font shows individual cells dripping in)
Type "toilet -f pagga 'composer-fanfare' > art/banner.txt && composer config extra.fanfare.animation drip >/dev/null 2>&1 && composer config extra.fanfare.direction diagonal >/dev/null 2>&1 && clear"
Enter
Sleep 400ms
Show
Type "composer update --no-audit"
Enter
Sleep 1500ms

# 2. future · horizontal · typewriter
Hide
Type "toilet -f future 'composer-fanfare' > art/banner.txt && composer config extra.fanfare.animation typewriter >/dev/null 2>&1 && composer config extra.fanfare.direction horizontal >/dev/null 2>&1 && clear"
Enter
Sleep 400ms
Show
Type "composer update --no-audit"
Enter
Sleep 1500ms

# 3. emboss · diagonal · shimmer
Hide
Type "toilet -f emboss 'composer-fanfare' > art/banner.txt && composer config extra.fanfare.animation shimmer >/dev/null 2>&1 && composer config extra.fanfare.direction diagonal >/dev/null 2>&1 && clear"
Enter
Sleep 400ms
Show
Type "composer update --no-audit"
Enter
Sleep 1500ms

# 4. smblock · vertical · no animation (static baseline)
Hide
Type "toilet -f smblock 'composer-fanfare' > art/banner.txt && composer config --unset extra.fanfare.animation >/dev/null 2>&1 && composer config extra.fanfare.direction vertical >/dev/null 2>&1 && clear"
Enter
Sleep 400ms
Show
Type "composer update --no-audit"
Enter
Sleep 1500ms

# 5. smbraille · diagonal · shimmer
Hide
Type "toilet -f smbraille 'composer-fanfare' > art/banner.txt && composer config extra.fanfare.animation shimmer >/dev/null 2>&1 && composer config extra.fanfare.direction diagonal >/dev/null 2>&1 && clear"
Enter
Sleep 400ms
Show
Type "composer update --no-audit"
Enter
Sleep 1500ms
