<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nvoos\Core\Domain\Contract\FileUploadServiceInterface;

/**
 * Media library — the WordPress-shaped surface the console consumes.
 *
 * `MediaClient` speaks `wp/v2/media`: a paginated list with `X-WP-Total` /
 * `X-WP-TotalPages`, single-item reads, and a multipart upload whose field is
 * `file`. The Laravel runtime serves the same shape from `nvoos_files`.
 *
 * Thumbnails and alt-text mutation are not implemented: `media_details.sizes`
 * is an empty object, so the client falls back to `source_url` for every size.
 */
class MediaController
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 30)));
        $page = max(1, (int) $request->query('page', 1));
        $search = trim((string) $request->query('search', ''));
        $mediaType = trim((string) $request->query('media_type', ''));

        $query = DB::table('nvoos_files');

        if ($search !== '') {
            $query->where('filename', 'like', '%'.$search.'%');
        }

        if ($mediaType !== '') {
            // `image` matches the whole family; `image/png` narrows it.
            $query->where('mime_type', 'like', $mediaType.'%');
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')
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

    public function show(int $id): JsonResponse
    {
        $row = DB::table('nvoos_files')->find($id);

        return $row === null
            ? $this->invalidId()
            : response()->json($this->record($row));
    }

    public function store(Request $request, FileUploadServiceInterface $uploads): JsonResponse
    {
        $file = $request->file('file');

        if ($file === null) {
            return response()->json([
                'code' => 'rest_upload_no_data',
                'message' => 'No file was uploaded. Send a multipart `file` field.',
                'data' => ['status' => 400],
            ], 400);
        }

        $result = $uploads->upload(
            [
                'name' => (string) $file->getClientOriginalName(),
                'type' => (string) $file->getClientMimeType(),
                'size' => (int) $file->getSize(),
                'tmp_name' => (string) $file->getRealPath(),
                'error' => (int) $file->getError(),
            ],
            ['user_id' => $request->user()?->getAuthIdentifier()],
        );

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'code' => 'rest_upload_invalid_file',
                'message' => (string) ($result['error'] ?? 'Upload failed.'),
                'data' => ['status' => 422],
            ], 422);
        }

        $row = DB::table('nvoos_files')->find((int) $result['attachment_id']);

        return response()->json($this->record($row), 201);
    }

    /**
     * Map an `nvoos_files` row onto the wp/v2 media shape.
     *
     * @return array<string, mixed>
     */
    private function record(object $row): array
    {
        $filename = (string) ($row->filename ?? '');
        $mime = (string) ($row->mime_type ?? '');
        $created = $row->created_at !== null ? Carbon::parse((string) $row->created_at) : null;
        [$width, $height] = $this->dimensions($row);

        return [
            'id' => (int) $row->id,
            'date' => $created?->format('Y-m-d\TH:i:s'),
            'slug' => pathinfo($filename, PATHINFO_FILENAME),
            'type' => 'attachment',
            'title' => ['rendered' => $filename],
            'caption' => ['rendered' => ''],
            'alt_text' => '',
            'description' => ['rendered' => ''],
            'mime_type' => $mime,
            'source_url' => $this->url($row),
            'media_details' => [
                'width' => $width,
                'height' => $height,
                'filesize' => (int) ($row->size_bytes ?? 0),
                'sizes' => (object) [],
            ],
            'status' => 'inherit',
            'author' => (int) ($row->owner_id ?? 0),
        ];
    }

    /**
     * Resolve the stored path to a console-loadable URL.
     */
    private function url(object $row): string
    {
        $disk = (string) ($row->disk ?? 'public');
        $path = (string) ($row->path ?? '');

        $url = Storage::disk($disk)->url($path);
        $appUrl = rtrim((string) config('app.url'), '/');

        if ($appUrl !== '' && str_starts_with($url, $appUrl)) {
            return substr($url, strlen($appUrl));
        }

        return $url;
    }

    /**
     * Image dimensions when the file is a locally readable image.
     *
     * @return array{0: int, 1: int}
     */
    private function dimensions(object $row): array
    {
        if (! str_starts_with((string) ($row->mime_type ?? ''), 'image/')) {
            return [0, 0];
        }

        try {
            $local = Storage::disk((string) ($row->disk ?? 'public'))->path((string) ($row->path ?? ''));
        } catch (\Throwable) {
            return [0, 0];
        }

        if (! is_file($local)) {
            return [0, 0];
        }

        $size = @getimagesize($local);

        return is_array($size)
            ? [(int) ($size[0] ?? 0), (int) ($size[1] ?? 0)]
            : [0, 0];
    }

    private function invalidId(): JsonResponse
    {
        return response()->json([
            'code' => 'rest_post_invalid_id',
            'message' => 'Invalid media ID.',
            'data' => ['status' => 404],
        ], 404);
    }
}
