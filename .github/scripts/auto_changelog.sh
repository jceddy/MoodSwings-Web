#!/bin/bash
# Auto-generated Discord deploy-announcement changelog: a flat bullet per
# commit subject, most-recent-first-when-truncated. Used by
# deploy.yml's "Announce deploy on Discord" step whenever this version
# has no curated .github/release-notes/<VERSION>.md file (see
# .github/release-notes/README.md) -- the ordinary path for a normal,
# small deploy where a bullet-per-commit list is still readable.
#
# Usage: auto_changelog.sh <before-sha> <deployed-sha>
set -euo pipefail

BEFORE_SHA="$1"
GITHUB_SHA="$2"

# BEFORE_SHA is the commit main pointed at right before this push (unset/
# all-zero for a workflow_dispatch run or a brand-new branch) -- diffing
# from it to the SHA just deployed lists exactly the commits this deploy
# introduced. --no-merges drops the "Merge pull request #NNN ..." commits
# themselves.
#
# The subject-only "Bump VERSION to X.Y.Z" filter below used to be `git
# log --invert-grep --grep=...`, but --grep matches anywhere in the FULL
# commit message (subject + body), not just the subject line -- so a
# commit whose subject was a real, substantive change (e.g. "Right-align
# the Foo button") but whose BODY happened to mention bumping the version
# (a bundled version-bump-plus-fix commit, not a dedicated bump-only one)
# got silently dropped in its entirety, once even producing an empty
# changelog that read "no new commits" despite a real change having
# shipped. Fixed by extracting just each commit's subject line via `%s`
# first, THEN filtering with an ordinary line-anchored `grep -v` -- %s
# never includes the body, so this can only ever match a commit whose
# subject itself starts with "Bump VERSION to ", regardless of what its
# body says.
#
# `|| true` on each pipeline: under `set -o pipefail`, `grep -v` finding
# zero non-matching lines (every commit in range was a bare version bump)
# exits 1, which -- being the rightmost non-zero exit status in the
# pipeline -- would otherwise trip `set -e` and abort this whole script
# instead of falling through to the "no new commits" fallback below.
#
# --reverse: `git log` defaults to newest-first, which read as backwards
# here -- a bullet list where an EARLIER line mentions something not
# explained until a LATER line, because "later" in the list actually
# meant "committed earlier". This lists commits oldest-first instead,
# i.e. in implementation order, matching the order the changes actually
# happened in.
if [ -z "$BEFORE_SHA" ] || [ "$BEFORE_SHA" = "0000000000000000000000000000000000000000" ]; then
    CHANGES_FULL="$(git log --no-merges --reverse -1 --pretty=format:'%s' | grep -v '^Bump VERSION to ' | sed 's/^/- /' || true)"
else
    CHANGES_FULL="$(git log --no-merges --reverse --pretty=format:'%s' "${BEFORE_SHA}..${GITHUB_SHA}" | grep -v '^Bump VERSION to ' | sed 's/^/- /' || true)"
fi
if [ -z "$CHANGES_FULL" ]; then
    CHANGES_FULL="- (no new commits since the last deploy)"
fi

# Capped well under Discord's 2000-char message limit so the version/URL
# lines are never at risk of being cut off by a large batch of promoted
# commits (e.g. a development -> main release PR bundling several
# feature PRs at once -- though see .github/release-notes/README.md for
# the better fix in that case: a curated summary instead of this
# fallback). Keeps the last 15 (tail, not head) -- since CHANGES_FULL is
# oldest-first per --reverse above, the last 15 are the MOST RECENT
# commits, the ones most worth keeping visible when something has to be
# cut. The omitted-count note goes BEFORE those 15 rather than trailing
# after them, since it stands for commits that chronologically precede
# everything actually listed.
CHANGES="$(printf '%s\n' "$CHANGES_FULL" | tail -n 15)"
TOTAL_LINES=$(printf '%s\n' "$CHANGES_FULL" | wc -l)
if [ "$TOTAL_LINES" -gt 15 ]; then
    CHANGES="$(printf -- '- …and %d earlier commit(s)\n%s' "$((TOTAL_LINES - 15))" "$CHANGES")"
fi

printf '%s\n' "$CHANGES"
