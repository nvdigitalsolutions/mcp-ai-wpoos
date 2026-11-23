<?php

/**
 * Tool: mcp_server_info — which runtime is this, and which contract does it speak?
 *
 * The WordPress tool reports plugin version, distribution mode, site name and
 * URL, and its MCP endpoint. An agent with several connections uses it to tell
 * one backend from another before it acts. This runtime had the diagnostics page
 * and no way for an agent to ask the same question.
 *
 * ## What is reported, and what is not
 *
 * Identity is a deployment fact, so `RuntimeIdentityInterface` carries it and
 * the app supplies the values. Two WordPress fields are deliberately absent:
 *
 *  - **`mode`** (`base` vs `complete`) is a WordPress distribution concept with
 *    no equivalent here.
 *  - **`mcp_endpoint`** names a JSON-RPC mount this runtime does not serve.
 *    `api_base` and `contract` answer the same question — where the API lives
 *    and what it speaks — without pointing a caller at a route that 404s.
 *
 * ## No secrets
 *
 * Every field is a public identifier. The tool performs no redaction pass
 * because the contract forbids secrets by construction, and a redaction pass
 * here would imply the host might have supplied one.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\RuntimeIdentityInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;

final class McpServerInfoTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * An unbound identity service means "this host does not expose one", which
     * the registrar's optional-collaborator rule expresses as a null default.
     */
    public function __construct(
        ErrorFactoryInterface $errors,
        private readonly ?RuntimeIdentityInterface $identity = null,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string
    {
        return 'mcp_server_info';
    }

    public function getName(): string
    {
        return 'MCP Server Info';
    }

    public function getDescription(): string
    {
        return 'Reports the identity of the runtime this assistant is connected to: product name, version, runtime family, the API contract it speaks, the deployment\'s name and URL, and the API base the tool surface is served from. Purely informational — never includes secrets, tokens, emails or user data.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'read';
    }

    public function getCapabilityFlags(): array
    {
        return array( 'read-only', 'local-only', 'cacheable', 'requires-capability' );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        if (null === $this->identity) {
            return $this->success(
                'This host does not expose a runtime identity.',
                array( 'server' => array() )
            );
        }

        $identity = $this->identity->identity();

        // Cast every field: the contract fixes the shape, but a host that
        // returned a null would otherwise make the JSON payload inconsistent
        // with the schema a caller was promised.
        $server = array(
            'name'      => (string) ( $identity['name'] ?? '' ),
            'version'   => (string) ( $identity['version'] ?? '' ),
            'runtime'   => (string) ( $identity['runtime'] ?? '' ),
            'contract'  => (string) ( $identity['contract'] ?? '' ),
            'site_name' => (string) ( $identity['site_name'] ?? '' ),
            'site_url'  => (string) ( $identity['site_url'] ?? '' ),
            'api_base'  => (string) ( $identity['api_base'] ?? '' ),
        );

        $name = '' === $server['name'] ? 'unknown runtime' : $server['name'];

        return $this->success(
            \sprintf('MCP server identity: %s.', $name),
            array( 'server' => $server )
        );
    }
}
