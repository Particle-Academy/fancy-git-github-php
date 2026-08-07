# Changelog

All notable changes to `particle-academy/fancy-git-github` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> **Pre-1.0: breaking changes land in MINOR releases.** Read the entry, not the
> version number.

## [Unreleased]

## [0.3.0] — 2026-08-07

### Changed

- **BREAKING — PHP 8.3 is no longer supported.** `require.php` moves from `^8.3` to `^8.4`.

  **What you must do:** on PHP 8.4 or newer, nothing. On 8.3, either upgrade PHP first or stay on the previous release — it keeps working and is unaffected by this.

- CI now tests PHP 8.4 only, instead of a matrix spanning versions this package no longer claims to support. A matrix that tests what the manifest forbids is worse than none — it reports green for a combination nobody can install.

### Why

These are the kit 0.5 platform floors. The suite was split across PHP 8.2 and 8.3 with the framework spanning 11–13, so no package could rely on anything newer than its weakest sibling. Every PHP package in the kit takes the same floors at once, so a consumer never has to resolve a mix.

Pre-1.0, so this lands in a MINOR. **No API changed, nothing was removed, nothing was renamed** — only what the package requires.


## [0.2.0] - 2026-07-31

### Added

- **Implements `FancyGit\Provider\IssueProvider`** — `listIssues`, `getIssue`,
  `createIssue`, `updateIssue`, `commentOnIssue`, against GitHub.com and GitHub
  Enterprise.

  **Nothing breaks.** The interface is optional and additive; existing callers
  are unaffected. Requires `particle-academy/fancy-git` >= 0.2, where the
  interface lives.

  Two behaviours worth knowing, both tested:

  - **Pull requests are excluded from `listIssues`.** A pull request IS an issue
    in GitHub's data model and comes back from the issues endpoint carrying a
    `pull_request` key. Unfiltered, "list the open issues" answers with the open
    PRs mixed in — wrong in a way that reads as right until somebody counts.
    Pagination still follows what GitHub returned, not what survived the filter,
    so a page that happened to be all PRs does not stop the walk early.
  - **`getIssue` refuses a number that is a pull request** rather than returning
    it as an issue. Same numbering space, different thing, and handing one back
    as the other is how a workflow closes the wrong item.

  `updateIssue` sends only the keys you pass. `labels: []` clears them; an absent
  key leaves them alone.

### Changed

- `particle-academy/fancy-git` requirement widened to `>=0.2 <2.0`. **No action
  needed** — widening only adds candidates.

[0.2.0]: https://github.com/Particle-Academy/fancy-git-github-php/releases/tag/v0.2.0
