<?php

/**
 * The six Cloudways app-lifecycle tools.
 *
 * Proposal 164 section 164.138. What is pinned: the application-type enum is the
 * WordPress toolkit's own (not the Cloudways MCP surface's wider set), the label
 * is required everywhere it matters, the restore/rollback pair needs no
 * confirmation because rollback is its undo, and the delete requires the literal
 * boolean `true` rather than any truthy value.
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
use Nvoos\Core\Tool\Cloudways\AppCloneToServerTool;
use Nvoos\Core\Tool\Cloudways\AppCloneTool;
use Nvoos\Core\Tool\Cloudways\AppCreateTool;
use Nvoos\Core\Tool\Cloudways\AppDeleteTool;
use Nvoos\Core\Tool\Cloudways\AppRestoreRollbackTool;
use Nvoos\Core\Tool\Cloudways\AppRestoreTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CloudwaysAppLifecycleTest extends TestCase
{
    private const CONTEXT = array('user_id' => 9);

    private const TARGET = array('server_id' => 11, 'app_id' => 22);

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

    // ─── create ──────────────────────────────────────────────────────────

    public function test_create_posts_the_three_arguments(): void
    {
        $client = $this->client(array('operation_id' => 'op-1'));

        $result = (new AppCreateTool($this->errors, $client))->execute(
            array('server_id' => 11, 'app' => 'laravel', 'label' => ' API '),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('post', $client->calls[0]['verb']);
        $this->assertSame('/app', $client->calls[0]['path']);
        $this->assertSame(
            array('server_id' => 11, 'app' => 'laravel', 'label' => 'API'),
            $client->calls[0]['body']
        );
        $this->assertSame('Application creation initiated.', $result['message']);
    }

    public function test_an_unknown_application_type_is_refused(): void
    {
        $client = $this->client(array());

        $result = (new AppCreateTool($this->errors, $client))->execute(
            array('server_id' => 11, 'app' => 'drupal', 'label' => 'Site'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('app', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_a_missing_label_is_refused(): void
    {
        $client = $this->client(array());

        $result = (new AppCreateTool($this->errors, $client))->execute(
            array('server_id' => 11, 'app' => 'php', 'label' => '  '),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('label', $result['error']['data']);
    }

    // ─── clone ───────────────────────────────────────────────────────────

    public function test_clone_posts_the_label_to_the_clone_path(): void
    {
        $client = $this->client(array('ok' => true));

        $result = (new AppCloneTool($this->errors, $client))->execute(
            self::TARGET + array('label' => 'Staging'),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('/app/11/22/clone', $client->calls[0]['path']);
        $this->assertSame(array('label' => 'Staging'), $client->calls[0]['body']);
    }

    public function test_clone_refuses_an_empty_label(): void
    {
        $result = (new AppCloneTool($this->errors, $this->client(array())))->execute(
            self::TARGET + array('label' => ''),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    // ─── clone to server ─────────────────────────────────────────────────

    public function test_clone_to_server_posts_the_target_and_label(): void
    {
        $client = $this->client(array('ok' => true));

        (new AppCloneToServerTool($this->errors, $client))->execute(
            self::TARGET + array('target_server_id' => 33, 'label' => 'Moved'),
            self::CONTEXT
        );

        $this->assertSame('/app/11/22/clone-to-server', $client->calls[0]['path']);
        $this->assertSame(
            array('target_server_id' => 33, 'label' => 'Moved'),
            $client->calls[0]['body']
        );
    }

    public function test_clone_to_server_requires_a_target(): void
    {
        $client = $this->client(array());

        $result = (new AppCloneToServerTool($this->errors, $client))->execute(
            self::TARGET + array('label' => 'Moved'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('target_server_id', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    // ─── restore and rollback ────────────────────────────────────────────

    public function test_restore_posts_with_no_body(): void
    {
        $client = $this->client(array('ok' => true));

        $result = (new AppRestoreTool($this->errors, $client))->execute(self::TARGET, self::CONTEXT);

        $this->assertSame('/app/11/22/restore', $client->calls[0]['path']);
        $this->assertSame(array(), $client->calls[0]['body']);
        $this->assertSame('Application restore initiated.', $result['message']);
    }

    public function test_rollback_posts_to_the_rollback_path(): void
    {
        $client = $this->client(array('ok' => true));

        $result = (new AppRestoreRollbackTool($this->errors, $client))->execute(self::TARGET, self::CONTEXT);

        $this->assertSame('/app/11/22/restore/rollback', $client->calls[0]['path']);
        $this->assertSame('Restore rollback initiated.', $result['message']);
    }

    // ─── delete ──────────────────────────────────────────────────────────

    public function test_delete_requires_the_literal_boolean_true(): void
    {
        $client = $this->client(array());

        $result = (new AppDeleteTool($this->errors, $client))->execute(
            self::TARGET + array('confirm' => 'true'),
            self::CONTEXT
        );

        $this->assertSame('cloudways_confirm_required', $result['error']['code']);
        $this->assertSame(array(), $client->calls);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function notConfirmations(): array
    {
        return array(
            'string true' => array('true'),
            'one'         => array(1),
            'false'       => array(false),
            'absent'      => array(null),
        );
    }

    #[DataProvider('notConfirmations')]
    public function test_nothing_but_true_confirms_a_delete(mixed $confirm): void
    {
        $client     = $this->client(array());
        $arguments  = self::TARGET;
        if (null !== $confirm) {
            $arguments['confirm'] = $confirm;
        }

        $result = (new AppDeleteTool($this->errors, $client))->execute($arguments, self::CONTEXT);

        $this->assertSame('cloudways_confirm_required', $result['error']['code']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_a_confirmed_delete_issues_the_delete(): void
    {
        $client = $this->client(array('ok' => true));

        $result = (new AppDeleteTool($this->errors, $client))->execute(
            self::TARGET + array('confirm' => true),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('delete', $client->calls[0]['verb']);
        $this->assertSame('/app/11/22', $client->calls[0]['path']);
        $this->assertSame('Application permanently deleted.', $result['message']);
    }

    public function test_an_api_refusal_keeps_its_classification(): void
    {
        $client         = $this->client(array());
        $client->throws = new CloudwaysApiException('Busy', 'cloudways_server_error', 503);

        $result = (new AppRestoreTool($this->errors, $client))->execute(self::TARGET, self::CONTEXT);

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
