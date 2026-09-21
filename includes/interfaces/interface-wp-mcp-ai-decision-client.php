<?php
/**
 * Interface: AI Decision Client
 *
 * Common contract for decision-only AI providers (e.g. TypeSafe Jev
 * "System One" models). Decision models do NOT generate prose, code, or
 * tool calls; they receive application state plus typed questions and
 * return constrained, probabilistic decisions (Choice / Score / Noul).
 *
 * This contract is deliberately separate from
 * {@see Interface_WP_MCP_AI_Provider_Client}: decision clients cannot
 * back a chat assistant and must never be selected as a chat provider.
 *
 * Concrete implementations live in `includes/infrastructure/providers/`
 * and the underlying HTTP clients in `includes/`.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstraction for AI decision-model provider clients.
 *
 * All parameters and return values use plain PHP arrays rather than
 * framework-specific objects to keep the interface dependency-free.
 *
 * @since 1.2.0
 */
interface Interface_WP_MCP_AI_Decision_Client {

	/**
	 * Send a decision request and return typed answers.
	 *
	 * @param mixed $state     The content to evaluate: a plain string, or
	 *                         structured data (object/array). Text only —
	 *                         images, audio and video must be preprocessed
	 *                         before being passed as state.
	 * @param array $questions Map of question name => definition. Each
	 *                         definition has:
	 *                         - type:         'choice' | 'score' | 'noul'
	 *                         - instructions: string describing the judgment
	 *                         - criteria:     choice => map name=>description
	 *                                         score => ordered list of level
	 *                                         descriptions (2–10); noul omits
	 *                                         criteria entirely.
	 * @param array $options   Provider-specific options:
	 *                         - model (string): Override the model
	 *                           (e.g. "jev-1.13.0").
	 *                         - timeout (int): HTTP timeout in seconds.
	 * @return array|WP_Error Normalised decision response or WP_Error. The
	 *                        response carries at minimum:
	 *                        - model:   versioned model id that answered
	 *                        - answers: map of question name => array with
	 *                          'type' plus the typed value ('choice',
	 *                          'score', or 'noul'), 'probabilities' and
	 *                          'confidence' where the provider supplies them
	 *                        - usage:   array with input/output token counts.
	 */
	public function decide( $state, $questions, $options = array() );

	/**
	 * Return the provider slug (e.g. 'typesafe').
	 *
	 * Used to identify the provider in diagnostics and cost tracking.
	 *
	 * @return string
	 */
	public function get_provider_slug();
}
