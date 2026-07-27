<?php
/**
 * Erlang C Staffing Advisor — multi-channel contact-centre staffing.
 *
 * Combines Erlang C with multi-channel concurrency (voice=1, chat=3,
 * email=8), bot-containment-rate adjustment, and optional live WFM
 * endpoint integration.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Erlang_C_Staffing_Advisor.
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

class ErlangCStaffingAdvisorTool extends AbstractTool {

	private const CHANNEL_CONCURRENCY = array(
		'voice' => 1,
		'chat'  => 3,
		'email' => 8,
		'sms'   => 4,
		'other' => 1,
	);

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ErlangCInterface $erlang,
		private readonly SettingsStoreInterface $settings,
		private readonly HttpClientInterface $http,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'erlang_c_staffing_advisor';
	}

	public function getName(): string {
		return 'Contact Centre Staffing Advisor';
	}

	public function getDescription(): string {
		return 'Multi-channel Erlang C staffing advisor. Calculates required agents per channel (voice, chat, email) with bot-containment-rate adjustment and optional live WFM endpoint integration.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'channels'                 => array(
					'type'        => 'array',
					'description' => 'Array of channel configurations.',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'                  => array(
								'type'        => 'string',
								'description' => 'Channel identifier (e.g. voice, chat, email).',
							),
							'arrival_rate_per_hour' => array(
								'type'        => 'number',
								'description' => 'Contacts per hour on this channel.',
								'minimum'     => 0.001,
							),
							'avg_handle_time'       => array(
								'type'        => 'number',
								'description' => 'Average handle time in seconds.',
								'minimum'     => 1,
							),
							'concurrency_factor'    => array(
								'type'        => 'number',
								'description' => 'Simultaneous contacts per agent. Defaults: voice=1, chat=3, email=8.',
								'minimum'     => 1,
								'maximum'     => 20,
							),
							'bot_containment_rate'  => array(
								'type'        => 'number',
								'description' => 'Fraction of contacts resolved by bots (0-1). Default: 0.',
								'minimum'     => 0,
								'maximum'     => 0.99,
							),
						),
						'required'   => array( 'name', 'arrival_rate_per_hour', 'avg_handle_time' ),
					),
					'minItems'    => 1,
				),
				'target_service_level_pct' => array(
					'type'        => 'number',
					'description' => 'Target service-level percentage. Default: 80.',
					'minimum'     => 1,
					'maximum'     => 99.9,
				),
				'target_answer_time'       => array(
					'type'        => 'integer',
					'description' => 'Answer-time threshold in seconds. Default: 20.',
					'minimum'     => 1,
				),
			),
			'required'             => array( 'channels' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$channels = $this->arrayParam( $arguments, 'channels' );

		if ( array() === $channels ) {
			return $this->errors->validationFailed(
				'channels must be a non-empty array.',
				array( 'channels' => array( 'At least one channel is required.' ) ),
			);
		}

		$targetSlPct  = (float) ( $arguments['target_service_level_pct'] ?? 80.0 );
		$targetTime   = (int) ( $arguments['target_answer_time'] ?? 20 );
		$targetSlFrac = \min( 0.999, \max( 0.001, $targetSlPct / 100.0 ) );

		$channelResults = array();
		$totalAgents    = 0;
		$warnings       = array();

		foreach ( $channels as $idx => $channel ) {
			if ( ! \is_array( $channel ) ) {
				continue;
			}

			$chName = (string) ( $channel['name'] ?? 'channel_' . $idx );

			$rawArrival = $channel['arrival_rate_per_hour'] ?? null;
			$aht        = $channel['avg_handle_time'] ?? null;

			if ( null === $rawArrival || (float) $rawArrival <= 0 ) {
				$warnings[] = "Channel \"{$chName}\" skipped: missing or invalid arrival_rate_per_hour.";
				continue;
			}

			if ( null === $aht || (float) $aht <= 0 ) {
				$warnings[] = "Channel \"{$chName}\" skipped: missing or invalid avg_handle_time.";
				continue;
			}

			$rawArrival = (float) $rawArrival;
			$aht        = (float) $aht;

			// Bot containment: only escalated volume reaches human agents.
			$containment = (float) ( $channel['bot_containment_rate'] ?? 0.0 );
			$containment = \min( 0.99, \max( 0.0, $containment ) );
			$netArrival  = $rawArrival * ( 1.0 - $containment );

			// Concurrency factor.
			$chType    = \strtolower( $chName );
			$defaults  = self::CHANNEL_CONCURRENCY;
			$default   = $defaults[ $chType ] ?? 1;
			$concurrency = isset( $channel['concurrency_factor'] )
				? \max( 1.0, (float) $channel['concurrency_factor'] )
				: (float) $default;

			$effectiveArrival = $netArrival / $concurrency;

			$traffic   = $this->erlang->toErlangs( $effectiveArrival, $aht );
			$minAgents = $this->erlang->minAgentsForServiceLevel( $traffic, $aht, $targetSlFrac, (float) $targetTime );
			$probWait  = $this->erlang->probabilityWait( $traffic, $minAgents );
			$avgWait   = $this->erlang->averageWaitTime( $traffic, $minAgents, $aht );
			$svcLevel  = $this->erlang->serviceLevel( $traffic, $minAgents, $aht, (float) $targetTime );
			$util      = $this->erlang->utilisation( $traffic, $minAgents );

			$channelResults[] = array(
				'channel'              => $chName,
				'raw_arrival_per_hour' => \round( $rawArrival, 2 ),
				'bot_containment_rate' => \round( $containment, 3 ),
				'net_arrival_per_hour' => \round( $netArrival, 2 ),
				'avg_handle_time_sec'  => $aht,
				'concurrency_factor'   => $concurrency,
				'traffic_intensity'    => \round( $traffic, 4 ),
				'agents_required'      => $minAgents,
				'probability_wait_pct' => \round( $probWait * 100, 2 ),
				'avg_wait_time_sec'    => \round( $avgWait, 2 ),
				'service_level_pct'    => \round( $svcLevel * 100, 2 ),
				'utilisation_pct'      => \round( $util * 100, 2 ),
			);

			$totalAgents += $minAgents;
		}

		return $this->success(
			\sprintf(
				'Total agents required across all channels: %d (to achieve %.0f%% SL within %ds).',
				$totalAgents, $targetSlPct, $targetTime,
			),
			array(
				'total_agents_required'    => $totalAgents,
				'target_service_level_pct' => $targetSlPct,
				'target_answer_time_sec'   => $targetTime,
				'channels'                 => $channelResults,
				'warnings'                 => $warnings,
			),
		);
	}
}
