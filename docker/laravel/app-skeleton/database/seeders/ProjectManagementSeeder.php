<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;

/**
 * Seeds the project-management family (090-e / 090-f Phase 2).
 *
 * Writes through ContentStoreInterface, so the rows land in whatever store the
 * adapter is bound to — nvoos_posts here, CPT + postmeta on WordPress.
 */
class ProjectManagementSeeder extends Seeder
{
    /**
     * Seed one project with two tasks, unless the family already has rows.
     */
    public function run(): void
    {
        $store = app(ContentStoreInterface::class);

        $existing = $store->query(new ContentQuery(
            types: array('mcp_ai_project'),
            perPage: 1,
        ));

        if ($existing->total > 0) {
            return;
        }

        $project = $store->create(new CreateContentCommand(
            title: 'Website relaunch',
            type: 'mcp_ai_project',
            authorId: 0,
            status: 'publish',
            content: 'Ship the new marketing site.',
            meta: array(
                '_project_status'      => 'active',
                '_project_start_date'  => '2026-01-05',
                '_project_end_date'    => '2026-03-31',
                '_project_assigned_to' => array('1'),
            ),
        ));

        $spec = $store->create(new CreateContentCommand(
            title: 'Write the spec',
            type: 'mcp_ai_task',
            authorId: 0,
            status: 'publish',
            content: 'Capture scope, pages and acceptance criteria.',
            meta: array(
                '_task_project_id'  => $project->id,
                '_task_status'      => 'completed',
                '_task_priority'    => 'high',
                '_task_category'    => 'documentation',
                '_task_assigned_to' => 1,
                '_task_due_date'    => '2026-01-15',
            ),
        ));

        $store->create(new CreateContentCommand(
            title: 'Build the pages',
            type: 'mcp_ai_task',
            authorId: 0,
            status: 'publish',
            content: 'Implement the templates from the spec.',
            meta: array(
                '_task_project_id'       => $project->id,
                '_task_status'           => 'todo',
                '_task_priority'         => 'high',
                '_task_category'         => 'feature',
                '_task_assigned_to'      => 1,
                '_task_due_date'         => '2026-02-20',
                '_task_depends_on'       => array($spec->id),
                '_task_estimated_effort' => 12,
            ),
        ));
    }
}
