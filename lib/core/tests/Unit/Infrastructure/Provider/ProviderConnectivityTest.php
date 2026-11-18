<?php

/**
 * Provider liveness probes — `connectivity()`.
 *
 * The panel's provider probe is only worth shipping if it cannot lie. These
 * tests pin the two ways the obvious implementation would:
 *
 *  - a 401 must be a failure, not "reachable with zero models" (which is what
 *    listModels() reports, because the HTTP adapter does not throw on 4xx);
 *  - a client with a static model catalogue must report "not supported" rather
 *    than a success it never observed.
 *
 * @package Nvoos\Core\Tests
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Infrastructure\Provider;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;
use Nvoos\Core\Infrastructure\Provider\AnthropicClient;
use Nvoos\Core\Infrastructure\Provider\GeminiClient;
use Nvoos\Core\Infrastructure\Provider\OpenAiClient;
use PHPUnit\Framework\TestCase;

final class ProviderConnectivityTest extends TestCase
{
    public function test_an_openai_compatible_provider_reports_the_model_count(): void
    {
        $client = $this->openAiClient($this->json(200, array(
            'object' => 'list',
            'data'   => array(
                array('id' => 'gpt-4o'),
                array('id' => 'gpt-4o-mini'),
                array('id' => 'o3-mini'),
            ),
        )));

        $result = $client->connectivity();

        $this->assertTrue($result->attempted);
        $this->assertTrue($result->ok);
        $this->assertSame(200, $result->status);
        $this->assertSame(3, $result->modelCount);
        $this->assertStringContainsString('api.openai.com', $result->detail);
        $this->assertStringContainsString('3 model', $result->detail);
    }

    public function test_a_rejected_key_is_a_failure_not_an_empty_catalogue(): void
    {
        // This is the case listModels() cannot see: a 401 body has no `data`
        // key, so it returns [] and every caller reads "no models".
        $client = $this->openAiClient($this->json(401, array(
            'error' => array('message' => 'Incorrect API key provided.'),
        )));

        $this->assertSame(array(), $client->listModels());

        $result = $client->connectivity();

        $this->assertTrue($result->attempted);
        $this->assertFalse($result->ok);
        $this->assertSame(401, $result->status);
        $this->assertStringContainsString('Incorrect API key provided.', $result->detail);
        $this->assertStringNotContainsString('sk-test-key', $result->detail);
    }

    public function test_a_missing_key_fails_without_calling_the_provider(): void
    {
        $settings = $this->createMock(SettingsStoreInterface::class);
        $settings->method('getApiKey')->willReturn('');
        $settings->method('getApiBaseUrl')->willReturn('');

        $http = $this->createMock(HttpClientInterface::class);
        $http->expects($this->never())->method('send');

        $client = new OpenAiClient($settings, $http, $this->createMock(ErrorFactoryInterface::class));

        $result = $client->connectivity();

        $this->assertTrue($result->attempted);
        $this->assertFalse($result->ok);
        $this->assertNull($result->status);
        $this->assertStringContainsString('No API key', $result->detail);
    }

    public function test_a_transport_failure_is_reported_without_a_status(): void
    {
        $client = $this->openAiClient(null, new \RuntimeException('Connection refused'));

        $result = $client->connectivity();

        $this->assertTrue($result->attempted);
        $this->assertFalse($result->ok);
        $this->assertNull($result->status);
        $this->assertSame('Connection refused', $result->detail);
    }

    public function test_gemini_reads_the_models_array_and_never_echoes_the_key(): void
    {
        $settings = $this->createMock(SettingsStoreInterface::class);
        $settings->method('getApiKey')->willReturn('gemini-secret');
        $settings->method('getApiBaseUrl')->willReturn('');

        $http = $this->createMock(HttpClientInterface::class);
        $http->method('send')->willReturn($this->json(200, array(
            'models' => array(
                array('name' => 'models/gemini-2.5-pro'),
                array('name' => 'models/gemini-2.5-flash'),
            ),
        )));

        $client = new GeminiClient($settings, $http, $this->createMock(ErrorFactoryInterface::class));

        $result = $client->connectivity();

        $this->assertTrue($result->ok);
        $this->assertSame(200, $result->status);
        $this->assertSame(2, $result->modelCount);
        $this->assertStringNotContainsString('gemini-secret', $result->detail);
        $this->assertStringContainsString('generativelanguage.googleapis.com', $result->detail);
    }

    public function test_anthropic_probes_the_endpoint_instead_of_its_static_catalogue(): void
    {
        $settings = $this->createMock(SettingsStoreInterface::class);
        $settings->method('getApiKey')->willReturn('anthropic-secret');
        $settings->method('getApiBaseUrl')->willReturn('');

        $http = $this->createMock(HttpClientInterface::class);
        $http->expects($this->once())
            ->method('send')
            ->with('GET', $this->stringContains('/v1/models'), $this->anything())
            ->willReturn($this->json(200, array(
                'data' => array(array('id' => 'claude-sonnet-4-6')),
            )));

        $client = new AnthropicClient($settings, $http, $this->createMock(ErrorFactoryInterface::class));

        // The catalogue is still static and unchanged — it is just not proof.
        $this->assertCount(5, $client->listModels());

        $result = $client->connectivity();

        $this->assertTrue($result->ok);
        $this->assertSame(1, $result->modelCount);
    }

    public function test_a_client_with_no_liveness_endpoint_says_so(): void
    {
        $client = new class (
            $this->createMock(SettingsStoreInterface::class),
            $this->createMock(HttpClientInterface::class),
            $this->createMock(ErrorFactoryInterface::class),
        ) extends AbstractProviderClient {
            protected string $providerSlug = 'custom';

            public function chat(array $messages, array $options = array()): mixed
            {
                return array();
            }

            public function stream(array $messages, array $options = array(), ?callable $onChunk = null): mixed
            {
                return array();
            }

            public function listModels(): mixed
            {
                return array('static-model');
            }

            protected function getDefaultBaseUrl(): string
            {
                return 'https://example.test/v1';
            }
        };

        $result = $client->connectivity();

        $this->assertFalse($result->attempted);
        $this->assertFalse($result->ok);
        $this->assertNull($result->status);
        $this->assertStringContainsString('no liveness endpoint', $result->detail);
    }

    private function openAiClient(?HttpResponse $response, ?\Throwable $throw = null): OpenAiClient
    {
        $settings = $this->createMock(SettingsStoreInterface::class);
        $settings->method('getApiKey')->willReturn('sk-test-key');
        $settings->method('getApiBaseUrl')->willReturn('');

        $http = $this->createMock(HttpClientInterface::class);

        if (null !== $throw) {
            $http->method('send')->willThrowException($throw);
        } else {
            $http->method('send')->willReturn($response);
        }

        return new OpenAiClient($settings, $http, $this->createMock(ErrorFactoryInterface::class));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(int $status, array $body): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode($body));
    }
}
