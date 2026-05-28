<?php
/**
 * CRM Toolkit Standards Registry
 *
 * Lightweight registry of CRM-standardised codesets shared across all
 * CRM sub-modules.  Mirrors WP_MCP_AI_Healthcare_Codes in the healthcare
 * toolkit: default in-memory catalogue, filter-extensible so partners
 * can register regional variants, custom qualification frameworks,
 * additional channels, and pipeline stages.
 *
 * The registry ships small "seed" code packs — enough to validate the
 * system and back smoke tests. Production deployments register full
 * catalogues via the wp_mcp_ai_crm_code_packs filter.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRM standards registry.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_CRM_Codes {

	/**
	 * Channel identifiers.
	 *
	 * @var string[]
	 */
	const CHANNELS = array(
		'email',
		'sms',
		'whatsapp',
		'telegram',
		'google_chat',
		'linkedin_dm',
		'phone_call',
		'web_form',
		'webchat',
		'in_person',
		'other',
	);

	/**
	 * Inquiry / intent types.
	 *
	 * @var string[]
	 */
	const INQUIRY_TYPES = array(
		'new_inquiry',
		'demo_request',
		'pricing_inquiry',
		'partnership',
		'support',
		'complaint',
		'spam',
		'follow_up',
		'qualification_response',
		'general',
		'unsubscribe',
	);

	/**
	 * Lead sources.
	 *
	 * @var string[]
	 */
	const SOURCES = array(
		'web_form',
		'referral',
		'import_csv',
		'campaign',
		'social_media',
		'trade_show',
		'cold_outreach',
		'inbound_call',
		'partner',
		'website_chat',
		'other',
	);

	/**
	 * Cached, filtered code-pack catalogue.
	 *
	 * @var array<string,array>
	 */
	private static $cache = array();

	/**
	 * Default in-memory code-pack seed catalogue.
	 *
	 * Each pack is keyed by `<system>:<id>` with:
	 *  - system  : Canonical system slug (e.g. 'bant', 'meddic').
	 *  - title   : Human-readable title.
	 *  - codes   : Map of code => display name.
	 *
	 * @return array<string,array>
	 */
	public static function default_packs() {
		return array(
			// --------------------------------------------------------
			// Qualification frameworks
			// --------------------------------------------------------
			'framework:bant'       => array(
				'system' => 'bant',
				'title'  => 'BANT — Budget · Authority · Need · Timeline',
				'codes'  => array(
					'budget'    => 'Budget — Does the prospect have budget allocated?',
					'authority' => 'Authority — Who is the decision-maker?',
					'need'      => 'Need — What is the problem?',
					'timeline'  => 'Timeline — When do they need to act?',
				),
			),
			'framework:meddic'     => array(
				'system' => 'meddic',
				'title'  => 'MEDDIC — Metrics · Economic Buyer · Decision Criteria · Decision Process · Identify Pain · Champion',
				'codes'  => array(
					'metrics'           => 'Metrics — Quantifiable ROI or KPI improvement.',
					'economic_buyer'    => 'Economic Buyer — Person who controls the budget.',
					'decision_criteria' => 'Decision Criteria — Technical and business requirements.',
					'decision_process'  => 'Decision Process — Steps and stakeholders in the buying process.',
					'identify_pain'     => 'Identify Pain — Core problem the prospect needs to solve.',
					'champion'          => 'Champion — Internal advocate with influence.',
				),
			),
			'framework:champ'      => array(
				'system' => 'champ',
				'title'  => 'CHAMP — Challenges · Authority · Money · Prioritisation',
				'codes'  => array(
					'challenges'     => 'Challenges — What is driving the need?',
					'authority'      => 'Authority — Decision-making power.',
					'money'          => 'Money — Budget availability.',
					'prioritisation' => 'Prioritisation — Urgency relative to other initiatives.',
				),
			),

			// --------------------------------------------------------
			// Lifecycle stages
			// --------------------------------------------------------
			'lifecycle:hubspot'    => array(
				'system' => 'hubspot_lifecycle',
				'title'  => 'HubSpot Lifecycle Stages',
				'codes'  => array(
					'subscriber'  => 'Subscriber — opted in to communication.',
					'lead'        => 'Lead — identified but unqualified.',
					'mql'         => 'MQL — Marketing Qualified Lead.',
					'sal'         => 'SAL — Sales Accepted Lead.',
					'sql'         => 'SQL — Sales Qualified Lead.',
					'opportunity' => 'Opportunity — active deal.',
					'customer'    => 'Customer — closed deal.',
					'evangelist'  => 'Evangelist — active promoter.',
					'other'       => 'Other — does not fit standard stages.',
				),
			),

			// --------------------------------------------------------
			// Sentiment labels
			// --------------------------------------------------------
			'sentiment:standard'   => array(
				'system' => 'sentiment',
				'title'  => 'Standard Sentiment Labels',
				'codes'  => array(
					'positive' => 'Positive — enthusiastic, ready to buy.',
					'neutral'  => 'Neutral — inquiring, no strong signal.',
					'negative' => 'Negative — frustration, complaint.',
					'mixed'    => 'Mixed — competing signals.',
					'unknown'  => 'Unknown — insufficient text to classify.',
				),
			),

			// --------------------------------------------------------
			// Deal stages
			// --------------------------------------------------------
			'pipeline:salesforce'  => array(
				'system' => 'salesforce_pipeline',
				'title'  => 'Salesforce Pipeline Stages',
				'codes'  => array(
					'prospecting'         => 'Prospecting — initial research.',
					'qualification'       => 'Qualification — BANT/MEDDIC applied.',
					'needs_analysis'      => 'Needs Analysis — discovery call.',
					'value_prop'          => 'Value Proposition — demo / proposal.',
					'id_decision_makers'  => 'ID Decision Makers — stakeholder mapping.',
					'perception_analysis' => 'Perception Analysis — addressing concerns.',
					'proposal'            => 'Proposal / Price Quote — formal offer.',
					'negotiation'         => 'Negotiation / Review — terms and redlines.',
					'closed_won'          => 'Closed Won — signed.',
					'closed_lost'         => 'Closed Lost — no deal.',
				),
			),

			// --------------------------------------------------------
			// Consent legal bases (GDPR Art. 6)
			// --------------------------------------------------------
			'consent:basis'        => array(
				'system' => 'gdpr_legal_basis',
				'title'  => 'GDPR Art. 6 Legal Bases for Processing',
				'codes'  => array(
					'consent'               => 'Consent — explicit, freely given.',
					'legitimate_interest'   => 'Legitimate Interest — balanced, documented.',
					'contractual_necessity' => 'Contractual Necessity — required to fulfil a contract.',
					'legal_obligation'      => 'Legal Obligation — required by law.',
					'vital_interests'       => 'Vital Interests — protect life.',
					'public_interest'       => 'Public Interest — official authority.',
				),
			),

			// --------------------------------------------------------
			// Activity / call outcome dispositions
			// --------------------------------------------------------
			'disposition:standard' => array(
				'system' => 'disposition',
				'title'  => 'Standard Call/Activity Dispositions',
				'codes'  => array(
					'connected'          => 'Connected — spoke with lead.',
					'voicemail'          => 'Voicemail — left message.',
					'no_answer'          => 'No Answer.',
					'wrong_number'       => 'Wrong Number.',
					'callback_scheduled' => 'Callback Scheduled.',
					'not_interested'     => 'Not Interested.',
					'qualified'          => 'Lead Qualified.',
					'disqualified'       => 'Lead Disqualified.',
					'demo_scheduled'     => 'Demo / Meeting Scheduled.',
					'needs_follow_up'    => 'Needs Follow-up.',
				),
			),
		);
	}

	/**
	 * Get a single code pack by key.
	 *
	 * @param string $key Code pack key, e.g. 'framework:bant'.
	 * @return array|null Pack definition or null if not found.
	 */
	public static function get_pack( $key ) {
		$packs = self::get_all_packs();
		return isset( $packs[ $key ] ) ? $packs[ $key ] : null;
	}

	/**
	 * Get all registered code packs (filterable).
	 *
	 * @return array<string,array>
	 */
	public static function get_all_packs() {
		if ( ! empty( self::$cache ) ) {
			return self::$cache;
		}

		$packs = self::default_packs();

		/**
		 * Filter: register additional CRM code packs.
		 *
		 * E.g. custom qualification frameworks, regional consent rules,
		 * partner-specific pipeline stages.
		 *
		 * @param array $packs Default packs.
		 */
		$filtered    = apply_filters( 'wp_mcp_ai_crm_code_packs', $packs );
		self::$cache = is_array( $filtered ) ? $filtered : $packs;

		return self::$cache;
	}

	/**
	 * Check whether a channel is valid.
	 *
	 * @param string $channel Channel slug.
	 * @return bool
	 */
	public static function is_valid_channel( $channel ) {
		return in_array( sanitize_key( $channel ), self::CHANNELS, true );
	}

	/**
	 * Check whether an inquiry/intent type is valid.
	 *
	 * @param string $intent Intent slug.
	 * @return bool
	 */
	public static function is_valid_intent( $intent ) {
		return in_array( sanitize_key( $intent ), self::INQUIRY_TYPES, true );
	}

	/**
	 * Check whether a source slug is valid.
	 *
	 * @param string $source Source slug.
	 * @return bool
	 */
	public static function is_valid_source( $source ) {
		return in_array( sanitize_key( $source ), self::SOURCES, true );
	}
}
