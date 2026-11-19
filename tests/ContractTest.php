<?php

declare(strict_types=1);

use FancyGit\Error\GitErrorCode;
use FancyGit\Error\GitException;
use FancyGit\GitHub\GitHubTransport;
use FancyGit\GitHub\Tests\Support\FakeGitHub;
use GuzzleHttp\Psr7\Request;
use Http\Client\Exception\NetworkException;

/*
| What the fancy-labs git-adapter test found the PHP twin doing differently from
| the TypeScript one (@particle-academy/fancy-git-github), each held to the
| TypeScript behaviour: Link pagination, fancy-git error codes, a bounded GET
| retry on 5xx, a checks() that runs, a repository() that keeps its baseUrl,
| and a createReview() without a body that is sent rather than refused.
*/

const GH_REF = ['provider' => 'github', 'owner' => 'acme', 'name' => 'app'];
const GH_API = 'https://api.github.com';

function ghRepo(): array
{
    return ['id' => 42, 'html_url' => 'https://github.com/acme/app', 'default_branch' => 'main', 'private' => false, 'description' => null];
}

function ghPull(int $n): array
{
    return ['id' => 1000 + $n, 'number' => $n, 'title' => "PR {$n}", 'state' => 'open', 'html_url' => "https://github.com/acme/app/pull/{$n}",
        'head' => ['ref' => "feature-{$n}"], 'base' => ['ref' => 'main'], 'user' => ['login' => 'mona']];
}

function ghCaught(callable $call): GitException
{
    try {
        $call();
    } catch (GitException $e) {
        return $e;
    }
    throw new RuntimeException('Expected a GitException.');
}

// ---- error codes

test('github failures carry the fancy-git code', function (int $status, array $headers, GitErrorCode $code) {
    $github = new FakeGitHub([FakeGitHub::json(['message' => 'nope'], $status, $headers)]);

    $e = ghCaught(fn () => $github->provider()->repository(GH_REF));

    expect($e->errorCode)->toBe($code)
        ->and($e->getCode())->toBe($status)
        ->and($github->count())->toBe(1);
})->with([
    '400' => [400, [], GitErrorCode::InvalidArgument],
    '401' => [401, [], GitErrorCode::Auth],
    '403' => [403, ['X-RateLimit-Remaining' => '4999'], GitErrorCode::Auth],
    '404' => [404, [], GitErrorCode::NotFound],
    '405' => [405, [], GitErrorCode::Unsupported],
    '409' => [409, [], GitErrorCode::Conflict],
    '422' => [422, [], GitErrorCode::InvalidArgument],
    '429 long wait' => [429, ['Retry-After' => '120'], GitErrorCode::RateLimited],
    '403 quota spent' => [403, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) (time() + 3600)], GitErrorCode::RateLimited],
]);

test('a short rate limit is waited out once, as the TypeScript twin does', function () {
    $github = new FakeGitHub([FakeGitHub::json(['message' => 'slow down'], 429, ['Retry-After' => '2']), FakeGitHub::json(ghRepo())]);

    $repository = $github->provider()->repository(GH_REF);

    expect($repository['id'])->toBe('42')
        ->and($github->count())->toBe(2)
        ->and($github->slept)->toBe([2000]);
});

test('a network failure is a git exception with code unknown', function () {
    $failure = new NetworkException('cURL error 7', new Request('GET', GH_API.'/repos/acme/app'));
    $github = new FakeGitHub(array_fill(0, 1 + GitHubTransport::RETRIES, $failure));

    $e = ghCaught(fn () => $github->provider()->repository(GH_REF));

    expect($e->errorCode)->toBe(GitErrorCode::Unknown)
        ->and($github->count())->toBe(1 + GitHubTransport::RETRIES);
});

// ---- retry

