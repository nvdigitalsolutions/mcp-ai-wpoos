/**
 * NV oOS Media Studio — typed REST helpers for the AI bridge.
 *
 * Talks to `/nvoos-media-studio/v1/ai/*` with cookie nonce auth from the
 * bootstrap localize (`window.NVOOS_MEDIA_STUDIO`). The SPA never holds
 * provider credentials.
 *
 * @since 0.2.0
 */

export interface CapabilityTransform {
	available: boolean;
	backend: string;
	fidelity: string;
	requires_consent: boolean;
}

export interface CapabilitiesSettings {
	ai_disclosure: string;
	watermark_face: boolean;
	require_ack: boolean;
	per_image_ceiling: number;
	per_job_ceiling: number;
	hard_cap: number;
}

export interface Capabilities {
	version: string;
	providers: Record< string, { label: string; configured: boolean } >;
	transforms: Record< string, CapabilityTransform >;
	profiles: Record< string, OutputProfile >;
	settings: CapabilitiesSettings;
	sidecar: boolean;
	wc_active: boolean;
	pro_active: boolean;
	batch: boolean;
}

export interface OutputProfile {
	label: string;
	min_side: number;
	square: boolean;
	format: string;
	white_bg: boolean;
}

export interface ProcessedPayload {
	attachment_id: number;
	url: string;
	mime_type: string;
	profile: string;
	source_id: number;
	upscaled: boolean;
	alt_text: string;
	xmp_embedded: boolean;
	c2pa_signed: boolean;
	white_background: WhiteBackgroundCheck | null;
}

export interface FashionModel {
	id: number;
	name: string;
	thumb_url: string;
	gender: string;
	skin_tone: string;
	body_type: string;
	age_group: string;
	consent_status: string;
	is_custom: boolean;
}

export interface BatchVariant {
	key: number;
	source_id: number;
	status: string;
	attachment_id: number;
	url: string;
	processed_id: number;
	processed_url: string;
	error: string;
	export_error: string;
	reroll_of: number;
}

export interface FashionJob {
	id: number;
	status: string;
	transform: string;
	total: number;
	done: number;
	estimate_usd: number | null;
	per_image_usd: number | null;
	product_id: number;
	collection_id: number;
	profile: string;
	created: string;
	variants: BatchVariant[];
}

export interface CreateJobArgs {
	attachment_ids: number[];
	transform: string;
	description?: string;
	color?: string;
	background_style?: string;
	aspect_ratio?: string;
	mime_type?: string;
	identity_id?: number;
	product_id?: number;
	collection_id?: number;
	profile?: string;
	alt_text?: boolean;
	confirmed?: boolean;
	acknowledged?: boolean;
}

export interface ReviewInfo {
	required: boolean;
	blocked: boolean;
	reason: string;
	estimate_usd: number | null;
	per_image_usd: number | null;
}

export interface WhiteBackgroundCheck {
	is_white: boolean;
	max_delta: number;
	tolerance: number;
}

export interface GenerateArgs {
	attachment_id: number;
	transform: string;
	description?: string;
	color?: string;
	background_style?: string;
	aspect_ratio?: string;
	mime_type?: string;
	identity_id?: number;
	count?: number;
	seed?: number;
	duration?: number;
	confirmed?: boolean;
	acknowledged?: boolean;
}

export interface GenerateResult {
	attachment_id: number;
	url: string;
	transform: string;
	provider: string;
	model: string;
	disclosure: string;
	watermarked: boolean;
	xmp_embedded: boolean;
	c2pa_signed: boolean;
	estimate_usd: number | null;
	per_image_usd: number | null;
	white_background?: WhiteBackgroundCheck | null;
	video_url?: string;
	prediction_id?: string;
	duration?: number;
}

export interface Preset {
	slug: string;
	label: string;
	transform: string;
	background?: string;
}

export interface ImportResult {
	attachment_id: number;
	url: string;
	mime_type: string;
	title: string;
	ai_generated: boolean;
	transform: string;
}

export interface ExportResult {
	attachment_id: number;
	url: string;
	mime_type: string;
	ai_generated: boolean;
}

export interface AiApiErrorShape extends Error {
	status: number;
	code: string;
	data: Record< string, unknown > | null;
	review: ReviewInfo | null;
}

interface BootstrapGlobal {
	apiUrl: string;
	nonce: string;
}

function getGlobals(): BootstrapGlobal {
	const globalScope = window as unknown as { NVOOS_MEDIA_STUDIO?: BootstrapGlobal };
	return globalScope.NVOOS_MEDIA_STUDIO ?? { apiUrl: '', nonce: '' };
}

async function request< T >( path: string, options: RequestInit = {} ): Promise< T > {
	const globals = getGlobals();
	const response = await fetch( `${ globals.apiUrl }${ path }`, {
		...options,
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': globals.nonce,
			...( options.headers ?? {} ),
		},
	} );
	const body = ( await response.json().catch( () => ( {} ) ) ) as Record< string, unknown >;
	if ( ! response.ok ) {
		const error = new Error(
			typeof body?.message === 'string' ? body.message : `Request failed (${ response.status })`
		) as AiApiErrorShape;
		error.status = response.status;
		error.code = typeof body?.code === 'string' ? body.code : 'http_error';
		error.data = ( body?.data as Record< string, unknown > | undefined ) ?? null;
		error.review =
			error.code === 'nvoos_ms_review_required' && error.data?.review
				? ( error.data.review as ReviewInfo )
				: null;
		throw error;
	}
	return body as T;
}

export const aiApi = {
	capabilities: () => request< Capabilities >( '/ai/capabilities' ),
	presets: () => request< Preset[] >( '/ai/presets' ),
	models: () => request< FashionModel[] >( '/ai/models' ),
	generate: ( args: GenerateArgs ) =>
		request< GenerateResult >( '/ai/generate', { method: 'POST', body: JSON.stringify( args ) } ),
	createJob: ( args: CreateJobArgs ) =>
		request< FashionJob >( '/ai/jobs', { method: 'POST', body: JSON.stringify( args ) } ),
	listJobs: () => request< FashionJob[] >( '/ai/jobs' ),
	getJob: ( jobId: number ) => request< FashionJob >( `/ai/jobs/${ jobId }` ),
	reviewJob: (
		jobId: number,
		args: { variant_key: number; action: 'approve' | 'reject' | 'reroll'; product_id?: number; collection_id?: number }
	) =>
		request< BatchVariant >( `/ai/jobs/${ jobId }/review`, {
			method: 'POST',
			body: JSON.stringify( args ),
		} ),
	runPipeline: ( attachmentId: number, profile: string, altText: boolean = true ) =>
		request< ProcessedPayload >( '/ai/pipeline', {
			method: 'POST',
			body: JSON.stringify( { attachment_id: attachmentId, profile, alt_text: altText } ),
		} ),
	importAttachment: ( attachmentId: number ) =>
		request< ImportResult >( '/ai/import', {
			method: 'POST',
			body: JSON.stringify( { attachment_id: attachmentId } ),
		} ),
	exportImage: (
		dataUrl: string,
		args: {
			file_name?: string;
			title?: string;
			ai_generated?: boolean;
			transform?: string;
			prompt?: string;
		}
	) =>
		request< ExportResult >( '/ai/export', {
			method: 'POST',
			body: JSON.stringify( { data_url: dataUrl, ...args } ),
		} ),
};
