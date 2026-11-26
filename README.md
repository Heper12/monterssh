# monterssh

Тулза для монтирования проекта на компьютер по SSH.

CLI-утилита на PHP для управления SSHFS-подключениями на macOS.

## Требования

- macOS
- PHP CLI
- `sshfs` и macFUSE
- SSH-ключи в `~/.ssh`

## Запуск

```sh
php mount-sshfs.php
```

При первом запуске программа создаст локальный `data.json`. Файл содержит адреса серверов и параметры SSH-подключений, поэтому он исключён из Git.

Проект основан на [mountsshfs](https://github.com/garrylie/mountsshfs) и развивается как `monterssh`.
