#!/usr/bin/env bash
#
# Clone the foreign extensions this project reads as source: the Outlook 365
# add-in and its dependencies. They land OUTSIDE the repository — ckconform
# and phpstan walk the working tree, and foreign code would drown their
# output — and they are deliberately not mounted into the stack, because the
# civikitchen image auto-enables everything under ext/ and
# com.cividesk.apikey aborts the boot (docs/measurements.md).
set -euo pipefail

DEST="${KEYRING_EXT_DIR:-${XDG_CACHE_HOME:-$HOME/.cache}/keyring-ext}"
mkdir -p "$DEST"

clone() { # clone <dir> <url>
  if [ -d "$DEST/$1/.git" ]; then
    echo "  = $DEST/$1 (present)"
  else
    echo "  + $DEST/$1"
    git clone --depth 1 "$2" "$DEST/$1"
  fi
}

clone outlook365          https://lab.civicrm.org/extensions/outlook365.git
clone dataprocessor       https://lab.civicrm.org/extensions/dataprocessor.git
clone com.cividesk.apikey https://github.com/cividesk/com.cividesk.apikey.git

echo
echo "Foreign sources in $DEST"
