#!/bin/bash
# Fallback toolchain for Claude Code cloud sessions whose network blocks
# viv's dist downloads (api.github.com / codeload.github.com) but allows git.
#
# session-start.sh points here when `viv install` comes up short. It takes a
# few minutes (everything is cloned from source), which is why it is not run
# from the hook itself. Safe to re-run: each step skips what is already there.
#
#   bash .claude/hooks/cloud-fallback.sh
#
# What it does:
#   1. PHP 8.4 from nix when the image only has 8.3, as a `php` wrapper in
#      ~/.local/bin (early on PATH — tests spawn `php` themselves) with an ini
#      that keeps vendor deprecation notices off stdout.
#   2. composer install --prefer-source from a copy of composer.json and
#      composer.lock without phpstan (dist-only). The tracked files are untouched.
#   3. phpstan from its release tag's git checkout, which ships the phar,
#      shimmed into vendor/bin/phpstan.
#   4. phpcs's installed_paths — composer skips plugins as root.
set -uo pipefail

cd "${CLAUDE_PROJECT_DIR:-$(dirname "$0")/../..}" || exit 1

log() { printf '[cloud-fallback] %s\n' "$1"; }
cache="$HOME/.cache/lamb-cloud"
mkdir -p "$cache" "$HOME/.local/bin"

# ---------------------------------------------------------------------------
# 1. PHP 8.4
# ---------------------------------------------------------------------------

if php -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);' 2>/dev/null; then
  log "PHP $(php -r 'echo PHP_VERSION;') already present"
elif command -v nix >/dev/null 2>&1; then
  log "building PHP 8.4 with nix"
  php84=$(nix --extra-experimental-features 'nix-command flakes' build nixpkgs#php84 --no-link --print-out-paths 2>/dev/null | tail -1)
  if [ -x "$php84/bin/php" ]; then
    printf 'display_errors=stderr\nerror_reporting=E_ALL & ~E_DEPRECATED\nmemory_limit=512M\n' > "$cache/php.ini"
    printf '#!/bin/sh\nexec %s -c %s "$@"\n' "$php84/bin/php" "$cache/php.ini" > "$HOME/.local/bin/php"
    chmod +x "$HOME/.local/bin/php"
    hash -r
    log "PHP $("$HOME/.local/bin/php" -r 'echo PHP_VERSION;') installed as $HOME/.local/bin/php"
  else
    log "ERROR: nix could not build php84"
    exit 1
  fi
else
  log "ERROR: PHP 8.4 is required and nix is not available"
  exit 1
fi
PHP="$HOME/.local/bin/php"
[ -x "$PHP" ] || PHP=php

# ---------------------------------------------------------------------------
# 2. Composer dependencies from source
# ---------------------------------------------------------------------------

if [ -x vendor/bin/codecept ] && [ -x vendor/bin/phpcs ]; then
  log "composer dependencies already present"
else
  log "installing composer dependencies from source (several minutes)"
  # Install from a phpstan-less copy of composer.json/composer.lock, named via
  # COMPOSER, so the tracked files are never edited: an interrupted run
  # leaves at most these two untracked copies behind. They sit next to the
  # originals because composer resolves autoload paths and vendor/ from the
  # manifest's directory.
  "$PHP" -r '
    $l = json_decode(file_get_contents("composer.lock"), true);
    $l["packages-dev"] = array_values(array_filter($l["packages-dev"], fn($p) => $p["name"] !== "phpstan/phpstan"));
    file_put_contents(".composer-cloud.lock", json_encode($l, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $j = json_decode(file_get_contents("composer.json"), true);
    unset($j["require-dev"]["phpstan/phpstan"]);
    file_put_contents(".composer-cloud.json", json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  '
  trap 'rm -f .composer-cloud.json .composer-cloud.lock' EXIT
  # With the session's GITHUB_TOKEN set, composer fails with "Could not
  # authenticate against github.com" even for git sources.
  if env -u GITHUB_TOKEN COMPOSER=.composer-cloud.json COMPOSER_NO_INTERACTION=1 \
      "$PHP" "$(command -v composer)" install --prefer-source --ignore-platform-req=php \
      --no-progress >"$cache/composer.log" 2>&1; then
    log "composer install complete"
  else
    log "ERROR: composer install failed, see $cache/composer.log"
  fi
  rm -f .composer-cloud.json .composer-cloud.lock
  trap - EXIT
fi

# ---------------------------------------------------------------------------
# 3. PHPStan
# ---------------------------------------------------------------------------

if [ -e vendor/bin/phpstan ]; then
  log "phpstan already present"
elif [ -d vendor/bin ]; then
  version=$("$PHP" -r '
    foreach (json_decode(file_get_contents("composer.lock"), true)["packages-dev"] as $p) {
      if ($p["name"] === "phpstan/phpstan") { echo $p["version"]; }
    }')
  if [ ! -f "$cache/phpstan-$version/phpstan.phar" ]; then
    git clone -q --depth 1 --branch "$version" https://github.com/phpstan/phpstan.git "$cache/phpstan-$version" 2>/dev/null
  fi
  if [ -f "$cache/phpstan-$version/phpstan.phar" ]; then
    # A PHP shim, so both `vendor/bin/phpstan` and `php vendor/bin/phpstan` work.
    printf '#!/usr/bin/env php\n<?php\nrequire %s;\n' "'$cache/phpstan-$version/phpstan.phar'" > vendor/bin/phpstan
    chmod +x vendor/bin/phpstan
    log "phpstan $version shimmed into vendor/bin/phpstan"
  else
    log "ERROR: could not fetch phpstan $version"
  fi
fi

# ---------------------------------------------------------------------------
# 4. phpcs standard
# ---------------------------------------------------------------------------

if [ -x vendor/bin/phpcs ]; then
  "$PHP" vendor/bin/phpcs --config-set installed_paths vendor/phpcompatibility/php-compatibility >/dev/null 2>&1 || true
fi

log "done. MakePasswordTest (container run) and MicropubMediaEndpointTest (store failure) fail in this environment regardless of the code."
