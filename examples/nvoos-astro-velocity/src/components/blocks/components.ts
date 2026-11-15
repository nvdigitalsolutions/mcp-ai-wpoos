// ---------------------------------------------------------------------------
// components.ts — the Portable Text component map (Proposal 107
// DECISION-2, shared in 108 Phase 1.3). Blocks.astro and TabsBlock.astro
// both render Portable Text (nested tab content), so the map lives here —
// one source of truth, no circular component imports.
//
// @portabletext/astro 0.2.0 component map — SINGULAR keys (`type`/`mark`),
// not the React-style plurals. Custom types map one component each; the
// `link` mark overrides the library default.
// ---------------------------------------------------------------------------

import HeroBlock from './HeroBlock.astro';
import ImageBlock from './ImageBlock.astro';
import ProductGridBlock from './ProductGridBlock.astro';
import PromotionBlock from './PromotionBlock.astro';
import TestimonialBlock from './TestimonialBlock.astro';
import FaqBlock from './FaqBlock.astro';
import StatBlock from './StatBlock.astro';
import ComparisonBlock from './ComparisonBlock.astro';
import StepsBlock from './StepsBlock.astro';
import TeamBlock from './TeamBlock.astro';
import TabsBlock from './TabsBlock.astro';
import LinkMark from './LinkMark.astro';

export const TYPE_COMPONENTS = {
	hero: HeroBlock,
	image: ImageBlock,
	productGrid: ProductGridBlock,
	promotion: PromotionBlock,
	testimonial: TestimonialBlock,
	faq: FaqBlock,
	stat: StatBlock,
	comparison: ComparisonBlock,
	steps: StepsBlock,
	team: TeamBlock,
	tabs: TabsBlock,
};

export const MARK_COMPONENTS = {
	link: LinkMark,
};

export const PORTABLE_TEXT_COMPONENTS = {
	type: TYPE_COMPONENTS,
	mark: MARK_COMPONENTS,
};
