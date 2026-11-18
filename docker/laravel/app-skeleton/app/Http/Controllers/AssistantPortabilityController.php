<?php

namespace App\Http\Controllers;

use App\Services\AssistantPortability;
use App\Support\BundleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Laravel port of the assistant portability surface (Proposal 149 §54).
 *
 * Produces and consumes the canonical `nvoos-assistant` v1 bundle. All the
 * behaviour lives in {@see AssistantPortability}: this class only parses the
 * request and maps a refusal onto its status.
 *
 * **The estate is not a parameter.** Export is scoped to the caller's estate and
 * import writes into it, because the bundle is a document — it can claim any
 * `tenant_id` it likes and none is read. That was not true before §54: every
 * query in this controller was unscoped, so export returned every estate's
 * assistants and an overwrite could reach another estate's row. See the service
 * docblock for the full finding.
 */
final class AssistantPortabilityController
{
    public function export(Request $request, AssistantPortability $portability): JsonResponse
    {
        $ids = $request->input('ids');
        $ids = is_array($ids) ? array_values($ids) : null;

        try {
            return response()->json(array(
                'success' => true,
                'data' => $portability->export($ids),
            ));
        } catch (BundleException $exception) {
            return $this->refuse($exception);
        }
    }

    public function import(Request $request, AssistantPortability $portability): JsonResponse
    {
        $payload = $request->validate(array(
            'json' => array('required', 'string'),
            'mode' => array('sometimes', 'in:skip,overwrite,duplicate'),
            'dry_run' => array('sometimes', 'boolean'),
            'status_override' => array('sometimes', 'in:draft,publish,private'),
        ));

        try {
            $report = $portability->importJson(
                $payload['json'],
                $payload['mode'] ?? 'skip',
                (bool) ($payload['dry_run'] ?? false),
                $payload['status_override'] ?? null
            );
        } catch (BundleException $exception) {
            return $this->refuse($exception);
        }

        return response()->json(array(
            'success' => true,
            'data' => $report,
        ));
    }

    private function refuse(BundleException $exception): JsonResponse
    {
        return response()->json(
            array(
                'success' => false,
                'message' => $exception->getMessage(),
            ),
            $exception->status()
        );
    }
}
