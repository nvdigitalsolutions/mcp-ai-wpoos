<?php

namespace App\Http\Controllers;

use App\Support\OkfCatalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nvoos\Core\Domain\Okf\OkfReadException;
use Nvoos\Core\Domain\Okf\OkfWriteException;
use Nvoos\Core\Domain\Okf\OkfWriter;

/**
 * Write side of the OKF surface — Proposal 162 §162.10.
 *
 * The read routes live in `OkfController` inside the `edit_posts` group, because
 * the drawer is in every console user's toolbar. Writes are a different act: a
 * bundle is deployment-global knowledge shared by every estate (the read
 * contract says so), so an authenticated reader must not be able to rewrite it.
 * These routes therefore sit in the `manage_options` group, on the same
 * `api/v1/pro/okf` base so the console's endpoint fragment still resolves.
 *
 * ## What the endpoints do
 *
 *   POST   /pro/okf/bundles/{bundle}/concepts            — write one concept
 *   DELETE /pro/okf/bundles/{bundle}/concepts/{concept}  — retire one concept
 *   POST   /pro/okf/bundles/{bundle}/index               — regenerate index.md
 *
 * Deletion retires (`name.md.deleted`) rather than unlinking, matching the
 * writer's recovery path; the response says whether anything was retired, so a
 * second delete is not an error.
 *
 * ## Errors
 *
 * A refusal from the writer is returned with the writer's `okf_*` code so the
 * caller can branch on it instead of matching prose. The status follows the
 * code: `okf_missing_type`, `okf_path_traversal`, `okf_reserved_name`,
 * `okf_missing_id`, `okf_empty_entry` and `okf_unsupported_shape` are the
 * caller's to fix and are `422`; `okf_not_found` is a `404`; a filesystem
 * failure (`okf_write_error`, `okf_read_error`) is ours, so it is a `500`.
 * Nothing here returns a `422` for a failure the caller cannot act on.
 */
final class OkfWriteController
{
    /**
     * POST /pro/okf/bundles/{bundle}/concepts.
     */
    public function writeConcept(Request $request, string $bundle): JsonResponse
    {
        $payload = $request->validate([
            'concept_id'  => ['required', 'string', 'max:500'],
            'frontmatter' => ['required', 'array', 'max:100'],
            'body'        => ['nullable', 'string', 'max:1000000'],
        ]);

        // `validate()` returns the keys the rules name, so a child rule such as
        // `frontmatter.title` would silently drop every other frontmatter key.
        // OKF frontmatter is an open map, so take the map as sent and let the
        // writer — not the request layer — decide what OKF requires.
        $frontmatter = (array) $request->input('frontmatter', array());

        return $this->respond(static function () use ($bundle, $payload, $frontmatter): array {
            $writer = OkfCatalogue::fromConfig()->writerFor($bundle);

            $writer->writeConcept(
                (string) $payload['concept_id'],
                $frontmatter,
                (string) ($payload['body'] ?? ''),
            );

            return array(
                'concept_id' => $payload['concept_id'],
                'path'       => $bundle . '/' . ltrim((string) $payload['concept_id'], '/') . '.md',
            );
        }, 201);
    }

    /**
     * DELETE /pro/okf/bundles/{bundle}/concepts/{concept}.
     */
    public function deleteConcept(string $bundle, string $concept): JsonResponse
    {
        return $this->respond(static function () use ($bundle, $concept): array {
            $retired = OkfCatalogue::fromConfig()->writerFor($bundle)->deleteConcept($concept);

            return array(
                'concept_id' => $concept,
                'retired'    => $retired,
            );
        });
    }

    /**
     * POST /pro/okf/bundles/{bundle}/index.
     */
    public function regenerateIndex(Request $request, string $bundle): JsonResponse
    {
        $path = (string) $request->input('path', '');

        return $this->respond(static function () use ($bundle, $path): array {
            OkfCatalogue::fromConfig()->writerFor($bundle)->regenerateIndex($path);

            return array(
                'bundle' => $bundle,
                'path'   => '' === $path ? 'index.md' : trim($path, '/') . '/index.md',
            );
        });
    }

    /**
     * Map the writer's refusals onto status codes.
     *
     * @param callable(): array<string, mixed> $work
     */
    private function respond(callable $work, int $status = 200): JsonResponse
    {
        try {
            return response()->json($work() + array('success' => true), $status);
        } catch (OkfWriteException $error) {
            return response()->json(array(
                'success' => false,
                'code'    => $error->errorCode,
                'error'   => $error->getMessage(),
            ), $this->statusFor($error->errorCode));
        } catch (OkfReadException $error) {
            // The catalogue could not resolve the bundle at all.
            return response()->json(array(
                'success' => false,
                'error'   => $error->getMessage(),
            ), 404);
        }
    }

    /**
     * Map a writer code to the status its caller can act on.
     */
    private function statusFor(string $code): int
    {
        return match ($code) {
            'okf_not_found' => 404,
            'okf_missing_id',
            'okf_missing_type',
            'okf_path_traversal',
            'okf_reserved_name',
            'okf_empty_entry',
            'okf_unsupported_shape' => 422,
            default => 500,
        };
    }
}
