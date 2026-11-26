<?php

/**
 * The assistant-import tool.
 *
 * The host is a mock, so what is pinned here is the tool's side of the contract:
 * the payload gate, the mode that is refused rather than coerced, the dry-run
 * passthrough, and the two failure classes a caller acts differently on.
 *
 * The restore itself — conflict matching, the denylist, status fidelity — belongs
 * to the engine and is pinned in the app suite.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\AssistantImportInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Tool\ImportAssistantTool;
use PHPUnit\Framework\TestCase;

final class ImportAssistantToolTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);
    }

    public function test_a_bundle_document_is_required(): void
    {
        $importer = $this->createMock(AssistantImportInterface::class);
        $importer->expects($this->never())->method('import');

        $this->errors->expects($this->once())
            ->method('validationFailed')
            ->with($this->anything(), array( 'json' => 'Required.' ))
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'validation_failed' ) ));

        $result = $this->tool($importer)->execute(array( 'json' => '   ' ));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_a_payload_over_the_ceiling_is_refused(): void
    {
        $importer = $this->createMock(AssistantImportInterface::class);
        $importer->expects($this->never())->method('import');

        $this->errors->expects($this->once())
            ->method('validationFailed')
            ->with($this->stringContains('over the 2097152-byte limit'))
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'validation_failed' ) ));

        $result = $this->tool($importer)->execute(array(
            'json' => \str_repeat('a', 2097153),
        ));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_an_unknown_mode_is_refused_rather_than_coerced(): void
    {
        $importer = $this->createMock(AssistantImportInterface::class);

        // The engine would fall back to `skip`, and a caller that asked to
        // overwrite must never be told it did.
        $importer->expects($this->never())->method('import');

        $this->errors->expects($this->once())
            ->method('validationFailed')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('"merge"'),
                    $this->stringContains('skip, duplicate or overwrite')
                )
            )
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'validation_failed' ) ));

        $result = $this->tool($importer)->execute(array( 'json' => '{}', 'mode' => 'merge' ));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_skip_is_the_default_mode(): void
    {
        $importer = $this->createMock(AssistantImportInterface::class);

        $importer->expects($this->once())
            ->method('import')
            ->with('{"format":"nvoos-assistant"}', 'skip', false)
            ->willReturn($this->report());

        $this->tool($importer)->execute(array( 'json' => '{"format":"nvoos-assistant"}' ));
    }

    public function test_the_mode_is_case_insensitive_and_writes_are_not_dry(): void
    {
        $importer = $this->createMock(AssistantImportInterface::class);

        $importer->expects($this->once())
            ->method('import')
            ->with('{}', 'overwrite', false)
            ->willReturn($this->report(array( 'updated' => 1 )));

        $this->tool($importer)->execute(array( 'json' => '{}', 'mode' => 'OVERWRITE' ));
    }

    public function test_dry_run_is_forwarded_and_reported_in_the_message(): void
    {
        $importer = $this->createMock(AssistantImportInterface::class);

        $importer->expects($this->once())
            ->method('import')
            ->with('{}', 'skip', true)
            ->willReturn($this->report(array( 'imported' => 2 )));

        $result = $this->tool($importer)->execute(array( 'json' => '{}', 'dry_run' => true ));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['dry_run']);
        $this->assertSame(2, $result['data']['imported']);
        $this->assertStringStartsWith('Dry run: ', $result['message']);
    }

    public function test_an_invalid_bundle_is_a_validation_failure_carrying_the_host_reason(): void
    {
        $importer = $this->createMock(AssistantImportInterface::class);
        $importer->method('import')->willReturn(array(
            'success' => false,
            'code'    => 'bundle_invalid',
            'error'   => 'The payload is not an nvoos-assistant bundle.',
        ));

        $this->errors->expects($this->once())
            ->method('validationFailed')
            ->with($this->stringContains('not an nvoos-assistant bundle'))
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'validation_failed' ) ));

        $result = $this->tool($importer)->execute(array( 'json' => 'nope' ));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_a_host_failure_is_reported_as_an_import_failure(): void
    {
        $importer = $this->createMock(AssistantImportInterface::class);
        $importer->method('import')->willReturn(array(
            'success' => false,
            'code'    => 'assistant_import_failed',
            'error'   => 'The bundle could not be imported.',
        ));

        $this->errors->expects($this->once())
            ->method('create')
            ->with('assistant_import_failed', $this->stringContains('could not be imported'))
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'assistant_import_failed' ) ));

        $result = $this->tool($importer)->execute(array( 'json' => '{}' ));

        $this->assertSame('assistant_import_failed', $result['error']['code']);
    }

    public function test_a_host_without_an_importer_says_so(): void
    {
        $this->errors->expects($this->once())
            ->method('create')
            ->with('assistant_import_unavailable', $this->anything())
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'assistant_import_unavailable' ) ));

        $result = (new ImportAssistantTool($this->errors))->execute(array( 'json' => '{}' ));

        $this->assertSame('assistant_import_unavailable', $result['error']['code']);
    }

    public function test_it_requires_the_administrative_capability_and_declares_no_reversal(): void
    {
        $tool = $this->tool($this->createMock(AssistantImportInterface::class));

        // `overwrite` replaces an assistant, which is why this is not the
        // `edit_posts` that create_assistant needs.
        $this->assertSame('manage_options', $tool->getRequiredCapability());

        $this->assertContains('write', $tool->getCapabilityFlags());
        $this->assertContains('state-changing', $tool->getCapabilityFlags());
        $this->assertContains('requires-capability', $tool->getCapabilityFlags());

        // Nothing here keeps a copy of what `overwrite` destroys.
        $this->assertNotContains('reversible', $tool->getCapabilityFlags());
    }

    public function test_the_document_is_not_content_sanitised(): void
    {
        $importer = $this->createMock(AssistantImportInterface::class);

        // A system prompt may legitimately contain HTML. Stripping tags from the
        // whole JSON string would rewrite it, so the payload must arrive at the
        // engine byte-for-byte (bar the trimmed edges).
        $document = '{"assistants":[{"content":"<b>bold</b> and <script>x()</script>"}]}';

        $importer->expects($this->once())
            ->method('import')
            ->with($document, 'skip', false)
            ->willReturn($this->report());

        $this->tool($importer)->execute(array( 'json' => $document ));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array{success: bool, report: array<string, mixed>}
     */
    private function report(array $overrides = array()): array
    {
        return array(
            'success' => true,
            'report'  => array_merge(
                array(
                    'dry_run'    => false,
                    'imported'   => 0,
                    'updated'    => 0,
                    'skipped'    => 0,
                    'assistants' => array(),
                ),
                $overrides
            ),
        );
    }

    private function tool(AssistantImportInterface $importer): ImportAssistantTool
    {
        return new ImportAssistantTool($this->errors, $importer);
    }
}
