/**
 * Pro SPA v2 — composer drag-resize handle (Proposal 080, Phase 2).
 *
 * A divider between the message list and the composer bar. Dragging up
 * grows the composer (and shrinks the message list). Fully keyboard
 * operable (Arrow keys / Home / End / Escape) and double-click toggles
 * between auto and the last manual height — mirroring the panel-divider
 * UX of native editors (VS Code, Zed).
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.3.0
 */

import { useCallback, useEffect, useRef, type JSX } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import {
	MIN_COMPOSER_HEIGHT,
	maxComposerHeight,
} from '../../hooks/useComposerHeight';

/** Keyboard step size in px per Arrow key press. */
const KEY_STEP_PX = 24;

/** Height used when toggling back from auto without any manual history. */
const DEFAULT_MANUAL_HEIGHT = 240;

export interface ResizableComposerHandleProps {
	/** Current manual height in px, or null for auto. */
	height: number | null;
	/** Called with a clamped px height, or null to reset to auto. */
	onChange: ( height: number | null ) => void;
	/** Composer wrapper element — measured when a drag starts from auto. */
	targetRef: React.RefObject< HTMLDivElement | null >;
}

export function ResizableComposerHandle(
	props: ResizableComposerHandleProps
): JSX.Element {
	const { height, onChange, targetRef } = props;
	const drag = useRef< { startY: number; startHeight: number } | null >(
		null
	);
	const lastManual = useRef< number | null >( null );

	useEffect( () => {
		if ( height !== null ) {
			lastManual.current = height;
		}
	}, [ height ] );

	const startHeight = useCallback( () => {
		return (
			height ??
			targetRef.current?.getBoundingClientRect().height ??
			DEFAULT_MANUAL_HEIGHT
		);
	}, [ height, targetRef ] );

	const handlePointerDown = useCallback(
		( e: React.PointerEvent< HTMLButtonElement > ) => {
			if ( e.button !== 0 ) {
				return;
			}
			drag.current = { startY: e.clientY, startHeight: startHeight() };
			e.currentTarget.setPointerCapture?.( e.pointerId );
		},
		[ startHeight ]
	);

	const handlePointerMove = useCallback(
		( e: React.PointerEvent< HTMLButtonElement > ) => {
			if ( ! drag.current ) {
				return;
			}
			// Dragging up (negative clientY delta) grows the composer.
			const delta = drag.current.startY - e.clientY;
			onChange( drag.current.startHeight + delta );
		},
		[ onChange ]
	);

	const endDrag = useCallback( () => {
		drag.current = null;
	}, [] );

	const handleDoubleClick = useCallback( () => {
		if ( height !== null ) {
			onChange( null );
		} else {
			onChange( lastManual.current ?? DEFAULT_MANUAL_HEIGHT );
		}
	}, [ height, onChange ] );

	const handleKeyDown = useCallback(
		( e: React.KeyboardEvent< HTMLButtonElement > ) => {
			const base = height ?? startHeight();
			switch ( e.key ) {
				case 'ArrowUp':
					e.preventDefault();
					onChange( base + KEY_STEP_PX );
					break;
				case 'ArrowDown':
					e.preventDefault();
					onChange( base - KEY_STEP_PX );
					break;
				case 'Home':
					e.preventDefault();
					onChange( MIN_COMPOSER_HEIGHT );
					break;
				case 'End':
					e.preventDefault();
					onChange( maxComposerHeight() );
					break;
				case 'Escape':
					e.preventDefault();
					onChange( null );
					break;
			}
		},
		[ height, startHeight, onChange ]
	);

	return (
		<div className="nvoos-pro-spa-composer-resizer">
			<button
				type="button"
				data-testid="nvoos-pro-spa-composer-resizer"
				className="nvoos-pro-spa-composer-resizer__handle"
				aria-label={
					height === null
						? __(
								'Resize message composer (auto height)',
								'nvoos-pro-spa'
						  )
						: sprintf(
								/* translators: %d: composer height in pixels */
								__(
									'Resize message composer (%d pixels)',
									'nvoos-pro-spa'
								),
								height
						  )
				}
				title={ __(
					'Drag to resize the message composer (double-click to reset)',
					'nvoos-pro-spa'
				) }
				onPointerDown={ handlePointerDown }
				onPointerMove={ handlePointerMove }
				onPointerUp={ endDrag }
				onPointerCancel={ endDrag }
				onKeyDown={ handleKeyDown }
				onDoubleClick={ handleDoubleClick }
			/>
		</div>
	);
}
