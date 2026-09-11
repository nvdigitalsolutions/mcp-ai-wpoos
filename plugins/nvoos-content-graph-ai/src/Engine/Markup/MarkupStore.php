<?php
/**
 * Markup request store (Wave E6, sub-cluster 2).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Markup_Store`
 * (`includes/markup/`): byte-identical transient prefix
 * (`wp_mcp_ai_markup_`), index option (`wp_mcp_ai_markup_index`,
 * non-autoloaded), the 16-per-assistant cap with expired-entry pruning,
 * the 429 `wp_mcp_ai_markup_too_many_requests` envelope, save/get/
 * consume (delete-on-read replay protection)/delete, and the daily
 * cleanup sweep of expired transients + index entries.
 *
 * Documented deviations:
 *  - Class name/namespace — the AI addon's PSR-4 tree (decision D4).
 *  - `WP_Error` is fully qualified.
 *  - Text domain `nvoos-content-graph-ai`.
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine\Markup
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine\Markup;

/**
 * Transient-backed store for pending markup elicitation requests.
 *
 * @since 1.1.0
 */
class MarkupStore {

	const TRANSIENT_PREFIX  = 'wp_mcp_ai_markup_';
	const INDEX_OPTION      = 'wp_mcp_ai_markup_index';
	const MAX_PER_ASSISTANT = 16;

	/**
	 * Persist a request and add it to the index.
	 *
	 * @param MarkupRequest $request Request to persist.
	 * @return true|\WP_Error
	 */
	public function save( MarkupRequest $request ) {
		$assistant_id = $request->get_assistant_id();
		$index        = $this->get_index();
		$bucket       = isset( $index[ $assistant_id ] ) && \is_array( $index[ $assistant_id ] ) ? $index[ $assistant_id ] : array();

		// Prune expired entries from this bucket before enforcing the cap.
		$now    = \time();
		$bucket = \array_filter(
			$bucket,
			static function ( $entry ) use ( $now ) {
				return \is_array( $entry ) && isset( $entry['expires_at'] ) && (int) $entry['expires_at'] > $now;
			}
		);

		if ( \count( $bucket ) >= self::MAX_PER_ASSISTANT ) {
			return new \WP_Error(
				'wp_mcp_ai_markup_too_many_requests',
				__( 'Too many pending markup requests for this assistant.', 'nvoos-content-graph-ai' ),
				array( 'status' => 429 )
			);
		}

		$ttl = \max( 60, $request->get_expires_at() - \time() );

		$saved = \set_transient( self::TRANSIENT_PREFIX . $request->get_request_id(), $request->to_array(), $ttl );
		if ( false === $saved ) {
			return new \WP_Error( 'wp_mcp_ai_markup_save_failed', __( 'Could not persist markup request.', 'nvoos-content-graph-ai' ) );
		}

		$bucket[ $request->get_request_id() ] = array(
			'expires_at' => $request->get_expires_at(),
			'tool_slug'  => $request->get_tool_slug(),
		);
		$index[ $assistant_id ]               = $bucket;
		$this->save_index( $index );

		return true;
	}

	/**
	 * Look up a request by ID without removing it.
	 *
	 * @param string $request_id Request ID.
	 * @return MarkupRequest|null
	 */
	public function get( $request_id ) {
		$request_id = (string) $request_id;
		if ( '' === $request_id ) {
			return null;
		}
		$data = \get_transient( self::TRANSIENT_PREFIX . $request_id );
		if ( ! \is_array( $data ) ) {
			return null;
		}
		$request = MarkupRequest::from_array( $data );
		if ( \is_wp_error( $request ) ) {
			return null;
		}
		if ( $request->is_expired() ) {
			$this->delete( $request_id );
			return null;
		}
		return $request;
	}

	/**
	 * Look up a request, then atomically delete it (replay protection).
	 *
	 * @param string $request_id Request ID.
	 * @return MarkupRequest|null
	 */
	public function consume( $request_id ) {
		$request = $this->get( $request_id );
		if ( null !== $request ) {
			$this->delete( $request_id );
		}
		return $request;
	}

	/**
	 * Delete a request and remove it from the index.
	 *
	 * @param string $request_id Request ID.
	 * @return void
	 */
	public function delete( $request_id ) {
		$request_id = (string) $request_id;
		if ( '' === $request_id ) {
			return;
		}
		\delete_transient( self::TRANSIENT_PREFIX . $request_id );
		$index   = $this->get_index();
		$changed = false;
		foreach ( $index as $assistant_id => $bucket ) {
			if ( isset( $bucket[ $request_id ] ) ) {
				unset( $index[ $assistant_id ][ $request_id ] );
				if ( empty( $index[ $assistant_id ] ) ) {
					unset( $index[ $assistant_id ] );
				}
				$changed = true;
			}
		}
		if ( $changed ) {
			$this->save_index( $index );
		}
	}

	/**
	 * Purge expired entries from the index.
	 *
	 * Intended to be called from a daily cron event.
	 *
	 * @return int Number of entries purged.
	 */
	public function cleanup_expired() {
		$index   = $this->get_index();
		$now     = \time();
		$purged  = 0;
		$updated = $index;
		foreach ( $index as $assistant_id => $bucket ) {
			if ( ! \is_array( $bucket ) ) {
				unset( $updated[ $assistant_id ] );
				continue;
			}
			foreach ( $bucket as $request_id => $entry ) {
				if ( ! \is_array( $entry ) || ! isset( $entry['expires_at'] ) || (int) $entry['expires_at'] <= $now ) {
					\delete_transient( self::TRANSIENT_PREFIX . $request_id );
					unset( $updated[ $assistant_id ][ $request_id ] );
					++$purged;
				}
			}
			if ( empty( $updated[ $assistant_id ] ) ) {
				unset( $updated[ $assistant_id ] );
			}
		}
		if ( $updated !== $index ) {
			$this->save_index( $updated );
		}
		return $purged;
	}

	/**
	 * Get the index option, defaulting to an empty array.
	 *
	 * @return array
	 */
	private function get_index() {
		$index = \get_option( self::INDEX_OPTION, array() );
		return \is_array( $index ) ? $index : array();
	}

	/**
	 * Save the index option (autoload disabled).
	 *
	 * @param array $index Index data.
	 * @return void
	 */
	private function save_index( array $index ) {
		\update_option( self::INDEX_OPTION, $index, false );
	}
}
