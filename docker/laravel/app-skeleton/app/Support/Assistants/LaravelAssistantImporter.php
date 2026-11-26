<?php

namespace App\Support\Assistants;

use App\Services\AssistantPortability;
use App\Support\BundleException;
use Nvoos\Core\Domain\Contract\AssistantImportInterface;

/**
 * The assistant import path behind the core contract — Proposal 090-M §19.
 *
 * `App\Services\AssistantPortability` is the one import engine: the REST
 * controller and the `/admin` portability page both call it, and it owns the
 * bundle assertion, the estate boundary, slug-then-title conflict matching, the
 * draft default and — since issue #8175 — the credential denylist. This class
 * adds no rules of its own; it adapts the engine's return values and its
 * refusals to the array a tool can carry.
 *
 * ## Two failure classes, because a caller acts differently on each
 *
 * A `BundleException` means the document is wrong: not JSON, not a bundle, a
 * version this runtime does not read, or a bundle with no assistants. The
 * message names the problem and replaying the same bytes will fail the same way,
 * so it is reported as `bundle_invalid` and the tool maps it to a 422.
 *
 * Everything else is a host failure — a database error, a disk error. Its message
 * may carry column names or paths, so it goes to `report()` and the model gets a
 * sentence. Retrying this one may work.
 *
 * ## The estate is not mentioned here
 *
 * `import()` writes through `AssistantWriter`, which takes the estate from
 * `TenantContext`, and resolves conflicts through the model's tenant scope. There
 * is nothing to pass in and nothing to filter here — and an import adapter that
 * accepted a tenant would be the place a cross-estate write gets introduced.
 */
final class LaravelAssistantImporter implements AssistantImportInterface
{
    public function __construct(private readonly AssistantPortability $portability)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function import(string $json, string $mode = 'skip', bool $dryRun = false): array
    {
        try {
            $report = $this->portability->importJson($json, $mode, $dryRun);
        } catch (BundleException $refusal) {
            return array(
                'success' => false,
                'code'    => 'bundle_invalid',
                'error'   => $refusal->getMessage(),
            );
        } catch (\Throwable $error) {
            report($error);

            return array(
                'success' => false,
                'code'    => 'assistant_import_failed',
                'error'   => 'The bundle could not be imported.',
            );
        }

        return array(
            'success' => true,
            'report'  => $report,
        );
    }
}
