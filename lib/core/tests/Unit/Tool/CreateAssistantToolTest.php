<?php

/**
 * The assistant-creation tool.
 *
 * The host is a mock, so what is pinned here is the tool's side of the contract:
 * the title gate, the provider/model resolution rules, the tool-slug hygiene, and
 * the fields it must **not** send — status, `is_default`, slug — because each of
 * those is a decision the writer or an operator owns.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\AssistantWriteInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Tool\CreateAssistantTool;
use PHPUnit\Framework\TestCase;

final class CreateAssistantToolTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    private SettingsStoreInterface $settings;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->settings = $this->createMock(SettingsStoreInterface::class);
        $this->settings->method('getDefaultProvider')->willReturn('openai');
        $this->settings->method('getDefaultModel')->willReturn('gpt-4o');
    }

    public function test_a_title_is_required(): void
    {
        $this->errors->expects($this->once())
            ->method('validationFailed')
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'validation_failed' ) ));

        $result = $this->tool($this->writer())->execute(array( 'title' => '   ' ));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_it_creates_a_draft_and_reports_the_hosts_fields(): void
    {
        $writer = $this->writer(array(
            'success'      => true,
            'assistant_id' => 42,
            'slug'         => 'jamaica-tax-assistant',
            'status'       => 'draft',
        ));

        $result = $this->tool($writer)->execute(array(
            'title'         => 'Jamaica Tax Assistant',
            'system_prompt' => 'You advise on Jamaican tax.',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame('Created assistant "Jamaica Tax Assistant" as a draft.', $result['message']);
        $this->assertSame(42, $result['data']['assistant']['id']);
        $this->assertSame('jamaica-tax-assistant', $result['data']['assistant']['slug']);
        $this->assertSame('draft', $result['data']['assistant']['status']);
    }

    public function test_it_never_sends_status_is_default_or_slug(): void
    {
        $writer = $this->createMock(AssistantWriteInterface::class);

        $writer->expects($this->once())
            ->method('create')
            ->with($this->callback(static function (array $draft): bool {
                return ! array_key_exists('status', $draft)
                    && ! array_key_exists('is_default', $draft)
                    && ! array_key_exists('slug', $draft);
            }))
            ->willReturn(array( 'success' => true, 'assistant_id' => 1, 'slug' => 'x', 'status' => 'draft' ));

        $this->tool($writer)->execute(array( 'title' => 'Ops helper' ));
    }

    public function test_it_defaults_the_provider_and_model_from_settings(): void
    {
        $draft = $this->captureDraft(array( 'title' => 'Ops helper' ));

        $this->assertSame('openai', $draft['provider']);
        $this->assertSame('gpt-4o', $draft['model']);
    }

    public function test_a_non_default_provider_does_not_borrow_the_default_model(): void
    {
        $draft = $this->captureDraft(array( 'title' => 'Ops helper', 'provider' => 'gemini' ));

        $this->assertSame('gemini', $draft['provider']);
        $this->assertArrayNotHasKey('model', $draft, 'The default model belongs to the default provider.');
    }

    public function test_an_unknown_provider_is_refused(): void
    {
        $this->errors->expects($this->once())
            ->method('validationFailed')
            ->with($this->stringContains('Unknown provider'))
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'validation_failed' ) ));

        $result = $this->tool($this->writer(), $this->router(array( 'openai', 'gemini' )))
            ->execute(array( 'title' => 'Ops helper', 'provider' => 'not-a-provider' ));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_an_empty_registry_does_not_reject_a_provider(): void
    {
        // Provider registration has not run yet; rejecting every slug then would
        // make a fresh install unable to create an assistant at all.
        $draft = $this->captureDraft(
            array( 'title' => 'Ops helper', 'provider' => 'gemini' ),
            $this->router(array())
        );

        $this->assertSame('gemini', $draft['provider']);
    }

    public function test_invalid_tool_slugs_are_refused(): void
    {
        $this->errors->expects($this->once())
            ->method('validationFailed')
            ->with($this->stringContains('snake_case'))
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'validation_failed' ) ));

        $result = $this->tool($this->writer())->execute(array(
            'title' => 'Ops helper',
            'tools' => array( 'web_search', 'Not A Slug' ),
        ));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_tools_are_deduplicated(): void
    {
        $draft = $this->captureDraft(array(
            'title' => 'Ops helper',
            'tools' => array( 'web_search', 'web_search', 'get_post' ),
        ));

        $this->assertSame(array( 'web_search', 'get_post' ), $draft['tools']);
    }

    public function test_a_host_without_a_writer_says_so(): void
    {
        $tool = new CreateAssistantTool($this->errors, $this->settings, null, null);

        $result = $tool->execute(array( 'title' => 'Ops helper' ));

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['data']['assistant']);
        $this->assertSame('This host does not accept assistant writes.', $result['message']);
    }

    public function test_a_writer_failure_is_returned_as_an_error(): void
    {
        $this->errors->expects($this->once())
            ->method('create')
            ->with('assistant_create_failed')
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'assistant_create_failed' ) ));

        $result = $this->tool($this->writer(array(
            'success' => false,
            'error'   => 'The assistant could not be created.',
        )))->execute(array( 'title' => 'Ops helper' ));

        $this->assertSame('assistant_create_failed', $result['error']['code']);
    }

    /**
     * Run the tool and return the draft the host received.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function captureDraft(array $arguments, ?ProviderRouter $router = null): array
    {
        $captured = array();

        $writer = $this->createMock(AssistantWriteInterface::class);
        $writer->method('create')->willReturnCallback(
            static function (array $draft) use (&$captured): array {
                $captured = $draft;

                return array( 'success' => true, 'assistant_id' => 1, 'slug' => 'x', 'status' => 'draft' );
            }
        );

        $this->tool($writer, $router)->execute($arguments);

        return $captured;
    }

    /**
     * @param array<string, mixed>|null $result
     */
    private function writer(?array $result = null): AssistantWriteInterface
    {
        $writer = $this->createMock(AssistantWriteInterface::class);
        $writer->method('create')->willReturn(
            $result ?? array( 'success' => true, 'assistant_id' => 1, 'slug' => 'x', 'status' => 'draft' )
        );

        return $writer;
    }

    /**
     * @param list<string> $registered
     */
    private function router(array $registered): ProviderRouter
    {
        $router = $this->createMock(ProviderRouter::class);
        $router->method('getRegisteredSlugs')->willReturn($registered);
        $router->method('has')->willReturnCallback(
            static fn (string $slug): bool => in_array($slug, $registered, true)
        );

        return $router;
    }

    private function tool(AssistantWriteInterface $writer, ?ProviderRouter $router = null): CreateAssistantTool
    {
        return new CreateAssistantTool($this->errors, $this->settings, $writer, $router);
    }
}
