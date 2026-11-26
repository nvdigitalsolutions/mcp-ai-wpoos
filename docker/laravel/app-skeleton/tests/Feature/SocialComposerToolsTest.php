<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Nvoos\Core\Domain\Contract\SocialComposerInterface;
use Nvoos\Core\Tool\GeneratePostIdeasTool;
use Nvoos\Core\Tool\GenerateSocialCaptionsTool;
use Nvoos\Core\Tool\GetSocialAccountsTool;
use Nvoos\Laravel\Adapter\MediaWorkerSocialComposer;
use RuntimeException;
use Tests\TestCase;

/**
 * The read-only social half reaches the worker — Proposal 164 §164.4.6.
 *
 * Three things are pinned here, and the second is the reason the file exists:
 *
 *  1. **The contract has no publish method.** Publishing is an irreversible send
 *     and belongs behind the necessity guard, not one adapter call away.
 *  2. **A template fallback is not copy.** The worker answers finished text when
 *     a model resolves and `prompt_templates` when none does. Rendering a prompt
 *     as a caption would report success for work not done.
 *  3. **`connected` is a credential fact.** It says a token is configured, never
 *     that it works.
 *
 * The worker is faked; its own routes are exercised by
 * `addons/media-worker/src/routes/social.test.js`.
 */
final class SocialComposerToolsTest extends TestCase
{
    /**
     * The matrix a current worker publishes.
     */
    private const CAPABILITIES = array(
        'social_accounts' => true,
        'social_composition' => true,
        'social_publishing' => true,
    );

    protected function setUp(): void
    {
        parent::setUp();

        config(['oos.media_worker.url' => 'http://worker.test']);

        Cache::flush();
    }

    public function test_the_contract_resolves_to_the_worker_adapter(): void
    {
        $adapter = $this->app->make(SocialComposerInterface::class);

        $this->assertInstanceOf(MediaWorkerSocialComposer::class, $adapter);
        $this->assertTrue($adapter->isAvailable());
    }

    public function test_the_contract_cannot_publish(): void
    {
        // A publish method appearing on this interface would bypass the approval
        // gate entirely, because the guard keys off capability flags and the
        // interface is what a future adapter would implement.
        $this->assertFalse(
            method_exists(SocialComposerInterface::class, 'publish'),
            'SocialComposerInterface must not publish: publishing is approval-gated.'
        );
    }

    public function test_accounts_reports_which_channels_have_credentials(): void
    {
        $captured = null;
        $this->fakeWorker(self::CAPABILITIES, $captured);

        $result = $this->app->make(GetSocialAccountsTool::class)->execute(array());

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('/api/social/accounts', $this->path($captured));
        $this->assertSame(array('twitter', 'linkedin'), $result['data']['connected']);
        $this->assertSame(4, $result['data']['total']);

        $instagram = null;

        foreach ($result['data']['platforms'] as $row) {
            if ('instagram' === $row['id']) {
                $instagram = $row;
            }
        }

        $this->assertNotNull($instagram);
        $this->assertFalse($instagram['connected']);
        $this->assertSame(2200, $instagram['max_chars']);
    }

