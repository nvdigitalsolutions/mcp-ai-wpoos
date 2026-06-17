<?php
/**
 * Health and Wellness Meta Boxes — WP Admin form fields for all health CPTs.
 *
 * Adds structured metadata panels, save hooks, and admin list columns for
 * every health-and-wellness CPT: Members, Policies, Medical Records,
 * Checkups, Prescriptions, and Allergies.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages WP Admin meta boxes and list columns for all health & wellness CPTs.
 */
class WP_MCP_AI_Health_Wellness_Meta_Boxes {

	/**
	 * Nonce action for meta-box saves.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'wp_mcp_ai_hw_meta_box';

	/**
	 * Nonce field name.
	 *
	 * @var string
	 */
	const NONCE_FIELD = 'wp_mcp_ai_hw_meta_box_nonce';

	/**
	 * Route of administration options.
	 *
	 * @var array
	 */
	const ROUTES = array(
		''            => '— Select —',
		'oral'        => 'Oral',
		'sublingual'  => 'Sublingual',
		'topical'     => 'Topical',
		'transdermal' => 'Transdermal',
		'injection'   => 'Injection',
		'inhalation'  => 'Inhalation',
		'ophthalmic'  => 'Ophthalmic (Eye)',
		'otic'        => 'Otic (Ear)',
		'nasal'       => 'Nasal',
		'rectal'      => 'Rectal',
		'vaginal'     => 'Vaginal',
		'other'       => 'Other',
	);

	/**
	 * Quantity unit options.
	 *
	 * @var array
	 */
	const QUANTITY_UNITS = array(
		''              => '— Select —',
		'tablets'       => 'Tablets',
		'capsules'      => 'Capsules',
		'ml'            => 'mL',
		'mg'            => 'mg',
		'patches'       => 'Patches',
		'drops'         => 'Drops',
		'puffs'         => 'Puffs',
		'units'         => 'Units (IU)',
		'suppositories' => 'Suppositories',
		'other'         => 'Other',
	);

	/**
	 * Allergy type options.
	 *
	 * @var array
	 */
	const ALLERGY_TYPES = array(
		''              => '— Select —',
		'food'          => 'Food',
		'drug'          => 'Drug / Medication',
		'environmental' => 'Environmental',
		'contact'       => 'Contact',
		'latex'         => 'Latex',
		'insect'        => 'Insect',
		'other'         => 'Other',
	);

	/**
	 * Allergy onset type options.
	 *
	 * @var array
	 */
	const ONSET_TYPES = array(
		''          => '— Select —',
		'immediate' => 'Immediate (< 1 hour)',
		'delayed'   => 'Delayed (> 1 hour)',
		'unknown'   => 'Unknown',
	);

	/**
	 * Insurance plan type options.
	 *
	 * @var array
	 */
	const PLAN_TYPES = array(
		''         => '— Select —',
		'hmo'      => 'HMO',
		'ppo'      => 'PPO',
		'epo'      => 'EPO',
		'pos'      => 'POS',
		'hdhp'     => 'HDHP',
		'medicaid' => 'Medicaid',
		'medicare' => 'Medicare',
		'tricare'  => 'TRICARE',
		'other'    => 'Other',
	);

