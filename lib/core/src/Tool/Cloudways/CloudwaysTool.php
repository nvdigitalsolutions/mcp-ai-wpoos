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
