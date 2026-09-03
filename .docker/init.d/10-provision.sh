#!/usr/bin/env bash
# Dev-stack provisioning: database logging on, so the attribution suite can
# observe log_user_id, and the site key printed for the curl suites.
set -euo pipefail

cv ev '\Civi\Api4\Setting::set(FALSE)->addValue("logging", TRUE)->execute();' >/dev/null
cv ev 'echo "keyring: logging = " . (Civi::settings()->get("logging") ? "on" : "off") . "\n";'
cv ev 'echo "keyring: site key = " . CIVICRM_SITE_KEY . "\n";'
