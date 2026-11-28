<?php

/**
 * Generate an application's Git deploy key — `POST /app/{server}/{app}/git/key/generate`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_Git_Generate_Key` (Proposal 164 section
 * 164.140). No body. The Cloudways side creates the keypair and keeps the
 * private half; the tool returns the API answer, which carries the public key to
 * paste into the Git host.
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

class GitGenerateKeyTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_git_generate_key';
    }

    public function getName(): string
    {
        return 'Cloudways: Generate Git Deploy Key';
    }

    public function getDescription(): string
    {
        return 'Generates a new Git deploy keypair for an application. Replaces any existing key.';
    }

    public function getParametersSchema(): array
    {
        return $this->targetSchema();
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $pair = $this->appPair($arguments);

        if (! is_array($pair) || ! isset($pair['app_id'])) {
            return $pair;
        }

        try {
            $result = $this->client->post(
                '/app/' . $pair['server_id'] . '/' . $pair['app_id'] . '/git/key/generate'
            );
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success(
            sprintf('Git deploy key generated for app %d.', $pair['app_id']),
            array('result' => $result)
        );
    }
}
