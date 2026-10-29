<?php
/**
 * Statuses REST controller.
 *
 * @package FuniqBridge\REST
 */

namespace FuniqBridge\REST;

use FuniqBridge\Schema;

/**
 * Handles /funiq/v1/statuses.
 */
class StatusesController extends TermController {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'funiq/v1';

	/**
	 * REST base path.
	 *
	 * @var string
	 */
	protected $rest_base = 'statuses';

	/**
	 * Get the taxonomy slug.
	 *
	 * @return string
	 */
	protected function taxonomy(): string {
		return Schema::TAX_STATUS;
	}
}
