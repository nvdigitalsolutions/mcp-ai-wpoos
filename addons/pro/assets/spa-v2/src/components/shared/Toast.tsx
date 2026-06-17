/**
 * Toast — Notification toast component.
 */

import { type JSX } from 'react';
import { useUIStore } from '../../stores/uiStore';

export function ToastContainer(): JSX.Element | null {
	const toasts = useUIStore( ( s ) => s.toasts );
	const removeToast = useUIStore( ( s ) => s.removeToast );

	if ( toasts.length === 0 ) {
		return null;
	}

	return (
		<div className="nvoos-pro-spa-toast-container" aria-live="polite">
			{ toasts.map( ( toast ) => (
				<div
					key={ toast.id }
					className={ `nvoos-pro-spa-toast nvoos-pro-spa-toast--${ toast.variant }` }
					role="status"
				>
					<span className="nvoos-pro-spa-toast__message">{ toast.message }</span>
					<button
						type="button"
						className="nvoos-pro-spa-toast__close"
						onClick={ () => removeToast( toast.id ) }
						aria-label="Dismiss notification"
					>
						×
					</button>
				</div>
			) ) }
		</div>
	);
}
