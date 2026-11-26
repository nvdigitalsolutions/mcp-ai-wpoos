<?php

/**
 * Shared ground for the CRM toolkit ports.
 *
 * The CRM stores its entities as content items with a prefixed type and a
 * `_company_*` / `_lead_*` meta family, so the port reads and writes them
 * through `ContentStoreInterface` exactly as the classroom family does. Two
 * helpers are shared by every tool in the family:
 *
 * - **Tolerant field readers.** A company created before a field existed has no
 *   key for it, and the WordPress projection calls `get_post_meta()` which
 *   yields an empty string. `text()` and `money()` yield the same without the
 *   undefined-key warning.
 * - **One company projection.** `companyRow()` is the shape `get_companies`
 *   returns, and it is defined once so a later company-aware tool (research,
 *   handover) cannot drift from it.
 *
 * `edit_url` is present and **null**. WordPress returns an admin link; this
 * runtime has no post edit screen. The key is kept so a shape-compatible
 * consumer does not have to distinguish "absent because this runtime" from
 * "absent because the tool changed".
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
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Tool\AbstractTool;

abstract class CrmTool extends AbstractTool
{
    /** The content type a CRM company is stored as. */
    public const COMPANY_TYPE = 'mcp_ai_company';

    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    /**
     * @param array<string, mixed> $meta
     */
    protected function companyRow(ContentItem $item, array $meta = array()): array
    {
        $meta = array() !== $meta ? $meta : $item->meta;

        return array(
            'id'            => $item->id,
            'company_name'  => $item->title,
            'description'   => $item->content,
            'industry'      => $this->text($meta['_company_industry'] ?? null),
            'company_size'  => $this->text($meta['_company_size'] ?? null),
            'website'       => $this->text($meta['_company_website'] ?? null),
            'city'          => $this->text($meta['_company_city'] ?? null),
            'state'         => $this->text($meta['_company_state'] ?? null),
            'country'       => $this->text($meta['_company_country'] ?? null),
            'phone'         => $this->text($meta['_company_phone'] ?? null),
            'revenue'       => $this->money($meta['_company_revenue'] ?? null),
            'target_status' => $this->text($meta['_company_target_status'] ?? null),
            'linkedin'      => $this->text($meta['_company_linkedin'] ?? null),
            'twitter'       => $this->text($meta['_company_twitter'] ?? null),
            'edit_url'      => null,
            'created_date'  => $item->createdAt->format('Y-m-d H:i:s'),
        );
    }

    protected function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * A numeric field, or 0.0 when absent. `revenue` is the only one today.
     */
    protected function money(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return 0.0;
        }

        $clean = str_replace(array(',', ' ', '$', '£', '€'), '', trim($value));

        return is_numeric($clean) ? (float) $clean : 0.0;
    }
}
