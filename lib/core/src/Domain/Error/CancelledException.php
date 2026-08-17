<?php
/**
 * CancelledException — thrown when cooperative cancellation is observed.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Error;

final class CancelledException extends \RuntimeException {

	/**
	 * @param string $reason Cancellation reason (see CancellationToken::reason()).
	 */
	public function __construct( string $reason = 'cancelled' ) {
		parent::__construct(
			'' === $reason ? 'Operation cancelled.' : "Operation cancelled: {$reason}",
			0,
		);
	}
}
