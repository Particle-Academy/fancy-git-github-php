<?php

declare(strict_types=1);

namespace FancyGit\GitHub;

use FancyGit\Error\GitErrorCode;
use FancyGit\Error\GitException;
use Http\Client\Common\Plugin;
use Http\Client\Promise\HttpFulfilledPromise;
use Http\Promise\Promise;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The adapter's own rules for every request knplabs sends, as one HTTP plugin
 * sitting innermost in knplabs' stack (so it sees GitHub's raw responses before
 * knplabs' own exception thrower does). The same rules, in the same words, as
 * `GitHubClient.request` in the TypeScript twin:
 *
 * - **Errors carry the fancy-git code.** Every HTTP failure becomes a
 *   `GitException`: 401/403 `auth`, 404 `not_found`, 400/422
 *   `invalid_argument`, 405 `unsupported`, 409 `conflict`, any rate limit
 *   `rate_limited`, otherwise `unknown`; the HTTP status is its code.
 * - **A rate limit** (429, or 403 with no quota left, a Retry-After or a
 *   secondary-limit message) is waited out ONCE when the wait is at most
 *   {@see self::MAX_RETRY_AFTER} seconds, otherwise thrown.
 * - **A 5xx or a network failure is retried** up to {@see self::RETRIES} times
 *   with backoff, for idempotent methods only. A POST is never replayed: a
 *   gateway error can arrive after GitHub already created the thing.
 * - **A `Link` header over {@see self::MAX_LINK_HEADER_LENGTH} is refused**,
 *   not ignored: ignoring it would end a walk early with a list that reads as
 *   complete.
 */
final class GitHubTransport implements Plugin
{
    public const RETRIES = 3;

    /** Longest rate-limit wait, in seconds, that is waited out rather than thrown. */
    public const MAX_RETRY_AFTER = 60;

    /** Longest pagination `Link` header parsed. GitHub's are a few hundred bytes. */
    public const MAX_LINK_HEADER_LENGTH = 8 * 1024;

    private const IDEMPOTENT = ['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE'];

