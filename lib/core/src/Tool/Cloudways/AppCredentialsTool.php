<?php

/**
 * The SSH/SFTP connection tuple for one application — `GET /app/{server}/{app}/credentials`.
 *
 * Port of `WP_MCP_AI_Tool_Cloudways_App_Credentials` (Proposal 164 section
 * 164.142).
 *
 * ## Only the tuple is projected
 *
 * The endpoint name suggests secrets, so the projection is the safeguard.
 * WordPress read `host`, `username` and `port` and dropped the rest of the
 * body; the port does the same. Whatever else the response carries — a
 * password, a passphrase — never reaches the envelope. It is the rule the SSH
 * key listing already follows: name what exists, not the key material.
 *
 * A missing or unusable port falls back to 22. WordPress defaulted only an
 * absent key and passed a malformed one through as `0`, which is not a port a
 * caller can connect to; the fallback covers both cases.
 *
 * ## A read, whatever WordPress called it
 *
 * The WordPress tool declared `write, state-changing, reversible` for a GET.
 * Those flags describe side effects, and a GET has none, so the annotation here
 * is read-only. The request itself is unchanged.
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

class AppCredentialsTool extends CloudwaysTool
{
    /** The port a caller needs when the API does not name a usable one. */
    private const DEFAULT_SSH_PORT = 22;

    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_app_credentials';
    }

    public function getName(): string
    {
        return 'Cloudways: App Credentials';
    }

    public function getDescription(): string
    {
        return 'Returns the SSH/SFTP host, username and port for an application. Key material and passwords are never included; the port defaults to 22.';
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
                '/app/' . $pair['server_id'] . '/' . $pair['app_id'] . '/credentials'
            );
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        $credentials = $result['credentials'] ?? null;

        if (! is_array($credentials)) {
            return $this->malformed('credentials');
        }

        $port = $this->number($credentials['port'] ?? null);

        return $this->success(
            sprintf('Credentials retrieved for app %d.', $pair['app_id']),
            array(
                'credentials' => array(
                    'host'     => $this->text($credentials['host'] ?? null),
                    'username' => $this->text($credentials['username'] ?? null),
                    'port'     => $port > 0 ? $port : self::DEFAULT_SSH_PORT,
                ),
            )
        );
    }
}
