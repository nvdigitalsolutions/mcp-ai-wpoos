<?php

namespace Nvoos\Laravel\Adapter;

/**
 * Whether a worker-answered filename is safe to turn into a URL.
 *
 * A worker writes its output to its own disk and answers a *name*; the host
 * then builds `/api/video/download/<name>` from it. That name is not
 * attacker-controlled in the usual sense — but a compromised worker, or a
 * future route that echoed a caller-supplied value, must not be able to make
 * this host fetch `../../etc/passwd`.
 *
 * A charset check alone is not enough: `..` is made entirely of allowed
 * characters, so `../../etc/passwd` passes a regex and the built URL climbs out
 * of the download prefix when the client normalises it. Each segment is
 * therefore validated by name, and dot segments — which have no legitimate use
 * in an output filename — are refused outright.
 *
 * The route itself resolves the name and checks containment, so it is safe on
 * its side. This is the host's own check, and the two are not interchangeable:
 * one resolves a path, the other does not.
 *
 * @package Nvoos\Laravel
 * @since   1.2.0
 * @license MIT
 */
final class MediaWorkerRemoteName
{
    /**
     * @param string $name The `output_file` the worker answered.
     */
    public static function isSafe(string $name): bool
    {
        if ('' === $name || \strlen($name) > 255) {
            return false;
        }

        foreach (\explode('/', $name) as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                return false;
            }

            if (1 !== \preg_match('/^[A-Za-z0-9._-]+$/', $segment)) {
                return false;
            }
        }

        return true;
    }
}
