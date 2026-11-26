<?php

/**
 * The identity of the runtime an assistant is connected to.
 *
 * WordPress answers this from plugin constants and `rest_url()`. This runtime
 * has no equivalent globals, and the identity is a *deployment* fact — the
 * product version, the site name the operator configured, the URL the API is
 * served from — so it belongs behind a host contract rather than in a constant.
 *
 * ## Why not an `mcp_endpoint`
 *
 * The WordPress payload reports `rest_url( 'mcp-ai/v1/mcp' )` because it serves
 * an MCP endpoint. This runtime serves its tool surface over REST and has no
 * MCP JSON-RPC mount, so the contract carries `api_base` and `contract`
 * instead: where the API lives, and which contract it speaks
 * (`mcp-ai/v1`, the same value the diagnostics payload's `rest_context`
 * declares). An agent with several connections can compare the pair; a
 * fabricated endpoint would send it to a 404.
 *
 * ## Rules
 *
 * - **No secrets.** Every field is a public identifier: a product name, a
 *   version, a site URL, a contract slug. Credentials, tokens, emails and user
 *   data are not identity and never belong here.
 * - **No invented values.** A host that does not know its version reports an
 *   empty string, not a plausible number. Four existing readers already fall
 *   back to `1.2.0` when `app.version` is unset; this contract does not repeat
 *   that guess — the host implementation decides.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface RuntimeIdentityInterface
{
    /**
     * The deployment's public identity.
     *
     * `name` is the product, `site_name` is what this installation calls
     * itself; `runtime` is the framework family (`laravel`, `wordpress`);
     * `contract` is the API contract version the tool surface speaks.
     *
     * @return array{
     *     name: string,
     *     version: string,
     *     runtime: string,
     *     contract: string,
     *     site_name: string,
     *     site_url: string,
     *     api_base: string
     * }
     */
    public function identity(): array;
}
