// ---------------------------------------------------------------------------
// plan.ts — deterministic adaptation plan (Proposal 098 Phase 3).
//
// Content-first mapping: the extracted design language is translated into an
// execution plan FOR THE USER'S OWN CONTENT — keep / replace / remix per
// element, mapped onto the content slots of the target surface (storefront,
// blog, or marketing). Deterministic today; the `adaptationProvider` seam is
// where the Phase 3.2 LLM-assisted pass plugs in without changing the
// contract (provider absent → this fallback, per the plan's exit criteria).
// ---------------------------------------------------------------------------

import type { DesignAnalysisResult } from './types';

export type ContentHint = 'storefront' | 'blog' | 'marketing';

export interface AdaptationPlan {
	contentHint: ContentHint;
	brandDirection: string;
	paletteMapping: Array<{ role: string; value: string; slot: string }>;
	typographyMapping: Array<{ family: string; slot: string }>;
	componentChecklist: string[];
	steps: string[];
	provider: 'deterministic';
}

const SLOTS: Record<ContentHint, { palette: Record<string, string>; components: string[] }> = {
	storefront: {
		palette: {
			accent: 'CTAs, price highlights, sale badges',
			surface: 'page and card backgrounds',
			text: 'product titles and body copy',
			'field.border': 'search/quantity inputs and dividers',
		},
		components: ['product cards', 'category chips', 'promotion banners', 'cart summary', 'search field'],
	},
	blog: {
		palette: {
			accent: 'links, tags, and pull quotes',
			surface: 'article and code-block backgrounds',
			text: 'headings and reading text',
			'field.border': 'dividers, blockquote rules, form fields',
		},
		components: ['article cards', 'tag pills', 'newsletter signup', 'related-posts list', 'toc'],
	},
	marketing: {
		palette: {
			accent: 'hero CTAs and pricing highlights',
			surface: 'section alternation backgrounds',
			text: 'headlines and feature copy',
			'field.border': 'pricing table rows and form fields',
		},
		components: ['hero', 'pricing cards', 'testimonial grid', 'faq accordion', 'footer CTA'],
	},
};

/** The adaptation-provider seam: deterministic today, LLM-assisted in Phase 3.2. */
export type AdaptationProvider = (analysis: DesignAnalysisResult, contentHint: ContentHint) => AdaptationPlan;

export function buildAdaptationPlan(analysis: DesignAnalysisResult, contentHint: ContentHint = 'storefront'): AdaptationPlan {
	const { designLanguage } = analysis;
	const slots = SLOTS[contentHint];

	const paletteMapping = Object.entries(designLanguage.palette.roles)
		.filter(([role]) => slots.palette[role])
		.map(([role, entry]) => ({ role, value: entry.value, slot: slots.palette[role] }));

	const display = designLanguage.typography.families[0]?.family;
	const body = designLanguage.typography.families[1]?.family;
	const typographyMapping = [
		...(display ? [{ family: display, slot: 'headings, hero text, product titles' }] : []),
		...(body ? [{ family: body, slot: 'body copy, descriptions, forms' }] : []),
	];

	// The user's content drives the layout; the source only supplies the
	// component vocabulary — map hits onto content slots for the hint.
	const vocabulary = designLanguage.components.vocabulary;
	const componentChecklist = slots.components.filter((slot) => {
		const word = slot.split(' ')[0].replace(/-.*$/, '');
		return Object.keys(vocabulary).some((hit) => slot.includes(hit) || hit.includes(word));
	});
	if (componentChecklist.length === 0) {
		componentChecklist.push(`apply the ${contentHint} default layout with the imported palette + type`);
	}

	const brandDirection = [
		designLanguage.concept,
		designLanguage.adaptationBrief.remix[0] ?? 'Make it visibly the user\'s own brand.',
		designLanguage.adaptationBrief.remix[1] ?? 'Let the user\'s content drive the layout.',
	].join(' ');

	const steps = [
		'Review the roles and unassigned pool — assign only what you are confident about.',
		'Apply the semantic layer via tokens_import_dtcg (dry_run first, then apply).',
		'Run contrast_check on accent vs accent-ink and text vs surface.',
		'Create the runtime theme via design_theme, then theme_apply.',
		'Verify against the user\'s real content; remix the accent hue to separate from the source.',
	];

	return {
		contentHint,
		brandDirection,
		paletteMapping,
		typographyMapping,
		componentChecklist,
		steps,
		provider: 'deterministic',
	};
}
