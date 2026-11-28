<?php

/**
 * Shared ground for the Cloudways toolkit ports.
 *
 * Two things every one of the sixty tools needs, and nothing else:
 *
 * - **The same capability WordPress asks for.** Infrastructure management is
 *   `manage_options` in the Pro toolkit and stays that way here. The classroom
 *   family uses `edit_posts`; a server delete must not.
 *
 * - **One place where a failed API call becomes an envelope.** The client
 *   throws a classified exception; `failure()` turns it into the canonical
 *   error with the code and the HTTP status preserved. A tool that caught the
 *   exception itself would eventually invent its own code.
 *
 * The payload readers are deliberately tolerant: the API omits keys for
 * features an account does not have, and WordPress's `absint( $server['id'] )`
 * turns a missing key into a warning. Here a missing key is zero or an empty
 * string, which is what the caller would have received anyway.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Cloudways;

use Nvoos\Core\Domain\Contract\CloudwaysClientInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Error\CloudwaysApiException;
use Nvoos\Core\Tool\AbstractTool;

abstract class CloudwaysTool extends AbstractTool
{
    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly CloudwaysClientInterface $client,
    ) {
        parent::__construct($e);
    }

    public function getRequiredCapability(): string
    {
        return 'manage_options';
    }

    /**
     * A refused API call, as the canonical error envelope.
     */
    protected function failure(CloudwaysApiException $e): mixed
    {
        $data = $e->statusCode > 0 ? array('status' => $e->statusCode) : array();

        return $this->errors->create($e->errorCode, $e->getMessage(), $data);
    }

    /**
     * The Cloudways response said something this tool cannot read.
     */
    protected function malformed(string $what): mixed
    {
        return $this->errors->create(
            'cloudways_invalid_response',
            'Cloudways returned an unexpected response: ' . $what . ' is missing.'
        );
    }

    protected function serverId(array $arguments): int
    {
        return max(0, $this->intParam($arguments, 'server_id'));
    }

    protected function appId(array $arguments): int
    {
        return max(0, $this->intParam($arguments, 'app_id'));
    }

    /**
     * The server and app a call targets, or the refusal envelope.
     *
     * Twenty-odd tools address one application on one server. Both ids are
     * required and both are validated before a request, so a caller that forgot
     * an argument gets a named validation error instead of a 404 from Cloudways
     * that does not say which argument was wrong.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array{server_id: int, app_id: int}|mixed The pair, or the canonical error envelope.
     */
    protected function appPair(array $arguments): mixed
    {
        $serverId = $this->serverId($arguments);

        if ($serverId <= 0) {
            return $this->errors->validationFailed(
                'A valid server id is required.',
                array('server_id' => array('The id shown by cloudways_list_servers.'))
            );
        }

        $appId = $this->appId($arguments);

        if ($appId <= 0) {
            return $this->errors->validationFailed(
                'A valid app id is required.',
                array('app_id' => array('The id shown by cloudways_list_apps.'))
            );
        }

        return array('server_id' => $serverId, 'app_id' => $appId);
    }

    /**
     * The positive integer fields a caller provided, split by validity.
     *
     * WordPress passed every writer argument through `absint()` and then dropped
     * anything that was not `> 0`. On a negative input that silently flipped the
     * sign and sent the opposite value, and on a zero it dropped the field with
     * no explanation. The port separates the two cases: absent fields stay
     * absent, and a field that was provided with an unusable value is named in a
     * validation error rather than rewritten.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     * @param list<string>         $keys      Field names to read.
     *
     * @return array{set: array<string, int>, invalid: list<string>}
     */
    protected function positiveInts(array $arguments, array $keys): array
    {
        $set     = array();
        $invalid = array();

        foreach ($keys as $key) {
            if (! array_key_exists($key, $arguments)) {
                continue;
            }

            $value = $this->intParam($arguments, $key, 0);

            if ($value <= 0) {
                $invalid[] = $key;
                continue;
            }

            $set[ $key ] = $value;
        }

        return array('set' => $set, 'invalid' => $invalid);
    }

    /**
     * The validation envelope for unusable writer fields.
     *
     * @param list<string> $fields Field names.
     */
    protected function invalidFields(array $fields, string $requirement = 'A positive integer is required.'): mixed
    {
        $errors = array();

        foreach ($fields as $field) {
            $errors[ $field ] = array($requirement);
        }

        return $this->errors->validationFailed('One or more settings have an unusable value.', $errors);
    }

    /**
     * A string field from an API row, or an empty string when absent.
     */
    protected function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * An integer field from an API row, or zero when absent or non-numeric.
     */
    protected function number(mixed $value): int
    {
        return is_scalar($value) ? (int) $value : 0;
    }
}
