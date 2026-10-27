#!/usr/bin/env bash
# Generates a Keep a Changelog section from a milestone's closed issues.
# Usage: scripts/changelog.sh <milestone-title> <version> [date] [--write]
set -euo pipefail

REPO="svandragt/lamb"

milestone=""
version=""
date=""
write=0
for arg in "$@"; do
	case "$arg" in
	--write) write=1 ;;
	*)
		if [ -z "$milestone" ]; then
			milestone="$arg"
		elif [ -z "$version" ]; then
			version="$arg"
		else
			date="$arg"
		fi
		;;
	esac
done

if [ -z "$milestone" ] || [ -z "$version" ]; then
	echo "Usage: scripts/changelog.sh <milestone-title> <version> [date] [--write]" >&2
	exit 1
fi

date="${date:-$(date +%F)}"

issues_json=$(gh issue list --repo "$REPO" --milestone "$milestone" --state closed --limit 200 --json number,title,labels)

section=$(echo "$issues_json" | jq -r '
	def bucket:
		(.labels | map(.name)) as $labels
		| if $labels | index("security") then "Security"
		elif ($labels | index("bug")) or (.title | test("^Fix "; "i")) then "Fixed"
		elif (.title | test("^(Remove|Drop) "; "i")) then "Removed"
		elif (.title | test("^Deprecate "; "i")) then "Deprecated"
		elif (.title | test("should|no longer|instead"; "i")) then "Changed"
		else "Added"
		end;
	["Added", "Changed", "Deprecated", "Removed", "Fixed", "Security"] as $order
	| map(. + {bucket: bucket})
	| group_by(.bucket)
	| map({bucket: .[0].bucket, items: sort_by(.number)})
	| INDEX(.bucket) as $by
	| $order[] as $b
	| ($by[$b].items // empty)
	| "### \($b)\n" + (map("- \(.title) ([#\(.number)](https://github.com/'"$REPO"'/issues/\(.number)))") | join("\n")) + "\n"
')

printf '## [%s] - %s\n%s' "$version" "$date" "$section"
echo

if [ "$write" -eq 1 ]; then
	changelog="CHANGELOG.md"
	if [ ! -f "$changelog" ]; then
		cat >"$changelog" <<'EOF'
# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]
EOF
	fi

	new_section=$(printf '## [%s] - %s\n%s' "$version" "$date" "$section")
	tmp=$(mktemp)
	awk -v section="$new_section" '
		/^## \[Unreleased\]/ { print; print ""; print section; inserted=1; next }
		{ print }
	' "$changelog" >"$tmp"
	mv "$tmp" "$changelog"

	link_line="[$version]: https://github.com/$REPO/releases/tag/$version"
	if ! grep -qF "$link_line" "$changelog"; then
		echo "$link_line" >>"$changelog"
	fi
fi
