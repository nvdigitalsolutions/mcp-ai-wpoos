<?php

/**
 * Revoke an SSH key — `DELETE /ssh-key/{ssh_key_id}`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_SSH_Key_Delete` (Proposal 164 section
 * 164.143). The third of the family's destructive tools.
 *
 * ## What "deleted" means here
 *
 * The key record is removed from the Cloudways account, so every machine that
 * authenticated with it loses access immediately. There is no undo and no
 * companion tool that recreates it — `cloudways_ssh_key_create` would add the
 * same public key again only if the caller still has it. The description says
 * so where the schema is read, and the confirmation is the strict literal
 * `true` that `cloudways_app_delete` established: a truthy string is not a
 * confirmation, and `"false"` is a non-empty string.
 *
 * The API's answer travels under `result`, the shape the other destructive tool
 * uses, so a caller does not have to branch on which delete it called.
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

class DeleteSshKeyTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_ssh_key_delete';
    }

    public function getName(): string
    {
        return 'Cloudways: Delete SSH Key';
    }

    public function getDescription(): string
    {
        return 'Permanently revokes an SSH key, cutting access for every machine that uses it. Irreversible; requires confirm: true.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'ssh_key_id' => array(
                    'type'        => 'integer',
                    'description' => 'The SSH key id, as listed by cloudways_ssh_key_list.',
                    'minimum'     => 1,
                ),
                'confirm'    => array(
                    'type'        => 'boolean',
                    'description' => 'Set to true to confirm this irreversible action.',
                ),
            ),
            'required'             => array('ssh_key_id', 'confirm'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $sshKeyId = max(0, $this->intParam($arguments, 'ssh_key_id'));

        if ($sshKeyId <= 0) {
            return $this->errors->validationFailed(
                'A valid SSH key id is required.',
                array('ssh_key_id' => array('The id shown by cloudways_ssh_key_list.'))
            );
        }

        if (! $this->confirmed($arguments)) {
            return $this->errors->create(
                'cloudways_confirm_required',
                'You must set confirm to true to perform this destructive action.'
            );
        }

        try {
            $result = $this->client->delete('/ssh-key/' . $sshKeyId);
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success(
            sprintf('SSH key %d deleted successfully.', $sshKeyId),
            array('result' => $result)
        );
    }
}
