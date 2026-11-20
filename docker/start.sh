#!/bin/sh
set -eu
php service/bin/migrate.php
exec php -S 0.0.0.0:8080 service/public/index.php
