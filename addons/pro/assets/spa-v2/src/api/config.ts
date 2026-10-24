/**
 * Pro SPA v2 — typed runtime config reader.
 *
 * Reads `window.NVOOS_PRO_SPA` localized by the PHP admin page enqueue.
 * Keep this file the single source of truth for the SPA's runtime config shape.
 */

export interface ProSpaPerInstanceConfig {
	assistantId?: number;
	theme?: 'auto' | 'light' | 'dark' | string;
	allowSensitiveTools?: boolean;
	/** Vector store ID (mirrors legacy chat.js preloading). */
	vectorStoreId?: number | string;
	/**
	 * Render mode.
	 * - `admin`: full three-column layout with router (default).
	 * - `embedded`: chat-first, router-free surface for the [nvoos_pro_spa]
	 *   shortcode.
	 */
	mode?: 'admin' | 'embedded';
	/** CSS height for embedded instances (e.g. "720px"). */
	height?: string;
	/** Guest mode — anonymous visitors authenticated via a guest token. */
	guest?: boolean;
	/** Server-minted guest access token sent via X-WP-MCP-AI-Guest. */
	guestToken?: string;
	/** Whether embedded mode renders the transcripts sidebar. */
	showSidebar?: boolean;
	/**
	 * Whether embedded mode renders the assistant switcher for logged-in users
	 * (admin surface for non-admin operators: default; shortcode instances:
	 * opt-in via assistant_selector="1").
	 */
	assistantSelector?: boolean;
	/**
	 * Whether the SPA connects to the cron-status job stream on mount
	 * (blocking SSE + REST poll fallback). Defaults to true. Set false for
	 * embedded surfaces on constrained hosts (e.g. WordPress Playground),
	 * where the held-open SSE connection exhausts the worker's request
	 * budget. Mirrors the [nvoos_pro_spa cron_monitor="0"] attribute.
	 */
	cronMonitor?: boolean;
	/** Route allowlist for the instance (embedded mode: chat only). */
	routes?: string[];
	/**
	 * Resolved chat profile slug governing tool execution (2.2.0).
	 * Read-only blocks writes, state changes and destructive tools.
	 */
	profile?: string;
	/**
	 * Whether the chat-profile selector should render for this instance.
	 * True on the admin surface for logged-in users when the feature is
	 * enabled server-side.
	 */
	profileSelector?: boolean;
	/**
	 * True when the shortcode explicitly pins a single assistant (an
	 * `assistant_id` attribute was provided). Pinned surfaces ignore the
	 * remembered assistant selection so a stale localStorage value cannot
	 * silently rebind the conversation to another assistant.
	 */
	pinnedAssistant?: boolean;
}

/** A chat profile as localized by the server (2.2.0). */
export interface ChatProfileSummary {
	slug: string;
	label: string;
	description: string;
	/** Whether the current user may select this profile. */
	selectable: boolean;
	is_default: boolean;
}

