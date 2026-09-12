#!/usr/bin/env bash
#
# Builds dist/super-abilities.zip with the plugin in a super-abilities/ directory.
#
# Uses `git archive` when the working copy is a git repository, honouring the
# export-ignore attributes in .gitattributes, and falls back to rsync with
# .distignore otherwise.

set -euo pipefail

SLUG="super-abilities"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="${ROOT}/dist"
BUILD="${DIST}/${SLUG}"
ZIP="${DIST}/${SLUG}.zip"

rm -rf "${BUILD}" "${ZIP}"
mkdir -p "${BUILD}"

cd "${ROOT}"

if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	echo "Exporting tracked files with git archive..."
	git archive --format=tar HEAD | tar -x -C "${BUILD}"
else
	echo "Not a git repository, copying with rsync and .distignore..."
	EXCLUDES=()
	if [ -f "${ROOT}/.distignore" ]; then
		while IFS= read -r line; do
			[ -z "${line}" ] && continue
			case "${line}" in
				\#*) continue ;;
			esac
			EXCLUDES+=("--exclude=${line}")
		done < "${ROOT}/.distignore"
	fi
	EXCLUDES+=("--exclude=dist" "--exclude=.git")
	rsync -a "${EXCLUDES[@]}" "${ROOT}/" "${BUILD}/"
fi

# git archive does not read .distignore, so prune anything it should not ship.
if [ -f "${ROOT}/.distignore" ]; then
	while IFS= read -r line; do
		[ -z "${line}" ] && continue
		case "${line}" in
			\#*) continue ;;
		esac
		rm -rf "${BUILD}/${line}"
	done < "${ROOT}/.distignore"
fi

cd "${DIST}"
zip -r -q "${SLUG}.zip" "${SLUG}"
rm -rf "${BUILD}"

echo "Built ${ZIP}"
