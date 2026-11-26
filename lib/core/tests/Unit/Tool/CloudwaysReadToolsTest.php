<?php

/**
 * The four Cloudways reads: the projection, the refusals, the error envelope.
 *
 * Two things are worth pinning here. First, the projection is the toolkit's
 * contract, not the API's: a payload carrying extra keys must arrive with the
 * documented ones and nothing else, and a row missing keys must not produce a
 * warning or a null the caller has to guard. Second, an API refusal must keep
 * its classification — a 404 that arrives as a generic "API error" makes the
 * caller re-read the message to decide whether the id was wrong.
 *
 * The client is a recorder: it returns whatever the test queued, including a
 * thrown exception, so the tool's own control flow is what is under test.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\CloudwaysClientInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\CloudwaysApiException;
use Nvoos\Core\Tool\Cloudways\CloudwaysTool;
use Nvoos\Core\Tool\Cloudways\GetAppTool;
use Nvoos\Core\Tool\Cloudways\GetServerTool;
use Nvoos\Core\Tool\Cloudways\ListAppsTool;
use Nvoos\Core\Tool\Cloudways\ListServersTool;
use PHPUnit\Framework\TestCase;

final class CloudwaysReadToolsTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => $code, 'message' => $message, 'data' => $data),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'validation_failed', 'message' => $message, 'data' => $fields),
            )
        );
    }

    public function test_list_servers_projects_the_documented_fields_only(): void
    {
        $client = $this->client(
            array(
                'servers' => array(
                    array(
                        'id'            => '123',
                        'label'         => 'Web 1',
                        'status'        => 'Running',
                        'cloud'         => 'do',
                        'region'        => 'lon1',
                        'public_ip'     => '203.0.113.10',
                        'app_count'     => '3',
                        'ram'           => '4096',
                        'instance_type' => '4GB',
                        'server_fqdn'   => 'not-in-the-contract.example',
                    ),
                    'not-a-row',
                ),
            )
        );

        $result = $this->listServers($client)->execute(array(), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['data']['servers']);
        $this->assertSame(
            array(
                'id'            => 123,
                'label'         => 'Web 1',
                'status'        => 'Running',
                'cloud'         => 'do',
                'region'        => 'lon1',
                'public_ip'     => '203.0.113.10',
                'app_count'     => 3,
                'ram'           => '4096',
                'instance_type' => '4GB',
            ),
            $result['data']['servers'][0]
        );
        $this->assertStringContainsString('Found 1 server', $result['message']);
    }

    public function test_list_servers_refuses_a_response_without_a_servers_array(): void
    {
        $result = $this->listServers($this->client(array('data' => array())))
            ->execute(array(), array('user_id' => 7));

        $this->assertFalse($result['success']);
        $this->assertSame('cloudways_invalid_response', $result['error']['code']);
        $this->assertStringContainsString('servers', $result['error']['message']);
    }

    public function test_get_server_refuses_a_missing_id_without_calling_the_api(): void
    {
        $client = $this->client(array());

        $result = $this->getServer($client)->execute(array(), array('user_id' => 7));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('server_id', $result['error']['data']);
        $this->assertCount(0, $client->calls);
    }

    public function test_get_server_reads_the_id_path_and_keeps_the_ram_as_text(): void
    {
        $client = $this->client(
            array(
                'server' => array(
                    'id'            => 55,
                    'label'         => 'API',
                    'status'        => 'Running',
                    'cloud'         => 'vultr',
                    'region'        => 'ams',
                    'public_ip'     => '198.51.100.7',
                    'ram'           => '8192',
                    'instance_type' => '8GB',
                ),
            )
        );

        $result = $this->getServer($client)->execute(array('server_id' => 55), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame('/server/55', $client->calls[0]['path']);
        $this->assertSame('8192', $result['data']['server']['ram']);
        $this->assertStringContainsString('API', $result['message']);
    }

    public function test_an_api_refusal_keeps_its_classification_and_status(): void
    {
        $client = $this->client();
        $client->throws = new CloudwaysApiException('The server does not exist.', 'cloudways_not_found', 404);

        $result = $this->getServer($client)->execute(array('server_id' => 999), array('user_id' => 7));

        $this->assertFalse($result['success']);
        $this->assertSame('cloudways_not_found', $result['error']['code']);
        $this->assertSame(404, $result['error']['data']['status']);
    }

    public function test_list_apps_accepts_an_empty_list_as_a_real_answer(): void
    {
        $client = $this->client(array('apps' => array()));

        $result = $this->listApps($client)->execute(array('server_id' => 12), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['data']['apps']);
        $this->assertStringContainsString('Found 0 app', $result['message']);
        $this->assertSame(array('server_id' => 12), $client->calls[0]['query']);
    }

    public function test_get_app_sends_both_ids_and_refuses_half_a_lookup(): void
    {
        $client = $this->client(array('app' => array('id' => 9, 'label' => 'Store', 'app_type' => 'woocommerce', 'status' => 'running')));

        $refused = $this->getApp($client)->execute(array('server_id' => 12), array('user_id' => 7));

        $this->assertFalse($refused['success']);
        $this->assertArrayHasKey('app_id', $refused['error']['data']);
        $this->assertCount(0, $client->calls);

        $result = $this->getApp($client)->execute(
            array('server_id' => 12, 'app_id' => 9),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame('/app', $client->calls[0]['path']);
        $this->assertSame(array('server_id' => 12, 'app_id' => 9), $client->calls[0]['query']);
        $this->assertSame('woocommerce', $result['data']['app']['app_type']);
    }

    public function test_every_read_requires_an_authenticated_operator(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->listServers($this->client(array('servers' => array())))
            ->execute(array(), array());
    }

    public function test_every_read_asks_for_manage_options(): void
    {
        $client = $this->client(array());

        $this->assertSame('manage_options', $this->listServers($client)->getRequiredCapability());
        $this->assertSame('manage_options', $this->getServer($client)->getRequiredCapability());
        $this->assertSame('manage_options', $this->listApps($client)->getRequiredCapability());
        $this->assertSame('manage_options', $this->getApp($client)->getRequiredCapability());
    }

    // ─── Fixtures ───────────────────────────────────────────────────────────

    private function listServers(CloudwaysClientInterface $client): CloudwaysTool
    {
        return new ListServersTool($this->errors, $client);
    }

    private function getServer(CloudwaysClientInterface $client): CloudwaysTool
    {
        return new GetServerTool($this->errors, $client);
    }

    private function listApps(CloudwaysClientInterface $client): CloudwaysTool
    {
        return new ListAppsTool($this->errors, $client);
    }

    private function getApp(CloudwaysClientInterface $client): CloudwaysTool
    {
        return new GetAppTool($this->errors, $client);
    }

    /**
     * A client that records calls and returns the queued payload, or throws.
     */
    private function client(array $payload = array()): CloudwaysClientInterface
    {
        return new class ($payload) implements CloudwaysClientInterface {
            /** @var list<array{path: string, query: array<string, mixed>, body: array<string, mixed>}> */
            public array $calls = array();

            public ?\Throwable $throws = null;

            public function __construct(private array $payload)
            {
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function get(string $path, array $query = array()): array
            {
                return $this->record('get', $path, $query, array());
            }

            public function post(string $path, array $body = array()): array
            {
                return $this->record('post', $path, array(), $body);
            }

            public function put(string $path, array $body = array()): array
            {
                return $this->record('put', $path, array(), $body);
            }

            public function delete(string $path, array $body = array()): array
            {
                return $this->record('delete', $path, array(), $body);
            }

            private function record(string $verb, string $path, array $query, array $body): array
            {
                $this->calls[] = array('verb' => $verb, 'path' => $path, 'query' => $query, 'body' => $body);

                if (null !== $this->throws) {
                    throw $this->throws;
                }

                return $this->payload;
            }
        };
    }
}
