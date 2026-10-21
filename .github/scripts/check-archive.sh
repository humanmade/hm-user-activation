#!/usr/bin/env bash
#
# Check that a plugin ZIP made by `git archive` contains the built blocks and
# none of the source, test or tooling files. With a version, also check that
# it was stamped into the plugin header, the constant and each built block.json.
#
# Usage: check-archive.sh <zip> [version]

set -euo pipefail

zip="$1"
version="${2:-}"
prefix='hm-user-activation/'
files="$( unzip -Z1 "$zip" )"
status=0

required=(
	hm-user-activation.php
	composer.json
	includes/upgrade.php
	build/variations/index.js
	build/variations/index.asset.php
)

for block in activation-form login-form password-reset registration-form resend-form; do
	required+=(
		"build/blocks/${block}/block.json"
		"build/blocks/${block}/index.js"
		"build/blocks/${block}/index.asset.php"
		"build/blocks/${block}/render.php"
	)
done

for block in activation-form password-reset registration-form; do
	required+=( "build/blocks/${block}/style-index.css" )
done

for file in "${required[@]}"; do
	if ! grep -qxF "${prefix}${file}" <<< "$files"; then
		echo "::error::${zip} is missing ${file}"
		status=1
	fi
done

forbidden="$( grep -E "^${prefix}(\.[^/]+|src/|tests/|node_modules/|vendor/|package\.json|package-lock\.json|webpack\.config\.js|playwright\.config\.js|phpcs\.xml|composer\.lock|REVIEW\.md)" <<< "$files" || true )"

if [ -n "$forbidden" ]; then
	echo "::error::${zip} contains files that should not ship:"
	echo "$forbidden"
	status=1
fi

if [ -n "$version" ]; then
	main_file="$( unzip -p "$zip" "${prefix}hm-user-activation.php" )"

	if ! grep -qxF " * Version: ${version}" <<< "$main_file" || ! grep -qF "define( 'HM_USER_ACTIVATION_VERSION', '${version}' );" <<< "$main_file"; then
		echo "::error::hm-user-activation.php in ${zip} does not have version ${version}"
		status=1
	fi

	for block in activation-form login-form password-reset registration-form resend-form; do
		if ! unzip -p "$zip" "${prefix}build/blocks/${block}/block.json" | grep -qF "\"version\": \"${version}\""; then
			echo "::error::build/blocks/${block}/block.json in ${zip} does not have version ${version}"
			status=1
		fi
	done
fi

exit "$status"
