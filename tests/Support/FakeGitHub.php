<?php

declare(strict_types=1);

namespace FancyGit\GitHub\Tests\Support;

use FancyGit\GitHub\GitHubProvider;
use FancyGit\GitHub\GitHubTransport;
use Github\Client;
use Github\HttpClient\Builder;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A GitHub that answers from a queue and records every request it was sent.
 *
 * It is the PSR-18 client under knplabs' whole plugin stack, so every request
 * passes through the same plugins it would in production. When the queue runs
 * dry it throws, so a test that sends one request more than it expects fails
 * instead of passing quietly.
 */
final class FakeGitHub implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<int> milliseconds the transport asked to sleep */
    public array $slept = [];

    /** @param list<ResponseInterface|\Throwable> $queue */
    public function __construct(private array $queue = []) {}

    /** @param array<mixed> $body */
    public static function json(array $body, int $status = 200, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json; charset=utf-8'] + $headers, json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException('FakeGitHub: no response queued for '.$request->getMethod().' '.$request->getUri());
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function provider(?string $baseUrl = null): GitHubProvider
    {
        $client = new Client(new Builder($this), null, $baseUrl);
        $client->authenticate('tkn', null, Client::AUTH_ACCESS_TOKEN);

        return new GitHubProvider($client, $baseUrl ?? 'https://api.github.com', new GitHubTransport(sleep: function (int $ms): void {
            $this->slept[] = $ms;
        }));
    }

    public function count(): int
    {
        return count($this->requests);
    }

    /** @return array<string,mixed> */
    public function body(int $index = 0): array
    {
        return json_decode((string) $this->requests[$index]->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
