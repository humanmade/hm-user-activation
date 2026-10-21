#!/usr/bin/env bash
#
# Put a version into the plugin header, the version constant and each built
# block.json. Core uses a block's version as the ?ver= of its stylesheet.
# Run after npm run build.
#
# Usage: stamp-version.sh <version>

set -euo pipefail

version="$1"

sed -i.bak "s/__VERSION__/${version}/g" hm-user-activation.php
rm hm-user-activation.php.bak

for file in build/blocks/*/block.json; do
	jq --arg version "$version" '.version = $version' "$file" > "$file.tmp"
	mv "$file.tmp" "$file"
done
