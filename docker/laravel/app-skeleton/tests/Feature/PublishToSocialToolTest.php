<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Nvoos\Core\Domain\Contract\SocialPublisherInterface;
use Nvoos\Core\Tool\PublishToSocialTool;
use Nvoos\Laravel\Adapter\MediaWorkerSocialPublisher;
use RuntimeException;
use Tests\TestCase;

/**
 * Publishing reaches the worker, and refuses legibly — Proposal 164 §164.4.6.
 *
 * The approval gate lives in `PublishToSocialApprovalTest` (lib/core), because it
 * is a necessity-gate concern. This file covers the other four refusals, each of
 * which happens **before** the network call on purpose:
 *
 *  - an unknown platform is named with the list the worker actually has;
 *  - a channel with no credential is named before the platform's opaque 401;
 *  - copy over the channel limit is refused with the limit;
 *  - a schedule request is refused, because a scheduled post fires with no human
 *    present.
 *
 * `dry_run` defaults to true, so the happy path here has to ask for the real
 * thing explicitly.
 */
final class PublishToSocialToolTest extends TestCase
{
    private const CAPABILITIES = array(
        'social_publishing' => true,
        'social_accounts' => true,
        'social_composition' => true,
    );

    protected function setUp(): void
    {
        parent::setUp();

        config(['oos.media_worker.url' => 'http://worker.test']);

        Cache::flush();
    }

    public function test_the_contract_resolves_to_the_worker_adapter(): void
    {
        $adapter = $this->app->make(SocialPublisherInterface::class);

        $this->assertInstanceOf(MediaWorkerSocialPublisher::class, $adapter);
        $this->assertTrue($adapter->isAvailable());
    }

