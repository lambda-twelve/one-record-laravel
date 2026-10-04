#!/usr/bin/env bash
# Installs this package into a fresh Laravel application (from a path repository, the way an
# application would from Packagist) and walks the paths a new install touches: booting and
# `about` before anything is configured, then migrations, the commands and the routes once the
# data holder is set. Fails loudly. Usage: tools/consumer-smoke.sh <package-dir> [<work-dir>]
set -euo pipefail

PACKAGE=$(cd "${1:-.}" && pwd)
WORK=${2:-/tmp/one-record-laravel-consumer}
SDK=$(php -r '$m = json_decode(file_get_contents($argv[1]), true); echo $m["require"]["lambda-twelve/one-record"];' "$PACKAGE/composer.json")

# Each step's output is kept and shown, then checked; no `grep -q` at the end of a pipe, which
# would close it early and turn the step into a SIGPIPE under pipefail.
expect() {
    local pattern=$1 output=$2
    printf '%s\n' "$output"
    if ! grep -Eq -- "$pattern" <<< "$output"; then
        echo "consumer smoke failed: expected /$pattern/ in the output above" >&2
        exit 1
    fi
}

rm -rf "$WORK"
composer create-project laravel/laravel "$WORK" --no-interaction --no-progress --prefer-dist --quiet
cd "$WORK"
composer config repositories.one-record-laravel path "$PACKAGE"
composer require --no-interaction --no-progress --quiet "lambda-twelve/one-record:$SDK" "lambda-twelve/one-record-laravel:@dev"

echo "--- a fresh install boots and says what is missing"
expect 'Configuration .*data_holder must be set' "$(php artisan about --only=one_record)"
expect 'one-record:outbox:deliver' "$(php artisan list --raw)"

echo "--- configured: migrations, commands, routes"
export ONE_RECORD_DATA_HOLDER=holder
expect 'create_one_record_tables' "$(php artisan migrate --force --no-interaction)"
expect 'add_lease_token_to_one_record_outbox' "$(php artisan migrate:status)"
expect 'Configuration \.+ OK' "$(php artisan about --only=one_record)"
expect 'Wiring \.+ OK' "$(php artisan about --only=one_record)"
expect 'one-record/\{path\?\}' "$(php artisan route:list --path=one-record)"
expect '0 due notification' "$(php artisan one-record:outbox:deliver --inline)"
expect '0 outbox row\(s\) removed' "$(php artisan one-record:outbox:prune)"

echo "consumer smoke passed"
