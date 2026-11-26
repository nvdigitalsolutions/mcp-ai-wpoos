<?php

/**
 * The F&B audit-log viewer.
 *
 * The tool owns three things: the filter semantics, the limit fallback, and the
 * honest answer when the host keeps no log. All three are pinned here; the
 * entry shapes come from `SystemLogInterface` and are not reinterpreted.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SystemLogInterface;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Tool\Fnb\FnbAuditLogTool;
use PHPUnit\Framework\TestCase;

final class FnbAuditLogToolTest extends TestCase
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
    }

    public function test_it_filters_by_tool_and_keeps_entries_without_one(): void
    {
        $log = $this->log(
            array(
                $this->entry('a', 'fnb_save_draft'),
                $this->entry('b', 'fnb_calculate_metric'),
                $this->entry('c', '', array('tool' => 'fnb_save_draft')),
                $this->entry('d', ''),
            )
        );

        $result = $this->tool($log)->execute(array('tool' => 'fnb_save'), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['data']['count']);
        $this->assertSame(array('a', 'c', 'd'), array_column($result['data']['entries'], 'id'));
    }

    public function test_a_non_matching_entry_with_a_tool_is_dropped(): void
    {
        $log = $this->log(
            array(
                $this->entry('a', 'fnb_save_draft'),
                $this->entry('b', 'fnb_calculate_metric'),
            )
        );

        $result = $this->tool($log)->execute(array('tool' => 'fnb_calculate'), self::CONTEXT);

        $this->assertSame(array('b'), array_column($result['data']['entries'], 'id'));
    }

    public function test_the_limit_outside_the_range_falls_back_to_one_hundred(): void
    {
        $log = $this->log($this->many(150));

        $zero = $this->tool($log)->execute(array('limit' => 0), self::CONTEXT);
        $big  = $this->tool($log)->execute(array('limit' => 501), self::CONTEXT);

        $this->assertSame(100, $zero['data']['count']);
        $this->assertSame(100, $big['data']['count']);
    }

    public function test_a_limit_inside_the_range_is_honoured(): void
    {
        $log = $this->log($this->many(10));

        $result = $this->tool($log)->execute(array('limit' => 3), self::CONTEXT);

        $this->assertSame(3, $result['data']['count']);
        $this->assertSame('Found 3 audit entries.', $result['message']);
    }

    public function test_act_tier_seen_is_computed_from_the_returned_entries(): void
    {
        $quiet = $this->tool($this->log(array($this->entry('a', 'fnb_save_draft'))))
            ->execute(array(), self::CONTEXT);
        $act   = $this->tool($this->log(array($this->entry('b', '', array('tool' => 'fnb_import_table')))))
            ->execute(array(), self::CONTEXT);

        $this->assertFalse($quiet['data']['act_tier_seen']);
        $this->assertTrue($act['data']['act_tier_seen']);
        $this->assertStringContainsString('act_tier_seen is computed', $act['data']['note']);
    }

    public function test_a_host_without_a_log_says_so(): void
    {
        $result = $this->tool(null)->execute(array(), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame('This host keeps no queryable audit log.', $result['message']);
        $this->assertSame(array(), $result['data']['entries']);
        $this->assertSame(array(), $result['data']['sources']);
        $this->assertFalse($result['data']['act_tier_seen']);
    }

    public function test_a_host_with_no_sources_says_so(): void
    {
        $result = $this->tool($this->log(array($this->entry('a', 'x')), array()))
            ->execute(array(), self::CONTEXT);

        $this->assertSame('This host keeps no queryable audit log.', $result['message']);
        $this->assertSame(0, $result['data']['count']);
    }

    public function test_it_reports_the_sources_it_read(): void
    {
        $result = $this->tool($this->log(array($this->entry('a', 'x')), array('audit', 'traces')))
            ->execute(array(), self::CONTEXT);

        $this->assertSame(array('audit', 'traces'), $result['data']['sources']);
    }

    public function test_it_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->tool($this->log(array()))->execute(array());
    }

    public function test_it_is_operator_gated_and_not_cacheable(): void
    {
        $tool = $this->tool(null);

        $this->assertSame('manage_options', $tool->getRequiredCapability());
        $this->assertContains('read-only', $tool->getCapabilityFlags());
        $this->assertContains('requires-capability', $tool->getCapabilityFlags());
        $this->assertNotContains('cacheable', $tool->getCapabilityFlags());
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function entry(string $id, string $tool, array $detail = array()): array
    {
        $entry = array(
            'id'      => $id,
            'at'      => '2026-09-30T10:00:00Z',
            'source'  => 'audit',
            'type'    => 'audit.record',
            'level'   => 'notice',
            'summary' => 'Entry ' . $id,
            'actor'   => 'operator',
            'detail'  => $detail,
        );

        if ('' !== $tool) {
            $entry['tool'] = $tool;
        }

        return $entry;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function many(int $count): array
    {
        $entries = array();

        for ($i = 0; $i < $count; $i++) {
            $entries[] = $this->entry('e' . $i, 'fnb_tool_' . $i);
        }

        return $entries;
    }

    /**
     * @param list<array<string, mixed>> $entries Entries the store answers with.
     * @param list<string>               $sources Stores this host can read.
     */
    private function log(array $entries, array $sources = array('audit')): SystemLogInterface
    {
        return new class ($entries, $sources) implements SystemLogInterface {
            /**
             * @param list<array<string, mixed>> $entries
             * @param list<string>               $sources
             */
            public function __construct(
                private readonly array $entries,
                private readonly array $sources,
            ) {
            }

            public function sources(): array
            {
                return $this->sources;
            }

            public function entries(array $query = array()): array
            {
                $limit = (int) ($query['limit'] ?? 20);

                return array_slice($this->entries, 0, max(1, $limit));
            }
        };
    }

    private function tool(?SystemLogInterface $log): FnbAuditLogTool
    {
        return new FnbAuditLogTool($this->errors, $log);
    }
}
