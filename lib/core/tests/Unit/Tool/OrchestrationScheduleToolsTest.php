<?php

/**
 * The ten Pro Schedule tools, the JSON store and the result renderer.
 *
 * Like the loop group, this slice shares mutable state, so the tests exercise
 * the real {@see JsonScheduleStore} in a temporary directory rather than a
 * fake: the hydration rules and the upsert semantics are what the readers
 * depend on, and a fake would hide them.
 *
 * Pinned behaviours, collected here rather than buried in assertions:
 *
 * - The id is derived, not random: an identical task schedule is an upsert, so
 *   re-creating it preserves history and results.
 * - The re-arm rule: an explicit timestamp wins, a disabled schedule stores 0,
 *   a consumed one-shot is not re-armed by a metadata edit, and a cadence
 *   change or re-enable arms one interval from now.
 * - `next_runs` extrapolates fixed seconds from the stored timestamp, so a
 *   `daily` projection is exactly 86,400 s apart and a `single` schedule yields
 *   one time.
 * - History is a 50-entry ring buffer; results honour `display.result_retention`.
 * - `widget_defaults` merges deeply — the one deliberate divergence from
 *   WordPress, whose shallow merge reset sibling widget keys on a partial update.
 * - The renderer escapes every interpolated value, including `raw` mode.
 * - The planner infers cadence and priority from keywords, offsets successive
 *   first runs by 60 s so ids do not collide, and persists nothing on a dry run.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ScheduleStoreInterface as Schedule;
use Nvoos\Core\Infrastructure\Schedule\JsonScheduleStore;
use Nvoos\Core\Tool\Orchestration\ConfigureScheduleWidgetDefaultsTool;
use Nvoos\Core\Tool\Orchestration\CreateProScheduleTool;
use Nvoos\Core\Tool\Orchestration\DeleteProScheduleTool;
use Nvoos\Core\Tool\Orchestration\DryRunProScheduleTool;
use Nvoos\Core\Tool\Orchestration\GetScheduleLatestResultTool;
use Nvoos\Core\Tool\Orchestration\GetScheduleRunHistoryTool;
use Nvoos\Core\Tool\Orchestration\ListProSchedulesTool;
use Nvoos\Core\Tool\Orchestration\PlanSchedulesFromWorkflowTool;
use Nvoos\Core\Tool\Orchestration\RenderScheduleResultTool;
use Nvoos\Core\Tool\Orchestration\ScheduledResultRenderer;
use Nvoos\Core\Tool\Orchestration\UpdateProScheduleTool;
use PHPUnit\Framework\TestCase;

final class OrchestrationScheduleToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 1);

    private ErrorFactoryInterface $errors;

    private JsonScheduleStore $schedules;

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

        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nvoos-schedules-' . bin2hex(random_bytes(6));

        mkdir($root, 0775, true);
        $this->tempDirs[] = $root;

        $this->schedules = new JsonScheduleStore($root . DIRECTORY_SEPARATOR . 'schedules');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }

        $this->tempDirs = array();
    }

    // ─── create_pro_schedule ─────────────────────────────────────────────

    public function test_creating_an_identical_task_schedule_updates_rather_than_duplicates(): void
    {
        $first  = $this->create(array('hook' => 'my_hook', 'schedule' => 'daily'));
        $second = $this->create(array('hook' => 'my_hook', 'schedule' => 'daily'));

        $this->assertSame($first['data']['schedule_id'], $second['data']['schedule_id']);
        $this->assertCount(1, $this->schedules->all());
    }

    public function test_an_upsert_preserves_the_run_history(): void
    {
        $created = $this->create(array('hook' => 'my_hook'));
        $id      = $created['data']['schedule_id'];

        $this->schedules->appendRun($id, array('status' => 'success', 'start_time' => 100));

        $this->create(array('hook' => 'my_hook'));

        $this->assertCount(1, $this->schedules->history($id, 10));
    }

    public function test_create_refuses_a_timestamp_in_the_past(): void
    {
        $result = $this->create(array('hook' => 'my_hook', 'timestamp' => time() - 60));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('timestamp', $result['error']['data']);
    }

    public function test_create_requires_a_hook_for_a_task_schedule(): void
    {
        $result = $this->create(array());

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('hook', $result['error']['data']);
    }

    public function test_each_type_requires_its_own_configuration(): void
    {
        $workflow = $this->create(array('schedule_type' => 'workflow'));
        $this->assertArrayHasKey('workflow_steps', $workflow['error']['data']);

        $assistant = $this->create(array('schedule_type' => 'assistant_run'));
        $this->assertArrayHasKey('assistant_config', $assistant['error']['data']);

        $broadcast = $this->create(array('schedule_type' => 'channel_broadcast'));
        $this->assertArrayHasKey('broadcast_config', $broadcast['error']['data']);
    }

    public function test_create_refuses_an_unregistered_cadence(): void
    {
        $result = $this->create(array('hook' => 'my_hook', 'schedule' => 'fortnightly'));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('schedule', $result['error']['data']);
    }

    public function test_create_normalises_a_recipient_list_and_refuses_a_bad_address(): void
    {
        $bad = $this->create(array('hook' => 'my_hook', 'notify_email' => 'not-an-address'));
        $this->assertArrayHasKey('notify_email', $bad['error']['data']);

        $good = $this->create(
            array('hook' => 'my_hook', 'notify_email' => 'ops@example.com, alerts@example.com')
        );

        $stored = $this->schedules->find($good['data']['schedule_id']);

        $this->assertSame('ops@example.com, alerts@example.com', $stored['notify_email']);
    }

    public function test_create_refuses_a_non_http_callback_url(): void
    {
        $result = $this->create(array('hook' => 'my_hook', 'callback_url' => 'ftp://example.com/hook'));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('callback_url', $result['error']['data']);
    }

    public function test_create_stores_the_documented_defaults(): void
    {
        $created = $this->create(array('hook' => 'my_hook', 'schedule' => 'daily'));
        $stored  = $this->schedules->find($created['data']['schedule_id']);

        $this->assertTrue($stored['enabled']);
        $this->assertSame(5, $stored['priority']);
        $this->assertSame(array(), $stored['tags']);
        $this->assertSame(300, $stored['retry_delay']);
        $this->assertSame(0, $stored['max_retries']);
        $this->assertSame('never', $stored['last_run_status']);
        $this->assertSame(0, $stored['run_count']);
        $this->assertSame('summary', $stored['display']['result_capture']);
        $this->assertSame(10, $stored['display']['result_retention']);
        $this->assertSame('summary-card', $stored['display']['widget_defaults']['render_mode']);
        $this->assertSame('task', $stored['schedule_type']);
    }

    // ─── dry_run_pro_schedule ────────────────────────────────────────────

    public function test_dry_run_projects_recurring_runs_at_the_fixed_interval(): void
    {
        $start   = time() + 3600;
        $created = $this->create(
            array('hook' => 'my_hook', 'schedule' => 'daily', 'timestamp' => $start)
        );

        $result = $this->dryRun(
            array('schedule_id' => $created['data']['schedule_id'], 'count' => 3)
        );

        $runs = $result['data']['next_runs'];

        $this->assertCount(3, $runs);
        $this->assertSame($start, $runs[0]['timestamp']);
        $this->assertSame($start + 86400, $runs[1]['timestamp']);
        $this->assertSame($start + 172800, $runs[2]['timestamp']);
        $this->assertSame(gmdate('c', $start), $runs[0]['iso8601']);
        $this->assertTrue($result['data']['would_dispatch']);
        $this->assertSame('UTC', $result['data']['timezone']);
    }

    public function test_dry_run_reports_a_paused_schedule_and_does_not_dispatch(): void
    {
        $created = $this->create(array('hook' => 'my_hook', 'schedule' => 'daily', 'enabled' => false));

        $result = $this->dryRun(array('schedule_id' => $created['data']['schedule_id']));

        $this->assertSame(array(), $result['data']['next_runs']);
        $this->assertFalse($result['data']['would_dispatch']);
        $this->assertContains('Schedule is currently paused and would not dispatch.', $result['data']['warnings']);
        $this->assertContains(
            'No upcoming runs are projected. The schedule may be one-shot and already fired, or paused.',
            $result['data']['warnings']
        );
    }

    public function test_dry_run_reports_a_single_schedule_as_one_run(): void
    {
        $created = $this->create(array('hook' => 'my_hook', 'schedule' => 'single'));

        $result = $this->dryRun(
            array('schedule_id' => $created['data']['schedule_id'], 'count' => 5)
        );

        $this->assertCount(1, $result['data']['next_runs']);
    }

    public function test_dry_run_warns_about_a_cadence_the_port_does_not_know(): void
    {
        $this->schedules->save(
            array(
                'id'            => 'custom-cadence',
                'name'          => 'Custom',
                'schedule_type' => Schedule::TYPE_TASK,
                'hook'          => 'my_hook',
                'schedule'      => 'fortnightly',
                'timestamp'     => time() + 3600,
            )
        );

        $result = $this->dryRun(array('schedule_id' => 'custom-cadence'));

        $this->assertContains(
            'Cadence "fortnightly" is not registered; the schedule will not fire.',
            $result['data']['warnings']
        );
    }

    public function test_dry_run_previews_the_action_per_type(): void
    {
        $task = $this->create(array('hook' => 'my_hook', 'args' => array('a')));
        $taskPreview = $this->dryRun(array('schedule_id' => $task['data']['schedule_id']));

        $this->assertSame('task', $taskPreview['data']['action']['type']);
        $this->assertSame('my_hook', $taskPreview['data']['action']['hook']);
        $this->assertSame(array('a'), $taskPreview['data']['action']['args']);

        $workflow = $this->create(
            array(
                'schedule_type'  => 'workflow',
                'workflow_steps' => array(
                    array('tool_slug' => 'get_posts', 'label' => 'Fetch'),
                    array('tool_slug' => 'create_post'),
                ),
            )
        );

        $workflowPreview = $this->dryRun(array('schedule_id' => $workflow['data']['schedule_id']));

        $this->assertSame('workflow', $workflowPreview['data']['action']['type']);
        $this->assertSame(1, $workflowPreview['data']['action']['steps'][1]['index']);
        $this->assertSame('create_post', $workflowPreview['data']['action']['steps'][1]['tool_slug']);

        $assistant = $this->create(
            array(
                'schedule_type'    => 'assistant_run',
                'assistant_config' => array('assistant_id' => 7, 'message' => 'Summarise sales.'),
            )
        );

        $assistantPreview = $this->dryRun(array('schedule_id' => $assistant['data']['schedule_id']));

        $this->assertSame(7, $assistantPreview['data']['action']['assistant_id']);
        $this->assertSame('', $assistantPreview['data']['action']['assistant_name']);
        $this->assertSame('Summarise sales.', $assistantPreview['data']['action']['message']);

        $broadcast = $this->create(
            array(
                'schedule_type'    => 'channel_broadcast',
                'broadcast_config' => array(
                    'message'     => 'Standup in five.',
                    'channels'    => array('slack'),
                    'credentials' => array('slack' => array('webhook' => 'https://example.com')),
                ),
            )
        );

        $broadcastPreview = $this->dryRun(array('schedule_id' => $broadcast['data']['schedule_id']));

        $this->assertSame(array('slack'), $broadcastPreview['data']['action']['channels']);
        $this->assertSame('Standup in five.', $broadcastPreview['data']['action']['message']);
    }

    public function test_dry_run_refuses_an_unknown_schedule(): void
    {
        $result = $this->dryRun(array('schedule_id' => 'no-such-schedule'));

        $this->assertFalse($result['success']);
        $this->assertSame('schedule_not_found', $result['error']['code']);
    }

    // ─── update_pro_schedule ─────────────────────────────────────────────

    public function test_a_metadata_update_keeps_the_armed_timestamp(): void
    {
        $armed   = time() + 7200;
        $created = $this->create(
            array('hook' => 'my_hook', 'schedule' => 'daily', 'timestamp' => $armed)
        );

        $result = $this->update(
            array('schedule_id' => $created['data']['schedule_id'], 'name' => 'Renamed')
        );

        $stored = $this->schedules->find($created['data']['schedule_id']);

        $this->assertSame('Renamed', $stored['name']);
        $this->assertSame($armed, $stored['timestamp']);
        $this->assertSame(gmdate('c', $armed), $result['data']['next_run']);
    }

    public function test_disabling_clears_the_timestamp_and_re_enabling_rearms_it(): void
    {
        $created = $this->create(array('hook' => 'my_hook', 'schedule' => 'daily'));
        $id      = $created['data']['schedule_id'];

        $this->update(array('schedule_id' => $id, 'enabled' => false));
        $disabled = $this->schedules->find($id);

        $this->assertSame(0, $disabled['timestamp']);

        $before = time();
        $this->update(array('schedule_id' => $id, 'enabled' => true));
        $rearmed = $this->schedules->find($id);

        $this->assertGreaterThanOrEqual($before + 86400, $rearmed['timestamp']);
        $this->assertLessThanOrEqual($before + 86405, $rearmed['timestamp']);
    }

    public function test_changing_the_cadence_rearms_one_new_interval_from_now(): void
    {
        $created = $this->create(
            array('hook' => 'my_hook', 'schedule' => 'daily', 'timestamp' => time() + 1000)
        );

        $before = time();
        $this->update(array('schedule_id' => $created['data']['schedule_id'], 'schedule' => 'hourly'));

        $stored = $this->schedules->find($created['data']['schedule_id']);

        $this->assertGreaterThanOrEqual($before + 3600, $stored['timestamp']);
        $this->assertLessThanOrEqual($before + 3605, $stored['timestamp']);
    }

    public function test_a_consumed_one_shot_is_not_rearmed_by_a_metadata_edit(): void
    {
        $created = $this->create(array('hook' => 'my_hook', 'schedule' => 'single'));
        $id      = $created['data']['schedule_id'];

        $stored                = $this->schedules->find($id);
        $stored['timestamp']   = 0;
        $stored['run_count']   = 1;
        $this->schedules->save($stored);

        $this->update(array('schedule_id' => $id, 'name' => 'Still Done'));

        $this->assertSame(0, $this->schedules->find($id)['timestamp']);
    }

    public function test_updating_an_unknown_schedule_is_an_error(): void
    {
        $result = $this->update(array('schedule_id' => 'nope', 'name' => 'x'));

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['error']['code']);
    }

    // ─── configure_schedule_widget_defaults ──────────────────────────────

    public function test_a_partial_widget_update_keeps_the_sibling_keys(): void
    {
        $created = $this->create(
            array(
                'hook'    => 'my_hook',
                'display' => array(
                    'widget_defaults' => array(
                        'render_mode'      => 'table',
                        'title'            => 'Ops',
                        'refresh_interval' => 60,
                    ),
                ),
            )
        );

        $result = $this->configure(
            array(
                'schedule_id'     => $created['data']['schedule_id'],
                'widget_defaults' => array('title' => 'Renamed'),
            )
        );

        $widgets = $result['data']['display']['widget_defaults'];

        $this->assertSame('table', $widgets['render_mode']);
        $this->assertSame('Renamed', $widgets['title']);
        $this->assertSame(60, $widgets['refresh_interval']);
    }

    public function test_configuring_requires_at_least_one_display_key(): void
    {
        $created = $this->create(array('hook' => 'my_hook'));

        $result = $this->configure(array('schedule_id' => $created['data']['schedule_id']));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('display', $result['error']['data']);
    }

    public function test_display_values_are_clamped_by_the_sanitiser(): void
    {
        $created = $this->create(array('hook' => 'my_hook'));

        $result = $this->configure(
            array(
                'schedule_id'      => $created['data']['schedule_id'],
                'result_retention' => 500,
                'widget_defaults'  => array('render_mode' => 'nope', 'refresh_interval' => 99999),
            )
        );

        $display = $result['data']['display'];

        $this->assertSame(100, $display['result_retention']);
        $this->assertSame('summary-card', $display['widget_defaults']['render_mode']);
        $this->assertSame(3600, $display['widget_defaults']['refresh_interval']);
    }

    public function test_configuring_an_unknown_schedule_is_an_error(): void
    {
        $result = $this->configure(array('schedule_id' => 'nope', 'result_capture' => 'full'));

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['error']['code']);
    }

    // ─── list_pro_schedules / delete_pro_schedule ────────────────────────

    public function test_list_sorts_by_priority_then_name_and_filters(): void
    {
        $this->create(array('hook' => 'zeta_hook', 'name' => 'Zeta', 'priority' => 5));
        $this->create(
            array('hook' => 'alpha_hook', 'name' => 'Alpha', 'priority' => 1, 'tags' => array('ops'))
        );

        $all = $this->listSchedules(array());

        $this->assertSame('Alpha', $all['data']['schedules'][0]['name']);
        $this->assertSame('Zeta', $all['data']['schedules'][1]['name']);
        $this->assertSame(2, $all['data']['total']);

        $tagged = $this->listSchedules(array('tag' => 'ops'));

        $this->assertSame(1, $tagged['data']['total']);

        $workflows = $this->listSchedules(array('schedule_type' => 'workflow'));

        $this->assertSame(0, $workflows['data']['total']);
    }

    public function test_list_refuses_an_unknown_type(): void
    {
        $result = $this->listSchedules(array('schedule_type' => 'nonsense'));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('schedule_type', $result['error']['data']);
    }

    public function test_delete_removes_the_definition_and_its_records(): void
    {
        $created = $this->create(array('hook' => 'my_hook'));
        $id      = $created['data']['schedule_id'];

        $this->schedules->appendRun($id, array('status' => 'success', 'start_time' => 1));
        $this->schedules->appendResult($id, array('summary' => 'one'));

        $result = $this->delete(array('schedule_id' => $id));

        $this->assertTrue($result['data']['deleted']);
        $this->assertNull($this->schedules->find($id));
        $this->assertSame(array(), $this->schedules->history($id, 10));
        $this->assertSame(array(), $this->schedules->results($id, 10));
    }

    public function test_delete_refuses_an_unknown_schedule(): void
    {
        $result = $this->delete(array('schedule_id' => 'nope'));

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['error']['code']);
    }

    // ─── get_schedule_run_history ────────────────────────────────────────

    public function test_run_history_returns_newest_first_with_iso_times(): void
    {
        $created = $this->create(array('hook' => 'my_hook'));
        $id      = $created['data']['schedule_id'];

        $this->schedules->appendRun($id, array('status' => 'success', 'start_time' => 100, 'duration' => 2));
        $this->schedules->appendRun($id, array('status' => 'failure', 'start_time' => 200, 'duration' => 3, 'error' => 'boom'));

        $result = $this->history(array('schedule_id' => $id));

        $this->assertSame(2, $result['data']['total_entries']);
        $this->assertSame('failure', $result['data']['history'][0]['status']);
        $this->assertSame(gmdate('c', 200), $result['data']['history'][0]['start_time']);
        $this->assertSame('boom', $result['data']['history'][0]['error']);
        $this->assertSame('success', $result['data']['history'][1]['status']);
    }

    public function test_run_history_limit_is_clamped(): void
    {
        $created = $this->create(array('hook' => 'my_hook'));
        $id      = $created['data']['schedule_id'];

        for ($index = 0; $index < 30; ++$index) {
            $this->schedules->appendRun($id, array('status' => 'success', 'start_time' => $index));
        }

        $this->assertCount(1, $this->history(array('schedule_id' => $id, 'limit' => 0))['data']['history']);
        $this->assertCount(30, $this->history(array('schedule_id' => $id, 'limit' => 500))['data']['history']);
    }

    public function test_history_is_a_fifty_entry_ring_buffer(): void
    {
        $created = $this->create(array('hook' => 'my_hook'));
        $id      = $created['data']['schedule_id'];

        for ($index = 0; $index < 55; ++$index) {
            $this->schedules->appendRun($id, array('status' => 'run-' . $index, 'start_time' => $index));
        }

        $entries = $this->schedules->history($id, 50);

        $this->assertCount(50, $entries);
        $this->assertSame('run-54', $entries[0]['status']);
        $this->assertSame('run-5', $entries[49]['status']);
    }

    public function test_run_history_refuses_an_unknown_schedule(): void
    {
        $result = $this->history(array('schedule_id' => 'nope'));

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['error']['code']);
    }

    // ─── result envelopes ────────────────────────────────────────────────

    public function test_results_are_capped_by_the_schedule_retention(): void
    {
        $created = $this->create(
            array('hook' => 'my_hook', 'display' => array('result_retention' => 3))
        );
        $id      = $created['data']['schedule_id'];

        for ($index = 1; $index <= 5; ++$index) {
            $this->schedules->appendResult($id, array('summary' => 'result-' . $index, 'generated_at' => $index));
        }

        $results = $this->schedules->results($id, 10);

        $this->assertCount(3, $results);
        $this->assertSame('result-5', $results[0]['summary']);
        $this->assertSame('result-3', $results[2]['summary']);
    }

    public function test_latest_result_reports_an_unrun_schedule_without_failing(): void
    {
        $created = $this->create(array('hook' => 'my_hook'));

        $result = $this->latest(array('schedule_id' => $created['data']['schedule_id']));

        $this->assertTrue($result['success']);
        $this->assertNull($result['data']['envelope']);
        $this->assertSame('No runs recorded for this schedule yet.', $result['data']['message']);
    }

    public function test_latest_result_returns_the_stored_envelope(): void
    {
        $created = $this->create(array('hook' => 'my_hook'));
        $id      = $created['data']['schedule_id'];

        $this->schedules->appendResult($id, array('summary' => 'Three orders', 'status' => 'success'));

        $result = $this->latest(array('schedule_id' => $id));

        $this->assertSame('Three orders', $result['data']['envelope']['summary']);
    }

    public function test_latest_result_refuses_an_unknown_schedule(): void
    {
        $result = $this->latest(array('schedule_id' => 'nope'));

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['error']['code']);
    }

    // ─── render_schedule_result ──────────────────────────────────────────

    public function test_render_escapes_envelope_content(): void
    {
        $created = $this->create(array('hook' => 'my_hook'));
        $id      = $created['data']['schedule_id'];

        $this->schedules->appendResult(
            $id,
            array('summary' => '<script>alert(1)</script>', 'status' => 'success', 'generated_at' => 100)
        );

        $html = $this->render(array('schedule_id' => $id))['data']['html'];

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('mcp-ai-scheduled-result--summary-card', $html);
    }

    public function test_render_supports_list_table_metric_and_raw_modes(): void
    {
        $created = $this->create(array('hook' => 'my_hook'));
        $id      = $created['data']['schedule_id'];

        $this->schedules->appendResult(
            $id,
            array(
                'summary'      => 'Summary',
                'status'       => 'success',
                'generated_at' => 100,
                'data'         => array(
                    'items'   => array('First', 'Second'),
                    'rows'    => array(array('name' => 'A', 'qty' => 2)),
                    'columns' => array('name', 'qty'),
                    'value'   => 42,
                    'label'   => 'Orders',
                    'response' => str_repeat('x', 20),
                ),
            )
        );

        $list = $this->render(array('schedule_id' => $id, 'render_mode' => 'list'))['data']['html'];
        $this->assertStringContainsString('<li>First</li>', $list);

        $table = $this->render(array('schedule_id' => $id, 'render_mode' => 'table'))['data']['html'];
        $this->assertStringContainsString('<th>name</th>', $table);
        $this->assertStringContainsString('<td>A</td>', $table);

        $metric = $this->render(array('schedule_id' => $id, 'render_mode' => 'metric'))['data']['html'];
        $this->assertStringContainsString('>42<', $metric);
        $this->assertStringContainsString('Orders', $metric);

        $raw = $this->render(
            array('schedule_id' => $id, 'render_mode' => 'raw', 'truncate_chars' => 5)
        )['data']['html'];

        $this->assertStringContainsString('xxxxx…', $raw);
    }

    public function test_render_falls_back_to_the_summary_card_for_an_unknown_mode(): void
    {
        $created = $this->create(array('hook' => 'my_hook'));
        $id      = $created['data']['schedule_id'];

        $this->schedules->appendResult($id, array('summary' => 'Plain', 'generated_at' => 1));

        $html = $this->render(array('schedule_id' => $id, 'render_mode' => 'sparkles'))['data']['html'];

        $this->assertStringContainsString('summary-card', $html);
        $this->assertStringContainsString('Plain', $html);
    }

    public function test_render_reports_an_unrun_schedule_as_a_notice(): void
    {
        $created = $this->create(array('hook' => 'my_hook'));

        $html = $this->render(array('schedule_id' => $created['data']['schedule_id']))['data']['html'];

        $this->assertStringContainsString('No runs have been recorded yet for this schedule.', $html);
    }

    public function test_render_refuses_an_unknown_schedule(): void
    {
        $result = $this->render(array('schedule_id' => 'nope'));

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['error']['code']);
    }

    // ─── plan_schedules_from_workflow ────────────────────────────────────

    public function test_plan_infers_cadence_and_priority_from_keywords(): void
    {
        $result = $this->plan(
            array(
                'workflow_items' => array(
                    'Weekly stock review',
                    'Urgent invoice follow-up',
                ),
                'dry_run'        => true,
            )
        );

        $this->assertSame('weekly', $result['data']['plan'][0]['schedule']);
        $this->assertSame(5, $result['data']['plan'][0]['priority']);
        $this->assertSame('daily', $result['data']['plan'][1]['schedule']);
        $this->assertSame(1, $result['data']['plan'][1]['priority']);
    }

    public function test_plan_parses_headings_and_strips_list_prefixes(): void
    {
        $result = $this->plan(
            array(
                'workflow_text' => "## Marketing\n- Post the weekly recap\n2) Review ad spend",
                'dry_run'       => true,
            )
        );

        $plan = $result['data']['plan'];

        $this->assertCount(2, $plan);
        $this->assertSame('Post the weekly recap', $plan[0]['name']);
        $this->assertSame('Review ad spend', $plan[1]['name']);
        $this->assertContains('Marketing', $plan[0]['tags']);
        $this->assertContains('planned-from-workflow', $plan[0]['tags']);
    }

    public function test_plan_spreads_first_runs_by_sixty_seconds(): void
    {
        $result = $this->plan(
            array(
                'workflow_items' => array('First task', 'Second task'),
                'default_time'   => '09:00',
                'dry_run'        => true,
            )
        );

        $first  = $result['data']['plan'][0]['timestamp'];
        $second = $result['data']['plan'][1]['timestamp'];

        $this->assertSame(60, $second - $first);
    }

    public function test_plan_dry_run_persists_nothing(): void
    {
        $result = $this->plan(
            array('workflow_items' => array('Post the recap'), 'dry_run' => true)
        );

        $this->assertSame(array(), $this->schedules->all());
        $this->assertTrue($result['data']['summary']['dry_run']);
        $this->assertSame(1, $result['data']['summary']['planned']);
        $this->assertSame(0, $result['data']['summary']['created']);
        $this->assertSame('Preview plan generated. No schedules were persisted.', $result['data']['message']);
    }

    public function test_plan_creates_task_schedules_by_default(): void
    {
        $result = $this->plan(array('workflow_items' => array('Post the recap')));

        $this->assertSame(1, $result['data']['summary']['created']);

        $stored = $this->schedules->find($result['data']['created'][0]['schedule_id']);

        $this->assertSame('task', $stored['schedule_type']);
        $this->assertSame('wp_mcp_ai_workflow_reminder', $stored['hook']);
        $this->assertSame('daily', $stored['schedule']);

        $args = json_decode($stored['args'][0], true);

        $this->assertSame('Post the recap', $args['title']);
        $this->assertContains('planned-from-workflow', $args['tags']);
    }

    public function test_plan_uses_assistant_runs_when_an_assistant_is_configured(): void
    {
        $result = $this->plan(
            array(
                'workflow_items'       => array('Post the recap'),
                'default_assistant_id' => 42,
            )
        );

        $stored = $this->schedules->find($result['data']['created'][0]['schedule_id']);

        $this->assertSame('assistant_run', $stored['schedule_type']);
        $this->assertSame(42, $stored['assistant_config']['assistant_id']);
        $this->assertSame('wp_mcp_ai_pro_assistant_run', $stored['hook']);
        $this->assertStringContainsString('report back when complete', $stored['assistant_config']['message']);
    }

    public function test_plan_truncates_the_name_to_eighty_characters(): void
    {
        $result = $this->plan(
            array('workflow_items' => array(str_repeat('a', 120)), 'dry_run' => true)
        );

        $this->assertSame(80, mb_strlen($result['data']['plan'][0]['name']));
    }

    public function test_plan_refuses_an_empty_workflow(): void
    {
        $result = $this->plan(array());

        $this->assertFalse($result['success']);
        $this->assertSame('no_workflow_items', $result['error']['code']);
    }

    public function test_plan_reports_an_invalid_item_without_failing_the_batch(): void
    {
        $result = $this->plan(
            array(
                'workflow_items' => array('Valid item', array('description' => '')),
                'dry_run'        => true,
            )
        );

        $this->assertSame(1, $result['data']['summary']['planned']);
        $this->assertCount(1, $result['data']['errors']);
        $this->assertSame(
            'Workflow item must have a title or description.',
            $result['data']['errors'][0]['message']
        );
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function create(array $arguments): array
    {
        return ( new CreateProScheduleTool($this->errors, $this->schedules) )
            ->execute($arguments, self::CONTEXT);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function update(array $arguments): array
    {
        return ( new UpdateProScheduleTool($this->errors, $this->schedules) )
            ->execute($arguments, self::CONTEXT);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function listSchedules(array $arguments): array
    {
        return ( new ListProSchedulesTool($this->errors, $this->schedules) )
            ->execute($arguments, self::CONTEXT);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function delete(array $arguments): array
    {
        return ( new DeleteProScheduleTool($this->errors, $this->schedules) )
            ->execute($arguments, self::CONTEXT);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function dryRun(array $arguments): array
    {
        return ( new DryRunProScheduleTool($this->errors, $this->schedules) )
            ->execute($arguments, self::CONTEXT);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function history(array $arguments): array
    {
        return ( new GetScheduleRunHistoryTool($this->errors, $this->schedules) )
            ->execute($arguments, self::CONTEXT);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function latest(array $arguments): array
    {
        return ( new GetScheduleLatestResultTool($this->errors, $this->schedules) )
            ->execute($arguments, self::CONTEXT);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function render(array $arguments): array
    {
        return ( new RenderScheduleResultTool(
            $this->errors,
            $this->schedules,
            new ScheduledResultRenderer($this->schedules)
        ) )->execute($arguments, self::CONTEXT);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function configure(array $arguments): array
    {
        return ( new ConfigureScheduleWidgetDefaultsTool($this->errors, $this->schedules) )
            ->execute($arguments, self::CONTEXT);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function plan(array $arguments): array
    {
        return ( new PlanSchedulesFromWorkflowTool($this->errors, $this->schedules) )
            ->execute($arguments, self::CONTEXT);
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
