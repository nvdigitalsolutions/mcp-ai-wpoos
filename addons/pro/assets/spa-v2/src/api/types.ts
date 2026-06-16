/**
 * Pro SPA v2 — Shared type re-exports.
 *
 * Single import point for all API DTOs used across the SPA.
 * Import from './api/types' instead of individual api/* modules.
 *
 * @since 2.0.0
 */

export type { ProSpaRuntime, ProSpaEndpoints, ProSpaPerInstanceConfig, ProSpaUser, MentionType } from './config';

export type { TranscriptSession, TranscriptListResponse, TranscriptMessage, TranscriptDetailResponse } from './transcripts';

export type { ThreadSummary, ThreadListResponse, ThreadMessage, ThreadMessagesResponse } from './threads';

export type { ToolDefinition, ToolsListResponse } from './tools';

export type { PluginSettings, ProviderSettings } from './settings';

export type { AssistantRecord, AssistantsListResponse } from './assistants';

export type { MemoryPreferences, MemoryContext, AuditEntry } from './memory';

export type { ApprovalRecord } from './hitl';
