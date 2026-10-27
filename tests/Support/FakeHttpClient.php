<?php

declare(strict_types=1);

namespace VamsysMcp\Tests\Support;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 client that records every request and answers from a queue of
 * responses, or from a callable for routing by URL.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface> */
    private array $queue = [];

    /** @var null|\Closure(RequestInterface): ResponseInterface */
    private ?\Closure $router = null;

    public function queue(ResponseInterface ...$responses): self
    {
        array_push($this->queue, ...$responses);

        return $this;
    }

    /** @param \Closure(RequestInterface): ResponseInterface $router */
    public function route(\Closure $router): self
    {
        $this->router = $router;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        if ($this->router !== null) {
            return ($this->router)($request);
        }

        return array_shift($this->queue) ?? throw new \LogicException('No fake response queued for ' . $request->getUri());
    }

    /** @param array<mixed> $data */
    public static function json(array $data, int $status = 200): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }

    public static function token(string $token = 'tok-1', int $expiresIn = 86400): ResponseInterface
    {
        return self::json(['token_type' => 'Bearer', 'access_token' => $token, 'expires_in' => $expiresIn]);
    }
}
