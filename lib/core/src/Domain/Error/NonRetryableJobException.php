<?php

/**
 * Thrown when a job failed in a way that retrying cannot fix.
 *
 * The generic queue job retries any exception up to its attempt budget, which is
 * what a flaky network or a still-running render needs. A terminal provider
 * failure is the opposite: the render is over and asking again repeats a paid
 * operation that already ended. This marker tells the job to fail immediately
 * and record the reason.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Error;

class NonRetryableJobException extends \RuntimeException
{
}
