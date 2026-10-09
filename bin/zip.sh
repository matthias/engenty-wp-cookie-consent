#!/bin/sh
# cookie-dialog.zip: what WordPress installs, built files included, sources of the build left out.
set -eu
cd "$(dirname "$0")/.."
[ -f build/dialog.js ] || npm run build
rm -rf dist && mkdir -p dist/cookie-dialog
cp -R cookie-dialog.php uninstall.php readme.txt LICENSE includes blocks build presets assets languages dist/cookie-dialog/
rm -f cookie-dialog.zip
(cd dist && zip -qr ../cookie-dialog.zip cookie-dialog)
rm -rf dist
echo "cookie-dialog.zip"
