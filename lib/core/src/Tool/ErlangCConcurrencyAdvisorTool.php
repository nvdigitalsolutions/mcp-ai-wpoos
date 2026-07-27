<?php
/**
 * Erlang C Concurrency Advisor — AI session concurrency optimizer.
 *
 * Applies Erlang C queuing theory to site-level AI chat-request metrics
 * to recommend optimal concurrent assistant session limits.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Erlang_C_Concurrency_Advisor.
 *
 * @package Nvoos\Core
 * @since   2.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErlangCInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class ErlangCConcurrencyAdvisorTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ErlangCInterface $erlang,
		private readonly SettingsStoreInterface $settings,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'erlang_c_concurrency_advisor';
	}

	public function getName(): string {
		return 'AI Session Concurrency Advisor';
	}

	public function getDescription(): string {
		return 'Analyses observed AI chat arrival rates and session durations, then applies Erlang C queuing theory to recommend the optimal number of concurrent assistant sessions.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'arrival_rate_per_hour'    => array(
					'type'        => 'number',
					'description' => 'Override observed arrival rate (requests per hour). When omitted, uses stored activity counters.',
					'minimum'     => 0.001,
				),
				'avg_session_duration'     => array(
					'type'        => 'number',
					'description' => 'Override average session duration in seconds. Default: 120s.',
					'minimum'     => 1,
				),
				'target_service_level_pct' => array(
					'type'        => 'number',
					'description' => 'Target service-level percentage (1-99.9). Default: 80.',
					'minimum'     => 1,
					'maximum'     => 99.9,
				),
				'target_answer_time'       => array(
					'type'        => 'integer',
					'description' => 'Target queue-wait threshold in seconds. Default: 5s.',
					'minimum'     => 1,
				),
				'window_hours'             => array(
					'type'        => 'integer',
					'description' => 'Observation window in hours. Default: 1.',
					'minimum'     => 1,
					'maximum'     => 168,
				),
			),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'manage_options';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$userId = $context['user_id'] ?? 0;

		if ( $userId <= 0 ) {
			return $this->errors->forbidden( 'You must be logged in to use the concurrency advisor.' );
		}

		// Resolve arrival rate.
		$arrivalRate = $arguments['arrival_rate_per_hour'] ?? null;
		$dataSource  = 'provided';

		if ( null === $arrivalRate || (float) $arrivalRate <= 0 ) {
			$storedRate = $this->settings->get( 'wp_mcp_ai_chat_request_rate', 0 );
			$arrivalRate = (float) $storedRate;
			$dataSource  = $arrivalRate > 0 ? 'stored' : 'fallback';

			if ( $arrivalRate <= 0 ) {
				return $this->errors->validationFailed(
					'No chat-request data found. Provide arrival_rate_per_hour or generate chat activity first.',
					array( 'arrival_rate_per_hour' => array( 'No data available.' ) ),
				);
			}
		} else {
			$arrivalRate = (float) $arrivalRate;
		}

		// Resolve session duration.
		$avgDuration    = (float) ( $arguments['avg_session_duration'] ?? 0 );
		$durationSource = 'provided';

		if ( $avgDuration <= 0 ) {
			$storedDuration = $this->settings->get( 'wp_mcp_ai_avg_session_duration', 0 );
			$avgDuration    = (float) $storedDuration;
			$durationSource = $avgDuration > 0 ? 'stored' : 'default';

			if ( $avgDuration <= 0 ) {
				$avgDuration = 120.0; // Default session duration.
			}
		}

		$targetSlPct  = (float) ( $arguments['target_service_level_pct'] ?? 80.0 );
		$targetTime   = (int) ( $arguments['target_answer_time'] ?? 5 );
		$windowHours  = (int) ( $arguments['window_hours'] ?? 1 );
		$targetSlFrac = \min( 0.999, \max( 0.001, $targetSlPct / 100.0 ) );

		$traffic     = $this->erlang->toErlangs( $arrivalRate, $avgDuration );
		$minSlots    = $this->erlang->minAgentsForServiceLevel( $traffic, $avgDuration, $targetSlFrac, (float) $targetTime );
		$recommended = (int) \ceil( $minSlots * 1.2 ); // +20% headroom.

		$probWait = $this->erlang->probabilityWait( $traffic, $recommended );
		$avgWait  = $this->erlang->averageWaitTime( $traffic, $recommended, $avgDuration );
		$svcLevel = $this->erlang->serviceLevel( $traffic, $recommended, $avgDuration, (float) $targetTime );
		$util     = $this->erlang->utilisation( $traffic, $recommended );

		$currentSlots = (int) $this->settings->get( 'wp_mcp_ai_max_concurrent_sessions', 0 );

		return $this->success(
			\sprintf(
				'Recommend %d concurrent AI sessions to achieve %.0f%% of chats queued within %ds (includes 20%% headroom).',
				$recommended, $targetSlPct, $targetTime,
			),
			array(
				'observation'              => array(
					'arrival_rate_per_hour' => \round( $arrivalRate, 2 ),
					'avg_session_duration'  => \round( $avgDuration, 1 ),
					'data_source'           => $dataSource,
					'duration_source'       => $durationSource,
					'window_hours'          => $windowHours,
				),
				'erlang_c'                 => array(
					'traffic_intensity'  => \round( $traffic, 4 ),
					'min_slots_required' => $minSlots,
					'recommended_slots'  => $recommended,
				),
				'with_recommended_slots'   => array(
					'probability_wait_pct' => \round( $probWait * 100, 2 ),
					'avg_wait_time_sec'    => \round( $avgWait, 2 ),
					'service_level_pct'    => \round( $svcLevel * 100, 2 ),
					'utilisation_pct'      => \round( $util * 100, 2 ),
				),
				'current_setting'          => $currentSlots > 0 ? $currentSlots : null,
				'setting_adequate'         => $currentSlots > 0 ? ( $currentSlots >= $minSlots ) : null,
				'target_service_level_pct' => $targetSlPct,
				'target_answer_time_sec'   => $targetTime,
			),
		);
	}
}
