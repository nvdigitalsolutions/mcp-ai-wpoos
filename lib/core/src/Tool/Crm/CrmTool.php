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
 * The entity type strings are constants here because the handover and
 * interaction tools resolve several of them in one call, and a typo in
 * `mcp_ai_deal` would read as "no such record" rather than as a bug.
 *
 * Two formatting helpers mirror WordPress functions the handover tool calls:
 * `humanTime()` stands in for `human_time_diff()`, and `formatCurrency()` for
 * the CRM engine's currency formatting. Neither is translated — the core has no
 * i18n layer — and `formatCurrency()` uses `number_format()` rather than
 * `number_format_i18n()` for the same reason. The units and the symbol map are
 * the same, so a bundle assembled here reads like one assembled in WordPress.
 *
 * CRM settings are an optional dependency: without a `SettingsStoreInterface`
 * the documented defaults apply, which is what a Laravel host gets until it
 * binds the option table.
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
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Tool\AbstractTool;

abstract class CrmTool extends AbstractTool
{
    /** The content type a CRM company is stored as. */
    public const COMPANY_TYPE = 'mcp_ai_company';

    /** The content type a CRM lead is stored as. */
    public const LEAD_TYPE = 'mcp_ai_lead';

    /** The content type a CRM deal is stored as. */
    public const DEAL_TYPE = 'mcp_ai_deal';

    /** The content type a CRM activity is stored as. */
    public const ACTIVITY_TYPE = 'mcp_ai_crm_activity';

    /** The WordPress option the toolkit settings live in. */
    public const SETTINGS_OPTION = 'wp_mcp_ai_crm_toolkit_settings';

    /** The subset of toolkit settings a core tool consumes today. */
    private const SETTINGS_DEFAULTS = array(
        'default_currency' => 'USD',
        'handover_ask'     => '',
    );

    /** The CRM engine's symbol map. */
    private const CURRENCY_SYMBOLS = array(
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'CAD' => 'C$',
        'AUD' => 'A$',
        'INR' => '₹',
    );

    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly ContentStoreInterface $content,
        protected readonly ?SettingsStoreInterface $settings = null,
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
     * An integer field, or 0 when absent or unparsable.
     *
     * Meta is untyped: an id may come back as `"12"`, `12`, or `12.0` from a
     * JSON store. Casting the raw value would turn an array into 1, so the
     * scalar check is the point of this helper.
     */
    protected function intValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (int) trim($value);
        }

        return 0;
    }

    /**
     * A numeric field, or 0.0 when absent. Company revenue and a deal's amount
     * are the users today.
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

    /**
     * Resolve the CRM toolkit settings over the documented defaults.
     *
     * @return array<string, mixed>
     */
    protected function crmSettings(): array
    {
        $stored = null !== $this->settings
            ? $this->settings->get(self::SETTINGS_OPTION, array())
            : array();

        if (! is_array($stored)) {
            $stored = array();
        }

        return array_replace(self::SETTINGS_DEFAULTS, $stored);
    }

    /**
     * Format an amount the way the CRM engine does.
     *
     * An unknown currency code keeps the engine's `"CODE "` prefix behaviour
     * rather than falling back to the default symbol, because a caller that
     * named a currency should see that name.
     */
    protected function formatCurrency(float $amount, string $currency = ''): string
    {
        if ('' === $currency) {
            $currency = (string) ( $this->crmSettings()['default_currency'] ?? 'USD' );
        }

        $symbol = self::CURRENCY_SYMBOLS[ $currency ] ?? $currency . ' ';

        return $symbol . number_format($amount, 2);
    }

    /**
     * A compact stand-in for `human_time_diff()`.
     */
    protected function humanTime(int $seconds): string
    {
        $seconds = max(0, $seconds);

        $units = array(
            array('year', 31536000),
            array('month', 2592000),
            array('week', 604800),
            array('day', 86400),
            array('hour', 3600),
            array('min', 60),
        );

        foreach ($units as $unit) {
            list($label, $size) = $unit;

            if ($seconds >= $size) {
                $count = max(1, (int) round($seconds / $size));

                return $count . ' ' . $label . ( $count > 1 ? 's' : '' );
            }
        }

        return $seconds . ' sec' . ( 1 === $seconds ? '' : 's' );
    }

    /**
     * The core equivalent of `sanitize_key()`.
     */
    protected function key(mixed $value): string
    {
        $clean = preg_replace(
            '/[^A-Za-z0-9_\-]/',
            '',
            is_scalar($value) ? (string) $value : ''
        );

        return strtolower(trim((string) $clean));
    }

    /**
     * The core equivalent of `wp_strip_all_tags()` for inline text.
     */
    protected function plainText(string $html): string
    {
        $text = preg_replace('/\s+/', ' ', strip_tags($html));

        return trim((string) $text);
    }
}
