#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

printf '\n=== Local worktree ===\n'
git status --short --branch

printf '\n=== Local branches and tracking status ===\n'
git branch -vv

printf '\n=== Recent history ===\n'
git log --oneline --decorate --graph -25

printf '\n=== Local main ===\n'
if git show-ref --verify --quiet refs/heads/main; then
  git show -s --format='%H%n%cs%n%s' main
else
  echo 'No local main branch found.'
fi

printf '\n=== Phase 164 commit cfca6b7 ===\n'
if git cat-file -e 'cfca6b7^{commit}' 2>/dev/null; then
  git show -s --format='%H%n%cs%n%s' cfca6b7
  echo 'Branches containing cfca6b7:'
  git branch --all --contains cfca6b7 || true
  if git show-ref --verify --quiet refs/heads/main; then
    if git merge-base --is-ancestor cfca6b7 main; then
      echo 'Local main contains cfca6b7.'
    else
      echo 'Local main does NOT contain cfca6b7 — design system branch not yet merged.'
    fi
  fi
else
  echo 'Commit cfca6b7 is not present in this local object database.'
fi

cat <<'NOTE'

NOTE: This is a local Git snapshot, not a GitHub CI or production-deployment check.
Fetch/prune remotes first if appropriate. Verify CI runs and deployed commit through
trusted GitHub/host interfaces; do not treat a local branch as proof of deployment.
NOTE
