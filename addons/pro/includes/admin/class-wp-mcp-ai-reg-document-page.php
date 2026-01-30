<?php
/**
 * Document Management Page for Regulatory Registration Toolkit.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Document Management Page class.
 */
class WP_MCP_AI_Reg_Document_Page {
	/**
	 * Initialize the class.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 23 );
	}

	/**
	 * Add menu page.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_reg_product',
			__( 'Document Management', 'mcp-ai-wpoos-pro' ),
			__( 'Documents', 'mcp-ai-wpoos-pro' ),
			'edit_posts',
			'wp-mcp-ai-reg-documents',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the document page.
	 */
	public static function render_page() {
		// Get document statistics.
		$stats = self::get_document_stats();
		?>
		<div class="wrap wp-mcp-ai-document-page">
			<h1><?php echo esc_html__( 'Document Management', 'mcp-ai-wpoos-pro' ); ?></h1>
			<p><?php echo esc_html__( 'Manage documents, track expiry dates, and monitor compliance.', 'mcp-ai-wpoos-pro' ); ?></p>

			<!-- Document Statistics -->
			<div class="document-stats">
				<div class="stat-card">
					<div class="stat-icon dashicons dashicons-media-document"></div>
					<div class="stat-content">
						<div class="stat-number"><?php echo esc_html( $stats['total_documents'] ); ?></div>
						<div class="stat-label"><?php esc_html_e( 'Total Documents', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
				</div>

				<div class="stat-card status-warning">
					<div class="stat-icon dashicons dashicons-warning"></div>
					<div class="stat-content">
						<div class="stat-number"><?php echo esc_html( $stats['expiring_soon'] ); ?></div>
						<div class="stat-label"><?php esc_html_e( 'Expiring Soon', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
				</div>

				<div class="stat-card status-expired">
					<div class="stat-icon dashicons dashicons-dismiss"></div>
					<div class="stat-content">
						<div class="stat-number"><?php echo esc_html( $stats['expired'] ); ?></div>
						<div class="stat-label"><?php esc_html_e( 'Expired', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
				</div>

				<div class="stat-card status-valid">
					<div class="stat-icon dashicons dashicons-yes-alt"></div>
					<div class="stat-content">
						<div class="stat-number"><?php echo esc_html( $stats['valid'] ); ?></div>
						<div class="stat-label"><?php esc_html_e( 'Valid', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
				</div>
			</div>

			<!-- Expiring Documents Alert -->
			<?php if ( $stats['expiring_soon'] > 0 || $stats['expired'] > 0 ) : ?>
				<div class="notice notice-warning">
					<p>
						<strong><?php esc_html_e( 'Action Required:', 'mcp-ai-wpoos-pro' ); ?></strong>
						<?php
						if ( $stats['expired'] > 0 ) {
							echo esc_html(
								sprintf(
									/* translators: %d: number of expired documents */
									_n(
										'You have %d expired document that needs renewal.',
										'You have %d expired documents that need renewal.',
										$stats['expired'],
										'mcp-ai-wpoos-pro'
									),
									$stats['expired']
								)
							);
						}
						if ( $stats['expiring_soon'] > 0 && $stats['expired'] > 0 ) {
							echo ' ';
						}
						if ( $stats['expiring_soon'] > 0 ) {
							echo esc_html(
								sprintf(
									/* translators: %d: number of expiring documents */
									_n(
										'%d document is expiring within 30 days.',
										'%d documents are expiring within 30 days.',
										$stats['expiring_soon'],
										'mcp-ai-wpoos-pro'
									),
									$stats['expiring_soon']
								)
							);
						}
						?>
					</p>
				</div>
			<?php endif; ?>

			<!-- Expiring Documents List -->
			<?php if ( $stats['expiring_soon'] > 0 || $stats['expired'] > 0 ) : ?>
				<div class="document-section">
					<h2><?php esc_html_e( 'Documents Requiring Attention', 'mcp-ai-wpoos-pro' ); ?></h2>
					<?php self::render_expiring_documents(); ?>
				</div>
			<?php endif; ?>

			<!-- Document Types Breakdown -->
			<div class="document-section">
				<h2><?php esc_html_e( 'Documents by Type', 'mcp-ai-wpoos-pro' ); ?></h2>
				<?php self::render_documents_by_type(); ?>
			</div>

			<!-- Recent Documents -->
			<div class="document-section">
				<h2><?php esc_html_e( 'Recently Added Documents', 'mcp-ai-wpoos-pro' ); ?></h2>
				<?php self::render_recent_documents(); ?>
			</div>

			<!-- Quick Actions -->
			<div class="document-section">
				<h2><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h2>
				<div class="quick-actions">
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_reg_document' ) ); ?>" class="button button-primary">
						<span class="dashicons dashicons-plus-alt"></span>
						<?php esc_html_e( 'Upload New Document', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_reg_document' ) ); ?>" class="button">
						<span class="dashicons dashicons-list-view"></span>
						<?php esc_html_e( 'View All Documents', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=mcp_ai_doc_type&post_type=mcp_ai_reg_document' ) ); ?>" class="button">
						<span class="dashicons dashicons-category"></span>
						<?php esc_html_e( 'Manage Document Types', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</div>
			</div>

			<!-- Document Management Tips -->
			<div class="document-section document-tips">
				<h2><?php esc_html_e( 'Document Management Best Practices', 'mcp-ai-wpoos-pro' ); ?></h2>
				<ul>
					<li><?php esc_html_e( 'Always set expiry dates for time-sensitive documents like certificates and licenses', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Use consistent naming conventions for easy identification', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Link documents to their corresponding products and registrations', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Keep digital copies of all physical documents for backup', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Set up renewal reminders at least 60 days before expiry', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Organize documents by type using the taxonomy system', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>
		</div>

		<style>
			.wp-mcp-ai-document-page .document-stats {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
				gap: 20px;
				margin: 30px 0;
			}
			.wp-mcp-ai-document-page .stat-card {
				background: #fff;
				border: 1px solid #ccd0d4;
				border-radius: 4px;
				padding: 20px;
				display: flex;
				align-items: center;
				gap: 15px;
			}
			.wp-mcp-ai-document-page .stat-icon {
				font-size: 40px;
				width: 40px;
				height: 40px;
				color: #2271b1;
			}
			.wp-mcp-ai-document-page .stat-number {
				font-size: 32px;
				font-weight: bold;
				color: #1d2327;
			}
			.wp-mcp-ai-document-page .stat-label {
				font-size: 14px;
				color: #646970;
			}
			.wp-mcp-ai-document-page .stat-card.status-warning .stat-icon {
				color: #dba617;
			}
			.wp-mcp-ai-document-page .stat-card.status-expired .stat-icon {
				color: #d63638;
			}
			.wp-mcp-ai-document-page .stat-card.status-valid .stat-icon {
				color: #00a32a;
			}
			.wp-mcp-ai-document-page .document-section {
				background: #fff;
				border: 1px solid #ccd0d4;
				border-radius: 4px;
				padding: 20px;
				margin: 20px 0;
			}
			.wp-mcp-ai-document-page .document-section h2 {
				margin-top: 0;
			}
			.wp-mcp-ai-document-page .quick-actions {
				display: flex;
				gap: 10px;
				flex-wrap: wrap;
			}
			.wp-mcp-ai-document-page .quick-actions .button .dashicons {
				vertical-align: middle;
				margin-right: 5px;
			}
			.wp-mcp-ai-document-page .document-tips ul {
				list-style: disc;
				margin-left: 30px;
			}
			.wp-mcp-ai-document-page .document-tips ul li {
				margin: 10px 0;
			}
			.wp-mcp-ai-document-page .document-list {
				list-style: none;
				margin: 0;
				padding: 0;
			}
			.wp-mcp-ai-document-page .document-item {
				padding: 12px;
				border-bottom: 1px solid #f0f0f1;
				display: flex;
				justify-content: space-between;
				align-items: center;
			}
			.wp-mcp-ai-document-page .document-item:last-child {
				border-bottom: none;
			}
			.wp-mcp-ai-document-page .document-item.expired {
				background: #fcf0f1;
			}
			.wp-mcp-ai-document-page .document-item.expiring {
				background: #fcf9e8;
			}
			.wp-mcp-ai-document-page .document-title {
				font-weight: bold;
			}
			.wp-mcp-ai-document-page .document-meta {
				color: #646970;
				font-size: 13px;
			}
			.wp-mcp-ai-document-page .expiry-badge {
				display: inline-block;
				padding: 3px 8px;
				border-radius: 3px;
				font-size: 12px;
				font-weight: bold;
			}
			.wp-mcp-ai-document-page .expiry-badge.expired {
				background: #d63638;
				color: #fff;
			}
			.wp-mcp-ai-document-page .expiry-badge.expiring {
				background: #dba617;
				color: #fff;
			}
			.wp-mcp-ai-document-page .doc-type-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
				gap: 15px;
			}
			.wp-mcp-ai-document-page .doc-type-card {
				border: 1px solid #ccd0d4;
				border-radius: 4px;
				padding: 15px;
				text-align: center;
			}
			.wp-mcp-ai-document-page .doc-type-count {
				font-size: 24px;
				font-weight: bold;
				color: #2271b1;
			}
			.wp-mcp-ai-document-page .doc-type-name {
				color: #1d2327;
				font-size: 14px;
			}
		</style>
		<?php
	}

	/**
	 * Get document statistics.
	 *
	 * @return array Statistics data.
	 */
	private static function get_document_stats() {
		$stats = array(
			'total_documents' => 0,
			'expiring_soon'   => 0,
			'expired'         => 0,
			'valid'           => 0,
		);

		// Count total documents.
		$total_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_reg_document',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$stats['total_documents'] = $total_query->found_posts;

		$today      = current_time( 'Y-m-d' );
		$in_30_days = date( 'Y-m-d', strtotime( '+30 days', current_time( 'timestamp' ) ) );

		// Count expiring soon (within 30 days).
		$expiring_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_reg_document',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => 'expiry_date',
						'value'   => array( $today, $in_30_days ),
						'compare' => 'BETWEEN',
						'type'    => 'DATE',
					),
				),
			)
		);
		$stats['expiring_soon'] = $expiring_query->found_posts;

		// Count expired.
		$expired_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_reg_document',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => 'expiry_date',
						'value'   => $today,
						'compare' => '<',
						'type'    => 'DATE',
					),
				),
			)
		);
		$stats['expired'] = $expired_query->found_posts;

