<?php

declare(strict_types=1);

namespace FancyGit\GitHub\Tests;

use FancyGit\GitHub\GitHubProvider;
use FancyGit\Provider\IssueProvider;
use Github\Api\Issue as IssueApi;
use Github\Client;
use PHPUnit\Framework\TestCase;

/**
 * The issue methods, against a recording API double.
 *
 * These mirror the TypeScript adapter's tests case for case. The two adapters
 * are a matched pair, and the failures worth catching here are the ones that
 * differ between them silently — a filter applied on one runtime and not the
 * other looks like working software on both.
 */
abstract class IssuesCase extends TestCase
{
    protected const REF = ['provider' => 'github', 'owner' => 'acme', 'name' => 'app'];

    /** @param array<string,mixed> $over */
    protected function issue(array $over = []): array
    {
        return $over + [
            'id' => 100,
            'number' => 7,
            'title' => 'Broken',
            'state' => 'open',
            'html_url' => 'https://github.com/acme/app/issues/7',
            'user' => ['login' => 'ada'],
            'labels' => [['name' => 'bug']],
            'assignees' => [['login' => 'grace']],
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-02T00:00:00Z',
        ];
    }

    /**
     * A Client whose issue API is a mock, recording what it was called with.
     *
     * Mocks of the REAL knplabs classes rather than a hand-rolled double: the
     * `Client::api()` return type is `Github\Api\AbstractApi`, so a stand-in
     * that does not extend it cannot be substituted at all.
     */
    protected function client(array $returns, ?object &$spy = null): Client
    {
        // A recorder OBJECT, not a by-ref array. Rebinding a by-reference
        // parameter with `=&` severs it from the caller's variable, so the
        // caller kept seeing the null it passed in while the closures happily
        // filled a local — three tests asserting against nothing.
        $spy = new class
        {
            /** @var list<array{0:string,1:mixed}> */
            public array $calls = [];
        };
        $recorder = $spy;

        $issues = $this->createMock(IssueApi::class);
        $issues->method('all')->willReturnCallback(function ($o, $r, $p = []) use ($recorder, $returns) {
            $recorder->calls[] = ['all', $p];

            return $returns['all'] ?? [];
        });
        $issues->method('show')->willReturnCallback(function ($o, $r, $n) use ($recorder, $returns) {
            $recorder->calls[] = ['show', $n];

            return $returns['show'] ?? [];
        });
        $issues->method('create')->willReturnCallback(function ($o, $r, $p) use ($recorder, $returns) {
            $recorder->calls[] = ['create', $p];

            return $returns['create'] ?? [];
        });
        $issues->method('update')->willReturnCallback(function ($o, $r, $n, $p) use ($recorder, $returns) {
            $recorder->calls[] = ['update', $p];

            return $returns['update'] ?? [];
        });

        $client = $this->createMock(Client::class);
        $client->method('api')->willReturn($issues);

        return $client;
    }
}

uses(IssuesCase::class);

test('the provider declares the issue capability', function () {
    // Callers check before reaching for these, because a host without a
    // tracker is still a perfectly good GitProvider.
    self::assertInstanceOf(IssueProvider::class, new GitHubProvider(new Client));
});

test('it excludes pull requests from the issue list', function () {
    // A pull request IS an issue in GitHub's data model and comes back from
    // the issues endpoint carrying a `pull_request` key. Unfiltered, "list
    // the open issues" answers with the open PRs mixed in — wrong in a way
    // that reads as right until somebody counts.
    $client = $this->client(['all' => [
        $this->issue(['number' => 7]),
        $this->issue(['number' => 8, 'pull_request' => ['url' => '…']]),
        $this->issue(['number' => 9]),
    ]]);

    $page = (new GitHubProvider($client))->listIssues(self::REF);

    self::assertSame([7, 9], array_column($page['items'], 'number'));
});

test('it normalizes the issue shape', function () {
    $client = $this->client(['all' => [$this->issue()]]);
    $page = (new GitHubProvider($client))->listIssues(self::REF);

    self::assertSame([
        'id' => '100',
        'number' => 7,
        'title' => 'Broken',
        'state' => 'open',
        'webUrl' => 'https://github.com/acme/app/issues/7',
        'author' => 'ada',
        'labels' => ['bug'],
        'assignees' => ['grace'],
        'createdAt' => '2026-01-01T00:00:00Z',
        'updatedAt' => '2026-01-02T00:00:00Z',
    ], $page['items'][0]);
});

test('it refuses a number that is a pull request', function () {
    // Same numbering space, different thing. Handing back the PR as though
    // it were an issue is how a workflow closes the wrong item.
    $client = $this->client(['show' => $this->issue(['pull_request' => ['url' => '…']])]);

    $this->expectExceptionMessageMatches('/is a pull request, not an issue/');

    (new GitHubProvider($client))->getIssue(self::REF, 7);
});

test('update sends only the fields given', function () {
    // A partial update. Echoing the whole issue back would clobber whatever
    // someone else changed between the read and the write.
    $client = $this->client(['update' => $this->issue(['state' => 'closed'])], $spy);

    (new GitHubProvider($client))->updateIssue(self::REF, 7, ['state' => 'closed']);

    [$method, $params] = $spy->calls[0];
    self::assertSame('update', $method);
    self::assertSame(['state' => 'closed'], $params);
});

test('update can clear labels', function () {
    // `labels: []` means "remove them all" and has to survive; only an
    // ABSENT key means "leave alone".
    $client = $this->client(['update' => $this->issue(['labels' => []])], $spy);

    (new GitHubProvider($client))->updateIssue(self::REF, 7, ['labels' => []]);

    self::assertSame(['labels' => []], $spy->calls[0][1]);
});

test('create omits empty optionals', function () {
    $client = $this->client(['create' => $this->issue()], $spy);

    (new GitHubProvider($client))->createIssue(self::REF, ['title' => 'Broken']);

    self::assertSame(['title' => 'Broken'], $spy->calls[0][1]);
});
