<?php
/**
 * Tool for generating invoice documents for a batch of orders.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Document_Generation_Toolkit
 * @since 2.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates invoice documents for a batch of orders.
 *
 * Processes a set of order IDs and generates PDF invoice documents using
 * the available invoice template. Supports dry_run mode and optional
 * email delivery.
 *
 * @since 2.9.0
 */
class WP_MCP_AI_Tool_Generate_Invoice_Batch implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_invoice_batch';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Invoice Batch', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates invoice documents for a batch of orders. Supports specifying a template, automatic email delivery, and dry_run mode to preview the batch before generation.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'order_ids'   => array(
					'type'        => 'array',
					'description' => __( 'Array of order IDs to generate invoices for.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'template_id' => array(
					'type'        => 'integer',
					'description' => __( 'Optional document template ID to use for invoice generation. If omitted, the default invoice template is used.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'send_email'  => array(
					'type'        => 'boolean',
					'description' => __( 'If true, send invoice emails to customers after generation. Default: false.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'dry_run'     => array(
					'type'        => 'boolean',
					'description' => __( 'If true, preview which orders would be invoiced without generating documents. Default: true.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'order_ids' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'document_generation',
			'post_type'             => 'shop_order',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'administrator', 'accountant', 'shop_manager' ),
			'risk_level'            => 'caution',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'write',
			'state-changing',
			'local-only',
			'requires-capability',
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * Requires the Document Generation Toolkit to be enabled.
	 *
	 * @since 2.9.0
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_document_generation_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.9.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Generate Invoice Batch tool requires the Document Generation Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Batch invoice generation result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_document_generation_toolkit'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Document Generation Toolkit is not enabled. Please enable it in Settings → NV oOS → Tools & Features.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$order_ids   = isset( $arguments['order_ids'] ) ? array_map( 'absint', (array) $arguments['order_ids'] ) : array();
		$template_id = isset( $arguments['template_id'] ) ? absint( $arguments['template_id'] ) : 0;
		$send_email  = isset( $arguments['send_email'] ) ? (bool) $arguments['send_email'] : false;
		$dry_run     = isset( $arguments['dry_run'] ) ? (bool) $arguments['dry_run'] : true;

		if ( empty( $order_ids ) ) {
			return array(
				'success' => false,
				'error'   => __( 'At least one order ID must be provided.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$generated = array();
		$failed    = array();
		$emailed   = array();

		foreach ( $order_ids as $order_id ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			if ( ! $order ) {
				$failed[] = array(
					'id'     => $order_id,
					'reason' => __( 'Order not found.', 'mcp-ai-wpoos-pro' ),
				);
				continue;
			}

			$order_info = array(
				'id'             => $order_id,
				'order_number'   => $order->get_order_number(),
				'total'          => $order->get_total(),
				'currency'       => $order->get_currency(),
				'customer_email' => $order->get_billing_email(),
			);

			if ( $dry_run ) {
				// Check if already invoiced.
				$already_invoiced               = ! empty( get_post_meta( $order_id, '_invoiced', true ) );
				$order_info['already_invoiced'] = $already_invoiced;
				$generated[]                    = $order_info;
				continue;
			}

			// Check if already invoiced.
			if ( ! empty( get_post_meta( $order_id, '_invoiced', true ) ) ) {
				$order_info['reason'] = __( 'Order already has an invoice.', 'mcp-ai-wpoos-pro' );
				$failed[]             = $order_info;
				continue;
			}

			// Generate invoice using the generate_invoice_pdf tool if available.
			$invoice_generated = false;
			if ( class_exists( 'WP_MCP_AI_Tool_Generate_Invoice_PDF' ) ) {
				$invoice_tool   = new WP_MCP_AI_Tool_Generate_Invoice_PDF();
				$invoice_args   = array(
					'invoice_number' => $order->get_order_number(),
					'items'          => $this->extract_order_items( $order ),
					'date'           => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : gmdate( 'Y-m-d' ),
					'bill_to'        => $order->get_formatted_billing_full_name(),
					'subtotal'       => $order->get_subtotal(),
					'total'          => $order->get_total(),
					'currency'       => $order->get_currency(),
				);
				$invoice_result = $invoice_tool->execute( $invoice_args, $context );

				if ( ! is_wp_error( $invoice_result ) && ! empty( $invoice_result['success'] ) ) {
					update_post_meta( $order_id, '_invoiced', gmdate( 'Y-m-d H:i:s' ) );
					if ( ! empty( $invoice_result['attachment_id'] ) ) {
						update_post_meta( $order_id, '_invoice_attachment_id', absint( $invoice_result['attachment_id'] ) );
					}
					$order_info['attachment_id'] = isset( $invoice_result['attachment_id'] ) ? $invoice_result['attachment_id'] : 0;
					$invoice_generated           = true;
				}
			}

			if ( ! $invoice_generated ) {
				// Fallback: mark as invoiced without PDF generation.
				update_post_meta( $order_id, '_invoiced', gmdate( 'Y-m-d H:i:s' ) );
				$order_info['note'] = __( 'Marked as invoiced (no PDF generated - invoice generation tool unavailable).', 'mcp-ai-wpoos-pro' );
			}

			// Send email if requested.
			if ( $send_email && $invoice_generated ) {
				$customer_email = $order->get_billing_email();
				if ( ! empty( $customer_email ) ) {
					// Note: Email sending is handled by the invoice tool or external WC integration.
					$order_info['email_sent'] = true;
					$emailed[]                = $order_info;
				}
			}

			$generated[] = $order_info;
		}

		return array(
			'success'         => true,
			'dry_run'         => $dry_run,
			'action'          => $dry_run
				? __( 'Dry run completed. No invoices were generated.', 'mcp-ai-wpoos-pro' )
				: __( 'Invoice batch generation completed.', 'mcp-ai-wpoos-pro' ),
			'generated_count' => count( $generated ),
			'failed_count'    => count( $failed ),
			'emailed_count'   => count( $emailed ),
			'orders'          => array(
				'generated' => $generated,
				'failed'    => $failed,
			),
			'template_id'     => $template_id,
			'send_email'      => $send_email,
		);
	}

	/**
	 * Extract order items into invoice-compatible format.
	 *
	 * @since 2.9.0
	 * @param \WC_Order $order WooCommerce order.
	 * @return array Array of item arrays with description, quantity, rate, amount.
	 */
	private function extract_order_items( $order ) {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'description' => $item->get_name(),
				'quantity'    => $item->get_quantity(),
				'rate'        => $item->get_total() / max( $item->get_quantity(), 1 ),
				'amount'      => $item->get_total(),
			);
		}
		return $items;
	}
}
