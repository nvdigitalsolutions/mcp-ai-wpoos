<?php

/**
 * Shared ground for the three bodyless server actions.
 *
 * Proposal 164 section 164.139. Restart, start and stop differ in one path
 * segment and one sentence; everything else — the capability, the id check, the
 * request, the refusal and the envelope — is the same, so it lives here.
 *
 * The API answers each with an operation to poll, which travels back unchanged
 * under `result`; none of the three waits for the server to reach Running.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Cloudways;

use Nvoos\Core\Domain\Error\CloudwaysApiException;

abstract class ServerLifecycleTool extends CloudwaysTool
{
    /** The path segment after `/server/{id}`. */
    abstract protected function action(): string;

    /** The sentence a successful call returns. */
    abstract protected function message(int $serverId): string;

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'server_id' => array(
                    'type'        => 'integer',
                    'description' => 'The Cloudways server id.',
                    'minimum'     => 1,
                ),
            ),
            'required'             => array('server_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $serverId = $this->serverId($arguments);

        if ($serverId <= 0) {
            return $this->errors->validationFailed(
                'A valid server id is required.',
                array('server_id' => array('The id shown by cloudways_list_servers.'))
            );
        }

        try {
            $result = $this->client->post('/server/' . $serverId . '/' . $this->action());
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success($this->message($serverId), array('result' => $result));
    }
}
