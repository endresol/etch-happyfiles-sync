#!/bin/sh
# Builds dist/etch-happyfiles-sync-<version>.zip for Plugins > Add New > Upload Plugin.
# Zips the committed plugin files (HEAD) inside an etch-happyfiles-sync/ folder, as WordPress expects.
set -e
cd "$(dirname "$0")"
v=$(sed -n 's/^ \* Version: *//p' etch-happyfiles-sync.php)
mkdir -p dist
git archive --format=zip --prefix=etch-happyfiles-sync/ -o "dist/etch-happyfiles-sync-$v.zip" HEAD etch-happyfiles-sync.php README.md
echo "dist/etch-happyfiles-sync-$v.zip"
