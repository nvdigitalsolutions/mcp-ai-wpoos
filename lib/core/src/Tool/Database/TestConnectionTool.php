<?php

/**
 * `db_test_connection` — probe and report a connection's privileges.
 *
 * Proposal 167 amendment A1 and phase P4. WordPress parity: same slug, same
 * one-argument schema, same `connection` / `kind` / `probe` / `persisted` /
 * `guidance` payload and the same four guidance strings.
 *
 * This is an operator tool, not an assistant capability: it requires
 * `manage_options`, because it discloses the role a credential authenticates as,
 * whether that role can write, and whether it bypasses row-level security. Those
 * three facts are the difference between a read-only grant and an illusion.
 *
 * The probe itself is the kernel's `PdoProbe` through
 * {@see ConnectionProbeInterface}; a host without one reports that plainly rather
 * than inferring privileges from a successful `SELECT`.
 *
 * `persisted` is always false here: the Laravel host declares connections in
 * configuration, so there is no record to update. The key is kept so a client can
 * read both hosts with one code path.
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
use Nvoos\Database\Domain\Contract\Database\ConnectionProbeInterface;
use Nvoos\Database\Domain\Contract\DatabaseQueryInterface;
use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\RefusalCode;
use Nvoos\Database\Domain\ValueObject\Database\ProbeResult;

final class TestConnectionTool extends DatabaseTool
{
    public function __construct(
        ErrorFactoryInterface $errors,
        DatabaseQueryInterface $databases,
        private readonly ConnectionProbeInterface $probe,
    ) {
        parent::__construct($errors, $databases);
    }

    public function getSlug(): string
    {
        return 'db_test_connection';
    }

    public function getName(): string
    {
        return 'Test Database Connection';
    }

    public function getDescription(): string
    {
        return 'Open a configured database connection, report its health, and report the role it authenticates as, whether that role can write, and whether it bypasses row-level security. Administrator only.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'connection' => array(
                    'type'        => 'string',
                    'description' => 'Connection id. The id from db_list_connections, or a Remote Sites id prefixed "rs:".',
                ),
            ),
            'required'             => array('connection'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'manage_options';
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        return $this->guard(function () use ($arguments, $context): array {
            $id = isset($arguments['connection']) ? strtolower(trim((string) $arguments['connection'])) : '';

            if ('' === $id) {
                throw DatabaseRefusal::of(RefusalCode::UNKNOWN_CONNECTION, 'A connection id is required.');
            }

            $descriptor = $this->descriptor($id, $context);
            $result     = $this->probe->probe($descriptor);

            return array(
                'success'    => true,
                'connection' => $descriptor->id,
                'kind'       => $descriptor->kind,
                'probe'      => $result->toArray(),
                'persisted'  => false,
                'guidance'   => $this->guidance($result),
            );
        });
    }

    /**
     * Operator-facing guidance from the probe result.
     *
     * The wording is the WordPress wording, deliberately: two hosts giving
     * different advice for the same probe would be a worse defect than a shared
     * translation catalogue.
     *
     * @return list<string>
     */
    private function guidance(ProbeResult $probe): array
    {
        $notes = array();

        if (! $probe->reachable) {
            $notes[] = 'The connection could not be opened. Check the host, port, database name, credential and network access.';
        }

        if ($probe->canWrite) {
            $notes[] = 'This role can write. Read-only scopes will refuse this connection until a SELECT-only role is used.';
        }

        if ($probe->bypassesRls) {
            $notes[] = 'This role bypasses row-level security. Tenant-filtered scopes will refuse this connection.';
        }

        if (array() === $notes) {
            $notes[] = 'The connection is reachable and safe for read-only scopes.';
        }

        return $notes;
    }
}
