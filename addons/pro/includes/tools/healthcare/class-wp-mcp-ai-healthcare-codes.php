<?php
/**
 * Healthcare Toolkit Clinical Codes Registry
 *
 * Lightweight registry of clinical code systems used by the healthcare
 * sub-toolkits.  Mirrors the architectural-design `WP_MCP_AI_Architectural_Codes`
 * pattern: a default in-memory catalogue, filterable so partners can register
 * regional variants (ICD-11, SNOMED-UK, regional drug codes, custom DICOM
 * modalities).
 *
 * The registry intentionally ships only a small "seed" of well-known codes per
 * system — enough to validate that the system is wired correctly and to back
 * a few smoke tests.  Production deployments are expected to register the
 * full catalogues via the `wp_mcp_ai_healthcare_code_packs` filter or by
 * loading a partner-provided plug-in.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clinical codes registry.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Healthcare_Codes {

	/**
	 * Cached, filtered code-pack catalogue.
	 *
	 * @var array<string,array>
	 */
	private static $cache = array();

	/**
	 * Default in-memory code-pack seed catalogue.
	 *
	 * Each pack is keyed by `<system>:<id>` and exposes:
	 *  - system  : Canonical system slug (e.g. 'icd10-cm-2025').
	 *  - title   : Human-readable system title.
	 *  - codes   : Map of code => display name.
	 *
	 * @return array<string,array>
	 */
	public static function default_packs() {
		return array(
			'icd10-cm-2025'  => array(
				'system' => 'icd10-cm',
				'title'  => 'ICD-10-CM (2025)',
				'url'    => 'http://hl7.org/fhir/sid/icd-10-cm',
				'codes'  => array(
					'I10'     => 'Essential (primary) hypertension',
					'E11.9'   => 'Type 2 diabetes mellitus without complications',
					'J45.909' => 'Unspecified asthma, uncomplicated',
					'M54.5'   => 'Low back pain',
					'R51'     => 'Headache',
				),
			),
			'snomed-ct-2025' => array(
				'system' => 'snomed-ct',
				'title'  => 'SNOMED CT (2025-01)',
				'url'    => 'http://snomed.info/sct',
				'codes'  => array(
					'38341003'  => 'Hypertensive disorder, systemic arterial',
					'73211009'  => 'Diabetes mellitus',
					'195967001' => 'Asthma',
					'271737000' => 'Anaemia',
				),
			),
			'loinc-2025'     => array(
				'system' => 'loinc',
				'title'  => 'LOINC (2025)',
				'url'    => 'http://loinc.org',
				'codes'  => array(
					'8480-6'  => 'Systolic blood pressure',
					'8462-4'  => 'Diastolic blood pressure',
					'8867-4'  => 'Heart rate',
					'8310-5'  => 'Body temperature',
					'2339-0'  => 'Glucose [Mass/volume] in Blood',
					'59408-5' => 'Oxygen saturation in arterial blood by pulse oximetry',
				),
			),
			'rxnorm-2025'    => array(
				'system' => 'rxnorm',
				'title'  => 'RxNorm (2025-01)',
				'url'    => 'http://www.nlm.nih.gov/research/umls/rxnorm',
				'codes'  => array(
					'1191'  => 'Aspirin',
					'5640'  => 'Ibuprofen',
					'6809'  => 'Metformin',
					'29046' => 'Lisinopril',
				),
			),
			'cvx-2025'       => array(
				'system' => 'cvx',
				'title'  => 'CDC CVX Vaccine Codes (2025)',
				'url'    => 'http://hl7.org/fhir/sid/cvx',
				'codes'  => array(
					'08'  => 'Hepatitis B vaccine, paediatric or paediatric/adolescent dosage',
					'21'  => 'Varicella virus vaccine',
					'140' => 'Influenza, seasonal, injectable, preservative free',
					'207' => 'COVID-19, mRNA, LNP-S, PF, 100 mcg/ 0.5 mL dose',
				),
			),
			'cpt-2025'       => array(
				'system' => 'cpt',
				'title'  => 'CPT (2025)',
				'url'    => 'http://www.ama-assn.org/go/cpt',
				'codes'  => array(
					'99202' => 'Office or other outpatient visit, new patient, low MDM',
					'99213' => 'Office or other outpatient visit, established patient',
					'93000' => 'Electrocardiogram, routine ECG with at least 12 leads',
				),
			),
			'dicom-modality' => array(
				'system' => 'dicom-modality',
				'title'  => 'DICOM Modality Codes',
				'url'    => 'http://dicom.nema.org/medical/dicom/current/output/chtml/part16/chapter_d.html',
				'codes'  => array(
					'CR' => 'Computed Radiography',
					'CT' => 'Computed Tomography',
					'MR' => 'Magnetic Resonance',
					'US' => 'Ultrasound',
					'NM' => 'Nuclear Medicine',
					'PT' => 'Positron Emission Tomography',
					'XA' => 'X-Ray Angiography',
					'MG' => 'Mammography',
					'OT' => 'Other',
				),
			),
		);
	}

	/**
	 * Resolved code-pack catalogue (filterable).
	 *
	 * @return array<string,array>
	 */
	public static function get_packs() {
		if ( ! empty( self::$cache ) ) {
			return self::$cache;
		}

		$packs = self::default_packs();

		/**
		 * Register additional or override clinical code packs.
		 *
		 * @param array $packs Map of pack-id => { system, title, url, codes }.
		 */
		$packs = apply_filters( 'wp_mcp_ai_healthcare_code_packs', $packs );

		self::$cache = is_array( $packs ) ? $packs : self::default_packs();
		return self::$cache;
	}

	/**
	 * Reset the in-memory cache.  Used by tests and when the filter changes.
	 *
	 * @return void
	 */
	public static function reset_cache() {
		self::$cache = array();
	}

	/**
	 * Default code pack id (filterable).
	 *
	 * @return string
	 */
	public static function default_pack_id() {
		$settings = WP_MCP_AI_Healthcare_Engine::get_toolkit_settings();
		$pack     = isset( $settings['default_code_pack'] ) ? (string) $settings['default_code_pack'] : 'icd10-cm-2025';
		/**
		 * Filter the default code pack id.
		 *
		 * @param string $pack Default code pack id.
		 */
		return (string) apply_filters( 'wp_mcp_ai_healthcare_default_code_pack', $pack );
	}

	/**
	 * Validate a code against a registered pack.
	 *
	 * @param string $pack_id Pack identifier (e.g. 'icd10-cm-2025').
	 * @param string $code    Code to validate.
	 * @return bool
	 */
	public static function validate_code( $pack_id, $code ) {
		$packs = self::get_packs();
		if ( ! isset( $packs[ $pack_id ]['codes'] ) ) {
			return false;
		}
		return array_key_exists( (string) $code, (array) $packs[ $pack_id ]['codes'] );
	}

	/**
	 * Look up a code's display name in a registered pack.
	 *
	 * @param string $pack_id Pack identifier.
	 * @param string $code    Code to look up.
	 * @return string|null Display name, or null when not registered.
	 */
	public static function lookup( $pack_id, $code ) {
		$packs = self::get_packs();
		if ( ! isset( $packs[ $pack_id ]['codes'][ $code ] ) ) {
			return null;
		}
		return (string) $packs[ $pack_id ]['codes'][ $code ];
	}

	/**
	 * Convenience alias for `lookup()`.
	 *
	 * @param string $pack_id Pack identifier.
	 * @param string $code    Code to look up.
	 * @return string|null
	 */
	public static function display_name( $pack_id, $code ) {
		return self::lookup( $pack_id, $code );
	}

	/**
	 * Get the canonical system URL for a pack id.
	 *
	 * @param string $pack_id Pack identifier.
	 * @return string|null
	 */
	public static function system_url( $pack_id ) {
		$packs = self::get_packs();
		return isset( $packs[ $pack_id ]['url'] ) ? (string) $packs[ $pack_id ]['url'] : null;
	}
}
