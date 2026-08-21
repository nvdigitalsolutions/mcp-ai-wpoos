/**
 * HTML → structured-data extraction helpers for the crawl routes (static
 * tier). No network access happens in this module — callers fetch the HTML
 * (after SSRF validation) and pass it in.
 *
 * Pipeline (industry standard, cf. crawldown / pullmd / Firefox Reader View):
 *   1. jsdom parses the HTML (scripts never execute — jsdom's default).
 *   2. Mozilla Readability isolates the main article content.
 *   3. Turndown converts the article to Markdown.
 *   4. The DOM is scanned for links; Cheerio handles CSS-schema extraction
 *      (the JsonCssExtractionStrategy-style contract used by Crawl4AI).
 */

import { JSDOM } from 'jsdom';
import { Readability } from '@mozilla/readability';
import TurndownService from 'turndown';
import { load } from 'cheerio';

const turndown = new TurndownService( {
	headingStyle: 'atx',
	codeBlockStyle: 'fenced',
} );

// Readability's default char threshold is 500 — shorter pages parse as
// "no article". Lower it so short-but-real pages still extract; callers
// decide meaningfulness via word count (the CRAWL_MIN_TEXT_CHARS gate).
const READABILITY_CHAR_THRESHOLD = 100;

/**
 * Count whitespace-separated words in a string.
 *
 * @param {string} text Raw text.
 * @return {number} Word count (0 for empty input).
 */
export function wordCount( text ) {
	return ( String( text || '' ).match( /\S+/g ) || [] ).length;
}

/**
 * Whether a link target belongs to the same site as the source URL
 * (scheme- and hostname-based, ignoring a leading "www.").
 *
 * @param {string} href      Link target.
 * @param {string} sourceUrl Source page URL.
 * @return {boolean} True when the link is internal.
 */
export function isInternalHref( href, sourceUrl ) {
	try {
		const link = new URL( href, sourceUrl || 'about:blank' );
		const source = new URL( sourceUrl || 'about:blank' );
		return (
			'http:' === link.protocol || 'https:' === link.protocol
		) && link.hostname.replace( /^www\./, '' ) === source.hostname.replace( /^www\./, '' );
	} catch {
		return false;
	}
}

/**
 * Extract the main article, links, and metadata from raw HTML.
 *
 * @param {string} html      Raw HTML document.
 * @param {string} sourceUrl Final URL of the page (resolves relative links).
 * @return {Object} Extracted content (never throws on parse failures).
 */
export function extractFromHtml( html, sourceUrl = '' ) {
	const dom = new JSDOM( html, { url: sourceUrl || 'about:blank' } );
	const doc = dom.window.document;

	const links = [];
	doc.querySelectorAll( 'a[href]' ).forEach( ( anchor ) => {
		links.push( {
			href: anchor.href,
			text: ( anchor.textContent || '' ).trim().slice( 0, 300 ),
			is_internal: isInternalHref( anchor.href, sourceUrl ),
		} );
	} );

	const article = new Readability( doc, { charThreshold: READABILITY_CHAR_THRESHOLD } ).parse();
	if ( ! article ) {
		return {
			title: doc.title || '',
			markdown: '',
			text: '',
			word_count: 0,
			byline: null,
			excerpt: null,
			site_name: null,
			links,
		};
	}

	return {
		title: article.title || doc.title || '',
		markdown: turndown.turndown( article.content || '' ),
		text: article.textContent || '',
		word_count: wordCount( article.textContent ),
		byline: article.byline || null,
		excerpt: article.excerpt || null,
		site_name: article.siteName || null,
		links,
	};
}

/**
 * Extract fields from HTML using a CSS schema (JsonCssExtractionStrategy
 * style): { baseSelector?, fields: [ { name, selector, type?, attribute? } ] }.
 *
 * @param {string} html   Raw HTML document.
 * @param {Object} schema Extraction schema.
 * @return {Object} Map of field name → extracted value.
 * @throws {Error} With status 400 on malformed schemas.
 */
export function extractCssFromHtml( html, schema ) {
	if ( ! schema || ! Array.isArray( schema.fields ) || 0 === schema.fields.length ) {
		throw Object.assign( new Error( 'css_schema must provide a non-empty "fields" array' ), { status: 400 } );
	}

	const $ = load( html );
	const scope = schema.baseSelector ? $( schema.baseSelector ).first() : $.root();

	const record = {};
	for ( const field of schema.fields ) {
		if ( ! field || 'string' !== typeof field.name || ! field.name || 'string' !== typeof field.selector ) {
			throw Object.assign( new Error( 'css_schema fields require "name" and "selector" strings' ), { status: 400 } );
		}

		const found = scope.find( field.selector ).first();
		let value = '';
		if ( found.length ) {
			value = 'attribute' === field.type && field.attribute
				? ( found.attr( field.attribute ) || '' ).trim()
				: ( found.text() || '' ).trim();
		}
		record[ field.name ] = value;
	}

	return record;
}
