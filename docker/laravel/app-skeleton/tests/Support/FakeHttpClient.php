<?php

namespace Tests\Support;

use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use RuntimeException;

/**
 * Scripted HttpClientInterface for MCP client tests.
 *
 * Responses are queued in call order. An exhausted queue throws, which the
 * metadata discovery path treats as a transport failure — so a test that only
 * cares about "nothing answers" can queue nothing at all.
 */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var array<int, HttpResponse|RuntimeException> */
    private array $queue = array();

    /** @var array<int, array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = array();

    /**
     * Queue a response.
     *
     * @param int                  $status  HTTP status.
     * @param string|array<mixed>  $body    Body, encoded when an array.
     * @param array<string,string> $headers Response headers.
     */
    public function queue(int $status, string|array $body, array $headers = array()): self
    {
        $this->queue[] = new HttpResponse(
            $status,
            is_array($body) ? (string) json_encode($body) : $body,
            $headers
        );

        return $this;
    }

    /**
     * Queue a transport failure.
     */
    public function queueFailure(string $message): self
    {
        $this->queue[] = new RuntimeException($message);

        return $this;
    }

    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers = array(), ?string $body = null): HttpResponse
    {
        $this->requests[] = array(
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        );

        $next = array_shift($this->queue);

        if ($next instanceof RuntimeException) {
            throw $next;
        }

        if (null === $next) {
            throw new RuntimeException('No queued HTTP response for '.$method.' '.$url);
        }

        return $next;
    }

    /**
     * The decoded JSON body of the request at $index.
     *
     * @return array<string, mixed>
     */
    public function bodyAt(int $index): array
    {
        $body = $this->requests[$index]['body'] ?? '';
        $decoded = json_decode((string) $body, true);

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * The raw JSON body of the request at $index.
     */
    public function rawBodyAt(int $index): string
    {
        return (string) ($this->requests[$index]['body'] ?? '');
    }
}
