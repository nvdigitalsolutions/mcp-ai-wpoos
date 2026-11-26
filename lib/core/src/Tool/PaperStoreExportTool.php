<?php

/**
 * Paper Store export — one collection, filtered and paged, as records.
 *
 * Proposal 164 §164.48. The WordPress tool returns `$repo->query()->get()`, which
 * is the whole collection with no bound. That is fine against a database and
 * wrong against this runtime's store, so the port states a page instead of
 * pretending the bound does not exist.
 *
 * ## Why one page and not a loop
 *
 * `JsonPaperStore::query()` scans the collection directory on every call — it
 * keeps no index, deliberately. Looping pages to assemble "everything" would
 * therefore read the whole directory once per page: a 200-record export would
 * cost one scan, but a 1,000-record export would cost five scans of the same
 * files. The tool returns what one query can answer, says whether there is more,
 * and gives the caller the offset to ask again.
 *
 * ## An invalid collection is refused, not answered
 *
 * `query()` treats an unusable collection name as "no records" and returns
 * `['records' => [], 'total' => 0]`. An agent that mistypes `../secrets` would
 * then be told the collection is empty, which is a *finding* rather than a
 * *refusal* — and the two must not look alike. So the name is validated here,
 * before the store sees it.
 *
 * ## Ordering is pinned for paging
 *
 * The WordPress tool inherits the repository's default order, which sorts by
 * `updated_at`. Paging by a mutable column can serve a record twice or skip one
 * when something is saved mid-export, so the port orders by `id` and says so.
 * Order is not part of the data; the set is.
 *
 * Offset paging is still sensitive to concurrent creates and deletes, which shift
 * every later page. The store offers no snapshot, so that residual drift is named
 * here rather than papered over.
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

class PaperStoreExportTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * The contract's documented ceiling on one `query()` page.
     *
     * Exposed as the schema `maximum` too, so a caller can discover the bound
     * without making a call that silently clamps.
     */
    private const MAX_LIMIT = 200;

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
        return 'paper_store_export';
    }

    public function getName(): string
    {
        return 'Paper Store — Export Records';
    }

    public function getDescription(): string
    {
        return 'Export the records of one Paper Store collection as a JSON array, optionally filtered by tag, status or type. Returns at most 200 records per call plus the total and the next offset, so a larger collection is paged rather than truncated silently.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'collection' => array(
                    'type' => 'string',
                    'description' => 'Collection name to export. Letters, digits, underscore and dash; it is a single path segment.',
                    'pattern' => '^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$',
                ),
                'tags' => array(
                    'type' => 'string',
                    'description' => 'Optional. Export only records carrying this tag. One tag per call, matched exactly.',
                ),
                'status' => array(
                    'type' => 'string',
                    'description' => 'Optional. Export only records with this status, matched exactly.',
                ),
                'type' => array(
                    'type' => 'string',
                    'description' => 'Optional. Export only records with this type, matched exactly. A collection defaults its records to a type of the same name.',
                ),
                'limit' => array(
                    'type' => 'integer',
                    'description' => 'Records to return in this call. Default and maximum: 200.',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'default' => self::MAX_LIMIT,
                ),
                'offset' => array(
                    'type' => 'integer',
                    'description' => 'Records to skip. Pass the previous call\'s next_offset to continue an export.',
                    'minimum' => 0,
                    'default' => 0,
                ),
            ),
            'required' => array('collection'),
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
        // A read against a store that can change between two identical calls, so
        // `cacheable` is deliberately absent even though the WordPress tool
        // declares it.
        return CapabilityFlagVocabulary::LOCAL_READ;
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $collection = \trim($this->stringParam($arguments, 'collection'));

        if ('' === $collection) {
            return $this->errors->validationFailed(
                'A collection name is required.',
                array('collection' => array('Give the collection to export.')),
            );
        }

        if (1 !== \preg_match(self::SEGMENT_PATTERN, $collection)) {
            return $this->errors->validationFailed(
                \sprintf('"%s" is not a usable collection name.', $collection),
                array('collection' => array('Use letters, digits, underscore and dash; no separators.')),
            );
        }

        $criteria = array(
            // Pinned for paging stability; see the class docblock.
            'order_by' => 'id',
            'direction' => 'asc',
            'limit' => \max(1, \min(self::MAX_LIMIT, $this->intParam($arguments, 'limit', self::MAX_LIMIT))),
            'offset' => \max(0, $this->intParam($arguments, 'offset')),
        );

        foreach (array('tags', 'status', 'type') as $filter) {
            $value = \trim($this->stringParam($arguments, $filter));

            if ('' !== $value) {
                $criteria[$filter] = $value;
            }
        }

        try {
            $page = $this->store->query($collection, $criteria);
        } catch (\Throwable $e) {
            return $this->errors->create('paper_store_unavailable', $e->getMessage());
        }

        $records = $page['records'];
        $count = \count($records);
        $total = (int) $page['total'];
        $next = $criteria['offset'] + $count;

        $message = $next < $total
            ? \sprintf('Exported %d of %d record(s) from "%s". Pass offset %d for the rest.', $count, $total, $collection, $next)
            : \sprintf('Exported %d record(s) from "%s".', $count, $collection);

        return $this->success($message, array(
            'collection' => $collection,
            'count' => $count,
            'total' => $total,
            'offset' => $criteria['offset'],
            'limit' => $criteria['limit'],
            'truncated' => $next < $total,
            'next_offset' => $next < $total ? $next : null,
            'records' => $records,
        ));
    }
}
