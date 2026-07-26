<?php
/**
 * Calculate Erlang C tool — queuing theory calculator.
 *
 * Pure mathematical computation of queue wait probabilities, service
 * levels, utilisation, and minimum staffing requirements using the
 * Erlang C formula (M/M/c queue model). No external dependencies.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Calculate_Erlang_C.
 *
 * @package Nvoos\Core
 * @since   2.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErlangCInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;

class CalculateErlangCTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ErlangCInterface $erlang,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'calculate_erlang_c';
	}

	public function getName(): string {
		return 'Calculate Erlang C';
	}

	public function getDescription(): string {
		return 'Applies the Erlang C queuing formula to calculate contact-centre or AI-chat staffing. Given an arrival rate, average handle time, and number of agents, returns the probability of waiting, average wait time, agent utilisation, and service-level attainment. Can also find the minimum agents required to meet a target service level.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'arrival_rate'             => array(
					'type'        => 'number',
					'description' => 'Number of contacts (calls/chats/tasks) arriving per hour. Must be greater than 0.',
					'minimum'     => 0.001,
				),
				'avg_handle_time'          => array(
					'type'        => 'number',
					'description' => 'Average handle time per contact in seconds.',
					'minimum'     => 1,
				),
				'num_agents'               => array(
					'type'        => 'integer',
					'description' => 'Number of agents. When omitted, finds the minimum agents required to meet the service-level target.',
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
					'description' => 'Target answer-time threshold in seconds. Default: 20.',
					'minimum'     => 1,
				),
				'concurrency_factor'       => array(
					'type'        => 'number',
					'description' => 'Simultaneous conversations one agent handles. Default: 1 (voice/synchronous).',
					'minimum'     => 1,
					'maximum'     => 10,
				),
			),
			'required'             => array( 'arrival_rate', 'avg_handle_time' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$arrivalRate = (float) ( $arguments['arrival_rate'] ?? 0 );
		$aht         = (float) ( $arguments['avg_handle_time'] ?? 0 );

		if ( $arrivalRate <= 0 ) {
			return $this->errors->validationFailed(
				'arrival_rate must be greater than 0.',
				array( 'arrival_rate' => array( 'Must be a positive number.' ) ),
			);
		}

		if ( $aht <= 0 ) {
			return $this->errors->validationFailed(
				'avg_handle_time must be greater than 0.',
				array( 'avg_handle_time' => array( 'Must be a positive number.' ) ),
			);
		}

		$targetSlPct   = (float) ( $arguments['target_service_level_pct'] ?? 80.0 );
		$targetTime    = (int) ( $arguments['target_answer_time'] ?? 20 );
		$concurrency   = (float) ( $arguments['concurrency_factor'] ?? 1.0 );
		$targetSlFrac  = \min( 0.999, \max( 0.001, $targetSlPct / 100.0 ) );

		// Apply concurrency: effective arrival rate per agent-equivalent.
		$effectiveArrival = $arrivalRate / $concurrency;
		$traffic          = $this->erlang->toErlangs( $effectiveArrival, $aht );

		$numAgents = $arguments['num_agents'] ?? null;

		if ( null !== $numAgents ) {
			return $this->computeForAgents(
				$traffic, $aht, (int) $numAgents, $targetTime, $targetSlFrac,
				$arrivalRate, $aht, $concurrency, $targetSlPct, $targetTime,
			);
		}

		return $this->computeMinAgents(
			$traffic, $aht, $targetSlFrac, (float) $targetTime,
			$arrivalRate, $aht, $concurrency, $targetSlPct, $targetTime,
		);
	}

	private function computeForAgents(
		float $traffic, float $aht, int $agents, int $targetTime, float $targetSlFrac,
		float $arrivalRate, float $origAht, float $concurrency, float $targetSlPct, int $origTargetTime,
	): array {
		$isStable  = $traffic < (float) $agents;
		$probWait  = $this->erlang->probabilityWait( $traffic, $agents );
		$avgWait   = $this->erlang->averageWaitTime( $traffic, $agents, $aht );
		$svcLevel  = $this->erlang->serviceLevel( $traffic, $agents, $aht, (float) $targetTime );
		$util      = $this->erlang->utilisation( $traffic, $agents );
		$minAgents = $this->erlang->minAgentsForServiceLevel( $traffic, $aht, $targetSlFrac, (float) $targetTime );

		$message = $isStable
			? \sprintf(
				'%d agents: P(wait)=%.1f%%, avg wait=%.1fs, SL(%ds)=%.1f%%, utilisation=%.1f%%',
				$agents, \round( $probWait * 100, 1 ), \round( $avgWait, 1 ),
				$targetTime, \round( $svcLevel * 100, 1 ), \round( $util * 100, 1 ),
			)
			: \sprintf(
				'Queue is UNSTABLE with %d agents (traffic exceeds capacity). Add more agents.',
				$agents,
			);

		return $this->success(
			$message,
			array(
				'input'                => array(
					'arrival_rate_per_hour'    => $arrivalRate,
					'avg_handle_time_seconds'  => $origAht,
					'num_agents'               => $agents,
					'concurrency_factor'       => $concurrency,
					'target_service_level_pct' => $targetSlPct,
					'target_answer_time_sec'   => $origTargetTime,
				),
				'traffic_intensity'    => \round( $traffic, 4 ),
				'is_stable'            => $isStable,
				'probability_wait'     => \round( $probWait, 4 ),
				'probability_wait_pct' => \round( $probWait * 100, 2 ),
				'avg_wait_time_sec'    => $isStable ? \round( $avgWait, 2 ) : null,
				'service_level_pct'    => \round( $svcLevel * 100, 2 ),
				'utilisation_pct'      => \round( $util * 100, 2 ),
				'agents_needed'        => $minAgents,
				'agents_surplus'       => $isStable ? ( $agents - $minAgents ) : null,
			),
		);
	}

	private function computeMinAgents(
		float $traffic, float $aht, float $targetSlFrac, float $targetTime,
		float $arrivalRate, float $origAht, float $concurrency, float $targetSlPct, int $origTargetTime,
	): array {
		$minAgents = $this->erlang->minAgentsForServiceLevel( $traffic, $aht, $targetSlFrac, $targetTime );
		$probWait  = $this->erlang->probabilityWait( $traffic, $minAgents );
		$avgWait   = $this->erlang->averageWaitTime( $traffic, $minAgents, $aht );
		$svcLevel  = $this->erlang->serviceLevel( $traffic, $minAgents, $aht, $targetTime );
		$util      = $this->erlang->utilisation( $traffic, $minAgents );

		$message = \sprintf(
			'Minimum %d agents needed to achieve %.0f%% of contacts answered within %ds (arrival rate %.0f/hr, AHT %.0fs).',
			$minAgents, $targetSlPct, $origTargetTime, $arrivalRate, $origAht,
		);

		return $this->success(
			$message,
			array(
				'input'                => array(
					'arrival_rate_per_hour'    => $arrivalRate,
					'avg_handle_time_seconds'  => $origAht,
					'concurrency_factor'       => $concurrency,
					'target_service_level_pct' => $targetSlPct,
					'target_answer_time_sec'   => $origTargetTime,
				),
				'traffic_intensity'    => \round( $traffic, 4 ),
				'agents_needed'        => $minAgents,
				'probability_wait'     => \round( $probWait, 4 ),
				'probability_wait_pct' => \round( $probWait * 100, 2 ),
				'avg_wait_time_sec'    => \round( $avgWait, 2 ),
				'service_level_pct'    => \round( $svcLevel * 100, 2 ),
				'utilisation_pct'      => \round( $util * 100, 2 ),
			),
		);
	}
}
