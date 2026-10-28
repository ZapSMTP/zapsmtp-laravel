<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Testing;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The HTTP side of ZapSMTP::fake(): records each API request and answers
 * without a network: a send gets a fake email id, a batch one per email, a
 * GET an empty list, a DELETE no content, anything else {"id": ...}.
 *
 * @internal
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<array{method: string, path: string, query: string, body: mixed}> */
    public array $requests = [];

    private int $ids = 0;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $raw = (string) $request->getBody();
        $body = json_decode($raw, true);
        $path = (string) preg_replace('#^.*?/v1#', '', $request->getUri()->getPath());
        $this->requests[] = ['method' => $request->getMethod(), 'path' => $path, 'query' => $request->getUri()->getQuery(), 'body' => $body ?? $raw];
        $method = $request->getMethod();
        if ($method === 'POST' && $path === '/emails/batch') {
            $data = [];
            foreach (\is_array($body) ? $body : [] as $i => $_) {
                $data[] = ['id' => $this->id(), 'index' => $i];
            }

            return self::json(['data' => $data, 'errors' => []]);
        }

        return match ($method) {
            'GET' => self::json(['object' => 'list', 'data' => [], 'has_more' => false]),
            'DELETE' => new Response(204, ['X-Request-Id' => 'req_fake']),
            default => self::json(['id' => $this->id()]),
        };
    }

    private function id(): string
    {
        return sprintf('00000000-0000-7000-8000-%012d', ++$this->ids);
    }

    /** @param array<string, mixed> $body */
    private static function json(array $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json', 'X-Request-Id' => 'req_fake'], json_encode($body, \JSON_THROW_ON_ERROR));
    }
}
