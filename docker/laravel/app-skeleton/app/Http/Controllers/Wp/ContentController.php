<?php

namespace App\Http\Controllers\Wp;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WordPress-compatible content shim (`/api/v1/wp/{restBase}`).
 *
 * The Pro console's Records pages talk to `wp/v2/{restBase}` directly and
 * read pagination from the `X-WP-Total` / `X-WP-TotalPages` headers. A pure
 * Laravel runtime has no WordPress, so this controller serves the same shape
 * from `nvoos_posts` for the two core content entities the runtime owns.
 *
 * Only the subset the console uses is implemented: list (with search,
 * ordering and pagination), read one, and create. `_fields` and `context`
 * are accepted and ignored — the full representation is always returned,
 * which is a superset of what the client asks for.
 */
class ContentController
{
    /**
     * REST base → `nvoos_posts.type`.
     *
     * @var array<string, string>
     */
    private const TYPES = [
        'posts' => 'post',
        'pages' => 'page',
        // Project-management family (090-F Phase 2 admin surface). The CPTs
        // already declare these REST bases in `oos.content_types`, so the
        // console's Records page can address them the same way it addresses
        // posts and pages.
        'tasks' => 'mcp_ai_task',
        'projects' => 'mcp_ai_project',
    ];

    public function index(Request $request, string $restBase): JsonResponse
    {
        $type = self::TYPES[$restBase] ?? null;

        if ($type === null) {
            return $this->unknownRoute();
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));
        $search = trim((string) $request->query('search', ''));
        $order = strtolower((string) $request->query('order', 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = DB::table('nvoos_posts')
            ->where('type', $type)
            ->whereNull('deleted_at');

        if ($search !== '') {
            $query->where(function ($where) use ($search): void {
                $where->where('title', 'like', '%'.$search.'%')
                    ->orWhere('content', 'like', '%'.$search.'%');
            });
        }

        $total = (clone $query)->count();
        $rows = $query->orderBy('created_at', $order)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return response()->json(
            array_map([$this, 'record'], $rows->all()),
            200,
            [
                'X-WP-Total' => (string) $total,
                'X-WP-TotalPages' => (string) max(1, (int) ceil($total / $perPage)),
            ],
        );
    }

    public function show(string $restBase, int $id): JsonResponse
    {
        $type = self::TYPES[$restBase] ?? null;

        if ($type === null) {
            return $this->unknownRoute();
        }

        $row = DB::table('nvoos_posts')
            ->where('id', $id)
            ->where('type', $type)
            ->whereNull('deleted_at')
            ->first();

        return $row === null
            ? $this->invalidId()
            : response()->json($this->record($row));
    }

    public function store(Request $request, string $restBase): JsonResponse
    {
        $type = self::TYPES[$restBase] ?? null;

        if ($type === null) {
            return $this->unknownRoute();
        }

        $data = $request->validate([
            'title' => ['required'],
            'status' => ['sometimes', 'string', 'in:publish,draft,pending,private'],
            'content' => ['sometimes', 'nullable'],
            'excerpt' => ['sometimes', 'nullable'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:200'],
            'meta' => ['sometimes', 'array'],
        ]);

        $title = $this->titleString($data['title']);
        $slug = isset($data['slug']) && (string) $data['slug'] !== ''
            ? Str::slug((string) $data['slug'])
            : Str::slug($title);

        $now = now();

        $id = DB::table('nvoos_posts')->insertGetId([
            'title' => $title,
            'content' => $this->stringValue($data['content'] ?? null),
            'excerpt' => $this->stringValue($data['excerpt'] ?? null),
            'status' => $data['status'] ?? 'draft',
            'type' => $type,
            'author_id' => $request->user()?->getAuthIdentifier(),
            'slug' => $this->uniqueSlug($slug !== '' ? $slug : 'record'),
            'meta' => json_encode($data['meta'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json(
            $this->record(DB::table('nvoos_posts')->find($id)),
            201,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function record(object $row): array
    {
        $title = (string) ($row->title ?? '');
        $content = (string) ($row->content ?? '');
        $excerpt = (string) ($row->excerpt ?? '');
        $meta = json_decode((string) ($row->meta ?? ''), true);
        $created = $row->created_at !== null ? Carbon::parse((string) $row->created_at) : null;

        return [
            'id' => (int) $row->id,
            'date' => $created?->format('Y-m-d\TH:i:s'),
            'date_gmt' => $created?->copy()->utc()->format('Y-m-d\TH:i:s'),
            'slug' => (string) ($row->slug ?? ''),
            'status' => (string) ($row->status ?? ''),
            'type' => (string) ($row->type ?? ''),
            'link' => '',
            'title' => ['rendered' => $title, 'raw' => $title],
            'content' => ['rendered' => $content, 'raw' => $content, 'protected' => false],
            'excerpt' => ['rendered' => $excerpt, 'raw' => $excerpt, 'protected' => false],
            'author' => (int) ($row->author_id ?? 0),
            'meta' => is_array($meta) && $meta !== [] ? $meta : (object) [],
        ];
    }

    /**
     * WordPress accepts `title` as a string or as `{ raw | rendered }`.
     */
    private function titleString(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value['raw'] ?? $value['rendered'] ?? '';
        }

        return trim((string) $value);
    }

    /**
     * WordPress accepts `content`/`excerpt` as a string or `{ raw | rendered }`.
     */
    private function stringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $value = $value['raw'] ?? $value['rendered'] ?? '';
        }

        return (string) $value;
    }

    private function uniqueSlug(string $slug): string
    {
        $candidate = $slug;
        $suffix = 2;

        while (DB::table('nvoos_posts')->where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function unknownRoute(): JsonResponse
    {
        return response()->json([
            'code' => 'rest_no_route',
            'message' => 'No route was found matching the URL and request method.',
            'data' => ['status' => 404],
        ], 404);
    }

    private function invalidId(): JsonResponse
    {
        return response()->json([
            'code' => 'rest_post_invalid_id',
            'message' => 'Invalid post ID.',
            'data' => ['status' => 404],
        ], 404);
    }
}
