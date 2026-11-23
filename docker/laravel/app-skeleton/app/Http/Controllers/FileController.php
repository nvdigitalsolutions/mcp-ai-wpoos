<?php

namespace App\Http\Controllers;

use App\Support\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read a stored file — Proposal 162.
 *
 * Public files already have a stable URL and never need this route. Private files
 * have no URL at all: this is the only way in, and it checks on every request.
 *
 * ## Why a controller rather than a signed URL
 *
 * On a local disk a "signed URL" still needs a route to serve it, so the
 * authorisation check has to exist either way — it may as well be the only
 * mechanism. When a deployment moves the private disk to S3 the transport changes
 * (`Storage::temporaryUrl()` in place of `->response()`) and this check does not:
 * temporary URLs are generated *after* the check, not instead of it.
 *
 * ## Estate scoping
 *
 * The row is looked up **with** the current estate in the query, so a foreign id
 * is absent (404) rather than forbidden (403). A 403 would confirm the file
 * exists, which is a fact about another estate.
 *
 * ## Headers
 *
 * Serving user-supplied content needs more than a `Content-Type`: `nosniff` stops
 * the browser from re-interpreting the bytes, `Content-Disposition` keeps
 * documents from rendering in the app's origin, and `default-src 'none'` makes a
 * script smuggled into an image inert if a browser does render it. Images, video
 * and audio are served inline because a player cannot use an attachment; every
 * other type downloads.
 *
 * ## What it deliberately does not do
 *
 * Multi-range requests. `Range: bytes=0-1,5-6` is answered with the whole
 * representation (200), which RFC 9110 §14.2 permits for a server that does not
 * implement the multipart form; browsers ask for one range at a time, and a
 * half-correct multipart response is worse than none.
 *
 * It also does not honour `If-Range`. This route emits no `ETag` or
 * `Last-Modified`, so a validator a client sends cannot be matched — and
 * honouring the range anyway is how a resumed download lands on the wrong bytes.
 * `If-Range` therefore gets the full representation.
 */
final class FileController
{
    public function __invoke(Request $request, int $id): StreamedResponse
    {
        $row = DB::table('nvoos_files')
            ->where('id', $id)
            ->where('tenant_id', TenantContext::current())
            ->first();

        abort_if(null === $row, 404);

        $userId = (int) $request->user()?->getAuthIdentifier();

        $owner = (int) ($row->owner_id ?? 0);

        $allowed = 'public' === (string) ($row->visibility ?? 'public')
            || ($userId > 0 && $owner === $userId)
            || $this->gateAllows($userId, $id);

        abort_unless($allowed, 403);

        $disk = Storage::disk((string) $row->disk);

        abort_unless($disk->exists((string) $row->path), 404);

        $mime = (string) ($row->mime_type ?? 'application/octet-stream');
        $name = (string) ($row->filename ?? 'download');

        $inline = str_starts_with($mime, 'image/')
            || str_starts_with($mime, 'video/')
            || str_starts_with($mime, 'audio/');

        $headers = array(
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => sprintf(
                '%s; filename="%s"',
                $inline ? 'inline' : 'attachment',
                addcslashes($name, "\"\\")
            ),
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store, max-age=0',
        );

        // Proposal 162 §162.7 — byte ranges. A player seeks by asking for one;
        // without `Accept-Ranges` it will not try, and without a 206 every seek
        // re-downloads from zero.
        $absolute = $this->localPath($disk, (string) $row->path);

        if (null === $absolute) {
            // A remote driver streams the whole representation; ranged reads are
            // its transport's job, not this controller's.
            return $disk->response((string) $row->path, $name, $headers);
        }

        $size = (int) filesize($absolute);

        $headers['Accept-Ranges'] = 'bytes';

        $range = $this->requestedRange($request, $size);

        if (false === $range) {
            // Unsatisfiable: tell the client how long the representation is so it
            // can recover, instead of silently sending something else.
            return response()->stream(
                static function (): void {
                },
                416,
                $headers + array('Content-Range' => 'bytes */'.$size)
            );
        }

        if (null === $range) {
            return response()->stream(
                $this->sink($absolute, 0, $size),
                200,
                $headers + array('Content-Length' => (string) $size)
            );
        }

        [$start, $end] = $range;

        return response()->stream(
            $this->sink($absolute, $start, $end - $start + 1),
            206,
            $headers + array(
                'Content-Range' => sprintf('bytes %d-%d/%d', $start, $end, $size),
                'Content-Length' => (string) ($end - $start + 1),
            )
        );
    }

    /**
     * The range this request asks for.
     *
     * @return array{0: int, 1: int}|null|false Null serves the whole file, false
     *                                          means the range is unsatisfiable.
     */
    private function requestedRange(Request $request, int $size): array|false|null
    {
        // No validator is emitted by this route, so an `If-Range` cannot match.
        // Serving the full representation is the safe answer (RFC 9110 §13.1.5).
        if ('' !== trim((string) $request->header('If-Range'))) {
            return null;
        }

        $header = trim((string) $request->header('Range'));

        // Absent, malformed, or more than one range: send everything. A multipart
        // 206 is permitted to be declined, and declining is honest.
        if (! preg_match('/^bytes=(\d*)-(\d*)$/i', $header, $matches)) {
            return null;
        }

        $first = $matches[1];
        $last  = $matches[2];

        if ('' === $first && '' === $last) {
            return null;
        }

        if ($size <= 0) {
            return null;
        }

        if ('' === $first) {
            // Suffix form: the last N bytes.
            $length = (int) $last;

            if ($length <= 0) {
                return false;
            }

            return array(max(0, $size - $length), $size - 1);
        }

        $start = (int) $first;

        if ($start >= $size) {
            return false;
        }

        $end = '' === $last ? $size - 1 : min((int) $last, $size - 1);

        if ($end < $start) {
            return false;
        }

        return array($start, $end);
    }

    /**
     * Emit exactly `$length` bytes from `$start`.
     *
     * Chunked rather than `readfile()` so a 4 GB video never becomes a 4 GB
     * string, and bounded so a truncated read cannot spin.
     */
    private function sink(string $absolute, int $start, int $length): callable
    {
        return static function () use ($absolute, $start, $length): void {
            $handle = fopen($absolute, 'rb');

            if (false === $handle) {
                return;
            }

            if ($start > 0) {
                fseek($handle, $start);
            }

            $remaining = $length;

            while ($remaining > 0 && ! feof($handle)) {
                $chunk = fread($handle, (int) min(65536, $remaining));

                if (false === $chunk || '' === $chunk) {
                    break;
                }

                echo $chunk;

                $remaining -= strlen($chunk);
            }

            fclose($handle);
        };
    }

    /**
     * The on-disk path, or null when the driver is remote.
     *
     * Ranged reads need a seekable file. Rather than assume the disk is local,
     * ask for the path and fall back to a whole-representation stream.
     */
    private function localPath(\Illuminate\Filesystem\FilesystemAdapter $disk, string $path): ?string
    {
        try {
            $absolute = $disk->path($path);
        } catch (\Throwable) {
            return null;
        }

        return is_file($absolute) ? $absolute : null;
    }

    /**
     * The host's file-level ability — the same extension point `FileStore` uses.
     */
    private function gateAllows(int $userId, int $fileId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        try {
            return app(Gate::class)->allows('oos-file.access', array($userId, $fileId));
        } catch (\Throwable) {
            return false;
        }
    }
}
