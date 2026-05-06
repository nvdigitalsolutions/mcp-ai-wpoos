/**
 * CodeBlock — syntax-highlighted code block with a language badge and copy button.
 *
 * Used as the `code` renderer inside ContentArea's ReactMarkdown instance.
 * The `children` prop contains the raw code string.
 *
 * @since 1.0.0
 */

import { useState } from 'react';

interface CodeBlockProps {
	/** Language identifier extracted from the fenced code block. */
	language?: string;
	/** Raw code string. */
	children: string;
}

export default function CodeBlock( { language, children }: CodeBlockProps ) {
	const [ copied, setCopied ] = useState( false );

	function handleCopy() {
		if ( typeof navigator !== 'undefined' && navigator.clipboard ) {
			navigator.clipboard.writeText( children ).then( () => {
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
