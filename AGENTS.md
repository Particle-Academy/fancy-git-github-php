# Using particle-academy/fancy-git-github — guide for agents

How an agent installs and uses this package in a consumer app. What the package
is, is in [README.md](./README.md). Notes for working ON this repo's code are in
[CONTRIBUTING.md](./CONTRIBUTING.md).

## Install

```bash
composer require particle-academy/fancy-git-github
```

- Requires PHP `^8.4`, `particle-academy/fancy-git` (`>=0.2 <2.0`),
  `knplabs/github-api` `^3.16` and `guzzlehttp/guzzle` `^7.15.1`; Composer pulls
  them in.
- `knplabs/github-api` finds its HTTP client through `php-http/discovery`, a
  Composer plugin. Allow it (`config.allow-plugins."php-http/discovery": true`)
  when Composer asks.

## Find it on the Fancy MCP server

The catalog server is `https://ui.particle.academy/mcp` (streamable HTTP, no API
key; MCP id `fancy-ui`). This package is the registry entry
`fancy-git-github-php`. Server-side packages are found with
`search-backend-packages` (stack `php`); `install-instructions` gives the exact
commands for the slug.

## API essentials

```php
use FancyGit\GitHub\GitHubProvider;
use FancyGit\Provider\ProviderRegistry;

$github = GitHubProvider::withToken($token);
$enterprise = GitHubProvider::withToken($token, 'https://ghe.example.com');
$providers = (new ProviderRegistry)->register($github);
```

- `GitHubProvider::withToken($token, $baseUrl)` builds the knplabs client. The
  base URL is `https://api.github.com` by default, or a GitHub Enterprise host,
  bare or as an Octokit-style `.../api/v3` URL. To bring your own
  `Github\Client`, use the constructor: `new GitHubProvider($client, $baseUrl)`.
- `GitHubProvider` implements fancy-git's `GitProvider` (`kind()` is
  `'github'`): `identify`, `repository`, `listReviews`, `getReview`,
  `createReview`, `compare`, `checks`. It also implements `IssueProvider`:
  `listIssues`, `getIssue`, `createIssue`, `updateIssue`, `commentOnIssue`.

## Traps

- **`listIssues` returns one page and no cursor.** It asks GitHub for up to
  `limit` items (default 30) and drops the pull requests GitHub mixes into its
  issues endpoint, so the result can be shorter than `limit`.