		// Calculate valid documents.
		$stats['valid'] = $stats['total_documents'] - $stats['expired'] - $stats['expiring_soon'];

		return $stats;
	}

	/**
	 * Render expiring documents list.
	 */
	private static function render_expiring_documents() {
		$today      = current_time( 'Y-m-d' );
		$in_30_days = date( 'Y-m-d', strtotime( '+30 days', current_time( 'timestamp' ) ) );

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_reg_document',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_key'       => 'expiry_date',
				'meta_query'     => array(
					array(
						'key'     => 'expiry_date',
						'value'   => $in_30_days,
						'compare' => '<=',
						'type'    => 'DATE',
					),
				),
			)
		);

		if ( $query->have_posts() ) {
			?>
			<ul class="document-list">
				<?php
				while ( $query->have_posts() ) {
					$query->the_post();
					$expiry_date = get_post_meta( get_the_ID(), 'expiry_date', true );
					$is_expired  = $expiry_date && strtotime( $expiry_date ) < strtotime( $today );
					$doc_type    = wp_get_post_terms( get_the_ID(), 'mcp_ai_doc_type', array( 'fields' => 'names' ) );
					?>
					<li class="document-item <?php echo esc_attr( $is_expired ? 'expired' : 'expiring' ); ?>">
						<div>
							<div class="document-title"><?php the_title(); ?></div>
							<div class="document-meta">
								<?php
								if ( ! empty( $doc_type ) ) {
									echo esc_html( $doc_type[0] ) . ' • ';
								}
								echo esc_html__( 'Expires:', 'mcp-ai-wpoos-pro' ) . ' ' . esc_html( date( 'M d, Y', strtotime( $expiry_date ) ) );
								?>
							</div>
						</div>
						<div>
							<span class="expiry-badge <?php echo esc_attr( $is_expired ? 'expired' : 'expiring' ); ?>">
								<?php echo $is_expired ? esc_html__( 'EXPIRED', 'mcp-ai-wpoos-pro' ) : esc_html__( 'EXPIRING SOON', 'mcp-ai-wpoos-pro' ); ?>
							</span>
							<a href="<?php echo esc_url( get_edit_post_link() ); ?>" class="button button-small">
								<?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</div>
					</li>
					<?php
				}
				?>
			</ul>
			<?php
			wp_reset_postdata();
		} else {
			?>
			<p><?php esc_html_e( 'No documents expiring soon.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php
		}
	}

	/**
	 * Render documents by type.
	 */
	private static function render_documents_by_type() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'mcp_ai_doc_type',
				'hide_empty' => false,
			)
		);

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			?>
			<p><?php esc_html_e( 'No document types configured.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php
			return;
		}

		// Get all term IDs.
		$term_ids = wp_list_pluck( $terms, 'term_id' );

		// Fetch all document counts in a single query grouped by term_id.
		global $wpdb;
		$document_counts = $wpdb->get_results(
			"SELECT tr.term_taxonomy_id, COUNT(*) as total
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
			INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			WHERE p.post_type = 'mcp_ai_reg_document'
			AND p.post_status = 'publish'
			AND tt.taxonomy = 'mcp_ai_doc_type'
			AND tt.term_id IN (" . implode( ',', array_map( 'intval', $term_ids ) ) . ')
			GROUP BY tr.term_taxonomy_id',
			ARRAY_A
		);

		// Convert to associative array for quick lookup.
		$counts_by_term = array();
		foreach ( $document_counts as $row ) {
			// Map term_taxonomy_id back to term_id.
			foreach ( $terms as $term ) {
				if ( $term->term_taxonomy_id === (int) $row['term_taxonomy_id'] ) {
					$counts_by_term[ $term->term_id ] = (int) $row['total'];
					break;
				}
			}
		}

		?>
		<div class="doc-type-grid">
			<?php
			foreach ( $terms as $term ) {
				$count = isset( $counts_by_term[ $term->term_id ] ) ? $counts_by_term[ $term->term_id ] : 0;
				?>
				<div class="doc-type-card">
					<div class="doc-type-count"><?php echo esc_html( $count ); ?></div>
					<div class="doc-type-name"><?php echo esc_html( $term->name ); ?></div>
				</div>
				<?php
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render recent documents.
	 */
	private static function render_recent_documents() {
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_reg_document',
				'post_status'    => 'publish',
				'posts_per_page' => 10,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		if ( $query->have_posts() ) {
			?>
			<ul class="document-list">
				<?php
				while ( $query->have_posts() ) {
					$query->the_post();
					$doc_type = wp_get_post_terms( get_the_ID(), 'mcp_ai_doc_type', array( 'fields' => 'names' ) );
					?>
					<li class="document-item">
						<div>
							<div class="document-title"><?php the_title(); ?></div>
							<div class="document-meta">
								<?php
								if ( ! empty( $doc_type ) ) {
									echo esc_html( $doc_type[0] ) . ' • ';
								}
								echo esc_html( sprintf( __( 'Added %s ago', 'mcp-ai-wpoos-pro' ), human_time_diff( get_the_time( 'U' ), current_time( 'timestamp' ) ) ) );
								?>
							</div>
						</div>
						<div>
							<a href="<?php echo esc_url( get_edit_post_link() ); ?>" class="button button-small">
								<?php esc_html_e( 'View', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</div>
					</li>
					<?php
				}
				?>
			</ul>
			<?php
			wp_reset_postdata();
		} else {
			?>
			<p><?php esc_html_e( 'No documents added yet.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php
		}
	}
}

WP_MCP_AI_Reg_Document_Page::init();
