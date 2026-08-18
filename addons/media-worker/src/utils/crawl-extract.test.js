/**
 * Tests for the static HTML extraction helpers.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { wordCount, isInternalHref, extractFromHtml, extractCssFromHtml } from './crawl-extract.js';

const ARTICLE_FIXTURE = `<!DOCTYPE html>
<html>
<head><title>Example Article</title></head>
<body>
  <nav><a href="/home">Home</a><a href="/about">About</a></nav>
  <article>
    <h1>The Future of Sidecar Architecture</h1>
    <p>Containers keep evolving quickly in production environments.</p>
    <p>Companion processes isolate concerns while sharing a network namespace.</p>
    <a href="/related">Related reading</a>
    <a href="https://external.example.org/ref">External reference</a>
  </article>
  <aside>Sidebar noise that Readability should drop.</aside>
</body>
</html>`;

test( 'wordCount counts whitespace-separated words', () => {
	assert.equal( wordCount( 'one two three' ), 3 );
	assert.equal( wordCount( '  spaced   out  ' ), 2 );
	assert.equal( wordCount( '' ), 0 );
	assert.equal( wordCount( null ), 0 );
} );

test( 'isInternalHref compares hosts ignoring www', () => {
	assert.equal( isInternalHref( '/path', 'https://example.com/a' ), true );
	assert.equal( isInternalHref( 'https://example.com/b', 'https://example.com/a' ), true );
	assert.equal( isInternalHref( 'https://www.example.com/b', 'https://example.com/a' ), true );
	assert.equal( isInternalHref( 'https://other.org/b', 'https://example.com/a' ), false );
	assert.equal( isInternalHref( 'mailto:x@example.com', 'https://example.com/a' ), false );
} );

test( 'extractFromHtml isolates the article and converts to Markdown', () => {
	const result = extractFromHtml( ARTICLE_FIXTURE, 'https://example.com/post' );

	// Readability uses the document title when it is a reasonable length.
	assert.equal( result.title, 'Example Article' );
	assert.ok( result.markdown.includes( 'Sidecar Architecture' ), 'markdown keeps the article heading' );
	assert.ok( result.markdown.includes( 'Containers keep evolving' ), 'markdown keeps article text' );
	assert.ok( result.word_count > 10, 'word count reflects article text' );
	assert.ok( result.markdown.length > 0, 'markdown is non-empty' );
} );

test( 'extractFromHtml resolves and classifies links', () => {
	const result = extractFromHtml( ARTICLE_FIXTURE, 'https://example.com/post' );
	const related = result.links.find( ( link ) => link.href.includes( '/related' ) );
	const external = result.links.find( ( link ) => link.href.includes( 'external.example.org' ) );

	assert.ok( related, 'internal link present' );
	assert.equal( related.is_internal, true );
	assert.ok( external, 'external link present' );
	assert.equal( external.is_internal, false );
} );

test( 'extractFromHtml tolerates empty and garbage pages', () => {
	const empty = extractFromHtml( '', 'https://example.com/' );
	assert.equal( empty.markdown, '' );
	assert.equal( empty.word_count, 0 );

	const garbage = extractFromHtml( '<html><body><div>No article structure here.</div></body></html>' );
	assert.ok( 'string' === typeof garbage.markdown, 'never throws on odd pages' );
} );

test( 'extractCssFromHtml extracts fields from a CSS schema', () => {
	const html = '<div class="product"><h2>Widget Pro</h2><span class="price">$19.99</span><img src="/w.png" alt="Widget"></div>';
	const schema = {
		baseSelector: 'div.product',
		fields: [
			{ name: 'title', selector: 'h2' },
			{ name: 'price', selector: '.price' },
			{ name: 'image', selector: 'img', type: 'attribute', attribute: 'src' },
		],
	};

	const record = extractCssFromHtml( html, schema );
	assert.deepEqual( record, { title: 'Widget Pro', price: '$19.99', image: '/w.png' } );
} );

test( 'extractCssFromHtml rejects malformed schemas with status 400', () => {
	assert.throws(
		() => extractCssFromHtml( '<html></html>', { fields: [] } ),
		( err ) => 400 === err.status
	);
	assert.throws(
		() => extractCssFromHtml( '<html></html>', { fields: [ { name: 'x' } ] } ),
		( err ) => 400 === err.status
	);
	assert.throws(
		() => extractCssFromHtml( '<html></html>', null ),
		( err ) => 400 === err.status
	);
} );
