#!/bin/sh
# 本地没有 php CLI，用 php:8.4-cli 容器做语法检查。
# 用法：docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli sh scripts/php-lint.sh [路径...]
# 不传路径时检查 app/ database/ routes/ config/ 下全部 PHP 文件。
set -e

if [ "$#" -gt 0 ]; then
    for file in "$@"; do
        php -l "$file" > /dev/null || { php -l "$file"; exit 1; }
    done
    echo "php -l passed: $# file(s)."
    exit 0
fi

count=0
for file in $(find app database routes config tests -name '*.php' -type f | sort); do
    php -l "$file" > /dev/null || { php -l "$file"; exit 1; }
    count=$((count + 1))
done
echo "php -l passed: $count file(s)."
