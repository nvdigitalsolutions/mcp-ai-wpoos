<?php
/**
 * Shared bridge for Upwork connections running in MCP mode.
 *
 * Wraps the official Upwork MCP gateway (https://mcp.upwork.com/mcp) behind
 * the MCP Apps client so the CRM Upwork tools (search / import) can consume
 * it exactly like the GraphQL API mode: sessionful initialize handshake,
 * `upwork__find_jobs` for search and details, `upwork__list_accounts` for
 * org_uid resolution, and defensive normalization of the gateway's job
 * payloads into the canonical tool envelope shapes.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 1.1.88
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static helpers bridging Upwork MCP-mode connections to the CRM tools.
 *
 * @since 1.1.88
 */
class WP_MCP_AI_Upwork_MCP_Bridge {

	/**
	 * Upwork MCP tool names (stable gateway contract).
	 *
	 * @var string
	 */
	const TOOL_FIND_JOBS = 'upwork__find_jobs';

	/**
	 * Upwork MCP tool name for account/organization discovery.
	 *
	 * @var string
	 */
	const TOOL_LIST_ACCOUNTS = 'upwork__list_accounts';

	/**
	 * Build an initialized MCP App client for an Upwork MCP-mode connection.
	 *
	 * @param array $connection Stored Upwork connection array.
	 * @return WP_MCP_AI_MCP_App_Client|WP_Error Client on success, WP_Error on failure.
	 */
	public static function build_client( array $connection ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) || ! class_exists( 'WP_MCP_AI_MCP_App_Client' ) ) {
			$manager_file = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
			$client_file  = WP_MCP_AI_PRO_PATH . 'includes/mcp-apps/class-wp-mcp-ai-mcp-app-client.php';
			$oauth_file   = WP_MCP_AI_PRO_PATH . 'includes/mcp-apps/class-wp-mcp-ai-mcp-app-oauth-client.php';

			if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) && file_exists( $manager_file ) ) {
				require_once $manager_file;
			}
			if ( ! class_exists( 'WP_MCP_AI_MCP_App_Client' ) && file_exists( $client_file ) ) {
				require_once $client_file;
			}
			if ( ! class_exists( 'WP_MCP_AI_MCP_App_OAuth_Client' ) && file_exists( $oauth_file ) ) {
				require_once $oauth_file;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) || ! class_exists( 'WP_MCP_AI_MCP_App_Client' ) ) {
			return new WP_Error(
				'wp_mcp_ai_upwork_mcp_unavailable',
				__( 'The Upwork MCP bridge requires the MCP Apps client.', 'mcp-ai-wpoos-pro' )
			);
		}

		$config = WP_MCP_AI_Pro_Remote_Site_Manager::build_upwork_mcp_app_config( $connection );

		// Attach an OAuth client so short-lived gateway tokens refresh
		// in-place; rotated credentials persist back to the encrypted central
		// store via the connection_ref on the config.
		if ( class_exists( 'WP_MCP_AI_MCP_App_OAuth_Client' ) ) {
			$oauth_client = new WP_MCP_AI_MCP_App_OAuth_Client( $config['server_url'] );
			if ( ! empty( $config['oauth_data'] ) && is_array( $config['oauth_data'] ) ) {
				$oauth_client->set_token_data( $config['oauth_data'] );
			}
			$config['oauth_client'] = $oauth_client;
		}

		return new WP_MCP_AI_MCP_App_Client( $config );
	}

	/**
	 * Open the sessionful legacy session the Upwork gateway requires.
	 *
	 * @param WP_MCP_AI_MCP_App_Client $client MCP App client.
	 * @return true|WP_Error True when the session is active, WP_Error otherwise.
	 */
	public static function initialize( WP_MCP_AI_MCP_App_Client $client ) {
		$result = $client->initialize();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Call an Upwork MCP tool and decode its result into a plain array.
	 *
	 * @param WP_MCP_AI_MCP_App_Client $client    Initialized MCP App client.
	 * @param string                   $tool_name Tool name (upwork__…).
	 * @param array                    $arguments Tool arguments (action/org_uid/params).
	 * @return array|WP_Error Decoded payload or WP_Error.
	 */
	public static function call( WP_MCP_AI_MCP_App_Client $client, $tool_name, array $arguments ) {
		$result = $client->call_tool( $tool_name, $arguments );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::decode_result( $result );
	}

	/**
	 * Decode an MCP tools/call result into a plain payload array.
	 *
	 * Prefers structuredContent, then the concatenated text blocks of the
	 * content envelope (the Envoy AI Gateway returns JSON text), and finally
	 * the raw result itself. `isError` results surface as WP_Error carrying
	 * the gateway's message.
	 *
	 * @param array $result Raw tools/call result.
	 * @return array|WP_Error Payload or WP_Error.
	 */
	public static function decode_result( $result ) {
		if ( ! is_array( $result ) ) {
			return new WP_Error(
				'wp_mcp_ai_upwork_mcp_bad_result',
				__( 'The Upwork MCP gateway returned an unrecognizable result.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! empty( $result['isError'] ) ) {
			$message = '';
			if ( isset( $result['content'] ) && is_array( $result['content'] ) ) {
				foreach ( $result['content'] as $block ) {
					if ( is_array( $block ) && isset( $block['text'] ) ) {
						$message .= $block['text'];
					}
				}
			}
			return new WP_Error(
				'wp_mcp_ai_upwork_mcp_tool_error',
				'' !== trim( $message ) ? $message : __( 'The Upwork MCP gateway reported an error.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( isset( $result['structuredContent'] ) && is_array( $result['structuredContent'] ) ) {
			return $result['structuredContent'];
		}

		if ( isset( $result['content'] ) && is_array( $result['content'] ) ) {
			$text = '';
			foreach ( $result['content'] as $block ) {
				if ( is_array( $block ) && isset( $block['text'] ) ) {
					$text .= $block['text'];
				}
			}
			$text = trim( $text );
			if ( '' !== $text ) {
				$decoded = json_decode( $text, true );
				if ( is_array( $decoded ) ) {
					return $decoded;
				}
				return array( 'message' => $text );
			}
		}

		return $result;
	}

	/**
	 * Resolve the Upwork org_uid required by every gateway tool call.
	 *
	 * Uses the org_uid stored on the connection; when absent, discovers the
	 * first available account via upwork__list_accounts.
	 *
	 * @param WP_MCP_AI_MCP_App_Client $client     Initialized MCP App client.
	 * @param array                    $connection Stored Upwork connection array.
	 * @return string|WP_Error org_uid or WP_Error.
	 */
	public static function resolve_org_uid( WP_MCP_AI_MCP_App_Client $client, array $connection ) {
		if ( ! empty( $connection['upwork_org_uid'] ) ) {
			return sanitize_text_field( $connection['upwork_org_uid'] );
		}

		$payload = self::call( $client, self::TOOL_LIST_ACCOUNTS, array() );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$candidates = array();
		$accounts   = self::find_first_array( $payload, array( 'accounts', 'results', 'items', 'data' ) );
		if ( ! empty( $accounts ) ) {
			$candidates = $accounts;
		} elseif ( self::is_list( $payload ) ) {
			$candidates = $payload;
		}

		foreach ( $candidates as $account ) {
			if ( ! is_array( $account ) ) {
				continue;
			}
			$org_uid = self::pick( $account, array( 'org_uid', 'orgUid', 'organization_id', 'organizationId', 'id' ) );
			if ( null !== $org_uid && '' !== (string) $org_uid ) {
				return (string) $org_uid;
			}
		}

		return new WP_Error(
			'wp_mcp_ai_upwork_org_uid_missing',
			__( 'Could not resolve your Upwork organization ID. Save one on the connection (Remote Sites → edit → Organization ID) or reconnect the account.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Normalize a gateway job payload into the canonical search-tool shape.
	 *
	 * The Upwork MCP search response is not a stable schema, so every field
	 * is extracted defensively from the candidate keys the gateway has been
	 * observed (and is documented) to use.
	 *
	 * @param array $raw Raw job payload.
	 * @return array Canonical job entry.
	 */
	public static function normalize_job( array $raw ) {
		$id          = (string) self::pick( $raw, array( 'id', 'job_id', 'jobId', 'job_posting_id', 'posting_id', 'ref' ) );
		$title       = (string) self::pick( $raw, array( 'title', 'name', 'job_title' ) );
		$description = (string) self::pick( $raw, array( 'description', 'snippet', 'preview', 'summary' ) );

		$budget_raw = self::pick( $raw, array( 'budget', 'fixed_budget', 'fixedBudget' ) );
		$budget     = null;
		if ( is_array( $budget_raw ) ) {
			$budget = self::pick( $budget_raw, array( 'amount', 'max', 'value' ) );
		} elseif ( is_numeric( $budget_raw ) ) {
			$budget = (float) $budget_raw;
		}
		if ( null !== $budget ) {
			$budget = (float) $budget;
		}

		$hourly_raw = self::pick( $raw, array( 'hourly_budget', 'hourlyBudget', 'hourly_rate' ) );
		$hourly     = null;
		if ( is_array( $hourly_raw ) ) {
			$hourly = self::pick( $hourly_raw, array( 'max', 'amount', 'rate' ) );
		} elseif ( is_numeric( $hourly_raw ) ) {
			$hourly = (float) $hourly_raw;
		}
		if ( null !== $hourly ) {
			$hourly = (float) $hourly;
		}

		$skills     = array();
		$raw_skills = self::pick( $raw, array( 'skills', 'skill_list', 'skillList' ) );
		if ( is_array( $raw_skills ) ) {
			foreach ( $raw_skills as $skill ) {
				if ( is_array( $skill ) ) {
					$name = self::pick( $skill, array( 'prettyName', 'pretty_name', 'name', 'title' ) );
					if ( null !== $name ) {
						$skills[] = (string) $name;
					}
				} elseif ( is_scalar( $skill ) && '' !== (string) $skill ) {
					$skills[] = (string) $skill;
				}
			}
		}

		$client_raw = self::pick( $raw, array( 'client', 'client_info', 'clientInfo' ) );
		$client     = array(
			'feedback'         => null,
			'total_hires'      => 0,
			'jobs_posted'      => 0,
			'total_spent'      => null,
			'payment_verified' => null,
			'country'          => '',
		);
		if ( is_array( $client_raw ) ) {
			$client = array(
				'feedback'         => self::to_float( self::pick( $client_raw, array( 'rating', 'feedback', 'client_rating', 'total_feedback' ) ) ),
				'total_hires'      => (int) self::pick( $client_raw, array( 'total_hires', 'totalHires', 'hire_count', 'hires' ) ),
				'jobs_posted'      => (int) self::pick( $client_raw, array( 'total_jobs_posted', 'totalJobsPosted', 'jobs_posted' ) ),
				'total_spent'      => self::to_float( self::pick( $client_raw, array( 'total_spent', 'totalSpent', 'total_charge' ) ) ),
				'payment_verified' => self::pick( $client_raw, array( 'payment_verified', 'paymentVerified', 'payment_verification_status' ) ),
				'country'          => (string) self::pick( $client_raw, array( 'country', 'location' ) ),
			);
		}

		return array(
			'id'            => $id,
			'title'         => $title,
			'description'   => '' !== $description ? wp_trim_words( $description, 60 ) : '',
			'url'           => '' !== $id ? self::build_job_url( $id, $title ) : (string) self::pick( $raw, array( 'url', 'job_url', 'jobUrl' ) ),
			'created'       => (string) self::pick( $raw, array( 'created_date', 'createdDate', 'created', 'createdDateTime', 'created_date_time' ) ),
			'published'     => (string) self::pick( $raw, array( 'published_date', 'publishedDate', 'publishedDateTime', 'published_date_time' ) ),
			'job_type'      => strtolower( (string) self::pick( $raw, array( 'job_type', 'jobType', 'type' ) ) ),
			'engagement'    => (string) self::pick( $raw, array( 'engagement', 'workload' ) ),
			'duration'      => (string) self::pick( $raw, array( 'duration', 'project_length', 'projectLength' ) ),
			'budget'        => $budget,
			'hourly_budget' => $hourly,
			'skills'        => $skills,
			'category'      => (string) self::pick( $raw, array( 'category', 'occupation' ) ),
			'subcategory'   => (string) self::pick( $raw, array( 'subcategory', 'specialty' ) ),
			'applicants'    => (int) self::pick( $raw, array( 'proposal_count', 'proposalCount', 'proposals', 'total_applicants', 'totalApplicants', 'applicants' ) ),
			'tier'          => (string) self::pick( $raw, array( 'tier', 'tier_text', 'tierText' ) ),
			'client'        => $client,
			'cursor'        => (string) self::pick( $raw, array( 'cursor' ) ),
			'raw'           => $raw,
		);
	}

	/**
	 * Normalize a gateway job-details payload into the import-tool shape.
	 *
	 * Matches the GraphQL `fetch_job_details()` contract consumed by
	 * WP_MCP_AI_Tool_Import_Upwork_Project::execute().
	 *
	 * @param array $raw Raw gateway payload.
	 * @return array Import-tool details array.
	 */
	public static function normalize_job_details( array $raw ) {
		$budget_raw = self::pick( $raw, array( 'budget', 'fixed_budget', 'fixedBudget' ) );
		$budget     = array();
		if ( is_array( $budget_raw ) ) {
			$amount = self::to_float( self::pick( $budget_raw, array( 'amount', 'max', 'value' ) ) );
			if ( null !== $amount ) {
				$budget = array(
					'amount'   => $amount,
					'currency' => (string) self::pick( $budget_raw, array( 'currency' ) ),
				);
			}
		} elseif ( is_numeric( $budget_raw ) ) {
			$budget = array(
				'amount'   => (float) $budget_raw,
				'currency' => '',
			);
		}

		$hourly_raw = self::pick( $raw, array( 'hourly_budget', 'hourlyBudget', 'hourly_rate' ) );
		$hourly     = array();
		if ( is_array( $hourly_raw ) ) {
			$max = self::to_float( self::pick( $hourly_raw, array( 'max', 'amount', 'rate' ) ) );
			$min = self::to_float( self::pick( $hourly_raw, array( 'min' ) ) );
			if ( null !== $max || null !== $min ) {
				$hourly = array(
					'min'      => null !== $min ? $min : 0,
					'max'      => null !== $max ? $max : 0,
					'currency' => (string) self::pick( $hourly_raw, array( 'currency' ) ),
				);
			}
		} elseif ( is_numeric( $hourly_raw ) ) {
			$hourly = array(
				'min'      => 0,
				'max'      => (float) $hourly_raw,
				'currency' => '',
			);
		}

		$skills     = array();
		$raw_skills = self::pick( $raw, array( 'skills', 'skill_list', 'skillList' ) );
		if ( is_array( $raw_skills ) ) {
			foreach ( $raw_skills as $skill ) {
				if ( is_array( $skill ) ) {
					$name = self::pick( $skill, array( 'prettyName', 'pretty_name', 'name', 'title' ) );
					if ( null !== $name ) {
						$skills[] = array( 'prettyName' => (string) $name );
					}
				} elseif ( is_scalar( $skill ) && '' !== (string) $skill ) {
					$skills[] = array( 'prettyName' => (string) $skill );
				}
			}
		}

		$client_raw = self::pick( $raw, array( 'client', 'client_info', 'clientInfo' ) );
		$client     = array(
			'totalFeedback' => self::to_float( self::pick( $client_raw, array( 'rating', 'feedback', 'client_rating', 'total_feedback' ) ) ),
			'totalHires'    => (int) self::pick( $client_raw, array( 'total_hires', 'totalHires', 'hires' ) ),
			'location'      => array(
				'country' => (string) self::pick( $client_raw, array( 'country', 'location' ) ),
			),
		);
		if ( null !== self::pick( $client_raw, array( 'total_spent', 'totalSpent' ) ) ) {
			$client['totalSpent'] = array(
				'amount' => self::to_float( self::pick( $client_raw, array( 'total_spent', 'totalSpent' ) ) ),
			);
		}

		return array(
			'title'        => (string) self::pick( $raw, array( 'title', 'name', 'job_title' ) ),
			'description'  => (string) self::pick( $raw, array( 'description', 'snippet', 'preview', 'summary' ) ),
			'jobType'      => strtoupper( (string) self::pick( $raw, array( 'job_type', 'jobType', 'type' ) ) ),
			'engagement'   => (string) self::pick( $raw, array( 'engagement', 'workload' ) ),
			'duration'     => (string) self::pick( $raw, array( 'duration', 'project_length', 'projectLength' ) ),
			'budget'       => $budget,
			'hourlyBudget' => $hourly,
			'skills'       => $skills,
			'client'       => $client,
		);
	}

	/**
	 * Build a resolvable Upwork job URL from an ID and title.
	 *
	 * Upwork resolves by the `~<jobId>` suffix; the title slug is cosmetic
	 * and a missing `~` prefix is normalized defensively.
	 *
	 * @param string $id    Job ID (numeric, ciphertext, or full URL).
	 * @param string $title Job title.
	 * @return string Job URL.
	 */
	public static function build_job_url( $id, $title ) {
		// Full Upwork URLs pass through untouched.
		if ( 0 === strpos( $id, 'https://www.upwork.com/jobs/' ) ) {
			return esc_url_raw( $id );
		}

		$job_ref = 0 === strpos( $id, '~' ) ? $id : '~' . $id;
		$slug    = sanitize_title( $title );

		return 'https://www.upwork.com/jobs/' . ( '' !== $slug ? $slug . '_' : '' ) . $job_ref . '/';
	}

	/**
	 * Extract the first non-empty array under any of the given keys.
	 *
	 * @param array $payload Payload array.
	 * @param array $keys    Candidate keys, in priority order.
	 * @return array List or empty array.
	 */
	protected static function find_first_array( array $payload, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $payload[ $key ] ) && is_array( $payload[ $key ] ) ) {
				return $payload[ $key ];
			}
		}
		return array();
	}

	/**
	 * Whether an array is a plain numeric list.
	 *
	 * @param array $payload Payload array.
	 * @return bool True when the payload is a list.
	 */
	protected static function is_list( array $payload ) {
		if ( empty( $payload ) ) {
			return false;
		}
		return array_keys( $payload ) === range( 0, count( $payload ) - 1 );
	}

	/**
	 * Return the first present (non-null, non-empty-string) value among keys.
	 *
	 * @param array $raw  Raw payload array.
	 * @param array $keys Candidate keys, in priority order.
	 * @return mixed Value or null when absent.
	 */
	protected static function pick( array $raw, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $raw[ $key ] ) && null !== $raw[ $key ] && '' !== $raw[ $key ] ) {
				return $raw[ $key ];
			}
		}
		return null;
	}

	/**
	 * Coerce a value to float, tolerating nested amount objects.
	 *
	 * @param mixed $value Value to coerce.
	 * @return float|null Float or null when not numeric.
	 */
	protected static function to_float( $value ) {
		if ( is_array( $value ) ) {
			$value = self::pick( $value, array( 'amount', 'value', 'max' ) );
		}
		if ( is_numeric( $value ) ) {
			return (float) $value;
		}
		return null;
	}
}
