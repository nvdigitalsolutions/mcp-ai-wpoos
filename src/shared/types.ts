/**
 * Shared types for NV oOS React SPAs.
 *
 * Import from here in any SPA component to get type-checked props,
 * API responses, and utility types without duplicating definitions.
 *
 * @package NV_MCP_AI
 * @since   1.2.0
 */

// ── WPi18n global (set by @wordpress/i18n or wp.i18n) ────────────────

declare const __: ( text: string, domain?: string ) => string;

// ── SPA config ───────────────────────────────────────────────────────

export interface SpaConfig {
	ajaxUrl: string;
	nonce: string;
}

// ── Template Builder ─────────────────────────────────────────────────

export interface TemplateMeta {
	slug: string;
	name: string;
	description: string;
	icon: string;
	accent_color: string;
	toolkit?: string;
	custom_css?: string;
}

export interface TemplateBuilderConfig extends SpaConfig {
	templatesUrl: string;
	saveUrl: string;
	activeTemplate: string;
	previewBaseUrl: string;
	customizeUrl: string;
}

export interface TemplateCardProps {
	template: TemplateMeta;
	isActive: boolean;
	isPreviewing: boolean;
	isEditing: boolean;
	onSelect: ( slug: string ) => void;
	onPreview: ( slug: string ) => void;
	onEdit: ( slug: string ) => void;
}

export interface TemplateEditorProps {
	template: TemplateMeta;
	config: TemplateBuilderConfig | SpaConfig;
	onClose: () => void;
	onSaved: ( updated: TemplateMeta ) => void;
	onReset: ( updated: TemplateMeta ) => void;
}

export interface PreviewPaneProps {
	slug: string;
	previewBaseUrl: string;
}

// ── Workflow Builder ─────────────────────────────────────────────────

export interface WorkflowNode {
	id: string;
	type: string;
	position: { x: number; y: number };
	data: WorkflowNodeData;
}

export interface WorkflowNodeData {
	label: string;
	[key: string]: unknown;
}

export interface WorkflowEdge {
	id: string;
	source: string;
	target: string;
	sourceHandle?: string;
	targetHandle?: string;
	type?: string;
}

export interface WorkflowConfig {
	nodes: WorkflowNode[];
	edges: WorkflowEdge[];
}

// ── TMA Shop (shared across woo/shopify SPAs) ────────────────────────

export interface Product {
	id: string | number;
	name: string;
	price: string | number;
	image?: string;
	description?: string;
	category?: string;
	[key: string]: unknown;
}

export interface CartItem {
	product: Product;
	quantity: number;
}

export interface Order {
	id: string;
	items: CartItem[];
	total: string | number;
	status: string;
	created_at?: string;
}

// ── Assistant (shared across TMA assistant pages) ────────────────────

export interface AssistantMessage {
	role: 'user' | 'assistant' | 'system';
	content: string;
	id?: string;
}
