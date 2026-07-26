<?php
/**
 * Erlang C Queue Health — real-time queue SLA monitor.
 *
 * Accepts current queue metrics (arrival rate, AHT, agent count) and
 * applies Erlang C to compute live service-level health. Optionally
 * fetches metrics from a configured REST endpoint.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Erlang_C_Queue_Health.
 *
 * @package Nvoos\Core
 * @since   2.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErlangCInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class ErlangCQueueHealthTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ErlangCInterface $erlang,
		private readonly SettingsStoreInterface $settings,
		private readonly HttpClientInterface $http,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'erlang_c_queue_health';
	}

	public function getName(): string {
		return 'Queue Health Monitor';
	}

	public function getDescription(): string {
		return 'Real-time queue health monitor. Accepts current queue depth, available agents, and arrival rate then applies Erlang C to calculate live service level. Optionally fetches metrics from a configured REST endpoint.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'arrival_rate_per_hour'    => array(
					'type'        => 'number',
					'description' => 'Current contacts arriving per hour.',
					'minimum'     => 0.001,
				),
				'avg_handle_time'          => array(
					'type'        => 'number',
					'description' => 'Average handle time in seconds.',
					'minimum'     => 1,
				),
				'current_agents'           => array(
					'type'        => 'integer',
					'description' => 'Number of agents currently available.',
					'minimum'     => 1,
				),
				'queue_depth'              => array(
					'type'        => 'integer',
					'description' => 'Current number of contacts waiting in queue.',
					'minimum'     => 0,
				),
				'target_service_level_pct' => array(
					'type'        => 'number',
					'description' => 'SLA threshold percentage. Default: 80.',
					'minimum'     => 1,
					'maximum'     => 99.9,
				),
				'target_answer_time'       => array(
					'type'        => 'integer',
					'description' => 'Answer-time threshold in seconds. Default: 20.',
					'minimum'     => 1,
				),
				'fetch_from_endpoint'      => array(
					'type'        => 'boolean',
					'description' => 'When true, fetch live metrics from the configured queue health endpoint.',
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
			return $this->errors->forbidden( 'You must be logged in to use the queue health monitor.' );
		}

		$targetSlPct  = (float) ( $arguments['target_service_level_pct'] ?? 80.0 );
		$targetTime   = (int) ( $arguments['target_answer_time'] ?? 20 );
		$targetSlFrac = \min( 0.999, \max( 0.001, $targetSlPct / 100.0 ) );

		// Resolve metrics.
		if ( ! empty( $arguments['fetch_from_endpoint'] ) ) {
			$metrics = $this->fetchEndpointMetrics();
			if ( null === $metrics ) {
				return $this->errors->create(
					'endpoint_error',
					'Failed to fetch metrics from the configured endpoint.',
				);
			}
		} else {
			$metrics = $this->resolveInlineMetrics( $arguments );
			if ( null === $metrics ) {
				return $this->errors->validationFailed(
					'arrival_rate_per_hour, avg_handle_time, and current_agents are required when not fetching from endpoint.',
					array(
						'arrival_rate_per_hour' => array( 'Required.' ),
						'avg_handle_time'       => array( 'Required.' ),
						'current_agents'        => array( 'Required.' ),
					),
				);
			}
		}

		$arrivalRate   = $metrics['arrival_rate'];
		$aht           = $metrics['aht'];
		$currentAgents = $metrics['agents'];
		$queueDepth    = $metrics['queue_depth'];

		$traffic    = $this->erlang->toErlangs( $arrivalRate, $aht );
		$minAgents  = $this->erlang->minAgentsForServiceLevel( $traffic, $aht, $targetSlFrac, (float) $targetTime );
		$probWait   = $this->erlang->probabilityWait( $traffic, $currentAgents );
		$avgWait    = $this->erlang->averageWaitTime( $traffic, $currentAgents, $aht );
		$svcLevel   = $this->erlang->serviceLevel( $traffic, $currentAgents, $aht, (float) $targetTime );
		$util       = $this->erlang->utilisation( $traffic, $currentAgents );
		$isStable   = $traffic < (float) $currentAgents;

		$agentDeficit = \max( 0, $minAgents - $currentAgents );
		$slaAtRisk    = $svcLevel < $targetSlFrac;

		if ( ! $isStable ) {
			$message = \sprintf(
				'QUEUE OVERLOADED: %d agents insufficient for current traffic. Add agents immediately.',
				$currentAgents,
			);
			$status = 'overloaded';
		} elseif ( $slaAtRisk ) {
			$message = \sprintf(
				'SLA AT RISK: Current service level %.1f%% is below target %.0f%%. Need %d more agent(s).',
				\round( $svcLevel * 100, 1 ), $targetSlPct, $agentDeficit,
			);
			$status = 'at_risk';
		} else {
			$message = \sprintf(
				'SLA HEALTHY: Service level %.1f%% meets target %.0f%%. Avg wait %.1fs.',
				\round( $svcLevel * 100, 1 ), $targetSlPct, \round( $avgWait, 1 ),
			);
			$status = 'healthy';
		}

		return $this->success(
			$message,
			array(
				'status'                   => $status,
				'sla_at_risk'              => $slaAtRisk,
				'is_stable'                => $isStable,
				'metrics'                  => array(
					'arrival_rate_per_hour' => \round( $arrivalRate, 2 ),
					'avg_handle_time_sec'   => $aht,
					'current_agents'        => $currentAgents,
					'queue_depth'           => $queueDepth,
				),
				'erlang_c'                 => array(
					'traffic_intensity'    => \round( $traffic, 4 ),
					'probability_wait_pct' => \round( $probWait * 100, 2 ),
					'avg_wait_time_sec'    => $isStable ? \round( $avgWait, 2 ) : null,
					'service_level_pct'    => \round( $svcLevel * 100, 2 ),
					'utilisation_pct'      => \round( $util * 100, 2 ),
				),
				'recommendation'           => array(
					'min_agents_needed' => $minAgents,
					'agent_deficit'     => $agentDeficit,
					'agent_surplus'     => \max( 0, $currentAgents - $minAgents ),
				),
				'target_service_level_pct' => $targetSlPct,
				'target_answer_time_sec'   => $targetTime,
			),
		);
	}

	/**
	 * @return array{arrival_rate: float, aht: float, agents: int, queue_depth: int}|null
	 */
	private function resolveInlineMetrics( array $arguments ): ?array {
		$arrivalRate = $arguments['arrival_rate_per_hour'] ?? null;
		$aht         = $arguments['avg_handle_time'] ?? null;
		$agents      = $arguments['current_agents'] ?? null;

		if ( null === $arrivalRate || null === $aht || null === $agents ) {
			return null;
		}

		return array(
			'arrival_rate' => (float) $arrivalRate,
			'aht'          => (float) $aht,
			'agents'       => \max( 1, (int) $agents ),
			'queue_depth'  => (int) ( $arguments['queue_depth'] ?? 0 ),
		);
	}

	/**
	 * @return array{arrival_rate: float, aht: float, agents: int, queue_depth: int}|null
	 */
	private function fetchEndpointMetrics(): ?array {
		$endpoint = $this->settings->get( 'wp_mcp_ai_queue_health_endpoint', '' );
		$token    = $this->settings->get( 'wp_mcp_ai_queue_health_token', '' );

		if ( '' === $endpoint ) {
			return null;
		}

		try {
			$headers = array( 'Accept' => 'application/json' );
			if ( '' !== $token ) {
				$headers['Authorization'] = "Bearer {$token}";
			}

			$response = $this->http->send( 'GET', $endpoint, $headers );

			if ( $response->statusCode >= 400 ) {
				return null;
			}

			$data = \json_decode( $response->body, true );

			if ( ! \is_array( $data ) ) {
				return null;
			}

			return array(
				'arrival_rate' => (float) ( $data['arrival_rate_per_hour'] ?? 0 ),
				'aht'          => (float) ( $data['avg_handle_time'] ?? 0 ),
				'agents'       => \max( 1, (int) ( $data['current_agents'] ?? 0 ) ),
				'queue_depth'  => (int) ( $data['queue_depth'] ?? 0 ),
			);

		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
