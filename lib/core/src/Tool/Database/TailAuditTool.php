<?php

/**
 * `db_audit_tail` — the audit trail.
 *
 * Proposal 167 phase P3 task 3.4 and P4. WordPress parity: same slug, same
 * arguments, same `records` / `count` / `summary` / `retained` payload.
 *
 * Administrator-only, because the trail is cross-assistant: it shows which
 * principal asked which connection for what, and a refusal pattern is itself
 * sensitive — it reveals which relations exist and which ones a colleague tried
 * to reach.
 *
 * Records never carry bound values, so this tool is safe to show in full; the
 * capability gate is about estate visibility, not row contents.
 *
 * The port is not queried here. It is a constructor argument because the audit
 * trail only exists when the toolkit does — requiring it makes "is this tool
 * buildable?" the same question as "is the toolkit enabled?", which is what the
 * readiness manifest asks.
 *
 * @package Nvoos\Core
 * @since   2.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Database;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Database\Domain\Contract\Database\AuditReaderInterface;
use Nvoos\Database\Domain\Contract\DatabaseQueryInterface;

final class TailAuditTool extends DatabaseTool
{
    public function __construct(
        ErrorFactoryInterface $errors,
        DatabaseQueryInterface $databases,
        private readonly AuditReaderInterface $audit,
    ) {
        parent::__construct($errors, $databases);
    }

    public function getSlug(): string
    {
        return 'db_audit_tail';
    }

    public function getName(): string
    {
        return 'Tail Database Audit Log';
    }

    public function getDescription(): string
    {
        return 'Return the most recent database audit records, newest first, with optional filters. Records contain the statement template and a fingerprint, never bound values. Administrator only.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'limit'        => array(
                    'type'    => 'integer',
                    'minimum' => 1,
                    'maximum' => 200,
                    'default' => 50,
                ),
                'connection'   => array('type' => 'string'),
                'scope'        => array('type' => 'string'),
                'action'       => array(
                    'type' => 'string',
                    'enum' => array('describe', 'select', 'aggregate', 'search', 'raw_sql', 'explain', 'explain_analyze', 'export'),
                ),
                'refusal_code' => array(
                    'type'        => 'string',
                    'description' => 'A refusal code, e.g. db_scope_table_not_allowed.',
                ),
            ),
            'required'             => array(),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'manage_options';
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        unset($context);

        return $this->guard(function () use ($arguments): array {
            $filters = array();

            foreach (array('connection', 'scope', 'action', 'refusal_code') as $key) {
                if (isset($arguments[$key]) && '' !== trim((string) $arguments[$key])) {
                    $filters[$key] = trim((string) $arguments[$key]);
                }
            }

            $limit   = isset($arguments['limit']) ? min(200, max(1, (int) $arguments['limit'])) : 50;
            $records = $this->audit->tail($limit, $filters);

            return array(
                'success'  => true,
                'records'  => $records,
                'count'    => count($records),
                'summary'  => $this->audit->summary(3600),
                'retained' => $this->audit->retained(),
            );
        });
    }
}
