#!/usr/bin/env bash
# Dev-stack provisioning: database logging on, so the attribution suite can
# observe log_user_id. The site key is confirmed, never printed — the e2e
# helpers read it from the container themselves.
set -euo pipefail

cv ev '\Civi\Api4\Setting::set(FALSE)->addValue("logging", TRUE)->execute();' >/dev/null
cv ev 'echo "keyring: logging = " . (Civi::settings()->get("logging") ? "on" : "off") . "\n";'
cv ev 'echo "keyring: site key = " . (defined("CIVICRM_SITE_KEY") && CIVICRM_SITE_KEY !== "" ? "set (redacted)" : "MISSING") . "\n";'
