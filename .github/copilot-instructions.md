# Commit and versioning instructions

Use this repository's Git history as a guide, but prefer the following workflow going forward:

## Commits

- Use Conventional Commits for normal work: `feat:`, `fix:`, `docs:`, `refactor:`, `test:`, `chore:`.
- Keep commits scoped to one logical change.
- Do not use raw version numbers like `v0.1.3` for ordinary implementation commits.

## Releases

- The root `VERSION` file is the source of truth.
- Release commits should use `chore(release): vX.Y.Z`.
- Every release should also create an annotated Git tag `vX.Y.Z`.
- Push tags so the GitHub release workflow can generate a GitHub Release automatically.

## Practical flow

1. Ship normal changes with Conventional Commit messages.
2. When ready to release, update `VERSION`.
3. Commit with `chore(release): vX.Y.Z`.
4. Run `git tag -a vX.Y.Z -m "vX.Y.Z"`.
5. Push with `git push origin main --follow-tags`.

## For future CLI sessions

- Preserve `VERSION` as the single application version source.
- If code or templates expose the version, read it from `App\Service\AppVersion`.
- Prefer docs updates in `docs/versioning.md` when versioning workflow changes.
