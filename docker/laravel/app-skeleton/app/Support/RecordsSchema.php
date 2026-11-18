<?php

namespace App\Support;

/**
 * Record entities this runtime can serve — Proposal 149 §52.
 *
 * The console discovers record entities from `/api/v1/pro/records/schema`
 * (090-F Phase 2) and the `/admin/paper-store` page renders the same list, so
 * the table lives here rather than in the controller: two surfaces reading one
 * catalogue cannot disagree about which entities exist.
 *
 * The Laravel runtime owns no CRM/PM/commerce post types, so this is the honest
 * subset of WordPress's catalogue — the four entities whose `wp/v2` shim is
 * mounted at `/api/v1/wp`. `columns` stays empty on both runtimes: content
 * entities ship no meta columns, and the Records page falls back to
 * title/date/status.
 */
final class RecordsSchema
{
    /**
     * Key => entity definition.
     *
     * @var array<string, array<string, string>>
     */
    private const ENTITIES = [
        'post' => [
            'post_type' => 'post',
            'rest_base' => 'posts',
            'label' => 'Posts',
            'singular' => 'Post',
            'icon' => '📰',
            'group' => 'content',
        ],
        'page' => [
            'post_type' => 'page',
            'rest_base' => 'pages',
            'label' => 'Pages',
            'singular' => 'Page',
            'icon' => '📄',
            'group' => 'content',
        ],
        'task' => [
            'post_type' => 'mcp_ai_task',
            'rest_base' => 'tasks',
            'label' => 'Tasks',
            'singular' => 'Task',
            'icon' => '✅',
            'group' => 'pm',
        ],
        'project' => [
            'post_type' => 'mcp_ai_project',
            'rest_base' => 'projects',
            'label' => 'Projects',
            'singular' => 'Project',
            'icon' => '📁',
            'group' => 'pm',
        ],
    ];

    /**
     * The schema payload `GET /pro/records/schema` serves.
     *
     * Key order is part of the published shape; `Phase3cTest` and
     * `ProjectManagementAdminTest` pin it.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function entities(): array
    {
        $entities = [];

        foreach (self::ENTITIES as $key => $config) {
            $entities[$key] = [
                'key' => $key,
                'postType' => $config['post_type'],
                'restBase' => $config['rest_base'],
                'label' => $config['label'],
                'singular' => $config['singular'],
                'icon' => $config['icon'],
                'group' => $config['group'],
                'columns' => [],
            ];
        }

        return $entities;
    }
}
