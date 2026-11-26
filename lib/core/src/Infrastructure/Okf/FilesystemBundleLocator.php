<?php

/**
 * OKF bundles as directories under one base path.
 *
 * The layout is `{base}/{bundle}`, which is what the WordPress bundle manager
 * writes and what `FilesystemBundleRoot` expects. Bundle names are validated as a
 * single path segment and rejected rather than normalised, for the same reason the
 * Paper Store rejects them: silently rewriting a caller's name turns a traversal
 * attempt into a successful read somewhere else.
 *
 * @package Nvoos\Core
 * @since   2.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Infrastructure\Okf;

use InvalidArgumentException;
use Nvoos\Core\Domain\Contract\BundleRootInterface;
use Nvoos\Core\Domain\Contract\OkfBundleLocatorInterface;
use RuntimeException;

final class FilesystemBundleLocator implements OkfBundleLocatorInterface
{
    /** A bundle name: one path segment, no separators. */
    private const SEGMENT_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/';

    /**
     * @param string $base Absolute path to the directory holding every bundle.
     */
    public function __construct(private readonly string $base)
    {
        if ('' === trim($base)) {
            throw new InvalidArgumentException('An OKF bundle base path must not be empty.');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function rootFor(string $bundle, bool $create = false): ?BundleRootInterface
    {
        $name = trim($bundle);

        if (1 !== preg_match(self::SEGMENT_PATTERN, $name)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a usable bundle name.', $name));
        }

        $dir = $this->baseDir() . DIRECTORY_SEPARATOR . $name;

        if (! is_dir($dir)) {
            if (! $create) {
                return null;
            }

            if (! mkdir($dir, 0775, true) && ! is_dir($dir)) {
                throw new RuntimeException('Unable to create the OKF bundle directory.');
            }
        }

        return new FilesystemBundleRoot($dir);
    }

    /**
     * {@inheritDoc}
     */
    public function bundles(): array
    {
        if (! is_dir($this->base)) {
            return array();
        }

        $bundles = array();

        foreach ((array) scandir($this->base) as $entry) {
            if (! is_string($entry) || 1 !== preg_match(self::SEGMENT_PATTERN, $entry)) {
                continue;
            }

            if (is_dir($this->baseDir() . DIRECTORY_SEPARATOR . $entry)) {
                $bundles[] = $entry;
            }
        }

        sort($bundles);

        return $bundles;
    }

    /**
     * The base path without a trailing separator, whichever separator was used.
     */
    private function baseDir(): string
    {
        return rtrim(rtrim($this->base, '/'), DIRECTORY_SEPARATOR);
    }
}
