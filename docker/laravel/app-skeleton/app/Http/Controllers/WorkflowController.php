<?php

namespace App\Http\Controllers;

use App\Support\WorkflowGraphValidator;
use App\Support\WorkflowRecords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Exception\AccessDeniedException;
use Nvoos\Core\Domain\Exception\NotFoundException;

/**
 * Workflows (`/api/v1/orchestration/workflows`).
 *
 * Port of `WP_MCP_AI_REST_Workflow_CPT_Controller` (090-h H2). The console's
 * `WorkflowBuilderClient` reads **bare** arrays and objects — WordPress answers
 * through `rest_ensure_response()` — so this controller unwraps too rather
 * than using the `{success, data}` envelope.
 *
 * Storage is a content record, not a new table (090-h §4); the meta contract
 * lives in {@see WorkflowRecords} because the run controller reads the same
 * graph.
 *
 * Deletion trashes rather than destroys, matching `wp_trash_post()`. The list
 * query only returns `publish`, so a trashed workflow disappears from the
 * console exactly as it does on WordPress, and nothing is lost.
 */
class WorkflowController
{
    public function __construct(private readonly ContentStoreInterface $content)
    {
    }

    /**
     * GET /orchestration/workflows — workflow summaries, newest edit first.
     */
    public function index(): JsonResponse
    {
        $collection = $this->content->query(new ContentQuery(
            types: [WorkflowRecords::TYPE],
            statuses: ['publish'],
            orderBy: 'modified',
            order: 'DESC',
            perPage: 100,
        ));

        return response()->json(array_map([WorkflowRecords::class, 'summarise'], $collection->items));
    }

    /**
     * POST /orchestration/workflows — create a workflow.
     */
    public function store(Request $request): JsonResponse
    {
        $name = $this->name($request);

        if ('' === $name) {
            return $this->fail('invalid_data', 'Workflow data is invalid or missing a name.', 422);
        }

        $graph = WorkflowGraphValidator::validate($request->input('graph', []));

        if ([] !== $graph['errors']) {
            return $this->invalidGraph($graph['errors']);
        }

        try {
            $item = $this->content->create(new CreateContentCommand(
                title: $name,
                type: WorkflowRecords::TYPE,
                authorId: (int) $request->user()->getAuthIdentifier(),
                status: 'publish',
                content: '',
                meta: [
                    WorkflowRecords::META_GRAPH => $graph['graph'],
                    WorkflowRecords::META_VERSION => WorkflowRecords::DEFAULT_VERSION,
                    WorkflowRecords::META_TAGS => $this->tags($request),
                ],
            ));
        } catch (AccessDeniedException $exception) {
            return $this->fail('forbidden', $exception->getMessage(), 403);
        }

        return response()->json([
            'id' => $item->id,
            'message' => 'Workflow created.',
        ]);
    }

    /**
     * GET /orchestration/workflows/{id} — one workflow with its graph.
     */
    public function show(int $id): JsonResponse
    {
        $item = WorkflowRecords::find($this->content, $id);

        if (null === $item) {
            return $this->notFound();
        }

        return response()->json([
            'id' => $item->id,
            'title' => $item->title,
            'description' => $item->content,
            'version' => WorkflowRecords::version($item),
            'tags' => WorkflowRecords::readTags($item),
            'graph' => WorkflowRecords::readGraph($item),
            'modified' => $item->updatedAt->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * PUT /orchestration/workflows/{id} — update the graph, name or tags.
     *
     * The patch version bumps on every save, matching
     * `WP_MCP_AI_Workflow_CPT::bump_version()`.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $item = WorkflowRecords::find($this->content, $id);

        if (null === $item) {
            return $this->notFound();
        }

        $meta = [];

        if (is_array($request->input('graph'))) {
            $graph = WorkflowGraphValidator::validate($request->input('graph'));

            if ([] !== $graph['errors']) {
                return $this->invalidGraph($graph['errors']);
            }

            $meta[WorkflowRecords::META_GRAPH] = $graph['graph'];
        }

        if (is_array($request->input('tags'))) {
            $meta[WorkflowRecords::META_TAGS] = $this->tags($request);
        }

        $name = $this->name($request);
        $version = WorkflowRecords::bump(WorkflowRecords::version($item));
        $meta[WorkflowRecords::META_VERSION] = $version;

        try {
            $this->content->update($id, new UpdateContentCommand(
                userId: (int) $request->user()->getAuthIdentifier(),
                title: '' !== $name ? $name : null,
                meta: $meta,
            ));
        } catch (NotFoundException) {
            return $this->notFound();
        } catch (AccessDeniedException $exception) {
            return $this->fail('forbidden', $exception->getMessage(), 403);
        }

        return response()->json([
            'id' => $id,
            'version' => $version,
            'message' => 'Workflow updated.',
        ]);
    }

    /**
     * DELETE /orchestration/workflows/{id} — trash a workflow.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $item = WorkflowRecords::find($this->content, $id);

        if (null === $item) {
            return $this->notFound();
        }

        try {
            $this->content->update($id, new UpdateContentCommand(
                userId: (int) $request->user()->getAuthIdentifier(),
                status: 'trash',
            ));
        } catch (NotFoundException) {
            return $this->notFound();
        } catch (AccessDeniedException $exception) {
            return $this->fail('forbidden', $exception->getMessage(), 403);
        }

        return response()->json([
            'id' => $id,
            'message' => 'Workflow trashed.',
        ]);
    }

    /**
     * Sanitised tags from the request.
     *
     * @return list<string>
     */
    private function tags(Request $request): array
    {
        $raw = $request->input('tags', []);

        if (! is_array($raw)) {
            return [];
        }

        $tags = [];

        foreach ($raw as $tag) {
            if (is_scalar($tag)) {
                $tags[] = trim(strip_tags((string) $tag));
            }
        }

        return array_values(array_filter($tags, static fn (string $tag): bool => '' !== $tag));
    }

    private function name(Request $request): string
    {
        $raw = $request->input('name', '');

        return is_scalar($raw) ? trim(strip_tags((string) $raw)) : '';
    }

    private function invalidGraph(array $errors): JsonResponse
    {
        return response()->json([
            'code' => 'invalid_graph',
            'message' => $errors[0],
            'errors' => array_values($errors),
        ], 422);
    }

    private function notFound(): JsonResponse
    {
        return $this->fail('not_found', 'Workflow not found.', 404);
    }

    private function fail(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'data' => ['status' => $status],
        ], $status);
    }
}