test('a GET that answered 5xx is retried', function (int $status) {
    $github = new FakeGitHub([FakeGitHub::json(['message' => 'down'], $status), FakeGitHub::json(ghRepo())]);

    $repository = $github->provider()->repository(GH_REF);

    expect($repository['id'])->toBe('42')
        ->and($github->count())->toBe(2)
        ->and($github->slept)->toBe([500]);
})->with([500, 502, 503, 504]);

test('a GET gives up after a bounded number of retries, with the last 5xx', function () {
    $github = new FakeGitHub(array_map(static fn () => FakeGitHub::json(['message' => 'down'], 503), range(1, 10)));

    $e = ghCaught(fn () => $github->provider()->repository(GH_REF));

    expect($github->count())->toBe(1 + GitHubTransport::RETRIES)
        ->and($e->errorCode)->toBe(GitErrorCode::Unknown)
        ->and($e->getCode())->toBe(503);
});

test('a POST that answered 5xx is never replayed', function () {
    $github = new FakeGitHub([FakeGitHub::json(['message' => 'Bad Gateway'], 502), FakeGitHub::json(ghPull(7), 201)]);

    $e = ghCaught(fn () => $github->provider()->createReview(GH_REF, ['title' => 'T', 'sourceBranch' => 'f', 'targetBranch' => 'main']));

    expect($github->count())->toBe(1)
        ->and($e->getCode())->toBe(502);
});

// ---- pagination

test('listReviews walks by the Link header and stops where it has no next', function () {
    $github = new FakeGitHub([
        FakeGitHub::json([ghPull(1), ghPull(2)], 200, ['Link' => '<'.GH_API.'/repos/acme/app/pulls?state=all&page=2&per_page=2>; rel="next", <'.GH_API.'/repos/acme/app/pulls?state=all&page=2&per_page=2>; rel="last"']),
        FakeGitHub::json([ghPull(3), ghPull(4)], 200, ['Link' => '<'.GH_API.'/repos/acme/app/pulls?state=all&page=1&per_page=2>; rel="prev"']),
    ]);
    $provider = $github->provider();

    $first = $provider->listReviews(GH_REF, ['limit' => 2]);
    $second = $provider->listReviews(GH_REF, ['limit' => 2, 'cursor' => $first['nextCursor']]);

    expect(array_column($first['items'], 'number'))->toBe([1, 2])
        ->and($first['nextCursor'])->toBe('2')
        ->and(array_column($second['items'], 'number'))->toBe([3, 4])
        ->and($second)->not->toHaveKey('nextCursor');
    parse_str($github->requests[0]->getUri()->getQuery(), $q0);
    parse_str($github->requests[1]->getUri()->getQuery(), $q1);
    expect($q0)->toBe(['state' => 'all', 'page' => '1', 'per_page' => '2'])
        ->and($q1['page'])->toBe('2');
});

test('without a Link header a full page offers the next one', function () {
    $github = new FakeGitHub([FakeGitHub::json([ghPull(1), ghPull(2)])]);

    expect($github->provider()->listReviews(GH_REF, ['limit' => 2])['nextCursor'])->toBe('2');
});

test('listIssues follows the Link past a page of pull requests', function () {
    $issue = static fn (int $n, bool $pr = false): array => ['id' => 2000 + $n, 'number' => $n, 'title' => "I {$n}", 'state' => 'open',
        'html_url' => "https://github.com/acme/app/issues/{$n}", 'user' => ['login' => 'mona'], 'labels' => [], 'assignees' => []] + ($pr ? ['pull_request' => []] : []);
    $github = new FakeGitHub([
        FakeGitHub::json([$issue(11), $issue(12, true)], 200, ['Link' => '<'.GH_API.'/repos/acme/app/issues?page=2&per_page=2>; rel="next"']),
    ]);

    $page = $github->provider()->listIssues(GH_REF, ['limit' => 2]);

    expect(array_column($page['items'], 'number'))->toBe([11])
        ->and($page['nextCursor'])->toBe('2');
});

