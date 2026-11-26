<?php

namespace App\Http\Controllers;

use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nvoos\Core\Domain\Contract\ChunkedUploadInterface;
use Nvoos\Core\Domain\Error\ConflictException;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Domain\Error\ValidationException;

/**
 * Resumable uploads — tus 1.0.0 core over `media/uploads`.
 *
 * The console's single-request upload is bounded by PHP: `upload_max_filesize` is
 * 2 MB and `post_max_size` 8 MB in the shipped image, so a file of any real size
 * cannot arrive in one request no matter what the app allows. This endpoint is the
 * other path — small requests, big files, and a dropped connection costs one chunk.
 *
 * It is the specification's protocol rather than a dialect, because the rules that
 * matter are the ones a client already knows: `Upload-Length` at creation,
 * `Upload-Offset` on every append, **409** when the offset is not the one the
 * server holds (never a best-effort append), and `Tus-Resumable: 1.0.0` on every
 * response. A client written against tus — Uppy, `tus-js-client`, `tusd` — works
 * unmodified; so does `curl`.
 *
 * Two extensions beyond the core, both additive and both documented in §164.30:
 *
 *  - the completing `PATCH` answers `X-Nvoos-Attachment-Id` and `X-Nvoos-Url`, so
 *    a client does not need a second request to learn what it stored;
 *  - `OPTIONS` advertises `Tus-Max-Size`, which is our per-file ceiling.
 *
 * Errors are the console's usual `{code, message, data:{status}}` shape, and a
 * 409 carries the current `Upload-Offset` header so a client can resume without a
 * `HEAD`.
 */
class MediaUploadController
{
    private const TUS = '1.0.0';

    /**
     * Capability discovery. A tus client sends this before anything else.
     */
    public function options(ChunkedUploadInterface $uploads): Response
    {
        return $this->tus(response()->noContent())
            ->header('Tus-Version', self::TUS)
            ->header('Tus-Extension', 'creation,expiration')
            ->header('Tus-Max-Size', (string) $uploads->maxTotalBytes());
    }

    /**
     * Creation: `POST` with `Upload-Length`, answer `201` and a `Location`.
     */
    public function create(Request $request, ChunkedUploadInterface $uploads): Response|JsonResponse
    {
        $refusal = $this->version($request);

        if (null !== $refusal) {
            return $refusal;
        }

        $declared = (string) $request->headers->get('Upload-Length', '');

        if (! ctype_digit($declared)) {
            return $this->problem('tus_upload_length_required', 'Upload-Length is required and must be a number of bytes.', 400);
        }

        $meta = $this->metadata($request);

        try {
            $session = $uploads->begin(
                is_string($meta['filename'] ?? null) ? $meta['filename'] : 'upload',
                is_string($meta['filetype'] ?? null) ? $meta['filetype'] : (string) $request->headers->get('Content-Type', ''),
                (int) $declared,
                $this->context($request),
            );
        } catch (ValidationException $error) {
            return $this->problem('tus_upload_invalid_length', $error->getMessage(), 422);
        }

        return $this->tus(response('', 201))
            ->header('Location', url('/api/v1/media/uploads/'.$session['id']))
            ->header('Upload-Offset', '0')
            ->header('Upload-Length', (string) $session['total']);
    }

    /**
     * Status: `HEAD` answers where the upload stands.
     */
    public function status(string $uploadId, Request $request, ChunkedUploadInterface $uploads): Response|JsonResponse
    {
        $refusal = $this->version($request);

        if (null !== $refusal) {
            return $refusal;
        }

        $state = $uploads->status($uploadId, $this->context($request));

        if (null === $state) {
            return $this->problem('tus_upload_not_found', 'No such upload.', 404);
        }

        return $this->tus(response('', 200))
            ->header('Upload-Offset', (string) $state['offset'])
            ->header('Upload-Length', (string) $state['total']);
    }

