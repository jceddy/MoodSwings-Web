# Release notes

`deploy.yml`'s "Announce deploy on Discord" step normally builds its
changelog automatically, one bullet per commit subject (see
`.github/scripts/auto_changelog.sh`) -- fine for an ordinary small
deploy, but a big batched `development` -> `main` promotion can carry
far more commits than a flat, truncated, one-bullet-per-commit list can
meaningfully summarize. Several related commits read far better as one
grouped bullet ("various bot AI fixes across a dozen mood cards") than
as several near-duplicate lines crowding out everything else, or
silently falling off the truncation cutoff.

To override the auto-generated list for one specific deploy, add a file
here named after the exact version being deployed (the repo-root
`VERSION` file's own contents, e.g. `1.28.18.md`), containing a Markdown
bullet list. The deploy step reads it verbatim if present -- no
truncation, no auto-generation -- so keep it to **10 bullets or fewer**
and well under Discord's 2000-character message limit (the version/URL
header line eats a little of that budget too).

Write it as part of the promotion PR (`development` -> `main`) itself,
grouping related commits by theme rather than listing every one --
e.g. "Numerous practice-bot AI fixes across many mood cards" instead of
ten separate one-line entries for ten separate card fixes. The file is
never required; when it's absent (the common case), the auto-generated
list is used exactly as before.
