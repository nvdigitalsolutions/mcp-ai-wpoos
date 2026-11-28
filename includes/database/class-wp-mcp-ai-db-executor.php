<?php
/**
 * WordPress binding of the host-neutral database executor.
 *
 * Proposal 167 phase P4. The read state machine — resolve, admit, open, begin
 * read-only, stream with caps, roll back, shape, mask, audit — lives in the
 * kernel as {@see \Nvoos\Database\Domain\Service\Database\PdoExecutor}, because
 * the Laravel runtime needs the identical ordering and a second copy would be a
 * second place for the guarantee to rot.
 *
 * What is left here is the WordPress wiring: option-backed registries, an
 * encrypted credential store, a transient cache, a bounded option audit log and
 * the site-database guard. Those are the parts that cannot leave the plugin, and
 * they are exactly the seven collaborator interfaces the kernel asks for.
 *
 * The class name and constructor signature are unchanged so the thirteen Pro
 * tools, the writer and the existing test suite keep working untouched.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Nvoos\Database\Domain\Service\Database\AdmissionClassifier;
use Nvoos\Database\Domain\Service\Database\CursorCodec;
use Nvoos\Database\Domain\Service\Database\ErrorMapper;
use Nvoos\Database\Domain\Service\Database\PdoExecutor;
use Nvoos\Database\Domain\Service\Database\ResultShaper;
use Nvoos\Database\Domain\Service\Database\ScopePolicy;
use Nvoos\Database\Domain\Service\Database\StatementGrammar;

/**
 * Scoped, audited query execution on WordPress.
 */
final class WP_MCP_AI_Db_Executor extends PdoExecutor {

	/**
	 * Constructor.
	 *
	 * The `$proposals` parameter is accepted for backwards compatibility — the
	 * read path never proposes a change, and the write path owns its own store.
	 *
	 * @param WP_MCP_AI_Db_Connection_Registry $connections   Connections.
	 * @param WP_MCP_AI_Db_Scope_Registry      $scopes        Scopes.
	 * @param WP_MCP_AI_Db_Catalogue           $catalogue     Catalogue loader.
	 * @param WP_MCP_AI_Db_Connection_Factory  $factory       Factory.
	 * @param WP_MCP_AI_Db_Audit_Log           $audit         Audit log.
	 * @param WP_MCP_AI_Db_Redactor            $redactor      Redactor.
	 * @param string                           $cursor_secret HMAC key for cursors.
	 * @param WP_MCP_AI_Db_Proposal_Store|null $proposals     Unused by the read path.
	 * @param Closure|null                     $tenant        Returns the current tenant id, or null.
	 * @param StatementGrammar|null            $grammar       Grammar override.
	 * @param AdmissionClassifier|null         $admission     Admission override.
	 * @param ScopePolicy|null                 $policy        Policy override.
	 * @param ResultShaper|null                $shaper        Shaper override.
	 * @param CursorCodec|null                 $cursors       Cursor override.
	 * @param ErrorMapper|null                 $errors        Error mapper override.
	 */
	public function __construct(
		WP_MCP_AI_Db_Connection_Registry $connections,
		WP_MCP_AI_Db_Scope_Registry $scopes,
		WP_MCP_AI_Db_Catalogue $catalogue,
		WP_MCP_AI_Db_Connection_Factory $factory,
		WP_MCP_AI_Db_Audit_Log $audit,
		WP_MCP_AI_Db_Redactor $redactor,
		string $cursor_secret,
		?WP_MCP_AI_Db_Proposal_Store $proposals = null,
		?Closure $tenant = null,
		?StatementGrammar $grammar = null,
		?AdmissionClassifier $admission = null,
		?ScopePolicy $policy = null,
		?ResultShaper $shaper = null,
		?CursorCodec $cursors = null,
		?ErrorMapper $errors = null
	) {
		unset( $proposals );

		parent::__construct(
			$connections,
			$scopes,
			$catalogue,
			$factory,
			$audit,
			$redactor,
			$cursor_secret,
			$tenant,
			$grammar,
			$admission,
			$policy,
			$shaper,
			$cursors,
			$errors
		);
	}
}
