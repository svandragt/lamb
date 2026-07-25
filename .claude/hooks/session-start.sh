#!/bin/bash
# SessionStart hook: provision lamb's toolchain in Claude Code on the web.
#
# Mirrors .workshop/lamb/hooks/setup-project (composer + pnpm) and adds what a
# web session needs on top: a .env for the test runner, phpcs's installed_paths,
# and a fallback for environments whose network policy blocks GitHub's archive
# hosts (see install_composer_deps below).
#
# Local development is unaffected — devbox, the devcontainer and Workshop each
# have their own setup, so this exits immediately outside a remote session.
set -uo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

cd "${CLAUDE_PROJECT_DIR:-$(dirname "$0")/../..}" || exit 0

log() { printf '[session-start] %s\n' "$1"; }

# ---------------------------------------------------------------------------
# Environment
# ---------------------------------------------------------------------------

# The remote container runs as root, and composer refuses to run *scripts* as
# root unless this is set — which silently breaks every documented entry point
# (composer lint / analyse / test / coverage) while `vendor/bin/*` still works.
# Exporting it for the session means AGENTS.md's commands work as written.
if [ -n "${CLAUDE_ENV_FILE:-}" ] && ! grep -q COMPOSER_ALLOW_SUPERUSER "$CLAUDE_ENV_FILE" 2>/dev/null; then
  echo 'export COMPOSER_ALLOW_SUPERUSER=1' >> "$CLAUDE_ENV_FILE"
fi
export COMPOSER_ALLOW_SUPERUSER=1

# ---------------------------------------------------------------------------
# Composer
# ---------------------------------------------------------------------------

# The version composer.lock pins phpstan/phpstan to, e.g. "2.2.5".
locked_phpstan_version() {
  php -r '$l = json_decode(file_get_contents("composer.lock"), true) ?: [];
    foreach ($l["packages-dev"] ?? [] as $p) {
      if ($p["name"] === "phpstan/phpstan") { echo $p["version"]; }
    }' 2>/dev/null
}

# Clones phpstan/phpstan at its locked version straight from GitHub.
#
# It is the one dependency published as a dist archive only (the package *is* a
# built phar), so a source install cannot resolve it. git works where the
# archive hosts may not, because Claude Code routes github.com through its own
# git proxy.
#
# The version is passed in rather than read here: the caller has taken phpstan
# out of composer.lock by this point, so reading it back would find nothing.
install_phpstan_from_git() {
  local version="$1"
  [ -n "$version" ] || return 1

  rm -rf vendor/phpstan/phpstan
  mkdir -p vendor/phpstan
  git -c advice.detachedHead=false clone --quiet --depth 1 --branch "$version" \
    https://github.com/phpstan/phpstan.git vendor/phpstan/phpstan || return 1
  mkdir -p vendor/bin
  ln -sf ../phpstan/phpstan/phpstan vendor/bin/phpstan
  log "phpstan $version installed from git"
}

# Installs the composer dependencies, tolerating a restrictive network policy.
#
# The normal path is a plain --prefer-dist install. When the environment's
# policy blocks api.github.com/codeload.github.com, every dist download 403s,
# so fall back to installing from source: composer then clones each package
# through the session's git proxy instead of downloading a zipball.
#
# The source path needs phpstan/phpstan held back (see above), which means
# taking it out of composer.json/composer.lock for the duration of the install
# and restoring both afterwards — the trap restores them even if it fails, so
# the working tree is never left dirty.
install_composer_deps() {
  if composer install --no-interaction --prefer-dist --no-progress 2>/dev/null; then
    log "composer install (dist) complete"
    return 0
  fi

  log "dist downloads unavailable (network policy?) — installing from source"
  composer config --global use-github-api false >/dev/null 2>&1 || true

  local phpstan_version
  phpstan_version=$(locked_phpstan_version)

  local backup
  backup=$(mktemp -d)
  cp composer.json composer.lock "$backup/"
  # shellcheck disable=SC2064  # $backup must expand now, not at trap time.
  trap "cp '$backup/composer.json' '$backup/composer.lock' . 2>/dev/null; rm -rf '$backup'" RETURN

  php -r '
    foreach (["composer.json" => "require-dev", "composer.lock" => "packages-dev"] as $file => $key) {
      $data = json_decode(file_get_contents($file), true);
      if ($key === "require-dev") {
        unset($data[$key]["phpstan/phpstan"]);
      } else {
        $data[$key] = array_values(array_filter(
          $data[$key],
          fn($p) => $p["name"] !== "phpstan/phpstan"
        ));
      }
      file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
  ' || return 1

  composer install --no-interaction --prefer-source --no-progress --no-scripts || return 1
  log "composer install (source) complete"
  install_phpstan_from_git "$phpstan_version" \
    || log "WARNING: phpstan unavailable — 'composer analyse' will not run"
}

if [ -x vendor/bin/codecept ] && [ -x vendor/bin/phpcs ] && [ -e vendor/bin/phpstan ]; then
  log "composer dependencies already present"
else
  install_composer_deps || log "WARNING: composer install incomplete"
fi

# composer.json's post-install-cmd does this, but the source path above runs
# with --no-scripts (the lock is patched at that point), so set it either way.
# Without it phpcs cannot resolve the PHPCompatibility standard phpcs.xml names.
if [ -x vendor/bin/phpcs ]; then
  vendor/bin/phpcs --config-set installed_paths vendor/phpcompatibility/php-compatibility >/dev/null 2>&1 || true
fi

# ---------------------------------------------------------------------------
# Test environment
# ---------------------------------------------------------------------------

# codeception.yml reads .env; the Acceptance suite needs SITE_URL and
# LAMB_TEST_PASSWORD, and LOGIN_PASSWORD is captured from the environment at
# require time. Mirrors the CI step. An existing .env is left alone.
if [ ! -f .env ] && [ -f make-password.php ]; then
  LAMB_WRITE_TEST_PASSWORD=1 php make-password.php "claude-web-session" >/dev/null 2>&1 \
    && log ".env written for the test suite" \
    || log "WARNING: could not write .env"
fi

# ---------------------------------------------------------------------------
# JavaScript (pnpm test, playwright)
# ---------------------------------------------------------------------------

# Chromium ships with the image and PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD stops
# @playwright/test's postinstall from trying to fetch its own copy.
if [ -f package.json ] && command -v pnpm >/dev/null 2>&1; then
  if [ -d node_modules ]; then
    log "node_modules already present"
  else
    PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 CI=true pnpm install --frozen-lockfile >/dev/null 2>&1 \
      && log "pnpm install complete" \
      || log "WARNING: pnpm install failed"
  fi
fi

log "ready"
exit 0