export interface ProSpaEndpoints {
	chat: string;
	chatClient: string;
	transcripts: string;
	memory: string;
	threads: string;
	tools: string;
	/** WordPress media upload endpoint (v0.9.0). */
	upload: string;
	assistants: string;
	settings: string;
	workflows: string;
	analytics: string;
	approvals: string;
	/** Workflow triggers REST base (mcp-ai/v1/orchestration/triggers, 2.4.0). */
	triggers: string;
	/** Teams REST base (mcp-ai/v1/teams, 2.4.0). */
	teams: string;
	/** Assistant portability REST base (mcp-ai/v1/assistants, 2.4.0). */
	portability: string;
	/** Security Center REST base (mcp-ai/v1/security, 2.4.0). */
	security: string;
	/** Operations REST base (mcp-ai/v1/operations, 2.4.0). */
	operations: string;
	/** Diagnostics REST base (mcp-ai/v1/diagnostics, 2.4.0). */
	diagnostics: string;
	/** Paper Store REST base (mcp-ai/v1/paper-store, 2.4.0). */
	paperStore: string;
	/** Token Manager REST base (mcp-ai/v1/token-manager, 2.4.0). */
	tokenManager: string;
	/**
	 * Cost analytics REST base (mcp-ai/v1/cost, 2.4.0) — the console
	 * Analytics page's cost summary/trend/provider charts. Empty for
	 * non-admin sessions (the endpoints are manage_options-gated).
	 */
	cost: string;
	/** Orchestration stats REST base (mcp-ai/v1/orchestration/stats, 2.4.0). */
	orchestrationStats: string;
	/** Pro ops REST base (mcp-ai-pro/v1/ops, 2.4.0). */
	proOps: string;
	/** Schedule Manager REST base (mcp-ai-pro/v1 schedules + presets, 2.4.0). */
	scheduleManager: string;
	/** Workflow builder REST base (mcp-ai/v1/orchestration/workflows, 2.4.0). */
	workflowBuilder: string;
	/** Workflow presets REST base (mcp-ai-pro/v1/workflow-presets, 2.4.0). */
	workflowPresets: string;
	/** Workflow node execution REST base (mcp-ai-pro/v1/workflow-builder, 2.4.0). */
	workflowNodes: string;
	/** Workflow runs REST base (mcp-ai/v1/orchestration/runs, 2.4.0). */
	workflowRuns: string;
	/** Core wp/v2 REST base — the Records feature's wp-core storage strategy (2.4.0). */
	wpBase: string;
	/** Records schema REST base (mcp-ai-pro/v1/records/schema, 2.4.0). */
	recordsSchema: string;
	/** Dashboards schema REST base (mcp-ai-pro/v1/dashboards/schema, 2.4.0). */
	dashboardsSchema: string;
	/** Chat channels REST base (mcp-ai-pro/v1/chat-channels, 2.4.0). */
	chatChannels: string;
	/** Media command-center REST base (mcp-ai-pro/v1/media-cc, 2.4.0). */
	mediaCc: string;
	/**
	 * Assistant blueprints REST base (mcp-ai-pro/v1/blueprints, 2.4.0) —
	 * the blueprint catalogue + install flow. Empty for sessions without
	 * edit_posts (the endpoints mirror the wp-admin page's gate).
	 */
	blueprints: string;
	/**
	 * Command Center overview REST base (mcp-ai-pro/v1/command-center,
	 * 2.4.0) — the console Dashboard's KPI + activity feed. Empty for
	 * non-admin sessions.
	 */
	commandCenter: string;
	shortcuts: string;
	slashCommands: string;
	/** OKF Skills & Knowledge browse endpoint (v2.1.1). */
	okf: string;
	/**
	 * NV oOS Content Graph REST base (nvoos-content-graph/v1) — the
	 * knowledge-graph explorer page. Empty when the site does not expose
	 * the plugin, and the page renders an unavailable state.
	 */
	contentGraph: string;
	/**
	 * NV oOS Docs Hub REST base (nvoos-docs/v1) — the #/docs page mounts the
	 * vendored Docs Hub island against it. Empty when the site does not
	 * expose the plugin, and the page renders an unavailable state.
	 */
	docsHub: string;
	/**
	 * NV oOS Media Studio REST base (nvoos-media-studio/v1) — the #/media page
	 * mounts the island against it. Empty when the site does not expose the
	 * addon, and the page renders an unavailable state.
	 */
	mediaStudio: string;
	/**
	 * Media Studio island bundle URL (lazy-loaded script asset) — empty when
	 * the addon is not exposed by the site.
	 */
	mediaStudioAsset: string;
	/** Media Studio island stylesheet URL (lazy-loaded link asset). */
	mediaStudioCss: string;
	/**
	 * NV oOS Document Editor REST base (nvoos-document-editor/v1) — the
	 * #/documents page mounts the island against it. Empty when the site does
	 * not expose the addon, and the page renders an unavailable state.
	 */
	documentEditor: string;
	/**
	 * Document Editor island bundle URL (lazy-loaded script asset) — empty
	 * when the addon is not exposed by the site.
	 */
	documentEditorAsset: string;
	/** Document Editor island stylesheet URL (lazy-loaded link asset). */
	documentEditorCss: string;
}

