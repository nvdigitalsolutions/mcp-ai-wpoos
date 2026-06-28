<?php
/**
 * Tool: flag_abnormal_vitals
 *
 * Walks a member's recent vital readings and labels each numeric metric using
 * `WP_MCP_AI_Healthcare_Engine::flag_value()` so a clinician (or another tool)
 * can quickly see what is in/out of range.  Storage is read by delegating to
 * the existing `log_vital_signs` tool's `get_history` action — no new
 * persistence layer is introduced.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flag abnormal vitals tool.
 */
class WP_MCP_AI_Tool_Flag_Abnormal_Vitals implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Whether the tool is available in the current install.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( class_exists( 'WP_MCP_AI_Healthcare_Engine' ) ) {
			return WP_MCP_AI_Healthcare_Engine::is_subtoolkit_enabled( 'vitals' );
		}
		return false;
	}

	/**
	 * Get the unavailable reason.
	 *
	 * @return string
	 */
	public function get_unavailable_reason() {
		return __( 'Medical Vitals sub-toolkit is disabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'flag_abnormal_vitals';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Flag Abnormal Vitals', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Review a member\'s recent vital-sign readings and flag values that fall outside age, sex, or species-aware reference ranges. Returns a per-metric breakdown of high/low/in-range counts plus the most recent flagged readings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'member_id' => array(
					'type'        => 'integer',
					'description' => __( 'Member post ID.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'days_back' => array(
					'type'        => 'integer',
					'description' => __( 'How many days of history to scan (default 30).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 3650,
					'default'     => 30,
				),
				'species'   => array(
					'type'        => 'string',
					'description' => __( 'Species context for reference-range lookup ("human", "canine", "feline").', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'human', 'canine', 'feline' ),
					'default'     => 'human',
				),
				'sex'       => array(
					'type'        => 'string',
					'description' => __( 'Biological sex for reference-range lookup ("male", "female", "unknown").', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'male', 'female', 'unknown' ),
					'default'     => 'unknown',
				),
				'age_years' => array(
					'type'        => 'number',
					'description' => __( 'Age in years for reference-range lookup (optional).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 150,
				),
			),
			'required'   => array( 'member_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'read-only', 'pii-data', 'cacheable' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! class_exists( 'WP_MCP_AI_Healthcare_Engine' ) ) {
			return new WP_Error( 'wp_mcp_ai_unavailable', __( 'Healthcare engine not loaded.', 'mcp-ai-wpoos-pro' ) );
		}

		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view vital-sign data.', 'mcp-ai-wpoos-pro' ) );
		}

		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		if ( $member_id <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_missing_member_id', __( 'A valid member_id is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$days_back = isset( $arguments['days_back'] ) ? max( 1, absint( $arguments['days_back'] ) ) : 30;
		$species   = isset( $arguments['species'] ) ? sanitize_key( $arguments['species'] ) : 'human';
		$sex       = isset( $arguments['sex'] ) ? sanitize_key( $arguments['sex'] ) : 'unknown';
		$age_years = isset( $arguments['age_years'] ) ? floatval( $arguments['age_years'] ) : null;

		// Audit the read.
		if ( class_exists( 'WP_MCP_AI_Healthcare_Audit' ) ) {
			WP_MCP_AI_Healthcare_Audit::record(
				'read',
				'vital_log',
				$member_id,
				array(
					'user_id' => $user_id,
					'tool'    => $this->get_slug(),
				)
			);
		}

		// Pull history via the existing tool to avoid duplicating storage logic.
		$history = $this->fetch_history( $member_id, $days_back, $context );
		if ( is_wp_error( $history ) ) {
			return $history;
		}

		$ctx           = array(
			'species'   => $species,
			'sex'       => $sex,
			'age_years' => $age_years,
		);
		$flagged       = array();
		$summary       = array();
		$total_records = 0;

		foreach ( $history as $entry ) {
			$measurements = isset( $entry['measurements'] ) && is_array( $entry['measurements'] )
				? $entry['measurements']
				: $entry;
			$entry_date   = isset( $entry['measurement_date'] ) ? $entry['measurement_date'] : ( isset( $entry['date'] ) ? $entry['date'] : '' );
			++$total_records;

			foreach ( self::get_metric_map() as $metric_key => $extractor ) {
				$value = $extractor( $measurements );
				if ( null === $value ) {
					continue;
				}
				$flag = WP_MCP_AI_Healthcare_Engine::flag_value( $metric_key, $value, $ctx );
				if ( ! isset( $summary[ $metric_key ] ) ) {
					$summary[ $metric_key ] = array(
						'in_range' => 0,
						'low'      => 0,
						'high'     => 0,
						'unknown'  => 0,
					);
				}
				++$summary[ $metric_key ][ $flag ];
				if ( 'low' === $flag || 'high' === $flag ) {
					$flagged[] = array(
						'date'   => $entry_date,
						'metric' => $metric_key,
						'value'  => $value,
						'flag'   => $flag,
					);
				}
			}
		}

		return array(
			'success'         => true,
			'member_id'       => $member_id,
			'days_back'       => $days_back,
			'records_scanned' => $total_records,
			'summary'         => $summary,
			'flagged'         => $flagged,
		);
	}

	/**
	 * Map of metric slug => callable that extracts the numeric value from a
	 * stored measurements row.  The Engine reference-range slugs are used.
	 *
	 * @return array
	 */
	private static function get_metric_map() {
		return array(
			'heart_rate'         => function ( $row ) {
				if ( isset( $row['heart_rate']['value'] ) ) {
					return (float) $row['heart_rate']['value'];
				}
				return isset( $row['heart_rate'] ) && is_numeric( $row['heart_rate'] ) ? (float) $row['heart_rate'] : null;
			},
			'systolic_bp'        => function ( $row ) {
				if ( isset( $row['blood_pressure']['systolic'] ) ) {
					return (float) $row['blood_pressure']['systolic'];
				}
				return null;
			},
			'diastolic_bp'       => function ( $row ) {
				if ( isset( $row['blood_pressure']['diastolic'] ) ) {
					return (float) $row['blood_pressure']['diastolic'];
				}
				return null;
			},
			'spo2'               => function ( $row ) {
				if ( isset( $row['oxygen_saturation']['value'] ) ) {
					return (float) $row['oxygen_saturation']['value'];
				}
				return isset( $row['oxygen_saturation'] ) && is_numeric( $row['oxygen_saturation'] ) ? (float) $row['oxygen_saturation'] : null;
			},
			'respiratory_rate'   => function ( $row ) {
				if ( isset( $row['respiratory_rate']['value'] ) ) {
					return (float) $row['respiratory_rate']['value'];
				}
				return isset( $row['respiratory_rate'] ) && is_numeric( $row['respiratory_rate'] ) ? (float) $row['respiratory_rate'] : null;
			},
			'blood_glucose_mgdl' => function ( $row ) {
				if ( isset( $row['blood_glucose']['value'] ) ) {
					return (float) $row['blood_glucose']['value'];
				}
				return isset( $row['blood_glucose'] ) && is_numeric( $row['blood_glucose'] ) ? (float) $row['blood_glucose'] : null;
			},
			'temperature_c'      => function ( $row ) {
				// log_vital_signs stores temperature normalised to °F; convert.
				if ( isset( $row['temperature']['value'] ) ) {
					$f = (float) $row['temperature']['value'];
					return ( $f - 32.0 ) * 5.0 / 9.0;
				}
				return null;
			},
		);
	}

	/**
	 * Fetch history by delegating to the existing log_vital_signs tool.
	 *
	 * @param int   $member_id Member ID.
	 * @param int   $days_back Days back.
	 * @param array $context   Tool execution context.
	 * @return array|WP_Error  Array of history entries, or WP_Error on failure.
	 */
	private function fetch_history( $member_id, $days_back, array $context ) {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Log_Vital_Signs' ) ) {
			return array();
		}
		$delegate = new WP_MCP_AI_Tool_Log_Vital_Signs();
		$result   = $delegate->execute(
			array(
				'action'    => 'get_history',
				'member_id' => $member_id,
				'days_back' => $days_back,
			),
			$context
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( is_array( $result ) ) {
			if ( isset( $result['history'] ) && is_array( $result['history'] ) ) {
				return $result['history'];
			}
			if ( isset( $result['entries'] ) && is_array( $result['entries'] ) ) {
				return $result['entries'];
			}
			if ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
				return $result['data'];
			}
			// If the result already looks like a list of rows, return as-is.
			if ( isset( $result[0] ) ) {
				return $result;
			}
		}
		return array();
	}
}
