<?php

/**
 * The six Cloudways Git tools.
 *
 * Proposal 164 section 164.140. What is pinned: the three readers project what
 * the API sends and drop non-rows; the history reader is treated as the read it
 * is, despite WordPress flagging it a write; and the clone message no longer
 * HTML-escapes the branch, so a branch containing `&` comes back as the ref that
 * was deployed rather than as `&amp;`.
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
use Nvoos\Core\Tool\Cloudways\GitBranchesTool;
use Nvoos\Core\Tool\Cloudways\GitCloneTool;
use Nvoos\Core\Tool\Cloudways\GitGenerateKeyTool;
use Nvoos\Core\Tool\Cloudways\GitHistoryTool;
use Nvoos\Core\Tool\Cloudways\GitKeyTool;
use Nvoos\Core\Tool\Cloudways\GitPullTool;
use PHPUnit\Framework\TestCase;

final class CloudwaysGitToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 5);

    private const TARGET = array('server_id' => 2, 'app_id' => 3);

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

    // ─── branches ────────────────────────────────────────────────────────

    public function test_branches_are_a_list_of_names(): void
    {
        $client = $this->client(array('branches' => array('main', 'release/1.0', array('nested'))));

        $result = (new GitBranchesTool($this->errors, $client))->execute(self::TARGET, self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame(array('main', 'release/1.0'), $result['data']['branches']);
        $this->assertSame('/app/2/3/git/branches', $client->calls[0]['path']);
        $this->assertSame('Found 2 branches.', $result['message']);
    }

    public function test_a_missing_branches_block_is_malformed(): void
    {
        $result = (new GitBranchesTool($this->errors, $this->client(array())))
            ->execute(self::TARGET, self::CONTEXT);

        $this->assertSame('cloudways_invalid_response', $result['error']['code']);
    }

    // ─── history ─────────────────────────────────────────────────────────

    public function test_history_projects_four_fields_and_drops_non_rows(): void
    {
        $client = $this->client(
            array(
                'history' => array(
                    array('id' => '1', 'commit' => 'abc123', 'message' => 'Deploy', 'timestamp' => '2026-03-01 10:00:00'),
                    array('id' => 2),
                    'not-a-row',
                ),
            )
        );

        $result = (new GitHistoryTool($this->errors, $client))->execute(self::TARGET, self::CONTEXT);

        $this->assertSame(
            array(
                array('id' => '1', 'commit' => 'abc123', 'message' => 'Deploy', 'timestamp' => '2026-03-01 10:00:00'),
                array('id' => '2', 'commit' => '', 'message' => '', 'timestamp' => ''),
            ),
            $result['data']['history']
        );
        $this->assertSame('/app/2/3/git/history', $client->calls[0]['path']);
        $this->assertSame('Found 2 deployment history entries.', $result['message']);
    }

    public function test_a_missing_history_block_is_malformed(): void
    {
        $result = (new GitHistoryTool($this->errors, $this->client(array())))
            ->execute(self::TARGET, self::CONTEXT);

        $this->assertSame('cloudways_invalid_response', $result['error']['code']);
    }

    // ─── key ─────────────────────────────────────────────────────────────

    public function test_the_deploy_key_is_returned_with_its_type(): void
    {
        $client = $this->client(
            array('git_key' => array('key' => 'ssh-ed25519 AAAA', 'type' => 'ed25519'))
        );

        $result = (new GitKeyTool($this->errors, $client))->execute(self::TARGET, self::CONTEXT);

        $this->assertSame(
            array('key' => 'ssh-ed25519 AAAA', 'type' => 'ed25519'),
            $result['data']['git_key']
        );
        $this->assertSame('/app/2/3/git/key', $client->calls[0]['path']);
    }

    public function test_a_missing_key_block_is_malformed(): void
    {
        $result = (new GitKeyTool($this->errors, $this->client(array())))
            ->execute(self::TARGET, self::CONTEXT);

        $this->assertSame('cloudways_invalid_response', $result['error']['code']);
    }

    // ─── clone ───────────────────────────────────────────────────────────

    public function test_clone_posts_the_branch_and_names_it(): void
    {
        $client = $this->client(array('ok' => true));

        $result = (new GitCloneTool($this->errors, $client))->execute(
            self::TARGET + array('branch' => ' main '),
            self::CONTEXT
        );

        $this->assertSame('/app/2/3/git/clone', $client->calls[0]['path']);
        $this->assertSame(array('branch' => 'main'), $client->calls[0]['body']);
        $this->assertSame('Git clone initiated for branch "main" on app 3.', $result['message']);
    }

    public function test_the_branch_is_not_html_escaped(): void
    {
        $client = $this->client(array('ok' => true));

        $result = (new GitCloneTool($this->errors, $client))->execute(
            self::TARGET + array('branch' => 'feature/a&b'),
            self::CONTEXT
        );

        $this->assertStringContainsString('feature/a&b', $result['message']);
        $this->assertStringNotContainsString('&amp;', $result['message']);
        $this->assertSame('feature/a&b', $result['data']['branch']);
    }

    public function test_clone_requires_a_branch(): void
    {
        $client = $this->client(array());

        $result = (new GitCloneTool($this->errors, $client))->execute(
            self::TARGET + array('branch' => '   '),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('branch', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    // ─── pull and generate key ───────────────────────────────────────────

    public function test_pull_posts_with_no_body(): void
    {
        $client = $this->client(array('ok' => true));

        $result = (new GitPullTool($this->errors, $client))->execute(self::TARGET, self::CONTEXT);

        $this->assertSame('/app/2/3/git/pull', $client->calls[0]['path']);
        $this->assertSame(array(), $client->calls[0]['body']);
        $this->assertSame('Git pull initiated for app 3.', $result['message']);
    }

    public function test_generate_key_posts_with_no_body(): void
    {
        $client = $this->client(array('ok' => true));

        $result = (new GitGenerateKeyTool($this->errors, $client))->execute(self::TARGET, self::CONTEXT);

        $this->assertSame('/app/2/3/git/key/generate', $client->calls[0]['path']);
        $this->assertSame(array(), $client->calls[0]['body']);
        $this->assertSame('Git deploy key generated for app 3.', $result['message']);
    }

    public function test_an_api_refusal_keeps_its_classification(): void
    {
        $client         = $this->client(array());
        $client->throws = new CloudwaysApiException('No repo', 'cloudways_not_found', 404);

        $result = (new GitPullTool($this->errors, $client))->execute(self::TARGET, self::CONTEXT);

        $this->assertSame('cloudways_not_found', $result['error']['code']);
        $this->assertSame(404, $result['error']['data']['status']);
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
