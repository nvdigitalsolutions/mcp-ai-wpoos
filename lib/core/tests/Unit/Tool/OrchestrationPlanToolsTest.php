<?php

/**
 * The eight task-plan and template tools.
 *
 * The store is a stateful in-memory {@see TaskPlanContentStore} that evaluates
 * the queries and enforces the edit rule the real adapter delegates to a Gate,
 * so the permission paths run rather than being mocked.
 *
 * Pinned and corrected behaviours covered here:
 *
 * - `create_task_plan` resets the counters when it regenerates the body, and a
 *   task cannot inject extra checkboxes.
 * - `get_task_plan` derives progress from the markdown and accepts
 *   `include_history` without pretending to have history.
 * - `update_task_plan` appends `new_tasks` (the WordPress regex for this did
 *   not compile and wiped the plan), maintains `completed_at`, and requires
 *   edit permission.
 * - `create_template` accepts the library's fourteen categories, not six.
 * - `list_templates` sorts by the field it was asked for and reports the
 *   matching total.
 * - `instantiate_template` writes the plan schema the readers use and does not
 *   increment another user's counter.
 * - `seed_template_library` seeds fourteen, skips on a second run and updates
 *   (rather than duplicates) on overwrite.
 * - `create_execution_prompt` renders plain text, not HTML-escaped text.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Tool\Orchestration\CreateExecutionPromptTool;
use Nvoos\Core\Tool\Orchestration\CreateTaskPlanTool;
use Nvoos\Core\Tool\Orchestration\CreateTemplateTool;
use Nvoos\Core\Tool\Orchestration\GetTaskPlanTool;
use Nvoos\Core\Tool\Orchestration\InstantiateTemplateTool;
use Nvoos\Core\Tool\Orchestration\ListTemplatesTool;
use Nvoos\Core\Tool\Orchestration\SeedTemplateLibraryTool;
use Nvoos\Core\Tool\Orchestration\TaskTemplateLibrary;
use Nvoos\Core\Tool\Orchestration\UpdateTaskPlanTool;
use PHPUnit\Framework\TestCase;

final class OrchestrationPlanToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 1);

    private const PLAN = "# Plan\n\n## Goal\nGoal\n\n## Tasks\n- [ ] One\n- [ ] Two\n- [ ] Three\n";

    private ErrorFactoryInterface $errors;

    private TaskPlanContentStore $store;

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

        $this->errors->method('forbidden')->willReturnCallback(
            static fn (string $message): array => array(
                'success' => false,
                'error'   => array('code' => 'forbidden', 'message' => $message),
            )
        );

        $this->errors->method('isError')->willReturnCallback(
            static fn (mixed $value): bool => is_array($value) && false === ( $value['success'] ?? true )
        );

        $this->store = new TaskPlanContentStore();
    }

    // ─── create_task_plan ─────────────────────────────────────────────────

    public function test_a_plan_needs_a_name_goal_and_tasks_and_each_is_named(): void
    {
        $result = $this->createPlanTool()->execute(array('tasks' => array()), self::CONTEXT);

        $this->assertTrue($this->errors->isError($result));
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('plan_name', $result['error']['data']);
        $this->assertArrayHasKey('goal', $result['error']['data']);
        $this->assertArrayHasKey('tasks', $result['error']['data']);
        $this->assertSame(array(), $this->store->items);
    }

    public function test_a_plan_is_stored_with_the_documented_meta_and_markdown(): void
    {
        $result = $this->createPlanTool()->execute(
            array(
                'plan_name'   => 'Market Research Q1',
                'goal'        => 'Find the market size.',
                'tasks'       => array(
                    array('text' => 'Collect data', 'priority' => 'high'),
                    array('text' => 'Write report'),
                ),
                'project_id'  => 7,
                'template_id' => 3,
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['plan_id']);
        $this->assertSame(2, $result['data']['task_count']);
        $this->assertSame(0, $result['data']['completed_count']);
        $this->assertEqualsWithDelta(0.0, $result['data']['progress'], 0.001);
        $this->assertFalse($result['data']['updated']);

        $item = $this->store->items[1];

        $this->assertSame('mcp_task_plan', $item->type);
        $this->assertSame('publish', $item->status);
        $this->assertSame(1, $item->authorId);
        $this->assertSame('Market Research Q1', $item->title);
        $this->assertSame('Find the market size.', $item->meta['goal']);
        $this->assertSame(2, $item->meta['task_count']);
        $this->assertSame(0, $item->meta['completed_count']);
        $this->assertEqualsWithDelta(0.0, $item->meta['progress'], 0.001);
        $this->assertSame('draft', $item->meta['status']);
        $this->assertSame(7, $item->meta['project_id']);
        $this->assertSame(3, $item->meta['template_id']);
        $this->assertNull($item->meta['completed_at']);
        $this->assertStringStartsWith(
            "# Market Research Q1\n\n## Goal\nFind the market size.\n\n"
            . "## Tasks\n- [ ] Collect data (Priority: High)\n- [ ] Write report\n\n"
            . "## Status\nProgress: 0%\nCreated: ",
            $item->content
        );
    }

    public function test_task_text_is_collapsed_so_it_cannot_inject_checkboxes(): void
    {
        $result = $this->createPlanTool()->execute(
            array(
                'plan_name' => 'Plan',
                'goal'      => 'Goal',
                'tasks'     => array(
                    array('text' => "Do it\n- [ ] Injected"),
                ),
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['task_count']);
        $this->assertStringContainsString('- [ ] Do it - [ ] Injected', $result['data']['markdown']);
    }

    public function test_an_unknown_priority_is_refused_by_task_index(): void
    {
        $result = $this->createPlanTool()->execute(
            array(
                'plan_name' => 'Plan',
                'goal'      => 'Goal',
                'tasks'     => array(
                    array('text' => 'Fine', 'priority' => 'high'),
                    array('text' => 'Not fine', 'priority' => 'urgent'),
                ),
            ),
            self::CONTEXT
        );

        $this->assertTrue($this->errors->isError($result));
        $this->assertArrayHasKey('tasks.1', $result['error']['data']);
    }

    public function test_updating_a_plan_rewrites_the_body_and_resets_the_counters(): void
    {
        $created = $this->createPlanTool()->execute(
            array(
                'plan_name' => 'Plan',
                'goal'      => 'Goal',
                'tasks'     => array(array('text' => 'One'), array('text' => 'Two')),
            ),
            self::CONTEXT
        );
        $planId  = (int) $created['data']['plan_id'];

        $this->updatePlanTool()->execute(
            array(
                'plan_id'      => $planId,
                'task_updates' => array(array('task_index' => 0, 'completed' => true)),
            ),
            self::CONTEXT
        );

        $this->assertSame('active', $this->store->items[ $planId ]->meta['status']);

        $updated = $this->createPlanTool()->execute(
            array(
                'task_plan_id' => $planId,
                'plan_name'    => 'Plan v2',
                'goal'         => 'New goal',
                'tasks'        => array(array('text' => 'Fresh one'), array('text' => 'Fresh two')),
            ),
            self::CONTEXT
        );

        $this->assertTrue($updated['success']);
        $this->assertTrue($updated['data']['updated']);

        $item = $this->store->items[ $planId ];

        $this->assertSame('Plan v2', $item->title);
        $this->assertStringContainsString('- [ ] Fresh one', $item->content);
        $this->assertStringNotContainsString('- [x] One', $item->content);
        $this->assertSame(0, $item->meta['completed_count']);
        $this->assertEqualsWithDelta(0.0, $item->meta['progress'], 0.001);
        $this->assertSame('active', $item->meta['status'], 'The status is left alone, as WordPress leaves it.');
    }

    public function test_updating_a_plan_requires_edit_permission(): void
    {
        $created = $this->createPlanTool()->execute(
            array('plan_name' => 'Plan', 'goal' => 'Goal', 'tasks' => array(array('text' => 'One'))),
            self::CONTEXT
        );

        $result = $this->createPlanTool()->execute(
            array(
                'task_plan_id' => $created['data']['plan_id'],
                'plan_name'    => 'Hijacked',
                'goal'         => 'Goal',
                'tasks'        => array(array('text' => 'One')),
            ),
            array('user_id' => 2)
        );

        $this->assertTrue($this->errors->isError($result));
        $this->assertSame('forbidden', $result['error']['code']);
        $this->assertSame('Plan', $this->store->items[ $created['data']['plan_id'] ]->title);
    }

    public function test_updating_an_unknown_plan_is_not_found(): void
    {
        $result = $this->createPlanTool()->execute(
            array(
                'task_plan_id' => 999,
                'plan_name'    => 'Plan',
                'goal'         => 'Goal',
                'tasks'        => array(array('text' => 'One')),
            ),
            self::CONTEXT
        );

        $this->assertSame('task_plan_not_found', $result['error']['code']);
    }

    // ─── get_task_plan ────────────────────────────────────────────────────

    public function test_a_plan_is_parsed_into_tasks_and_progress(): void
    {
        $plan = $this->storePlan(
            "# Plan\n\n## Tasks\n- [x] Done (Priority: High)\n- [ ] Open\n",
            array('goal' => 'Ship it', 'status' => 'active')
        );

        $result = $this->getPlanTool()->execute(array('plan_id' => $plan->id), self::CONTEXT);
        $data   = $result['data'];

        $this->assertTrue($result['success']);
        $this->assertSame('Ship it', $data['goal']);
        $this->assertSame('active', $data['status']);
        $this->assertSame(2, $data['task_count']);
        $this->assertSame(1, $data['completed_count']);
        $this->assertEqualsWithDelta(50.0, $data['progress'], 0.001);
        $this->assertTrue($data['tasks'][0]['completed']);
        $this->assertSame('Done', $data['tasks'][0]['text']);
        $this->assertSame('high', $data['tasks'][0]['priority']);
        $this->assertFalse($data['tasks'][1]['completed']);
        $this->assertNull($data['tasks'][1]['priority']);
        $this->assertNull($data['completed_at']);
    }

    public function test_include_history_is_accepted_and_inert(): void
    {
        $plan = $this->storePlan(self::PLAN);

        $result = $this->getPlanTool()->execute(
            array('plan_id' => $plan->id, 'include_history' => true),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('history', $result['data']);
    }

    public function test_an_unknown_plan_is_an_error(): void
    {
        $result = $this->getPlanTool()->execute(array('plan_id' => 999), self::CONTEXT);

        $this->assertSame('task_plan_not_found', $result['error']['code']);
        $this->assertStringContainsString('Task plan #999 not found', $result['error']['message']);
    }

    public function test_a_missing_plan_id_is_an_error(): void
    {
        $result = $this->getPlanTool()->execute(array(), self::CONTEXT);

        $this->assertSame('missing_plan_id', $result['error']['code']);
    }

    public function test_project_id_is_reported_only_when_set(): void
    {
        $plain = $this->storePlan(self::PLAN);
        $linked = $this->storePlan(self::PLAN, array('project_id' => 12));

        $this->assertArrayNotHasKey(
            'project_id',
            $this->getPlanTool()->execute(array('plan_id' => $plain->id), self::CONTEXT)['data']
        );
        $this->assertSame(
            12,
            $this->getPlanTool()->execute(array('plan_id' => $linked->id), self::CONTEXT)['data']['project_id']
        );
    }

    // ─── update_task_plan ─────────────────────────────────────────────────

    public function test_marking_tasks_complete_updates_the_counts(): void
    {
        $plan = $this->storePlan(self::PLAN);

        $result = $this->updatePlanTool()->execute(
            array(
                'plan_id'      => $plan->id,
                'task_updates' => array(
                    array('task_index' => 0, 'completed' => true),
                    array('task_index' => 2, 'completed' => true),
                ),
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['data']['task_count']);
        $this->assertSame(2, $result['data']['completed_count']);
        $this->assertSame(1, $result['data']['remaining_tasks']);
        $this->assertEqualsWithDelta(66.7, $result['data']['progress'], 0.01);
        $this->assertSame('active', $this->store->items[ $plan->id ]->meta['status']);
        $this->assertNull($this->store->items[ $plan->id ]->meta['completed_at']);
    }

    public function test_completing_every_task_stamps_and_then_clears_completion(): void
    {
        $plan = $this->storePlan(self::PLAN);

        $all = $this->updatePlanTool()->execute(
            array(
                'plan_id'      => $plan->id,
                'task_updates' => array(
                    array('task_index' => 0, 'completed' => true),
                    array('task_index' => 1, 'completed' => true),
                    array('task_index' => 2, 'completed' => true),
                ),
            ),
            self::CONTEXT
        );

        $this->assertEqualsWithDelta(100.0, $all['data']['progress'], 0.001);
        $this->assertSame('completed', $this->store->items[ $plan->id ]->meta['status']);
        $this->assertNotSame('', $this->store->items[ $plan->id ]->meta['completed_at']);

        $reopened = $this->updatePlanTool()->execute(
            array(
                'plan_id'      => $plan->id,
                'task_updates' => array(array('task_index' => 0, 'completed' => false)),
            ),
            self::CONTEXT
        );

        $this->assertEqualsWithDelta(66.7, $reopened['data']['progress'], 0.01);
        $this->assertSame('active', $this->store->items[ $plan->id ]->meta['status']);
        $this->assertNull($this->store->items[ $plan->id ]->meta['completed_at']);
    }

    public function test_new_tasks_are_appended_and_the_plan_survives(): void
    {
        $plan = $this->storePlan(self::PLAN);

        $result = $this->updatePlanTool()->execute(
            array(
                'plan_id'   => $plan->id,
                'new_tasks' => array(array('text' => 'Fourth', 'priority' => 'low')),
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(4, $result['data']['task_count']);

        $markdown = $result['data']['updated_markdown'];

        $this->assertStringContainsString('- [ ] One', $markdown);
        $this->assertStringContainsString('- [ ] Two', $markdown);
        $this->assertStringContainsString('- [ ] Three', $markdown);
        $this->assertStringContainsString('- [ ] Fourth (Priority: Low)', $markdown);
        $this->assertStringContainsString("## Goal\nGoal", $markdown);
    }

    public function test_an_out_of_range_index_changes_nothing(): void
    {
        $plan = $this->storePlan(self::PLAN);

        $result = $this->updatePlanTool()->execute(
            array(
                'plan_id'      => $plan->id,
                'task_updates' => array(array('task_index' => 99, 'completed' => true)),
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['completed_count']);
        $this->assertEqualsWithDelta(0.0, $result['data']['progress'], 0.001);
    }

    public function test_appending_to_a_plan_without_a_tasks_heading_creates_one(): void
    {
        $plan = $this->storePlan("# Plan\n\n## Goal\nA goal without a task section.\n");

        $result = $this->updatePlanTool()->execute(
            array('plan_id' => $plan->id, 'new_tasks' => array(array('text' => 'First'))),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString("## Tasks\n- [ ] First", $result['data']['updated_markdown']);
    }

    public function test_updating_requires_edit_permission(): void
    {
        $plan = $this->storePlan(self::PLAN);

        $result = $this->updatePlanTool()->execute(
            array(
                'plan_id'      => $plan->id,
                'task_updates' => array(array('task_index' => 0, 'completed' => true)),
            ),
            array('user_id' => 2)
        );

        $this->assertTrue($this->errors->isError($result));
        $this->assertSame('forbidden', $result['error']['code']);
        $this->assertFalse($this->store->items[ $plan->id ]->meta['completed_count'] > 0);
    }

    public function test_an_invalid_new_task_is_refused_by_index(): void
    {
        $plan = $this->storePlan(self::PLAN);

        $result = $this->updatePlanTool()->execute(
            array('plan_id' => $plan->id, 'new_tasks' => array(array('text' => ''))),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('new_tasks.0', $result['error']['data']);
    }

    // ─── create_template ──────────────────────────────────────────────────

    public function test_a_template_is_stored_as_a_draft_with_placeholders(): void
    {
        $result = $this->createTemplateTool()->execute(
            array(
                'template_name'     => 'Research',
                'description'       => 'A research template.',
                'category'          => 'research',
                'markdown_template' => "# {{goal}}\n- [ ] Search {{topic}}\n- [ ] Read {{source_count}} sources\n",
                'default_config'    => array('max_iterations' => 10, 'ignored' => array('nested')),
                'tags'              => array('alpha', 'beta', 'alpha'),
                'version'           => '1.2.0',
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(array('goal', 'topic', 'source_count'), $result['data']['placeholders']);
        $this->assertSame('content_store', $result['data']['storage_type']);
        $this->assertFalse($result['data']['updated']);

        $item = $this->store->items[ $result['data']['template_id'] ];

        $this->assertSame('mcp_task_template', $item->type);
        $this->assertSame('draft', $item->status);
        $this->assertSame('A research template.', $item->excerpt);
        $this->assertSame('research', $item->meta['category']);
        $this->assertSame(array('max_iterations' => 10), $item->meta['default_config']);
        $this->assertSame(array('alpha', 'beta'), $item->meta['tags']);
        $this->assertSame('1.2.0', $item->meta['version']);
        $this->assertSame(0, $item->meta['usage_count']);
        $this->assertEqualsWithDelta(0.0, $item->meta['success_rate'], 0.001);
        $this->assertSame(0, $item->meta['avg_completion_time']);
    }

    public function test_a_toolkit_category_is_accepted(): void
    {
        $result = $this->createTemplateTool()->execute(
            array(
                'template_name'     => 'Store launch',
                'description'       => 'E-commerce template.',
                'category'          => 'ecommerce',
                'markdown_template' => "# Launch\n- [ ] Set up\n",
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('ecommerce', $this->store->items[ $result['data']['template_id'] ]->meta['category']);
    }

    public function test_an_unknown_category_is_refused(): void
    {
        $result = $this->createTemplateTool()->execute(
            array(
                'template_name'     => 'Nope',
                'description'       => 'Invalid.',
                'category'          => 'nope',
                'markdown_template' => "# Nope\n",
            ),
            self::CONTEXT
        );

        $this->assertTrue($this->errors->isError($result));
        $this->assertArrayHasKey('category', $result['error']['data']);
    }

    public function test_the_required_template_fields_are_named(): void
    {
        $result = $this->createTemplateTool()->execute(array(), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('template_name', $result['error']['data']);
        $this->assertArrayHasKey('description', $result['error']['data']);
        $this->assertArrayHasKey('markdown_template', $result['error']['data']);
    }

    public function test_a_template_update_keeps_its_status(): void
    {
        $created = $this->createTemplateTool()->execute(
            array(
                'template_name'     => 'Original',
                'description'       => 'First.',
                'category'          => 'content',
                'markdown_template' => "# {{goal}}\n",
            ),
            self::CONTEXT
        );
        $id = (int) $created['data']['template_id'];

        $this->store->update(
            $id,
            new UpdateContentCommand(userId: 1, status: 'publish')
        );

        $updated = $this->createTemplateTool()->execute(
            array(
                'template_id'       => $id,
                'template_name'     => 'Renamed',
                'description'       => 'Second.',
                'category'          => 'marketing',
                'markdown_template' => "# {{goal}}\n- [ ] New\n",
                'tags'              => array('updated'),
            ),
            self::CONTEXT
        );

        $this->assertTrue($updated['success']);
        $this->assertTrue($updated['data']['updated']);

        $item = $this->store->items[ $id ];

        $this->assertSame('publish', $item->status);
        $this->assertSame('Renamed', $item->title);
        $this->assertSame('marketing', $item->meta['category']);
        $this->assertSame(array('updated'), $item->meta['tags']);
    }

    public function test_updating_a_template_requires_edit_permission(): void
    {
        $created = $this->createTemplateTool()->execute(
            array(
                'template_name'     => 'Original',
                'description'       => 'First.',
                'category'          => 'content',
                'markdown_template' => "# {{goal}}\n",
            ),
            self::CONTEXT
        );

        $result = $this->createTemplateTool()->execute(
            array(
                'template_id'       => $created['data']['template_id'],
                'template_name'     => 'Hijacked',
                'description'       => 'Second.',
                'category'          => 'content',
                'markdown_template' => "# {{goal}}\n",
            ),
            array('user_id' => 2)
        );

        $this->assertSame('forbidden', $result['error']['code']);
    }

    // ─── list_templates ───────────────────────────────────────────────────

    public function test_templates_are_listed_and_sorted_by_usage(): void
    {
        $this->seedTemplatesForListing();

        $result = $this->listTemplatesTool()->execute(array(), self::CONTEXT);
        $data   = $result['data'];

        $this->assertTrue($result['success']);
        $this->assertSame(4, $data['total_count']);
        $this->assertSame(array('Beta', 'Alpha', 'Gamma', 'Delta'), array_column($data['templates'], 'template_name'));
        $this->assertEquals(array('research' => 2, 'content' => 1, 'ecommerce' => 1), $data['category_counts']);
        $this->assertFalse($data['truncated']);
        $this->assertSame('all', $data['filters']['category']);
        $this->assertSame('usage_count', $data['filters']['sort_by']);
    }

    public function test_success_rate_sorting_is_honoured(): void
    {
        $this->seedTemplatesForListing();

        $result = $this->listTemplatesTool()->execute(
            array('sort_by' => 'success_rate', 'order' => 'desc'),
            self::CONTEXT
        );

        $this->assertSame(
            array('Alpha', 'Beta', 'Gamma', 'Delta'),
            array_column($result['data']['templates'], 'template_name')
        );
    }

    public function test_status_and_category_filters(): void
    {
        $this->seedTemplatesForListing();

        $published = $this->listTemplatesTool()->execute(array('status' => 'published'), self::CONTEXT);
        $this->assertSame(array('Alpha', 'Gamma'), array_column($published['data']['templates'], 'template_name'));

        $research = $this->listTemplatesTool()->execute(array('category' => 'research'), self::CONTEXT);
        $this->assertSame(array('Alpha', 'Gamma'), array_column($research['data']['templates'], 'template_name'));

        $archived = $this->listTemplatesTool()->execute(array('status' => 'archived'), self::CONTEXT);
        $this->assertSame(array('Delta'), array_column($archived['data']['templates'], 'template_name'));

        $ecommerce = $this->listTemplatesTool()->execute(array('category' => 'ecommerce'), self::CONTEXT);
        $this->assertSame(array('Delta'), array_column($ecommerce['data']['templates'], 'template_name'));
    }

    public function test_limit_cuts_the_page_but_not_the_total(): void
    {
        $this->seedTemplatesForListing();

        $result = $this->listTemplatesTool()->execute(array('limit' => 2), self::CONTEXT);

        $this->assertCount(2, $result['data']['templates']);
        $this->assertSame(4, $result['data']['total_count']);
    }

    public function test_invalid_filters_are_refused(): void
    {
        $category = $this->listTemplatesTool()->execute(array('category' => 'nope'), self::CONTEXT);
        $this->assertArrayHasKey('category', $category['error']['data']);

        $status = $this->listTemplatesTool()->execute(array('status' => 'live'), self::CONTEXT);
        $this->assertArrayHasKey('status', $status['error']['data']);

        $sort = $this->listTemplatesTool()->execute(array('sort_by' => 'random'), self::CONTEXT);
        $this->assertArrayHasKey('sort_by', $sort['error']['data']);

        $order = $this->listTemplatesTool()->execute(array('order' => 'sideways'), self::CONTEXT);
        $this->assertArrayHasKey('order', $order['error']['data']);
    }

    // ─── instantiate_template ─────────────────────────────────────────────

    public function test_variables_are_replaced_and_the_plan_uses_the_plan_schema(): void
    {
        $template = $this->storeTemplate(
            "# Research {{topic}}\n\n## Tasks\n- [ ] Search {{topic}}\n- [ ] Read {{count}} sources\n\n**Max Iterations:** {{max_iterations}}\n",
            array('default_config' => array('max_iterations' => 15, 'token_budget' => 100))
        );

        $result = $this->instantiateTool()->execute(
            array(
                'template_id'      => $template->id,
                'variables'        => array('topic' => 'AI', 'count' => '3', 'max_iterations' => '15'),
                'config_overrides' => array('max_iterations' => 30),
                'project_id'       => 9,
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('Research AI', $result['data']['goal']);
        $this->assertSame(2, $result['data']['task_count']);
        $this->assertSame(
            array('max_iterations' => 30, 'token_budget' => 100),
            $result['data']['config']
        );
        $this->assertSame(1, $result['data']['usage_count']);

        $plan = $this->store->items[ $result['data']['plan_id'] ];

        $this->assertSame('mcp_task_plan', $plan->type);
        $this->assertSame('Research AI', $plan->title);
        $this->assertStringContainsString('Search AI', $plan->content);
        $this->assertSame('Research AI', $plan->meta['goal']);
        $this->assertSame(2, $plan->meta['task_count']);
        $this->assertSame('active', $plan->meta['status']);
        $this->assertEqualsWithDelta(0.0, $plan->meta['progress'], 0.001);
        $this->assertSame($template->id, $plan->meta['template_id']);
        $this->assertSame(9, $plan->meta['project_id']);

        $this->assertSame(1, $this->store->items[ $template->id ]->meta['usage_count']);
    }

    public function test_a_missing_variable_is_named(): void
    {
        $template = $this->storeTemplate("# Research {{topic}}\n- [ ] Read {{count}} sources\n");

        $result = $this->instantiateTool()->execute(
            array('template_id' => $template->id, 'variables' => array('topic' => 'AI')),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('count', $result['error']['data']);
        $this->assertCount(1, $this->store->items, 'The template is the only record; no plan was created.');
        $this->assertSame('mcp_task_template', $this->store->items[ $template->id ]->type);
    }

    public function test_a_non_scalar_variable_is_skipped_and_remains_missing(): void
    {
        $template = $this->storeTemplate("# Research {{topic}}\n");

        $result = $this->instantiateTool()->execute(
            array('template_id' => $template->id, 'variables' => array('topic' => array('AI'))),
            self::CONTEXT
        );

        $this->assertArrayHasKey('topic', $result['error']['data']);
    }

    public function test_an_unknown_template_is_an_error(): void
    {
        $result = $this->instantiateTool()->execute(
            array('template_id' => 999, 'variables' => array()),
            self::CONTEXT
        );

        $this->assertSame('template_not_found', $result['error']['code']);
    }

    public function test_a_non_author_does_not_increment_the_counter(): void
    {
        $template = $this->storeTemplate("# Research {{topic}}\n- [ ] Search\n", array('usage_count' => 4));

        $result = $this->instantiateTool()->execute(
            array('template_id' => $template->id, 'variables' => array('topic' => 'AI')),
            array('user_id' => 2)
        );

        $this->assertTrue($result['success']);
        $this->assertSame(4, $result['data']['usage_count']);
        $this->assertSame(4, $this->store->items[ $template->id ]->meta['usage_count']);
    }

    // ─── seed_template_library ────────────────────────────────────────────

    public function test_the_library_seeds_all_fourteen_templates(): void
    {
        $this->assertCount(14, TaskTemplateLibrary::all());

        $result = $this->seedTool()->execute(array(), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame(14, $result['data']['templates_created']);
        $this->assertSame(0, $result['data']['templates_skipped']);
        $this->assertSame(0, $result['data']['templates_errors']);
        $this->assertCount(14, $this->store->items);

        $names = array_column($result['data']['created'], 'name');

        $this->assertContains('Comprehensive Market Research', $names);

        foreach ($this->store->items as $item) {
            $this->assertSame('publish', $item->status);
            $this->assertSame('mcp_task_template', $item->type);
        }
    }

    public function test_a_second_run_skips_every_existing_template(): void
    {
        $this->seedTool()->execute(array(), self::CONTEXT);
        $second = $this->seedTool()->execute(array(), self::CONTEXT);

        $this->assertSame(0, $second['data']['templates_created']);
        $this->assertSame(14, $second['data']['templates_skipped']);
        $this->assertSame(0, $second['data']['templates_errors']);
        $this->assertCount(14, $this->store->items);
    }

    public function test_overwrite_updates_instead_of_duplicating(): void
    {
        $this->seedTool()->execute(array(), self::CONTEXT);
        $second = $this->seedTool()->execute(array('overwrite' => true), self::CONTEXT);

        $this->assertSame(14, $second['data']['templates_created']);
        $this->assertSame(0, $second['data']['templates_skipped']);
        $this->assertSame(0, $second['data']['templates_errors']);
        $this->assertCount(14, $this->store->items, 'Overwrite must not add a second copy of the library.');
    }

    public function test_seeding_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->seedTool()->execute(array(), array('user_id' => 0));
    }

    // ─── create_execution_prompt ──────────────────────────────────────────

    public function test_the_prompt_contains_every_section(): void
    {
        $plan = $this->storePlan(
            "# Plan\n\n## Tasks\n- [x] Collect\n- [ ] Write docs\n- [ ] Test\n",
            array(
                'goal'            => 'Ship it',
                'status'          => 'active',
                'task_count'      => 3,
                'completed_count' => 1,
                'progress'        => 33.3,
            ),
            'Execution plan'
        );

        $result = $this->promptTool()->execute(
            array(
                'plan_id'                => $plan->id,
                'iteration_number'       => 2,
                'previous_result'        => 'Built the API',
                'constraints'            => array(
                    'remaining_iterations' => 5,
                    'remaining_tokens'     => 1200,
                    'time_remaining_hours' => 1.5,
                    'max_tool_calls'       => 4,
                ),
                'assistant_capabilities' => array('get_post', 'create_post'),
            ),
            self::CONTEXT
        );

        $prompt = $result['data']['prompt_content'];

        $this->assertStringStartsWith('# Execution Prompt: Execution plan — Iteration 2', $prompt);
        $this->assertStringContainsString("## Objective\nShip it", $prompt);
        $this->assertStringContainsString("## Current Task\nWrite docs", $prompt);
        $this->assertStringContainsString('Completed: 1 / 3 tasks (33.3%)', $prompt);
        $this->assertStringContainsString('Status: active', $prompt);
        $this->assertStringContainsString("## Previous Iteration Result\nBuilt the API", $prompt);
        $this->assertStringContainsString('- get_post', $prompt);
        $this->assertStringContainsString('- Remaining iterations: 5', $prompt);
        $this->assertStringContainsString('- Remaining tokens: 1,200', $prompt);
        $this->assertStringContainsString('- Time remaining: 1.5 hours', $prompt);
        $this->assertStringContainsString('- Max tool calls this iteration: 4', $prompt);
        $this->assertStringContainsString('- Current task is completed', $prompt);
        $this->assertStringContainsString('Do NOT emit EXIT_SIGNAL: true', $prompt);
        $this->assertSame('Write docs', $result['data']['next_incomplete']);
        $this->assertSame(strlen($prompt), $result['data']['prompt_length']);
    }

    public function test_the_prompt_is_plain_text_not_html_escaped(): void
    {
        $plan = $this->storePlan(
            "# Plan\n\n## Tasks\n- [ ] Open\n",
            array('goal' => "Improve O'Reilly & Sons"),
            "Client's plan"
        );

        $prompt = $this->promptTool()->execute(array('plan_id' => $plan->id), self::CONTEXT)['data']['prompt_content'];

        $this->assertStringContainsString("Client's plan", $prompt);
        $this->assertStringContainsString("O'Reilly & Sons", $prompt);
        $this->assertStringNotContainsString('&#039;', $prompt);
        $this->assertStringNotContainsString('&amp;', $prompt);
    }

    public function test_a_current_task_override_wins(): void
    {
        $plan = $this->storePlan(self::PLAN);

        $result = $this->promptTool()->execute(
            array('plan_id' => $plan->id, 'current_task' => 'Do the specific thing'),
            self::CONTEXT
        );

        $this->assertStringContainsString("## Current Task\nDo the specific thing", $result['data']['prompt_content']);
        $this->assertSame('One', $result['data']['next_incomplete']);
    }

    public function test_all_tasks_complete_says_so(): void
    {
        $plan = $this->storePlan("# Plan\n\n## Tasks\n- [x] One\n- [x] Two\n");

        $result = $this->promptTool()->execute(array('plan_id' => $plan->id), self::CONTEXT);

        $this->assertNull($result['data']['next_incomplete']);
        $this->assertStringContainsString(
            'All tasks are complete. Verify work and emit EXIT_SIGNAL.',
            $result['data']['prompt_content']
        );
    }

    public function test_success_criteria_meta_is_used(): void
    {
        $plan = $this->storePlan(
            self::PLAN,
            array('success_criteria' => '- Revenue plan approved')
        );

        $result = $this->promptTool()->execute(array('plan_id' => $plan->id), self::CONTEXT);

        $this->assertStringContainsString(
            "## Success Criteria\n- Revenue plan approved",
            $result['data']['prompt_content']
        );
    }

    public function test_no_constraints_says_so(): void
    {
        $plan = $this->storePlan(self::PLAN);

        $result = $this->promptTool()->execute(array('plan_id' => $plan->id), self::CONTEXT);

        $this->assertStringContainsString(
            '- No specific constraints. Proceed efficiently.',
            $result['data']['prompt_content']
        );
    }

    public function test_the_prompt_for_an_unknown_plan_is_an_error(): void
    {
        $result = $this->promptTool()->execute(array('plan_id' => 999), self::CONTEXT);

        $this->assertSame('plan_not_found', $result['error']['code']);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $meta Extra or overriding meta.
     */
    private function storePlan(string $content, array $meta = array(), string $title = 'Plan'): ContentItem
    {
        return $this->store->create(
            new CreateContentCommand(
                title: $title,
                type: 'mcp_task_plan',
                authorId: 1,
                status: 'publish',
                content: $content,
                meta: array_merge(
                    array(
                        'goal'            => 'Goal',
                        'task_count'      => 0,
                        'completed_count' => 0,
                        'progress'        => 0.0,
                        'status'          => 'draft',
                    ),
                    $meta
                ),
            )
        );
    }

    /**
     * @param array<string, mixed> $meta Extra or overriding meta.
     */
    private function storeTemplate(string $markdown, array $meta = array(), int $authorId = 1): ContentItem
    {
        return $this->store->create(
            new CreateContentCommand(
                title: 'Template ' . ( count($this->store->items) + 1 ),
                type: 'mcp_task_template',
                authorId: $authorId,
                status: 'publish',
                content: $markdown,
                excerpt: 'A template.',
                meta: array_merge(
                    array(
                        'category'            => 'custom',
                        'default_config'      => array(),
                        'tags'                => array(),
                        'version'             => '1.0.0',
                        'usage_count'         => 0,
                        'success_rate'        => 0.0,
                        'avg_completion_time' => 0,
                    ),
                    $meta
                ),
            )
        );
    }

    private function seedTemplatesForListing(): void
    {
        $this->storeTemplate('# Alpha', array('category' => 'research', 'usage_count' => 5, 'success_rate' => 90.0));
        $this->storeTemplate('# Beta', array('category' => 'content', 'usage_count' => 20, 'success_rate' => 50.0));
        $this->storeTemplate('# Gamma', array('category' => 'research', 'usage_count' => 1, 'success_rate' => 10.0));
        $this->storeTemplate('# Delta', array('category' => 'ecommerce', 'usage_count' => 0, 'success_rate' => 0.0));

        $this->store->items[1] = $this->rename($this->store->items[1], 'Alpha', 'publish');
        $this->store->items[2] = $this->rename($this->store->items[2], 'Beta', 'draft');
        $this->store->items[3] = $this->rename($this->store->items[3], 'Gamma', 'publish');
        $this->store->items[4] = $this->rename($this->store->items[4], 'Delta', 'private');
    }

    private function rename(ContentItem $item, string $title, string $status): ContentItem
    {
        return new ContentItem(
            id: $item->id,
            title: $title,
            content: $item->content,
            status: $status,
            type: $item->type,
            authorId: $item->authorId,
            createdAt: $item->createdAt,
            updatedAt: $item->updatedAt,
            meta: $item->meta,
            taxonomy: $item->taxonomy,
            excerpt: $item->excerpt,
        );
    }

    private function createPlanTool(): CreateTaskPlanTool
    {
        return new CreateTaskPlanTool($this->errors, $this->store);
    }

    private function getPlanTool(): GetTaskPlanTool
    {
        return new GetTaskPlanTool($this->errors, $this->store);
    }

    private function updatePlanTool(): UpdateTaskPlanTool
    {
        return new UpdateTaskPlanTool($this->errors, $this->store);
    }

    private function createTemplateTool(): CreateTemplateTool
    {
        return new CreateTemplateTool($this->errors, $this->store);
    }

    private function listTemplatesTool(): ListTemplatesTool
    {
        return new ListTemplatesTool($this->errors, $this->store);
    }

    private function instantiateTool(): InstantiateTemplateTool
    {
        return new InstantiateTemplateTool($this->errors, $this->store);
    }

    private function seedTool(): SeedTemplateLibraryTool
    {
        return new SeedTemplateLibraryTool($this->errors, $this->store);
    }

    private function promptTool(): CreateExecutionPromptTool
    {
        return new CreateExecutionPromptTool($this->errors, $this->store);
    }
}
