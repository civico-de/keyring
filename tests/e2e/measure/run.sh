#!/usr/bin/env bash
#
# Runs every keyring e2e suite against the .docker dev stack and leaves the raw
# evidence in tests/e2e/measure/out/, the directory docs/measurements.md cites.
#
# Usage:  bash tests/e2e/measure/run.sh
set -uo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
rc=0
for s in "$here"/[0-9][0-9]-*.sh; do
  echo "──────────────────────────────────────────────────────────────"
  bash "$s" || rc=1
  echo
done
exit "$rc"
