// ---------------------------------------------------------------------------
// Design-language import types (Proposal 098).
//
// The DesignLanguage contract is the output of the import pipeline. It is
// deliberately a *description* — token data, prose, and an adaptation brief —
// never source CSS, markup, copy, or assets. The default and recommended
// mode is inspired-by recreation: the user's own content, re-skinned with
// the source's design language.
// ---------------------------------------------------------------------------

/** How the source was classified (Proposal 098 §3.3). */
export type SourceMode = 'astro-theme' | 'wordpress' | 'generic';

/** Provenance + classification of one analyzed source. */
export interface DesignSourceInfo {
	url: string;
	mode: SourceMode;
	fetchedAt: string;
}

/** One semantic palette role, with an honest confidence level. */
export interface PaletteRole {
	/** Normalized hex value (#rrggbb). */
	value: string;
	/**
	 * high = read from a semantic CSS custom property; medium = frequency +
	 * saturation heuristics; low = derived (e.g. accent-ink from luminance).
	 */
	confidence: 'high' | 'medium' | 'low';
	/** Where the value came from (e.g. 'css:--color-accent' | 'heuristic'). */
	source: string;
}

/**
 * The extracted design language — the whole point of the pipeline.
 *
 * Fail-loud role discipline (research §2): only confident roles are labeled;
 * everything else lands in `palette.unassigned`. A missing role is visible;
 * a mislabeled one gets used confidently everywhere.
 */
export interface DesignLanguage {
	/** One-paragraph deterministic summary of the design's character. */
	concept: string;
	palette: {
		/** Semantic roles: accent, 'accent-ink', surface, text, 'field.border'. */
		roles: Record<string, PaletteRole>;
		/** Everything extracted but not confidently labeled — the fail-loud pool. */
		unassigned: string[];
	};
	typography: {
		/** Ranked font stacks (weight = declaration count). */
		families: Array<{ family: string; weight: number }>;
		/** Distinct px sizes, ascending, capped. */
		scalePx: number[];
	};
	spacing: {
		/** Distinct px values from padding/margin/gap, ascending, capped. */
		scalePx: number[];
	};
	radii: {
		valuesPx: number[];
	};
	shadows: {
		count: number;
		/** Up to two example declarations (truncated). */
		samples: string[];
	};
	components: {
		/** Class-name lexicon hits — semantic vocabulary hints, never layouts. */
		vocabulary: Record<string, number>;
	};
	/**
	 * DTCG-shaped semantic draft carrying ONLY confident roles. Feed the 095
	 * importer (tokens_import_dtcg) after review — gaps stay missing on purpose.
	 */
	tokensDraft: {
		semantic: Record<string, string>;
	};
	adaptationBrief: {
		keep: string[];
		replace: string[];
		remix: string[];
		licensingNote: string;
		attribution: string[];
	};
}

/** Full result of one design_analyze run. */
export interface DesignAnalysisResult {
	source: DesignSourceInfo;
	designLanguage: DesignLanguage;
}
