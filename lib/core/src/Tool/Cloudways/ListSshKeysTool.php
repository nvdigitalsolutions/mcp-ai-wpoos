<?php

/**
 * The SSH keys attached to one server — `GET /ssh-key`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_SSH_Key_List` (Proposal 164 section
 * 164.133). The WordPress tool embedded the server id in the path
 * (`/ssh-key?server_id=N`); this port passes it as the client's query argument,
 * which is the same request without hand-built URL text.
 *
 * The key material itself is not projected. WordPress returned `id`, `label`,
 * `key_type` and `user`; a public key is not a secret, but the tool's job is to
 * name which keys exist, and a caller that needs the material can read it in the
 * console.
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

class ListSshKeysTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_ssh_key_list';
    }

    public function getName(): string
    {
        return 'Cloudways: List SSH Keys';
    }

    public function getDescription(): string
    {
        return 'Lists the SSH keys attached to a Cloudways server: id, label, key type and user. Read-only.';
    }

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
            $result = $this->client->get('/ssh-key', array('server_id' => $serverId));
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        if (! is_array($result['ssh_keys'] ?? null)) {
            return $this->malformed('ssh_keys');
        }

        $keys = array();

        foreach ($result['ssh_keys'] as $key) {
            if (! is_array($key)) {
                continue;
            }

            $keys[] = array(
                'id'       => $this->number($key['id'] ?? 0),
                'label'    => $this->text($key['label'] ?? null),
                'key_type' => $this->text($key['key_type'] ?? null),
                'user'     => $this->text($key['user'] ?? null),
            );
        }

        return $this->success(
            sprintf('Found %d SSH %s.', count($keys), 1 === count($keys) ? 'key' : 'keys'),
            array('ssh_keys' => $keys)
        );
    }
}