    /**
     * Append: one chunk, at the offset the client believes it is writing.
     */
    public function append(string $uploadId, Request $request, ChunkedUploadInterface $uploads): Response|JsonResponse
    {
        $refusal = $this->version($request);

        if (null !== $refusal) {
            return $refusal;
        }

        if ('application/offset+octet-stream' !== strtolower(trim((string) $request->headers->get('Content-Type', '')))) {
            return $this->problem(
                'tus_invalid_content_type',
                'PATCH requires Content-Type: application/offset+octet-stream.',
                415,
            );
        }

        $offset = (string) $request->headers->get('Upload-Offset', '');

        if (! ctype_digit($offset)) {
            return $this->problem('tus_upload_offset_required', 'Upload-Offset is required and must be a number of bytes.', 400);
        }

        try {
            $state = $uploads->append($uploadId, (int) $offset, (string) $request->getContent(), $this->context($request));
        } catch (NotFoundException $error) {
            return $this->problem('tus_upload_not_found', $error->getMessage(), 404);
        } catch (ConflictException $error) {
            return $this->problem('tus_upload_offset_mismatch', $error->getMessage(), 409)
                ->header('Upload-Offset', (string) ($error->context['offset'] ?? 0));
        } catch (ValidationException $error) {
            return $this->problem('tus_upload_refused', $error->getMessage(), 422);
        }

        $response = $this->tus(response()->noContent())->header('Upload-Offset', (string) $state['offset']);

        if ($state['complete'] && null !== $state['attachment_id']) {
            $response = $response
                ->header('X-Nvoos-Attachment-Id', (string) $state['attachment_id'])
                ->header('X-Nvoos-Url', (string) ($state['url'] ?? ''));
        }

        return $response;
    }

    /**
     * Discard an upload. Idempotent: deleting what is already gone is still 204.
     */
    public function destroy(string $uploadId, Request $request, ChunkedUploadInterface $uploads): Response|JsonResponse
    {
        $refusal = $this->version($request);

        if (null !== $refusal) {
            return $refusal;
        }

        $uploads->abort($uploadId, $this->context($request));

        return $this->tus(response()->noContent());
    }

    /**
     * The one header a tus client checks on every response.
     */
    private function tus(Response $response): Response
    {
        return $response->header('Tus-Resumable', self::TUS);
    }

    /**
     * Refuse an unsupported protocol version, as the specification requires.
     *
     * A *missing* header is tolerated — a client that knows one version need not
     * announce it — but a client that announces an unsupported one is answered
     * with `412` and the versions this host speaks, which is how tus says "not
     * this dialect".
     */
    private function version(Request $request): Response|JsonResponse|null
    {
        $announced = trim((string) $request->headers->get('Tus-Resumable', ''));

        if ('' === $announced || self::TUS === $announced) {
            return null;
        }

        return $this->problem('tus_version_unsupported', 'This host speaks tus '.self::TUS.'.', 412)
            ->header('Tus-Version', self::TUS);
    }

    /**
     * @return array<string, string> `Upload-Metadata` as key → value (`'1'` for a valueless key).
     */
    private function metadata(Request $request): array
    {
        $raw = (string) $request->headers->get('Upload-Metadata', '');
        $out = array();

        foreach (array_filter(array_map('trim', explode(',', $raw))) as $pair) {
            $parts = preg_split('/\s+/', $pair, 2) ?: array();
            $key = (string) ($parts[0] ?? '');

            if ('' === $key) {
                continue;
            }

            if (! isset($parts[1])) {
                $out[$key] = '1';
                continue;
            }

            $decoded = base64_decode((string) $parts[1], true);
            $out[$key] = false === $decoded ? '1' : $decoded;
        }

        return $out;
    }

    /**
     * @return array{user_id: int, tenant_id: string}
     */
    private function context(Request $request): array
    {
        return array(
            'user_id' => (int) ($request->user()?->getAuthIdentifier() ?? 0),
            'tenant_id' => TenantContext::current(),
        );
    }

    private function problem(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(array(
            'code' => $code,
            'message' => $message,
            'data' => array('status' => $status),
        ), $status);
    }
}
