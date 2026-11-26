<?php

/**
 * Poll a GTmetrix test.
 *
 * Port of `WP_MCP_AI_Tool_GTmetrix_Get_Test_Status` (Proposal 164 section
 * 164.92).
 *
 * **Correction:** a completed test answers `303 See Other` with
 * `Location: /reports/{id}`, and WordPress's HTTP client followed the redirect,
 * so the tool received the *report*, found no `state` attribute, and answered
 * `state: unknown` with an empty `report_id` — the one transition a poller
 * exists to detect. The port reads the status and the location, and also falls
 * back to the `report` attribute when a client or proxy has flattened the
 * transition to `200`.
 *
 * GTmetrix keeps tests for 24 hours, so a test id older than that answers a
 * not-found error rather than a state.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\GTmetrix;

use Nvoos\Core\Domain\Error\GtmetrixApiException;

class GtmetrixGetTestStatusTool extends GtmetrixTool
{
    public function getSlug(): string
    {
        return 'gtmetrix_get_test_status';
    }

    public function getName(): string
    {
        return 'GTmetrix Get Test Status';
    }

    public function getDescription(): string
    {
        return 'Reports the state of a GTmetrix test (queued, started, error or completed) and, once complete, the report id. Costs no credits.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'test_id' => array(
                    'type'        => 'string',
                    'description' => 'The test id returned by gtmetrix_start_test.',
                    'minLength'   => 1,
                ),
            ),
            'required'             => array('test_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $guard = $this->guard();

        if (null !== $guard) {
            return $guard;
        }

        $testId = array_key_exists('test_id', $arguments) && is_scalar($arguments['test_id'])
            ? trim((string) $arguments['test_id'])
            : '';

        if ('' === $testId || 1 !== preg_match('/^[A-Za-z0-9_-]+$/', $testId)) {
            return $this->errors->validationFailed(
                'test_id is required and may contain only letters, digits, hyphens and underscores.',
                array('test_id' => array('Invalid test id.'))
            );
        }

        try {
            $response = $this->client->request('GET', 'tests/' . rawurlencode($testId));
        } catch (GtmetrixApiException $error) {
            return $this->failure($error);
        }

        $reportId = $this->reportFromLocation($response['location']);
        $resource = is_array($response['data']) ? $response['data'] : array();
        $attrs    = $this->attrs($resource);
        $state    = $this->text($attrs['state'] ?? '');

        if (303 === $response['status'] && '' !== $reportId) {
            return $this->success(
                'The test is complete.',
                array(
                    'test_id'     => $testId,
                    'state'       => 'completed',
                    'report_id'   => $reportId,
                    'http_status' => $response['status'],
                )
            );
        }

        if ('' === $state) {
            $state = 'unknown';
        }

        if ('completed' === $state) {
            $fromAttribute = $this->text($attrs['report'] ?? '');

            return $this->success(
                'The test is complete.',
                array(
                    'test_id'     => $testId,
                    'state'       => 'completed',
                    'report_id'   => '' !== $fromAttribute ? $fromAttribute : $reportId,
                    'http_status' => $response['status'],
                )
            );
        }

        $data = array(
            'test_id'     => $testId,
            'state'       => $state,
            'http_status' => $response['status'],
        );

        $url = $this->text($attrs['url'] ?? '');

        if ('' !== $url) {
            $data['url'] = $url;
        }

        if ('error' === $state) {
            $data['error'] = $this->text($attrs['error'] ?? 'Unknown test error.');
        }

        return $this->success(
            'error' === $state ? 'The test failed.' : 'The test is ' . $state . '.',
            $data
        );
    }

    /**
     * The report id inside a `Location: /api/2.0/reports/{id}` header.
     */
    private function reportFromLocation(string $location): string
    {
        if (1 === preg_match('#/reports/([^/?\#]+)#', $location, $match)) {
            return rawurldecode($match[1]);
        }

        return '';
    }
}
