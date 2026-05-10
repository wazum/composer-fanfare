Output art/demo.gif

Set Theme { "background": "#0d1117", "foreground": "#c9d1d9", "black": "#484f58", "red": "#c9d1d9", "green": "#c9d1d9", "yellow": "#c9d1d9", "blue": "#c9d1d9", "magenta": "#c9d1d9", "cyan": "#c9d1d9", "white": "#c9d1d9", "brightBlack": "#6e7681", "brightRed": "#f0f6fc", "brightGreen": "#f0f6fc", "brightYellow": "#f0f6fc", "brightBlue": "#f0f6fc", "brightMagenta": "#f0f6fc", "brightCyan": "#f0f6fc", "brightWhite": "#f0f6fc" }
Set FontSize 22
Set Width 1320
Set Height 660
Set Padding 22
Set TypingSpeed 55ms
Set Framerate 60
Set Shell "bash"

Hide
Type "export PS1='$ ' && cd demo && export TERM=xterm-256color && clear"
Enter
Sleep 400ms

# 1. pagga + sunset + diagonal
Type "toilet -f pagga 'composer-fanfare' > art/banner.txt && composer config extra.fanfare.colors sunset >/dev/null 2>&1 && composer config extra.fanfare.direction diagonal >/dev/null 2>&1 && clear"
Enter
Sleep 400ms
Show
Type "composer update --no-audit"
Enter
Sleep 5s

# 2. future + nord + horizontal
Hide
Type "toilet -f future 'composer-fanfare' > art/banner.txt && composer config extra.fanfare.colors nord >/dev/null 2>&1 && composer config extra.fanfare.direction horizontal >/dev/null 2>&1 && clear"
Enter
Sleep 400ms
Show
Type "composer update --no-audit"
Enter
Sleep 5s

# 3. emboss + dracula + diagonal
Hide
Type "toilet -f emboss 'composer-fanfare' > art/banner.txt && composer config extra.fanfare.colors dracula >/dev/null 2>&1 && composer config extra.fanfare.direction diagonal >/dev/null 2>&1 && clear"
Enter
Sleep 400ms
Show
Type "composer update --no-audit"
Enter
Sleep 5s

# 4. smblock + matrix + horizontal  (matrix paired with solid blocks for readability)
Hide
Type "toilet -f smblock 'composer-fanfare' > art/banner.txt && composer config extra.fanfare.colors matrix >/dev/null 2>&1 && composer config extra.fanfare.direction horizontal >/dev/null 2>&1 && clear"
Enter
Sleep 400ms
Show
Type "composer update --no-audit"
Enter
Sleep 5s

# 5. smbraille + synthwave + diagonal  (bright contrast on the sparse braille glyphs)
Hide
Type "toilet -f smbraille 'composer-fanfare' > art/banner.txt && composer config extra.fanfare.colors synthwave >/dev/null 2>&1 && composer config extra.fanfare.direction diagonal >/dev/null 2>&1 && clear"
Enter
Sleep 400ms
Show
Type "composer update --no-audit"
Enter
Sleep 5s
