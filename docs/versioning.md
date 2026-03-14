# Versioning strategy

This workspace already uses release-style commits such as `v0.1.2`. The missing pieces are a stable source of truth, Git tags, and GitHub Releases. For this project, the source of truth is now the root `VERSION` file.

## Recommended standard for all projects in `../../`

Use Semantic Versioning:

- `MAJOR`: breaking changes
- `MINOR`: backwards-compatible features
- `PATCH`: backwards-compatible fixes

For each repository:

1. Keep a root `VERSION` file with the current release number, for example `0.1.2`.
2. Use Conventional Commits for day-to-day work:
   - `feat:`
   - `fix:`
   - `chore:`
   - `docs:`
   - `refactor:`
   - `test:`
3. When you cut a release:
   - update `VERSION`
   - commit with `chore(release): vX.Y.Z`
   - create an annotated tag `vX.Y.Z`
   - push the branch and the tag
4. Let GitHub create a Release from the tag with generated notes.

This keeps version history clear without forcing every normal commit to carry version numbers.

## Why this is better than version-only commits

- Git tags are the proper release boundary in Git.
- GitHub Releases attach notes and downloadable artifacts to those tags.
- A `VERSION` file gives the application a dependable runtime version.
- Conventional Commits make the history easier to scan between releases.

## GitHub features worth using

### 1. Annotated tags

Use tags for every release:

```bash
git tag -a v0.1.3 -m "v0.1.3"
git push origin main --follow-tags
```

### 2. GitHub Releases

This repository now includes a tag-based release workflow. When you push `v*.*.*`, GitHub can generate release notes automatically.

### 3. Protected `main`

For active repos, protect `main` and require pull requests when practical. That makes release points more deliberate.

### 4. Compare links and release notes

GitHub Releases automatically show what changed since the previous tag, which is much more useful than relying on commit names alone.

## Project-specific notes for `edfolio-blog-v2`

- `VERSION` is the release source of truth.
- Symfony reads `VERSION` through `App\Service\AppVersion`.
- Twig exposes `app_version` and `app_release_tag`.
- The public blog footer and admin footer display the current release tag.

## Suggested rollout in sibling repos

- Laravel apps: add `VERSION`, expose it via config/service provider, and optionally show it in admin/footer areas.
- Symfony apps: use the same pattern as this repo.
- Static or small utility repos: keep `VERSION`, tags, and GitHub Releases even if the app itself does not display the version.
- Libraries or packages: also mirror the version into ecosystem-specific metadata only when the package manager genuinely needs it.

## Release checklist

1. Confirm `main` is green.
2. Review commits since the last tag.
3. Decide whether the release is major, minor, or patch.
4. Update `VERSION`.
5. Commit the release bump with `chore(release): vX.Y.Z`.
6. Tag the release with `vX.Y.Z`.
7. Push branch and tags.
8. Verify the GitHub Release was created.
