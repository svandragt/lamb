#!/bin/bash
# SessionStart hook: provision lamb's toolchain in Claude Code on the web.
#
# Mirrors .workshop/lamb/hooks/setup-project (viv + pnpm) and adds what a web
# session needs on top: a .env for the test runner and phpcs's installed_paths.
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
# Dependencies
# ---------------------------------------------------------------------------

# Installs dependencies from composer.json/composer.lock via viv.
#
# viv is dist-only — unlike composer it has no --prefer-source fallback, so
# there is nothing to retry with when a restrictive network policy 403s the
# dist downloads (api.github.com/codeload.github.com). A failure here just
# leaves vendor/ incomplete; the WARNING below says so.
install_deps() {
  if viv install; then
    log "viv install complete"
    return 0
  fi

  return 1
}

if [ -x vendor/bin/codecept ] && [ -x vendor/bin/phpcs ] && [ -e vendor/bin/phpstan ]; then
  log "dependencies already present"
else
  install_deps || log "WARNING: viv install incomplete — dependencies are missing and, viv being dist-only, a restricted network here has no workaround"
fi

# composer.json's post-install-cmd does this, but set it either way in case
# --no-scripts or a failed install skipped it. Without it phpcs cannot resolve
# the PHPCompatibility standard phpcs.xml names.
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
