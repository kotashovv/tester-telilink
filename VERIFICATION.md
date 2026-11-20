# Фактическая проверка

Дата: 20 ноября 2026.

## Выполнено

- PHP 8.3.2; MySQL Community Server 8.0.42 в отдельном datadir внутри игнорируемой папки `var/`.
- Установка Composer-зависимостей и генерация `composer.lock`.
- `php -l` для всех PHP-файлов сервиса, mock, мигратора и тестов: успешно.
- `composer validate --strict --no-check-publish`: успешно.
- `docker compose config --quiet`: успешно; проверена структура Compose и подстановка параметров.
- PHPUnit с реальным MySQL и HTTP mock: **44 теста, 199 проверок, все прошли** (10,846 секунды).

Для локальной проверки на Windows подняты два процесса mock с общей SQLite: основной endpoint обслуживает debit, второй обслуживает admin/callback. Это позволяет воспроизвести параллелизм, поскольку встроенный PHP-сервер Windows не поддерживает `PHP_CLI_SERVER_WORKERS`. В Docker на Linux mock и service используют штатный multiworker режим. Проверки гонок используют отдельные PHP-процессы и реальные MySQL advisory locks.

## Оставшаяся проверка окружения

Фактически выполнена попытка `docker compose up --build -d`. Docker Desktop установлен и запущен, но доступ к его named pipe запрещён текущему окружению:

```text
permission denied while trying to connect to the docker API at npipe:////./pipe/docker_engine
```

Поэтому успешная сборка Docker-образа и запуск на чистой машине **не подтверждены**. Это важный открытый пункт hard gate задания, а не пройденная проверка. В окружении с доступом к Docker нужно выполнить:

```sh
docker compose up --build -d --wait
docker compose --profile test run --build --rm tests
```

Секреты и локальные данные проверки находятся в `var/`, исключены из Git и Docker build context. Для проверки не использовалась существующая пользовательская БД.
