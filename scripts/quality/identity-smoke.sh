#!/usr/bin/env bash

set -euo pipefail

repo_root=$(git rev-parse --show-toplevel)
api_root="$repo_root/apps/api"
web_root="$repo_root/apps/web"
smoke_root=$(mktemp -d -t maestro-identity-smoke.XXXXXX)
smoke_web_root="$smoke_root/web"
database_path="$smoke_root/database.sqlite"
cookie_jar="$smoke_root/cookies.txt"
api_port=18110
web_port=13110
api_origin="http://127.0.0.1:$api_port"
web_origin="http://localhost:$web_port"
api_pid=
web_pid=

cleanup() {
  if [[ -n "$web_pid" ]]; then
    kill "$web_pid" 2>/dev/null || true
    wait "$web_pid" 2>/dev/null || true
  fi
  if [[ -n "$api_pid" ]]; then
    kill "$api_pid" 2>/dev/null || true
    wait "$api_pid" 2>/dev/null || true
  fi
  rm -rf -- "$smoke_root"
}
trap cleanup EXIT

require_command() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Identity smoke requires $1." >&2
    exit 127
  fi
}

assert_status() {
  local expected=$1
  local actual=$2
  local operation=$3

  if [[ "$actual" != "$expected" ]]; then
    echo "$operation returned HTTP $actual; expected $expected." >&2
    exit 1
  fi
}

wait_for_url() {
  local url=$1
  local process_id=$2

  for _ in $(seq 1 300); do
    if ! kill -0 "$process_id" 2>/dev/null; then
      echo "Server exited before $url became ready." >&2
      exit 1
    fi
    if curl --silent --fail --output /dev/null "$url"; then
      return
    fi
    sleep 0.1
  done

  echo "Timed out waiting for $url." >&2
  exit 1
}

require_command curl
require_command jq
require_command php
require_command tar

touch "$database_path"
mkdir -p "$smoke_web_root"
tar -C "$web_root" \
  --exclude='.next' \
  --exclude='node_modules' \
  --exclude='.env*' \
  -cf - . | tar -C "$smoke_web_root" -xf -
ln -s "$web_root/node_modules" "$smoke_web_root/node_modules"

export APP_ENV=local
export APP_DEBUG=false
export APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
export APP_URL="$api_origin"
export FRONTEND_URL="$web_origin"
export CORS_ALLOWED_ORIGINS="$web_origin"
export SANCTUM_STATEFUL_DOMAINS="localhost:$web_port,127.0.0.1:$web_port"
export DB_CONNECTION=sqlite
export DB_DATABASE="$database_path"
export CACHE_STORE=array
export SESSION_DRIVER=database
export SESSION_SECURE_COOKIE=false
export QUEUE_CONNECTION=sync
export MAIL_MAILER=array

(
  cd "$api_root"
  php artisan migrate --force --no-interaction >"$smoke_root/migrate.log" 2>&1
  exec php artisan serve --host=127.0.0.1 --port="$api_port" >"$smoke_root/api.log" 2>&1
) &
api_pid=$!

(
  cd "$smoke_web_root"
  API_ORIGIN="$api_origin" NEXT_TELEMETRY_DISABLED=1 \
    exec "$web_root/node_modules/.bin/next" dev --webpack --port "$web_port" >"$smoke_root/web.log" 2>&1
) &
web_pid=$!

wait_for_url "$api_origin/up" "$api_pid"
wait_for_url "$web_origin/login" "$web_pid"

headers=$(curl --silent --show-error --head "$web_origin/login")
if ! grep -Eiq '^referrer-policy:[[:space:]]*no-referrer' <<<"$headers"; then
  echo "Next.js did not emit Referrer-Policy: no-referrer." >&2
  exit 1
fi

csrf_status=$(curl --silent --show-error \
  --cookie-jar "$cookie_jar" \
  --output "$smoke_root/csrf.body" \
  --write-out '%{http_code}' \
  "$web_origin/sanctum/csrf-cookie")
assert_status 204 "$csrf_status" 'CSRF bootstrap'

xsrf_encoded=$(awk '$6 == "XSRF-TOKEN" { token=$7 } END { print token }' "$cookie_jar")
# PHP expands $argv, not the shell.
# shellcheck disable=SC2016
xsrf_token=$(php -r 'echo rawurldecode($argv[1]);' "$xsrf_encoded")
if [[ -z "$xsrf_token" ]]; then
  echo 'CSRF bootstrap did not issue XSRF-TOKEN.' >&2
  exit 1
fi

