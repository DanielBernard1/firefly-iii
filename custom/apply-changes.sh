#!/usr/bin/env bash
#
# apply-changes.sh
#
# Custom addition (not part of upstream Firefly III).
# Runs inside "docker build" (see custom/Dockerfile). It makes the only two changes to
# existing Firefly III files that the "Investments" page needs:
#
#   1. routes/web.php:   one line at the end that loads routes/custom/investments.php
#   2. sidebar.blade.php: one line that adds the "Investments" menu entry
#
# Both edits are made to the files that are already inside the official image, so they
# also work when you later switch to a newer Firefly III version. If Firefly III ever
# changes the sidebar so the edit cannot be made, the build stops with a clear error
# instead of producing a broken image. Running this script twice changes nothing.

set -euo pipefail

cd "${FIREFLY_III_PATH:-/var/www/html}"

WEB="routes/web.php"
SIDEBAR="resources/views/components/layout/sidebar.blade.php"
MENU_LINE="    @include('investments.menu-item')"
# the menu entry is placed just above the "Accounting" heading in the menu.
ANCHOR="__('firefly.accounting')"

# 1. routes
if ! grep -q "custom/investments.php" "$WEB"; then
    printf '\n// Custom addition: "Investments" page (all its routes are in this file).\nrequire __DIR__.'"'"'/custom/investments.php'"'"';\n' >> "$WEB"
    echo "[investments] Added route file to $WEB"
fi

# 2. menu entry
if ! grep -qF "investments.menu-item" "$SIDEBAR"; then
    if [ "$(grep -cF "$ANCHOR" "$SIDEBAR")" != "1" ]; then
        echo "[investments] ERROR: could not find the place for the menu entry in $SIDEBAR." >&2
        echo "[investments] Firefly III probably changed this file. Look for the 'Accounting' heading and update ANCHOR in custom/apply-changes.sh." >&2
        exit 1
    fi
    awk -v line="$MENU_LINE" -v anchor="$ANCHOR" 'index($0, anchor) { print line } { print }' "$SIDEBAR" > /tmp/sidebar.blade.php
    cat /tmp/sidebar.blade.php > "$SIDEBAR"
    rm /tmp/sidebar.blade.php
    echo "[investments] Added menu entry to $SIDEBAR"
fi

# 3. check that all PHP files of the investments page are valid PHP.
for file in app/Support/Investments/*.php app/Http/Controllers/Investments/*.php routes/custom/investments.php resources/lang/en_US/investments.php "$WEB"; do
    php -l "$file" > /dev/null
done
echo "[investments] All changes applied."
