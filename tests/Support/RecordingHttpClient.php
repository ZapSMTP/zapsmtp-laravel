<?php

declare(strict_types=1);

namespace ZapSMTP\Laravel\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** A PSR-18 client that answers with fixed responses and records each request. */
final class RecordingHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|\Throwable> */
    private array $answers;

    public function __construct(ResponseInterface|\Throwable ...$answers)
    {
        $this->answers = array_values($answers);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $a = \count($this->answers) > 1 ? array_shift($this->answers) : ($this->answers[0] ?? new Response(200, [], '{"id":"e1"}'));
        if ($a instanceof \Throwable) {
            throw $a;
        }

        return $a;
    }

    public static function json(mixed $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json', 'X-Request-Id' => 'req_1'], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> the JSON body of request $i */
    public function body(int $i = 0): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $this->requests[$i]->getBody(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
