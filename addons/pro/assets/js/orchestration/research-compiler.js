/**
 * Research Compiler
 * 
 * Enhanced web research with HTML parsing and markdown conversion
 * using cheerio and turndown.
 * 
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

import * as cheerio from 'cheerio';
import TurndownService from 'turndown';

/**
 * Research Compiler Class
 * 
 * Provides tools for:
 * - HTML parsing and structured data extraction
 * - Clean HTML to Markdown conversion
 * - Multi-source data aggregation
 * - Research report generation
 */
class ResearchCompiler {
	/**
	 * Constructor
	 * 
	 * @param {Object} config Configuration options
	 */
	constructor( config = {} ) {
		this.config = {
			headingStyle: config.headingStyle || 'atx',
			codeBlockStyle: config.codeBlockStyle || 'fenced',
			...config,
		};

		// Initialize Turndown service
		this.turndown = new TurndownService( {
			headingStyle: this.config.headingStyle,
			hr: '---',
			bulletListMarker: '-',
			codeBlockStyle: this.config.codeBlockStyle,
			emDelimiter: '_',
		} );

		// Remove script and style tags
		this.turndown.remove( 'script' );
		this.turndown.remove( 'style' );
	}

	/**
	 * Parse HTML and extract structured data
	 * 
	 * @param {string} html HTML content
	 * @param {Object} selectors CSS selectors for data extraction
	 * @return {Object} Extracted data
	 */
	parseHtml( html, selectors = {} ) {
		const $ = cheerio.load( html );
		const data = {};

		// Default selectors
		const defaultSelectors = {
			title: 'h1, title',
			headings: 'h2, h3',
			paragraphs: 'p',
			lists: 'ul, ol',
			links: 'a[href]',
			images: 'img[src]',
			...selectors,
		};

		try {
			// Extract title
			if ( defaultSelectors.title ) {
				data.title = $( defaultSelectors.title ).first().text().trim();
			}

			// Extract headings
			if ( defaultSelectors.headings ) {
				data.headings = [];
				$( defaultSelectors.headings ).each( ( i, el ) => {
					const text = $( el ).text().trim();
					if ( text ) {
						data.headings.push( {
							level: el.tagName.toLowerCase(),
							text,
						} );
					}
				} );
			}

			// Extract paragraphs
			if ( defaultSelectors.paragraphs ) {
				data.paragraphs = [];
				$( defaultSelectors.paragraphs ).each( ( i, el ) => {
					const text = $( el ).text().trim();
					if ( text ) {
						data.paragraphs.push( text );
					}
				} );
			}

			// Extract links
			if ( defaultSelectors.links ) {
				data.links = [];
				$( defaultSelectors.links ).each( ( i, el ) => {
					const href = $( el ).attr( 'href' );
					const text = $( el ).text().trim();
					if ( href ) {
						data.links.push( {
							url: href,
							text,
						} );
					}
				} );
			}

			// Extract images
			if ( defaultSelectors.images ) {
				data.images = [];
				$( defaultSelectors.images ).each( ( i, el ) => {
					const src = $( el ).attr( 'src' );
					const alt = $( el ).attr( 'alt' ) || '';
					if ( src ) {
						data.images.push( {
							url: src,
							alt,
						} );
					}
				} );
			}

			// Extract custom selectors
			Object.keys( selectors ).forEach( ( key ) => {
				if ( ! defaultSelectors[ key ] ) {
					const selector = selectors[ key ];
					data[ key ] = [];
					$( selector ).each( ( i, el ) => {
						data[ key ].push( $( el ).text().trim() );
					} );
				}
			} );

			return {
				success: true,
				data,
			};
		} catch ( error ) {
			return {
				success: false,
				error: error.message,
			};
		}
	}

	/**
	 * Convert HTML to clean Markdown
	 * 
	 * @param {string} html HTML content
	 * @param {Object} options Turndown options
	 * @return {string} Markdown content
	 */
	htmlToMarkdown( html, options = {} ) {
		try {
			// Apply custom options if provided
			if ( Object.keys( options ).length > 0 ) {
				const service = new TurndownService( {
					...this.config,
					...options,
				} );
				service.remove( 'script' );
				service.remove( 'style' );
				return service.turndown( html );
			}

			return this.turndown.turndown( html );
		} catch ( error ) {
			console.error( '[ResearchCompiler] HTML to Markdown conversion failed:', error );
			return html; // Return original on error
		}
	}

