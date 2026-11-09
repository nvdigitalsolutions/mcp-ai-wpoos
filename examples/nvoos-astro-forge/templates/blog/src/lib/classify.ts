// ---------------------------------------------------------------------------
// classify.ts — the one classifier entry point for a page body.
//
// DesignRenderer and the page's JSON-LD graph both need the same sections from
// the same body with the same learned vocabulary. Classifying twice with two
// different option sets would let the rendered bands and the structured data
// describe different pages, so both call this.
// ---------------------------------------------------------------------------
import manifestJson from '../../nvoos.json';
import { classifyHtml, type SemanticSection } from '../forge/semantic.ts';
import { vocabulary } from './vocabulary.generated.ts';

// The manifest's inferred JSON type has no `forms` key on profiles that omit
// the block — read it through an explicit shape.
const manifest = manifestJson as { forms?: { searchForms?: string[] } };

/** Classify a sanitized builder body into semantic sections. */
export function classifyBody( html: string ): SemanticSection[] {
	if ( ! html ) {
		return [];
	}
	return classifyHtml( html, {
		searchForms: manifest.forms?.searchForms,
		// The learned vocabulary (Proposal 116) wins over the seeded classifier
		// defaults — per-site, baked at scaffold time.
		widgetRoles: vocabulary.widgetRoles,
		blockRoles: vocabulary.blockRoles,
	} );
}
