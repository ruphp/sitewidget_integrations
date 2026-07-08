#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="$ROOT/dist"
TMP="$DIST/tmp"

if ! command -v python3 >/dev/null 2>&1; then
    echo "python3 is required" >&2
    exit 1
fi

rm -rf "$DIST"
mkdir -p "$TMP"

copy_clean() {
    rm -rf "$TMP"
    mkdir -p "$TMP"
}

copy_clean
cp -R "$ROOT/wordpress/sitewidget" "$TMP/sitewidget"
python3 - "$TMP" "$DIST/sitewidget-wordpress-0.1.0.zip" <<'PY'
import os
import sys
import zipfile

root, archive = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED) as zf:
    for base, _, files in os.walk(root):
        for name in files:
            path = os.path.join(base, name)
            zf.write(path, os.path.relpath(path, root))
PY

copy_clean
cp -R "$ROOT/joomla/plg_system_sitewidget/"* "$TMP/"
python3 - "$TMP" "$DIST/sitewidget-joomla-system-0.1.0.zip" <<'PY'
import os
import sys
import zipfile

root, archive = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED) as zf:
    for base, _, files in os.walk(root):
        for name in files:
            path = os.path.join(base, name)
            zf.write(path, os.path.relpath(path, root))
PY

copy_clean
mkdir -p "$TMP/upload/extension/sitewidget"
cp "$ROOT/opencart/sitewidget/install.json" "$TMP/install.json"
cp -R "$ROOT/opencart/sitewidget/admin" "$TMP/upload/extension/sitewidget/admin"
cp -R "$ROOT/opencart/sitewidget/catalog" "$TMP/upload/extension/sitewidget/catalog"
python3 - "$TMP" "$DIST/sitewidget-opencart-0.1.0.ocmod.zip" <<'PY'
import os
import sys
import zipfile

root, archive = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED) as zf:
    for base, _, files in os.walk(root):
        for name in files:
            path = os.path.join(base, name)
            zf.write(path, os.path.relpath(path, root))
PY

rm -rf "$TMP"

echo "Packages built:"
find "$DIST" -maxdepth 1 -type f -printf " - %f\n" | sort
