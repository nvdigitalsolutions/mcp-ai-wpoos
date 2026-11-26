<?php

/**
 * The system-log tool.
 *
 * The host is a mock, so what is pinned here is the tool's side of the contract:
 * the cap, the ordering, the `since` parser, and the fact that every filter is
 * re-applied even when the host ignores it. The WordPress tool does the same
 * second pass for the same reason — a host implementation is not the place to
 * discover that `levels` was advisory.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SystemLogInterface;
use Nvoos\Core\Tool\GetSystemLogsTool;
use PHPUnit\Framework\TestCase;

final class GetSystemLogsToolTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);
    }

    public function test_entries_are_sorted_newest_first_and_capped(): void
    {
        $result = $this->tool(
            $this->log(
                array(
                    $this->entry('a', '2026-11-20T10:00:00Z', SystemLogInterface::NOTICE),
                    $this->entry('b', '2026-11-22T10:00:00Z', SystemLogInterface::WARNING),
                    $this->entry('c', '2026-11-21T10:00:00Z', SystemLogInterface::NOTICE),
                )
            )
        )->execute(array('activity_limit' => 2));

        $this->assertTrue($result['success']);
        $this->assertSame(array('b', 'c'), array_column($result['data']['entries'], 'id'));
        $this->assertSame(2, $result['data']['count']);
        $this->assertSame(2, $result['data']['limit']);
        $this->assertSame('Found 2 system log entries from audit, traces.', $result['message']);
    }

    public function test_the_limit_is_clamped(): void
    {
        $result = $this->tool($this->log(array()))->execute(array('activity_limit' => 5000));

        $this->assertSame(100, $result['data']['limit']);
    }

    public function test_types_and_levels_are_re_applied_even_when_the_host_ignores_them(): void
    {
        $entries = array(
            $this->entry('record', '2026-11-22T10:00:00Z', SystemLogInterface::NOTICE, 'audit.record'),
            $this->entry('refused', '2026-11-22T09:00:00Z', SystemLogInterface::WARNING, 'audit.request'),
            $this->entry('error', '2026-11-22T08:00:00Z', SystemLogInterface::ERROR, 'audit.request'),
        );

        $byType = $this->tool($this->log($entries))->execute(
            array('activity_types' => array('audit.request'))
        );
        $this->assertSame(array('refused', 'error'), array_column($byType['data']['entries'], 'id'));

        $byLevel = $this->tool($this->log($entries))->execute(
            array('levels' => array(SystemLogInterface::ERROR))
        );
        $this->assertSame(array('error'), array_column($byLevel['data']['entries'], 'id'));
    }

    public function test_search_matches_summary_actor_or_detail(): void
    {
        $entries = array(
            $this->entry('summary', '2026-11-22T10:00:00Z', SystemLogInterface::NOTICE, 'audit.record', 'Deleted estate acme.'),
            $this->entry('actor', '2026-11-22T09:00:00Z', SystemLogInterface::NOTICE, 'audit.record', 'Updated settings.', 'ada@example.test'),
            $this->entry('detail', '2026-11-22T08:00:00Z', SystemLogInterface::NOTICE, 'audit.record', 'Updated settings.', 'ops', array('resource' => 'tenants')),
        );

        $result = $this->tool($this->log($entries))->execute(array('search' => 'ACME'));
        $this->assertSame(array('summary'), array_column($result['data']['entries'], 'id'));

        $result = $this->tool($this->log($entries))->execute(array('search' => 'ada@'));
        $this->assertSame(array('actor'), array_column($result['data']['entries'], 'id'));

        $result = $this->tool($this->log($entries))->execute(array('search' => 'tenants'));
        $this->assertSame(array('detail'), array_column($result['data']['entries'], 'id'));
    }

    public function test_since_is_parsed_and_passed_to_the_host(): void
    {
        $captured = array();

        $log = $this->createMock(SystemLogInterface::class);
        $log->method('sources')->willReturn(array('audit'));
        $log->method('entries')->willReturnCallback(
            static function (array $query) use (&$captured): array {
                $captured = $query;

                return array();
            }
        );

        $before = time();
        (new GetSystemLogsTool($this->errors, $log))->execute(array('since' => '2h'));
        $after = time();

        $since = strtotime((string) $captured['since']);

        $this->assertGreaterThanOrEqual($before - 7200, $since);
        $this->assertLessThanOrEqual($after - 7200, $since);
    }

    public function test_a_bare_number_is_minutes(): void
    {
        $captured = array();

        $log = $this->createMock(SystemLogInterface::class);
        $log->method('sources')->willReturn(array('audit'));
        $log->method('entries')->willReturnCallback(
            static function (array $query) use (&$captured): array {
                $captured = $query;

                return array();
            }
        );

        (new GetSystemLogsTool($this->errors, $log))->execute(array('since' => '45'));

        $this->assertEqualsWithDelta(time() - 2700, strtotime((string) $captured['since']), 5);
    }

    public function test_an_unparseable_since_is_refused(): void
    {
        $this->errors->expects($this->once())
            ->method('validationFailed')
            ->willReturn(array('success' => false, 'error' => array('code' => 'validation_failed')));

        $result = $this->tool($this->log(array()))->execute(array('since' => 'last tuesday-ish'));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_a_host_with_no_log_service_says_so(): void
    {
        $tool = new GetSystemLogsTool($this->errors, null);

        $result = $tool->execute();

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['data']['sources']);
        $this->assertSame(array(), $result['data']['entries']);
        $this->assertSame('This host keeps no queryable system log.', $result['message']);
    }

    public function test_a_host_that_declares_no_sources_says_so(): void
    {
        $result = $this->tool($this->log(array(), array()))->execute();

        $this->assertSame('This host keeps no queryable system log.', $result['message']);
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param list<string>               $sources
     */
    private function log(array $entries, array $sources = array('audit', 'traces')): SystemLogInterface
    {
        $log = $this->createMock(SystemLogInterface::class);
        $log->method('sources')->willReturn($sources);
        $log->method('entries')->willReturn($entries);

        return $log;
    }

    private function tool(SystemLogInterface $log): GetSystemLogsTool
    {
        return new GetSystemLogsTool($this->errors, $log);
    }

    /**
     * @param array<string, mixed> $detail
     *
     * @return array<string, mixed>
     */
    private function entry(
        string $id,
        string $at,
        string $level,
        string $type = 'audit.record',
        string $summary = 'Something happened.',
        ?string $actor = 'operator',
        array $detail = array()
    ): array {
        return array(
            'id'      => $id,
            'at'      => $at,
            'source'  => 'audit',
            'type'    => $type,
            'level'   => $level,
            'summary' => $summary,
            'actor'   => $actor,
            'detail'  => $detail,
        );
    }
}
