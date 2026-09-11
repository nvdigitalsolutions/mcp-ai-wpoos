<?php
/**
 * Icp Admin Page (ecosystem port — Wave F2, CRM admin slice).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/admin/class-wp-mcp-ai-icp-admin-page.php` for the standalone `nvoos-content-graph-pro` addon.
 * Kept byte-identical. The base Pro addon owns the class in monolith
 * installs — the addon's autoloader skips its copy when
 * `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * ICP Profiles Admin Page
 *
 * WordPress admin page for managing Ideal Customer Profiles (ICP) in the
 * NV oOS Pro CRM Toolkit. Provides a full CRUD interface for creating,
 * editing, deleting, and setting default ICP profiles — the machine-readable
 * definitions that power the ICP scoring engine.
 *
 * Profiles are persisted as a serialised array in the `wp_mcp_ai_icp_profiles`
 * WordPress option, keyed by URL-safe slug.
 *
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`.
 *
 * @package NvoosContentGraphPro
 * @since   2.11.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ICP Profiles admin page class.
 *
 * Registers a submenu page under the "NV CRM" top-level menu and renders
 * the profile list table, the add/edit form, and handles all CRUD operations
 * with proper nonce verification, capability checks, and data sanitisation.
 *
 * @since 2.11.0
 */
class WP_MCP_AI_ICP_Admin_Page {

	/**
	 * Admin page slug.
	 *
	 * @since 2.11.0
	 * @var string
	 */
	const PAGE_SLUG = 'nvoos-crm-icp-profiles';

	/**
	 * Option name that stores all ICP profiles.
	 *
	 * @since 2.11.0
	 * @var string
	 */
	const OPTION_NAME = 'wp_mcp_ai_icp_profiles';

	/**
	 * Nonce action used on the ICP admin page.
	 *
	 * @since 2.11.0
	 * @var string
	 */
	const NONCE_ACTION = 'wp_mcp_ai_icp_admin_action';

	/**
	 * Admin page hook suffix.
	 *
	 * @since 2.11.0
	 * @var string
	 */
	private static $hook_suffix = '';

	// -------------------------------------------------------------------------
	// Initialisation
	// -------------------------------------------------------------------------