test('a Link header over the cap throws instead of ending the walk', function () {
    $github = new FakeGitHub([
        FakeGitHub::json([ghPull(1), ghPull(2)], 200, ['Link' => '<'.GH_API.'/repos/acme/app/pulls?page=2&pad='.str_repeat('x', 9000).'>; rel="next"']),
    ]);

    $e = ghCaught(fn () => $github->provider()->listReviews(GH_REF, ['limit' => 2]));

    expect($e->errorCode)->toBe(GitErrorCode::Unknown)
        ->and($e->getMessage())->toContain('Link header');
});

test('a hostile 100,000-character Link header throws quickly', function () {
    $github = new FakeGitHub([FakeGitHub::json([], 200, ['Link' => str_repeat('<', 100000)])]);

    $t = hrtime(true);
    ghCaught(fn () => $github->provider()->listReviews(GH_REF, ['limit' => 2]));

    expect((hrtime(true) - $t) / 1e6)->toBeLessThan(200);
});

// ---- operations

test('checks combines check runs and commit statuses', function () {
    $sha = str_repeat('a', 40);
    $github = new FakeGitHub([
        FakeGitHub::json(['total_count' => 1, 'check_runs' => [['id' => 11, 'name' => 'build', 'status' => 'completed', 'conclusion' => 'success',
            'html_url' => 'https://github.com/acme/app/runs/11', 'started_at' => '2026-11-01T09:01:00Z', 'completed_at' => '2026-11-01T09:05:00Z']]]),
        FakeGitHub::json(['state' => 'failure', 'statuses' => [['id' => 21, 'context' => 'ci/lint', 'state' => 'failure', 'target_url' => 'https://ci.example.test/lint/21']]]),
    ]);

    $checks = $github->provider()->checks(GH_REF, $sha);

    expect($checks)->toBe([
        ['id' => '11', 'name' => 'build', 'state' => 'passed', 'webUrl' => 'https://github.com/acme/app/runs/11', 'startedAt' => '2026-11-01T09:01:00Z', 'completedAt' => '2026-11-01T09:05:00Z'],
        ['id' => '21', 'name' => 'ci/lint', 'state' => 'failed', 'webUrl' => 'https://ci.example.test/lint/21'],
    ])
        ->and($github->requests[0]->getUri()->getPath())->toBe("/repos/acme/app/commits/{$sha}/check-runs")
        ->and($github->requests[1]->getUri()->getPath())->toBe("/repos/acme/app/commits/{$sha}/status");
});

test('repository keeps the base url the ref was given', function () {
    $github = new FakeGitHub([FakeGitHub::json(ghRepo())]);

    $repository = $github->provider('https://ghe.example.test/api/v3')->repository(GH_REF + ['baseUrl' => 'https://ghe.example.test/api/v3']);

    expect($repository['baseUrl'] ?? null)->toBe('https://ghe.example.test/api/v3');
});

test('createReview without a body is sent, not refused', function () {
    $github = new FakeGitHub([FakeGitHub::json(ghPull(7), 201)]);

    $review = $github->provider()->createReview(GH_REF, ['title' => 'Add', 'sourceBranch' => 'feature-7', 'targetBranch' => 'main']);

    expect($review['number'])->toBe(7)
        ->and($github->count())->toBe(1)
        ->and($github->requests[0]->getMethod())->toBe('POST')
        ->and($github->requests[0]->getUri()->getPath())->toBe('/repos/acme/app/pulls')
        ->and($github->body())->toBe(['title' => 'Add', 'head' => 'feature-7', 'base' => 'main']);
});

test('createReview sends the body and draft it is given', function () {
    $github = new FakeGitHub([FakeGitHub::json(ghPull(7), 201)]);

    $github->provider()->createReview(GH_REF, ['title' => 'Add', 'body' => 'Why.', 'sourceBranch' => 'feature-7', 'targetBranch' => 'main', 'draft' => false]);

    expect($github->body())->toBe(['title' => 'Add', 'body' => 'Why.', 'head' => 'feature-7', 'base' => 'main', 'draft' => false]);
});
