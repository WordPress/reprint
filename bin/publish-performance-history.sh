#!/usr/bin/env bash
# Publish only the completed history file, from a fresh publishing checkout.
set -euo pipefail

history_file="$1"
: "${GH_TOKEN:?GH_TOKEN is required to publish performance history.}"
: "${GITHUB_SHA:?GITHUB_SHA is required for the performance-history commit.}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

git config user.name 'github-actions[bot]'
git config user.email 'github-actions[bot]@users.noreply.github.com'
if git ls-remote --exit-code --heads origin gh-pages >/dev/null 2>&1; then
    git fetch origin gh-pages:gh-pages
    git worktree add pages gh-pages
else
    git worktree add --detach pages
    (cd pages && git checkout --orphan gh-pages && git reset --hard && find . -mindepth 1 -maxdepth 1 -not -name .git -exec rm -rf {} +)
fi

mkdir -p pages/data
# Static files come from this checkout, not from the benchmark artifact.
rsync -a --exclude='data/history.json' .github/perf-site/ pages/
cp "$history_file" pages/data/history.json
touch pages/.nojekyll
cd pages
git add -A
if git diff --cached --quiet; then
    echo 'Nothing to publish.'
    exit 0
fi
git commit -m "perf: history for $GITHUB_SHA"
# The token is supplied only to this publishing step and is never saved in Git config.
git -c credential.helper= \
    -c 'credential.helper=!f() { echo username=x-access-token; echo "password=$GH_TOKEN"; }; f' \
    push origin gh-pages
