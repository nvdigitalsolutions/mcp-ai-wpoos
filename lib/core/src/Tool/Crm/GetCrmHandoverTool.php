<?php

/**
 * A paste-ready CRM handover bundle for a lead or a deal.
 *
 * Ported from `WP_MCP_AI_Tool_Get_CRM_Handover`. The bundle is assembled from
 * content items and their meta only — no model call is made at assembly time —
 * and the output is the same plain-text layout, section by section:
 *
 *  - **lead**: name, email, phone, company, job title, source, lifecycle,
 *    status, score, BANT, and the last email's date/sentiment/snippet.
 *  - **company**: name, website, industry, size, status.
 *  - **deals**: one block per deal with stage, amount, probability and close
 *    date, plus time in the current stage.
 *  - **history**: the deal's stage ledger (`stage_history`), oldest first.
 *  - **activities**: the 20 most recent activities linked to the subject.
 *  - **closing ask**: the toolkit's `handover_ask` setting, or a default.
 *
 * Two behaviours are pinned as they are in WordPress rather than corrected:
 *
 * 1. The company block reads `_company_status`, while `create_company` writes
 *    `_company_target_status`. A company created by the toolkit therefore
 *    renders an empty `Status:` line. Correcting it would change an output a
 *    consumer may already parse; it is recorded as an upstream bug candidate.
 * 2. A missing `lead_id`, `company_id` or activity linkage simply omits that
 *    section — the bundle is still returned. Only an invalid `entity` /
 *    `entity_id` or an unknown record is an error.
 *
 * `stage_history`, `bant_assessment` and the other structured meta values are
 * read as arrays or JSON strings. PHP-serialised strings are not decoded: the
 * core will not call `unserialize()` on stored data.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Crm;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;

class GetCrmHandoverTool extends CrmTool
{
    /** The bundle sections, in output order. */
    public const SECTIONS = array('lead', 'company', 'deals', 'activities', 'history');

    /** How many deals a lead's bundle lists, as WordPress does. */
    public const MAX_DEALS = 50;

    /** How many activities a bundle lists, as WordPress does. */
    public const MAX_ACTIVITIES = 20;

    public function __construct(
        ErrorFactoryInterface $e,
        ContentStoreInterface $content,
        ?SettingsStoreInterface $settings = null,
    ) {
        parent::__construct($e, $content, $settings);
    }

    public function getSlug(): string
    {
        return 'get_crm_handover';
    }

    public function getName(): string
    {
        return 'Get CRM Handover';
    }

    public function getDescription(): string
    {
        return 'Assemble a paste-ready plain-text handover bundle for a lead or deal: entity facts, BANT scores, stage history with time-in-stage, last email signals, company profile, recent activities, and a closing ask. No AI call is made.';
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'entity'    => array(
                    'type'        => 'string',
                    'description' => 'Entity kind: "lead" or "deal". Defaults to "deal".',
                    'enum'        => array('lead', 'deal'),
                    'default'     => 'deal',
                ),
                'entity_id' => array(
                    'type'        => 'integer',
                    'description' => 'Lead or deal ID (required).',
                    'maximum'     => 9007199254740991,
                ),
                'include'   => array(
                    'type'        => 'array',
                    'items'       => array('type' => 'string'),
                    'description' => 'Sections to include: lead, company, deals, activities, history. Defaults to all.',
                ),
            ),
            'required'             => array('entity_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $entity   = $this->key($this->stringParam($arguments, 'entity', 'deal'));
        $entityId = $this->intParam($arguments, 'entity_id');

        if (! in_array($entity, array('lead', 'deal'), true)) {
            return $this->errors->validationFailed(
                'entity must be "lead" or "deal".',
                array('entity' => array('Use lead or deal.')),
            );
        }

        if ($entityId <= 0) {
            return $this->errors->validationFailed(
                'A valid entity ID is required.',
                array('entity_id' => array('Give the lead or deal to bundle.')),
            );
        }

        $include = self::SECTIONS;

        if (isset($arguments['include']) && is_array($arguments['include'])) {
            $requested = array_map(fn (mixed $value): string => $this->key($value), $arguments['include']);
            $include   = array_values(array_intersect($requested, self::SECTIONS));

            if (array() === $include) {
                return $this->errors->validationFailed(
                    'include must contain at least one of: lead, company, deals, activities, history.',
                    array('include' => array('Name at least one known section.')),
                );
            }
        }

        // ── Resolve the subject ──────────────────────────────────────────
        $deal   = null;
        $leadId = 0;

        if ('deal' === $entity) {
            $deal = $this->content->find($entityId);

            if (! $deal instanceof ContentItem || self::DEAL_TYPE !== $deal->type) {
                return $this->errors->notFound('Deal not found.', array('status' => 404));
            }

            $leadId = $this->intValue($deal->meta['lead_id'] ?? 0);
        } else {
            $lead = $this->content->find($entityId);

            if (! $lead instanceof ContentItem || self::LEAD_TYPE !== $lead->type) {
                return $this->errors->notFound('Lead not found.', array('status' => 404));
            }

            $leadId = $entityId;
        }

        $settings = $this->crmSettings();
        $ask      = (string) ( $settings['handover_ask'] ?? '' );

        $lines    = array();
        $sections = array();

        // ── Lead facts ───────────────────────────────────────────────────
        if (in_array('lead', $include, true) && $leadId > 0) {
            $lead = $this->content->find($leadId);

            if ($lead instanceof ContentItem && self::LEAD_TYPE === $lead->type) {
                $meta  = $lead->meta;
                $first = $this->text($meta['first_name'] ?? '');
                $last  = $this->text($meta['last_name'] ?? '');
                $name  = trim($first . ' ' . $last);

                $lines[] = '## Lead';
                $lines[] = '- Name: ' . ( '' !== $name ? $name : ( '' !== $lead->title ? $lead->title : 'Unknown' ) );
                $lines[] = '- Email: ' . $this->text($meta['email'] ?? '');
                $lines[] = '- Phone: ' . $this->text($meta['phone'] ?? '');
                $lines[] = '- Company: ' . $this->text($meta['company_name'] ?? '');
                $lines[] = '- Job title: ' . $this->text($meta['job_title'] ?? '');
                $lines[] = '- Source: ' . $this->text($meta['source'] ?? '');
                $lines[] = '- Lifecycle: ' . $this->text($meta['lifecycle_stage'] ?? '');
                $lines[] = '- Status: ' . $this->text($meta['lead_status'] ?? '');
                $lines[] = '- Score: ' . $this->text($meta['lead_score'] ?? '0');

                $bant = $this->structured($meta['bant_assessment'] ?? null);

                if (array() !== $bant) {
                    $encoded = json_encode($bant);
                    $lines[] = '- BANT: ' . ( false !== $encoded ? $encoded : '{}' );
                }

                $received = $this->text($meta['last_email_received'] ?? '');

                if ('' !== $received) {
                    $sentiment = $this->text($meta['last_email_sentiment'] ?? '');
                    $lines[]   = '- Last reply: ' . $received
                        . ( '' !== $sentiment ? ' (' . $sentiment . ')' : '' );

                    $snippet = $this->text($meta['last_email_snippet'] ?? '');

                    if ('' !== $snippet) {
                        $lines[] = '  "' . $snippet . '"';
                    }
                }

                $sections['lead'] = true;
            }
        }

        // ── Company profile ──────────────────────────────────────────────
        $companyId = $leadId > 0 ? $this->intValue($this->leadMeta($leadId, 'company_id')) : 0;

        if (in_array('company', $include, true) && $companyId > 0) {
            $company = $this->content->find($companyId);

            if ($company instanceof ContentItem && self::COMPANY_TYPE === $company->type) {
                $lines[] = '';
                $lines[] = '## Company';
                $lines[] = '- Name: ' . $company->title;
                $lines[] = '- Website: ' . $this->text($company->meta['_company_website'] ?? '');
                $lines[] = '- Industry: ' . $this->text($company->meta['_company_industry'] ?? '');
                $lines[] = '- Size: ' . $this->text($company->meta['_company_size'] ?? '');
                // Pinned as-is: WordPress reads `_company_status`, while
                // `create_company` writes `_company_target_status`.
                $lines[] = '- Status: ' . $this->text($company->meta['_company_status'] ?? '');

                $sections['company'] = true;
            }
        }

        // ── Deal / deals ─────────────────────────────────────────────────
        if (in_array('deals', $include, true) && $leadId > 0) {
            $deals = array();

            if ($deal instanceof ContentItem) {
                $deals = array($deal);
            } else {
                $deals = $this->dealsForLead($leadId);
            }

            if (array() !== $deals) {
                $lines[] = '';
                $lines[] = '## Deals';

                foreach ($deals as $item) {
                    $stage    = $this->text($item->meta['pipeline_stage'] ?? '');
                    $amount   = $this->money($item->meta['amount'] ?? 0);
                    $currency = $this->text($item->meta['currency'] ?? '');

                    $lines[] = '- #' . $item->id . ' ' . $item->title
                        . ' [' . $stage . ']'
                        . ( $amount > 0 ? ' — ' . $this->formatCurrency($amount, $currency) : '' );
                    $lines[] = '  Probability: ' . $this->text($item->meta['win_probability'] ?? '')
                        . ', Close: ' . $this->text($item->meta['expected_close_date'] ?? '');

                    $lines[] = '  In stage for ' . $this->humanTime($this->secondsInStage($item));
                }

                $sections['deals'] = true;
            }
        }

        // ── Stage history ────────────────────────────────────────────────
        if (in_array('history', $include, true) && 'deal' === $entity && $deal instanceof ContentItem) {
            $history = $this->ledger($deal->meta['stage_history'] ?? array());

            if (array() !== $history) {
                $lines[] = '';
                $lines[] = '## Stage history';

                foreach ($history as $entry) {
                    $from = $this->text($entry['from'] ?? '');
                    $lines[] = '- ' . $this->text($entry['at'] ?? '')
                        . ' ' . ( '' !== $from ? $from . ' → ' : '' )
                        . $this->text($entry['to'] ?? '')
                        . ' (' . ( '' !== $this->text($entry['source'] ?? '') ? $this->text($entry['source'] ?? '') : 'tool' ) . ')';
                }

                $sections['history'] = true;
            }
        }

        // ── Recent activities ────────────────────────────────────────────
        if (in_array('activities', $include, true) && $leadId > 0) {
            $relatedType = 'deal' === $entity ? 'deal' : 'lead';
            $relatedId   = 'deal' === $entity ? $entityId : $leadId;
            $activities  = $this->activitiesFor($relatedType, $relatedId);

            if (array() !== $activities) {
                $lines[] = '';
                $lines[] = '## Recent activities';

                foreach ($activities as $activity) {
                    $type = $this->text($activity->meta['activity_type'] ?? '');
                    $body = $this->plainText($activity->content);

                    $lines[] = '- [' . $type . '] ' . $activity->title
                        . ( '' !== $body ? ' — ' . $body : '' );
                }

                $sections['activities'] = true;
            }
        }

        // ── Closing ask ──────────────────────────────────────────────────
        $lines[] = '';
        $lines[] = '## What I need from you';
        $lines[] = '' !== $ask ? $ask : 'Summarize this record and propose the next action.';

        return $this->success(
            'Handover bundle assembled.',
            array(
                'entity'    => $entity,
                'entity_id' => $entityId,
                'sections'  => array_keys($sections),
                'text'      => implode("\n", $lines),
            )
        );
    }

    /**
     * The deals linked to a lead, newest first.
     *
     * @return list<ContentItem>
     */
    private function dealsForLead(int $leadId): array
    {
        $result = $this->content->query(
            new ContentQuery(
                types: array(self::DEAL_TYPE),
                statuses: array('publish'),
                metaQuery: array(
                    array('key' => 'lead_id', 'value' => $leadId, 'type' => 'NUMERIC'),
                ),
                orderBy: 'date',
                order: 'DESC',
                page: 1,
                perPage: self::MAX_DEALS,
            )
        );

        $deals = array();

        foreach ($result->items as $item) {
            if ($item instanceof ContentItem && self::DEAL_TYPE === $item->type) {
                $deals[] = $item;
            }
        }

        return $deals;
    }

    /**
     * The activities linked to a lead or deal, newest first.
     *
     * @return list<ContentItem>
     */
    private function activitiesFor(string $relatedType, int $relatedId): array
    {
        $result = $this->content->query(
            new ContentQuery(
                types: array(self::ACTIVITY_TYPE),
                statuses: array('publish'),
                metaQuery: array(
                    'relation' => 'AND',
                    array('key' => 'related_type', 'value' => $relatedType),
                    array('key' => 'related_id', 'value' => $relatedId, 'type' => 'NUMERIC'),
                ),
                orderBy: 'date',
                order: 'DESC',
                page: 1,
                perPage: self::MAX_ACTIVITIES,
            )
        );

        $activities = array();

        foreach ($result->items as $item) {
            if ($item instanceof ContentItem && self::ACTIVITY_TYPE === $item->type) {
                $activities[] = $item;
            }
        }

        return $activities;
    }

    /**
     * Seconds the deal has spent in its current stage.
     *
     * `stage_changed_at` when present, the deal's own creation time otherwise —
     * the same fallback WordPress applies to deals older than the ledger.
     */
    private function secondsInStage(ContentItem $deal): int
    {
        $changedAt = $this->text($deal->meta['stage_changed_at'] ?? '');
        $timestamp = '' !== $changedAt ? strtotime($changedAt) : false;

        if (false === $timestamp) {
            $timestamp = $deal->createdAt->getTimestamp();
        }

        return max(0, time() - $timestamp);
    }

    /**
     * A meta value that should be a list of arrays.
     *
     * @return list<array<string, mixed>>
     */
    private function ledger(mixed $value): array
    {
        if (! is_array($value)) {
            return array();
        }

        $entries = array();

        foreach ($value as $entry) {
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * A meta value that should be an array, accepting a JSON string too.
     *
     * @return array<mixed>
     */
    private function structured(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && '' !== trim($value)) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return array();
    }

    /**
     * One meta value from a lead, without loading the whole item again.
     */
    private function leadMeta(int $leadId, string $key): mixed
    {
        $lead = $this->content->find($leadId);

        return $lead instanceof ContentItem ? ( $lead->meta[ $key ] ?? null ) : null;
    }
}
