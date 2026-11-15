#!/usr/bin/env bash
# Resolves the unresolved review threads that cubic opened on a pull request.
# The branch rulesets block merging while any thread is unresolved.
# Usage: scripts/resolve-cubic-threads.sh <owner> <repo> <pr-number>
# Needs GH_TOKEN with pull-requests: write.
set -euo pipefail

if [ "$#" -ne 3 ]; then
	echo "Usage: scripts/resolve-cubic-threads.sh <owner> <repo> <pr-number>" >&2
	exit 1
fi
owner="$1"
repo="$2"
number="$3"

# shellcheck disable=SC2016 # $vars are GraphQL variables, not shell
query='query($owner: String!, $name: String!, $number: Int!, $endCursor: String) {
  repository(owner: $owner, name: $name) {
    pullRequest(number: $number) {
      reviewThreads(first: 100, after: $endCursor) {
        pageInfo { hasNextPage endCursor }
        nodes { id isResolved comments(first: 1) { nodes { author { login } } } }
      }
    }
  }
}'
pr=$(gh api graphql --paginate -f query="$query" -F owner="$owner" -F name="$repo" -F number="$number")
ids=$(jq -r '.data.repository.pullRequest.reviewThreads.nodes[]
	| select(.isResolved == false and .comments.nodes[0].author.login == "cubic-dev-ai")
	| .id' <<<"$pr")
for id in $ids; do
	# shellcheck disable=SC2016
	gh api graphql -f query='mutation($id: ID!) { resolveReviewThread(input: {threadId: $id}) { thread { id } } }' -F id="$id" >/dev/null
	echo "Resolved thread $id"
done
