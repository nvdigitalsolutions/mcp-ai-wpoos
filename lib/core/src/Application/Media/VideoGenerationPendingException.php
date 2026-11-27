<?php

/**
 * Thrown by a polling handler to ask the queue for another attempt.
 *
 * It is a control signal, not an error: the render is still running. The
 * dispatch that enqueued the job sets the attempt budget and the cadence, so a
 * poll every fifteen seconds for twenty minutes is configuration rather than a
 * `sleep()` inside a request.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Media;

class VideoGenerationPendingException extends \RuntimeException
{
}
