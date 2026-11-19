<?php

declare(strict_types=1);

namespace FancyGit\GitHub;

use FancyGit\Provider\GitProvider;
use FancyGit\Provider\IssueProvider;
use Github\Client;
use Github\HttpClient\Message\ResponseMediator;

final class GitHubProvider implements GitProvider, IssueProvider
{
    /**
     * @param  GitHubTransport|null  $transport  the adapter's request rules
     *                                           (error codes, retries, the Link
     *                                           cap); installed innermost on the
     *                                           client's plugin stack, replacing
     *                                           any earlier one
     */
    public function __construct(
        private readonly Client $client,
        private readonly string $baseUrl = 'https://api.github.com',
        ?GitHubTransport $transport = null,
    ) {
        // knplabs keeps its plugin builder private, so it is read in knplabs'
        // own class scope. A test double of Client has none, and is left alone.
        $builder = \Closure::bind(static fn (Client $c): mixed => $c->httpClientBuilder, null, Client::class)($client);
        if ($builder instanceof \Github\HttpClient\Builder) {
            $builder->removePlugin(GitHubTransport::class);
            $builder->addPlugin($transport ?? new GitHubTransport);
        }
    }

    /**
     * @param  string  $baseUrl  `https://api.github.com`, or a GitHub Enterprise
     *                           host — bare (`https://ghe.example.com`) or as an
     *                           Octokit-style base URL (`…/api/v3`). knplabs adds
     *                           the `/api/v3` path itself and keeps only the host.
     */
    public static function withToken(string $token, string $baseUrl = 'https://api.github.com'): self
    {
        $baseUrl = rtrim($baseUrl, '/');

        // The Enterprise URL goes in through the constructor. knplabs'
        // setEnterpriseUrl() is private, and calling it from here fell into
        // Client::__call(), which threw for every Enterprise host.
        $client = new Client(null, null, $baseUrl === 'https://api.github.com' ? null : $baseUrl);
        $client->authenticate($token, null, Client::AUTH_ACCESS_TOKEN);

        return new self($client, $baseUrl);
    }

    public function kind(): string
    {
        return 'github';
    }

    public function identify(array $remote): ?array
    {
        if (! preg_match('#^(?:https?://|ssh://git@|git@)([^/:]+)[:/]([^/]+)/([^/]+?)(?:\.git)?$#', $remote['fetchUrl'], $match)) {
            return null;
        }
        $expected = $this->baseUrl === 'https://api.github.com' ? 'github.com' : parse_url($this->baseUrl, PHP_URL_HOST);
        if ($match[1] !== $expected) {
            return null;
        }

        return array_filter([
            'provider' => 'github',
            'owner' => $match[2],
            'name' => $match[3],
            'baseUrl' => $this->baseUrl === 'https://api.github.com' ? null : $this->baseUrl,
        ]);
    }

    public function repository(array $ref): array
    {
        $data = $this->client->api('repo')->show($ref['owner'], $ref['name']);

        return [
            'provider' => 'github',
            'owner' => $ref['owner'],
            'name' => $ref['name'],
            'id' => (string) $data['id'],
            'webUrl' => $data['html_url'],
            'defaultBranch' => $data['default_branch'],
            'private' => $data['private'],
            'description' => $data['description'] ?? null,
        ] + (($ref['baseUrl'] ?? '') !== '' ? ['baseUrl' => $ref['baseUrl']] : []);
    }

    public function listReviews(array $ref, array $query = []): array
    {
        $state = match ($query['state'] ?? null) {
            'merged', 'closed' => 'closed',
            'open', 'draft' => 'open',
            default => 'all',
        };
        $page = (int) ($query['cursor'] ?? 1);
        $perPage = (int) ($query['limit'] ?? 30);
        $items = $this->client->api('pull_request')->all($ref['owner'], $ref['name'], ['state' => $state, 'page' => $page, 'per_page' => $perPage]);

        return ['items' => array_map($this->mapReview(...), $items)] + $this->nextCursor(count($items), $page, $perPage);
    }

    public function getReview(array $ref, int $number): array
    {
        $data = $this->client->api('pull_request')->show($ref['owner'], $ref['name'], $number);

        return $this->mapReview($data) + [
            'body' => $data['body'] ?? null,
            'mergeable' => $data['mergeable'] ?? null,
            'createdAt' => $data['created_at'],
            'updatedAt' => $data['updated_at'],
        ];
    }