export interface RuntimeAssistantSummary {
	id: number;
	title: string;
	provider?: string;
	model?: string;
}

export interface ProSpaRuntime {
	apiUrl: string;
	proApi: string;
	nonce: string;
	config: ProSpaPerInstanceConfig;
	endpoints: ProSpaEndpoints;
	user: ProSpaUser;
	mentionTypes: MentionType[];
	/** Pre-loaded assistants from the server — avoids a separate REST round-trip. */
	assistants?: RuntimeAssistantSummary[];
	/** Chat profile catalogue (2.2.0). */
	profiles?: ChatProfileSummary[];
	/**
	 * Server-reported feature availability (2.4.0, Proposal 084 Phase 0).
	 * Maps feature keys (docs_hub, knowledge_graph, …) to booleans; surfaces
	 * gate their navigation on it. Absent = unknown = fail open.
	 */
	features?: Record< string, boolean >;
}

export interface ProSpaUser {
	id: number;
	login: string;
	displayName: string;
	capabilities: string[];
	assistant_id?: number;
}

export interface MentionType {
	type: string;
	label: string;
	icon?: string;
}

export type ProSpaConfig = ProSpaPerInstanceConfig;

/**
 * Overlay per-instance overrides (the shortcode's data-config JSON) onto the
 * global runtime and store the result back on `window.NVOOS_PRO_SPA`.
 *
 * Every existing consumer (useBootstrap, Layout, ChatPage, useChatSpoke)
 * reads the runtime through readProSpaConfig(), so writing the merged value
 * back to the global makes per-instance config transparent to the whole app.
 *
 * NOTE: the global is shared — this intentionally supports a single embedded
 * instance per page (documented shortcode constraint for v1).
 */
export function applyPerInstanceConfig( perInstance: unknown ): ProSpaRuntime | null {
	if ( typeof window === 'undefined' ) {
		return null;
	}
	const runtime = readProSpaConfig();
	if ( ! runtime ) {
		return null;
	}
	if ( ! perInstance || typeof perInstance !== 'object' || Array.isArray( perInstance ) ) {
		return runtime;
	}
	const overrides = perInstance as Partial< ProSpaPerInstanceConfig >;
	const next: ProSpaRuntime = {
		...runtime,
		config: {
			...runtime.config,
			...( overrides as ProSpaPerInstanceConfig ),
		},
	};
	( window as unknown as { NVOOS_PRO_SPA?: ProSpaRuntime } ).NVOOS_PRO_SPA = next;
	return next;
}

/**
 * Read the localized runtime config emitted by `wp_localize_script()`.
 *
 * Returns `null` when the global is missing or malformed so the App can render
 * a graceful fallback instead of throwing.
 */
