<?php

/**
 * Attach an SSH public key — `POST /ssh-key`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_SSH_Key_Create` (Proposal 164 section
 * 164.142).
 *
 * ## One endpoint, two scopes
 *
 * `app_id` is optional and travels as `0` when absent, exactly as WordPress
 * sent it: `0` means the key is attached to the server itself, and a positive
 * id attaches it to one application on that server. The body always carries the
 * field, because omitting it is a different request from sending the
 * server-level zero.
 *
 * ## Private key material is refused before the request
 *
 * The tool adds a key, so a caller that pastes the wrong file would upload a
 * *private* key to a third-party control plane — a breach no later validation
 * can undo. The tool's own guidance says private material must never be
 * submitted, and this is where that sentence is enforced: a value containing a
 * PEM private-key header is refused locally. The check is narrow on purpose.
 * It cannot reject a valid public key, and it does not attempt to parse the key
 * — the API remains the authority on whether a public key is well formed.
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

class CreateSshKeyTool extends CloudwaysTool
{
    /**
     * A PEM private-key header, which no public key contains.
     *
     * `-----BEGIN RSA PRIVATE KEY-----`, `-----BEGIN OPENSSH PRIVATE KEY-----`
     * and the encrypted variants all match; `-----BEGIN PUBLIC KEY-----` and the
     * one-line `ssh-rsa AAAA...` form do not.
     */
    private const PRIVATE_KEY_HEADER = '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----/';

    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_ssh_key_create';
    }

    public function getName(): string
    {
        return 'Cloudways: Create SSH Key';
    }

    public function getDescription(): string
    {
        return 'Adds an SSH public key to a Cloudways server, or to one application when app_id is given. Private key material is refused before the request.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'server_id'  => array(
                    'type'        => 'integer',
                    'description' => 'The Cloudways server id.',
                    'minimum'     => 1,
                ),
                'app_id'     => array(
                    'type'        => 'integer',
                    'description' => 'The application id, or 0 for a server-level key.',
                    'minimum'     => 0,
                ),
                'label'      => array(
                    'type'        => 'string',
                    'description' => 'A label identifying the key.',
                    'minLength'   => 1,
                ),
                'public_key' => array(
                    'type'        => 'string',
                    'description' => 'The SSH public key content, e.g. one line beginning ssh-ed25519.',
                    'minLength'   => 1,
                ),
            ),
            'required'             => array('server_id', 'label', 'public_key'),
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

        $label = $this->label($arguments);

        if ('' === $label) {
            return $this->errors->validationFailed(
                'A label is required.',
                array('label' => array('A short name identifying the key.'))
            );
        }

        $publicKey = trim($this->stringParam($arguments, 'public_key'));

        if ('' === $publicKey) {
            return $this->errors->validationFailed(
                'An SSH public key is required.',
                array('public_key' => array('The one-line public key, e.g. ssh-ed25519 AAAA... user@host.'))
            );
        }

        if (1 === preg_match(self::PRIVATE_KEY_HEADER, $publicKey)) {
            return $this->errors->create(
                'cloudways_private_key_rejected',
                'The value looks like a private key. Send only the public key; private key material must never leave the machine that holds it.'
            );
        }

        try {
            $result = $this->client->post('/ssh-key', array(
                'server_id'  => $serverId,
                'app_id'     => $this->appId($arguments),
                'label'      => $label,
                'public_key' => $publicKey,
            ));
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success(
            sprintf('SSH key "%s" created successfully.', $label),
            $result
        );
    }
}
