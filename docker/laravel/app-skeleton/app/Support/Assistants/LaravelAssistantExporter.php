<?php

namespace App\Support\Assistants;

use App\Services\AssistantPortability;
use App\Support\BundleException;
use Nvoos\Core\Domain\Contract\AssistantExportInterface;

/**
 * The assistant export path behind the core contract — Proposal 090-M §18.
 *
 * `App\Services\AssistantPortability` is the one export engine: the REST
 * controller and the `/admin` portability page both call it, and it owns the
 * `nvoos-assistant` v1 shape, the credential denylist and the estate boundary.
 * This class adds no shape of its own; it adapts the engine's return values to
 * the array a tool can carry and keeps an exception out of a transcript.
 *
 * ## Why the estate is not mentioned here
 *
 * `AssistantPortability::export()` reads through `estate()`, which resolves
 * `TenantContext`. There is nothing to pass in and nothing to filter here — and
 * that is the point: an export adapter that took a tenant would be the place a
 * cross-estate read gets introduced.
 *
 * ## The refusal that is not a failure
 *
 * `export()`'s only documented refusal is an id that is not in the current
 * estate, so a `BundleException` here is reported as `assistant_not_found` — the
 * same code `duplicate_assistant` uses for a foreign id. The engine's message
 * already declines to say whether the row exists elsewhere; the tool maps that
 * to a 404 so the two tools cannot be compared to learn it.
 *
 * Everything else is a host failure and stays generic: the message may carry
 * column names or paths, so it goes to `report()` and the model gets a sentence.
 */
final class LaravelAssistantExporter implements AssistantExportInterface
{
    public function __construct(private readonly AssistantPortability $portability)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function export(?array $ids = null): array
    {
        try {
            $bundle = $this->portability->export($ids);
        } catch (BundleException $refusal) {
            return array(
                'success' => false,
                'code'    => 'assistant_not_found',
                'error'   => $refusal->getMessage(),
            );
        } catch (\Throwable $error) {
            report($error);

            return array(
                'success' => false,
                'code'    => 'assistant_export_failed',
                'error'   => 'The assistants could not be exported.',
            );
        }

        return array(
            'success' => true,
            'bundle'  => $bundle,
        );
    }
}