	/**
	 * Initialise the admin page — hook into WordPress.
	 *
	 * Registers the submenu page at priority 30, after the CRM Admin Menu
	 * top-level registration at priority 25 and submenu pages at 26–28.
	 *
	 * @since 2.11.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 30 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	// -------------------------------------------------------------------------
	// Menu Registration
	// -------------------------------------------------------------------------

	/**
	 * Register the ICP Profiles submenu page under the NV CRM menu.
	 *
	 * @since 2.11.0
	 *
	 * @return void
	 */
	public static function register_page() {
		self::$hook_suffix = add_submenu_page(
			WP_MCP_AI_CRM_Admin_Menu::PARENT_SLUG,
			__( 'ICP Profiles', 'nvoos-content-graph-pro' ),
			__( 'ICP Profiles', 'nvoos-content-graph-pro' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	// -------------------------------------------------------------------------
	// Asset Enqueueing
	// -------------------------------------------------------------------------

	/**
	 * Enqueue admin assets for the ICP profiles page.
	 *
	 * Prints an inline script that powers the real-time weight-sum validator
	 * and the delete-confirmation dialog.
	 *
	 * @since 2.11.0
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( self::$hook_suffix !== $hook ) {
			return;
		}

		add_action(
			'admin_footer',
			function () {
				?>
				<script type="text/javascript">
				(function($) {
					var $weightInputs = $('.icp-weight-input');
					var $weightTotal  = $('#icp-weight-total');
					var $tierA        = $('#icp_tier_a');
					var $tierB        = $('#icp_tier_b');
					var $saveBtn      = $('#icp-save-btn');

					function updateWeightTotal() {
						var sum = 0;
						$weightInputs.each(function() {
							sum += parseInt( $(this).val(), 10 ) || 0;
						});
						$weightTotal.text( sum );
						if ( sum !== 100 ) {
							$weightTotal.css( 'color', '#d63638' );
						} else {
							$weightTotal.css( 'color', '#00a32a' );
						}
					}

					function validateThresholds() {
						var a = parseInt( $tierA.val(), 10 ) || 0;
						var b = parseInt( $tierB.val(), 10 ) || 0;
						if ( a <= b ) {
							$tierA.css( 'border-color', '#d63638' );
							$tierB.css( 'border-color', '#d63638' );
							return false;
						}
						$tierA.css( 'border-color', '' );
						$tierB.css( 'border-color', '' );
						return true;
					}

					$weightInputs.on( 'input', updateWeightTotal );
					$tierA.add( $tierB ).on( 'input', validateThresholds );

					// Validate on form submit.
					$('#icp-profile-form').on( 'submit', function(e) {
						var sum = 0;
						$weightInputs.each(function() { sum += parseInt( $(this).val(), 10 ) || 0; });
						if ( sum !== 100 ) {
							e.preventDefault();
							alert( '<?php echo esc_js( __( 'Scoring weights must sum to 100.', 'nvoos-content-graph-pro' ) ); ?>' );
							return false;
						}
						if ( ! validateThresholds() ) {
							e.preventDefault();
							alert( '<?php echo esc_js( __( 'Tier A threshold must be greater than Tier B.', 'nvoos-content-graph-pro' ) ); ?>' );
							return false;
						}
						var $nameField = $('#icp_name');
						if ( ! $.trim( $nameField.val() ) ) {
							e.preventDefault();
							alert( '<?php echo esc_js( __( 'Profile name is required.', 'nvoos-content-graph-pro' ) ); ?>' );
							$nameField.focus();
							return false;
						}
					});

					// Delete confirmation.
					$('.icp-delete-link').on( 'click', function(e) {
						if ( ! confirm( '<?php echo esc_js( __( 'Are you sure you want to delete this ICP profile? This action cannot be undone.', 'nvoos-content-graph-pro' ) ); ?>' ) ) {
							e.preventDefault();
						}
					});

					// Initial total.
					updateWeightTotal();
				})(jQuery);
				</script>
				<?php
			},
			99
		);
	}

	// -------------------------------------------------------------------------
	// Page Rendering
	// -------------------------------------------------------------------------

	/**
	 * Render the ICP Profiles admin page.
	 *
	 * Dispatches to list, add, or edit views based on the `action` and `slug`
	 * query parameters.  Handles POST saves, GET deletes, and default-setting
	 * with full nonce verification.
	 *
	 * @since 2.11.0
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'nvoos-content-graph-pro' ) );
		}

		// --- Handle GET: delete ---
		if ( isset( $_GET['action'] ) && 'delete' === $_GET['action'] && ! empty( $_GET['slug'] ) ) {
			self::handle_delete();
		}

		// --- Handle GET: set_default ---
		if ( isset( $_GET['action'] ) && 'set_default' === $_GET['action'] && ! empty( $_GET['slug'] ) ) {
			self::handle_set_default();
		}

		// --- Handle POST: save ---
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			self::handle_save();
		}

		// --- Show notices from redirect messages ---
		$message = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : '';
		if ( '' !== $message ) {
			add_action(
				'admin_notices',
				function () use ( $message ) {
					switch ( $message ) {
						case 'saved':
							echo '<div class="notice notice-success is-dismissible"><p>';
							esc_html_e( 'ICP profile saved successfully.', 'nvoos-content-graph-pro' );
							echo '</p></div>';
							break;
						case 'deleted':
							echo '<div class="notice notice-success is-dismissible"><p>';
							esc_html_e( 'ICP profile deleted successfully.', 'nvoos-content-graph-pro' );
							echo '</p></div>';
							break;
						case 'default_set':
							echo '<div class="notice notice-success is-dismissible"><p>';
							esc_html_e( 'Default ICP profile updated.', 'nvoos-content-graph-pro' );
							echo '</p></div>';
							break;
						case 'not_found':
							echo '<div class="notice notice-error is-dismissible"><p>';
							esc_html_e( 'The requested ICP profile was not found.', 'nvoos-content-graph-pro' );
							echo '</p></div>';
							break;
					}
				}
			);
		}

		// --- Determine view ---
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
		$slug   = isset( $_GET['slug'] ) ? sanitize_key( wp_unslash( $_GET['slug'] ) ) : '';

		if ( 'add' === $action || ( 'edit' === $action && ! empty( $slug ) ) ) {
			self::render_editor( $action, $slug );
		} else {
			self::render_list();
		}
	}

	// -------------------------------------------------------------------------
	// List View
	// -------------------------------------------------------------------------

	/**
	 * Render the profiles list table.
	 *
	 * @since 2.11.0
	 *
	 * @return void
	 */
	private static function render_list() {
		$profiles = self::get_all_profiles();

		?>
		<div class="wrap">
			<h1>
				<span class="dashicons dashicons-id-alt" style="font-size:28px;width:28px;height:28px;vertical-align:middle;"></span>
				<?php esc_html_e( 'ICP Profiles', 'nvoos-content-graph-pro' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=add' ) ); ?>"
					class="page-title-action">
					<?php esc_html_e( 'Add New Profile', 'nvoos-content-graph-pro' ); ?>
				</a>
			</h1>

			<p class="description" style="max-width:800px;">
				<?php
				printf(
					/* translators: %s: statistic about ICP-driven win rates */
					esc_html__(
						'Ideal Customer Profiles define the attributes of your best-fit accounts. Companies using ICP-driven targeting report %s higher win rates. Use this page to create and manage machine-readable ICP profiles that power the CRM scoring engine.',
						'nvoos-content-graph-pro'
					),
					'68%'
				);
				?>
			</p>

			<?php if ( empty( $profiles ) ) : ?>
				<div class="notice notice-info" style="margin:16px 0 0;">
					<p>
						<?php esc_html_e( 'No ICP profiles have been created yet. Click "Add New Profile" above to define your first Ideal Customer Profile.', 'nvoos-content-graph-pro' ); ?>
					</p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped" style="margin-top:16px;">
					<thead>
						<tr>
							<th scope="col" style="width:20%;"><?php esc_html_e( 'Name', 'nvoos-content-graph-pro' ); ?></th>
							<th scope="col" style="width:30%;"><?php esc_html_e( 'Description', 'nvoos-content-graph-pro' ); ?></th>
							<th scope="col" style="width:12%;"><?php esc_html_e( 'Default Badge', 'nvoos-content-graph-pro' ); ?></th>
							<th scope="col" style="width:12%;"><?php esc_html_e( 'Dimensions Configured', 'nvoos-content-graph-pro' ); ?></th>
							<th scope="col" style="width:26%;"><?php esc_html_e( 'Actions', 'nvoos-content-graph-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $profiles as $profile_slug => $profile ) : ?>
							<tr>
								<td>
									<strong>
										<?php echo esc_html( isset( $profile['name'] ) ? $profile['name'] : $profile_slug ); ?>
									</strong>
									<?php if ( ! empty( $profile['is_default'] ) ) : ?>
										<span class="dashicons dashicons-star-filled" style="color:#f0ad4e;font-size:14px;width:14px;height:14px;vertical-align:middle;"
												title="<?php esc_attr_e( 'Default profile', 'nvoos-content-graph-pro' ); ?>"></span>
									<?php endif; ?>
								</td>
								<td>
									<?php echo esc_html( isset( $profile['description'] ) ? $profile['description'] : '' ); ?>
								</td>
								<td>
									<?php echo esc_html( isset( $profile['badge'] ) ? $profile['badge'] : '—' ); ?>
								</td>
								<td>
									<?php echo esc_html( self::format_dimension_count( $profile ) ); ?>
								</td>
								<td>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=edit&slug=' . urlencode( $profile_slug ) ) ); ?>"
										class="button button-small">
										<?php esc_html_e( 'Edit', 'nvoos-content-graph-pro' ); ?>
									</a>
									<?php if ( empty( $profile['is_default'] ) ) : ?>
										<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=set_default&slug=' . urlencode( $profile_slug ) ), self::NONCE_ACTION . '_set_default_' . $profile_slug ) ); ?>"
											class="button button-small">
											<?php esc_html_e( 'Set as Default', 'nvoos-content-graph-pro' ); ?>
										</a>
									<?php endif; ?>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=delete&slug=' . urlencode( $profile_slug ) ), self::NONCE_ACTION . '_delete_' . $profile_slug ) ); ?>"
										class="button button-small icp-delete-link"
										style="color:#d63638;border-color:#d63638;">
										<?php esc_html_e( 'Delete', 'nvoos-content-graph-pro' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Editor View
	// -------------------------------------------------------------------------

	/**
	 * Render the profile add / edit form.
	 *
	 * @since 2.11.0
	 *
	 * @param string $action 'add' or 'edit'.
	 * @param string $slug   Profile slug when editing.
	 * @return void
	 */
	private static function render_editor( $action, $slug ) {
		$profile = array();
		$is_edit = ( 'edit' === $action && ! empty( $slug ) );

		// Recover pending profile data and errors from a failed save attempt.
		$pending    = get_transient( 'wp_mcp_ai_icp_pending' );
		$error_list = get_transient( 'wp_mcp_ai_icp_errors' );
		delete_transient( 'wp_mcp_ai_icp_pending' );
		delete_transient( 'wp_mcp_ai_icp_errors' );

		if ( $is_edit ) {
			$profile = self::get_profile_by_slug( $slug );
			if ( empty( $profile ) ) {
				echo '<div class="wrap"><div class="notice notice-error"><p>';
				esc_html_e( 'The requested ICP profile was not found.', 'nvoos-content-graph-pro' );
				echo '</p></div>';
				echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) . '" class="button">';
				esc_html_e( '&larr; Back to Profiles', 'nvoos-content-graph-pro' );
				echo '</a></p></div>';
				return;
			}
		}

		// Merge pending (unsaved) data over stored profile for re-rendering after validation error.
		if ( is_array( $pending ) && ! empty( $pending ) ) {
			$profile = array_merge( $profile, $pending );
		}

		$page_title = $is_edit
			? __( 'Edit ICP Profile', 'nvoos-content-graph-pro' )
			: __( 'Add New ICP Profile', 'nvoos-content-graph-pro' );

		?>
		<div class="wrap">
			<h1>
				<span class="dashicons dashicons-id-alt" style="font-size:28px;width:28px;height:28px;vertical-align:middle;"></span>
				<?php echo esc_html( $page_title ); ?>
			</h1>

			<?php
			// Show validation errors from a previous failed save.
			if ( is_array( $error_list ) && ! empty( $error_list ) ) {
				foreach ( $error_list as $err ) {
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $err ) . '</p></div>';
				}
			}
			?>

			<?php
			$form_action = add_query_arg(
				array(
					'page'   => self::PAGE_SLUG,
					'action' => $action,
				),
				admin_url( 'admin.php' )
			);
			if ( $is_edit ) {
				$form_action = add_query_arg( 'slug', $slug, $form_action );
			}
			?>
			<form id="icp-profile-form" method="post" action="<?php echo esc_url( $form_action ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION . '_save' ); ?>
				<input type="hidden" name="icp_action" value="save" />
				<?php if ( $is_edit ) : ?>
					<input type="hidden" name="icp_original_slug" value="<?php echo esc_attr( $slug ); ?>" />
				<?php endif; ?>

				<!-- Name & Description -->
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="icp_name"><?php esc_html_e( 'Profile Name', 'nvoos-content-graph-pro' ); ?> <span style="color:#d63638;">*</span></label>
						</th>
						<td>
							<input type="text" id="icp_name" name="icp_name" class="regular-text"
									value="<?php echo esc_attr( isset( $profile['name'] ) ? $profile['name'] : '' ); ?>"
									required maxlength="200" />
							<p class="description"><?php esc_html_e( 'A descriptive name for this profile (e.g. "Enterprise SaaS — North America").', 'nvoos-content-graph-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="icp_description"><?php esc_html_e( 'Description', 'nvoos-content-graph-pro' ); ?></label>
						</th>
						<td>
							<textarea id="icp_description" name="icp_description" class="large-text" rows="3"
										maxlength="500"><?php echo esc_textarea( isset( $profile['description'] ) ? $profile['description'] : '' ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Brief description of who this profile targets and when to use it.', 'nvoos-content-graph-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="icp_badge"><?php esc_html_e( 'Default Badge', 'nvoos-content-graph-pro' ); ?></label>
						</th>
						<td>
							<input type="text" id="icp_badge" name="icp_badge" class="regular-text"
									value="<?php echo esc_attr( isset( $profile['badge'] ) ? $profile['badge'] : '' ); ?>"
									maxlength="50" />
							<p class="description"><?php esc_html_e( 'Optional short badge label shown next to scored leads (e.g. "Enterprise", "SMB", "Strategic").', 'nvoos-content-graph-pro' ); ?></p>
						</td>
					</tr>
				</table>

				<!-- Dimension Sections -->
				<?php
				self::render_dimension_section(
					__( 'Firmographics', 'nvoos-content-graph-pro' ),
					'firmographics',
					function () use ( $profile ) {
						self::render_firmographics_fields( $profile );
					}
				);

				self::render_dimension_section(
					__( 'Technographics', 'nvoos-content-graph-pro' ),
					'technographics',
					function () use ( $profile ) {
						self::render_technographics_fields( $profile );
					}
				);

				self::render_dimension_section(
					__( 'Buying Triggers', 'nvoos-content-graph-pro' ),
					'buying_triggers',
					function () use ( $profile ) {
						self::render_buying_triggers_fields( $profile );
					}
				);

				self::render_dimension_section(
					__( 'Macro Trends', 'nvoos-content-graph-pro' ),
					'macro_trends',
					function () use ( $profile ) {
						self::render_macro_trends_fields( $profile );
					}
				);

				self::render_dimension_section(
					__( 'Negative Signals', 'nvoos-content-graph-pro' ),
					'negative_signals',
					function () use ( $profile ) {
						self::render_negative_signals_fields( $profile );
					}
				);

				self::render_dimension_section(
					__( 'Scoring Weights', 'nvoos-content-graph-pro' ),
					'scoring_weights',
					function () use ( $profile ) {
						self::render_scoring_weights_fields( $profile );
					}
				);

				self::render_dimension_section(
					__( 'Score Thresholds', 'nvoos-content-graph-pro' ),
					'score_thresholds',
					function () use ( $profile ) {
						self::render_score_thresholds_fields( $profile );
					}
				);
				?>

				<p class="submit">
					<button type="submit" id="icp-save-btn" class="button button-primary">
						<?php echo $is_edit ? esc_html__( 'Update Profile', 'nvoos-content-graph-pro' ) : esc_html__( 'Create Profile', 'nvoos-content-graph-pro' ); ?>
					</button>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" class="button">
						<?php esc_html_e( 'Cancel', 'nvoos-content-graph-pro' ); ?>
					</a>
				</p>
			</form>
		</div>
		<?php
	}

	// =====================================================================
	// Dimension Field Renderers
	// =====================================================================

	/**
	 * Render firmographics fields.
	 *
	 * @since 2.11.0
	 *
	 * @param array $profile Current profile data (empty for new profiles).
	 * @return void
	 */
	private static function render_firmographics_fields( array $profile ) {
		$selected_industries  = isset( $profile['industries'] ) ? (array) $profile['industries'] : array();
		$selected_geographies = isset( $profile['geographies'] ) ? (array) $profile['geographies'] : array();
		$selected_funding     = isset( $profile['funding_stages'] ) ? (array) $profile['funding_stages'] : array();
		$selected_biz_models  = isset( $profile['business_models'] ) ? (array) $profile['business_models'] : array();

		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Industries', 'nvoos-content-graph-pro' ); ?></th>
				<td>
					<?php self::render_multi_checkbox( 'icp_industries', __( 'Select target industries', 'nvoos-content-graph-pro' ), self::get_industry_options(), $selected_industries ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Company Size', 'nvoos-content-graph-pro' ); ?></th>
				<td>
					<?php
					self::render_number_field(
						'icp_employees_min',
						__( 'Min employees', 'nvoos-content-graph-pro' ),
						isset( $profile['employees_min'] ) ? (int) $profile['employees_min'] : 0,
						0,
						1000000
					);
					echo ' &mdash; ';
					self::render_number_field(
						'icp_employees_max',
						__( 'Max employees', 'nvoos-content-graph-pro' ),
						isset( $profile['employees_max'] ) ? (int) $profile['employees_max'] : 0,
						0,
						1000000
					);
					?>
					<p class="description"><?php esc_html_e( 'Target employee count range. Leave both at 0 to ignore.', 'nvoos-content-graph-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Annual Revenue', 'nvoos-content-graph-pro' ); ?></th>
				<td>
					<?php
					self::render_number_field(
						'icp_revenue_min',
						__( 'Min revenue (USD)', 'nvoos-content-graph-pro' ),
						isset( $profile['revenue_min'] ) ? (int) $profile['revenue_min'] : 0,
						0,
						100000000000
					);
					echo ' &mdash; ';
					self::render_number_field(
						'icp_revenue_max',
						__( 'Max revenue (USD)', 'nvoos-content-graph-pro' ),
						isset( $profile['revenue_max'] ) ? (int) $profile['revenue_max'] : 0,
						0,
						100000000000
					);
					?>
					<p class="description"><?php esc_html_e( 'Target annual revenue range in USD. Leave both at 0 to ignore.', 'nvoos-content-graph-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Geographies', 'nvoos-content-graph-pro' ); ?></th>
				<td>
					<?php self::render_multi_checkbox( 'icp_geographies', __( 'Select target regions', 'nvoos-content-graph-pro' ), self::get_geography_options(), $selected_geographies ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Funding Stages', 'nvoos-content-graph-pro' ); ?></th>
				<td>
					<?php self::render_multi_checkbox( 'icp_funding_stages', __( 'Select target funding stages', 'nvoos-content-graph-pro' ), self::get_funding_stage_options(), $selected_funding ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Business Models', 'nvoos-content-graph-pro' ); ?></th>
				<td>
					<?php self::render_multi_checkbox( 'icp_business_models', __( 'Select target business models', 'nvoos-content-graph-pro' ), self::get_business_model_options(), $selected_biz_models ); ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render technographics fields.
	 *
	 * @since 2.11.0
	 *
	 * @param array $profile Current profile data.
	 * @return void
	 */
	private static function render_technographics_fields( array $profile ) {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="icp_required_tech"><?php esc_html_e( 'Required Tools', 'nvoos-content-graph-pro' ); ?></label>
				</th>
				<td>
					<?php
					self::render_textarea_field(
						'icp_required_tech',
						__( 'Required tools', 'nvoos-content-graph-pro' ),
						isset( $profile['required_tech'] ) ? implode( "\n", (array) $profile['required_tech'] ) : '',
						__( 'e.g. Salesforce\nHubSpot\nSlack', 'nvoos-content-graph-pro' )
					);
					?>
					<p class="description"><?php esc_html_e( 'Tools that must be present in the company\'s tech stack — one per line.', 'nvoos-content-graph-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="icp_preferred_tech"><?php esc_html_e( 'Preferred Tools', 'nvoos-content-graph-pro' ); ?></label>
				</th>
				<td>
					<?php
					self::render_textarea_field(
						'icp_preferred_tech',
						__( 'Preferred tools', 'nvoos-content-graph-pro' ),
						isset( $profile['preferred_tech'] ) ? implode( "\n", (array) $profile['preferred_tech'] ) : '',
						__( 'e.g. Jira\nAsana\nNotion', 'nvoos-content-graph-pro' )
					);
					?>
					<p class="description"><?php esc_html_e( 'Tools that indicate a good fit but are not mandatory — one per line.', 'nvoos-content-graph-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="icp_competitor_tech"><?php esc_html_e( 'Competitor Tools', 'nvoos-content-graph-pro' ); ?></label>
				</th>
				<td>
					<?php
					self::render_textarea_field(
						'icp_competitor_tech',
						__( 'Competitor tools', 'nvoos-content-graph-pro' ),
						isset( $profile['competitor_tech'] ) ? implode( "\n", (array) $profile['competitor_tech'] ) : '',
						__( 'e.g. Zoho CRM\nPipedrive', 'nvoos-content-graph-pro' )
					);
					?>
					<p class="description"><?php esc_html_e( 'Competitor products whose presence should reduce the fit score — one per line.', 'nvoos-content-graph-pro' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render buying triggers fields.
	 *
	 * @since 2.11.0
	 *
	 * @param array $profile Current profile data.
	 * @return void
	 */
	private static function render_buying_triggers_fields( array $profile ) {
		$selected_triggers = isset( $profile['buying_triggers'] ) ? (array) $profile['buying_triggers'] : array();

		$trigger_options = array(
			'funding_round'      => __( 'Funding round announced', 'nvoos-content-graph-pro' ),
			'new_leadership'     => __( 'New executive leadership', 'nvoos-content-graph-pro' ),
			'rapid_hiring'       => __( 'Rapid hiring / team expansion', 'nvoos-content-graph-pro' ),
			'tech_change'        => __( 'Technology stack change', 'nvoos-content-graph-pro' ),
			'compliance_mandate' => __( 'Compliance / regulatory mandate', 'nvoos-content-graph-pro' ),
			'ma_activity'        => __( 'M&A activity', 'nvoos-content-graph-pro' ),
			'product_launch'     => __( 'Product launch', 'nvoos-content-graph-pro' ),
			'office_expansion'   => __( 'Office / geographic expansion', 'nvoos-content-graph-pro' ),
			'partnership'        => __( 'Strategic partnership announced', 'nvoos-content-graph-pro' ),
			'rebrand'            => __( 'Rebrand / repositioning', 'nvoos-content-graph-pro' ),
		);

		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Trigger Events', 'nvoos-content-graph-pro' ); ?></th>
				<td>
					<?php self::render_multi_checkbox( 'icp_buying_triggers', __( 'Select relevant buying triggers', 'nvoos-content-graph-pro' ), $trigger_options, $selected_triggers ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="icp_custom_triggers"><?php esc_html_e( 'Custom Triggers', 'nvoos-content-graph-pro' ); ?></label>
				</th>
				<td>
					<?php
					self::render_textarea_field(
						'icp_custom_triggers',
						__( 'Custom triggers', 'nvoos-content-graph-pro' ),
						isset( $profile['custom_triggers'] ) ? $profile['custom_triggers'] : '',
						__( 'Describe additional custom buying triggers — one per line.', 'nvoos-content-graph-pro' )
					);
					?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render macro trends fields.
	 *
	 * @since 2.11.0
	 *
	 * @param array $profile Current profile data.
	 * @return void
	 */
	private static function render_macro_trends_fields( array $profile ) {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="icp_macro_trends"><?php esc_html_e( 'Macro Trends Description', 'nvoos-content-graph-pro' ); ?></label>
				</th>
				<td>
					<?php
					self::render_textarea_field(
						'icp_macro_trends',
						__( 'Macro trends', 'nvoos-content-graph-pro' ),
						isset( $profile['macro_trends'] ) ? $profile['macro_trends'] : '',
						__( 'Describe macro-level trends that make this ICP relevant (e.g. "Increased cloud migration in financial services due to...").', 'nvoos-content-graph-pro' )
					);
					?>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="icp_market_shifts"><?php esc_html_e( 'Market Shifts', 'nvoos-content-graph-pro' ); ?></label>
				</th>
				<td>
					<?php
					self::render_textarea_field(
						'icp_market_shifts',
						__( 'Market shifts', 'nvoos-content-graph-pro' ),
						isset( $profile['market_shifts'] ) ? $profile['market_shifts'] : '',
						__( 'Describe market shifts or disruptions that create opportunities for this ICP segment.', 'nvoos-content-graph-pro' )
					);
					?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render negative signals / disqualifiers fields.
	 *
	 * @since 2.11.0
	 *
	 * @param array $profile Current profile data.
	 * @return void
	 */
	private static function render_negative_signals_fields( array $profile ) {
		$selected_excluded_industries  = isset( $profile['excluded_industries'] ) ? (array) $profile['excluded_industries'] : array();
		$selected_excluded_geographies = isset( $profile['excluded_geographies'] ) ? (array) $profile['excluded_geographies'] : array();

		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Excluded Industries', 'nvoos-content-graph-pro' ); ?></th>
				<td>
					<?php self::render_multi_checkbox( 'icp_excluded_industries', __( 'Select industries to exclude', 'nvoos-content-graph-pro' ), self::get_industry_options(), $selected_excluded_industries ); ?>
					<p class="description"><?php esc_html_e( 'Companies in these industries will receive a penalty in scoring.', 'nvoos-content-graph-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Excluded Geographies', 'nvoos-content-graph-pro' ); ?></th>
				<td>
					<?php self::render_multi_checkbox( 'icp_excluded_geographies', __( 'Select geographies to exclude', 'nvoos-content-graph-pro' ), self::get_geography_options(), $selected_excluded_geographies ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="icp_max_size"><?php esc_html_e( 'Maximum Company Size', 'nvoos-content-graph-pro' ); ?></label>
				</th>
				<td>
					<?php
					self::render_number_field(
						'icp_max_size',
						__( 'Max employees (disqualifier)', 'nvoos-content-graph-pro' ),
						isset( $profile['max_size'] ) ? (int) $profile['max_size'] : 0,
						0,
						1000000
					);
					?>
					<p class="description"><?php esc_html_e( 'Companies above this employee count receive a penalty. 0 = no limit.', 'nvoos-content-graph-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="icp_min_revenue"><?php esc_html_e( 'Minimum Revenue (Disqualifier)', 'nvoos-content-graph-pro' ); ?></label>
				</th>
				<td>
					<?php
					self::render_number_field(
						'icp_min_revenue',
						__( 'Min revenue (USD, disqualifier)', 'nvoos-content-graph-pro' ),
						isset( $profile['min_revenue'] ) ? (int) $profile['min_revenue'] : 0,
						0,
						100000000000
					);
					?>
					<p class="description"><?php esc_html_e( 'Companies below this revenue receive a penalty. 0 = no minimum.', 'nvoos-content-graph-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Competitor Flag', 'nvoos-content-graph-pro' ); ?></th>
				<td>
					<?php
					self::render_checkbox_field(
						'icp_competitor_flag',
						__( 'Apply competitor penalty', 'nvoos-content-graph-pro' ),
						! empty( $profile['competitor_flag'] )
					);
					?>
					<p class="description"><?php esc_html_e( 'When enabled, known competitor relationships significantly reduce the score.', 'nvoos-content-graph-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="icp_custom_disqualifiers"><?php esc_html_e( 'Custom Disqualifiers', 'nvoos-content-graph-pro' ); ?></label>
				</th>
				<td>
					<?php
					self::render_textarea_field(
						'icp_custom_disqualifiers',
						__( 'Custom disqualifiers', 'nvoos-content-graph-pro' ),
						isset( $profile['custom_disqualifiers'] ) ? $profile['custom_disqualifiers'] : '',
						__( 'Describe additional disqualifying conditions — one per line.', 'nvoos-content-graph-pro' )
					);
					?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render scoring weights fields.
	 *
	 * @since 2.11.0
	 *
	 * @param array $profile Current profile data.
	 * @return void
	 */
	private static function render_scoring_weights_fields( array $profile ) {
		$weights = isset( $profile['weights'] ) && is_array( $profile['weights'] )
			? $profile['weights']
			: array(
				'firmographic_fit'    => 25,
				'technographic_fit'   => 20,
				'intent_signals'      => 15,
				'engagement_activity' => 15,
				'buying_triggers'     => 10,
				'economic_outcome'    => 10,
				'negative_signals'    => 5,
			);

		$dimension_labels = array(
			'firmographic_fit'    => __( 'Firmographic Fit', 'nvoos-content-graph-pro' ),
			'technographic_fit'   => __( 'Technographic Fit', 'nvoos-content-graph-pro' ),
			'intent_signals'      => __( 'Intent Signals', 'nvoos-content-graph-pro' ),
			'engagement_activity' => __( 'Engagement Activity', 'nvoos-content-graph-pro' ),
			'buying_triggers'     => __( 'Buying Triggers', 'nvoos-content-graph-pro' ),
			'economic_outcome'    => __( 'Economic Outcome', 'nvoos-content-graph-pro' ),
			'negative_signals'    => __( 'Negative Signals', 'nvoos-content-graph-pro' ),
		);

		?>
		<p class="description" style="max-width:800px;">
			<?php esc_html_e( 'Assign a percentage weight to each scoring dimension. All seven weights must sum to 100. These weights control how much each dimension contributes to the final ICP fit score.', 'nvoos-content-graph-pro' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<?php foreach ( $dimension_labels as $slug => $label ) : ?>
				<tr>
					<th scope="row">
						<label for="icp_weight_<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></label>
					</th>
					<td>
						<input type="number"
								id="icp_weight_<?php echo esc_attr( $slug ); ?>"
								name="icp_weights[<?php echo esc_attr( $slug ); ?>]"
								class="small-text icp-weight-input"
								value="<?php echo esc_attr( isset( $weights[ $slug ] ) ? (int) $weights[ $slug ] : 0 ); ?>"
								min="0" max="100" step="1" /> %
					</td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Total', 'nvoos-content-graph-pro' ); ?></th>
				<td>
					<strong id="icp-weight-total" style="font-size:16px;">0</strong> / 100
					<span style="margin-left:8px;color:#646970;">
						<?php esc_html_e( '(must equal 100 to save)', 'nvoos-content-graph-pro' ); ?>
					</span>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render score thresholds fields.
	 *
	 * @since 2.11.0
	 *
	 * @param array $profile Current profile data.
	 * @return void
	 */
	private static function render_score_thresholds_fields( array $profile ) {
		$thresholds = isset( $profile['tier_thresholds'] ) && is_array( $profile['tier_thresholds'] )
			? $profile['tier_thresholds']
			: array(
				'A' => 80,
				'B' => 60,
			);

		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="icp_tier_a"><?php esc_html_e( 'Tier A Threshold', 'nvoos-content-graph-pro' ); ?></label>
				</th>
				<td>
					<?php
					self::render_number_field(
						'icp_tier_a',
						__( 'Tier A threshold', 'nvoos-content-graph-pro' ),
						isset( $thresholds['A'] ) ? (int) $thresholds['A'] : 80,
						0,
						100
					);
					?>
					<p class="description"><?php esc_html_e( 'Companies scoring at or above this value receive Tier A — highest priority. Must be greater than Tier B. Default: 80.', 'nvoos-content-graph-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="icp_tier_b"><?php esc_html_e( 'Tier B Threshold', 'nvoos-content-graph-pro' ); ?></label>
				</th>
				<td>
					<?php
					self::render_number_field(
						'icp_tier_b',
						__( 'Tier B threshold', 'nvoos-content-graph-pro' ),
						isset( $thresholds['B'] ) ? (int) $thresholds['B'] : 60,
						0,
						100
					);
					?>
					<p class="description"><?php esc_html_e( 'Companies at or above this value (but below Tier A) receive Tier B — qualified leads. Scores below this value fall into Tier C. Default: 60.', 'nvoos-content-graph-pro' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	// =====================================================================
	// Request Handlers
	// =====================================================================

	/**
	 * Handle a profile save (POST).
	 *
	 * @since 2.11.0
	 *
	 * @return void
	 */
	private static function handle_save() {
		// Verify the action field.
		if ( ! isset( $_POST['icp_action'] ) || 'save' !== $_POST['icp_action'] ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION . '_save' );

		$profile = self::sanitise_profile_input();

		// Get the original action/slug for redirect on error.
		$page_action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'add';
		$page_slug   = isset( $_GET['slug'] ) ? sanitize_key( wp_unslash( $_GET['slug'] ) ) : '';

		// Build the redirect URL for the current editor page.
		$editor_url = add_query_arg(
			array(
				'page'   => self::PAGE_SLUG,
				'action' => $page_action,
			),
			admin_url( 'admin.php' )
		);
		if ( ! empty( $page_slug ) ) {
			$editor_url = add_query_arg( 'slug', $page_slug, $editor_url );
		}

		// Validate.
		$errors = self::validate_profile( $profile );
		if ( ! empty( $errors ) ) {
			set_transient( 'wp_mcp_ai_icp_errors', $errors, 30 );
			set_transient( 'wp_mcp_ai_icp_pending', $profile, 30 );
			wp_safe_redirect( $editor_url );
			exit;
		}

		// Generate slug.
		$original_slug = isset( $_POST['icp_original_slug'] ) ? sanitize_key( wp_unslash( $_POST['icp_original_slug'] ) ) : '';
		$slug          = ! empty( $original_slug )
			? $original_slug
			: sanitize_title( $profile['name'] );

		// Ensure uniqueness.
		$all_profiles = self::get_all_profiles();
		if ( empty( $original_slug ) && isset( $all_profiles[ $slug ] ) ) {
			$base = $slug;
			$i    = 1;
			while ( isset( $all_profiles[ $slug ] ) ) {
				$slug = $base . '-' . $i;
				++$i;
			}
		}

		// If renaming via slug change, remove old key.
		if ( ! empty( $original_slug ) && $original_slug !== $slug ) {
			unset( $all_profiles[ $original_slug ] );
		}

		$all_profiles[ $slug ] = $profile;

		$saved = update_option( self::OPTION_NAME, $all_profiles, false );

		$redirect_url = add_query_arg(
			array(
				'page'    => self::PAGE_SLUG,
				'message' => $saved ? 'saved' : 'no_change',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle a profile delete (GET).
	 *
	 * @since 2.11.0
	 *
	 * @return void
	 */
	private static function handle_delete() {
		$slug = isset( $_GET['slug'] ) ? sanitize_key( wp_unslash( $_GET['slug'] ) ) : '';

		if ( empty( $slug ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION . '_delete_' . $slug );

		$all_profiles = self::get_all_profiles();

		if ( ! isset( $all_profiles[ $slug ] ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => self::PAGE_SLUG,
						'message' => 'not_found',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		unset( $all_profiles[ $slug ] );
		update_option( self::OPTION_NAME, $all_profiles, false );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'message' => 'deleted',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle setting a profile as default (GET).
	 *
	 * @since 2.11.0
	 *
	 * @return void
	 */
	private static function handle_set_default() {
		$slug = isset( $_GET['slug'] ) ? sanitize_key( wp_unslash( $_GET['slug'] ) ) : '';

		if ( empty( $slug ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION . '_set_default_' . $slug );

		$all_profiles = self::get_all_profiles();

		if ( ! isset( $all_profiles[ $slug ] ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => self::PAGE_SLUG,
						'message' => 'not_found',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// Clear default flag on all profiles.
		foreach ( $all_profiles as $key => &$prof ) {
			$prof['is_default'] = false;
		}
		unset( $prof );

		// Set the target as default.
		$all_profiles[ $slug ]['is_default'] = true;

		update_option( self::OPTION_NAME, $all_profiles, false );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'message' => 'default_set',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// =====================================================================
	// Input Sanitisation & Validation
	// =====================================================================

	/**
	 * Sanitise all profile fields from $_POST.
	 *
	 * @since 2.11.0
	 *
	 * @return array Sanitised profile data.
	 */
	private static function sanitise_profile_input() {
		check_admin_referer( self::NONCE_ACTION . '_save' );

		$profile = array();

		// Text fields.
		$profile['name']        = isset( $_POST['icp_name'] ) ? sanitize_text_field( wp_unslash( $_POST['icp_name'] ) ) : '';
		$profile['description'] = isset( $_POST['icp_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['icp_description'] ) ) : '';
		$profile['badge']       = isset( $_POST['icp_badge'] ) ? sanitize_text_field( wp_unslash( $_POST['icp_badge'] ) ) : '';

		// Numeric fields.
		$profile['employees_min'] = isset( $_POST['icp_employees_min'] ) ? absint( wp_unslash( $_POST['icp_employees_min'] ) ) : 0;
		$profile['employees_max'] = isset( $_POST['icp_employees_max'] ) ? absint( wp_unslash( $_POST['icp_employees_max'] ) ) : 0;
		$profile['revenue_min']   = isset( $_POST['icp_revenue_min'] ) ? absint( wp_unslash( $_POST['icp_revenue_min'] ) ) : 0;
		$profile['revenue_max']   = isset( $_POST['icp_revenue_max'] ) ? absint( wp_unslash( $_POST['icp_revenue_max'] ) ) : 0;

		// Array checkboxes.
		$profile['industries']      = isset( $_POST['icp_industries'] ) ? array_map( 'sanitize_key', wp_unslash( (array) $_POST['icp_industries'] ) ) : array();
		$profile['geographies']     = isset( $_POST['icp_geographies'] ) ? array_map( 'sanitize_key', wp_unslash( (array) $_POST['icp_geographies'] ) ) : array();
		$profile['funding_stages']  = isset( $_POST['icp_funding_stages'] ) ? array_map( 'sanitize_key', wp_unslash( (array) $_POST['icp_funding_stages'] ) ) : array();
		$profile['business_models'] = isset( $_POST['icp_business_models'] ) ? array_map( 'sanitize_key', wp_unslash( (array) $_POST['icp_business_models'] ) ) : array();

		// Technographics — textarea, split by newline.
		$profile['required_tech']   = isset( $_POST['icp_required_tech'] )
			? self::textarea_to_array( sanitize_textarea_field( wp_unslash( $_POST['icp_required_tech'] ) ) )
			: array();
		$profile['preferred_tech']  = isset( $_POST['icp_preferred_tech'] )
			? self::textarea_to_array( sanitize_textarea_field( wp_unslash( $_POST['icp_preferred_tech'] ) ) )
			: array();
		$profile['competitor_tech'] = isset( $_POST['icp_competitor_tech'] )
			? self::textarea_to_array( sanitize_textarea_field( wp_unslash( $_POST['icp_competitor_tech'] ) ) )
			: array();

		// Buying triggers — checkboxes + textarea.
		$profile['buying_triggers'] = isset( $_POST['icp_buying_triggers'] ) ? array_map( 'sanitize_key', wp_unslash( (array) $_POST['icp_buying_triggers'] ) ) : array();
		$profile['custom_triggers'] = isset( $_POST['icp_custom_triggers'] ) ? sanitize_textarea_field( wp_unslash( $_POST['icp_custom_triggers'] ) ) : '';

		// Macro trends.
		$profile['macro_trends']  = isset( $_POST['icp_macro_trends'] ) ? sanitize_textarea_field( wp_unslash( $_POST['icp_macro_trends'] ) ) : '';
		$profile['market_shifts'] = isset( $_POST['icp_market_shifts'] ) ? sanitize_textarea_field( wp_unslash( $_POST['icp_market_shifts'] ) ) : '';

		// Negative signals.
		$profile['excluded_industries']  = isset( $_POST['icp_excluded_industries'] ) ? array_map( 'sanitize_key', wp_unslash( (array) $_POST['icp_excluded_industries'] ) ) : array();
		$profile['excluded_geographies'] = isset( $_POST['icp_excluded_geographies'] ) ? array_map( 'sanitize_key', wp_unslash( (array) $_POST['icp_excluded_geographies'] ) ) : array();
		$profile['max_size']             = isset( $_POST['icp_max_size'] ) ? absint( wp_unslash( $_POST['icp_max_size'] ) ) : 0;
		$profile['min_revenue']          = isset( $_POST['icp_min_revenue'] ) ? absint( wp_unslash( $_POST['icp_min_revenue'] ) ) : 0;
		$profile['competitor_flag']      = ! empty( $_POST['icp_competitor_flag'] );
		$profile['custom_disqualifiers'] = isset( $_POST['icp_custom_disqualifiers'] ) ? sanitize_textarea_field( wp_unslash( $_POST['icp_custom_disqualifiers'] ) ) : '';

		// Scoring weights.
		$profile['weights'] = array(
			'firmographic_fit'    => 0,
			'technographic_fit'   => 0,
			'intent_signals'      => 0,
			'engagement_activity' => 0,
			'buying_triggers'     => 0,
			'economic_outcome'    => 0,
			'negative_signals'    => 0,
		);
		if ( isset( $_POST['icp_weights'] ) && is_array( $_POST['icp_weights'] ) ) {
			$raw_weights = array_map( 'absint', wp_unslash( $_POST['icp_weights'] ) );
			foreach ( $profile['weights'] as $dim => $val ) {
				if ( isset( $raw_weights[ $dim ] ) ) {
					$profile['weights'][ $dim ] = $raw_weights[ $dim ];
				}
			}
		}

		// Tier thresholds.
		$profile['tier_thresholds'] = array(
			'A' => isset( $_POST['icp_tier_a'] ) ? absint( wp_unslash( $_POST['icp_tier_a'] ) ) : 80,
			'B' => isset( $_POST['icp_tier_b'] ) ? absint( wp_unslash( $_POST['icp_tier_b'] ) ) : 60,
		);

		// Scorer-compatible derived fields.
		if ( ! empty( $profile['industries'] ) ) {
			$profile['industry'] = reset( $profile['industries'] );
		}
		if ( ! empty( $profile['geographies'] ) ) {
			$profile['country'] = implode( ', ', $profile['geographies'] );
			// Also store first geography as the primary region for the scorer.
			$profile['region'] = reset( $profile['geographies'] );
		}

		return $profile;
	}

	/**
	 * Validate a sanitised profile array.
	 *
	 * @since 2.11.0
	 *
	 * @param array $profile Sanitised profile data.
	 * @return string[] Error messages (empty if valid).
	 */
	private static function validate_profile( array $profile ) {
		$errors = array();

		if ( empty( trim( $profile['name'] ) ) ) {
			$errors[] = __( 'Profile name is required.', 'nvoos-content-graph-pro' );
		}

		$weight_sum = array_sum( $profile['weights'] );
		if ( 100 !== $weight_sum ) {
			$errors[] = sprintf(
				/* translators: %d: sum of weights */
				__( 'Scoring weights must sum to 100. Current sum: %d.', 'nvoos-content-graph-pro' ),
				$weight_sum
			);
		}

		$tier_a = isset( $profile['tier_thresholds']['A'] ) ? (int) $profile['tier_thresholds']['A'] : 80;
		$tier_b = isset( $profile['tier_thresholds']['B'] ) ? (int) $profile['tier_thresholds']['B'] : 60;

		if ( $tier_a <= $tier_b ) {
			$errors[] = __( 'Tier A threshold must be greater than Tier B threshold.', 'nvoos-content-graph-pro' );
		}

		if ( $tier_a < 0 || $tier_a > 100 ) {
			$errors[] = __( 'Tier A threshold must be between 0 and 100.', 'nvoos-content-graph-pro' );
		}

		if ( $tier_b < 0 || $tier_b > 100 ) {
			$errors[] = __( 'Tier B threshold must be between 0 and 100.', 'nvoos-content-graph-pro' );
		}

		return $errors;
	}

	// =====================================================================
	// Profile CRUD (option-backed)
	// =====================================================================

	/**
	 * Retrieve all ICP profiles.
	 *
	 * @since 2.11.0
	 *
	 * @return array<string,array> Slug → profile.
	 */
	private static function get_all_profiles() {
		$profiles = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $profiles ) ) {
			return array();
		}
		return $profiles;
	}

	/**
	 * Retrieve a single profile by slug.
	 *
	 * @since 2.11.0
	 *
	 * @param string $slug Profile slug.
	 * @return array Empty array if not found.
	 */
	private static function get_profile_by_slug( $slug ) {
		$profiles = self::get_all_profiles();
		return isset( $profiles[ $slug ] ) ? $profiles[ $slug ] : array();
	}

	// =====================================================================
	// Static Option Lists
	// =====================================================================

	/**
	 * Return common industry options for multi-select fields.
	 *
	 * @since 2.11.0
	 *
	 * @return array<string,string> Slug → label.
	 */
	public static function get_industry_options() {
		return array(
			'saas'              => __( 'SaaS', 'nvoos-content-graph-pro' ),
			'fintech'           => __( 'FinTech', 'nvoos-content-graph-pro' ),
			'healthtech'        => __( 'HealthTech', 'nvoos-content-graph-pro' ),
			'ecommerce'         => __( 'E-Commerce', 'nvoos-content-graph-pro' ),
			'manufacturing'     => __( 'Manufacturing', 'nvoos-content-graph-pro' ),
			'logistics'         => __( 'Logistics & Supply Chain', 'nvoos-content-graph-pro' ),
			'proptech'          => __( 'PropTech / Real Estate', 'nvoos-content-graph-pro' ),
			'edtech'            => __( 'EdTech', 'nvoos-content-graph-pro' ),
			'legaltech'         => __( 'LegalTech', 'nvoos-content-graph-pro' ),
			'cybersecurity'     => __( 'Cybersecurity', 'nvoos-content-graph-pro' ),
			'marketing_tech'    => __( 'Marketing Tech / AdTech', 'nvoos-content-graph-pro' ),
			'hrt'               => __( 'HR Tech', 'nvoos-content-graph-pro' ),
			'energy'            => __( 'Energy / CleanTech', 'nvoos-content-graph-pro' ),
			'government'        => __( 'Government / Public Sector', 'nvoos-content-graph-pro' ),
			'nonprofit'         => __( 'Nonprofit', 'nvoos-content-graph-pro' ),
			'professional_svcs' => __( 'Professional Services', 'nvoos-content-graph-pro' ),
			'telecom'           => __( 'Telecom', 'nvoos-content-graph-pro' ),
			'media'             => __( 'Media & Entertainment', 'nvoos-content-graph-pro' ),
			'automotive'        => __( 'Automotive / Mobility', 'nvoos-content-graph-pro' ),
			'agritech'          => __( 'AgriTech', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Return geography / region options for multi-select fields.
	 *
	 * @since 2.11.0
	 *
	 * @return array<string,string> Slug → label.
	 */
	public static function get_geography_options() {
		return array(
			'us'     => __( 'United States', 'nvoos-content-graph-pro' ),
			'ca'     => __( 'Canada', 'nvoos-content-graph-pro' ),
			'gb'     => __( 'United Kingdom', 'nvoos-content-graph-pro' ),
			'de'     => __( 'Germany', 'nvoos-content-graph-pro' ),
			'fr'     => __( 'France', 'nvoos-content-graph-pro' ),
			'jp'     => __( 'Japan', 'nvoos-content-graph-pro' ),
			'au'     => __( 'Australia', 'nvoos-content-graph-pro' ),
			'in'     => __( 'India', 'nvoos-content-graph-pro' ),
			'br'     => __( 'Brazil', 'nvoos-content-graph-pro' ),
			'sg'     => __( 'Singapore', 'nvoos-content-graph-pro' ),
			'nl'     => __( 'Netherlands', 'nvoos-content-graph-pro' ),
			'se'     => __( 'Sweden', 'nvoos-content-graph-pro' ),
			'ch'     => __( 'Switzerland', 'nvoos-content-graph-pro' ),
			'il'     => __( 'Israel', 'nvoos-content-graph-pro' ),
			'ae'     => __( 'United Arab Emirates', 'nvoos-content-graph-pro' ),
			'kr'     => __( 'South Korea', 'nvoos-content-graph-pro' ),
			'za'     => __( 'South Africa', 'nvoos-content-graph-pro' ),
			'mx'     => __( 'Mexico', 'nvoos-content-graph-pro' ),
			'es'     => __( 'Spain', 'nvoos-content-graph-pro' ),
			'it'     => __( 'Italy', 'nvoos-content-graph-pro' ),
			'eu'     => __( 'European Union (broad)', 'nvoos-content-graph-pro' ),
			'apac'   => __( 'Asia-Pacific (broad)', 'nvoos-content-graph-pro' ),
			'latam'  => __( 'Latin America (broad)', 'nvoos-content-graph-pro' ),
			'mena'   => __( 'MENA (broad)', 'nvoos-content-graph-pro' ),
			'global' => __( 'Global', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Return funding stage options.
	 *
	 * @since 2.11.0
	 *
	 * @return array<string,string> Slug → label.
	 */
	public static function get_funding_stage_options() {
		return array(
			'pre_seed'       => __( 'Pre-Seed', 'nvoos-content-graph-pro' ),
			'seed'           => __( 'Seed', 'nvoos-content-graph-pro' ),
			'series_a'       => __( 'Series A', 'nvoos-content-graph-pro' ),
			'series_b'       => __( 'Series B', 'nvoos-content-graph-pro' ),
			'series_c_plus'  => __( 'Series C+', 'nvoos-content-graph-pro' ),
			'private_equity' => __( 'Private Equity', 'nvoos-content-graph-pro' ),
			'public'         => __( 'Public', 'nvoos-content-graph-pro' ),
			'bootstrapped'   => __( 'Bootstrapped', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Return business model options.
	 *
	 * @since 2.11.0
	 *
	 * @return array<string,string> Slug → label.
	 */
	public static function get_business_model_options() {
		return array(
			'b2b_saas'          => __( 'B2B SaaS', 'nvoos-content-graph-pro' ),
			'b2c_saas'          => __( 'B2C SaaS', 'nvoos-content-graph-pro' ),
			'marketplace'       => __( 'Marketplace', 'nvoos-content-graph-pro' ),
			'subscription'      => __( 'Subscription', 'nvoos-content-graph-pro' ),
			'transactional'     => __( 'Transactional', 'nvoos-content-graph-pro' ),
			'advertising'       => __( 'Advertising-based', 'nvoos-content-graph-pro' ),
			'usage_based'       => __( 'Usage-based', 'nvoos-content-graph-pro' ),
			'professional_svcs' => __( 'Professional Services', 'nvoos-content-graph-pro' ),
			'manufacturing'     => __( 'Manufacturing', 'nvoos-content-graph-pro' ),
		);
	}

	// =====================================================================
	// Utility Methods
	// =====================================================================

	/**
	 * Count how many dimension groups have non-empty configuration.
	 *
	 * Used in the profiles list table to show at a glance how thoroughly
	 * each profile has been configured.
	 *
	 * @since 2.11.0
	 *
	 * @param array $profile ICP profile.
	 * @return string Formatted count (e.g. "5 of 7").
	 */
	public static function format_dimension_count( array $profile ) {
		$dimensions = array(
			'firmographic'    => array( 'industries', 'geographies', 'employees_min', 'employees_max', 'revenue_min', 'revenue_max', 'funding_stages', 'business_models' ),
			'technographic'   => array( 'required_tech', 'preferred_tech', 'competitor_tech' ),
			'buying_triggers' => array( 'buying_triggers', 'custom_triggers' ),
			'macro_trends'    => array( 'macro_trends', 'market_shifts' ),
			'negative'        => array( 'excluded_industries', 'excluded_geographies', 'max_size', 'min_revenue', 'custom_disqualifiers' ),
			'weights'         => array( 'weights' ),
			'thresholds'      => array( 'tier_thresholds' ),
		);

		$configured = 0;

		foreach ( $dimensions as $dim_keys ) {
			$has_data = false;
			foreach ( $dim_keys as $key ) {
				if ( ! isset( $profile[ $key ] ) ) {
					continue;
				}
				$value = $profile[ $key ];
				if ( is_array( $value ) && ! empty( $value ) ) {
					// For weights, check if non-zero.
					if ( 'weights' === $key ) {
						if ( array_sum( $value ) > 0 ) {
							$has_data = true;
							break;
						}
					} else {
						$has_data = true;
						break;
					}
				} elseif ( is_string( $value ) && '' !== trim( $value ) ) {
					$has_data = true;
					break;
				} elseif ( is_int( $value ) && 0 !== $value ) {
					$has_data = true;
					break;
				} elseif ( is_bool( $value ) && true === $value ) {
					$has_data = true;
					break;
				}
			}
			if ( $has_data ) {
				++$configured;
			}
		}

		return sprintf(
			/* translators: 1: configured count, 2: total count */
			__( '%1$d of %2$d', 'nvoos-content-graph-pro' ),
			$configured,
			count( $dimensions )
		);
	}

	/**
	 * Render a collapsible dimension section on the editor form.
	 *
	 * @since 2.11.0
	 *
	 * @param string   $title    Section title.
	 * @param string   $slug     Section slug (used for toggle ID).
	 * @param callable $renderer Callback that renders the section body.
	 * @return void
	 */
	private static function render_dimension_section( $title, $slug, $renderer ) {
		?>
		<div class="postbox" style="margin-top:12px;">
			<div class="postbox-header">
				<h2 class="hndle" style="cursor:pointer;"
					onclick="var b=document.getElementById('icp-section-<?php echo esc_js( $slug ); ?>');b.style.display=b.style.display==='none'?'block':'none';">
					<?php echo esc_html( $title ); ?>
				</h2>
			</div>
			<div id="icp-section-<?php echo esc_attr( $slug ); ?>" class="inside" style="padding:0 12px 12px;">
				<?php call_user_func( $renderer ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a textarea form field.
	 *
	 * @since 2.11.0
	 *
	 * @param string $name        Field name attribute.
	 * @param string $label       Accessibility label.
	 * @param string $value       Current value.
	 * @param string $placeholder Placeholder text.
	 * @return void
	 */
	private static function render_textarea_field( $name, $label, $value, $placeholder = '' ) {
		?>
		<textarea id="<?php echo esc_attr( $name ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					class="large-text code"
					rows="5"
					aria-label="<?php echo esc_attr( $label ); ?>"
					placeholder="<?php echo esc_attr( $placeholder ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
		<?php
	}

	/**
	 * Render a single checkbox form field.
	 *
	 * @since 2.11.0
	 *
	 * @param string $name    Field name attribute.
	 * @param string $label   Label text.
	 * @param bool   $checked Whether the checkbox is checked.
	 * @return void
	 */
	private static function render_checkbox_field( $name, $label, $checked ) {
		?>
		<label>
			<input type="checkbox"
					id="<?php echo esc_attr( $name ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="1"
					<?php checked( $checked ); ?> />
			<?php echo esc_html( $label ); ?>
		</label>
		<?php
	}

	/**
	 * Render a number input form field.
	 *
	 * @since 2.11.0
	 *
	 * @param string $name  Field name attribute.
	 * @param string $label Accessibility label.
	 * @param int    $value Current value.
	 * @param int    $min   Minimum allowed value.
	 * @param int    $max   Maximum allowed value.
	 * @return void
	 */
	private static function render_number_field( $name, $label, $value, $min, $max ) {
		?>
		<input type="number"
				id="<?php echo esc_attr( $name ); ?>"
				name="<?php echo esc_attr( $name ); ?>"
				class="small-text"
				value="<?php echo esc_attr( $value ); ?>"
				min="<?php echo esc_attr( $min ); ?>"
				max="<?php echo esc_attr( $max ); ?>"
				step="1"
				aria-label="<?php echo esc_attr( $label ); ?>" />
		<?php
	}

	/**
	 * Render a multi-checkbox group.
	 *
	 * Each checkbox is rendered as a `<label>` wrapping an `<input>`, laid
	 * out in a compact grid for scanability.
	 *
	 * @since 2.11.0
	 *
	 * @param string               $name     Field name attribute (brackets auto-appended).
	 * @param string               $label    Group label for accessibility.
	 * @param array<string,string> $options  Slug → label pairs.
	 * @param array                $selected Array of selected slugs.
	 * @return void
	 */
	private static function render_multi_checkbox( $name, $label, $options, $selected ) {
		?>
		<fieldset aria-label="<?php echo esc_attr( $label ); ?>" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:4px 12px;">
			<?php foreach ( $options as $slug => $opt_label ) : ?>
				<label style="display:flex;align-items:center;gap:4px;font-weight:normal;margin:0;white-space:nowrap;">
					<input type="checkbox"
							name="<?php echo esc_attr( $name ); ?>[]"
							value="<?php echo esc_attr( $slug ); ?>"
							<?php checked( in_array( $slug, $selected, true ) ); ?> />
					<?php echo esc_html( $opt_label ); ?>
				</label>
			<?php endforeach; ?>
		</fieldset>
		<?php
	}

	/**
	 * Convert a textarea value (one entry per line) to an array.
	 *
	 * Blank lines and whitespace-only lines are stripped.
	 *
	 * @since 2.11.0
	 *
	 * @param string $text Raw textarea value.
	 * @return string[] Array of non-empty trimmed lines.
	 */
	private static function textarea_to_array( $text ) {
		$lines = explode( "\n", $text );
		$lines = array_map( 'trim', $lines );
		$lines = array_filter(
			$lines,
			function ( $line ) {
				return '' !== $line;
			}
		);
		return array_values( $lines );
	}
}
