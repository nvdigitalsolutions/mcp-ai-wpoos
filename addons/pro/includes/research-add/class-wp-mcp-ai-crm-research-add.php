<?php
/**
 * CRM & Email Marketing Toolkit Research & Add
 *
 * Research & Add implementation for CRM toolkit.
 * Manages Contacts, Campaigns, and Leads entities with CCT/CPT storage.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-research-add-base.php';

/**
 * CRM Research & Add implementation.
 */
class WP_MCP_AI_CRM_Research_Add extends WP_MCP_AI_Research_Add_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'crm' );

		// Register field schemas.
		add_filter( 'wp_mcp_ai_toolkit_cpt_field_schema', array( $this, 'filter_cpt_field_schema' ), 10, 3 );
		add_filter( 'wp_mcp_ai_toolkit_cct_field_schema', array( $this, 'filter_cct_field_schema' ), 10, 3 );
	}

	/**
	 * Get entity types for CRM toolkit.
	 *
	 * @return array Entity types.
	 */
	protected function get_entity_types() {
		return array(
			'contacts'  => __( 'Contacts', 'mcp-ai-wpoos-pro' ),
			'companies' => __( 'Companies', 'mcp-ai-wpoos-pro' ),
			'campaigns' => __( 'Email Campaigns', 'mcp-ai-wpoos-pro' ),
			'leads'     => __( 'Leads', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Filter CPT field schema.
	 *
	 * @param array  $schema       Field schema.
	 * @param string $toolkit_slug Toolkit slug.
	 * @param string $entity_type  Entity type.
	 * @return array Filtered schema.
	 */
	public function filter_cpt_field_schema( $schema, $toolkit_slug, $entity_type ) {
		if ( 'crm' !== $toolkit_slug ) {
			return $schema;
		}

		switch ( $entity_type ) {
			case 'contacts':
				return $this->get_contacts_schema();
			case 'companies':
				return $this->get_companies_schema();
			case 'campaigns':
				return $this->get_campaigns_schema();
			case 'leads':
				return $this->get_leads_schema();
		}

		return $schema;
	}

	/**
	 * Filter CCT field schema.
	 *
	 * @param array  $schema       Field schema.
	 * @param string $toolkit_slug Toolkit slug.
	 * @param string $entity_type  Entity type.
	 * @return array Filtered schema.
	 */
	public function filter_cct_field_schema( $schema, $toolkit_slug, $entity_type ) {
		// Use same schema for both CPT and CCT.
		return $this->filter_cpt_field_schema( $schema, $toolkit_slug, $entity_type );
	}

	/**
	 * Get contacts field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_contacts_schema() {
		return array(
			'first_name'       => array(
				'title'       => __( 'First Name', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '50%',
				'is_required' => true,
			),
			'last_name'        => array(
				'title'       => __( 'Last Name', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '50%',
				'is_required' => true,
			),
			'email'            => array(
				'title'       => __( 'Email Address', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '50%',
				'is_required' => true,
			),
			'phone'            => array(
				'title' => __( 'Phone Number', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'company'          => array(
				'title' => __( 'Company', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'job_title'        => array(
				'title' => __( 'Job Title', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'address'          => array(
				'title' => __( 'Address', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'city'             => array(
				'title' => __( 'City', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '33%',
			),
			'state'            => array(
				'title' => __( 'State/Province', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '33%',
			),
			'zip'              => array(
				'title' => __( 'ZIP/Postal Code', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '34%',
			),
			'country'          => array(
				'title' => __( 'Country', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'website'          => array(
				'title' => __( 'Website', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'lead_score'       => array(
				'title' => __( 'Lead Score', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'lead_status'      => array(
				'title'   => __( 'Lead Status', 'mcp-ai-wpoos-pro' ),
				'type'    => 'select',
				'width'   => '50%',
				'options' => array(
					array(
						'key'   => 'new',
						'value' => __( 'New', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'contacted',
						'value' => __( 'Contacted', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'qualified',
						'value' => __( 'Qualified', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'converted',
						'value' => __( 'Converted', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'lost',
						'value' => __( 'Lost', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'email_subscribed' => array(
				'title' => __( 'Email Subscribed', 'mcp-ai-wpoos-pro' ),
				'type'  => 'switcher',
				'width' => '50%',
			),
			'tags'             => array(
				'title' => __( 'Tags', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '100%',
			),
			'notes'            => array(
				'title' => __( 'Notes', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
		);
	}

	/**
	 * Get companies field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_companies_schema() {
		return array(
			'company_name'   => array(
				'title'       => __( 'Company Name', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'industry'       => array(
				'title'       => __( 'Industry', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '50%',
				'is_required' => true,
			),
			'company_size'   => array(
				'title'   => __( 'Company Size', 'mcp-ai-wpoos-pro' ),
				'type'    => 'select',
				'width'   => '50%',
				'options' => array(
					array(
						'key'   => '1-10',
						'value' => __( '1-10 employees', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => '11-50',
						'value' => __( '11-50 employees', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => '51-200',
						'value' => __( '51-200 employees', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => '201-500',
						'value' => __( '201-500 employees', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => '501-1000',
						'value' => __( '501-1,000 employees', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => '1001-5000',
						'value' => __( '1,001-5,000 employees', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => '5001+',
						'value' => __( '5,001+ employees', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'website'        => array(
				'title' => __( 'Website', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '100%',
			),
			'address'        => array(
				'title' => __( 'Address', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'city'           => array(
				'title' => __( 'City', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '33%',
			),
			'state'          => array(
				'title' => __( 'State/Province', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '33%',
			),
			'zip'            => array(
				'title' => __( 'ZIP/Postal Code', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '34%',
			),
			'country'        => array(
				'title' => __( 'Country', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'phone'          => array(
				'title' => __( 'Phone Number', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'revenue'        => array(
				'title' => __( 'Annual Revenue', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '50%',
			),
			'target_status'  => array(
				'title'   => __( 'Target Status', 'mcp-ai-wpoos-pro' ),
				'type'    => 'select',
				'width'   => '50%',
				'options' => array(
					array(
						'key'   => 'prospect',
						'value' => __( 'Prospect', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'target',
						'value' => __( 'Target', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'in_discussion',
						'value' => __( 'In Discussion', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'client',
						'value' => __( 'Client', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'not_interested',
						'value' => __( 'Not Interested', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'linkedin'       => array(
				'title' => __( 'LinkedIn URL', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'twitter'        => array(
				'title' => __( 'Twitter/X Handle', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'description'    => array(
				'title' => __( 'Company Description', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
			'tags'           => array(
				'title' => __( 'Tags', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '100%',
			),
			'notes'          => array(
				'title' => __( 'Research Notes', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
		);
	}

	/**
	 * Get campaigns field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_campaigns_schema() {
		return array(
			'campaign_name'    => array(
				'title'       => __( 'Campaign Name', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'subject_line'     => array(
				'title'       => __( 'Subject Line', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'preview_text'     => array(
				'title' => __( 'Preview Text', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '100%',
			),
			'campaign_type'    => array(
				'title'   => __( 'Campaign Type', 'mcp-ai-wpoos-pro' ),
				'type'    => 'select',
				'width'   => '50%',
				'options' => array(
					array(
						'key'   => 'newsletter',
						'value' => __( 'Newsletter', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'promotional',
						'value' => __( 'Promotional', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'transactional',
						'value' => __( 'Transactional', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'drip',
						'value' => __( 'Drip Campaign', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'status'           => array(
				'title'   => __( 'Status', 'mcp-ai-wpoos-pro' ),
				'type'    => 'select',
				'width'   => '50%',
				'options' => array(
					array(
						'key'   => 'draft',
						'value' => __( 'Draft', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'scheduled',
						'value' => __( 'Scheduled', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'sending',
						'value' => __( 'Sending', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'sent',
						'value' => __( 'Sent', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'paused',
						'value' => __( 'Paused', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'send_date'        => array(
				'title' => __( 'Send Date', 'mcp-ai-wpoos-pro' ),
				'type'  => 'datetime-local',
				'width' => '50%',
			),
			'total_sent'       => array(
				'title' => __( 'Total Sent', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '25%',
			),
			'open_rate'        => array(
				'title' => __( 'Open Rate (%)', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '25%',
			),
			'click_rate'       => array(
				'title' => __( 'Click Rate (%)', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '25%',
			),
			'unsubscribe_rate' => array(
				'title' => __( 'Unsubscribe Rate (%)', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '25%',
			),
			'segment'          => array(
				'title' => __( 'Target Segment', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '100%',
			),
		);
	}

	/**
	 * Get leads field schema.
	 *
	 * @return array Field definitions.
	 */
	private function get_leads_schema() {
		return array(
			'lead_name'   => array(
				'title'       => __( 'Lead Name', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '100%',
				'is_required' => true,
			),
			'email'       => array(
				'title'       => __( 'Email Address', 'mcp-ai-wpoos-pro' ),
				'type'        => 'text',
				'width'       => '50%',
				'is_required' => true,
			),
			'phone'       => array(
				'title' => __( 'Phone Number', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'company'     => array(
				'title' => __( 'Company', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'source'      => array(
				'title'   => __( 'Lead Source', 'mcp-ai-wpoos-pro' ),
				'type'    => 'select',
				'width'   => '50%',
				'options' => array(
					array(
						'key'   => 'website',
						'value' => __( 'Website', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'referral',
						'value' => __( 'Referral', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'social_media',
						'value' => __( 'Social Media', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'email_campaign',
						'value' => __( 'Email Campaign', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'paid_ad',
						'value' => __( 'Paid Ad', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'other',
						'value' => __( 'Other', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'score'       => array(
				'title' => __( 'Lead Score', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '33%',
			),
			'status'      => array(
				'title'   => __( 'Status', 'mcp-ai-wpoos-pro' ),
				'type'    => 'select',
				'width'   => '34%',
				'options' => array(
					array(
						'key'   => 'new',
						'value' => __( 'New', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'contacted',
						'value' => __( 'Contacted', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'qualified',
						'value' => __( 'Qualified', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'nurturing',
						'value' => __( 'Nurturing', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'converted',
						'value' => __( 'Converted', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'lost',
						'value' => __( 'Lost', 'mcp-ai-wpoos-pro' ),
					),
				),
			),
			'value'       => array(
				'title' => __( 'Estimated Value', 'mcp-ai-wpoos-pro' ),
				'type'  => 'number',
				'width' => '33%',
			),
			'assigned_to' => array(
				'title' => __( 'Assigned To', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'next_action' => array(
				'title' => __( 'Next Action', 'mcp-ai-wpoos-pro' ),
				'type'  => 'text',
				'width' => '50%',
			),
			'notes'       => array(
				'title' => __( 'Notes', 'mcp-ai-wpoos-pro' ),
				'type'  => 'textarea',
				'width' => '100%',
			),
		);
	}
}
