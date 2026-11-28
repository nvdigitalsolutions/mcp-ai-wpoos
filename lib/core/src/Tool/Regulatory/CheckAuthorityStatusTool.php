<?php

/**
 * Check the authority status of several registrations at once.
 *
 * Port of `WP_MCP_AI_Tool_Check_Authority_Status` (Proposal 164 section
 * 164.152). A batch read over known registration ids, because the caller
 * already holds the ids and wants one answer rather than N calls.
 *
 * ## This tool's declared default is the actual one
 *
 * `include_history` is declared `false` and read with `! empty()`, which is the
 * same answer. It is the one member of this family where the schema and the
 * handler agree on a default-off boolean — worth stating, because three
 * neighbouring tools got it wrong in the same direction and a reader of this
 * family should not assume the fault is universal.
 *
 * ## Declared and actual capability disagree in WordPress
 *
 * `get_required_capability()` returns `edit_posts`; the handler checks `read`.
 * A user with `read` and without `edit_posts` is refused by the declaration and
 * admitted by the code. The port gates on `read`, which is the handler's rule
 * and the only one that was ever enforced.
 *
 * ## A filtered registration is reported, not dropped
 *
 * WordPress `continue`d past a registration whose country did not match, so the
 * caller received a shorter `results` array with no indication that an id they
 * supplied had been considered and rejected. `total` then described the answer,
 * not the request. The port lists the skipped ids in `skipped` and counts them
 * in `requested`, so an empty result can be told apart from a filtered one.
 *
 * ## Status history has no writer
 *
 * `include_history` reads the `_status_history` meta. Nothing in the toolkit
 * writes that key: the sync tools write `_nmra_status` and `_mohap_status`, and
 * the lifecycle tool writes the status term. So the documented history is always
 * empty, and the port reports it as an empty list rather than WordPress's empty
 * string. A host that populates the key gets its value back unchanged.
 *
 * ## Not-found ids are items
 *
 * One missing registration does not fail a batch that also asks about three
 * that exist. The item carries `found: false` and an `error`, and every item in
 * the response carries `found`, so a caller can iterate one shape.
 *
 * ## Batches are bounded
 *
 * WordPress accepted any number of ids. The port rejects more than 100 rather
 * than truncating, because a truncated batch that looks complete is worse than
 * a refused one.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Regulatory;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;

class CheckAuthorityStatusTool extends RegulatoryTool
{
    /** The most registrations one call may ask about. */
    private const MAX_IDS = 100;

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'check_authority_status';
    }

    public function getName(): string
    {
        return 'Check Authority Status';
    }

    public function getDescription(): string
    {
        return 'Reports the status of up to 100 known registration ids in one call, with the authority, key dates and the NMRA or MOHAP fields for Sri Lanka and the UAE. Ids filtered out by country are listed in skipped; an id that does not exist is an item with found false.';
    }

    public function getRequiredCapability(): string
    {
        // WordPress checks `read` in the handler and declares `edit_posts`.
        return 'read';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'registration_ids' => array(
                    'type'        => 'array',
                    'description' => 'The registration ids to check.',
                    'items'       => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                    ),
                    'minItems'    => 1,
                    'maxItems'    => self::MAX_IDS,
                ),
                'countries'        => array(
                    'type'        => 'array',
                    'description' => 'Only report registrations in these countries.',
                    'items'       => array(
                        'type' => 'string',
                    ),
                ),
                'include_history'  => array(
                    'type'        => 'boolean',
                    'description' => 'Include the stored status history. Default false.',
                    'default'     => false,
                ),
            ),
            'required'             => array('registration_ids'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $rawIds = $arguments['registration_ids'] ?? null;

        if (! is_array($rawIds) || array() === $rawIds) {
            return $this->errors->validationFailed(
                'The authority check cannot run.',
                array('registration_ids' => array('At least one registration id.'))
            );
        }

        if (count($rawIds) > self::MAX_IDS) {
            return $this->errors->validationFailed(
                'The authority check cannot run.',
                array(
                    'registration_ids' => array(
                        sprintf('At most %d registration ids per call.', self::MAX_IDS),
                    ),
                )
            );
        }

        $ids = array();

        foreach ($rawIds as $rawId) {
            $ids[] = $this->intValue($rawId);
        }

        $countries      = $this->countryFilter($arguments['countries'] ?? null);
        $includeHistory = $this->boolParam($arguments, 'include_history', false);

        $results = array();
        $skipped = array();

        foreach ($ids as $id) {
            $registration = $this->content->find($id);

            if (! $registration instanceof ContentItem || self::REGISTRATION_TYPE !== $registration->type) {
                $results[] = array(
                    'registration_id' => $id,
                    'found'           => false,
                    'error'           => 'Registration not found',
                );

                continue;
            }

            $country = $this->text($registration->meta['country'] ?? null);

            if (array() !== $countries && ! in_array($country, $countries, true)) {
                $skipped[] = array(
                    'registration_id' => $id,
                    'reason'          => sprintf('Country %s is not in the requested filter.', $country),
                );

                continue;
            }

            $results[] = $this->row($registration, $includeHistory);
        }

        return $this->success(
            sprintf(
                'Checked %1$d of %2$d registration(s): %3$d found, %4$d not found, %5$d skipped.',
                count($results),
                count($ids),
                $this->countFound($results),
                $this->countMissing($results),
                count($skipped)
            ),
            array(
                'requested'  => count($ids),
                'total'      => count($results),
                'not_found'  => $this->countMissing($results),
                'skipped'    => $skipped,
                'checked_at' => $this->nowStamp(),
                'results'    => $results,
            )
        );
    }

    /**
     * One registration's authority row.
     *
     * @return array<string, mixed>
     */
    private function row(ContentItem $registration, bool $includeHistory): array
    {
        $country = $this->text($registration->meta['country'] ?? null);

        $status = 'Unknown';

        try {
            $terms = $this->termNames($this->content->getTaxonomyTerms($registration->id), self::STATUS_TAX);
        } catch (\Throwable $e) {
            $terms = array();
        }

        if (array() !== $terms) {
            $status = $terms[0];
        }

        $row = array(
            'registration_id' => $registration->id,
            'found'           => true,
            'title'           => $registration->title,
            'country'         => $country,
            'authority'       => $this->text($registration->meta['authority'] ?? null),
            'status'          => $status,
            'submission_date' => $this->text($registration->meta['submission_date'] ?? null),
            'approval_date'   => $this->text($registration->meta['approval_date'] ?? null),
            'expiry_date'     => $this->text($registration->meta['expiry_date'] ?? null),
            'last_checked'    => $this->nowStamp(),
        );

        if ('Sri Lanka' === $country || 'LK' === $country) {
            $row['nmra_status']    = $this->text($registration->meta['_nmra_status'] ?? null);
            $row['nmra_reference'] = $this->text($registration->meta['_nmra_reference'] ?? null);
        } elseif ('UAE' === $country || 'AE' === $country) {
            $row['mohap_status']      = $this->text($registration->meta['_mohap_status'] ?? null);
            $row['mohap_tracking_id'] = $this->text($registration->meta['_mohap_tracking_id'] ?? null);
        }

        if ($includeHistory) {
            $row['status_history'] = $this->history($registration->meta['_status_history'] ?? null);
        }

        return $row;
    }

    /**
     * The stored history as a list.
     *
     * WordPress returned whatever `get_post_meta` found, which is an empty
     * string when the key was never written. A list is what the field promises.
     *
     * @return list<mixed>
     */
    private function history(mixed $stored): array
    {
        if (is_array($stored)) {
            return array_values($stored);
        }

        if (is_string($stored) && '' !== trim($stored)) {
            $decoded = json_decode($stored, true);

            if (is_array($decoded)) {
                return array_values($decoded);
            }

            return array($stored);
        }

        return array();
    }

    /**
     * The requested country filter, trimmed and de-duplicated.
     *
     * @return list<string>
     */
    private function countryFilter(mixed $raw): array
    {
        if (! is_array($raw)) {
            return array();
        }

        $countries = array();

        foreach ($raw as $country) {
            $value = $this->text($country);

            if ('' !== $value && ! in_array($value, $countries, true)) {
                $countries[] = $value;
            }
        }

        return $countries;
    }

    /**
     * @param list<array<string, mixed>> $results
     */
    private function countFound(array $results): int
    {
        $found = 0;

        foreach ($results as $result) {
            if (true === ( $result['found'] ?? false )) {
                ++$found;
            }
        }

        return $found;
    }

    /**
     * @param list<array<string, mixed>> $results
     */
    private function countMissing(array $results): int
    {
        return count($results) - $this->countFound($results);
    }
}
