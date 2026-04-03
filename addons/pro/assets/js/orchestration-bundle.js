/**
 * Orchestration Bundle Entry Point
 * 
 * Bundles opossum + p-queue with autonomous orchestrator
 * for circuit breaker pattern and rate limiting.
 * 
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

import AutonomousOrchestrator from './orchestration/autonomous-orchestrator.js';

// Export to window for WordPress
window.WpMcpAiOrchestrator = AutonomousOrchestrator;

// Also export as module for modern builds
export default AutonomousOrchestrator;
