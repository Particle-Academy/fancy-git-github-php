<?php

declare(strict_types=1);

namespace FancyGit\GitHub;

use FancyGit\Provider\GitProvider;
use FancyGit\Provider\IssueProvider;
use Github\Client;

final class GitHubProvider implements GitProvider, IssueProvider
{
    public function __construct(
        private readonly Client $client,
        private readonly string $baseUrl = 'https://api.github.com',
    ) {}

    public static function withToken(string $token, string $baseUrl = 'https://api.github.com'): self
    {
        $client = new Client;
        $client->authenticate($token, null, Client::AUTH_ACCESS_TOKEN);
        if ($baseUrl !== 'https://api.github.com') {
            $client->setEnterpriseUrl(rtrim($baseUrl, '/'));
        }

        return new self($client, rtrim($baseUrl, '/'));
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
        ];
    }

    public function listReviews(array $ref, array $query = []): array
    {
        $state = in_array($query['state'] ?? null, ['open', 'closed'], true) ? $query['state'] : 'all';
        $items = $this->client->api('pull_request')->all($ref['owner'], $ref['name'], ['state' => $state, 'per_page' => $query['limit'] ?? 30]);

        return ['items' => array_map($this->mapReview(...), $items)];
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
        $data = $this->client->api('pull_request')->create($ref['owner'], $ref['name'], [
            'title' => $input['title'],
            'body' => $input['body'] ?? null,
            'head' => $input['sourceBranch'],
            'base' => $input['targetBranch'],
            'draft' => $input['draft'] ?? false,
        ]);

        return $this->mapReview($data);
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
        $runs = $this->client->api('check')->runs()->allForReference($ref['owner'], $ref['name'], $revision);

        return array_map(static fn (array $run): array => [
            'id' => (string) $run['id'],
            'name' => $run['name'],
            'state' => self::checkState($run['status'] ?? null, $run['conclusion'] ?? null),
            'webUrl' => $run['html_url'] ?? null,
            'startedAt' => $run['started_at'] ?? null,
            'completedAt' => $run['completed_at'] ?? null,
        ], $runs['check_runs'] ?? []);
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
        $params = [
            'state' => in_array($query['state'] ?? null, ['open', 'closed'], true) ? $query['state'] : 'open',
            'per_page' => $query['limit'] ?? 30,
        ];
        if (! empty($query['labels'])) {
            $params['labels'] = implode(',', $query['labels']);
        }
        if (! empty($query['assignee'])) {
            $params['assignee'] = $query['assignee'];
        }

        $items = $this->client->api('issue')->all($ref['owner'], $ref['name'], $params);
        $issues = array_values(array_filter($items, fn ($item) => ! isset($item['pull_request'])));

        return ['items' => array_map($this->mapIssue(...), $issues)];
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