    public function createReview(array $ref, array $input): array
    {
        // Sent directly: knplabs' PullRequest::create() refuses a pull request
        // with neither `body` nor `issue` before sending it, but GitHub accepts
        // one with only a title, and the TypeScript twin sends it. Only the
        // keys given are sent, as there.
        $params = ['title' => $input['title']];
        if (isset($input['body'])) {
            $params['body'] = $input['body'];
        }
        $params['head'] = $input['sourceBranch'];
        $params['base'] = $input['targetBranch'];
        if (isset($input['draft'])) {
            $params['draft'] = (bool) $input['draft'];
        }

        $response = $this->client->getHttpClient()->post(
            '/repos/'.rawurlencode($ref['owner']).'/'.rawurlencode($ref['name']).'/pulls',
            ['Content-Type' => 'application/json'],
            json_encode($params, JSON_THROW_ON_ERROR),
        );

        return $this->mapReview(ResponseMediator::getContent($response));
    }

    public function compare(array $ref, string $base, string $head): array
    {
        $data = $this->client->api('repo')->commits()->compare($ref['owner'], $ref['name'], $base, $head);

        return [
            'aheadBy' => $data['ahead_by'],
            'behindBy' => $data['behind_by'],
            'commits' => array_map(static fn (array $commit): array => [
                'id' => $commit['sha'],
                'shortId' => substr($commit['sha'], 0, 7),
                'parents' => array_column($commit['parents'], 'sha'),
                'authorName' => $commit['commit']['author']['name'] ?? 'unknown',
                'authorEmail' => $commit['commit']['author']['email'] ?? '',
                'authoredAt' => $commit['commit']['author']['date'] ?? '',
                'subject' => strtok($commit['commit']['message'], "\n"),
            ], $data['commits']),
            'patchUrl' => $data['html_url'].'.diff',
        ];
    }

    public function checks(array $ref, string $revision): array
    {
        // A commit carries both check runs and commit statuses; a summary that
        // dropped either would report a red commit as green.
        $repo = $this->client->api('repo');
        $runs = $repo->checkRuns()->allForReference($ref['owner'], $ref['name'], $revision);
        $statuses = $repo->statuses()->combined($ref['owner'], $ref['name'], $revision);

        return [
            ...array_map(static fn (array $run): array => array_filter([
                'id' => (string) $run['id'],
                'name' => $run['name'],
                'state' => self::checkState($run['status'] ?? null, $run['conclusion'] ?? null),
                'webUrl' => $run['html_url'] ?? null,
                'startedAt' => $run['started_at'] ?? null,
                'completedAt' => $run['completed_at'] ?? null,
            ], static fn ($v) => $v !== null), $runs['check_runs'] ?? []),
            ...array_map(static fn (array $status): array => array_filter([
                'id' => (string) $status['id'],
                'name' => $status['context'],
                'state' => self::checkState($status['state'] ?? null, $status['state'] ?? null),
                'webUrl' => $status['target_url'] ?? null,
            ], static fn ($v) => $v !== null), $statuses['statuses'] ?? []),
        ];
    }

    /**
     * List issues.
     *
     * GitHub's issues endpoint returns PULL REQUESTS TOO — a pull request is an
     * issue in its data model, and every one arrives carrying a `pull_request`
     * key. Unfiltered, "list the open issues" answers with the open PRs mixed
     * in: wrong in a way that reads as right until somebody counts.
     */
    public function listIssues(array $ref, array $query = []): array
    {
        $page = (int) ($query['cursor'] ?? 1);
        $perPage = (int) ($query['limit'] ?? 30);
        $params = [
            'state' => $query['state'] ?? 'open',
            'page' => $page,
            'per_page' => $perPage,
        ];
        if (! empty($query['labels'])) {
            $params['labels'] = implode(',', $query['labels']);
        }
        if (! empty($query['assignee'])) {
            $params['assignee'] = $query['assignee'];
        }

        $items = $this->client->api('issue')->all($ref['owner'], $ref['name'], $params);
        $issues = array_values(array_filter($items, fn ($item) => ! isset($item['pull_request'])));

        // Paginate on what GitHub returned, not on what survived the filter: a
        // page that was all pull requests still has a next page behind it.
        return ['items' => array_map($this->mapIssue(...), $issues)] + $this->nextCursor(count($items), $page, $perPage);
    }