register_status=$(curl --silent --show-error \
  --cookie "$cookie_jar" \
  --cookie-jar "$cookie_jar" \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --header "Origin: $web_origin" \
  --header "Referer: $web_origin/register" \
  --header "X-XSRF-TOKEN: $xsrf_token" \
  --request POST \
  --data '{"name":"Live Smoke Owner","email":"live-smoke-owner@example.test","password":"Correct-Horse-42!","password_confirmation":"Correct-Horse-42!"}' \
  --output "$smoke_root/register.json" \
  --write-out '%{http_code}' \
  "$web_origin/api/v1/auth/register")
assert_status 202 "$register_status" 'Registration'
jq --exit-status \
  '.message == "If registration can be completed, check your email for next steps."' \
  "$smoke_root/register.json" >/dev/null

duplicate_registration_status=$(curl --silent --show-error \
  --cookie "$cookie_jar" \
  --cookie-jar "$cookie_jar" \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --header "Origin: $web_origin" \
  --header "Referer: $web_origin/register" \
  --header "X-XSRF-TOKEN: $xsrf_token" \
  --request POST \
  --data '{"name":"Replacement Owner","email":"LIVE-SMOKE-OWNER@EXAMPLE.TEST","password":"Replacement-Password-84!","password_confirmation":"Replacement-Password-84!"}' \
  --output "$smoke_root/duplicate-registration.json" \
  --write-out '%{http_code}' \
  "$web_origin/api/v1/auth/register")
assert_status 202 "$duplicate_registration_status" 'Existing-address registration'
if ! cmp --silent "$smoke_root/register.json" "$smoke_root/duplicate-registration.json"; then
  echo 'New and existing registrations did not return the same response body.' >&2
  exit 1
fi

current_user_status=$(curl --silent --show-error \
  --cookie "$cookie_jar" \
  --header 'Accept: application/json' \
  --header "Referer: $web_origin/register" \
  --output "$smoke_root/current-user.json" \
  --write-out '%{http_code}' \
  "$web_origin/api/v1/auth/user")
assert_status 401 "$current_user_status" 'Unauthenticated lookup after registration'

# PHP expands its own variables.
# shellcheck disable=SC2016
php -r '
  $database = new PDO("sqlite:".$argv[1]);
  $statement = $database->prepare(
      "UPDATE users SET email_verified_at = CURRENT_TIMESTAMP WHERE email = ?"
  );
  $statement->execute(["live-smoke-owner@example.test"]);
  if ($statement->rowCount() !== 1) {
      throw new RuntimeException("Expected one user to be verified.");
  }
' "$database_path"

login_status=$(curl --silent --show-error \
  --cookie "$cookie_jar" \
  --cookie-jar "$cookie_jar" \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --header "Origin: $web_origin" \
  --header "Referer: $web_origin/login" \
  --header "X-XSRF-TOKEN: $xsrf_token" \
  --request POST \
  --data '{"email":"live-smoke-owner@example.test","password":"Correct-Horse-42!"}' \
  --output "$smoke_root/login.json" \
  --write-out '%{http_code}' \
  "$web_origin/api/v1/auth/login")
assert_status 200 "$login_status" 'Login after verification'

current_user_status=$(curl --silent --show-error \
  --cookie "$cookie_jar" \
  --header 'Accept: application/json' \
  --header "Referer: $web_origin/login" \
  --output "$smoke_root/current-user.json" \
  --write-out '%{http_code}' \
  "$web_origin/api/v1/auth/user")
assert_status 200 "$current_user_status" 'Current-user lookup after login'
jq --exit-status \
  '.data.email == "live-smoke-owner@example.test" and .data.email_verified_at != null' \
  "$smoke_root/current-user.json" >/dev/null

onboarding_payload='{"preferred_name":"Live","workspace_mode":"owner","primary_goal":"schedule","studio":{"name":"Live Smoke Studio","slug":"live-smoke-studio","timezone":"America/Bogota","currency":"USD"}}'
missing_csrf_status=$(curl --silent --show-error \
  --cookie "$cookie_jar" \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --header "Origin: $web_origin" \
  --header "Referer: $web_origin/onboarding" \
  --request POST \
  --data "$onboarding_payload" \
  --output "$smoke_root/missing-csrf.json" \
  --write-out '%{http_code}' \
  "$web_origin/api/v1/onboarding")
assert_status 419 "$missing_csrf_status" 'Onboarding without CSRF token'

# A rejected CSRF attempt is intentionally followed by the same recovery path
# as the browser adapter: bootstrap a fresh cookie and replay once.
csrf_recovery_status=$(curl --silent --show-error \
  --cookie "$cookie_jar" \
  --cookie-jar "$cookie_jar" \
  --output "$smoke_root/csrf-recovery.body" \
  --write-out '%{http_code}' \
  "$web_origin/sanctum/csrf-cookie")
