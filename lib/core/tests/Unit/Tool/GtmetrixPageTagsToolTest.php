<?php

/**
 * The GTmetrix page-tag tool.
 *
 * Proposal 164 section 164.98. The cases that matter most are the two the
 * WordPress tool got wrong: `update` created a second tag instead of updating
 * the named one, and attach/detach reported success after discarding every
 * per-page failure.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Error\GtmetrixApiException;
use Nvoos\Core\Tests\Support\FakeGtmetrixClient;
use Nvoos\Core\Tool\GTmetrix\GtmetrixManagePageTagsTool;
use PHPUnit\Framework\TestCase;

final class GtmetrixPageTagsToolTest extends TestCase
{
    private const CONTEXT = array('user_id' => 1);

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

        $this->errors->method('forbidden')->willReturnCallback(
            static fn (string $message = 'Access denied.', array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'forbidden', 'message' => $message, 'data' => $data),
            )
        );
    }

    public function test_the_tags_are_listed(): void
    {
        $result = $this->executeTool(array('action' => 'list'));

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['count']);
        $this->assertSame('TAG1', $result['data']['tags'][0]['tag_id']);
        $this->assertSame('Production', $result['data']['tags'][0]['name']);
        $this->assertSame('FFFFFF', $result['data']['tags'][0]['color']);
    }

    public function test_a_tag_is_created(): void
    {
        $client = $this->client();
        $result = $this->executeTool(
            array(
                'action'      => 'create',
                'name'        => 'Staging',
                'description' => 'Customer facing staging pages',
                'color'       => 'FF8800',
            ),
            $client
        );

        $this->assertTrue($result['success']);
        $this->assertSame('TAG9', $result['data']['tag_id']);
        $this->assertSame('Staging', $result['data']['name']);

        $body = $client->calls[0]['body'];
        $this->assertSame('page_tags', $client->calls[0]['path']);
        $this->assertSame('tag', $body['data']['type']);
        $this->assertSame('FF8800', $body['data']['attributes']['color']);
    }

    public function test_create_requires_a_name(): void
    {
        $client = $this->client();
        $result = $this->executeTool(array('action' => 'create'), $client);

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('name', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_the_name_and_description_limits_are_enforced(): void
    {
        $client = $this->client();

        $longName = $this->executeTool(array('action' => 'create', 'name' => str_repeat('a', 46)), $client);
        $this->assertArrayHasKey('name', $longName['error']['data']);

        $longDesc = $this->executeTool(
            array('action' => 'create', 'name' => 'ok', 'description' => str_repeat('a', 256)),
            $client
        );
        $this->assertArrayHasKey('description', $longDesc['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_a_colour_must_be_six_hex_digits(): void
    {
        $client = $this->client();

        foreach (array('#FF8800', 'FFF', 'GGGGGG') as $color) {
            $result = $this->executeTool(array('action' => 'create', 'name' => 'ok', 'color' => $color), $client);

            $this->assertArrayHasKey('color', $result['error']['data'], $color);
        }

        $this->assertSame(array(), $client->calls);
    }

    public function test_update_posts_to_the_tag_endpoint(): void
    {
        $client = $this->client();
        $result = $this->executeTool(
            array('action' => 'update', 'tag_id' => 'TAG1', 'color' => '00FF00'),
            $client
        );

        $this->assertTrue($result['success']);
        $this->assertSame('page_tags/TAG1', $client->calls[0]['path']);
        $this->assertSame('00FF00', $client->calls[0]['body']['data']['attributes']['color']);
        $this->assertArrayNotHasKey('name', $client->calls[0]['body']['data']['attributes']);
        $this->assertSame('TAG1', $result['data']['tag_id']);
        $this->assertSame('00FF00', $result['data']['color']);
    }

    public function test_update_requires_a_tag_id_and_at_least_one_attribute(): void
    {
        $client = $this->client();

        $noId = $this->executeTool(array('action' => 'update', 'name' => 'Renamed'), $client);
        $this->assertArrayHasKey('tag_id', $noId['error']['data']);

        $nothing = $this->executeTool(array('action' => 'update', 'tag_id' => 'TAG1'), $client);
        $this->assertSame('validation_failed', $nothing['error']['code']);

        $this->assertSame(array(), $client->calls);
    }

    public function test_pages_are_attached_in_one_request(): void
    {
        $client = $this->client();
        $result = $this->executeTool(
            array('action' => 'attach', 'tag_id' => 'TAG1', 'page_ids' => array('PAGE1', 'PAGE2')),
            $client
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $client->calls);
        $this->assertSame('page_tags/TAG1/attach', $client->calls[0]['path']);
        $this->assertSame(array('PAGE1', 'PAGE2'), $client->calls[0]['body']['page_ids']);
        $this->assertSame('attach', $result['data']['action']);
        $this->assertSame(2, $result['data']['pages']);
    }

    public function test_pages_are_detached(): void
    {
        $client = $this->client();
        $result = $this->executeTool(
            array('action' => 'detach', 'tag_id' => 'TAG1', 'page_ids' => array('PAGE1')),
            $client
        );

        $this->assertSame('page_tags/TAG1/detach', $client->calls[0]['path']);
        $this->assertSame('detach', $result['data']['action']);
    }

    public function test_an_invalid_page_id_refuses_the_whole_call(): void
    {
        $client = $this->client();
        $result = $this->executeTool(
            array('action' => 'attach', 'tag_id' => 'TAG1', 'page_ids' => array('PAGE1', '../etc/passwd')),
            $client
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('page_ids[1]', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_attach_needs_one_to_five_hundred_pages(): void
    {
        $client = $this->client();

        $none = $this->executeTool(array('action' => 'attach', 'tag_id' => 'TAG1', 'page_ids' => array()), $client);
        $this->assertArrayHasKey('page_ids', $none['error']['data']);

        $tooMany = $this->executeTool(
            array('action' => 'attach', 'tag_id' => 'TAG1', 'page_ids' => array_fill(0, 501, 'PAGE1')),
            $client
        );
        $this->assertArrayHasKey('page_ids', $tooMany['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_an_unknown_action_is_refused(): void
    {
        $client = $this->client();
        $result = $this->executeTool(array('action' => 'delete', 'tag_id' => 'TAG1'), $client);

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('action', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_a_tag_limit_is_classified(): void
    {
        $client                    = $this->client();
        $client->failOn['POST page_tags'] = new GtmetrixApiException(
            'Tag limit reached for your plan.',
            'gtmetrix_plan_denied',
            403,
            'E40303'
        );

        $result = $this->executeTool(array('action' => 'create', 'name' => 'One more'), $client);

        $this->assertSame('gtmetrix_plan_denied', $result['error']['code']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function executeTool(array $arguments, ?FakeGtmetrixClient $client = null): mixed
    {
        return ( new GtmetrixManagePageTagsTool($this->errors, $client ?? $this->client()) )
            ->execute($arguments, self::CONTEXT);
    }

    private function client(): FakeGtmetrixClient
    {
        $client            = new FakeGtmetrixClient();
        $client->responses = array(
            'GET page_tags'          => array(
                'data' => array(
                    array(
                        'type'       => 'tag',
                        'id'         => 'TAG1',
                        'attributes' => array('name' => 'Production', 'description' => 'Live pages', 'color' => 'FFFFFF'),
                    ),
                    array(
                        'type'       => 'tag',
                        'id'         => 'TAG2',
                        'attributes' => array('name' => 'Staging', 'color' => 'FF0000'),
                    ),
                    'not-a-tag',
                ),
            ),
            'POST page_tags'         => array(
                'status' => 201,
                'data'   => array(
                    'type'       => 'tag',
                    'id'         => 'TAG9',
                    'attributes' => array('name' => 'Staging', 'description' => 'Customer facing staging pages', 'color' => 'FF8800'),
                ),
            ),
            'POST page_tags/TAG1'    => array(
                'data' => array(
                    'type'       => 'tag',
                    'id'         => 'TAG1',
                    'attributes' => array('name' => 'Production', 'color' => '00FF00'),
                ),
            ),
            'POST page_tags/TAG1/attach' => array(
                'data' => array('type' => 'tag', 'id' => 'TAG1', 'attributes' => array('name' => 'Production')),
            ),
            'POST page_tags/TAG1/detach' => array(
                'data' => array('type' => 'tag', 'id' => 'TAG1', 'attributes' => array('name' => 'Production')),
            ),
        );

        return $client;
    }
}
