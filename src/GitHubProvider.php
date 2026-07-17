<?php

declare(strict_types=1);

namespace FancyGit\GitHub;

use FancyGit\Provider\GitProvider;
use Github\Client;

final class GitHubProvider implements GitProvider
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
