#!/usr/bin/env bash
# Assembles the fragments in changelog.d/ into a release section of CHANGELOG.md.
# Run from the repository root; see changelog.d/README.md for the fragment format.
# Usage: scripts/changelog-assemble.sh <version> [date] [--dry-run]
set -euo pipefail

usage() {
	echo "Usage: scripts/changelog-assemble.sh <version> [date] [--dry-run]" >&2
	exit 1
}

fail() {
	echo "changelog-assemble: $*" >&2
	exit 1
}

# Prints a fragment without its leading and trailing blank lines. Fails on a
# line that neither starts a bullet nor continues one, and on an empty file.
emit_fragment() {
	awk -v f="$1" '
		/^[ \t]*$/ { if (seen) pending++; next }
		/^- / { seen = 1 }
		/^- / || (/^[ \t]/ && seen) {
			for (; pending > 0; pending--) print ""
			print
			next
		}
		{
			printf "%s:%d: not a bullet (\"- \") or an indented continuation: %s\n", f, NR, $0 > "/dev/stderr"
			bad = 1
		}
		END {
			if (bad) exit 1
			if (!seen) {
				print f ": empty fragment" > "/dev/stderr"
				exit 1
			}
		}
	' "$1"
}

version=""
date=""
dry_run=0
for arg in "$@"; do
	case "$arg" in
	--dry-run) dry_run=1 ;;
	-*) usage ;;
	*)
		if [ -z "$version" ]; then
			version="$arg"
		elif [ -z "$date" ]; then
			date="$arg"
		else
			usage
		fi
		;;
	esac
done
[ -n "$version" ] || usage
date="${date:-$(date +%F)}"

[[ $version =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || fail "version must look like 1.2.3 or 1.2.3-rc1: $version"
[[ $date =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || fail "date must look like YYYY-MM-DD: $date"

changelog="CHANGELOG.md"
dir="changelog.d"
types="added changed deprecated removed fixed security"
[ -f "$changelog" ] || fail "$changelog not found; run this from the repository root"

# One index line per fragment: group|issue|rest-of-name|type|path. Names that
# start with an issue number sort first, by number and then by the rest of the
# name; slug-only names come after them. The delimiter is a non-whitespace
# character so `read` keeps empty fields.
index=""
for f in "$dir"/*.md; do
	[ -e "$f" ] || continue
	file=${f##*/}
	[ "$file" = README.md ] && continue
	[[ $file =~ ^([A-Za-z0-9_-]+)\.([a-z]+)\.md$ ]] || fail "$f: file name must be <name>.<type>.md"
	name=${BASH_REMATCH[1]}
	type=${BASH_REMATCH[2]}
	case " $types " in
	*" $type "*) ;;
	*) fail "$f: unknown type \"$type\" (use one of: $types)" ;;
	esac
	if [[ $name =~ ^([0-9]+)(-(.*))?$ ]]; then
		key="0|${BASH_REMATCH[1]}|${BASH_REMATCH[3]}"
	else
		key="1|0|$name"
	fi
	index+="$key|$type|$f"$'\n'
done
[ -n "$index" ] || fail "no fragments in $dir/ to assemble"
sorted=$(printf '%s' "$index" | LC_ALL=C sort -t '|' -k1,1n -k2,2n -k3,3)

section="## [$version] - $date"$'\n'
files=()
for heading in Added Changed Deprecated Removed Fixed Security; do
	type=$(printf '%s' "$heading" | tr '[:upper:]' '[:lower:]')
	body=""
	while IFS='|' read -r _ _ _ ftype f; do
		[ "$ftype" = "$type" ] || continue
		text=$(emit_fragment "$f")
		body+="$text"$'\n'
		files+=("$f")
	done <<<"$sorted"
	if [ -n "$body" ]; then
		section+=$'\n'"### $heading"$'\n\n'"$body"
	fi
done

if grep -q "^## \[$version\]" "$changelog"; then
	fail "$changelog already has a section for $version"
fi
unreleased_link=$(grep -m1 '^\[Unreleased\]: ' "$changelog" || true)
link_pattern='^\[Unreleased\]: (.+)/compare/.+\.\.\.HEAD$'
[[ $unreleased_link =~ $link_pattern ]] || fail "$changelog has no [Unreleased]: <url>/compare/<previous>...HEAD link"
base=${BASH_REMATCH[1]}
previous=$(awk '
	/^## \[Unreleased\]/ { found = 1; next }
	found && /^## \[/ { sub(/^## \[/, ""); sub(/\].*/, ""); print; exit }
' "$changelog")
[ -n "$previous" ] || fail "$changelog has no release section below ## [Unreleased] to compare against"

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
printf '%s' "$section" >"$work/section"
awk -v secfile="$work/section" -v version="$version" -v previous="$previous" -v base="$base" '
	/^## \[Unreleased\]/ { unreleased = 1; print; next }
	unreleased && /^## \[/ {
		while ((getline line < secfile) > 0) print line
		print ""
		unreleased = 0
	}
	unreleased && /^(- |### )/ {
		print "CHANGELOG.md: the Unreleased section holds entries; move them into changelog.d/ first: " $0 > "/dev/stderr"
		bad = 1
	}
	/^\[Unreleased\]: / {
		print "[Unreleased]: " base "/compare/" version "...HEAD"
		print "[" version "]: " base "/compare/" previous "..." version
		next
	}
	{ print }
	END { exit bad }
' "$changelog" >"$work/changelog"

if [ "$dry_run" -eq 1 ]; then
	printf '%s' "$section"
	exit 0
fi

cat "$work/changelog" >"$changelog"
for f in "${files[@]}"; do
	if git ls-files --error-unmatch -- "$f" >/dev/null 2>&1; then
		git rm -qf -- "$f"
	else
		rm -- "$f"
	fi
done
echo "Assembled ${#files[@]} fragment(s) into $changelog as [$version]."
