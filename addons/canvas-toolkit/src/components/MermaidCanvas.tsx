/**
 * NV oOS Canvas Toolkit — Mermaid live-preview canvas.
 *
 * Provides a split-pane surface: a textarea on the left for editing Mermaid
 * diagram syntax and a live SVG preview on the right that updates on every
 * change. Uses the official mermaid package (MIT) for rendering.
 *
 * @link    https://github.com/mermaid-js/mermaid
 * @credit  mermaid by Knut Sveidqvist and contributors (MIT)
 * @since   0.2.0
 */

import { useEffect, useId, useRef, useState } from 'react';
import { __ } from '@wordpress/i18n';
import mermaid from 'mermaid';

/** Default diagram shown on first load. */
const DEFAULT_DIAGRAM = `flowchart TD
    A[Start] --> B{Decision?}
    B -- Yes --> C[Do something]
    B -- No  --> D[Do something else]
    C --> E[End]
    D --> E`;

mermaid.initialize( { startOnLoad: false, theme: 'default' } );

interface MermaidCanvasProps {
	toolkit?: string;
}

export function MermaidCanvas( { toolkit }: MermaidCanvasProps ) {
	const uniqueId          = useId().replace( /:/g, '' );
	const previewRef        = useRef<HTMLDivElement>( null );
	const [ source, setSource ]   = useState( DEFAULT_DIAGRAM );
	const [ error, setError ]     = useState<string | null>( null );
	const debounceTimer           = useRef<ReturnType<typeof setTimeout> | null>( null );

	/** Render the current source into the preview container. */
	const renderDiagram = async ( src: string ) => {
		if ( ! previewRef.current ) {
			return;
		}
		try {
			const id     = `nvoos-mermaid-${ uniqueId }`;
			const { svg } = await mermaid.render( id, src );
			if ( previewRef.current ) {
				previewRef.current.innerHTML = svg;
				setError( null );
			}
		} catch ( err ) {
			const msg = err instanceof Error ? err.message : String( err );
			setError( msg );
			if ( previewRef.current ) {
				previewRef.current.innerHTML = '';
			}
		}
	};

	/** Initial render. */
	useEffect( () => {
		renderDiagram( DEFAULT_DIAGRAM );
	}, [] );

	/** Debounced live preview as user types. */
	const handleChange = ( value: string ) => {
		setSource( value );
		if ( debounceTimer.current ) {
			clearTimeout( debounceTimer.current );
		}
		debounceTimer.current = setTimeout( () => {
			renderDiagram( value );
		}, 400 );
	};

	/** Clean up timer on unmount. */
	useEffect( () => {
		return () => {
			if ( debounceTimer.current ) {
				clearTimeout( debounceTimer.current );
			}
		};
	}, [] );

	return (
		<div className="nvoos-canvas-toolkit-mermaid" role="application" aria-label={ __( 'Mermaid live preview', 'nvoos-canvas-toolkit' ) }>
			<header className="nvoos-canvas-toolkit-mermaid-header">
				<strong>{ __( 'Mermaid Live Preview', 'nvoos-canvas-toolkit' ) }</strong>
				{ toolkit ? (
					<span className="nvoos-canvas-toolkit-mermaid-toolkit">{ toolkit }</span>
				) : null }
			</header>

			<div className="nvoos-canvas-toolkit-mermaid-panes">
				<div className="nvoos-canvas-toolkit-mermaid-editor">
					<label htmlFor="nvoos-mermaid-source">
						{ __( 'Mermaid source', 'nvoos-canvas-toolkit' ) }
					</label>
					<textarea
						id="nvoos-mermaid-source"
						value={ source }
						onChange={ ( e ) => handleChange( e.target.value ) }
						spellCheck={ false }
						aria-label={ __( 'Mermaid diagram source code', 'nvoos-canvas-toolkit' ) }
					/>
				</div>

				<div className="nvoos-canvas-toolkit-mermaid-preview" aria-live="polite">
					{ error ? (
						<p className="nvoos-canvas-toolkit-mermaid-error" role="alert">{ error }</p>
					) : null }
					<div ref={ previewRef } className="nvoos-canvas-toolkit-mermaid-svg" />
				</div>
			</div>
		</div>
	);
}
