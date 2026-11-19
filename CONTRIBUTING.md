# Contributing to particle-academy/fancy-git-github

Notes for anyone, human or agent, working ON this package's code. How to USE the
package is in [AGENTS.md](./AGENTS.md); what it is, is in [README.md](./README.md).
`CLAUDE.md` is a symlink to this file, so Claude Code reads these notes when it
works in this repo.

## Layout

- `src/GitHubProvider.php` — the whole adapter (namespace `FancyGit\GitHub`),
  over `knplabs/github-api`.
- `tests/` — the Pest suite. `HttpStackTest.php` pins the HTTP stack that
  knplabs finds through `php-http/discovery` at runtime: what the adapter sends
  is decided by what is installed, not by this package's code, so those tests
  replace only the PSR-18 client and leave the factories to discovery.

## Commands

```bash
composer install
composer test   # pest
```

## Changelog

Update `CHANGELOG.md` (Keep a Changelog) in the same commit as the change.