    /**
     * The next cursor of a page-numbered list. GitHub's `Link` header is the
     * authority when present: no `rel="next"` means there is no next page, even
     * when this page came back full. Only without a Link header at all does the
     * page length decide.
     *
     * @return array{nextCursor?: string}
     */
    private function nextCursor(int $count, int $page, int $perPage): array
    {
        $response = $this->client->getLastResponse();
        if ($response !== null && $response->hasHeader('Link')) {
            $next = GitHubTransport::nextLink($response->getHeaderLine('Link'));
            if ($next === null) {
                return [];
            }
            parse_str((string) parse_url($next, PHP_URL_QUERY), $q);

            return ['nextCursor' => is_string($q['page'] ?? null) && $q['page'] !== '' ? $q['page'] : (string) ($page + 1)];
        }

        return $count === $perPage ? ['nextCursor' => (string) ($page + 1)] : [];
    }

    public function getIssue(array $ref, int $number): array
    {
        $data = $this->client->api('issue')->show($ref['owner'], $ref['name'], $number);

        if (isset($data['pull_request'])) {
            throw new \RuntimeException(
                "#{$number} in {$ref['owner']}/{$ref['name']} is a pull request, not an issue. Use getReview for pull requests."
            );
        }

        return $this->mapIssue($data) + [
            'body' => $data['body'] ?? null,
            'closedAt' => $data['closed_at'] ?? null,
            'commentCount' => $data['comments'] ?? null,
        ];
    }

    public function createIssue(array $ref, array $input): array
    {
        $params = ['title' => $input['title']];
        foreach (['body' => 'body', 'labels' => 'labels', 'assignees' => 'assignees'] as $from => $to) {
            if (! empty($input[$from])) {
                $params[$to] = $input[$from];
            }
        }

        return $this->mapIssue($this->client->api('issue')->create($ref['owner'], $ref['name'], $params));
    }

    /**
     * Update an issue — only the keys present are sent.
     *
     * Echoing the whole issue back would clobber whatever someone else changed
     * between the read and the write, and on a tracker that someone is usually
     * a person mid-conversation.
     */
    public function updateIssue(array $ref, int $number, array $input): array
    {
        $params = [];
        foreach (['title', 'body', 'state', 'labels', 'assignees'] as $key) {
            if (array_key_exists($key, $input)) {
                $params[$key] = $input[$key];
            }
        }

        return $this->mapIssue($this->client->api('issue')->update($ref['owner'], $ref['name'], $number, $params));
    }

    public function commentOnIssue(array $ref, int $number, string $body): array
    {
        $data = $this->client->api('issue')->comments()->create($ref['owner'], $ref['name'], $number, ['body' => $body]);

        return ['id' => (string) $data['id'], 'webUrl' => $data['html_url']];
    }

    /** @return array<string,mixed> */
    private function mapIssue(array $item): array
    {
        return [
            'id' => (string) $item['id'],
            'number' => $item['number'],
            'title' => $item['title'],
            'state' => ($item['state'] ?? 'open') === 'closed' ? 'closed' : 'open',
            'webUrl' => $item['html_url'],
            'author' => $item['user']['login'] ?? 'unknown',
            'labels' => array_values(array_map(
                fn ($label) => is_string($label) ? $label : ($label['name'] ?? ''),
                $item['labels'] ?? [],
            )),
            'assignees' => array_values(array_map(
                fn ($user) => $user['login'] ?? '',
                $item['assignees'] ?? [],
            )),
            'createdAt' => $item['created_at'] ?? '',
            'updatedAt' => $item['updated_at'] ?? '',
        ];
    }


    private function mapReview(array $item): array
    {
        $state = ($item['merged'] ?? false) ? 'merged' : (($item['draft'] ?? false) ? 'draft' : ($item['state'] === 'open' ? 'open' : 'closed'));

        return [
            'id' => (string) $item['id'],
            'number' => $item['number'],
            'title' => $item['title'],
            'state' => $state,
            'webUrl' => $item['html_url'],
            'sourceBranch' => $item['head']['ref'],
            'targetBranch' => $item['base']['ref'],
            'author' => $item['user']['login'] ?? 'unknown',
        ];
    }

    private static function checkState(?string $status, ?string $conclusion): string
    {
        return match (true) {
            in_array($status, ['queued', 'pending'], true) => 'queued',
            $status === 'in_progress' => 'running',
            in_array($conclusion, ['success', 'neutral'], true) => 'passed',
            $conclusion === 'cancelled' => 'cancelled',
            $conclusion === 'skipped' => 'skipped',
            $conclusion !== null => 'failed',
            default => 'unknown',
        };
    }
}