    public function test_a_dry_run_is_the_default_and_sends_nothing(): void
    {
        Http::fake();

        $result = $this->app->make(PublishToSocialTool::class)->execute(array(
            'platform' => 'linkedin',
            'content' => 'A sentence about the new collection.',
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['dry_run']);
        $this->assertSame('linkedin', $result['data']['platform']);
        $this->assertFalse($result['data']['link_appended']);
        Http::assertNothingSent();
    }

    public function test_a_dry_run_shows_the_link_being_appended_to_the_copy(): void
    {
        Http::fake();

        $result = $this->app->make(PublishToSocialTool::class)->execute(array(
            'platform' => 'twitter',
            'content' => 'A sentence about the new collection.',
            'link_url' => 'https://example.test/collection',
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['data']['link_appended']);
        $this->assertStringContainsString(
            "A sentence about the new collection.\n\nhttps://example.test/collection",
            $result['data']['content']
        );
    }

    public function test_a_real_publish_sends_the_body_media_and_hashtags(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $result = $this->app->make(PublishToSocialTool::class)->execute(array(
            'platform' => 'linkedin',
            'content' => 'A sentence about the new collection.',
            'image_url' => 'https://example.test/hero.jpg',
            'hashtags' => array('SpringLaunch', '#NewCollection'),
            'dry_run' => false,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['dry_run']);
        $this->assertSame('/api/social/post', $this->path($captured));
        $this->assertSame('linkedin', $this->field($captured, 'platform'));
        $this->assertSame('https://example.test/hero.jpg', $this->field($captured, 'media_url'));
        $this->assertSame('image', $this->field($captured, 'media_type'));
        $this->assertSame('#SpringLaunch #NewCollection', $this->field($captured, 'hashtags'));
        $this->assertNull($this->field($captured, 'schedule'), 'scheduling must never be sent');
        $this->assertSame('urn:li:share:123', $result['data']['post_id']);
    }

    public function test_a_channel_without_credentials_is_named_before_the_call(): void
    {
        $captured = null;
        $posting = $this->fakeWorker($captured);

        $result = $this->app->make(PublishToSocialTool::class)->execute(array(
            'platform' => 'instagram',
            'content' => 'A sentence about the new collection.',
            'dry_run' => false,
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('Instagram has no credentials configured', $result->getMessage());
        $this->assertFalse($posting->called, 'an uncredentialed channel must not be called at all');
    }

    public function test_an_unknown_platform_is_named_with_the_workers_own_list(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $result = $this->app->make(PublishToSocialTool::class)->execute(array(
            'platform' => 'tiktok',
            'content' => 'A sentence about the new collection.',
            'dry_run' => false,
        ));

        // tiktok is in the WordPress enum and not in this worker's, so the schema
        // refuses it before the adapter is asked.
        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('Unknown platform: tiktok', $result->getMessage());
    }

    public function test_copy_over_the_channel_limit_is_refused_with_the_limit(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $result = $this->app->make(PublishToSocialTool::class)->execute(array(
            'platform' => 'twitter',
            'content' => str_repeat('a', 281),
            'dry_run' => false,
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('at most 280 characters', $result->getMessage());
    }

    public function test_scheduling_is_refused_rather_than_silently_sent(): void
    {
        $captured = null;
        $posting = $this->fakeWorker($captured);

        $adapter = $this->app->make(SocialPublisherInterface::class);

        try {
            $adapter->publish(array(
                'platform' => 'linkedin',
                'content' => 'A sentence about the new collection.',
                'schedule' => '2030-01-01T09:00:00Z',
            ));
            $this->fail('A scheduled publish must be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Scheduling is not offered', $e->getMessage());
        }

        $this->assertFalse($posting->called);
    }

    public function test_a_worker_without_the_publish_route_refuses_by_name(): void
    {
        $matrix = self::CAPABILITIES;
        $matrix['social_publishing'] = false;

        $captured = null;
        $this->fakeWorker($captured, $matrix);

        $result = $this->app->make(PublishToSocialTool::class)->execute(array(
            'platform' => 'linkedin',
            'content' => 'A sentence about the new collection.',
            'dry_run' => false,
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('social_publishing', $result->getMessage());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Fake the channel list plus the publish route.
     *
     * Returns a by-reference flag that turns true if `/api/social/post` is ever
     * called — the cleanest way to prove a refusal happened *before* the network.
     *
     * @param array{url: string, data: mixed}|null $captured
     * @param array<string, bool>                  $capabilities
     */
    private function fakeWorker(?array &$captured, array $capabilities = self::CAPABILITIES): object
    {
        $captured = null;
        $posted = new \stdClass();
        $posted->called = false;

        $json = array('Content-Type' => 'application/json');

        $health = Http::response(array(
            'status' => 'ok',
            'version' => '3.4.0',
            'capabilities' => $capabilities,
        ), 200, $json);

        $accounts = Http::response(array(
            'platforms' => array(
                array('id' => 'twitter', 'name' => 'Twitter / X', 'connected' => true, 'max_chars' => 280),
                array('id' => 'facebook', 'name' => 'Facebook', 'connected' => true, 'max_chars' => 63206),
                array('id' => 'instagram', 'name' => 'Instagram', 'connected' => false, 'max_chars' => 2200),
                array('id' => 'linkedin', 'name' => 'LinkedIn', 'connected' => true, 'max_chars' => 3000),
            ),
        ), 200, $json);

        $published = Http::response(array(
            'success' => true,
            'platform' => 'linkedin',
            'post_id' => 'urn:li:share:123',
            'url' => 'https://linkedin.com/feed/update/urn:li:share:123',
        ), 200, $json);

        Http::fake(function (ClientRequest $request) use (&$captured, $posted, $health, $accounts, $published) {
            $url = $request->url();

            // `/api/health` is the liveness probe and `/api/health/full` is the
            // capability matrix. Both are health, neither is a publish, and the
            // probe calls them in that order.
            if (str_contains($url, '/api/health')) {
                return $health;
            }

            if (str_contains($url, '/api/social/accounts')) {
                return $accounts;
            }

            $captured = array('url' => $url, 'data' => $request->data());
            $posted->called = true;

            return $published;
        });

        return $posted;
    }

    /**
     * @param array{url: string, data: mixed}|null $captured
     */
    private function path(?array $captured): string
    {
        $path = parse_url((string) ($captured['url'] ?? ''), PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }

    /**
     * One field from a JSON body or a multipart part list.
     *
     * @param array{url: string, data: mixed}|null $captured
     */
    private function field(?array $captured, string $name): ?string
    {
        $data = $captured['data'] ?? array();

        if (is_string($data)) {
            $decoded = json_decode($data, true);
            $data = is_array($decoded) ? $decoded : array();
        }

        foreach ((array) $data as $value) {
            if (is_array($value) && array_key_exists('name', $value) && (string) $value['name'] === $name) {
                $contents = $value['contents'] ?? null;

                return is_scalar($contents) ? (string) $contents : null;
            }
        }

        if (is_array($data) && array_key_exists($name, $data)) {
            $value = $data[$name];

            return is_scalar($value) ? (string) $value : null;
        }

        return null;
    }
}
