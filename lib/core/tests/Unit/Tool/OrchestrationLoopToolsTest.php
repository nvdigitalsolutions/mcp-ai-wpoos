<?php

/**
 * The eight autonomous-loop tools, plus the two stores and the breaker.
 *
 * The loop group is the one toolkit slice whose tools share mutable state, so
 * these tests exercise the real {@see JsonAutonomousSessionStore} and
 * {@see JsonExecutionHistoryStore} in a temporary directory rather than fakes.
 * The store is cheap, and a fake would hide the hydration rules that every
 * reader depends on.
 *
 * Pinned behaviours, collected here rather than buried in assertions:
 *
 * - The session machine is permissive: `resume` turns a completed session back
 *   into `active`, and only an expired session is refused.
 * - The breaker opens on the *third* consecutive failure, not the fourth — the
 *   WordPress counter was read one write late.
 * - `analyze_loop_health` reports `warning` for a session with no actions,
 *   because a 0% velocity reads as "poor"; nothing acts on it.
 * - `detect_completion_indicators` restores the eight regexes WordPress
 *   mangled; the corrupted form is asserted to fail compilation.
 * - `check_exit_conditions.force_check` is accepted and inert, and a hard limit
 *   forces a guarded exit without the dual gate.
 * - `calculate_orchestration_capacity` measures the current load from the store
 *   and cannot divide by zero.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Application\Orchestration\AutonomousCircuitBreaker;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Infrastructure\Session\JsonAutonomousSessionStore;
use Nvoos\Core\Infrastructure\Session\JsonExecutionHistoryStore;
use Nvoos\Core\Tool\Orchestration\AnalyzeLoopHealthTool;
use Nvoos\Core\Tool\Orchestration\CalculateOrchestrationCapacityTool;
use Nvoos\Core\Tool\Orchestration\CheckExitConditionsTool;
use Nvoos\Core\Tool\Orchestration\ConfigureCircuitBreakerTool;
use Nvoos\Core\Tool\Orchestration\DetectCompletionIndicatorsTool;
use Nvoos\Core\Tool\Orchestration\GetLoopMetricsTool;
use Nvoos\Core\Tool\Orchestration\GetSessionStatusTool;
use Nvoos\Core\Tool\Orchestration\ManageAutonomousSessionTool;
use PHPUnit\Framework\TestCase;

final class OrchestrationLoopToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 1);

    private ErrorFactoryInterface $errors;

    private JsonAutonomousSessionStore $sessions;

    private JsonExecutionHistoryStore $history;

    private LoopSettingsStore $settings;

    /** @var list<string> */
    private array $tempDirs = array();

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

        $this->errors->method('isError')->willReturnCallback(
            static fn (mixed $value): bool => is_array($value) && false === ( $value['success'] ?? true )
        );

        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nvoos-orchestration-' . bin2hex(random_bytes(6));

        mkdir($root, 0775, true);
        $this->tempDirs[] = $root;

        $this->sessions = new JsonAutonomousSessionStore($root . DIRECTORY_SEPARATOR . 'sessions');
        $this->history  = new JsonExecutionHistoryStore($root . DIRECTORY_SEPARATOR . 'history');
        $this->settings = new LoopSettingsStore();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }

        $this->tempDirs = array();
    }

    // ─── The session store ────────────────────────────────────────────────

    public function test_a_new_session_carries_the_documented_defaults(): void
    {
        $session = $this->sessions->create(array('plan_id' => 4));

        $this->assertSame(4, $session['plan_id']);
        $this->assertSame('active', $session['status']);
        $this->assertSame(0, $session['iteration_count']);
        $this->assertSame(25, $session['max_iterations']);
        $this->assertSame(10000, $session['token_budget']);
        $this->assertSame('healthy', $session['health_status']);
        $this->assertSame('closed', $session['circuit_breaker']);
        $this->assertSame(100.0, $session['success_rate']);
        $this->assertNotSame('', $session['started_at']);
        $this->assertNotSame('', $session['last_activity']);
        $this->assertNotSame('', $session['expires_at']);
        $this->assertNull($session['circuit_opened_at']);
    }

    public function test_a_duplicate_id_is_refused_rather_than_overwritten(): void
    {
        $this->sessions->create(array('session_id' => 'repeat-me'));

        $this->expectException(\RuntimeException::class);

        $this->sessions->create(array('session_id' => 'repeat-me'));
    }

    public function test_an_unsafe_id_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->sessions->create(array('session_id' => '../escape'));
    }

    public function test_query_filters_by_status_and_pages(): void
    {
        $this->sessions->create(array('session_id' => 'one', 'status' => 'active'));
        $this->sessions->create(array('session_id' => 'two', 'status' => 'paused'));
        $this->sessions->create(array('session_id' => 'three', 'status' => 'completed'));

        $this->assertSame(3, $this->sessions->query()['total']);
        $this->assertSame(1, $this->sessions->query(array('statuses' => array('active')))['total']);
        $this->assertSame(1, $this->sessions->query(array('status' => 'paused'))['total']);

        $page = $this->sessions->query(array('limit' => 1, 'offset' => 1));

        $this->assertSame(3, $page['total']);
        $this->assertCount(1, $page['sessions']);
    }

    public function test_an_update_stamps_last_activity(): void
    {
        $this->sessions->create(
            array('session_id' => 'stamp', 'last_activity' => '2020-01-01T00:00:00+00:00')
        );

        $updated = $this->sessions->update('stamp', array('iteration_count' => 2));

        $this->assertNotNull($updated);
        $this->assertSame(2, $updated['iteration_count']);
        $this->assertNotSame('2020-01-01T00:00:00+00:00', $updated['last_activity']);
    }

    // ─── The history store ────────────────────────────────────────────────

    public function test_history_is_read_oldest_first_and_bounded(): void
    {
        $this->history->append('s1', array('tool_name' => 'a', 'success' => true));
        $this->history->append('s1', array('tool_name' => 'b', 'success' => false));

        $records = $this->history->forSession('s1');

        $this->assertCount(2, $records);
        $this->assertSame('a', $records[0]['tool_name']);
        $this->assertSame('b', $records[1]['tool_name']);
        $this->assertNotSame('', $records[0]['at']);

        $this->assertCount(1, $this->history->forSession('s1', 1));
        $this->assertCount(0, $this->history->forSession('missing'));
    }

    // ─── manage_autonomous_session ────────────────────────────────────────

    public function test_start_requires_a_plan(): void
    {
        $result = $this->manage()->execute(array('action' => 'start'), self::CONTEXT);

        $this->assertTrue($this->errors->isError($result));
        $this->assertSame('missing_plan_id', $result['error']['code']);
    }

    public function test_start_writes_an_active_session_with_the_configured_limits(): void
    {
        $result = $this->manage()->execute(
            array(
                'action'  => 'start',
                'plan_id' => 9,
                'config'  => array(
                    'max_iterations' => 5,
                    'token_budget'   => 500,
                    'assistant_id'   => 3,
                ),
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(9, $result['data']['plan_id']);
        $this->assertSame(5, $result['data']['max_iterations']);
        $this->assertSame(500, $result['data']['token_budget']);

        $session = $this->sessions->find((string) $result['data']['session_id']);

        $this->assertNotNull($session);
        $this->assertSame('active', $session['status']);
        $this->assertSame(3, $session['assistant_id']);
        $this->assertSame(1, $session['user_id']);
        $this->assertSame('closed', $session['circuit_breaker']);

        $ttl = strtotime($session['expires_at']) - time();

        $this->assertGreaterThan(86000, $ttl);
        $this->assertLessThanOrEqual(86405, $ttl);
    }

    public function test_pause_resume_and_stop_move_the_status(): void
    {
        $id = $this->start();

        $paused = $this->manage()->execute(array('action' => 'pause', 'session_id' => $id), self::CONTEXT);
        $this->assertSame('paused', $paused['data']['status']);

        $resumed = $this->manage()->execute(array('action' => 'resume', 'session_id' => $id), self::CONTEXT);
        $this->assertSame('active', $resumed['data']['status']);

        $stopped = $this->manage()->execute(array('action' => 'stop', 'session_id' => $id), self::CONTEXT);
        $this->assertSame('completed', $stopped['data']['status']);

        $session = $this->sessions->find($id);

        $this->assertNotNull($session);
        $this->assertNotSame('', $session['completed_at']);
    }

    public function test_resume_is_permissive_for_a_completed_session(): void
    {
        $id = $this->start();
        $this->manage()->execute(array('action' => 'stop', 'session_id' => $id), self::CONTEXT);

        $resumed = $this->manage()->execute(array('action' => 'resume', 'session_id' => $id), self::CONTEXT);

        $this->assertTrue($resumed['success']);
        $this->assertSame('active', $resumed['data']['status']);
    }

    public function test_resume_refuses_an_expired_session(): void
    {
        $this->sessions->create(
            array('session_id' => 'stale', 'expires_at' => gmdate('c', time() - 60))
        );

        $result = $this->manage()->execute(array('action' => 'resume', 'session_id' => 'stale'), self::CONTEXT);

        $this->assertTrue($this->errors->isError($result));
        $this->assertSame('session_expired', $result['error']['code']);
    }

    public function test_update_writes_only_the_known_metrics(): void
    {
        $id = $this->start();

        $result = $this->manage()->execute(
            array(
                'action'     => 'update',
                'session_id' => $id,
                'metrics'    => array(
                    'iteration_count' => 3,
                    'token_usage'     => 250,
                    'success_rate'    => 87.5,
                    'error_count'     => 1,
                    'last_tool'       => " get_post\n",
                    'last_error'      => "first line\nsecond line",
                    'invented'        => 'ignored',
                ),
            ),
            self::CONTEXT
        );

        $this->assertSame(
            array('iteration_count', 'token_usage', 'success_rate', 'error_count', 'last_tool', 'last_error'),
            $result['data']['updated']
        );

        $session = $this->sessions->find($id);

        $this->assertNotNull($session);
        $this->assertSame(3, $session['iteration_count']);
        $this->assertSame(250, $session['token_usage']);
        $this->assertSame(87.5, $session['success_rate']);
        $this->assertSame('get_post', $session['last_tool']);
        $this->assertSame("first line\nsecond line", $session['last_error']);
        $this->assertArrayNotHasKey('invented', $session);
    }

    public function test_update_without_metrics_is_an_error(): void
    {
        $id = $this->start();

        $result = $this->manage()->execute(
            array('action' => 'update', 'session_id' => $id),
            self::CONTEXT
        );

        $this->assertSame('missing_metrics', $result['error']['code']);
    }

    public function test_management_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->manage()->execute(array('action' => 'start', 'plan_id' => 1), array('user_id' => 0));
    }

    // ─── get_session_status ───────────────────────────────────────────────

    public function test_status_reports_progress_and_resources(): void
    {
        $id = $this->start();

        $this->manage()->execute(
            array(
                'action'     => 'update',
                'session_id' => $id,
                'metrics'    => array('iteration_count' => 5, 'token_usage' => 2500),
            ),
            self::CONTEXT
        );

        $result = $this->statusTool()->execute(
            array('session_id' => $id, 'include_plan' => true),
            self::CONTEXT
        );

        $data = $result['data'];

        $this->assertSame('active', $data['status']);
        $this->assertSame(5, $data['progress']['iteration_count']);
        $this->assertSame(20, $data['progress']['iterations_remaining']);
        $this->assertEqualsWithDelta(20.0, $data['progress']['progress_percent'], 0.001);
        $this->assertSame(7500, $data['resources']['tokens_remaining']);
        $this->assertEqualsWithDelta(25.0, $data['resources']['budget_percent'], 0.001);
        $this->assertTrue($data['indicators']['should_continue']);
        $this->assertArrayNotHasKey('task_plan', $data);
        $this->assertNull($data['metadata']['last_tool']);
        $this->assertNull($data['metadata']['last_error']);
    }

    public function test_status_of_an_unknown_session_is_an_error(): void
    {
        $this->assertSame(
            'missing_session_id',
            $this->statusTool()->execute(array(), self::CONTEXT)['error']['code']
        );
        $this->assertSame(
            'session_not_found',
            $this->statusTool()->execute(array('session_id' => 'nope'), self::CONTEXT)['error']['code']
        );
    }

    // ─── check_exit_conditions ────────────────────────────────────────────

    public function test_the_dual_gate_needs_both_keys(): void
    {
        $id = $this->start();

        $withoutSignal = $this->exitGate()->execute(
            array('session_id' => $id, 'completion_score' => 2, 'threshold' => 2),
            self::CONTEXT
        );

        $this->assertFalse($withoutSignal['data']['should_exit']);
        $this->assertFalse($withoutSignal['data']['dual_gate_passed']);
        $this->assertStringStartsWith('CONTINUE_WITH_CAUTION', $withoutSignal['data']['recommendation']);
        $this->assertStringContainsString('EXIT_SIGNAL not set', $withoutSignal['data']['recommendation']);

        $withSignal = $this->exitGate()->execute(
            array('session_id' => $id, 'completion_score' => 2, 'threshold' => 2, 'exit_signal' => true),
            self::CONTEXT
        );

        $this->assertTrue($withSignal['data']['should_exit']);
        $this->assertTrue($withSignal['data']['dual_gate_passed']);
        $this->assertStringStartsWith('COMPLETED:', $withSignal['data']['exit_reason']);
        $this->assertSame(
            'EXIT_CLEANLY: Work completed successfully. Both completion criteria met.',
            $withSignal['data']['recommendation']
        );
    }

    public function test_a_hard_limit_forces_a_guarded_exit(): void
    {
        $id = $this->start();
        $this->sessions->update($id, array('iteration_count' => 25, 'max_iterations' => 25));

        $result = $this->exitGate()->execute(array('session_id' => $id), self::CONTEXT);

        $this->assertTrue($result['data']['should_exit']);
        $this->assertFalse($result['data']['dual_gate_passed']);
        $this->assertStringStartsWith('MAX_ITERATIONS', $result['data']['exit_reason']);
        $this->assertStringStartsWith('EXIT_INCOMPLETE', $result['data']['recommendation']);
    }

    public function test_an_open_breaker_forces_a_guarded_exit(): void
    {
        $id = $this->start();
        $this->sessions->update($id, array('circuit_breaker' => 'open'));

        $result = $this->exitGate()->execute(array('session_id' => $id), self::CONTEXT);

        $this->assertTrue($result['data']['should_exit']);
        $this->assertSame(
            'CIRCUIT_BREAKER: Circuit breaker opened due to repeated failures',
            $result['data']['exit_reason']
        );
        $this->assertStringStartsWith('EXIT_ERROR', $result['data']['recommendation']);
    }

    public function test_force_check_is_inert(): void
    {
        $id = $this->start();

        $result = $this->exitGate()->execute(
            array(
                'session_id'       => $id,
                'completion_score' => 0,
                'exit_signal'      => false,
                'force_check'      => true,
            ),
            self::CONTEXT
        );

        $this->assertFalse($result['data']['should_exit']);
        $this->assertStringStartsWith('CONTINUE', $result['data']['exit_reason']);
    }

    public function test_exit_conditions_for_an_unknown_session_are_an_error(): void
    {
        $result = $this->exitGate()->execute(array('session_id' => 'nope'), self::CONTEXT);

        $this->assertSame('session_not_found', $result['error']['code']);
    }

    // ─── detect_completion_indicators ─────────────────────────────────────

    public function test_two_indicators_meet_the_default_threshold(): void
    {
        $result = $this->detect()->execute(
            array('text' => 'Objective achieved. All requirements met.'),
            self::CONTEXT
        );

        $this->assertSame(2, $result['data']['indicator_count']);
        $this->assertTrue($result['data']['completion_met']);
        $this->assertNull($result['data']['task_completion']);
        $this->assertSame(
            'INDICATORS_MET: 2 completion indicators detected (threshold: 2). Verify work before exiting.',
            $result['data']['recommendation']
        );
    }

    public function test_below_the_threshold_needs_more_signals(): void
    {
        $result = $this->detect()->execute(
            array('text' => 'Nothing more to do.', 'threshold' => 2),
            self::CONTEXT
        );

        $this->assertSame(1, $result['data']['indicator_count']);
        $this->assertFalse($result['data']['completion_met']);
        $this->assertSame(
            'CONTINUE_WORKING: Only 1 of 2 indicators found. Need 1 more completion signal(s).',
            $result['data']['recommendation']
        );
    }

    public function test_the_corrupted_wordpress_pattern_does_not_compile_and_the_restored_one_matches(): void
    {
        $corrupted = '/\b(nothing ( ? ( :more|else) to ( ? ( :do|add|fix))\b/i';

        $this->assertFalse(@preg_match($corrupted, 'Nothing more to do.'));

        $result = $this->detect()->execute(array('text' => 'Nothing more to do.'), self::CONTEXT);

        $this->assertSame('Nothing more to do', $result['data']['indicators_found'][0]['match']);
    }

    public function test_text_is_required_and_must_be_a_string(): void
    {
        $this->assertSame(
            'missing_text',
            $this->detect()->execute(array(), self::CONTEXT)['error']['code']
        );
        $this->assertSame(
            'invalid_text',
            $this->detect()->execute(array('text' => 42), self::CONTEXT)['error']['code']
        );
    }

    // ─── get_loop_metrics ─────────────────────────────────────────────────

    public function test_metrics_without_a_history_store_are_zeroed_not_an_error(): void
    {
        $result = (new GetLoopMetricsTool($this->errors))->execute(
            array('session_id' => 'any'),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['metrics']['total_calls']);
        $this->assertSame(
            'No execution history found for this session.',
            $result['data']['metrics']['message']
        );
    }

    public function test_metrics_aggregate_success_duration_and_errors(): void
    {
        $records = array(
            array('tool_name' => 'a', 'success' => true, 'duration_ms' => 100, 'tokens_used' => 10),
            array('tool_name' => 'a', 'success' => true, 'duration_ms' => 200, 'tokens_used' => 20),
            array('tool_name' => 'a', 'success' => true, 'duration_ms' => 300, 'tokens_used' => 30),
            array('tool_name' => 'b', 'success' => false, 'duration_ms' => 0, 'error_message' => 'boom'),
            array('tool_name' => 'b', 'success' => false, 'duration_ms' => 100, 'error_message' => 'boom again'),
        );

        foreach ($records as $record) {
            $this->history->append('loop', $record);
        }

        $result  = $this->metrics()->execute(array('session_id' => 'loop'), self::CONTEXT);
        $metrics = $result['data']['metrics'];

        $this->assertSame(5, $metrics['total_calls']);
        $this->assertSame(3, $metrics['successful_calls']);
        $this->assertSame(2, $metrics['failed_calls']);
        $this->assertEqualsWithDelta(60.0, $metrics['success_rate'], 0.001);
        $this->assertEqualsWithDelta(140.0, $metrics['avg_duration_ms'], 0.001);
        $this->assertSame(60, $metrics['total_tokens']);
        $this->assertSame('a', $metrics['most_used_tool']);
        $this->assertSame(array('a' => 3, 'b' => 2), $metrics['tool_frequency']);
        $this->assertSame(
            array('b' => array('count' => 2, 'messages' => array('boom', 'boom again'))),
            $metrics['error_distribution']
        );
        $this->assertSame(array('b'), $metrics['error_tools']);
        $this->assertStringContainsString('Moderate performance', $metrics['health_recommendation']);
    }

    public function test_a_record_without_a_success_key_counts_as_a_success(): void
    {
        $this->history->append('legacy', array('tool_name' => 'a', 'duration_ms' => 50));

        $result = $this->metrics()->execute(array('session_id' => 'legacy'), self::CONTEXT);

        $this->assertSame(1, $result['data']['metrics']['successful_calls']);
        $this->assertEqualsWithDelta(100.0, $result['data']['metrics']['success_rate'], 0.001);
    }

    // ─── configure_circuit_breaker ────────────────────────────────────────

    public function test_the_defaults_are_the_breakers_defaults(): void
    {
        $result = $this->breakerConfig()->execute(array('action' => 'view'), self::CONTEXT);

        $this->assertSame(
            array(
                'error_threshold_pct'    => 50,
                'volume_threshold'       => 5,
                'reset_timeout_sec'      => 300,
                'max_consecutive_errors' => 3,
                'no_progress_cycles'     => 3,
            ),
            $result['data']['settings']
        );
        $this->assertSame($result['data']['defaults'], $result['data']['settings']);
    }

    public function test_view_reports_the_values_the_breaker_will_use(): void
    {
        $this->settings->values[ AutonomousCircuitBreaker::CONFIG_KEY ] = array(
            'error_threshold_pct' => 0,
            'volume_threshold'    => 100,
        );

        $result = $this->breakerConfig()->execute(array('action' => 'view'), self::CONTEXT);

        $this->assertSame(10, $result['data']['settings']['error_threshold_pct']);
        $this->assertSame(50, $result['data']['settings']['volume_threshold']);
    }

    public function test_an_update_accepts_valid_keys_and_rejects_the_rest_by_name(): void
    {
        $result = $this->breakerConfig()->execute(
            array(
                'action'   => 'update',
                'settings' => array(
                    'error_threshold_pct' => 60,
                    'volume_threshold'    => 100,
                    'invented'            => 1,
                ),
            ),
            self::CONTEXT
        );

        $this->assertSame(array('error_threshold_pct'), $result['data']['updated']);
        $this->assertSame(
            array('volume_threshold (must be 2–50)', 'invented'),
            $result['data']['rejected']
        );
        $this->assertSame(60, $result['data']['settings']['error_threshold_pct']);
        $this->assertSame(5, $result['data']['settings']['volume_threshold']);
        $this->assertSame(
            array('error_threshold_pct' => 60),
            $this->settings->values[ AutonomousCircuitBreaker::CONFIG_KEY ]
        );
    }

    public function test_reset_removes_the_stored_row(): void
    {
        $this->settings->values[ AutonomousCircuitBreaker::CONFIG_KEY ] = array('error_threshold_pct' => 70);

        $result = $this->breakerConfig()->execute(array('action' => 'reset'), self::CONTEXT);

        $this->assertSame(50, $result['data']['settings']['error_threshold_pct']);
        $this->assertNull($this->settings->get(AutonomousCircuitBreaker::CONFIG_KEY));
    }

    public function test_configuration_requires_manage_options(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->breakerConfig()->execute(array('action' => 'view'), array('user_id' => 0));
    }

    // ─── The breaker ──────────────────────────────────────────────────────

    public function test_the_breaker_opens_on_the_third_consecutive_failure(): void
    {
        $id      = $this->start();
        $breaker = new AutonomousCircuitBreaker($id, $this->sessions, $this->settings);

        $breaker->recordFailure();
        $breaker->recordFailure();

        $this->assertSame(AutonomousCircuitBreaker::STATE_CLOSED, $breaker->state());

        $breaker->recordFailure();

        $this->assertSame(AutonomousCircuitBreaker::STATE_OPEN, $breaker->state());

        $session = $this->sessions->find($id);

        $this->assertNotNull($session);
        $this->assertSame(3, $session['consecutive_errors']);
        $this->assertIsInt($session['circuit_opened_at']);
    }

    public function test_an_open_breaker_blocks_until_the_cooldown_elapses_then_probes(): void
    {
        $id = $this->start();
        $this->sessions->update($id, array('circuit_breaker' => 'open', 'circuit_opened_at' => time()));

        $breaker = new AutonomousCircuitBreaker($id, $this->sessions, $this->settings);

        $this->assertFalse($breaker->allowExecution());
        $this->assertSame(AutonomousCircuitBreaker::STATE_OPEN, $breaker->state());

        $this->sessions->update($id, array('circuit_opened_at' => time() - 301));

        $this->assertTrue($breaker->allowExecution());
        $this->assertSame(AutonomousCircuitBreaker::STATE_HALF_OPEN, $breaker->state());

        $breaker->recordSuccess();

        $this->assertSame(AutonomousCircuitBreaker::STATE_CLOSED, $breaker->state());
    }

    public function test_an_older_record_without_a_stamp_is_treated_as_elapsed(): void
    {
        $id = $this->start();
        $this->sessions->update($id, array('circuit_breaker' => 'open', 'circuit_opened_at' => null));

        $breaker = new AutonomousCircuitBreaker($id, $this->sessions, $this->settings);

        $this->assertTrue($breaker->allowExecution());
        $this->assertSame(AutonomousCircuitBreaker::STATE_HALF_OPEN, $breaker->state());
    }

    public function test_no_progress_is_three_identical_or_three_error_outputs(): void
    {
        $breaker = new AutonomousCircuitBreaker('any', $this->sessions, $this->settings);

        $this->assertFalse($breaker->detectNoProgress(array('same', 'same')));
        $this->assertTrue($breaker->detectNoProgress(array('same', 'same', 'same')));
        $this->assertTrue($breaker->detectNoProgress(array('error one', 'failed two', 'exception three')));
        $this->assertFalse($breaker->detectNoProgress(array('error one', 'ok', 'exception three')));
    }

    // ─── analyze_loop_health ──────────────────────────────────────────────

    public function test_health_of_an_unknown_session_is_an_error(): void
    {
        $result = $this->health()->execute(array('session_id' => 'nope'), self::CONTEXT);

        $this->assertSame('session_not_found', $result['error']['code']);
    }

    public function test_three_distinct_successful_actions_are_healthy(): void
    {
        $id = $this->start();

        $result = $this->health()->execute(
            array('session_id' => $id, 'last_actions' => $this->actions(array('a', 'b', 'c'))),
            self::CONTEXT
        );

        $this->assertSame('healthy', $result['data']['health_status']);
        $this->assertFalse($result['data']['should_pause']);
        $this->assertSame('closed', $result['data']['circuit_breaker']);
        $this->assertSame(array(), $result['data']['warnings']);
        $this->assertSame(array('Loop health is good. Continue working.'), $result['data']['recommendations']);

        $session = $this->sessions->find($id);

        $this->assertNotNull($session);
        $this->assertSame('healthy', $session['health_status']);
    }

    public function test_a_repetitive_pattern_is_a_warning_without_pausing(): void
    {
        $id = $this->start();

        $result = $this->health()->execute(
            array('session_id' => $id, 'last_actions' => $this->actions(array('a', 'b', 'a'))),
            self::CONTEXT
        );

        $this->assertSame('warning', $result['data']['health_status']);
        $this->assertFalse($result['data']['should_pause']);
        $this->assertSame(
            array('Repetitive pattern: Tool "a" called 2 times'),
            $result['data']['warnings']
        );
        $this->assertContains(
            'Vary your approach to avoid repetitive loops.',
            $result['data']['recommendations']
        );

        $session = $this->sessions->find($id);

        $this->assertNotNull($session);
        $this->assertSame('warning', $session['health_status']);
        $this->assertSame('closed', $session['circuit_breaker']);
    }

    public function test_an_error_cascade_is_a_warning(): void
    {
        $id = $this->start();

        $actions = array(
            array('tool' => 'a', 'success' => false, 'error' => 'e1'),
            array('tool' => 'b', 'success' => false, 'error' => 'e2'),
            array('tool' => 'c', 'success' => false, 'error' => 'e3'),
            array('tool' => 'd', 'success' => true),
            array('tool' => 'e', 'success' => true),
        );

        $result = $this->health()->execute(
            array('session_id' => $id, 'last_actions' => $actions),
            self::CONTEXT
        );

        $this->assertSame(3, $result['data']['analysis']['error_cascade']['error_count']);
        $this->assertTrue($result['data']['analysis']['error_cascade']['detected']);
        $this->assertSame('warning', $result['data']['health_status']);
        $this->assertFalse($result['data']['should_pause']);
        $this->assertStringContainsString(
            'Error cascade: 3 errors in last 5 actions (60.0% error rate)',
            $result['data']['warnings'][0]
        );
    }

    public function test_a_stuck_tool_is_critical_and_opens_the_breaker(): void
    {
        $id = $this->start();

        $result = $this->health()->execute(
            array(
                'session_id'   => $id,
                'last_actions' => $this->actions(array('x', 'x', 'x', 'x', 'x')),
            ),
            self::CONTEXT
        );

        $this->assertSame('critical', $result['data']['health_status']);
        $this->assertTrue($result['data']['should_pause']);
        $this->assertSame('open', $result['data']['circuit_breaker']);
        $this->assertStringContainsString(
            'Stuck pattern: Tool "x" called 5 times in last 5 actions',
            $result['data']['warnings'][1]
        );

        $session = $this->sessions->find($id);

        $this->assertNotNull($session);
        $this->assertSame('critical', $session['health_status']);
        $this->assertSame('open', $session['circuit_breaker']);
        $this->assertTrue($session['circuit_breaker_open']);
        $this->assertIsInt($session['circuit_opened_at']);
    }

    public function test_a_critical_error_opens_the_breaker_even_with_no_actions(): void
    {
        $id = $this->start();

        $result = $this->health()->execute(
            array('session_id' => $id, 'current_error' => 'Fatal error: out of memory'),
            self::CONTEXT
        );

        $this->assertSame('critical', $result['data']['health_status']);
        $this->assertTrue($result['data']['analysis']['error_severity']['critical']);
        $this->assertTrue($result['data']['should_pause']);
        $this->assertSame('open', $result['data']['circuit_breaker']);
        $this->assertContains(
            'STOP: Critical issues detected. Review errors and restart session.',
            $result['data']['recommendations']
        );
    }

    public function test_no_actions_reports_the_pinned_warning_band(): void
    {
        $id = $this->start();

        $result = $this->health()->execute(array('session_id' => $id), self::CONTEXT);

        $this->assertSame(0.0, $result['data']['analysis']['velocity']['success_rate']);
        $this->assertSame('poor', $result['data']['analysis']['velocity']['velocity']);
        $this->assertSame('warning', $result['data']['health_status']);
        $this->assertFalse($result['data']['should_pause']);
        $this->assertSame(
            array('Loop health is good. Continue working.'),
            $result['data']['recommendations']
        );
    }

    public function test_a_zero_cap_reads_as_pressure_without_dividing_by_zero(): void
    {
        $this->sessions->create(
            array(
                'session_id'      => 'zero-cap',
                'max_iterations'  => 0,
                'token_budget'    => 0,
                'iteration_count' => 0,
                'token_usage'     => 0,
            )
        );

        $result = $this->health()->execute(
            array(
                'session_id'   => 'zero-cap',
                'last_actions' => $this->actions(array('a', 'b', 'c')),
            ),
            self::CONTEXT
        );

        $this->assertEqualsWithDelta(
            0.0,
            $result['data']['analysis']['resource_pressure']['iteration_pressure'],
            0.001
        );
        $this->assertEqualsWithDelta(
            0.0,
            $result['data']['analysis']['resource_pressure']['token_pressure'],
            0.001
        );
        $this->assertFalse($result['data']['analysis']['resource_pressure']['high_pressure']);

        $this->sessions->update('zero-cap', array('iteration_count' => 1, 'token_usage' => 1));

        $result = $this->health()->execute(
            array(
                'session_id'   => 'zero-cap',
                'last_actions' => $this->actions(array('a', 'b', 'c')),
            ),
            self::CONTEXT
        );

        $this->assertEqualsWithDelta(
            100.0,
            $result['data']['analysis']['resource_pressure']['iteration_pressure'],
            0.001
        );
        $this->assertEqualsWithDelta(
            100.0,
            $result['data']['analysis']['resource_pressure']['token_pressure'],
            0.001
        );
        $this->assertTrue($result['data']['analysis']['resource_pressure']['high_pressure']);
    }

    // ─── calculate_orchestration_capacity ─────────────────────────────────

    public function test_capacity_applies_littles_law(): void
    {
        $result = $this->capacity()->execute(
            array(
                'mode'           => 'calculate_capacity',
                'arrival_rate'   => 2,
                'service_time'   => 1.5,
                'max_concurrent' => 10,
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertEqualsWithDelta(3.0, $result['data']['littles_law']['L'], 0.001);
        $this->assertSame(2.0, $result['data']['littles_law']['λ']);
        $this->assertSame(1.5, $result['data']['littles_law']['W']);
        $this->assertSame('L = λ × W', $result['data']['littles_law']['formula']);
        $this->assertSame(10, $result['data']['capacity']['current_limit']);
        $this->assertEqualsWithDelta(30.0, $result['data']['capacity']['utilization_percent'], 0.001);
        $this->assertEqualsWithDelta(8.0, $result['data']['capacity']['safe_capacity'], 0.001);
        $this->assertEqualsWithDelta(5.0, $result['data']['capacity']['recommended_limit'], 0.001);
        $this->assertContains('Can handle 3.3x more load', $result['data']['recommendations']);
    }

    public function test_capacity_requires_positive_inputs(): void
    {
        $missing = $this->capacity()->execute(array('mode' => 'calculate_capacity'), self::CONTEXT);

        $this->assertSame('missing_arguments', $missing['error']['code']);

        $invalid = $this->capacity()->execute(
            array('mode' => 'calculate_capacity', 'arrival_rate' => 0, 'service_time' => 1),
            self::CONTEXT
        );

        $this->assertSame('invalid_arguments', $invalid['error']['code']);
    }

    public function test_wait_time_is_immediate_below_the_cap(): void
    {
        $result = $this->capacity()->execute(
            array('mode' => 'predict_wait_time', 'current_sessions' => 3, 'max_concurrent' => 10),
            self::CONTEXT
        );

        $this->assertSame(0, $result['data']['wait_time_hours']);
        $this->assertSame(0, $result['data']['queue_position']);
        $this->assertSame(
            'Capacity available - session can start immediately',
            $result['data']['message']
        );
    }

    public function test_wait_time_counts_the_queue_against_the_cap(): void
    {
        $result = $this->capacity()->execute(
            array(
                'mode'             => 'predict_wait_time',
                'current_sessions' => 12,
                'max_concurrent'   => 10,
                'service_time'     => 0.5,
            ),
            self::CONTEXT
        );

        $this->assertSame(3, $result['data']['queue_position']);
        $this->assertEqualsWithDelta(1.5, $result['data']['wait_time_hours'], 0.001);
        $this->assertEqualsWithDelta(90.0, $result['data']['wait_time_minutes'], 0.001);
        $this->assertEqualsWithDelta(120.0, $result['data']['current_load']['utilization'], 0.001);
        $this->assertSame(
            'System at capacity. Estimated wait: 90 minutes (position 3 in queue)',
            $result['data']['message']
        );
    }

    public function test_the_current_load_is_measured_from_the_session_store(): void
    {
        $this->sessions->create(array('session_id' => 'active-1', 'status' => 'active'));
        $this->sessions->create(array('session_id' => 'active-2', 'status' => 'active'));
        $this->sessions->create(
            array(
                'session_id'   => 'done-1',
                'status'       => 'completed',
                'started_at'   => gmdate('c', time() - 7200),
                'completed_at' => gmdate('c', time() - 3600),
            )
        );

        $result = $this->capacity()->execute(array('mode' => 'analyze_current'), self::CONTEXT);

        $this->assertSame(2, $result['data']['current_state']['active_sessions']);
        $this->assertSame(10, $result['data']['current_state']['max_concurrent']);
        $this->assertEqualsWithDelta(20.0, $result['data']['current_state']['utilization'], 0.001);
        $this->assertSame(1, $result['data']['historical_metrics']['completed_today']);
        $this->assertEqualsWithDelta(0.04, $result['data']['historical_metrics']['arrival_rate'], 0.001);
        $this->assertEqualsWithDelta(1.0, $result['data']['historical_metrics']['avg_service_time'], 0.001);
        $this->assertEqualsWithDelta(60.0, $result['data']['historical_metrics']['avg_duration_mins'], 0.001);
        $this->assertEqualsWithDelta(0.04, $result['data']['littles_law']['predicted_queue_length'], 0.001);
        $this->assertSame('IDLE', $result['data']['capacity_analysis']['status']);
        $this->assertSame(8, $result['data']['capacity_analysis']['headroom_sessions']);
        $this->assertEqualsWithDelta(80.0, $result['data']['capacity_analysis']['headroom_percent'], 0.001);
        $this->assertContains(
            '✅ Capacity available: 2 of 10 slots in use (20.0%)',
            $result['data']['recommendations']
        );
    }

    public function test_a_zero_cap_falls_back_to_ten(): void
    {
        $result = $this->capacity()->execute(
            array('mode' => 'analyze_current', 'max_concurrent' => 0),
            self::CONTEXT
        );

        $this->assertSame(10, $result['data']['current_state']['max_concurrent']);
    }

    public function test_an_unknown_mode_is_a_typed_error(): void
    {
        $result = $this->capacity()->execute(array('mode' => 'guess'), self::CONTEXT);

        $this->assertSame('invalid_mode', $result['error']['code']);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    private function start(): string
    {
        $result = $this->manage()->execute(
            array('action' => 'start', 'plan_id' => 1),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);

        return (string) $result['data']['session_id'];
    }

    /**
     * @param list<string> $tools Tool names, all successful.
     *
     * @return list<array{tool: string, success: bool}>
     */
    private function actions(array $tools): array
    {
        $actions = array();

        foreach ($tools as $tool) {
            $actions[] = array('tool' => $tool, 'success' => true);
        }

        return $actions;
    }

    private function manage(): ManageAutonomousSessionTool
    {
        return new ManageAutonomousSessionTool($this->errors, $this->sessions);
    }

    private function statusTool(): GetSessionStatusTool
    {
        return new GetSessionStatusTool($this->errors, $this->sessions);
    }

    private function exitGate(): CheckExitConditionsTool
    {
        return new CheckExitConditionsTool($this->errors, $this->sessions);
    }

    private function detect(): DetectCompletionIndicatorsTool
    {
        return new DetectCompletionIndicatorsTool($this->errors);
    }

    private function metrics(): GetLoopMetricsTool
    {
        return new GetLoopMetricsTool($this->errors, $this->history);
    }

    private function breakerConfig(): ConfigureCircuitBreakerTool
    {
        return new ConfigureCircuitBreakerTool($this->errors, $this->settings);
    }

    private function health(): AnalyzeLoopHealthTool
    {
        return new AnalyzeLoopHealthTool($this->errors, $this->sessions, $this->settings);
    }

    private function capacity(): CalculateOrchestrationCapacityTool
    {
        return new CalculateOrchestrationCapacityTool($this->errors, $this->sessions);
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach ((array) scandir($dir) as $entry) {
            if (! is_string($entry) || '.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($dir);
    }
}
