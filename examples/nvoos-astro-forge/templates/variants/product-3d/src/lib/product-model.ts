// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// product-model.ts — resolve a product document's 3D model (product-3d variant).
//
// POSTER-FIRST BY CONSTRUCTION: a product with no valid poster is treated as
// having no model at all. The poster is the no-JS, reduced-data and crawler
// experience, so returning null (and falling back to the normal product image)
// is always better than rendering an empty 3D box.
//
// Pure: no filesystem, no network, no Astro — so it is unit-tested directly.
// ---------------------------------------------------------------------------

/** A model/poster URL must be root-relative or https — never `//`, never another scheme. */
const SITE_ASSET = /^(?:\/(?!\/)|https:\/\/)/;

/** The fields this resolver reads from a commerce product document. */
export interface ProductDocLike {
	/** Operator-authored: `{ src, poster?, alt?, ar? }`. */
	model3d?: unknown;
	/** The catalogue's own images (`[{ src }]` or plain strings). */
	images?: unknown;
	/** Product name — the last-resort accessible name. */
	name?: unknown;
}

/** The viewer contract a page needs. */
export interface ProductModelSource {
	/** The .glb/.gltf the viewer loads. */
	src: string;
	/** The still that ships in the HTML and stands in for the model. */
	poster: string;
	/** Accessible name for the viewer. */
	alt: string;
	/** Opt in to AR only when the asset is actually AR-ready. */
	ar?: boolean;
}

/** True for a root-relative or https asset URL. */
export function isSiteAsset(value: unknown): value is string {
	return typeof value === 'string' && SITE_ASSET.test(value);
}

/** The first usable image URL on a product document. */
function firstImage(images: unknown): string | null {
	if (!Array.isArray(images)) {
		return null;
	}
	for (const image of images) {
		const candidate =
			typeof image === 'string' ? image : (image as { src?: unknown } | null)?.src;
		if (isSiteAsset(candidate)) {
			return candidate;
		}
	}
	return null;
}

/**
 * Resolve the viewer contract from a product document, or null when the product
 * has no usable model. Every fallback comes from data the catalogue already
 * carries, so an operator only has to author `model3d.src`.
 */
export function resolveProductModel(doc: ProductDocLike | null | undefined): ProductModelSource | null {
	const model = doc?.model3d as
		| { src?: unknown; poster?: unknown; alt?: unknown; ar?: unknown }
		| null
		| undefined;

	if (!model || !isSiteAsset(model.src)) {
		return null;
	}

	const poster = isSiteAsset(model.poster) ? model.poster : firstImage(doc?.images);
	if (!poster) {
		return null;
	}

	const authored = typeof model.alt === 'string' ? model.alt.trim() : '';
	const name = typeof doc?.name === 'string' ? doc.name.trim() : '';
	const alt = authored || name;
	if (!alt) {
		return null;
	}

	const resolved: ProductModelSource = { src: model.src, poster, alt };
	if (model.ar === true) {
		resolved.ar = true;
	}
	return resolved;
}
