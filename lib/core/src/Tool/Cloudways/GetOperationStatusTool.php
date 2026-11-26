<?php

/**
 * Poll an asynchronous operation — `GET /operation/{operation_id}`.
 *
 * Ported from `WP_MCP_AI_Tool_Cloudways_Get_Operation_Status`: same slug, same
 * string id, same four fields. One field is added, and the reason is the tool's
 * whole purpose.
 *
 * ### `is_completed` is the answer, and WordPress drops it
 *
 * The v2 operation payload carries `is_completed` as a tri-state integer:
 * `0` while running, `1` when finished, `-1` when failed. The WordPress tool
 * projects only `status` and `progress`, so a polling caller has to
 * string-match a human-readable status and has no way to see the failure flag.
 * This port adds `is_completed` as `?int`: the integer when Cloudways reports
 * it, `null` when it does not. Only an addition — every existing key keeps its
 * meaning and an existing consumer ignores the new one.
 *
 * ### `progress` is not a percentage of certainty
 *
 * The same payload only carries `progress` for operations that publish it.
 * Absent means *not reported*, which is why the port yields `0` exactly as
 * WordPress does, and why the description points a caller at `status` and
 * `is_completed` rather than at the number.
 *
 * ### The id is a string
 *
 * Cloudways operation ids are not integers (the API returns values such as
 * `flex-79648230`), so the argument is a trimmed string and the slug is never
 * cast to an int.
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

class GetOperationStatusTool extends CloudwaysTool
{
    public function __construct(ErrorFactoryInterface $e, CloudwaysClientInterface $client)
    {
        parent::__construct($e, $client);
    }

    public function getSlug(): string
    {
        return 'cloudways_get_operation_status';
    }

    public function getName(): string
    {
        return 'Cloudways: Get Operation Status';
    }

    public function getDescription(): string
    {
        return 'Returns the status of an asynchronous Cloudways operation: id, status, type, progress and is_completed (0 running, 1 finished, -1 failed, absent when the API does not report it). Read-only; poll this after a create, clone, restore or restart.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'operation_id' => array(
                    'type'        => 'string',
                    'description' => 'The operation id returned by the tool that started the work, e.g. flex-79648230.',
                    'minLength'   => 1,
                    'maxLength'   => 120,
                ),
            ),
            'required'             => array('operation_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $operation_id = trim($this->stringParam($arguments, 'operation_id'));

        if ('' === $operation_id) {
            return $this->errors->validationFailed(
                'A valid operation id is required.',
                array('operation_id' => array('The id returned when the operation started.'))
            );
        }

        try {
            $result = $this->client->get('/operation/' . rawurlencode($operation_id));
        } catch (CloudwaysApiException $e) {
            return $this->failure($e);
        }

        if (! is_array($result['operation'] ?? null)) {
            return $this->malformed('operation');
        }

        $operation = $result['operation'];

        $data = array(
            'id'           => $this->text($operation['id'] ?? $operation_id),
            'status'       => $this->text($operation['status'] ?? null),
            'type'         => $this->text($operation['type'] ?? null),
            'progress'     => $this->number($operation['progress'] ?? null),
            'is_completed' => array_key_exists('is_completed', $operation)
                ? $this->number($operation['is_completed'])
                : null,
        );

        return $this->success(
            'Operation status for ' . $operation_id . '.',
            array('operation' => $data)
        );
    }
}
