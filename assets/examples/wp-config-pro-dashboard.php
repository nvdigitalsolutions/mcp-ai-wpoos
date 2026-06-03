<?php
/**
 * wp-config.php snippet for enabling NV oOS Pro Dashboard
 *
 * Add this line to your wp-config.php file to enable the Pro Dashboard.
 * Place it anywhere before the "That's all, stop editing!" comment.
 *
 * @package WP_MCP_AI
 * @see     docs/operations/compliance/iso27001/Pro-Dashboard-Activation.md
 */

// Enable NV oOS Pro Dashboard with ISO 27001 compliance features
define( 'WP_MCP_AI_PRO_DASHBOARD_ENABLED', true );

/**
 * When enabled, the Pro Dashboard provides:
 * - ISO/IEC 27001 compliance monitoring
 * - Automated report generation
 * - Real-time security monitoring
 * - Risk matrix visualization
 * - Multi-framework compliance tracking
 * - Audit trail and logging
 * - SIEM integration capabilities
 *
 * The constant takes priority over license validation and the legacy filter.
 * Set to false or remove the line to disable.
 *
 * Alternative (legacy) activation method using filter:
 * add_filter( 'wp_mcp_ai_pro_dashboard_available', '__return_true' );
 */
