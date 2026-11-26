<?php

/**
 * The assistant-export tool.
 *
 * The host is a mock, so what is pinned here is the tool's side of the contract:
 * the dialect it refuses, the id-list it forwards, the byte ceiling that keeps a
 * bundle out of a context window it would blow, and the failure codes it maps.
 *
 * The engine's redaction is a separate concern and is pinned where it lives, in
 * the app suite — this file only asserts that the tool does not reshape what the
 * host returned.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\AssistantExportInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Tool\ExportAssistantTool;
use PHPUnit\Framework\TestCase;

final class ExportAssistantToolTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);
    }

    public function test_it_exports_every_assistant_when_no_ids_are_given(): void
    {
        $exporter = $this->createMock(AssistantExportInterface::class);

        // Null, not `array()`: "every assistant" is the host's contract, and an
        // empty list would mean something different if ids became mandatory.
        $exporter->expects($this->once())
            ->method('export')
            ->with(null)
            ->willReturn($this->bundleOf(2));

        $result = $this->tool($exporter)->execute(array());

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['count']);
        $this->assertSame(2, \count($result['data']['bundle']['assistants']));
    }

    public function test_an_explicit_id_list_is_forwarded_unchanged(): void
    {
        $exporter = $this->createMock(AssistantExportInterface::class);

        $exporter->expects($this->once())
            ->method('export')
            ->with(array( 4, 7 ))
            ->willReturn($this->bundleOf(2));

        $this->tool($exporter)->execute(array( 'assistant_ids' => array( 4, 7 ) ));
    }

    public function test_an_empty_id_list_means_every_assistant(): void
    {
        $exporter = $this->createMock(AssistantExportInterface::class);

        $exporter->expects($this->once())
            ->method('export')
            ->with(null)
            ->willReturn($this->bundleOf(1));

        $this->tool($exporter)->execute(array( 'assistant_ids' => array() ));
    }

    public function test_the_supported_dialect_is_accepted_case_insensitively(): void
    {
        $exporter = $this->createMock(AssistantExportInterface::class);
        $exporter->method('export')->willReturn($this->bundleOf(1));

        $result = $this->tool($exporter)->execute(array( 'format' => 'JSON' ));

        $this->assertTrue($result['success']);
    }

    public function test_an_unsupported_dialect_is_refused_and_names_why(): void
    {
        $exporter = $this->createMock(AssistantExportInterface::class);

        // Never reached: a dialect this runtime cannot produce must not become a
        // bundle that looks like the thing that was asked for.
        $exporter->expects($this->never())->method('export');

        $this->errors->expects($this->once())
            ->method('validationFailed')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('a2a'),
                    $this->stringContains('no A2A agent card')
                )
            )
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'validation_failed' ) ));

        $result = $this->tool($exporter)->execute(array( 'format' => 'a2a' ));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_an_unknown_id_is_reported_as_not_found(): void
    {
        $exporter = $this->createMock(AssistantExportInterface::class);
        $exporter->method('export')->willReturn(array(
            'success' => false,
            'code'    => 'assistant_not_found',
            'error'   => 'These assistant ids are not in this estate: 99.',
        ));

        // The same status `duplicate_assistant` uses for a foreign id. Two tools
        // disagreeing here would be an oracle built out of their difference.
        $this->errors->expects($this->once())
            ->method('notFound')
            ->with($this->stringContains('not in this estate'))
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'not_found' ) ));

        $result = $this->tool($exporter)->execute(array( 'assistant_ids' => array( 99 ) ));

        $this->assertSame('not_found', $result['error']['code']);
    }

    public function test_a_host_failure_is_reported_as_an_export_failure(): void
    {
        $exporter = $this->createMock(AssistantExportInterface::class);
        $exporter->method('export')->willReturn(array(
            'success' => false,
            'code'    => 'assistant_export_failed',
            'error'   => 'The assistants could not be exported.',
        ));

        $this->errors->expects($this->once())
            ->method('create')
            ->with('assistant_export_failed', $this->stringContains('could not be exported'))
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'assistant_export_failed' ) ));

        $result = $this->tool($exporter)->execute(array());

        $this->assertSame('assistant_export_failed', $result['error']['code']);
    }

    public function test_a_host_without_an_exporter_says_so(): void
    {
        $this->errors->expects($this->once())
            ->method('create')
            ->with('assistant_export_unavailable', $this->anything())
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'assistant_export_unavailable' ) ));

        $result = (new ExportAssistantTool($this->errors))->execute(array());

        $this->assertSame('assistant_export_unavailable', $result['error']['code']);
    }

    public function test_a_bundle_over_the_inline_ceiling_is_refused_with_a_batch_hint(): void
    {
        $exporter = $this->createMock(AssistantExportInterface::class);
        $exporter->method('export')->willReturn($this->bundleOf(1, 300000));

        $this->errors->expects($this->once())
            ->method('validationFailed')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('over the 262144-byte limit'),
                    $this->stringContains('assistant_ids')
                )
            )
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'validation_failed' ) ));

        $result = $this->tool($exporter)->execute(array());

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_it_declares_a_read_capability(): void
    {
        $tool = $this->tool($this->createMock(AssistantExportInterface::class));

        $this->assertContains('read-only', $tool->getCapabilityFlags());
        $this->assertContains('local-only', $tool->getCapabilityFlags());
        $this->assertContains('requires-capability', $tool->getCapabilityFlags());

        // Not `cacheable`: an assistant created a second ago must appear in the
        // next export.
        $this->assertNotContains('cacheable', $tool->getCapabilityFlags());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());
    }

    /**
     * A bundle whose one assistant carries a deliberately large system prompt.
     *
     * @return array{success: bool, bundle: array<string, mixed>}
     */
    private function bundleOf(int $count, int $pad = 0): array
    {
        $assistants = array();

        for ($i = 1; $i <= $count; ++$i) {
            $assistants[] = array(
                'title'   => 'Assistant ' . $i,
                'slug'    => 'assistant-' . $i,
                'status'  => 'draft',
                'content' => \str_repeat('x', $pad),
                'meta'    => array( 'provider' => 'openai' ),
            );
        }

        return array(
            'success' => true,
            'bundle'  => array(
                'format'         => 'nvoos-assistant',
                'format_version' => 1,
                'plugin_version' => '1.2.0',
                'exported_at'    => '2026-11-23T00:00:00+00:00',
                'assistants'     => $assistants,
            ),
        );
    }

    private function tool(AssistantExportInterface $exporter): ExportAssistantTool
    {
        return new ExportAssistantTool($this->errors, $exporter);
    }
}
