/**
 * CodeBlock — syntax-highlighted code block with a language badge and copy button.
 *
 * Used as the `code` renderer inside ContentArea's ReactMarkdown instance.
 * The `children` prop may be either:
 *   - A plain string (for non-highlighted fenced blocks)
 *   - An array of React nodes (when rehype-highlight has tokenised the block)
 *
 * @since 1.0.0
 */

import { useState } from 'react';

interface CodeBlockProps {
	/** Language identifier extracted from the fenced code block. */
	language?: string;
	/** Raw code string (used for the copy button). */
	rawCode: string;
	/** Rendered children — either a plain string or pre-highlighted spans. */
	children: React.ReactNode;
}

export default function CodeBlock( { language, rawCode, children }: CodeBlockProps ) {
	const [ copied, setCopied ] = useState( false );

	function handleCopy() {
		if ( typeof navigator !== 'undefined' && navigator.clipboard ) {
			navigator.clipboard.writeText( rawCode ).then( () => {
				setCopied( true );
				setTimeout( () => setCopied( false ), 2000 );
			} );
		}
	}

	const hasHeader = !! language;

	return (
		<div className="dh-code-block">
			{ hasHeader && (
				<div className="dh-code-header">
					<span className="dh-code-lang">{ language }</span>
					<button
						type="button"
						className={ `dh-code-copy-btn${ copied ? ' dh-copied' : '' }` }
						onClick={ handleCopy }
						aria-label={ copied ? 'Copied!' : 'Copy code' }
					>
						{ copied ? '✓ Copied' : 'Copy' }
					</button>
				</div>
			) }
			<pre>
				<code className={ language ? `language-${ language }` : undefined }>
					{ children }
				</code>
			</pre>
		</div>
	);
}
