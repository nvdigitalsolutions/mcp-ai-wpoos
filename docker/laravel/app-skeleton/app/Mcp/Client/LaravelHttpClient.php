<?php

namespace App\Mcp\Client;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use RuntimeException;

/**
 * HttpClientInterface over Laravel's HTTP client.
 *
 * The core contract promises a RuntimeException when the request cannot be
 * completed, and Laravel's ConnectionException is not one, so it is translated
 * here rather than leaking a framework type into the caller's catch block.
 */
final class LaravelHttpClient implements HttpClientInterface
{
    /**
     * @param int $timeout Request timeout in seconds.
     */
    public function __construct(private readonly int $timeout = 15)
    {
    }

    /**
     * @param array<string, string> $headers
     *
     * @throws RuntimeException When the request cannot be completed.
     */
    public function send(string $method, string $url, array $headers = array(), ?string $body = null): HttpResponse
    {
        try {
            $response = Http::withHeaders($headers)
                ->timeout($this->timeout)
                ->send($method, $url, null === $body ? array() : array('body' => $body));
        } catch (ConnectionException $error) {
            throw new RuntimeException($error->getMessage(), 0, $error);
        }

        return new HttpResponse(
            $response->status(),
            $response->body(),
            $this->flattenHeaders($response->headers())
        );
    }

    /**
     * Laravel returns each header as a list; the contract wants one string.
     *
     * @param array<string, array<int, string>> $headers
     * @return array<string, string>
     */
    private function flattenHeaders(array $headers): array
    {
        $flattened = array();

        foreach ($headers as $name => $values) {
            $flattened[(string) $name] = implode(', ', (array) $values);
        }

        return $flattened;
    }
}
