#!/usr/bin/env bash
#
# Audit Blade output escaping for double-encoding / mis-escaping anti-patterns.
#
# Scans a directory of `.blade.php` files for the patterns the
# sage-blade-escaping skill forbids. Prints every hit with file:line and exits
# non-zero when any are found — so it doubles as a pre-commit / CI guard.
#
# This script is self-contained: it takes the directory to scan as an argument
# and has no knowledge of any particular project layout.
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

target="${1:-.}"

if [[ ! -d "$target" ]]; then
    echo "directory not found: $target" >&2
    echo "usage: $(basename "$0") [DIR]   (DIR defaults to the current directory)" >&2
    exit 2
fi

found=0

report() {
    local label="$1" pattern="$2"
    local hits
    hits="$(grep -rEn --include='*.blade.php' "$pattern" "$target" || true)"
    if [[ -n "$hits" ]]; then
        echo "✗ $label"
        echo "$hits" | sed 's/^/    /'
        echo
        found=1
    fi
}

echo "Auditing *.blade.php under: $target"
echo

# 1. esc_url() or esc_url_raw() passed through {{ }} → double-encodes & ' "
report 'Display URL helper inside {{ }} (double-encodes) — use {!! esc_url(...) !!}' \
    '\{\{ *esc_url(_raw)? *\('

# 2. esc_html / esc_attr wrapping inside {{ }} → redundant, Blade already escaped
report 'Redundant esc_html/esc_attr inside {{ }} — drop to bare {{ $var }}' \
    '\{\{ *esc_(html|attr) *\('

# 3. esc_js() inside {{ }} → context-specific; prefer Js::from/@json for data
report 'esc_js inside {{ }} — review JS context; do not double-encode it through Blade' \
    '\{\{ *esc_js *\('

# 4. Already-escaped WP/WC HTML pushed through {{ }} → prints tags as literal text
report 'WP/WC HTML inside {{ }} (prints tags as text) — use {!! ... !!}' \
    '\{\{[^}]*(wp_kses_post|wc_price|get_price_html|get_the_term_list|the_content) *\('

if [[ "$found" -eq 0 ]]; then
    echo "✓ No known double-encoding anti-patterns found. Manually review any raw {!! !!} echoes."
    exit 0
fi

echo "Found escaping anti-patterns. See the sage-blade-escaping SKILL.md." >&2
exit 1
