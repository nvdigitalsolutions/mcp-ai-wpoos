<?php

namespace App\Http\Controllers\Pro;

use Illuminate\Http\JsonResponse;

/**
 * Pro Records schema (`/api/v1/pro/records/schema`).
 *
 * The WordPress catalogue covers CRM, PM, professions, F&B and commerce CPTs.
 * The Laravel runtime owns no such post types, so the honest subset is the
 * two core content entities — `posts` and `pages` — served from
 * `nvoos_posts` by the `wp/v2`-compatible shim at `/api/v1/wp`.
 *
 * Content entities ship no meta columns on WordPress either (the Records page
 * falls back to title/date/status), so `columns` stays empty.
 */
class RecordsController
{
    /**
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

    public function schema(): JsonResponse
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

        return response()->json(['entities' => $entities]);
    }

    /**
     * Remote-site browsing is a WordPress-to-WordPress proxy; the Laravel
     * runtime has no Remote Sites integration, so it fails explicitly.
     */
    public function remoteIndex(string $entity): JsonResponse
    {
        return $this->unavailable(
            sprintf('Remote record browsing for `%s` is not available on the Laravel runtime.', $entity),
        );
    }

    public function remoteShow(string $entity, int $id): JsonResponse
    {
        return $this->unavailable(
            sprintf('Remote record %d for `%s` is not available on the Laravel runtime.', $id, $entity),
        );
    }

    /**
     * Create-from-research is bound to the Pro deep_research pipeline on
     * WordPress; it is not ported yet.
     */
    public function research(string $entity): JsonResponse
    {
        return $this->unavailable(
            sprintf('Create-from-research for `%s` is not available on the Laravel runtime yet.', $entity),
        );
    }

    private function unavailable(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'ok' => false,
            'message' => $message,
            'error' => [
                'code' => 'not_implemented',
                'message' => $message,
            ],
        ], 501);
    }
}
