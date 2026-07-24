<?php
/**
 * Tests for the Data Budget Tracker domain service.
 *
 * @package Nvoos\Core\Tests
 * @since   2.0.0
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Domain\Service\Budget;

use Nvoos\Core\Domain\Service\Budget\DataBudgetTracker;
use Nvoos\Core\Domain\Contract\DataBudgetTrackerInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nvoos\Core\Domain\Service\Budget\DataBudgetTracker
 */
final class DataBudgetTrackerTest extends TestCase
{
    private DataBudgetTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tracker = new DataBudgetTracker(
            DataBudgetTrackerInterface::DEFAULT_REQUEST_BUDGET_BYTES,
            DataBudgetTrackerInterface::DEFAULT_PER_MESSAGE_BUDGET_BYTES,
            'test-request'
        );
    }

    // ── Budget Resolution ────────────────────────────────────────────

    public function test_default_budgets(): void
    {
        $this->assertSame(1048576, $this->tracker->getRequestBudget());
        $this->assertSame(65536, $this->tracker->getPerMessageBudget());
    }

    public function test_custom_budgets(): void
    {
        $tracker = new DataBudgetTracker(500000, 32000);
        $this->assertSame(500000, $tracker->getRequestBudget());
        $this->assertSame(32000, $tracker->getPerMessageBudget());
    }

    public function test_budgets_never_below_minimum(): void
    {
        $tracker = new DataBudgetTracker(100, 100);
        $this->assertSame(1024, $tracker->getRequestBudget());
        $this->assertSame(512, $tracker->getPerMessageBudget());
    }

    // ── Recording & Consumption ──────────────────────────────────────

    public function test_initial_state_is_zero(): void
    {
        $this->assertSame(0, $this->tracker->consumed());
        $this->assertSame(1048576, $this->tracker->remaining());
        $this->assertFalse($this->tracker->isExhausted());
    }

    public function test_record_increases_consumed(): void
    {
        $this->tracker->record(50000);
        $this->assertSame(50000, $this->tracker->consumed());
        $this->assertSame(1048576 - 50000, $this->tracker->remaining());
    }

    public function test_record_rejects_negative_bytes(): void
    {
        $this->tracker->record(-100);
        $this->assertSame(0, $this->tracker->consumed());
    }

    public function test_multiple_records_accumulate(): void
    {
        $this->tracker->record(10000);
        $this->tracker->record(20000);
        $this->tracker->record(30000);
        $this->assertSame(60000, $this->tracker->consumed());
    }

    // ── Exhaustion ───────────────────────────────────────────────────

    public function test_exhaustion_when_consumed_equals_budget(): void
    {
        $tracker = new DataBudgetTracker(1000, 500);
        $tracker->record(1000);
        $this->assertTrue($tracker->isExhausted());
        $this->assertSame(0, $tracker->remaining());
    }

    public function test_exhaustion_when_consumed_exceeds_budget(): void
    {
        $tracker = new DataBudgetTracker(1000, 500);
        $tracker->record(1500);
        $this->assertTrue($tracker->isExhausted());
        $this->assertSame(0, $tracker->remaining());
    }

    // ── Spill Decisions ──────────────────────────────────────────────

    public function test_should_spill_when_exceeds_per_message_budget(): void
    {
        $tracker = new DataBudgetTracker(100000, 1000);
        $this->assertTrue($tracker->shouldSpill(2000));
    }

    public function test_should_not_spill_when_within_budgets(): void
    {
        $this->assertFalse($this->tracker->shouldSpill(1000));
    }

    public function test_should_spill_when_would_exhaust_request_budget(): void
    {
        $tracker = new DataBudgetTracker(10000, 5000);
        $tracker->record(8000);
        $this->assertTrue($tracker->shouldSpill(3000)); // 8000 + 3000 > 10000
    }

    public function test_should_not_spill_when_remaining_is_sufficient(): void
    {
        $tracker = new DataBudgetTracker(10000, 5000);
        $tracker->record(5000);
        $this->assertFalse($tracker->shouldSpill(3000)); // 5000 + 3000 <= 10000
        $this->assertFalse($tracker->shouldSpill(5000)); // 5000 + 5000 = 10000
    }

    // ── Spill Counter ────────────────────────────────────────────────

    public function test_spill_counter_starts_at_zero(): void
    {
        $this->assertSame(0, $this->tracker->spillCount());
    }

    public function test_note_spill_increments_counter(): void
    {
        $this->tracker->noteSpill();
        $this->tracker->noteSpill();
        $this->assertSame(2, $this->tracker->spillCount());
    }

    // ── Reset ────────────────────────────────────────────────────────

    public function test_reset_clears_state(): void
    {
        $this->tracker->record(50000);
        $this->tracker->noteSpill();
        $this->tracker->noteSpill();

        $this->tracker->reset('new-request');

        $this->assertSame(0, $this->tracker->consumed());
        $this->assertSame(0, $this->tracker->spillCount());
    }

    // ── Edge Cases ───────────────────────────────────────────────────

    public function test_zero_byte_record_is_noop(): void
    {
        $this->tracker->record(0);
        $this->assertSame(0, $this->tracker->consumed());
    }

    public function test_should_spill_zero_bytes(): void
    {
        $this->assertFalse($this->tracker->shouldSpill(0));
    }

    public function test_exhausted_budget_always_spills(): void
    {
        $tracker = new DataBudgetTracker(100, 50);
        $tracker->record(100);
        $this->assertTrue($tracker->shouldSpill(1));
    }
}
