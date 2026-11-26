<?php

/**
 * OKF bundle locator — the seam between a bundle *name* and a bundle *root*.
 *
 * `OkfReader` and `OkfWriter` both take a `BundleRootInterface`, which is a
 * directory. Nothing in the engine maps the string a caller says — `site-content`
 * — onto that directory, because on WordPress the mapping belongs to
 * `WP_MCP_AI_OKF_Bundle_Manager` and a protected-bundle list.
 *
 * This contract is that mapping and nothing else. It exists so the tools do not
 * each re-implement "a bundle is a folder under some configured base", and so a
 * host that keeps bundles somewhere other than a filesystem can serve the same
 * tools.
 *
 * ## Creating is explicit
 *
 * `rootFor()` creates a bundle only when the caller says so. A read path that
 * silently created an empty bundle would answer "no matches" for a typo — the
 * failure mode the Paper Store export avoids — so a missing bundle is `null` on
 * the read path and a directory on the write path.
 *
 * @package Nvoos\Core
 * @since   2.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface OkfBundleLocatorInterface
{
    /**
     * Resolve a bundle name to its root.
     *
     * @param string $bundle Bundle name, e.g. `site-content`.
     * @param bool   $create Create the bundle directory when it is absent.
     *
     * @return BundleRootInterface|null Null when the bundle does not exist and
     *                                  `$create` is false, or the host has no store.
     *
     * @throws \InvalidArgumentException When the name is not a usable bundle name.
     */
    public function rootFor(string $bundle, bool $create = false): ?BundleRootInterface;

    /**
     * Bundles that exist, sorted.
     *
     * @return list<string>
     */
    public function bundles(): array;
}