assert_status 204 "$csrf_recovery_status" 'CSRF recovery bootstrap'
xsrf_encoded=$(awk '$6 == "XSRF-TOKEN" { token=$7 } END { print token }' "$cookie_jar")
# PHP expands $argv, not the shell.
# shellcheck disable=SC2016
xsrf_token=$(php -r 'echo rawurldecode($argv[1]);' "$xsrf_encoded")

onboarding_status=$(curl --silent --show-error \
  --cookie "$cookie_jar" \
  --cookie-jar "$cookie_jar" \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --header "Origin: $web_origin" \
  --header "Referer: $web_origin/onboarding" \
  --header "X-XSRF-TOKEN: $xsrf_token" \
  --request POST \
  --data "$onboarding_payload" \
  --output "$smoke_root/onboarding.json" \
  --write-out '%{http_code}' \
  "$web_origin/api/v1/onboarding")
assert_status 200 "$onboarding_status" 'Onboarding'
jq --exit-status '
  .data.slug == "live-smoke-studio"
  and .data.status == "trial"
  and .data.membership.role == "owner"
  and .data.membership.status == "active"
' "$smoke_root/onboarding.json" >/dev/null

studios_status=$(curl --silent --show-error \
  --cookie "$cookie_jar" \
  --header 'Accept: application/json' \
  --header "Referer: $web_origin/onboarding" \
  --output "$smoke_root/studios.json" \
  --write-out '%{http_code}' \
  "$web_origin/api/v1/studios")
assert_status 200 "$studios_status" 'Studio membership lookup'
jq --exit-status '
  (.data | length) == 1
  and .data[0].slug == "live-smoke-studio"
  and .data[0].membership.role == "owner"
' "$smoke_root/studios.json" >/dev/null

logout_status=$(curl --silent --show-error \
  --cookie "$cookie_jar" \
  --cookie-jar "$cookie_jar" \
  --header 'Accept: application/json' \
  --header "Origin: $web_origin" \
  --header "Referer: $web_origin/login" \
  --header "X-XSRF-TOKEN: $xsrf_token" \
  --request POST \
  --output "$smoke_root/logout.json" \
  --write-out '%{http_code}' \
  "$web_origin/api/v1/auth/logout")
assert_status 204 "$logout_status" 'Logout'

signed_out_status=$(curl --silent --show-error \
  --cookie "$cookie_jar" \
  --header 'Accept: application/json' \
  --header "Referer: $web_origin/login" \
  --output "$smoke_root/signed-out.json" \
  --write-out '%{http_code}' \
  "$web_origin/api/v1/auth/user")
assert_status 401 "$signed_out_status" 'Current-user lookup after logout'

csrf_after_logout_status=$(curl --silent --show-error \
  --cookie "$cookie_jar" \
  --cookie-jar "$cookie_jar" \
  --output "$smoke_root/csrf-after-logout.body" \
  --write-out '%{http_code}' \
  "$web_origin/sanctum/csrf-cookie")
assert_status 204 "$csrf_after_logout_status" 'CSRF bootstrap after logout'
xsrf_encoded=$(awk '$6 == "XSRF-TOKEN" { token=$7 } END { print token }' "$cookie_jar")
# PHP expands $argv, not the shell.
# shellcheck disable=SC2016
xsrf_token=$(php -r 'echo rawurldecode($argv[1]);' "$xsrf_encoded")

login_status=$(curl --silent --show-error \
  --cookie "$cookie_jar" \
  --cookie-jar "$cookie_jar" \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --header "Origin: $web_origin" \
  --header "Referer: $web_origin/login" \
  --header "X-XSRF-TOKEN: $xsrf_token" \
  --request POST \
  --data '{"email":" LIVE-SMOKE-OWNER@EXAMPLE.TEST ","password":"Correct-Horse-42!","remember":false}' \
  --output "$smoke_root/login.json" \
  --write-out '%{http_code}' \
  "$web_origin/api/v1/auth/login")
assert_status 200 "$login_status" 'Normalized-email login'

signed_in_status=$(curl --silent --show-error \
  --cookie "$cookie_jar" \
  --header 'Accept: application/json' \
  --header "Referer: $web_origin/login" \
  --output "$smoke_root/signed-in.json" \
  --write-out '%{http_code}' \
  "$web_origin/api/v1/auth/user")
assert_status 200 "$signed_in_status" 'Current-user lookup after login'
jq --exit-status '
  .data.email == "live-smoke-owner@example.test"
  and .data.email_verified_at != null
' "$smoke_root/signed-in.json" >/dev/null

echo 'Identity transport smoke passed: CSRF, session, registration, onboarding, tenant membership, logout, and login.'
