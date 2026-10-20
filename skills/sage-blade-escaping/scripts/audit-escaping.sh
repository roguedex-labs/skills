#!/usr/bin/env bash
#
# Audit Blade output escaping for double-encoding / mis-escaping anti-patterns.
#
# Scans a directory of `.blade.php` files for the patterns the
# sage-blade-escaping skill forbids. Prints every hit with file:line and exits
# non-zero when any are found — so it doubles as a pre-commit / CI guard.
#
# Requires PHP CLI; has no knowledge of any particular project layout.
#
# Usage:
#   scripts/audit-escaping.sh [DIR]      # DIR defaults to the current directory
#
# Examples:
#   scripts/audit-escaping.sh                       # scan ./
#   scripts/audit-escaping.sh resources/views       # scan a views dir
#   scripts/audit-escaping.sh path/to/theme/views   # scan anywhere
#
set -euo pipefail

exec php "$(dirname "${BASH_SOURCE[0]}")/audit-escaping.php" "$@"