	/**
	 * Extract article content from web page
	 * 
	 * Uses readability-style heuristics to find main content.
	 * 
	 * @param {string} html HTML content
	 * @return {Object} Extracted article
	 */
	extractArticle( html ) {
		const $ = cheerio.load( html );

		try {
			// Remove unwanted elements
			$( 'script, style, nav, header, footer, aside, .ad, .advertisement' ).remove();

			// Find main content container (common selectors)
			const mainSelectors = [
				'article',
				'main',
				'[role="main"]',
				'.post-content',
				'.entry-content',
				'.article-content',
				'#content',
				'.content',
			];

			let mainContent = null;
			for ( const selector of mainSelectors ) {
				const el = $( selector ).first();
				if ( el.length > 0 ) {
					mainContent = el;
					break;
				}
			}

			// Fallback: find largest text block
			if ( ! mainContent ) {
				let maxLength = 0;
				$( 'div' ).each( ( i, el ) => {
					const text = $( el ).text().trim();
					if ( text.length > maxLength ) {
						maxLength = text.length;
						mainContent = $( el );
					}
				} );
			}

			if ( ! mainContent ) {
				return {
					success: false,
					error: 'Could not find main content',
				};
			}

			// Extract metadata
			const title = $( 'h1' ).first().text().trim() || $( 'title' ).text().trim();
			const author = $( '[rel="author"], .author, .byline' ).first().text().trim();
			const date = $( 'time, .date, .published' ).first().text().trim();

			// Convert to markdown
			const markdown = this.htmlToMarkdown( mainContent.html() );

			return {
				success: true,
				article: {
					title,
					author,
					date,
					content: markdown,
					wordCount: markdown.split( /\s+/ ).length,
				},
			};
		} catch ( error ) {
			return {
				success: false,
				error: error.message,
			};
		}
	}

	/**
	 * Aggregate data from multiple sources
	 * 
	 * @param {Array} sources Array of source objects
	 * @return {Object} Aggregated data
	 */
	aggregateSources( sources ) {
		const aggregated = {
			sources: sources.length,
			combined: {
				titles: [],
				paragraphs: [],
				links: [],
				images: [],
			},
			bySource: [],
		};

		sources.forEach( ( source ) => {
			if ( source.success && source.data ) {
				// Combine data
				if ( source.data.title ) {
					aggregated.combined.titles.push( source.data.title );
				}
				if ( source.data.paragraphs ) {
					aggregated.combined.paragraphs.push( ...source.data.paragraphs );
				}
				if ( source.data.links ) {
					aggregated.combined.links.push( ...source.data.links );
				}
				if ( source.data.images ) {
					aggregated.combined.images.push( ...source.data.images );
				}

				// Store per-source
				aggregated.bySource.push( {
					url: source.url,
					data: source.data,
				} );
			}
		} );

		// Deduplicate
		aggregated.combined.titles = [ ...new Set( aggregated.combined.titles ) ];
		aggregated.combined.paragraphs = [ ...new Set( aggregated.combined.paragraphs ) ];

		// Deduplicate links by URL
		const uniqueLinks = new Map();
		aggregated.combined.links.forEach( ( link ) => {
			if ( ! uniqueLinks.has( link.url ) ) {
				uniqueLinks.set( link.url, link );
			}
		} );
		aggregated.combined.links = Array.from( uniqueLinks.values() );

		// Deduplicate images by URL
		const uniqueImages = new Map();
		aggregated.combined.images.forEach( ( image ) => {
			if ( ! uniqueImages.has( image.url ) ) {
				uniqueImages.set( image.url, image );
			}
		} );
		aggregated.combined.images = Array.from( uniqueImages.values() );

		return aggregated;
	}

	/**
	 * Generate a formatted research report
	 * 
	 * @param {Object} data Aggregated data
	 * @param {Object} options Report options
	 * @return {string} Markdown report
	 */
	generateReport( data, options = {} ) {
		const { title = 'Research Report', includeSources = true, includeToc = true } = options;

		let report = `# ${title}\n\n`;

		// Metadata
		report += `**Generated:** ${new Date().toISOString()}\n`;
		report += `**Sources:** ${data.sources}\n\n`;

		// Table of Contents
		if ( includeToc && data.combined.titles.length > 0 ) {
			report += '## Table of Contents\n\n';
			data.combined.titles.forEach( ( t, i ) => {
				report += `${i + 1}. [${t}](#${this.slugify( t )})\n`;
			} );
			report += '\n';
		}

		// Main content
		if ( data.combined.titles.length > 0 ) {
			data.combined.titles.forEach( ( t ) => {
				report += `## ${t}\n\n`;
			} );
		}

		if ( data.combined.paragraphs.length > 0 ) {
			report += '## Key Findings\n\n';
			data.combined.paragraphs.slice( 0, 10 ).forEach( ( p ) => {
				report += `${p}\n\n`;
			} );
		}

		// Links
		if ( data.combined.links.length > 0 ) {
			report += '## References\n\n';
			data.combined.links.slice( 0, 20 ).forEach( ( link ) => {
				report += `- [${link.text || link.url}](${link.url})\n`;
			} );
			report += '\n';
		}

		// Sources
		if ( includeSources && data.bySource.length > 0 ) {
			report += '## Sources\n\n';
			data.bySource.forEach( ( source, i ) => {
				report += `### Source ${i + 1}\n\n`;
				report += `**URL:** ${source.url}\n\n`;
				if ( source.data.title ) {
					report += `**Title:** ${source.data.title}\n\n`;
				}
			} );
		}

		return report;
	}

	/**
	 * Create URL-friendly slug from text
	 * 
	 * @param {string} text Text to slugify
	 * @return {string} Slug
	 */
	slugify( text ) {
		return text
			.toLowerCase()
			.replace( /[^\w\s-]/g, '' )
			.replace( /\s+/g, '-' )
			.replace( /-+/g, '-' )
			.trim();
	}
}

// Export for use in WordPress
if ( typeof window !== 'undefined' ) {
	window.WpMcpAiResearchCompiler = ResearchCompiler;
}

export default ResearchCompiler;
