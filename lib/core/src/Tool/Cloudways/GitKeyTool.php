<?php

/**
 * Read an application's Git deploy key — `GET /app/{server}/{app}/git/key`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_Git_Key_Get` (Proposal 164 section 164.140).
 * The public key and its type.
 *
 * A deploy key is a public key, so returning it is the point of the tool; it is
 * not a credential. The private half never leaves the Cloudways side, which is
 * why `git_generate_key` creates a keypair rather than accepting one.
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

class GitKeyTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_git_key_get';
    }

    public function getName(): string
    {
        return 'Cloudways: Get Git Deploy Key';
    }

    public function getDescription(): string
    {
        return 'Returns an application\'s public Git deploy key and its type. Read-only.';
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
            $result = $this->client->get(
                '/app/' . $pair['server_id'] . '/' . $pair['app_id'] . '/git/key'
            );
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        if (! is_array($result['git_key'] ?? null)) {
            return $this->malformed('git_key');
        }

        $key = $result['git_key'];

        return $this->success(
            'Git deploy key retrieved.',
            array(
                'git_key' => array(
                    'key'  => $this->text($key['key'] ?? null),
                    'type' => $this->text($key['type'] ?? null),
                ),
            )
        );
    }
}
