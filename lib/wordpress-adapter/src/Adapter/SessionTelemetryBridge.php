<?php
/**
 * SessionTelemetryBridge — WordPress adapter exposing session-log
 * telemetry as a single WP action (Proposal 029, Phase 5.8).
 *
 * Subscribes to the orchestrator's SessionTelemetry tap and re-emits
 * every appended log entry as do_action( 'wp_mcp_ai_session_log_event',
 * $type, $data, $seq, $time ). Audit loggers and metric observers attach
 * to that ONE action instead of re-wrapping the chat loop — the same
 * stream that derives model history feeds telemetry.
 *
 * Deliberately does NOT re-fire the legacy wp_mcp_ai_* loop hooks: both
 * chat paths would double-emit them. Legacy-hook consumers migrate to
 * wp_mcp_ai_session_log_event during the Phase 6 cleanup.
 *
 * @package Nvoos\WordPress
 * @since   1.3.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Application\Session\SessionEvent;
use Nvoos\Core\Application\Session\SessionTelemetry;

final class SessionTelemetryBridge {

	public function __construct( SessionTelemetry $telemetry ) {
		$telemetry->subscribe( array( $this, 'onEvent' ) );
	}

	/**
	 * Fan one appended log entry out to WordPress consumers.
	 *
	 * Hook signature:
	 * do_action( 'wp_mcp_ai_session_log_event', $type, $data, $seq, $time )
	 */
	public function onEvent( SessionEvent $event ): void {
		if ( ! \function_exists( 'do_action' ) ) {
			return;
		}

		\do_action( 'wp_mcp_ai_session_log_event', $event->type, $event->data, $event->seq, $event->time );
	}
}
