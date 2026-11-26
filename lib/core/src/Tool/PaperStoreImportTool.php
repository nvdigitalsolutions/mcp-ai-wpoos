<?php

/**
 * Paper Store import — records in, upserted by id, with a report that adds up.
 *
 * Proposal 164 §164.48. The WordPress tool's loop is the shape this follows: an
 * `id` per record, `overwrite` defaulting to true, a count of imported and one of
 * skipped, and a per-record error list. Three things are tightened, each because
 * the reference is silent or wrong rather than because the port wanted a change:
 *
 *  - **A missing title is reported, not discovered later.** The WordPress schema
 *    says every item needs `id` *and* `title`, but `execute()` only checks `id`
 *    and leaves the title to the repository. That turns one documented
 *    precondition into a per-record exception whose wording depends on the store.
 *    Here both are checked before any write.
 *  - **A record with no id is an error, not a silent skip.** The reference
 *    increments `skipped` and says nothing, so a caller cannot tell "already
 *    existed" from "malformed row". Those are different facts and they get
 *    different counters.
 *  - **The counts reconcile.** `imported + skipped + failed === total`, always,
 *    including when the store throws mid-batch. A batch report a caller cannot
 *    reconcile is worse than no report.
 *
 * ## `dry_run` defaults to false, unlike the bulk image tools
 *
 * `optimise_images_batch` defaults to a dry run because it *infers* a bulk action
 * over a library the caller did not enumerate. An import is the opposite: the
 * caller wrote every record out in the arguments, so the intent is the payload
 * itself. The reference tool writes immediately and a migrating prompt depends on
 * that; the flag is offered, not imposed.
 *
 * ## Ids are matched exactly
 *
 * `save()` replaces the whole record and preserves `created_at`, so an import is
 * an upsert rather than a merge — a field absent from the incoming record is
 * removed, not kept. That is the store's contract and the tool says so rather
 * than promising a partial update it cannot deliver.
 *
 * @package Nvoos\Core
 * @since   2.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\PaperStoreInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;

class PaperStoreImportTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * A ceiling on one call.
     *
     * Records carry prose, so this is a payload bound as much as a work bound: an
     * import that is larger than this is a bulk load that deserves its own
     * streaming path rather than one tool result holding it all.
     */
    private const MAX_RECORDS = 500;

    /** A collection name or record id: one path segment, no separators. */
    private const SEGMENT_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/';

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly PaperStoreInterface $store,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'paper_store_import';
    }

    public function getName(): string
    {
        return 'Paper Store — Import Records';
    }

    public function getDescription(): string
    {
        return 'Import records into a Paper Store collection. Each record needs an id and a title. An existing id is replaced in full (upsert) unless overwrite is false. Reports imported, skipped and failed per record, and the three always add up to the total.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'collection' => array(
                    'type' => 'string',
                    'description' => 'Target collection name. Letters, digits, underscore and dash; it is a single path segment.',
                    'pattern' => '^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$',
                ),
                'records' => array(
                    'type' => 'array',
                    'description' => 'Records to import. Each must be an object with "id" and "title"; everything else is stored as given.',
                    'maxItems' => self::MAX_RECORDS,
                    'items' => array('type' => 'object'),
                ),
                'overwrite' => array(
                    'type' => 'boolean',
                    'description' => 'Replace records whose id already exists. When false they are counted as skipped. Default: true.',
                    'default' => true,
                ),
                'dry_run' => array(
                    'type' => 'boolean',
                    'description' => 'Validate and report what would be written without touching the store. Default: false, because the caller enumerated the records.',
                    'default' => false,
                ),
            ),
            'required' => array('collection', 'records'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'manage_options';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        // Not `idempotent`: re-importing the same payload reaches the same state,
        // but every write stamps a new `updated_at`, so the records change.
        return CapabilityFlagVocabulary::LOCAL_WRITE;
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $collection = \trim($this->stringParam($arguments, 'collection'));

        if ('' === $collection) {
            return $this->errors->validationFailed(
                'A collection name is required.',
                array('collection' => array('Give the collection to import into.')),
            );
        }

        if (1 !== \preg_match(self::SEGMENT_PATTERN, $collection)) {
            return $this->errors->validationFailed(
                \sprintf('"%s" is not a usable collection name.', $collection),
                array('collection' => array('Use letters, digits, underscore and dash; no separators.')),
            );
        }

        $records = $arguments['records'] ?? null;

        if (! \is_array($records) || ! \array_is_list($records)) {
            return $this->errors->validationFailed(
                'records must be a list of objects.',
                array('records' => array('Give an array of records, not a map.')),
            );
        }

        if (array() === $records) {
            return $this->errors->validationFailed(
                'At least one record is required.',
                array('records' => array('The list is empty.')),
            );
        }

        if (\count($records) > self::MAX_RECORDS) {
            return $this->errors->validationFailed(
                \sprintf('Import at most %d records per call.', self::MAX_RECORDS),
                array('records' => array('Split the import into smaller batches.')),
            );
        }

        $overwrite = $this->boolParam($arguments, 'overwrite', true);
        $dryRun = $this->isDryRun($arguments);

        $imported = 0;
        $skipped = 0;
        $failed = array();

        /** @var array<string, true> $seen Ids this batch has already touched. */
        $seen = array();

        foreach ($records as $index => $record) {
            if (! \is_array($record)) {
                $failed[] = array(
                    'index' => $index,
                    'id' => null,
                    'error' => 'Each record must be an object.',
                );
                continue;
            }

            $id = isset($record['id']) && \is_scalar($record['id']) ? \trim((string) $record['id']) : '';

            if ('' === $id) {
                $failed[] = array(
                    'index' => $index,
                    'id' => null,
                    'error' => 'A record id is required; it is the key an import matches on.',
                );
                continue;
            }

            $title = isset($record['title']) && \is_scalar($record['title']) ? \trim((string) $record['title']) : '';

            if ('' === $title) {
                $failed[] = array(
                    'index' => $index,
                    'id' => $id,
                    'error' => 'A record title is required.',
                );
                continue;
            }

            if (1 !== \preg_match(self::SEGMENT_PATTERN, $id)) {
                $failed[] = array(
                    'index' => $index,
                    'id' => $id,
                    'error' => 'A record id must be a single path segment: letters, digits, underscore and dash.',
                );
                continue;
            }

            try {
                $exists = isset($seen[$id]) || $this->store->has($collection, $id);
            } catch (\Throwable $e) {
                $failed[] = array(
                    'index' => $index,
                    'id' => $id,
                    'error' => $e->getMessage(),
                );
                continue;
            }

            if (! $overwrite && $exists) {
                ++$skipped;
                continue;
            }

            if ($dryRun) {
                $seen[$id] = true;
                ++$imported;
                continue;
            }

            try {
                // Write the values that were validated, not the raw ones: the id
                // and title were trimmed above and the store trims again, but a
                // padded id would otherwise pass this gate and fail the store's.
                $record['id'] = $id;
                $record['title'] = $title;

                $this->store->save($collection, $record);
                $seen[$id] = true;
                ++$imported;
            } catch (\Throwable $e) {
                $failed[] = array(
                    'index' => $index,
                    'id' => $id,
                    'error' => $e->getMessage(),
                );
            }
        }

        $total = \count($records);

        $message = $dryRun
            ? \sprintf('Dry run: %d of %d record(s) would be imported into "%s".', $imported, $total, $collection)
            : \sprintf('Imported %d record(s) into "%s" (%d skipped, %d failed).', $imported, $collection, $skipped, \count($failed));

        $payload = array(
            'collection' => $collection,
            'total' => $total,
            'imported' => $imported,
            'skipped' => $skipped,
            'failed' => \count($failed),
            'errors' => $failed,
            'dry_run' => $dryRun,
        );

        if ($dryRun) {
            // Named so a caller cannot mistake a rehearsal for a write.
            $payload['would_import'] = $imported;
        }

        return $this->success($message, $payload);
    }
}