    public function test_captions_return_finished_copy_when_a_model_answered(): void
    {
        $captured = null;
        $this->fakeWorker(self::CAPABILITIES, $captured, 'openai');

        $result = $this->app->make(GenerateSocialCaptionsTool::class)->execute(array(
            'topic' => 'a spring collection launch',
            'tone' => 'casual',
            'count' => 2,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('/api/social/generate-content', $this->path($captured));
        $this->assertSame('a spring collection launch', $this->part($captured, 'topic'));
        $this->assertSame('casual', $this->part($captured, 'tone'));
        $this->assertSame('2', $this->part($captured, 'count'));
        $this->assertSame('openai', $result['data']['provider']);
        $this->assertCount(1, $result['data']['posts']);
        $this->assertSame(array(), $result['data']['prompts']);
        $this->assertSame('#SpringLaunch', $result['data']['suggested_hashtags'][0]);
        $this->assertStringContainsString('caption variant', $result['message']);
    }

    public function test_captions_report_prompt_templates_rather_than_pretending_they_are_copy(): void
    {
        $captured = null;
        $this->fakeWorker(self::CAPABILITIES, $captured, 'template');

        $result = $this->app->make(GenerateSocialCaptionsTool::class)->execute(array(
            'topic' => 'a spring collection launch',
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('template', $result['data']['provider']);
        $this->assertSame(array(), $result['data']['posts'], 'a template is not a caption');
        $this->assertArrayHasKey('twitter', $result['data']['prompts']);
        $this->assertStringContainsString('OPENAI_API_KEY', (string) $result['data']['note']);
        $this->assertStringContainsString('prompt template', $result['message']);
    }

    public function test_post_ideas_fold_every_context_field_into_the_subject(): void
    {
        $captured = null;
        $this->fakeWorker(self::CAPABILITIES, $captured, 'openai');

        $result = $this->app->make(GeneratePostIdeasTool::class)->execute(array(
            'industry' => 'artisan bakery',
            'topics' => array('sourdough', 'seasonal pastries'),
            'target_audience' => 'new parents',
            'content_types' => array('poll', 'behind-the-scenes'),
            'brand_voice' => 'friendly',
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);

        $subject = (string) $this->part($captured, 'topic');

        // None of the four inputs is dropped: the composer takes one subject, so
        // the tool has to carry them into it.
        $this->assertStringContainsString('artisan bakery', $subject);
        $this->assertStringContainsString('sourdough', $subject);
        $this->assertStringContainsString('new parents', $subject);
        $this->assertStringContainsString('poll', $subject);
        $this->assertSame('friendly', $this->part($captured, 'tone'));
        $this->assertSame($subject, $result['data']['subject']);
    }

    public function test_post_ideas_do_not_send_a_channel_the_worker_cannot_compose_for(): void
    {
        $captured = null;
        $this->fakeWorker(self::CAPABILITIES, $captured, 'openai');

        $result = $this->app->make(GeneratePostIdeasTool::class)->execute(array(
            'industry' => 'artisan bakery',
            'platform' => 'pinterest',
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertNull($this->part($captured, 'platform'), 'pinterest has no character limit here');
        $this->assertFalse($result['data']['composable']);
        $this->assertSame('pinterest', $result['data']['requested_platform']);
        $this->assertStringContainsString('not a channel this worker composes for', $result['message']);
    }

    public function test_a_worker_without_the_read_routes_refuses_by_name(): void
    {
        $matrix = self::CAPABILITIES;
        $matrix['social_accounts'] = false;

        $captured = null;
        $this->fakeWorker($matrix, $captured);

        $result = $this->app->make(GetSocialAccountsTool::class)->execute(array());

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('social_accounts', $result->getMessage());
    }

    public function test_an_empty_topic_is_refused_before_the_worker_is_called(): void
    {
        Http::fake();

        $result = $this->app->make(GenerateSocialCaptionsTool::class)->execute(array(
            'topic' => '   ',
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        Http::assertNothingSent();
    }

    public function test_a_worker_error_is_reported_as_compose_failure(): void
    {
        $json = array('Content-Type' => 'application/json');
        $health = Http::response(array('status' => 'ok', 'capabilities' => self::CAPABILITIES), 200, $json);
        $boom = Http::response(array('error' => 'topic too long'), 500, $json);

        Http::fake(function (ClientRequest $request) use ($health, $boom) {
            return str_contains($request->url(), '/api/health/full') ? $health : $boom;
        });

        $result = $this->app->make(GenerateSocialCaptionsTool::class)->execute(array(
            'topic' => 'a topic',
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('topic too long', $result->getMessage());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Fake the worker's matrix plus both social read routes.
     *
     * Responses are pre-built: constructing one inside the stub re-enters the
     * factory while a request is dispatching, and Laravel's stub recorder does
     * not survive that — the caller silently receives an empty body.
     *
     * @param array<string, bool>                                    $capabilities
     * @param array{url: string, data: mixed}|null                   $captured
     */
    private function fakeWorker(array $capabilities, ?array &$captured, string $provider = 'openai'): void
    {
        $captured = null;

        $json = array('Content-Type' => 'application/json');

        $health = Http::response(array(
            'status' => 'ok',
            'version' => '3.4.0',
            'capabilities' => $capabilities,
        ), 200, $json);

        $accounts = Http::response(array(
            'platforms' => array(
                array('id' => 'twitter', 'name' => 'Twitter / X', 'connected' => true, 'max_chars' => 280),
                array('id' => 'facebook', 'name' => 'Facebook', 'connected' => false, 'max_chars' => 63206),
                array('id' => 'instagram', 'name' => 'Instagram', 'connected' => false, 'max_chars' => 2200),
                array('id' => 'linkedin', 'name' => 'LinkedIn', 'connected' => true, 'max_chars' => 3000),
            ),
        ), 200, $json);

        $generated = 'openai' === $provider
            ? Http::response(array(
                'success' => true,
                'topic' => 'a spring collection launch',
                'tone' => 'professional',
                'generated_by' => 'openai',
                'language' => 'en',
                'suggested_hashtags' => array(
                    'design' => array('#SpringLaunch', '#NewCollection'),
                    'general' => array('#SpringLaunch'),
                ),
                'content' => array(
                    array(
                        'platform' => 'twitter',
                        'character_limit' => 280,
                        'variants' => array('A first caption.', 'A second caption.'),
                    ),
                ),
            ), 200, $json)
            : Http::response(array(
                'success' => true,
                'topic' => 'a spring collection launch',
                'tone' => 'professional',
                'platform' => 'all',
                'character_limit' => array('twitter' => 280),
                'suggested_hashtags' => array('general' => array('#SpringLaunch')),
                'generated_by' => 'template',
                'tip' => 'Set OPENAI_API_KEY in .env for AI-generated content.',
                'prompt_templates' => array(
                    'twitter' => 'Write a professional tweet about "a spring collection launch". Under 280 chars.',
                ),
            ), 200, $json);

        Http::fake(function (ClientRequest $request) use (&$captured, $health, $accounts, $generated) {
            $url = $request->url();

            if (str_contains($url, '/api/health/full')) {
                return $health;
            }

            $captured = array('url' => $url, 'data' => $request->data());

            return str_contains($url, '/api/social/accounts') ? $accounts : $generated;
        });
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
     * One field's value from either request shape.
     *
     * `/api/social/accounts` is a GET with no body, `/api/social/generate-content`
     * is a JSON POST, and the image and document routes are multipart. `data()`
     * answers a list of `{name, contents}` for multipart and the decoded body
     * otherwise, so one helper normalises both rather than each test knowing
     * which verb it used.
     *
     * @param array{url: string, data: mixed}|null $captured
     */
    private function part(?array $captured, string $name): ?string
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
