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
 * No `Range` handling. Video playback wants it and doing it badly is worse than
 * not doing it (Proposal 162 §162.7); the whole representation is streamed.
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

        return $disk->response(
            (string) $row->path,
            $name,
            array(
                'Content-Type' => $mime,
                'X-Content-Type-Options' => 'nosniff',
                'Content-Disposition' => sprintf(
                    '%s; filename="%s"',
                    $inline ? 'inline' : 'attachment',
                    addcslashes($name, "\"\\")
                ),
                'Content-Security-Policy' => "default-src 'none'; sandbox",
                'Cache-Control' => 'private, no-store, max-age=0',
            )
        );
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
