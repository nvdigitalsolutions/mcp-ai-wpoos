<?php

/**
 * The assistant-duplication tool.
 *
 * The host is a mock, so what is pinned here is the tool's side of the contract:
 * the id gate, the `assistant_not_found` mapping (a 404, not a generic failure),
 * and the fact that the host — not the tool — names an untitled copy.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\AssistantWriteInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Tool\DuplicateAssistantTool;
use PHPUnit\Framework\TestCase;

final class DuplicateAssistantToolTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);
    }

    public function test_an_assistant_id_is_required(): void
    {
        $this->errors->expects($this->once())
            ->method('validationFailed')
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'validation_failed' ) ));

        $result = $this->tool($this->writer())->execute(array( 'assistant_id' => 0 ));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_a_missing_source_is_reported_as_not_found(): void
    {
        $this->errors->expects($this->once())
            ->method('notFound')
            ->with($this->stringContains('this estate'))
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'not_found' ) ));

        $result = $this->tool($this->writer(array(
            'success' => false,
            'code'    => 'assistant_not_found',
            'error'   => 'No assistant with that id exists in this estate.',
        )))->execute(array( 'assistant_id' => 99 ));

        $this->assertSame('not_found', $result['error']['code']);
    }

    public function test_a_host_failure_is_reported_as_a_duplicate_failure(): void
    {
        $this->errors->expects($this->once())
            ->method('create')
            ->with('assistant_duplicate_failed')
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'assistant_duplicate_failed' ) ));

        $result = $this->tool($this->writer(array(
            'success' => false,
            'code'    => 'assistant_duplicate_failed',
            'error'   => 'The assistant could not be duplicated.',
        )))->execute(array( 'assistant_id' => 7 ));

        $this->assertSame('assistant_duplicate_failed', $result['error']['code']);
    }

    public function test_it_passes_the_title_and_returns_the_copy(): void
    {
        $captured = array();

        $writer = $this->createMock(AssistantWriteInterface::class);
        $writer->expects($this->once())
            ->method('duplicate')
            ->willReturnCallback(static function (int $id, string $title) use (&$captured): array {
                $captured = array( 'id' => $id, 'title' => $title );

                return array( 'success' => true, 'assistant_id' => 12, 'slug' => 'ops-helper-copy', 'status' => 'draft' );
            });

        $result = $this->tool($writer)->execute(array(
            'assistant_id' => 4,
            'title'        => 'Ops Helper Two',
        ));

        $this->assertSame(array( 'id' => 4, 'title' => 'Ops Helper Two' ), $captured);
        $this->assertTrue($result['success']);
        $this->assertSame('Duplicated assistant #4 as a draft.', $result['message']);
        $this->assertSame(12, $result['data']['assistant']['id']);
        $this->assertSame('ops-helper-copy', $result['data']['assistant']['slug']);
        $this->assertSame('draft', $result['data']['assistant']['status']);
        $this->assertSame(4, $result['data']['source_id']);
    }

    public function test_an_omitted_title_is_passed_empty_so_the_host_names_the_copy(): void
    {
        $writer = $this->createMock(AssistantWriteInterface::class);
        $writer->expects($this->once())
            ->method('duplicate')
            ->with(4, '')
            ->willReturn(array( 'success' => true, 'assistant_id' => 5, 'slug' => 'x-copy', 'status' => 'draft' ));

        $this->tool($writer)->execute(array( 'assistant_id' => 4 ));
    }

    public function test_a_host_without_a_writer_says_so(): void
    {
        $tool = new DuplicateAssistantTool($this->errors, null);

        $result = $tool->execute(array( 'assistant_id' => 4 ));

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['data']['assistant']);
        $this->assertSame('This host does not accept assistant writes.', $result['message']);
    }

    public function test_it_declares_a_write_capability(): void
    {
        $tool = $this->tool($this->writer());

        $this->assertSame('edit_posts', $tool->getRequiredCapability());
        $this->assertContains('write', $tool->getCapabilityFlags());
        $this->assertContains('state-changing', $tool->getCapabilityFlags());
    }

    /**
     * @param array<string, mixed>|null $result
     */
    private function writer(?array $result = null): AssistantWriteInterface
    {
        $writer = $this->createMock(AssistantWriteInterface::class);
        $writer->method('duplicate')->willReturn(
            $result ?? array( 'success' => true, 'assistant_id' => 1, 'slug' => 'x-copy', 'status' => 'draft' )
        );

        return $writer;
    }

    private function tool(AssistantWriteInterface $writer): DuplicateAssistantTool
    {
        return new DuplicateAssistantTool($this->errors, $writer);
    }
}
