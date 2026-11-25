<?php

namespace Nvoos\Laravel\Adapter;

/**
 * The worker refused the request itself (HTTP 400 or 422).
 *
 * This is deliberately a {@see \RuntimeException} — the contract every media
 * adapter has always thrown, with the same `Media worker error: …` message, so
 * existing callers and their tests are unaffected.
 *
 * The subclass exists so a caller that *can* tell the difference may: a refused
 * request is the caller's to fix (mismatched merge inputs, an option the route
 * does not take), while an auth failure or a 5xx is the engine's. The
 * composition contract reports the former as `InvalidArgumentException`; every
 * other adapter keeps treating both as a runtime failure.
 *
 * @package Nvoos\Laravel
 * @since   1.2.0
 * @license MIT
 */
final class MediaWorkerRefusedException extends \RuntimeException
{
}
