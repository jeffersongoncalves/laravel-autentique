<?php

namespace JeffersonGoncalves\Autentique\Exceptions;

use Illuminate\Http\Client\Response;

class RateLimitException extends AutentiqueException
{
    public function __construct(string $message, public readonly int $retryAfter, ?Response $response = null)
    {
        parent::__construct($message, [], $response);
    }

    public static function throttled(int $retryAfter): self
    {
        return new self("Autentique rate limit reached, retry in {$retryAfter}s.", $retryAfter);
    }

    public static function fromTooManyRequests(Response $response): self
    {
        $retryAfter = (int) $response->header('Retry-After');

        return new self('Autentique API returned 429 Too Many Attempts.', $retryAfter > 0 ? $retryAfter : 60, $response);
    }
}
