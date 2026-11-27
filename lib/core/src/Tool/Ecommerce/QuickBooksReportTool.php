<?php

/**
 * Tool: quickbooks_report — pull a QuickBooks Online report for a realm.
 *
 * Proposal 164 section 164.127. The WordPress original reads a company's books
 * through the QuickBooks Online REST API, behind a credential ladder built on
 * Remote Sites: a token pasted on a connection, then an auto-refreshing OAuth
 * grant, then a legacy site-wide bearer token.
 *
 * ## The token and the realm are arguments
 *
 * This port has no ladder, because it has no Remote Sites store to walk. The
 * access token and the realm id are arguments, which is how every other
 * credentialed tool in this runtime takes its credentials — Shopify, ShipStation
 * and Printful all do the same, and for the same reason: a framework-agnostic
 * core cannot acquire a host's connection registry.
 *
 * The consequence is stated rather than hidden: **this tool does not refresh**.
 * Intuit issues an access token for 3,600 seconds, and the caller is responsible
 * for minting a fresh one. Automatic refresh needs a credential store that
 * persists a rotated refresh token — Intuit rotates it on a rolling 100-day
 * window, so a store that keeps the old one silently dies — and that store is
 * registered as a separate prerequisite rather than invented here. A 401 refusal
 * says exactly this.
 *
 * ## Four corrections
 *
 * 1. **The report is not sanitised field by field.** The original ran
 *    `sanitize_text_field()` over every string *and every key* of the decoded
 *    report before returning it. On structured financial data that is the wrong
 *    direction: it strips anything that looks like markup or a percent-encoded
 *    octet, so a label containing `<` or `%20` comes back altered, and the caller
 *    cannot tell. This runtime escapes at the output boundary instead, so the
 *    provider's values are returned as the provider sent them.
 * 2. **One payload, not two.** The original returned `raw_response` holding the
 *    sanitised copy of the whole body *and* the top-level fields beside it. The
 *    body is returned once, under `report_data`, with a size ceiling.
 * 3. **`requires-credentials` is declared.** The original declared `read-only`,
 *    `external-api` and `network-dependent` but not `requires-credentials`,
 *    although it cannot run without a token. In this runtime the token is an
 *    argument, so the flag is the honest description.
 * 4. **The 401 retry-with-refresh is gone, and the refusal says why.** The
 *    original force-refreshed an OAuth grant and retried once. There is nothing
 *    here to refresh from, so the refusal names the fix instead of pretending.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Ecommerce;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Tool\AbstractTool;

final class QuickBooksReportTool extends AbstractTool
{
    /**
     * The reports route. `%1$s` is the realm, `%2$s` the report name.
     */
    public const ENDPOINT = 'https://quickbooks.api.intuit.com/v3/company/%1$s/reports/%2$s';

    /**
     * A report is a table and can be large; this is still a refusal, not a parse.
     */
    private const MAX_BODY_BYTES = 5242880;

    public function __construct(
        ErrorFactoryInterface $errors,
        private readonly HttpClientInterface $http,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string
    {
        return 'quickbooks_report';
    }

    public function getName(): string
    {
        return 'QuickBooks Online Report';
    }

    public function getDescription(): string
    {
        return 'Fetches one QuickBooks Online report, such as ProfitAndLoss or BalanceSheet, for a '
            . 'company realm. Pass an access token and the realm id: this tool does not mint or '
            . 'refresh tokens, and Intuit access tokens last about an hour. Dates use YYYY-MM-DD. '
            . 'Returns the report body as QuickBooks sent it, unsanitised, so escape it at the '
            . 'boundary.';
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array(
            'read-only',
            'external-api',
            'network-dependent',
            'requires-credentials',
            'requires-capability',
        );
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'access_token'      => array(
                    'type'        => 'string',
                    'description' => 'QuickBooks Online OAuth access token. Intuit issues these for '
                        . 'about an hour; mint a fresh one rather than reusing an expired value.',
                ),
                'realm_id'          => array(
                    'type'        => 'string',
                    'description' => 'The QuickBooks company id. Intuit calls it the realm id and '
                        . 'returns it as `realmId` when the token is minted.',
                ),
                'report'            => array(
                    'type'        => 'string',
                    'description' => 'The report name, e.g. ProfitAndLoss, BalanceSheet or '
                        . 'CashFlow. QuickBooks report names are case-sensitive.',
                ),
                'start_date'        => array(
                    'type'        => 'string',
                    'description' => 'Inclusive start date in YYYY-MM-DD form.',
                ),
                'end_date'          => array(
                    'type'        => 'string',
                    'description' => 'Inclusive end date in YYYY-MM-DD form.',
                ),
                'accounting_method' => array(
                    'type'        => 'string',
                    'description' => 'Accrual or Cash. Sent capitalised, which is what Intuit '
                        . 'expects.',
                ),
                'minor_version'     => array(
                    'type'        => 'integer',
                    'minimum'     => 0,
                    'description' => 'Intuit API minor version, sent as `minorversion` when above 0.',
                ),
            ),
            'required'             => array('access_token', 'realm_id', 'report'),
            'additionalProperties' => false,
        );
    }

    /**
     * @param array<string, mixed> $arguments Tool arguments.
     * @param array<string, mixed> $context   Execution context.
     */
    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $token = $this->text($arguments['access_token'] ?? null);

        if ('' === $token) {
            return $this->errors->validationFailed(
                'An access token is required.',
                array('access_token' => array('Mint a QuickBooks Online access token and pass it here.'))
            );
        }

        $realm = $this->text($arguments['realm_id'] ?? null);

        if ('' === $realm) {
            return $this->errors->validationFailed(
                'A realm id is required.',
                array('realm_id' => array('The QuickBooks company id, as returned by Intuit as realmId.'))
            );
        }

        $report = $this->text($arguments['report'] ?? null);

        if ('' === $report) {
            return $this->errors->validationFailed(
                'A report name is required.',
                array('report' => array('For example ProfitAndLoss.'))
            );
        }

        $query = array();

        foreach (array('start_date' => 'start_date', 'end_date' => 'end_date') as $argument => $parameter) {
            $value = $this->text($arguments[$argument] ?? null);

            if ('' === $value) {
                continue;
            }

            if (! $this->isIsoDate($value)) {
                return $this->errors->validationFailed(
                    'Dates must use the YYYY-MM-DD format.',
                    array($argument => array('Expected a date such as 2026-01-31.'))
                );
            }

            $query[$parameter] = $value;
        }

        $method = $this->accountingMethod($arguments);

        if ($this->errors->isError($method)) {
            return $method;
        }

        if ('' !== $method) {
            $query['accounting_method'] = $method;
        }

        $minor = $this->intParam($arguments, 'minor_version', 0);

        if ($minor > 0) {
            $query['minorversion'] = $minor;
        }

        $url = sprintf(self::ENDPOINT, rawurlencode($realm), rawurlencode($report));

        if (array() !== $query) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $response = $this->request($url, $token);

        if ($this->errors->isError($response)) {
            return $response;
        }

        return $this->project($response, $report, $realm, $query);
    }

    /**
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function accountingMethod(array $arguments): mixed
    {
        $value = $this->text($arguments['accounting_method'] ?? null);

        if ('' === $value) {
            return '';
        }

        $normalised = ucfirst(strtolower($value));

        if (! in_array($normalised, array('Accrual', 'Cash'), true)) {
            return $this->errors->validationFailed(
                'The accounting method must be Accrual or Cash.',
                array('accounting_method' => array('Expected Accrual or Cash.'))
            );
        }

        return $normalised;
    }

    /**
     * One authenticated request, or the canonical refusal.
     */
    private function request(string $url, string $token): mixed
    {
        try {
            return $this->http->send(
                'GET',
                $url,
                array(
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                )
            );
        } catch (\RuntimeException $error) {
            return $this->errors->create(
                'quickbooks_request_failed',
                'The QuickBooks request failed to send. ' . $error->getMessage()
            );
        }
    }

    /**
     * Classify the response and project the report.
     *
     * @param array<string, string|int> $query The parameters that were sent.
     */
    private function project(HttpResponse $response, string $report, string $realm, array $query): mixed
    {
        $status = $response->statusCode;

        if (401 === $status) {
            return $this->errors->create(
                'quickbooks_unauthorized',
                'QuickBooks rejected the access token (HTTP 401). Intuit access tokens last about an '
                . 'hour; mint a fresh one and retry. Automatic refresh is not available here because '
                . 'this runtime has no credential store.',
                array('status' => 401)
            );
        }

        if (403 === $status) {
            return $this->errors->create(
                'quickbooks_forbidden',
                'QuickBooks denied access to this report (HTTP 403). The token is valid but not '
                . 'authorised for this company or scope; check that it carries '
                . 'com.intuit.quickbooks.accounting and was minted for this realm.',
                array('status' => 403)
            );
        }

        if (strlen($response->body) > self::MAX_BODY_BYTES) {
            return $this->errors->create(
                'quickbooks_report_too_large',
                'The QuickBooks report exceeds the maximum allowed size. Narrow the date range.'
            );
        }

        $decoded = json_decode($response->body, true);

        // A `Fault` is the provider's own structured error and it arrives with a
        // 4xx status, so it has to be read before the status is classified —
        // otherwise its detail is thrown away for "HTTP 400".
        if (is_array($decoded) && is_array($decoded['Fault'] ?? null)) {
            $fault  = $decoded['Fault'];
            $errors = is_array($fault['Error'] ?? null) ? $fault['Error'] : array();
            $first  = is_array($errors[0] ?? null) ? $errors[0] : array();
            $detail = is_scalar($first['Detail'] ?? null) ? (string) $first['Detail'] : '';
            $code   = is_scalar($first['code'] ?? null) ? (string) $first['code'] : '';

            return $this->errors->create(
                'quickbooks_fault',
                'QuickBooks reported an error for this request'
                    . ( '' !== $detail ? ': ' . $detail : '.' ),
                array('code' => $code, 'status' => $status)
            );
        }

        if (200 !== $status) {
            return $this->errors->create(
                'quickbooks_http_error',
                sprintf('QuickBooks Online returned HTTP %d.', $status),
                array('status' => $status)
            );
        }

        if (! is_array($decoded)) {
            return $this->errors->create(
                'quickbooks_invalid_json',
                'QuickBooks Online returned a response that is not JSON.'
            );
        }

        return $this->success(
            sprintf('Fetched the %s report for realm %s.', $report, $realm),
            array(
                'report'      => $report,
                'realm_id'    => $realm,
                'parameters'  => $query,
                'http_status' => $status,
                'fetched_at'  => gmdate('c'),
                'report_data' => $decoded,
            )
        );
    }

    /**
     * A string field, trimmed, or ''.
     */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
