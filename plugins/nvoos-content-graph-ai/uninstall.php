<?php
/**
 * Uninstall handler for NV oOS Content Graph — AI.
 *
 * Removes only the options owned by this addon:
 *  - nvoos_content_graph_ai_credentials (encrypted provider keys)
 *  - nvoos_content_graph_ai_credentials_migrated (migration flag)
 *
 * The parent plugin's options and tables are never touched here — the
 * core plugin owns its own cleanup.
 *
 * @package NvoosContentGraphAi
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'nvoos_content_graph_ai_credentials' );
delete_option( 'nvoos_content_graph_ai_credentials_migrated' );
