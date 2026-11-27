<?php

/**
 * Shared ground for the three iCloud Drive tools.
 *
 * Proposal 164 section 164.108. Apple exposes no public third-party REST API
 * for a user's iCloud Drive: CloudKit is available to app developers, not as a
 * drive API a server can call with a bearer token. Reaching iCloud Drive from
 * this runtime therefore requires a bridge the host runs, which is why every
 * tool in this family takes `gateway_url`.
 *
 * The gateway contract is the host's, so the tools constrain only what they can
 * know: the endpoint is a public https URL, the token is present, the ids are
 * printable and bounded, and the response is decoded rather than projected to a
 * guessed field set (see `GatewayTool`).
 *
 * ## Corrections shared by the three tools
 *
 * 1. **The endpoint must be a public https URL.** WordPress checked
 *    `FILTER_VALIDATE_URL` and an `https://` prefix, which accepts a loopback
 *    host and a URL carrying userinfo — with the token attached.
 * 2. **Cursors and ids are bounded.** WordPress put unbounded
 *    `sanitize_text_field()` output into the query string.
 * 3. **The endpoint is never echoed.**
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

abstract class ICloudTool extends GatewayTool
{
    /**
     * The list page size ceiling.
     */
    protected const MAX_LIST_LIMIT = 100;

    /**
     * The list page size used when none is given.
     */
    protected const DEFAULT_LIST_LIMIT = 25;

    /**
     * The longest cursor or identifier the tools forward.
     */
    protected const MAX_REF_LENGTH = 512;

    /**
     * The gateway endpoint, or the canonical refusal.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return string|mixed
     */
    protected function gateway(array $arguments): mixed
    {
        return $this->gatewayUrl($arguments, 'gateway_url', 'iCloud gateway URL');
    }

    /**
     * The gateway token, or the canonical refusal.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return string|mixed
     */
    protected function key(array $arguments): mixed
    {
        return $this->gatewayKey($arguments);
    }

    /**
     * A bounded identifier or cursor, or ''.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     * @param string               $key       Argument name.
     */
    protected function ref(array $arguments, string $key): string
    {
        $value = is_scalar($arguments[$key] ?? null) ? trim((string) $arguments[$key]) : '';

        if ('' === $value || self::MAX_REF_LENGTH < strlen($value)) {
            return '';
        }

        return 1 === preg_match('/^[^\x00-\x1F\x7F]+$/', $value) ? $value : '';
    }
}
