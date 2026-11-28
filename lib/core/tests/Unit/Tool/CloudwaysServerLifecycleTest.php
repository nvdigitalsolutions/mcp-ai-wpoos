<?php

/**
 * The seven Cloudways server-lifecycle writers.
 *
 * Proposal 164 section 164.139. What is pinned: the three bodyless actions share
 * one base and differ only in their path segment and sentence; the create and
 * clone enums are the WordPress vocabulary; a size string is passed through
 * because the per-provider plan list is the API's to enforce; and the two scale
 * messages name the value they set, which the WordPress `sprintf` did not.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\CloudwaysClientInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Error\CloudwaysApiException;
use Nvoos\Core\Tool\Cloudways\ServerCloneTool;
use Nvoos\Core\Tool\Cloudways\ServerCreateTool;
use Nvoos\Core\Tool\Cloudways\ServerRestartTool;
use Nvoos\Core\Tool\Cloudways\ServerScaleTool;
use Nvoos\Core\Tool\Cloudways\ServerScaleVolumeTool;
use Nvoos\Core\Tool\Cloudways\ServerStartTool;
use Nvoos\Core\Tool\Cloudways\ServerStopTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CloudwaysServerLifecycleTest extends TestCase
{
    private const CONTEXT = array('user_id' => 6);

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

    // ─── restart / start / stop ──────────────────────────────────────────

    /**
     * @return array<string, array{0: class-string, 1: string, 2: string}>
     */
    public static function toggles(): array
    {
        return array(
            'restart' => array(ServerRestartTool::class, 'restart', 'Server 44 restart initiated.'),
            'start'   => array(ServerStartTool::class, 'start', 'Server 44 start initiated.'),
            'stop'    => array(ServerStopTool::class, 'stop', 'Server 44 stop initiated.'),
        );
    }

    /**
     * @param class-string $class Tool class.
     */
    #[DataProvider('toggles')]
    public function test_each_toggle_posts_its_action_with_no_body(string $class, string $action, string $message): void
    {
        $client = $this->client(array('operation_id' => 'op-9'));
        $tool   = new $class($this->errors, $client);

        $result = $tool->execute(array('server_id' => 44), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame('post', $client->calls[0]['verb']);
        $this->assertSame('/server/44/' . $action, $client->calls[0]['path']);
        $this->assertSame(array(), $client->calls[0]['body']);
        $this->assertSame($message, $result['message']);
        $this->assertSame(array('operation_id' => 'op-9'), $result['data']['result']);
    }

    public function test_a_toggle_without_a_server_id_is_refused(): void
    {
        $client = $this->client(array());
        $result = (new ServerStartTool($this->errors, $client))->execute(array(), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertSame(array(), $client->calls);
    }

    // ─── create ──────────────────────────────────────────────────────────

    public function test_create_posts_the_five_arguments(): void
    {
        $client = $this->client(array('operation_id' => 'op-1'));

        $result = (new ServerCreateTool($this->errors, $client))->execute(
            array(
                'cloud'  => 'digitalocean',
                'size'   => '2GB',
                'region' => 'lon1',
                'app'    => 'wordpress',
                'label'  => 'Web 1',
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('/server', $client->calls[0]['path']);
        $this->assertSame(
            array(
                'cloud'  => 'digitalocean',
                'size'   => '2GB',
                'region' => 'lon1',
                'app'    => 'wordpress',
                'label'  => 'Web 1',
            ),
            $client->calls[0]['body']
        );
        $this->assertSame('Server creation initiated.', $result['message']);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function badCreates(): array
    {
        $valid = array(
            'cloud'  => 'aws',
            'size'   => '2GB',
            'region' => 'us-east-1',
            'app'    => 'php',
            'label'  => 'API',
        );

        return array(
            'unknown cloud' => array(array('cloud' => 'azure') + $valid, 'cloud'),
            'empty size'    => array(array('size' => '') + $valid, 'size'),
            'empty region'  => array(array('region' => '') + $valid, 'region'),
            'unknown app'   => array(array('app' => 'drupal') + $valid, 'app'),
            'empty label'   => array(array('label' => '  ') + $valid, 'label'),
        );
    }

    /**
     * @param array<string, string> $arguments Arguments.
     */
    #[DataProvider('badCreates')]
    public function test_create_names_the_unusable_argument(array $arguments, string $field): void
    {
        $client = $this->client(array());

        $result = (new ServerCreateTool($this->errors, $client))->execute($arguments, self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey($field, $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    // ─── clone ───────────────────────────────────────────────────────────

    public function test_clone_posts_the_label(): void
    {
        $client = $this->client(array('ok' => true));

        (new ServerCloneTool($this->errors, $client))->execute(
            array('server_id' => 8, 'label' => 'Clone'),
            self::CONTEXT
        );

        $this->assertSame('/server/8/clone', $client->calls[0]['path']);
        $this->assertSame(array('label' => 'Clone'), $client->calls[0]['body']);
    }

    public function test_clone_requires_a_label(): void
    {
        $result = (new ServerCloneTool($this->errors, $this->client(array())))
            ->execute(array('server_id' => 8), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    // ─── scale ───────────────────────────────────────────────────────────

    public function test_scale_sends_the_size_and_names_it_in_the_message(): void
    {
        $client = $this->client(array('ok' => true));

        $result = (new ServerScaleTool($this->errors, $client))->execute(
            array('server_id' => 8, 'size' => 'c-8GB'),
            self::CONTEXT
        );

        $this->assertSame('/server/8/scale', $client->calls[0]['path']);
        $this->assertSame(array('size' => 'c-8GB'), $client->calls[0]['body']);
        $this->assertSame('Server 8 scaling to c-8GB initiated.', $result['message']);
    }

    public function test_scale_requires_a_size(): void
    {
        $result = (new ServerScaleTool($this->errors, $this->client(array())))
            ->execute(array('server_id' => 8), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    // ─── volume scale ────────────────────────────────────────────────────

    public function test_volume_scale_sends_the_gb_and_names_it(): void
    {
        $client = $this->client(array('ok' => true));

        $result = (new ServerScaleVolumeTool($this->errors, $client))->execute(
            array('server_id' => 8, 'size_gb' => 60),
            self::CONTEXT
        );

        $this->assertSame('/server/8/volume/scale', $client->calls[0]['path']);
        $this->assertSame(array('size_gb' => 60), $client->calls[0]['body']);
        $this->assertSame('Server 8 volume scaling to 60 GB initiated.', $result['message']);
    }

    public function test_a_negative_volume_size_is_named(): void
    {
        $client = $this->client(array());

        $result = (new ServerScaleVolumeTool($this->errors, $client))->execute(
            array('server_id' => 8, 'size_gb' => -10),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('size_gb', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_an_api_refusal_keeps_its_classification(): void
    {
        $client         = $this->client(array());
        $client->throws = new CloudwaysApiException('Busy', 'cloudways_server_error', 503);

        $result = (new ServerRestartTool($this->errors, $client))->execute(array('server_id' => 8), self::CONTEXT);

        $this->assertSame('cloudways_server_error', $result['error']['code']);
        $this->assertSame(503, $result['error']['data']['status']);
    }

    /**
     * A recorder client that answers the same payload for every verb.
     *
     * @param array<string, mixed> $payload Response body.
     */
    private function client(array $payload = array()): CloudwaysClientInterface
    {
        return new class ($payload) implements CloudwaysClientInterface {
            /** @var list<array{verb: string, path: string, query: array<string, mixed>, body: array<string, mixed>}> */
            public array $calls = array();

            public ?\Throwable $throws = null;

            /**
             * @param array<string, mixed> $payload Response body.
             */
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

            /**
             * @param array<string, mixed> $query Query.
             * @param array<string, mixed> $body  Body.
             *
             * @return array<string, mixed>
             */
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