export function readProSpaConfig(): ProSpaRuntime | null {
	if ( typeof window === 'undefined' ) {
		return null;
	}
	const g = ( window as unknown as { NVOOS_PRO_SPA?: Partial< ProSpaRuntime > } )
		.NVOOS_PRO_SPA;
	if ( ! g || typeof g.apiUrl !== 'string' || ! g.endpoints ) {
		return null;
	}
	const e = g.endpoints as Partial< ProSpaEndpoints >;
	if (
		typeof e.chatClient !== 'string' ||
		typeof e.chat !== 'string' ||
		typeof e.transcripts !== 'string' ||
		typeof e.threads !== 'string' ||
		typeof e.tools !== 'string' ||
		typeof e.assistants !== 'string' ||
		typeof e.settings !== 'string'
	) {
		return null;
	}
	return {
		apiUrl: g.apiUrl,
		proApi: typeof g.proApi === 'string' ? g.proApi : '',
		nonce: typeof g.nonce === 'string' ? g.nonce : '',
		config: ( g.config ?? {} ) as ProSpaPerInstanceConfig,
		endpoints: {
			chat: e.chat,
			chatClient: e.chatClient,
			transcripts: e.transcripts,
			memory: typeof e.memory === 'string' ? e.memory : '',
			threads: e.threads,
			tools: e.tools,
			upload: typeof e.upload === 'string' ? e.upload : '',
			assistants: e.assistants,
			settings: e.settings,
			workflows: typeof e.workflows === 'string' ? e.workflows : '',
			analytics: typeof e.analytics === 'string' ? e.analytics : '',
			approvals: typeof e.approvals === 'string' ? e.approvals : '',
			triggers: typeof e.triggers === 'string' ? e.triggers : '',
			teams: typeof e.teams === 'string' ? e.teams : '',
			portability: typeof e.portability === 'string' ? e.portability : '',
			security: typeof e.security === 'string' ? e.security : '',
			operations: typeof e.operations === 'string' ? e.operations : '',
			diagnostics: typeof e.diagnostics === 'string' ? e.diagnostics : '',
			paperStore: typeof e.paperStore === 'string' ? e.paperStore : '',
			tokenManager: typeof e.tokenManager === 'string' ? e.tokenManager : '',
			cost: typeof e.cost === 'string' ? e.cost : '',
			orchestrationStats: typeof e.orchestrationStats === 'string' ? e.orchestrationStats : '',
			proOps: typeof e.proOps === 'string' ? e.proOps : '',
			scheduleManager: typeof e.scheduleManager === 'string' ? e.scheduleManager : '',
			workflowBuilder: typeof e.workflowBuilder === 'string' ? e.workflowBuilder : '',
			workflowPresets: typeof e.workflowPresets === 'string' ? e.workflowPresets : '',
			workflowNodes: typeof e.workflowNodes === 'string' ? e.workflowNodes : '',
			workflowRuns: typeof e.workflowRuns === 'string' ? e.workflowRuns : '',
			wpBase: typeof e.wpBase === 'string' ? e.wpBase : '',
			recordsSchema: typeof e.recordsSchema === 'string' ? e.recordsSchema : '',
			dashboardsSchema: typeof e.dashboardsSchema === 'string' ? e.dashboardsSchema : '',
			chatChannels: typeof e.chatChannels === 'string' ? e.chatChannels : '',
			mediaCc: typeof e.mediaCc === 'string' ? e.mediaCc : '',
			blueprints: typeof e.blueprints === 'string' ? e.blueprints : '',
			commandCenter: typeof e.commandCenter === 'string' ? e.commandCenter : '',
			shortcuts: typeof e.shortcuts === 'string' ? e.shortcuts : '',
			slashCommands: typeof e.slashCommands === 'string' ? e.slashCommands : '',
			okf: typeof e.okf === 'string' ? e.okf : '',
			contentGraph: typeof e.contentGraph === 'string' ? e.contentGraph : '',
			docsHub: typeof e.docsHub === 'string' ? e.docsHub : '',
			mediaStudio: typeof e.mediaStudio === 'string' ? e.mediaStudio : '',
			mediaStudioAsset: typeof e.mediaStudioAsset === 'string' ? e.mediaStudioAsset : '',
			mediaStudioCss: typeof e.mediaStudioCss === 'string' ? e.mediaStudioCss : '',
			documentEditor: typeof e.documentEditor === 'string' ? e.documentEditor : '',
			documentEditorAsset: typeof e.documentEditorAsset === 'string' ? e.documentEditorAsset : '',
			documentEditorCss: typeof e.documentEditorCss === 'string' ? e.documentEditorCss : '',
		},
		user: ( g.user ?? { id: 0, login: '', displayName: '', capabilities: [] } ) as ProSpaUser,
		mentionTypes: Array.isArray( g.mentionTypes ) ? g.mentionTypes : [],
		assistants: Array.isArray( g.assistants ) ? g.assistants : undefined,
		profiles: Array.isArray( g.profiles ) ? ( g.profiles as ChatProfileSummary[] ) : undefined,
		features:
			g.features && typeof g.features === 'object' && ! Array.isArray( g.features )
				? ( g.features as Record< string, boolean > )
				: undefined,
	};
}