    private const RETRYABLE_STATUS = [500, 502, 503, 504];

    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    /** @param  (callable(int): void)|null  $sleep  milliseconds; injectable for tests */
    public function __construct(?callable $sleep = null)
    {
        $this->sleep = $sleep !== null ? $sleep(...) : static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    public function handleRequest(RequestInterface $request, callable $next, callable $first): Promise
    {
        $method = strtoupper($request->getMethod());
        $url = (string) $request->getUri();
        $idempotent = in_array($method, self::IDEMPOTENT, true);
        $serverRetries = 0;
        $rateLimitRetried = false;

        while (true) {
            if ($request->getBody()->isSeekable()) {
                $request->getBody()->rewind();
            }
            try {
                $response = $next($request)->wait();
            } catch (ClientExceptionInterface $e) {
                if ($idempotent && $serverRetries < self::RETRIES) {
                    ($this->sleep)(self::backoff($serverRetries++));

                    continue;
                }

                throw new GitException(GitErrorCode::Unknown, "GitHub API 0 {$method} {$url}: network error: {$e->getMessage()}");
            }

            $status = $response->getStatusCode();
            if ($status < 400) {
                self::refuseOversizedLink($response);

                return new HttpFulfilledPromise($response);
            }

            $payload = json_decode((string) $response->getBody(), true);
            $message = is_array($payload) && is_string($payload['message'] ?? null) ? $payload['message'] : null;

            $wait = self::rateLimitWait($response, $message);
            if ($wait !== false) {
                if (! $rateLimitRetried && $wait !== null && $wait <= self::MAX_RETRY_AFTER) {
                    $rateLimitRetried = true;
                    ($this->sleep)($wait * 1000);

                    continue;
                }

                throw self::error($method, $url, $response, $message, true, $wait);
            }

            if ($idempotent && in_array($status, self::RETRYABLE_STATUS, true) && $serverRetries < self::RETRIES) {
                $hinted = self::intHeader($response, 'Retry-After');
                ($this->sleep)($hinted !== null ? $hinted * 1000 : self::backoff($serverRetries));
                $serverRetries++;

                continue;
            }

            throw self::error($method, $url, $response, $message, false, null);
        }
    }

    /** The fancy-git contract code for a GitHub HTTP status, the same table every adapter uses. */
    public static function errorCode(int $status, bool $rateLimited = false): GitErrorCode
    {
        return match (true) {
            $rateLimited || $status === 429 => GitErrorCode::RateLimited,
            $status === 401, $status === 403 => GitErrorCode::Auth,
            $status === 404 => GitErrorCode::NotFound,
            $status === 405 => GitErrorCode::Unsupported,
            $status === 409 => GitErrorCode::Conflict,
            $status === 400, $status === 422 => GitErrorCode::InvalidArgument,
            default => GitErrorCode::Unknown,
        };
    }

    /**
     * The `rel="next"` target of a `Link` header, or null. Linear: strpos /
     * substr only. The header comes from whatever server the base URL points at.
     */
    public static function nextLink(string $header): ?string
    {
        if ($header === '') {
            return null;
        }
        if (strlen($header) > self::MAX_LINK_HEADER_LENGTH) {
            throw self::oversizedLink();
        }

        foreach (explode(',', $header) as $part) {
            $open = strpos($part, '<');
            if ($open === false) {
                continue;
            }
            $close = strpos($part, '>', $open + 1);
            if ($close === false || $close <= $open + 1) {
                continue;
            }
            $target = substr($part, $open + 1, $close - $open - 1);
            foreach (explode(';', substr($part, $close + 1)) as $param) {
                $eq = strpos($param, '=');
                if ($eq === false || strtolower(trim(substr($param, 0, $eq))) !== 'rel') {
                    continue;
                }
                $value = trim(substr($param, $eq + 1));
                $end = strpos($value, '"', 1);
                if (! str_starts_with($value, '"') || $end === false || $end <= 1) {
                    break;
                }
                if (in_array('next', preg_split('/\s+/', substr($value, 1, $end - 1)) ?: [], true)) {
                    return $target;
                }
                break;
            }
        }

        return null;
    }

    private static function refuseOversizedLink(ResponseInterface $response): void
    {
        if (strlen($response->getHeaderLine('Link')) > self::MAX_LINK_HEADER_LENGTH) {
            throw self::oversizedLink();
        }
    }

    private static function oversizedLink(): GitException
    {
        return new GitException(GitErrorCode::Unknown, 'GitHub sent a pagination Link header longer than '.self::MAX_LINK_HEADER_LENGTH.' characters. It was not parsed, and the page was not returned, because the list may be incomplete.');
    }

    private static function error(string $method, string $url, ResponseInterface $response, ?string $message, bool $rateLimited, ?int $retryAfter): GitException
    {
        $status = $response->getStatusCode();
        $what = $message ?? "HTTP {$status}";
        $requestId = $response->getHeaderLine('X-GitHub-Request-Id');
        $text = "GitHub API {$status} {$method} {$url}: {$what}".($requestId !== '' ? " (request {$requestId})" : '');
        if ($rateLimited && $retryAfter !== null) {
            $text .= ". Retry after {$retryAfter} seconds.";
        }

        return new GitException(self::errorCode($status, $rateLimited), $text, $status);
    }

    /**
     * False when the response is not a rate limit; otherwise the seconds to
     * wait, or null when GitHub gave nothing to compute one from.
     */
    private static function rateLimitWait(ResponseInterface $response, ?string $message): int|false|null
    {
        $status = $response->getStatusCode();
        $retryAfter = self::intHeader($response, 'Retry-After');
        $exhausted = self::intHeader($response, 'X-RateLimit-Remaining') === 0;
        $secondary = preg_match('/secondary rate limit/i', $message ?? '') === 1;
        if (! ($status === 429 || ($status === 403 && ($exhausted || $retryAfter !== null || $secondary)))) {
            return false;
        }
        if ($retryAfter !== null) {
            return $retryAfter;
        }
        $reset = self::intHeader($response, 'X-RateLimit-Reset');
        if ($exhausted && $reset !== null) {
            return max(0, $reset - time());
        }

        return null;
    }

    private static function intHeader(ResponseInterface $response, string $name): ?int
    {
        $value = trim($response->getHeaderLine($name));

        return $value !== '' && is_numeric($value) ? (int) $value : null;
    }

    private static function backoff(int $attempt): int
    {
        return 500 * 2 ** $attempt;
    }
}
