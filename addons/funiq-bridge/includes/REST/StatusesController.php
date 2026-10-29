<?php
/**
 * Statuses REST controller.
 *
 * Native mode: the built-in status taxonomy. Woo mode: the stock-status
 * pseudo-collection (instock / outofstock / onbackorder — deterministic
 * pseudo-IDs so product relationship payloads stay stable). Woo mode is
 * read-only for this collection: stock statuses are enumerations, not
 * terms.
 *
 * @package FuniqBridge\REST
 */

namespace FuniqBridge\REST;

use FuniqBridge\Schema;
use FuniqBridge\Sources\ProductSources;
use FuniqBridge\Sources\WooTaxonomy;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

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
	 * Get the taxonomy slug (native mode only).
	 *
	 * @return string
	 */
	protected function taxonomy(): string {
		return Schema::TAX_STATUS;
	}

	/**
	 * Whether the stock-status pseudo-collection is active.
	 *
	 * @return bool
	 */
	private function is_woo_statuses(): bool {
		return ProductSources::is_woo_active_mode();
	}

	/**
	 * GET collection — stock-status pseudo-docs in woo mode.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		if ( $this->is_woo_statuses() ) {
			$docs = WooTaxonomy::stock_status_docs();
			return rest_ensure_response(
				$this->paginatedResponse( $docs, count( $docs ), 1, count( $docs ) )
			);
		}
		return parent::get_items( $request );
	}

	/**
	 * GET single — pseudo-doc lookup in woo mode.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		if ( $this->is_woo_statuses() ) {
			foreach ( WooTaxonomy::stock_status_docs() as $doc ) {
				if ( $doc['id'] === (int) $request['id'] ) {
					return rest_ensure_response( $doc );
				}
			}
			return new WP_Error( 'funiq_not_found', 'Status not found.', array( 'status' => 404 ) );
		}
		return parent::get_item( $request );
	}

	/**
	 * POST — unsupported in woo mode (enumerations are not editable).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		if ( $this->is_woo_statuses() ) {
			return new WP_Error(
				'funiq_woo_readonly_statuses',
				'Stock statuses are managed by WooCommerce and cannot be created.',
				array( 'status' => 405 )
			);
		}
		return parent::create_item( $request );
	}

	/**
	 * PUT — unsupported in woo mode.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		if ( $this->is_woo_statuses() ) {
			return new WP_Error(
				'funiq_woo_readonly_statuses',
				'Stock statuses are managed by WooCommerce and cannot be updated.',
				array( 'status' => 405 )
			);
		}
		return parent::update_item( $request );
	}

	/**
	 * DELETE — unsupported in woo mode.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		if ( $this->is_woo_statuses() ) {
			return new WP_Error(
				'funiq_woo_readonly_statuses',
				'Stock statuses are managed by WooCommerce and cannot be deleted.',
				array( 'status' => 405 )
			);
		}
		return parent::delete_item( $request );
	}
}
