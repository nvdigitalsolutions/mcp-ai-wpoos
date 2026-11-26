<?php

/**
 * The Cloudways service, operation and label tools.
 *
 * What is pinned here is the validation boundary and the payload that leaves:
 * an unlisted service is refused before the API is called, a blank label is
 * refused after trimming, the restart body is exactly the service name, and an
 * operation's completion flag survives as a tri-state rather than collapsing
 * to a boolean (the API uses -1 for failed).
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\CloudwaysClientInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Error\CloudwaysApiException;
use Nvoos\Core\Tool\Cloudways\CloudwaysTool;
use Nvoos\Core\Tool\Cloudways\GetOperationStatusTool;
use Nvoos\Core\Tool\Cloudways\RestartServiceTool;
use Nvoos\Core\Tool\Cloudways\ServiceStatusTool;
use Nvoos\Core\Tool\Cloudways\UpdateServerLabelTool;
use PHPUnit\Framework\TestCase;

final class CloudwaysWriteToolsTest extends TestCase
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

    public function test_service_status_keeps_the_status_string_and_skips_bad_rows(): void
    {
        $client = $this->client(
            array(
                'services' => array(
                    array('name' => 'nginx', 'status' => 'running', 'pid' => 12),
                    array('name' => 'mysql', 'status' => 'restarting'),
                    'not-a-row',
                ),
            )
        );

        $result = $this->services($client)->execute(array('server_id' => 4), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame(
            array(
                array('name' => 'nginx', 'status' => 'running'),
                array('name' => 'mysql', 'status' => 'restarting'),
            ),
            $result['data']['services']
        );
        $this->assertSame('/service/4', $client->calls[0]['path']);
    }

    public function test_service_status_refuses_a_response_without_services(): void
    {
        $result = $this->services($this->client(array('data' => array())))
            ->execute(array('server_id' => 4), array('user_id' => 7));

        $this->assertFalse($result['success']);
        $this->assertSame('cloudways_invalid_response', $result['error']['code']);
    }

    public function test_restart_refuses_an_unlisted_service_before_calling_the_api(): void
    {
        $client = $this->client(array());

        $result = $this->restart($client)->execute(
            array('server_id' => 4, 'service' => 'apache2'),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('php-fpm', $result['error']['data']['service'][0]);
        $this->assertCount(0, $client->calls);
    }

    public function test_restart_posts_the_service_and_returns_the_api_answer(): void
    {
        $client = $this->client(array('operation_id' => 'flex-77'));

        $result = $this->restart($client)->execute(
            array('server_id' => 4, 'service' => 'MySQL'),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame('/service/4/restart', $client->calls[0]['path']);
        $this->assertSame('post', $client->calls[0]['verb']);
        $this->assertSame(array('service' => 'mysql'), $client->calls[0]['body']);
        $this->assertSame('flex-77', $result['data']['operation_id']);
        $this->assertStringContainsString('mysql', $result['message']);
    }

    public function test_operation_status_reports_the_completion_tri_state(): void
    {
        $cases = array(
            array(0, 0),
            array(1, 1),
            array(-1, -1),
        );

        foreach ($cases as $case) {
            $client = $this->client(
                array(
                    'operation' => array(
                        'id'           => 'flex-1',
                        'status'       => 'running',
                        'type'         => 'restart',
                        'progress'     => 40,
                        'is_completed' => $case[0],
                    ),
                )
            );

            $result = $this->operation($client)->execute(
                array('operation_id' => 'flex-1'),
                array('user_id' => 7)
            );

            $this->assertSame($case[1], $result['data']['operation']['is_completed']);
            $this->assertSame(40, $result['data']['operation']['progress']);
        }
    }

    public function test_operation_status_reports_null_when_the_api_omits_the_flag(): void
    {
        $client = $this->client(
            array('operation' => array('id' => 'flex-2', 'status' => 'done'))
        );

        $result = $this->operation($client)->execute(
            array('operation_id' => 'flex-2'),
            array('user_id' => 7)
        );

        $this->assertNull($result['data']['operation']['is_completed']);
        $this->assertSame(0, $result['data']['operation']['progress']);
        $this->assertSame('', $result['data']['operation']['type']);
    }

    public function test_operation_status_url_encodes_the_id(): void
    {
        $client = $this->client(array('operation' => array('id' => 'flex 9/1')));

        $this->operation($client)->execute(
            array('operation_id' => 'flex 9/1'),
            array('user_id' => 7)
        );

        $this->assertSame('/operation/flex%209%2F1', $client->calls[0]['path']);
    }

    public function test_operation_status_refuses_an_empty_id(): void
    {
        $client = $this->client(array());

        $result = $this->operation($client)->execute(array('operation_id' => '  '), array('user_id' => 7));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $client->calls);
    }

    public function test_update_label_refuses_a_blank_label_without_calling_the_api(): void
    {
        $client = $this->client(array());

        $result = $this->label($client)->execute(
            array('server_id' => 4, 'label' => '   '),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $client->calls);
    }

    public function test_update_label_puts_the_trimmed_label_and_returns_the_api_answer(): void
    {
        $client = $this->client(array('server' => array('id' => 4, 'label' => 'Prod Web')));

        $result = $this->label($client)->execute(
            array('server_id' => 4, 'label' => '  Prod Web  '),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame('put', $client->calls[0]['verb']);
        $this->assertSame('/server/4/label', $client->calls[0]['path']);
        $this->assertSame(array('label' => 'Prod Web'), $client->calls[0]['body']);
        $this->assertSame('Prod Web', $result['data']['server']['label']);
        $this->assertStringContainsString('Prod Web', $result['message']);
    }

    public function test_a_classified_failure_keeps_the_status(): void
    {
        $client       = $this->client();
        $client->throws = new CloudwaysApiException('Rate limited.', 'cloudways_rate_limited', 429);

        $result = $this->restart($client)->execute(
            array('server_id' => 4, 'service' => 'nginx'),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertSame('cloudways_rate_limited', $result['error']['code']);
        $this->assertSame(429, $result['error']['data']['status']);
    }

    // ─── Fixtures ───────────────────────────────────────────────────────────

    private function services(CloudwaysClientInterface $client): CloudwaysTool
    {
        return new ServiceStatusTool($this->errors, $client);
    }

    private function restart(CloudwaysClientInterface $client): CloudwaysTool
    {
        return new RestartServiceTool($this->errors, $client);
    }

    private function operation(CloudwaysClientInterface $client): CloudwaysTool
    {
        return new GetOperationStatusTool($this->errors, $client);
    }

    private function label(CloudwaysClientInterface $client): CloudwaysTool
    {
        return new UpdateServerLabelTool($this->errors, $client);
    }

    /**
     * A client that records calls and returns the queued payload, or throws.
     */
    private function client(array $payload = array()): CloudwaysClientInterface
    {
        return new class ($payload) implements CloudwaysClientInterface {
            /** @var list<array{verb: string, path: string, query: array<string, mixed>, body: array<string, mixed>}> */
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
