<?php

declare(strict_types=1);

namespace FancyGit\GitHub\Tests;

use FancyGit\GitHub\GitHubProvider;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use Http\Discovery\ClassDiscovery;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Http\Discovery\Strategy\DiscoveryStrategy;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The HTTP stack `GitHubProvider::withToken()` is actually built on.
 *
 * knplabs/github-api finds its PSR-17 factories (request, stream, URI) and its
 * PSR-18 client through php-http/discovery at runtime, so what the adapter
 * sends is decided by what happens to be installed, not by anything this
 * package's code says. These tests pin that: requests go out as guzzlehttp/psr7
 * objects, built by guzzlehttp/psr7's own `HttpFactory`.
 *
 * Only the PSR-18 client is replaced, so nothing leaves the machine. The
 * factories are left to discovery exactly as production leaves them — they are
 * the thing under test.
 */
final class HttpStackTest extends TestCase
{
    private const REF = ['provider' => 'github', 'owner' => 'acme', 'name' => 'app'];

    /** @var array<int,string> */
    private array $strategies = [];

    protected function setUp(): void
    {
        $this->strategies = [...ClassDiscovery::getStrategies()];
        RecordingHttpClient::reset();
        Psr18ClientDiscovery::prependStrategy(RecordingClientStrategy::class);
    }

    protected function tearDown(): void
    {
        ClassDiscovery::setStrategies($this->strategies);
    }

    public function test_every_psr17_factory_the_client_discovers_is_guzzle_psr7s_own(): void
    {
        // knplabs' Builder asks for a request and a stream factory; its Client
        // asks for a URI factory. All three must resolve without any bridge.
        self::assertInstanceOf(HttpFactory::class, Psr17FactoryDiscovery::findRequestFactory());
        self::assertInstanceOf(HttpFactory::class, Psr17FactoryDiscovery::findStreamFactory());
        self::assertInstanceOf(HttpFactory::class, Psr17FactoryDiscovery::findUriFactory());
    }

    public function test_the_psr17_bridge_is_not_installed(): void
    {
        // The control for the rest of this file. Every other test here passes
        // whether or not http-interop/http-factory-guzzle is installed, because
        // discovery prefers guzzlehttp/psr7's HttpFactory over it — so without
        // this, a green run could not say which configuration it proved.
        self::assertFalse(
            class_exists('Http\Factory\Guzzle\RequestFactory'),
            'http-interop/http-factory-guzzle is installed again; the adapter does not need it.',
        );
    }

    public function test_a_read_goes_out_authenticated_to_api_github_com(): void
    {
        RecordingHttpClient::respondWith([
            'id' => 42,
            'html_url' => 'https://github.com/acme/app',
            'default_branch' => 'main',
            'private' => false,
            'description' => 'An app',
        ]);

        $repository = GitHubProvider::withToken('secret')->repository(self::REF);

        $request = RecordingHttpClient::only($this);
        self::assertInstanceOf(Request::class, $request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://api.github.com/repos/acme/app', (string) $request->getUri());
        self::assertSame('token secret', $request->getHeaderLine('Authorization'));
        self::assertSame([
            'provider' => 'github',
            'owner' => 'acme',
            'name' => 'app',
            'id' => '42',
            'webUrl' => 'https://github.com/acme/app',
            'defaultBranch' => 'main',
            'private' => false,
            'description' => 'An app',
        ], $repository);
    }

    /**
     * GitHub Enterprise, through the factory a consumer is told to use.
     *
     * `withToken()` called knplabs' `Client::setEnterpriseUrl()`, which is
     * PRIVATE. From outside the class that lands in `Client::__call()`, which
     * treats the name as an API accessor and throws — so every Enterprise base
     * URL failed at construction, while the github.com default never reached
     * that line and kept every other test green.
     *
     * Both spellings are accepted: a bare host, and the Octokit-style base URL
     * (`…/api/v3`) the TypeScript twin takes.
     *
     * @return array<string,array{0:string}>
     */
    public static function enterpriseBaseUrls(): array
    {
        return [
            'bare host' => ['https://ghe.example.com'],
            'Octokit-style base URL' => ['https://ghe.example.com/api/v3'],
            'trailing slash' => ['https://ghe.example.com/'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('enterpriseBaseUrls')]
    public function test_a_github_enterprise_base_url_reaches_that_host(string $baseUrl): void
    {
        RecordingHttpClient::respondWith([
            'id' => 42,
            'html_url' => 'https://ghe.example.com/acme/app',
            'default_branch' => 'main',
            'private' => true,
        ]);

        $provider = GitHubProvider::withToken('secret', $baseUrl);
        $provider->repository(self::REF);

        $request = RecordingHttpClient::only($this);
        self::assertSame('https://ghe.example.com/api/v3/repos/acme/app', (string) $request->getUri());
        self::assertSame('token secret', $request->getHeaderLine('Authorization'));
        self::assertSame(
            ['provider' => 'github', 'owner' => 'acme', 'name' => 'app', 'baseUrl' => rtrim($baseUrl, '/')],
            $provider->identify(['name' => 'origin', 'fetchUrl' => 'git@ghe.example.com:acme/app.git']),
        );
    }

    public function test_a_write_sends_its_json_body_through_guzzle_psr7s_stream_factory(): void
    {
        RecordingHttpClient::respondWith([
            'id' => 100,
            'number' => 7,
            'title' => 'Broken',
            'state' => 'open',
            'html_url' => 'https://github.com/acme/app/issues/7',
            'user' => ['login' => 'ada'],
            'labels' => [['name' => 'bug']],
            'assignees' => [],
        ], 201);

        $issue = GitHubProvider::withToken('secret')->createIssue(self::REF, ['title' => 'Broken', 'labels' => ['bug']]);

        $request = RecordingHttpClient::only($this);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.github.com/repos/acme/app/issues', (string) $request->getUri());
        self::assertInstanceOf(Stream::class, $request->getBody());
        self::assertSame(['title' => 'Broken', 'labels' => ['bug']], json_decode((string) $request->getBody(), true));
        self::assertSame(7, $issue['number']);
        self::assertSame(['bug'], $issue['labels']);
    }
}

/**
 * A PSR-18 client that records what it is sent and answers from a script.
 *
 * Static because discovery instantiates the client itself; the test cannot
 * hand it an instance.
 */
final class RecordingHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public static array $requests = [];

    private static ?ResponseInterface $response = null;

    public static function reset(): void
    {
        self::$requests = [];
        self::$response = null;
    }

    /** @param array<string,mixed> $json */
    public static function respondWith(array $json, int $status = 200): void
    {
        self::$response = new Response($status, ['Content-Type' => 'application/json'], json_encode($json, JSON_THROW_ON_ERROR));
    }

    public static function only(TestCase $test): RequestInterface
    {
        $test::assertCount(1, self::$requests, 'Expected exactly one request to reach the HTTP client.');

        return self::$requests[0];
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        self::$requests[] = $request;

        return self::$response ?? new Response(500);
    }
}

final class RecordingClientStrategy implements DiscoveryStrategy
{
    public static function getCandidates($type)
    {
        return $type === ClientInterface::class
            ? [['class' => RecordingHttpClient::class, 'condition' => RecordingHttpClient::class]]
            : [];
    }
}
