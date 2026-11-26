<?php

/**
 * Restart one service on a server — `POST /service/{server_id}/restart`.
 *
 * Ported from `WP_MCP_AI_Tool_Cloudways_Restart_Service`: same slug, same five
 * service names, same body. Three things are said here that the WordPress
 * description does not:
 *
 * ### The five names are a closed list
 *
 * `nginx`, `mysql`, `php-fpm`, `varnish`, `redis`. The API accepts other
 * service names on some stacks, and the WordPress enum refuses them; the enum
 * is kept because a caller that needs `apache2` is on a stack this toolkit
 * does not manage, and silently forwarding an unlisted name would turn a
 * capability question into a 500. The refusal names all five. The name is
 * lowercased before the check, so `MySQL` restarts mysql; WordPress compares the
 * raw value and refuses the capitalised form, which is a spelling test rather
 * than a capability one.
 *
 * ### A restart is a blip, not a rollback
 *
 * WordPress flags this tool `reversible`. It is reversible in the sense that
 * the server keeps existing; it is not reversible for the requests that were
 * in flight while nginx was down. The description says so, because an agent
 * that restarts mysql to "fix" a slow query has dropped every open connection.
 *
 * ### The API's own answer is the payload
 *
 * As in WordPress, the decoded response travels as `data` unchanged. A restart
 * is asynchronous and the body naming the operation is the only handle a
 * caller has to poll with `cloudways_get_operation_status`; projecting it into
 * a fixed shape would risk dropping that id if Cloudways renames a key.
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

class RestartServiceTool extends CloudwaysTool
{
    public const SERVICES = array('nginx', 'mysql', 'php-fpm', 'varnish', 'redis');

    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_restart_service';
    }

    public function getName(): string
    {
        return 'Cloudways: Restart Service';
    }

    public function getDescription(): string
    {
        return 'Restarts one service on a Cloudways server: nginx, mysql, php-fpm, varnish or redis. The restart is asynchronous and briefly interrupts in-flight requests; the API response, including any operation id, is returned unchanged.';
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
                'service'   => array(
                    'type'        => 'string',
                    'enum'        => self::SERVICES,
                    'description' => 'The service to restart.',
                ),
            ),
            'required'             => array('server_id', 'service'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $server_id = $this->serverId($arguments);
        $service   = strtolower(trim($this->stringParam($arguments, 'service')));

        if ($server_id <= 0) {
            return $this->errors->validationFailed(
                'A valid server id is required.',
                array('server_id' => array('The id shown by cloudways_list_servers.'))
            );
        }

        if (! in_array($service, self::SERVICES, true)) {
            return $this->errors->validationFailed(
                'A valid service name is required.',
                array(
                    'service' => array(
                        'Use one of ' . implode(', ', self::SERVICES) . '.',
                    ),
                )
            );
        }

        try {
            $result = $this->client->post(
                '/service/' . $server_id . '/restart',
                array('service' => $service)
            );
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        return $this->success(
            sprintf('Service %s restarted on server %d.', $service, $server_id),
            $result
        );
    }
}
