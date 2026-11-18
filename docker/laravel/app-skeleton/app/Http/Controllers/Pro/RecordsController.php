<?php

namespace App\Http\Controllers\Pro;

use App\Support\RecordsSchema;
use Illuminate\Http\JsonResponse;

/**
 * Pro Records schema (`/api/v1/pro/records/schema`).
 *
 * The WordPress catalogue covers CRM, PM, professions, F&B and commerce CPTs.
 * The Laravel runtime owns no such post types, so the honest subset is the
 * entities whose `wp/v2` shim is mounted here. The catalogue lives in
 * {@see RecordsSchema} because the `/admin/paper-store` page (149 §52) renders
 * the same list.
 */
class RecordsController
{
    public function schema(): JsonResponse
    {
        return response()->json(['entities' => RecordsSchema::entities()]);
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