	/**
	 * Register all hooks.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta_fields' ), 10, 2 );

		// ── Member columns ──────────────────────────────────────────────────
		add_filter( 'manage_mcp_ai_member_posts_columns', array( __CLASS__, 'member_columns' ) );
		add_action( 'manage_mcp_ai_member_posts_custom_column', array( __CLASS__, 'render_member_column' ), 10, 2 );

		// ── Policy columns ───────────────────────────────────────────────────
		add_filter( 'manage_mcp_ai_policy_posts_columns', array( __CLASS__, 'policy_columns' ) );
		add_action( 'manage_mcp_ai_policy_posts_custom_column', array( __CLASS__, 'render_policy_column' ), 10, 2 );

		// ── Medical Record columns ────────────────────────────────────────────
		add_filter( 'manage_mcp_ai_med_record_posts_columns', array( __CLASS__, 'med_record_columns' ) );
		add_action( 'manage_mcp_ai_med_record_posts_custom_column', array( __CLASS__, 'render_med_record_column' ), 10, 2 );

		// ── Checkup columns ───────────────────────────────────────────────────
		add_filter( 'manage_mcp_ai_checkup_posts_columns', array( __CLASS__, 'checkup_columns' ) );
		add_action( 'manage_mcp_ai_checkup_posts_custom_column', array( __CLASS__, 'render_checkup_column' ), 10, 2 );

		// ── Prescription columns ──────────────────────────────────────────────
		add_filter( 'manage_mcp_ai_prescription_posts_columns', array( __CLASS__, 'prescription_columns' ) );
		add_action( 'manage_mcp_ai_prescription_posts_custom_column', array( __CLASS__, 'render_prescription_column' ), 10, 2 );

		// ── Allergy columns ───────────────────────────────────────────────────
		add_filter( 'manage_mcp_ai_allergy_posts_columns', array( __CLASS__, 'allergy_columns' ) );
		add_action( 'manage_mcp_ai_allergy_posts_custom_column', array( __CLASS__, 'render_allergy_column' ), 10, 2 );
	}

	// =========================================================================
	// META BOX REGISTRATION
	// =========================================================================

	/**
	 * Register meta boxes for every health CPT.
	 */
	public static function register_meta_boxes() {
		add_meta_box(
			'mcp_ai_member_details',
			__( 'Member Details', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_member_meta_box' ),
			'mcp_ai_member',
			'normal',
			'high'
		);

		add_meta_box(
			'mcp_ai_policy_details',
			__( 'Policy Details', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_policy_meta_box' ),
			'mcp_ai_policy',
			'normal',
			'high'
		);

		add_meta_box(
			'mcp_ai_med_record_details',
			__( 'Record Details', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_med_record_meta_box' ),
			'mcp_ai_med_record',
			'normal',
			'high'
		);

		add_meta_box(
			'mcp_ai_checkup_details',
			__( 'Checkup Details', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_checkup_meta_box' ),
			'mcp_ai_checkup',
			'normal',
			'high'
		);

		add_meta_box(
			'mcp_ai_prescription_details',
			__( 'Prescription Details', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_prescription_meta_box' ),
			'mcp_ai_prescription',
			'normal',
			'high'
		);

		add_meta_box(
			'mcp_ai_allergy_details',
			__( 'Allergy Details', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_allergy_meta_box' ),
			'mcp_ai_allergy',
			'normal',
			'high'
		);
	}

	// =========================================================================
	// RENDER HELPERS
	// =========================================================================

	/**
	 * Output the shared nonce field. Call once per meta box render.
	 */
	private static function nonce_field() {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
	}

	/**
	 * Render a text input row.
	 *
	 * @param string $label     Field label.
	 * @param string $id        Input id/name.
	 * @param string $value     Current value.
	 * @param string $type      HTML input type (text, email, tel, number, date).
	 * @param string $placeholder Placeholder text.
	 * @param bool   $required  Whether the field is required.
	 */
	private static function text_field( $label, $id, $value, $type = 'text', $placeholder = '', $required = false ) {
		?>
		<tr class="hw-meta-row">
			<th scope="row">
				<label for="<?php echo esc_attr( $id ); ?>">
					<?php echo esc_html( $label ); ?>
					<?php if ( $required ) : ?>
						<span class="hw-required" aria-label="<?php esc_attr_e( 'Required', 'mcp-ai-wpoos-pro' ); ?>">*</span>
					<?php endif; ?>
				</label>
			</th>
			<td>
				<input
					type="<?php echo esc_attr( $type ); ?>"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $id ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
					class="regular-text"
					<?php
					if ( $placeholder ) :
						?>
						placeholder="<?php echo esc_attr( $placeholder ); ?>"<?php endif; ?>
					<?php
					if ( $required ) :
						?>
						required<?php endif; ?>
				/>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render a textarea row.
	 *
	 * @param string $label     Field label.
	 * @param string $id        Input id/name.
	 * @param string $value     Current value.
	 * @param int    $rows      Number of rows.
	 * @param string $placeholder Placeholder text.
	 */
	private static function textarea_field( $label, $id, $value, $rows = 4, $placeholder = '' ) {
		?>
		<tr class="hw-meta-row">
			<th scope="row">
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			</th>
			<td>
				<textarea
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $id ); ?>"
					rows="<?php echo absint( $rows ); ?>"
					class="large-text"
					<?php
					if ( $placeholder ) :
						?>
						placeholder="<?php echo esc_attr( $placeholder ); ?>"<?php endif; ?>
				><?php echo esc_textarea( $value ); ?></textarea>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render a select dropdown row.
	 *
	 * @param string $label    Field label.
	 * @param string $id       Input id/name.
	 * @param string $current  Currently selected value.
	 * @param array  $options  Associative array of value => label.
	 * @param bool   $required Whether the field is required.
	 */
	private static function select_field( $label, $id, $current, array $options, $required = false ) {
		?>
		<tr class="hw-meta-row">
			<th scope="row">
				<label for="<?php echo esc_attr( $id ); ?>">
					<?php echo esc_html( $label ); ?>
					<?php if ( $required ) : ?>
						<span class="hw-required" aria-label="<?php esc_attr_e( 'Required', 'mcp-ai-wpoos-pro' ); ?>">*</span>
					<?php endif; ?>
				</label>
			</th>
			<td>
				<select
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $id ); ?>"
					class="regular-text"
					<?php
					if ( $required ) :
						?>
						required<?php endif; ?>
				>
					<?php foreach ( $options as $val => $lbl ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $current, $val ); ?>>
							<?php echo esc_html( $lbl ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render a checkbox row.
	 *
	 * @param string $label   Field label.
	 * @param string $id      Input id/name.
	 * @param bool   $checked Whether checked.
	 * @param string $description Optional description below.
	 */
	private static function checkbox_field( $label, $id, $checked, $description = '' ) {
		?>
		<tr class="hw-meta-row">
			<th scope="row">
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			</th>
			<td>
				<input
					type="checkbox"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $id ); ?>"
					value="1"
					<?php checked( $checked ); ?>
				/>
				<?php if ( $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render a member select row (dropdown of all mcp_ai_member posts).
	 *
	 * @param string $id      Input id/name.
	 * @param int    $current Currently selected member ID.
	 * @param bool   $required Whether the field is required.
	 */
	private static function member_select_field( $id, $current, $required = true ) {
		$members = get_posts(
			array(
				'post_type'      => 'mcp_ai_member',
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		?>
		<tr class="hw-meta-row">
			<th scope="row">
				<label for="<?php echo esc_attr( $id ); ?>">
					<?php esc_html_e( 'Member', 'mcp-ai-wpoos-pro' ); ?>
					<?php if ( $required ) : ?>
						<span class="hw-required" aria-label="<?php esc_attr_e( 'Required', 'mcp-ai-wpoos-pro' ); ?>">*</span>
					<?php endif; ?>
				</label>
			</th>
			<td>
				<select
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $id ); ?>"
					class="regular-text"
					<?php
					if ( $required ) :
						?>
						required<?php endif; ?>
				>
					<option value=""><?php esc_html_e( '— Select Member —', 'mcp-ai-wpoos-pro' ); ?></option>
					<?php foreach ( $members as $member ) : ?>
						<option value="<?php echo absint( $member->ID ); ?>" <?php selected( $current, $member->ID ); ?>>
							<?php echo esc_html( $member->post_title ); ?> (#<?php echo absint( $member->ID ); ?>)
						</option>
					<?php endforeach; ?>
				</select>
				<?php if ( empty( $members ) ) : ?>
					<p class="description">
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: link to add member */
								__( 'No members found. <a href="%s">Add a Member</a> first.', 'mcp-ai-wpoos-pro' ),
								esc_url( admin_url( 'post-new.php?post_type=mcp_ai_member' ) )
							)
						);
						?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render a section sub-heading inside a meta box table.
	 *
	 * @param string $heading The section heading text.
	 */
	private static function section_heading( $heading ) {
		?>
		<tr class="hw-meta-section-row">
			<td colspan="2"><h4 class="hw-meta-section-heading"><?php echo esc_html( $heading ); ?></h4></td>
		</tr>
		<?php
	}

	// =========================================================================
	// RENDER: MEMBER
	// =========================================================================

	/**
	 * Render the Member Details meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render_member_meta_box( $post ) {
		self::nonce_field();

		$dob               = get_post_meta( $post->ID, '_member_date_of_birth', true );
		$gender            = get_post_meta( $post->ID, '_member_gender', true );
		$blood_type        = get_post_meta( $post->ID, '_member_blood_type', true );
		$email             = get_post_meta( $post->ID, '_member_email', true );
		$phone             = get_post_meta( $post->ID, '_member_phone', true );
		$address           = get_post_meta( $post->ID, '_member_address', true );
		$emergency_contact = get_post_meta( $post->ID, '_member_emergency_contact', true );
		$mrn               = get_post_meta( $post->ID, '_member_mrn', true );
		$pref_pharmacy     = get_post_meta( $post->ID, '_member_preferred_pharmacy', true );
		$species           = get_post_meta( $post->ID, '_pet_species', true );
		$breed             = get_post_meta( $post->ID, '_pet_breed', true );

		$blood_types = array(
			''    => '— Select —',
			'A+'  => 'A+',
			'A-'  => 'A-',
			'B+'  => 'B+',
			'B-'  => 'B-',
			'AB+' => 'AB+',
			'AB-' => 'AB-',
			'O+'  => 'O+',
			'O-'  => 'O-',
		);

		?>
		<table class="hw-meta-table widefat">
			<tbody>
				<?php self::section_heading( __( 'Demographics', 'mcp-ai-wpoos-pro' ) ); ?>
				<?php self::text_field( __( 'Date of Birth', 'mcp-ai-wpoos-pro' ), '_member_date_of_birth', $dob, 'date' ); ?>
				<?php
				self::text_field( __( 'Gender', 'mcp-ai-wpoos-pro' ), '_member_gender', $gender, 'text', __( 'e.g. Male, Female, Non-binary', 'mcp-ai-wpoos-pro' ) );
				self::select_field( __( 'Blood Type', 'mcp-ai-wpoos-pro' ), '_member_blood_type', $blood_type, $blood_types );
				?>

				<?php self::section_heading( __( 'Contact Information', 'mcp-ai-wpoos-pro' ) ); ?>
				<?php
				self::text_field( __( 'Email', 'mcp-ai-wpoos-pro' ), '_member_email', $email, 'email' );
				self::text_field( __( 'Phone', 'mcp-ai-wpoos-pro' ), '_member_phone', $phone, 'tel', __( 'e.g. (555) 123-4567', 'mcp-ai-wpoos-pro' ) );
				self::textarea_field( __( 'Address', 'mcp-ai-wpoos-pro' ), '_member_address', $address, 3, __( 'Street, City, State, ZIP', 'mcp-ai-wpoos-pro' ) );
				self::textarea_field( __( 'Emergency Contact', 'mcp-ai-wpoos-pro' ), '_member_emergency_contact', $emergency_contact, 3, __( 'Name, relationship, phone', 'mcp-ai-wpoos-pro' ) );
				?>

				<?php self::section_heading( __( 'Healthcare Identifiers', 'mcp-ai-wpoos-pro' ) ); ?>
				<?php
				self::text_field( __( 'Medical Record # (MRN)', 'mcp-ai-wpoos-pro' ), '_member_mrn', $mrn, 'text', __( 'Internal or provider-assigned MRN', 'mcp-ai-wpoos-pro' ) );
				self::text_field( __( 'Preferred Pharmacy', 'mcp-ai-wpoos-pro' ), '_member_preferred_pharmacy', $pref_pharmacy, 'text', __( 'Name and address of preferred pharmacy', 'mcp-ai-wpoos-pro' ) );
				?>

				<?php self::section_heading( __( 'Pet Information (if applicable)', 'mcp-ai-wpoos-pro' ) ); ?>
				<?php
				self::text_field( __( 'Species', 'mcp-ai-wpoos-pro' ), '_pet_species', $species, 'text', __( 'e.g. Dog, Cat, Bird', 'mcp-ai-wpoos-pro' ) );
				self::text_field( __( 'Breed', 'mcp-ai-wpoos-pro' ), '_pet_breed', $breed, 'text', __( 'e.g. Golden Retriever', 'mcp-ai-wpoos-pro' ) );
				?>
			</tbody>
		</table>
		<?php
	}

	// =========================================================================
	// RENDER: POLICY
	// =========================================================================

	/**
	 * Render the Policy Details meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render_policy_meta_box( $post ) {
		self::nonce_field();

		$member_id        = get_post_meta( $post->ID, '_policy_member_id', true );
		$policy_number    = get_post_meta( $post->ID, '_policy_number', true );
		$provider         = get_post_meta( $post->ID, '_policy_provider', true );
		$status           = get_post_meta( $post->ID, '_policy_status', true );
		$effective_date   = get_post_meta( $post->ID, '_policy_effective_date', true );
		$expiration_date  = get_post_meta( $post->ID, '_policy_expiration_date', true );
		$premium          = get_post_meta( $post->ID, '_policy_premium', true );
		$group_number     = get_post_meta( $post->ID, '_policy_group_number', true );
		$plan_type        = get_post_meta( $post->ID, '_policy_plan_type', true );
		$copay_primary    = get_post_meta( $post->ID, '_policy_copay_primary', true );
		$copay_specialist = get_post_meta( $post->ID, '_policy_copay_specialist', true );
		$deductible       = get_post_meta( $post->ID, '_policy_deductible', true );
		$oop_max          = get_post_meta( $post->ID, '_policy_out_of_pocket_max', true );
		$rx_bin           = get_post_meta( $post->ID, '_policy_rx_bin', true );
		$rx_pcn           = get_post_meta( $post->ID, '_policy_rx_pcn', true );
		$rx_group         = get_post_meta( $post->ID, '_policy_rx_group', true );

		$status_options = array(
			'active'    => __( 'Active', 'mcp-ai-wpoos-pro' ),
			'expired'   => __( 'Expired', 'mcp-ai-wpoos-pro' ),
			'pending'   => __( 'Pending', 'mcp-ai-wpoos-pro' ),
			'cancelled' => __( 'Cancelled', 'mcp-ai-wpoos-pro' ),
		);

		?>
		<table class="hw-meta-table widefat">
			<tbody>
				<?php self::section_heading( __( 'Policy Identification', 'mcp-ai-wpoos-pro' ) ); ?>
				<?php
				self::member_select_field( '_policy_member_id', absint( $member_id ) );
				self::text_field( __( 'Policy Number', 'mcp-ai-wpoos-pro' ), '_policy_policy_number', $policy_number, 'text', '', true );
				self::text_field( __( 'Group Number', 'mcp-ai-wpoos-pro' ), '_policy_group_number', $group_number, 'text', __( 'Group / employer ID', 'mcp-ai-wpoos-pro' ) );
				self::text_field( __( 'Insurance Provider', 'mcp-ai-wpoos-pro' ), '_policy_provider', $provider, 'text', __( 'e.g. Blue Cross Blue Shield', 'mcp-ai-wpoos-pro' ) );
				self::select_field( __( 'Plan Type', 'mcp-ai-wpoos-pro' ), '_policy_plan_type', $plan_type, self::PLAN_TYPES );
				self::select_field( __( 'Status', 'mcp-ai-wpoos-pro' ), '_policy_status', $status ? $status : 'active', $status_options );
				?>

				<?php self::section_heading( __( 'Coverage Dates & Cost', 'mcp-ai-wpoos-pro' ) ); ?>
				<?php
				self::text_field( __( 'Effective Date', 'mcp-ai-wpoos-pro' ), '_policy_effective_date', $effective_date, 'date' );
				self::text_field( __( 'Expiration Date', 'mcp-ai-wpoos-pro' ), '_policy_expiration_date', $expiration_date, 'date' );
				self::text_field( __( 'Monthly Premium', 'mcp-ai-wpoos-pro' ), '_policy_premium', $premium, 'text', __( 'e.g. $250.00/mo', 'mcp-ai-wpoos-pro' ) );
				self::text_field( __( 'Annual Deductible', 'mcp-ai-wpoos-pro' ), '_policy_deductible', $deductible, 'text', __( 'e.g. $1,500', 'mcp-ai-wpoos-pro' ) );
				self::text_field( __( 'Out-of-Pocket Maximum', 'mcp-ai-wpoos-pro' ), '_policy_out_of_pocket_max', $oop_max, 'text', __( 'e.g. $5,000', 'mcp-ai-wpoos-pro' ) );
				self::text_field( __( 'PCP Copay', 'mcp-ai-wpoos-pro' ), '_policy_copay_primary', $copay_primary, 'text', __( 'e.g. $20', 'mcp-ai-wpoos-pro' ) );
				self::text_field( __( 'Specialist Copay', 'mcp-ai-wpoos-pro' ), '_policy_copay_specialist', $copay_specialist, 'text', __( 'e.g. $50', 'mcp-ai-wpoos-pro' ) );
				?>

				<?php self::section_heading( __( 'Pharmacy Benefit', 'mcp-ai-wpoos-pro' ) ); ?>
				<?php
				self::text_field( __( 'Rx BIN', 'mcp-ai-wpoos-pro' ), '_policy_rx_bin', $rx_bin, 'text', __( '6-digit pharmacy BIN number', 'mcp-ai-wpoos-pro' ) );
				self::text_field( __( 'Rx PCN', 'mcp-ai-wpoos-pro' ), '_policy_rx_pcn', $rx_pcn, 'text', __( 'Processor control number', 'mcp-ai-wpoos-pro' ) );
				self::text_field( __( 'Rx Group', 'mcp-ai-wpoos-pro' ), '_policy_rx_group', $rx_group, 'text', __( 'Pharmacy group / plan ID', 'mcp-ai-wpoos-pro' ) );
				?>
			</tbody>
		</table>
		<?php
	}

	// =========================================================================
	// RENDER: MEDICAL RECORD
	// =========================================================================

	/**
	 * Render the Medical Record Details meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render_med_record_meta_box( $post ) {
		self::nonce_field();

		$member_id    = get_post_meta( $post->ID, '_medical_record_member_id', true );
		$date         = get_post_meta( $post->ID, '_medical_record_date', true );
		$provider     = get_post_meta( $post->ID, '_medical_record_provider', true );
		$icd_code     = get_post_meta( $post->ID, '_medical_record_icd_code', true );
		$lab_value    = get_post_meta( $post->ID, '_medical_record_lab_value', true );
		$lab_unit     = get_post_meta( $post->ID, '_medical_record_lab_unit', true );
		$lab_ref      = get_post_meta( $post->ID, '_medical_record_lab_reference_range', true );
		$lab_abnormal = (bool) get_post_meta( $post->ID, '_medical_record_lab_abnormal', true );

		?>
		<table class="hw-meta-table widefat">
			<tbody>
				<?php self::section_heading( __( 'Record Information', 'mcp-ai-wpoos-pro' ) ); ?>
				<?php
				self::member_select_field( '_medical_record_member_id', absint( $member_id ) );
				self::text_field( __( 'Record Date', 'mcp-ai-wpoos-pro' ), '_medical_record_date', $date, 'date' );
				self::text_field( __( 'Provider / Facility', 'mcp-ai-wpoos-pro' ), '_medical_record_provider', $provider, 'text', __( 'Doctor name or facility', 'mcp-ai-wpoos-pro' ) );
				self::text_field(
					__( 'ICD-10 / Diagnosis Code', 'mcp-ai-wpoos-pro' ),
					'_medical_record_icd_code',
					$icd_code,
					'text',
					__( 'e.g. J06.9, E11.9', 'mcp-ai-wpoos-pro' )
				);
				?>

				<?php self::section_heading( __( 'Lab Result Fields (for Lab-Result records)', 'mcp-ai-wpoos-pro' ) ); ?>
				<?php
				self::text_field( __( 'Result Value', 'mcp-ai-wpoos-pro' ), '_medical_record_lab_value', $lab_value, 'text', __( 'e.g. 5.4', 'mcp-ai-wpoos-pro' ) );
				self::text_field( __( 'Unit', 'mcp-ai-wpoos-pro' ), '_medical_record_lab_unit', $lab_unit, 'text', __( 'e.g. mmol/L, mg/dL', 'mcp-ai-wpoos-pro' ) );
				self::text_field( __( 'Reference Range', 'mcp-ai-wpoos-pro' ), '_medical_record_lab_reference_range', $lab_ref, 'text', __( 'e.g. 3.5–5.0 mmol/L', 'mcp-ai-wpoos-pro' ) );
				self::checkbox_field(
					__( 'Abnormal Result', 'mcp-ai-wpoos-pro' ),
					'_medical_record_lab_abnormal',
					$lab_abnormal,
					__( 'Check if this result is outside the normal reference range.', 'mcp-ai-wpoos-pro' )
				);
				?>
			</tbody>
		</table>
		<?php
	}

	// =========================================================================
	// RENDER: CHECKUP
	// =========================================================================

	/**
	 * Render the Checkup Details meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render_checkup_meta_box( $post ) {
		self::nonce_field();

		$member_id       = get_post_meta( $post->ID, '_checkup_member_id', true );
		$datetime        = get_post_meta( $post->ID, '_checkup_datetime', true );
		$provider        = get_post_meta( $post->ID, '_checkup_provider', true );
		$location        = get_post_meta( $post->ID, '_checkup_location', true );
		$type            = get_post_meta( $post->ID, '_checkup_type', true );
		$status          = get_post_meta( $post->ID, '_checkup_status', true );
		$chief_complaint = get_post_meta( $post->ID, '_checkup_chief_complaint', true );
		$diagnosis       = get_post_meta( $post->ID, '_checkup_diagnosis', true );
		$duration        = get_post_meta( $post->ID, '_checkup_duration_minutes', true );
		$follow_up_date  = get_post_meta( $post->ID, '_checkup_follow_up_date', true );
		$copay           = get_post_meta( $post->ID, '_checkup_copay_amount', true );

		$type_options = array(
			''             => '— Select —',
			'wellness'     => __( 'Wellness', 'mcp-ai-wpoos-pro' ),
			'follow-up'    => __( 'Follow-Up', 'mcp-ai-wpoos-pro' ),
			'consultation' => __( 'Consultation', 'mcp-ai-wpoos-pro' ),
			'procedure'    => __( 'Procedure', 'mcp-ai-wpoos-pro' ),
			'vaccination'  => __( 'Vaccination', 'mcp-ai-wpoos-pro' ),
			'dental'       => __( 'Dental', 'mcp-ai-wpoos-pro' ),
			'vision'       => __( 'Vision', 'mcp-ai-wpoos-pro' ),
		);

		$status_options = array(
			'scheduled' => __( 'Scheduled', 'mcp-ai-wpoos-pro' ),
			'completed' => __( 'Completed', 'mcp-ai-wpoos-pro' ),
			'cancelled' => __( 'Cancelled', 'mcp-ai-wpoos-pro' ),
			'no-show'   => __( 'No-Show', 'mcp-ai-wpoos-pro' ),
		);

		?>
		<table class="hw-meta-table widefat">
			<tbody>
				<?php self::section_heading( __( 'Appointment Details', 'mcp-ai-wpoos-pro' ) ); ?>
				<?php
				self::member_select_field( '_checkup_member_id', absint( $member_id ) );
				?>
				<tr class="hw-meta-row">
					<th scope="row">
						<label for="_checkup_datetime">
							<?php esc_html_e( 'Date &amp; Time', 'mcp-ai-wpoos-pro' ); ?>
							<span class="hw-required">*</span>
						</label>
					</th>
					<td>
						<input
							type="datetime-local"
							id="_checkup_datetime"
							name="_checkup_datetime_local"
							value="<?php echo esc_attr( str_replace( ' ', 'T', $datetime ) ); ?>"
							class="regular-text"
						/>
						<input type="hidden" name="_checkup_datetime" id="_checkup_datetime_hidden" value="<?php echo esc_attr( $datetime ); ?>" />
						<p class="description"><?php esc_html_e( 'Format: YYYY-MM-DD HH:MM', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<?php
				self::text_field( __( 'Provider', 'mcp-ai-wpoos-pro' ), '_checkup_provider', $provider, 'text', __( 'Doctor / practitioner name', 'mcp-ai-wpoos-pro' ) );
				self::text_field( __( 'Location / Facility', 'mcp-ai-wpoos-pro' ), '_checkup_location', $location, 'text', __( 'Clinic or hospital name', 'mcp-ai-wpoos-pro' ) );
				self::select_field( __( 'Type', 'mcp-ai-wpoos-pro' ), '_checkup_type', $type, $type_options );
				self::select_field( __( 'Status', 'mcp-ai-wpoos-pro' ), '_checkup_status', $status ? $status : 'scheduled', $status_options );
				self::text_field( __( 'Duration (minutes)', 'mcp-ai-wpoos-pro' ), '_checkup_duration_minutes', $duration, 'number', __( 'e.g. 30', 'mcp-ai-wpoos-pro' ) );
				self::text_field( __( 'Copay Paid', 'mcp-ai-wpoos-pro' ), '_checkup_copay_amount', $copay, 'text', __( 'e.g. $25.00', 'mcp-ai-wpoos-pro' ) );
				?>

				<?php self::section_heading( __( 'Clinical Notes', 'mcp-ai-wpoos-pro' ) ); ?>
				<?php
				self::textarea_field( __( 'Chief Complaint', 'mcp-ai-wpoos-pro' ), '_checkup_chief_complaint', $chief_complaint, 3, __( 'Reason for the visit', 'mcp-ai-wpoos-pro' ) );
				self::textarea_field( __( 'Diagnosis / Assessment', 'mcp-ai-wpoos-pro' ), '_checkup_diagnosis', $diagnosis, 3, __( 'Working or final diagnosis', 'mcp-ai-wpoos-pro' ) );
				self::text_field( __( 'Follow-Up Date', 'mcp-ai-wpoos-pro' ), '_checkup_follow_up_date', $follow_up_date, 'date' );
				?>
			</tbody>
		</table>
		<script>
		( function() {
			var localInput = document.getElementById( '_checkup_datetime' );
			var hiddenInput = document.getElementById( '_checkup_datetime_hidden' );
			if ( localInput && hiddenInput ) {
				localInput.addEventListener( 'change', function () {
					hiddenInput.value = localInput.value.replace( 'T', ' ' );
				} );
			}
		} )();
		</script>
		<?php
	}

	// =========================================================================
	// RENDER: PRESCRIPTION
	// =========================================================================

	/**
	 * Render the Prescription Details meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render_prescription_meta_box( $post ) {
		self::nonce_field();

		$member_id      = get_post_meta( $post->ID, '_prescription_member_id', true );
		$dosage         = get_post_meta( $post->ID, '_prescription_dosage', true );
		$frequency      = get_post_meta( $post->ID, '_prescription_frequency', true );
		$status         = get_post_meta( $post->ID, '_prescription_status', true );
		$doctor         = get_post_meta( $post->ID, '_prescription_doctor', true );
		$start_date     = get_post_meta( $post->ID, '_prescription_start_date', true );
		$end_date       = get_post_meta( $post->ID, '_prescription_end_date', true );
		$refills        = get_post_meta( $post->ID, '_prescription_refills_remaining', true );
		$rx_number      = get_post_meta( $post->ID, '_prescription_rx_number', true );
		$ndc_code       = get_post_meta( $post->ID, '_prescription_ndc_code', true );
		$route          = get_post_meta( $post->ID, '_prescription_route', true );
		$quantity       = get_post_meta( $post->ID, '_prescription_quantity', true );
		$quantity_unit  = get_post_meta( $post->ID, '_prescription_quantity_unit', true );
		$indication     = get_post_meta( $post->ID, '_prescription_indication', true );
		$pharmacy_name  = get_post_meta( $post->ID, '_prescription_pharmacy_name', true );
		$pharmacy_phone = get_post_meta( $post->ID, '_prescription_pharmacy_phone', true );

		$status_options = array(
			'active'       => __( 'Active', 'mcp-ai-wpoos-pro' ),
			'completed'    => __( 'Completed', 'mcp-ai-wpoos-pro' ),
			'discontinued' => __( 'Discontinued', 'mcp-ai-wpoos-pro' ),
			'expired'      => __( 'Expired', 'mcp-ai-wpoos-pro' ),
		);

		?>
		<table class="hw-meta-table widefat">
			<tbody>
				<?php self::section_heading( __( 'Medication Details', 'mcp-ai-wpoos-pro' ) ); ?>
				<?php
				self::member_select_field( '_prescription_member_id', absint( $member_id ) );
				?>
				<tr class="hw-meta-row">
					<th scope="row">
						<label><?php esc_html_e( 'Medication Name', 'mcp-ai-wpoos-pro' ); ?> <span class="hw-required">*</span></label>
					</th>
					<td>
						<p class="description">
							<?php esc_html_e( 'Set the medication name using the Post Title field above.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
				<?php
				self::text_field( __( 'Dosage', 'mcp-ai-wpoos-pro' ), '_prescription_dosage', $dosage, 'text', __( 'e.g. 10mg, 500mg, 2 tablets', 'mcp-ai-wpoos-pro' ), true );
				self::text_field( __( 'Frequency', 'mcp-ai-wpoos-pro' ), '_prescription_frequency', $frequency, 'text', __( 'e.g. twice daily, every 8 hours', 'mcp-ai-wpoos-pro' ), true );
				self::text_field( __( 'Quantity', 'mcp-ai-wpoos-pro' ), '_prescription_quantity', $quantity, 'number', __( 'e.g. 30', 'mcp-ai-wpoos-pro' ) );
				self::select_field( __( 'Quantity Unit', 'mcp-ai-wpoos-pro' ), '_prescription_quantity_unit', $quantity_unit, self::QUANTITY_UNITS );
				self::select_field( __( 'Route of Administration', 'mcp-ai-wpoos-pro' ), '_prescription_route', $route, self::ROUTES );
				self::text_field( __( 'Indication / Reason', 'mcp-ai-wpoos-pro' ), '_prescription_indication', $indication, 'text', __( 'Condition being treated', 'mcp-ai-wpoos-pro' ) );
				self::select_field( __( 'Status', 'mcp-ai-wpoos-pro' ), '_prescription_status', $status ? $status : 'active', $status_options );
				?>

				<?php self::section_heading( __( 'Prescriber & Dates', 'mcp-ai-wpoos-pro' ) ); ?>
				<?php
				self::text_field( __( 'Prescribing Doctor', 'mcp-ai-wpoos-pro' ), '_prescription_doctor', $doctor, 'text', __( 'Full name of prescribing physician', 'mcp-ai-wpoos-pro' ) );
				self::text_field( __( 'Start Date', 'mcp-ai-wpoos-pro' ), '_prescription_start_date', $start_date, 'date' );
				self::text_field( __( 'End Date', 'mcp-ai-wpoos-pro' ), '_prescription_end_date', $end_date, 'date' );
				self::text_field( __( 'Refills Remaining', 'mcp-ai-wpoos-pro' ), '_prescription_refills_remaining', $refills, 'number', '0' );
				?>

				<?php self::section_heading( __( 'Pharmacy & Identifiers', 'mcp-ai-wpoos-pro' ) ); ?>
				<?php
				self::text_field( __( 'Rx / Prescription #', 'mcp-ai-wpoos-pro' ), '_prescription_rx_number', $rx_number, 'text', __( 'Pharmacy prescription number', 'mcp-ai-wpoos-pro' ) );
				self::text_field( __( 'NDC Code', 'mcp-ai-wpoos-pro' ), '_prescription_ndc_code', $ndc_code, 'text', __( 'National Drug Code (e.g. 0069-0010-01)', 'mcp-ai-wpoos-pro' ) );
				self::text_field( __( 'Pharmacy Name', 'mcp-ai-wpoos-pro' ), '_prescription_pharmacy_name', $pharmacy_name, 'text', __( 'Dispensing pharmacy name', 'mcp-ai-wpoos-pro' ) );
				self::text_field( __( 'Pharmacy Phone', 'mcp-ai-wpoos-pro' ), '_prescription_pharmacy_phone', $pharmacy_phone, 'tel', __( 'e.g. (555) 123-4567', 'mcp-ai-wpoos-pro' ) );
				?>
			</tbody>
		</table>
		<?php
	}

	// =========================================================================
	// RENDER: ALLERGY
	// =========================================================================

	/**
	 * Render the Allergy Details meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render_allergy_meta_box( $post ) {
		self::nonce_field();

		$member_id          = get_post_meta( $post->ID, '_allergy_member_id', true );
		$severity           = get_post_meta( $post->ID, '_allergy_severity', true );
		$allergy_type       = get_post_meta( $post->ID, '_allergy_type', true );
		$reactions          = get_post_meta( $post->ID, '_allergy_reactions', true );
		$onset_type         = get_post_meta( $post->ID, '_allergy_onset_type', true );
		$diagnosed_date     = get_post_meta( $post->ID, '_allergy_diagnosed_date', true );
		$last_reaction_date = get_post_meta( $post->ID, '_allergy_last_reaction_date', true );
		$treatment          = get_post_meta( $post->ID, '_allergy_treatment', true );

		$severity_options = array(
			''         => '— Select —',
			'mild'     => __( 'Mild', 'mcp-ai-wpoos-pro' ),
			'moderate' => __( 'Moderate', 'mcp-ai-wpoos-pro' ),
			'severe'   => __( 'Severe', 'mcp-ai-wpoos-pro' ),
		);

		?>
		<table class="hw-meta-table widefat">
			<tbody>
				<?php self::section_heading( __( 'Allergy Information', 'mcp-ai-wpoos-pro' ) ); ?>
				<?php
				self::member_select_field( '_allergy_member_id', absint( $member_id ) );
				?>
				<tr class="hw-meta-row">
					<th scope="row">
						<label><?php esc_html_e( 'Allergen', 'mcp-ai-wpoos-pro' ); ?> <span class="hw-required">*</span></label>
					</th>
					<td>
						<p class="description">
							<?php esc_html_e( 'Set the allergen name using the Post Title field above.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
				<?php
				self::select_field( __( 'Allergy Type', 'mcp-ai-wpoos-pro' ), '_allergy_type', $allergy_type, self::ALLERGY_TYPES, true );
				self::select_field( __( 'Severity', 'mcp-ai-wpoos-pro' ), 'hw_allergy_severity', $severity, $severity_options, true );
				self::select_field( __( 'Onset Type', 'mcp-ai-wpoos-pro' ), '_allergy_onset_type', $onset_type, self::ONSET_TYPES );
				self::textarea_field( __( 'Reactions / Symptoms', 'mcp-ai-wpoos-pro' ), '_allergy_reactions', $reactions, 3, __( 'Describe typical reactions', 'mcp-ai-wpoos-pro' ) );
				self::textarea_field( __( 'Treatment / Management', 'mcp-ai-wpoos-pro' ), '_allergy_treatment', $treatment, 3, __( 'e.g. EpiPen, antihistamine, avoid exposure', 'mcp-ai-wpoos-pro' ) );
				self::text_field( __( 'Diagnosed Date', 'mcp-ai-wpoos-pro' ), '_allergy_diagnosed_date', $diagnosed_date, 'date' );
				self::text_field( __( 'Last Reaction Date', 'mcp-ai-wpoos-pro' ), '_allergy_last_reaction_date', $last_reaction_date, 'date' );
				?>
			</tbody>
		</table>
		<?php
	}

	// =========================================================================
	// SAVE META FIELDS
	// =========================================================================

	/**
	 * Save all health & wellness meta fields.
	 *
	 * @param int     $post_id Post ID being saved.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_meta_fields( $post_id, $post ) {
		// Bail on autosave, AJAX, and revisions.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Verify nonce.
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE_FIELD ] ), self::NONCE_ACTION ) ) {
			return;
		}

		$post_type = get_post_type( $post_id );

		switch ( $post_type ) {
			case 'mcp_ai_member':
				self::save_member_meta( $post_id );
				break;

			case 'mcp_ai_policy':
				self::save_policy_meta( $post_id );
				break;

			case 'mcp_ai_med_record':
				self::save_med_record_meta( $post_id );
				break;

			case 'mcp_ai_checkup':
				self::save_checkup_meta( $post_id );
				break;

			case 'mcp_ai_prescription':
				self::save_prescription_meta( $post_id );
				break;

			case 'mcp_ai_allergy':
				self::save_allergy_meta( $post_id );
				break;
		}
	}

	/**
	 * Sanitize and save a text meta value.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $key      Meta key.
	 * @param string $sanitize Sanitization function name (default: sanitize_text_field).
	 */
	private static function save_text( $post_id, $key, $sanitize = 'sanitize_text_field' ) {
		if ( ! is_callable( $sanitize ) ) {
			$sanitize = 'sanitize_text_field';
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Sanitized below; nonce verified in save_post_meta().
		$value = isset( $_POST[ $key ] ) ? call_user_func( $sanitize, wp_unslash( $_POST[ $key ] ) ) : '';
		update_post_meta( $post_id, $key, $value );
	}

	/**
	 * Sanitize and save an integer meta value.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 */
	private static function save_int( $post_id, $key ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- absint sanitizes; nonce verified in save_post_meta().
		$value = isset( $_POST[ $key ] ) ? absint( $_POST[ $key ] ) : 0;
		update_post_meta( $post_id, $key, $value );
	}

	/**
	 * Sanitize and save a checkbox (0 or 1) meta value.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 */
	private static function save_checkbox( $post_id, $key ) {
		$value = ! empty( $_POST[ $key ] ) ? 1 : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Nonce verified in save_post_meta().
		update_post_meta( $post_id, $key, $value );
	}

	/**
	 * Save Member meta fields.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function save_member_meta( $post_id ) {
		self::save_text( $post_id, '_member_date_of_birth' );
		self::save_text( $post_id, '_member_gender' );
		self::save_text( $post_id, '_member_blood_type' );
		self::save_text( $post_id, '_member_email', 'sanitize_email' );
		self::save_text( $post_id, '_member_phone' );
		self::save_text( $post_id, '_member_address', 'sanitize_textarea_field' );
		self::save_text( $post_id, '_member_emergency_contact', 'sanitize_textarea_field' );
		self::save_text( $post_id, '_member_mrn' );
		self::save_text( $post_id, '_member_preferred_pharmacy' );
		self::save_text( $post_id, '_pet_species' );
		self::save_text( $post_id, '_pet_breed' );
	}

	/**
	 * Save Policy meta fields.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function save_policy_meta( $post_id ) {
		self::save_int( $post_id, '_policy_member_id' );
		self::save_text( $post_id, '_policy_policy_number' );
		// Map the admin form field name to the canonical meta key.
		$policy_number = get_post_meta( $post_id, '_policy_policy_number', true );
		update_post_meta( $post_id, '_policy_number', $policy_number );
		self::save_text( $post_id, '_policy_group_number' );
		self::save_text( $post_id, '_policy_provider' );
		self::save_text( $post_id, '_policy_plan_type' );
		self::save_text( $post_id, '_policy_status' );
		self::save_text( $post_id, '_policy_effective_date' );
		self::save_text( $post_id, '_policy_expiration_date' );
		self::save_text( $post_id, '_policy_premium' );
		self::save_text( $post_id, '_policy_deductible' );
		self::save_text( $post_id, '_policy_out_of_pocket_max' );
		self::save_text( $post_id, '_policy_copay_primary' );
		self::save_text( $post_id, '_policy_copay_specialist' );
		self::save_text( $post_id, '_policy_rx_bin' );
		self::save_text( $post_id, '_policy_rx_pcn' );
		self::save_text( $post_id, '_policy_rx_group' );
	}

	/**
	 * Save Medical Record meta fields.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function save_med_record_meta( $post_id ) {
		self::save_int( $post_id, '_medical_record_member_id' );
		self::save_text( $post_id, '_medical_record_date' );
		self::save_text( $post_id, '_medical_record_provider' );
		self::save_text( $post_id, '_medical_record_icd_code' );
		self::save_text( $post_id, '_medical_record_lab_value' );
		self::save_text( $post_id, '_medical_record_lab_unit' );
		self::save_text( $post_id, '_medical_record_lab_reference_range' );
		self::save_checkbox( $post_id, '_medical_record_lab_abnormal' );
	}

	/**
	 * Save Checkup meta fields.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function save_checkup_meta( $post_id ) {
		self::save_int( $post_id, '_checkup_member_id' );

		// Datetime: datetime-local inputs send YYYY-MM-DDTHH:MM; normalize to YYYY-MM-DD HH:MM.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Sanitized below; nonce verified in save_post_meta().
		$raw_dt = isset( $_POST['_checkup_datetime'] ) ? sanitize_text_field( wp_unslash( $_POST['_checkup_datetime'] ) ) : '';
		if ( $raw_dt ) {
			$raw_dt = str_replace( 'T', ' ', $raw_dt );
			// Validate format: YYYY-MM-DD HH:MM or YYYY-MM-DD HH:MM:SS.
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $raw_dt ) ) {
				$raw_dt = '';
			}
		}
		update_post_meta( $post_id, '_checkup_datetime', $raw_dt );

		self::save_text( $post_id, '_checkup_provider' );
		self::save_text( $post_id, '_checkup_location' );
		self::save_text( $post_id, '_checkup_type' );
		self::save_text( $post_id, '_checkup_status' );
		self::save_int( $post_id, '_checkup_duration_minutes' );
		self::save_text( $post_id, '_checkup_copay_amount' );
		self::save_text( $post_id, '_checkup_chief_complaint', 'sanitize_textarea_field' );
		self::save_text( $post_id, '_checkup_diagnosis', 'sanitize_textarea_field' );
		self::save_text( $post_id, '_checkup_follow_up_date' );
	}

	/**
	 * Save Prescription meta fields.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function save_prescription_meta( $post_id ) {
		self::save_int( $post_id, '_prescription_member_id' );
		self::save_text( $post_id, '_prescription_dosage' );
		self::save_text( $post_id, '_prescription_frequency' );
		self::save_text( $post_id, '_prescription_status' );
		self::save_text( $post_id, '_prescription_doctor' );
		self::save_text( $post_id, '_prescription_start_date' );
		self::save_text( $post_id, '_prescription_end_date' );
		self::save_int( $post_id, '_prescription_refills_remaining' );
		self::save_text( $post_id, '_prescription_rx_number' );
		self::save_text( $post_id, '_prescription_ndc_code' );
		self::save_text( $post_id, '_prescription_route' );
		self::save_int( $post_id, '_prescription_quantity' );
		self::save_text( $post_id, '_prescription_quantity_unit' );
		self::save_text( $post_id, '_prescription_indication' );
		self::save_text( $post_id, '_prescription_pharmacy_name' );
		self::save_text( $post_id, '_prescription_pharmacy_phone' );
		// Keep _prescription_medication_name in sync with the post_title.
		$post_title = get_post_field( 'post_title', $post_id );
		if ( $post_title ) {
			update_post_meta( $post_id, '_prescription_medication_name', $post_title );
		}
	}

	/**
	 * Save Allergy meta fields.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function save_allergy_meta( $post_id ) {
		self::save_int( $post_id, '_allergy_member_id' );
		self::save_text( $post_id, '_allergy_type' );
		self::save_text( $post_id, '_allergy_onset_type' );
		self::save_text( $post_id, '_allergy_reactions', 'sanitize_textarea_field' );
		self::save_text( $post_id, '_allergy_treatment', 'sanitize_textarea_field' );
		self::save_text( $post_id, '_allergy_diagnosed_date' );
		self::save_text( $post_id, '_allergy_last_reaction_date' );

		// Sync severity from admin form → meta and taxonomy.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- sanitize_key sanitizes; nonce verified in save_post_meta().
		$severity = isset( $_POST['hw_allergy_severity'] ) ? sanitize_key( $_POST['hw_allergy_severity'] ) : '';
		if ( $severity ) {
			update_post_meta( $post_id, '_allergy_severity', $severity );
			wp_set_object_terms( $post_id, $severity, 'mcp_ai_allergy_severity' );
		}

		// Keep _allergy_allergen in sync with post_title.
		$post_title = get_post_field( 'post_title', $post_id );
		if ( $post_title ) {
			update_post_meta( $post_id, '_allergy_allergen', $post_title );
		}
	}

	// =========================================================================
	// ADMIN COLUMNS — MEMBER
	// =========================================================================

	/**
	 * Define admin columns for the Member CPT.
	 *
	 * @param array $columns Default columns.
	 * @return array
	 */
	public static function member_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['hw_member_type']  = __( 'Type', 'mcp-ai-wpoos-pro' );
				$new['hw_member_dob']   = __( 'Date of Birth', 'mcp-ai-wpoos-pro' );
				$new['hw_member_mrn']   = __( 'MRN', 'mcp-ai-wpoos-pro' );
				$new['hw_member_phone'] = __( 'Phone', 'mcp-ai-wpoos-pro' );
			}
		}
		return $new;
	}

	/**
	 * Render content for custom Member columns.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function render_member_column( $column, $post_id ) {
		switch ( $column ) {
			case 'hw_member_type':
				$terms = get_the_terms( $post_id, 'mcp_ai_member_type' );
				if ( $terms && ! is_wp_error( $terms ) ) {
					echo esc_html( implode( ', ', wp_list_pluck( $terms, 'name' ) ) );
				} else {
					echo '—';
				}
				break;

			case 'hw_member_dob':
				$dob = get_post_meta( $post_id, '_member_date_of_birth', true );
				echo $dob ? esc_html( $dob ) : '—';
				break;

			case 'hw_member_mrn':
				$mrn = get_post_meta( $post_id, '_member_mrn', true );
				echo $mrn ? esc_html( $mrn ) : '—';
				break;

			case 'hw_member_phone':
				$phone = get_post_meta( $post_id, '_member_phone', true );
				echo $phone ? esc_html( $phone ) : '—';
				break;
		}
	}

	// =========================================================================
	// ADMIN COLUMNS — POLICY
	// =========================================================================

	/**
	 * Define admin columns for the Policy CPT.
	 *
	 * @param array $columns Default columns.
	 * @return array
	 */
	public static function policy_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['hw_policy_member']   = __( 'Member', 'mcp-ai-wpoos-pro' );
				$new['hw_policy_number']   = __( 'Policy #', 'mcp-ai-wpoos-pro' );
				$new['hw_policy_provider'] = __( 'Provider', 'mcp-ai-wpoos-pro' );
				$new['hw_policy_status']   = __( 'Status', 'mcp-ai-wpoos-pro' );
				$new['hw_policy_expires']  = __( 'Expires', 'mcp-ai-wpoos-pro' );
			}
		}
		return $new;
	}

	/**
	 * Render content for custom Policy columns.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function render_policy_column( $column, $post_id ) {
		switch ( $column ) {
			case 'hw_policy_member':
				$member_id = get_post_meta( $post_id, '_policy_member_id', true );
				echo self::member_link( $member_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- method escapes.
				break;

			case 'hw_policy_number':
				$number = get_post_meta( $post_id, '_policy_number', true );
				echo $number ? esc_html( $number ) : '—';
				break;

			case 'hw_policy_provider':
				$provider = get_post_meta( $post_id, '_policy_provider', true );
				echo $provider ? esc_html( $provider ) : '—';
				break;

			case 'hw_policy_status':
				$status = get_post_meta( $post_id, '_policy_status', true );
				if ( $status ) {
					$cls = 'active' === $status ? 'hw-badge-green' : 'hw-badge-grey';
					printf( '<span class="hw-badge %s">%s</span>', esc_attr( $cls ), esc_html( ucfirst( $status ) ) );
				} else {
					echo '—';
				}
				break;

			case 'hw_policy_expires':
				$exp = get_post_meta( $post_id, '_policy_expiration_date', true );
				echo $exp ? esc_html( $exp ) : '—';
				break;
		}
	}

	// =========================================================================
	// ADMIN COLUMNS — MEDICAL RECORD
	// =========================================================================

	/**
	 * Define admin columns for the Medical Record CPT.
	 *
	 * @param array $columns Default columns.
	 * @return array
	 */
	public static function med_record_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['hw_rec_member']   = __( 'Member', 'mcp-ai-wpoos-pro' );
				$new['hw_rec_date']     = __( 'Date', 'mcp-ai-wpoos-pro' );
				$new['hw_rec_provider'] = __( 'Provider', 'mcp-ai-wpoos-pro' );
				$new['hw_rec_icd']      = __( 'ICD Code', 'mcp-ai-wpoos-pro' );
			}
		}
		return $new;
	}

	/**
	 * Render content for custom Medical Record columns.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function render_med_record_column( $column, $post_id ) {
		switch ( $column ) {
			case 'hw_rec_member':
				$member_id = get_post_meta( $post_id, '_medical_record_member_id', true );
				echo self::member_link( $member_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- method escapes.
				break;

			case 'hw_rec_date':
				$date = get_post_meta( $post_id, '_medical_record_date', true );
				echo $date ? esc_html( $date ) : '—';
				break;

			case 'hw_rec_provider':
				$provider = get_post_meta( $post_id, '_medical_record_provider', true );
				echo $provider ? esc_html( $provider ) : '—';
				break;

			case 'hw_rec_icd':
				$icd = get_post_meta( $post_id, '_medical_record_icd_code', true );
				echo $icd ? esc_html( $icd ) : '—';
				break;
		}
	}

	// =========================================================================
	// ADMIN COLUMNS — CHECKUP
	// =========================================================================

	/**
	 * Define admin columns for the Checkup CPT.
	 *
	 * @param array $columns Default columns.
	 * @return array
	 */
	public static function checkup_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['hw_chk_member']   = __( 'Member', 'mcp-ai-wpoos-pro' );
				$new['hw_chk_datetime'] = __( 'Date / Time', 'mcp-ai-wpoos-pro' );
				$new['hw_chk_provider'] = __( 'Provider', 'mcp-ai-wpoos-pro' );
				$new['hw_chk_type']     = __( 'Type', 'mcp-ai-wpoos-pro' );
				$new['hw_chk_status']   = __( 'Status', 'mcp-ai-wpoos-pro' );
			}
		}
		return $new;
	}

	/**
	 * Render content for custom Checkup columns.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function render_checkup_column( $column, $post_id ) {
		switch ( $column ) {
			case 'hw_chk_member':
				$member_id = get_post_meta( $post_id, '_checkup_member_id', true );
				echo self::member_link( $member_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- method escapes.
				break;

			case 'hw_chk_datetime':
				$dt = get_post_meta( $post_id, '_checkup_datetime', true );
				echo $dt ? esc_html( $dt ) : '—';
				break;

			case 'hw_chk_provider':
				$provider = get_post_meta( $post_id, '_checkup_provider', true );
				echo $provider ? esc_html( $provider ) : '—';
				break;

			case 'hw_chk_type':
				$type = get_post_meta( $post_id, '_checkup_type', true );
				echo $type ? esc_html( ucwords( str_replace( '-', ' ', $type ) ) ) : '—';
				break;

			case 'hw_chk_status':
				$status = get_post_meta( $post_id, '_checkup_status', true );
				if ( $status ) {
					$cls = 'scheduled' === $status ? 'hw-badge-blue' : ( 'completed' === $status ? 'hw-badge-green' : 'hw-badge-grey' );
					printf( '<span class="hw-badge %s">%s</span>', esc_attr( $cls ), esc_html( ucfirst( $status ) ) );
				} else {
					echo '—';
				}
				break;
		}
	}

	// =========================================================================
	// ADMIN COLUMNS — PRESCRIPTION
	// =========================================================================

	/**
	 * Define admin columns for the Prescription CPT.
	 *
	 * @param array $columns Default columns.
	 * @return array
	 */
	public static function prescription_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['hw_rx_member']  = __( 'Member', 'mcp-ai-wpoos-pro' );
				$new['hw_rx_dosage']  = __( 'Dosage', 'mcp-ai-wpoos-pro' );
				$new['hw_rx_freq']    = __( 'Frequency', 'mcp-ai-wpoos-pro' );
				$new['hw_rx_status']  = __( 'Status', 'mcp-ai-wpoos-pro' );
				$new['hw_rx_refills'] = __( 'Refills', 'mcp-ai-wpoos-pro' );
				$new['hw_rx_dates']   = __( 'Start → End', 'mcp-ai-wpoos-pro' );
			}
		}
		return $new;
	}

	/**
	 * Render content for custom Prescription columns.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function render_prescription_column( $column, $post_id ) {
		switch ( $column ) {
			case 'hw_rx_member':
				$member_id = get_post_meta( $post_id, '_prescription_member_id', true );
				echo self::member_link( $member_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- method escapes.
				break;

			case 'hw_rx_dosage':
				$dosage = get_post_meta( $post_id, '_prescription_dosage', true );
				echo $dosage ? esc_html( $dosage ) : '—';
				break;

			case 'hw_rx_freq':
				$freq = get_post_meta( $post_id, '_prescription_frequency', true );
				echo $freq ? esc_html( $freq ) : '—';
				break;

			case 'hw_rx_status':
				$status = get_post_meta( $post_id, '_prescription_status', true );
				if ( $status ) {
					$cls = 'active' === $status ? 'hw-badge-green' : 'hw-badge-grey';
					printf( '<span class="hw-badge %s">%s</span>', esc_attr( $cls ), esc_html( ucfirst( $status ) ) );
				} else {
					echo '—';
				}
				break;

			case 'hw_rx_refills':
				$refills = get_post_meta( $post_id, '_prescription_refills_remaining', true );
				echo '' !== $refills ? esc_html( $refills ) : '—';
				break;

			case 'hw_rx_dates':
				$start = get_post_meta( $post_id, '_prescription_start_date', true );
				$end   = get_post_meta( $post_id, '_prescription_end_date', true );
				if ( $start || $end ) {
					echo esc_html( ( $start ? $start : '?' ) . ' → ' . ( $end ? $end : '∞' ) );
				} else {
					echo '—';
				}
				break;
		}
	}

	// =========================================================================
	// ADMIN COLUMNS — ALLERGY
	// =========================================================================

	/**
	 * Define admin columns for the Allergy CPT.
	 *
	 * @param array $columns Default columns.
	 * @return array
	 */
	public static function allergy_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['hw_al_member']    = __( 'Member', 'mcp-ai-wpoos-pro' );
				$new['hw_al_type']      = __( 'Type', 'mcp-ai-wpoos-pro' );
				$new['hw_al_severity']  = __( 'Severity', 'mcp-ai-wpoos-pro' );
				$new['hw_al_onset']     = __( 'Onset', 'mcp-ai-wpoos-pro' );
				$new['hw_al_reactions'] = __( 'Reactions', 'mcp-ai-wpoos-pro' );
			}
		}
		return $new;
	}

	/**
	 * Render content for custom Allergy columns.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function render_allergy_column( $column, $post_id ) {
		switch ( $column ) {
			case 'hw_al_member':
				$member_id = get_post_meta( $post_id, '_allergy_member_id', true );
				echo self::member_link( $member_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- method escapes.
				break;

			case 'hw_al_type':
				$type  = get_post_meta( $post_id, '_allergy_type', true );
				$types = self::ALLERGY_TYPES;
				echo isset( $types[ $type ] ) && $type ? esc_html( $types[ $type ] ) : '—';
				break;

			case 'hw_al_severity':
				$severity = get_post_meta( $post_id, '_allergy_severity', true );
				if ( $severity ) {
					$cls = 'severe' === $severity ? 'hw-badge-red' : ( 'moderate' === $severity ? 'hw-badge-orange' : 'hw-badge-grey' );
					printf( '<span class="hw-badge %s">%s</span>', esc_attr( $cls ), esc_html( ucfirst( $severity ) ) );
				} else {
					echo '—';
				}
				break;

			case 'hw_al_onset':
				$onset  = get_post_meta( $post_id, '_allergy_onset_type', true );
				$onsets = self::ONSET_TYPES;
				echo isset( $onsets[ $onset ] ) && $onset ? esc_html( $onsets[ $onset ] ) : '—';
				break;

			case 'hw_al_reactions':
				$reactions = get_post_meta( $post_id, '_allergy_reactions', true );
				if ( $reactions ) {
					echo esc_html( wp_trim_words( $reactions, 10, '…' ) );
				} else {
					echo '—';
				}
				break;
		}
	}

	// =========================================================================
	// SHARED UTILITY
	// =========================================================================

	/**
	 * Return an HTML anchor to a member's edit page, or "—" if unknown.
	 *
	 * @param int $member_id Member post ID.
	 * @return string Escaped HTML.
	 */
	private static function member_link( $member_id ) {
		$member_id = absint( $member_id );
		if ( ! $member_id ) {
			return '—';
		}
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return '<em>' . esc_html__( 'Unknown', 'mcp-ai-wpoos-pro' ) . '</em>';
		}
		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( get_edit_post_link( $member_id ) ),
			esc_html( $member->post_title )
		);
	}
}
