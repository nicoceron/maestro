#!/usr/bin/env bash

set -euo pipefail

repo_root=$(git rev-parse --show-toplevel)
api_root="$repo_root/apps/api"
browser_tmp=$(mktemp -d -t maestro-browser.XXXXXX)
database_path="$browser_tmp/database.sqlite"

cleanup() {
  rm -rf -- "$browser_tmp"
}
trap cleanup EXIT

touch "$database_path"

export APP_ENV=testing
export APP_DEBUG=false
export APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
export APP_URL='http://127.0.0.1:18120'
export DB_CONNECTION=sqlite
export DB_DATABASE="$database_path"
export CACHE_STORE=array
export SESSION_DRIVER=file
export SESSION_SECURE_COOKIE=false
export QUEUE_CONNECTION=sync
export MAIL_MAILER=array
export FILESYSTEM_DISK=local
export BROADCAST_CONNECTION=log
export BROWSER_BASE_URL='http://127.0.0.1:18120'

cd "$api_root"
php artisan migrate:fresh --force --no-interaction
php artisan db:seed --class=Database\\Seeders\\BrowserJourneySeeder --force --no-interaction
npm run build
npm run test:browser
