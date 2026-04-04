/**
 * Navigation component – Jewelry TMA
 *
 * Fixed bottom tab bar with 4 tabs: Shop, Assistant (Ask AI), Cart (with
 * item badge), Orders. Matches Telegram's native UI feel with a gold accent.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { useCart } from '../context/CartContext';
import { useTMA } from '../context/TMAContext';

/**
 * @param {{ activePage:string, navigate:Function }} props
 * @return {JSX.Element}
 */
export default function Navigation( { activePage, navigate } ) {
	const { totalItems } = useCart();
	const { haptic }     = useTMA();

	const go = ( page ) => {
		haptic( 'selectionChanged' );
		navigate( page );
	};

	/** @type {Array<{id:string,label:string,icon:JSX.Element}>} */
	const tabs = [
		{
			id:    'shop',
			label: 'Shop',
			icon:  (
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
					<path d="M12 2L2 7l10 5 10-5-10-5z" />
					<path d="M2 17l10 5 10-5" />
					<path d="M2 12l10 5 10-5" />
				</svg>
			),
		},
		{
			id:    'assistant',
			label: 'Ask AI',
			icon:  (
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
					<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
				</svg>
			),
		},
		{
			id:    'cart',
			label: 'Cart',
			icon:  (
				<span className="tma-jw-nav__cart-wrap">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
						<circle cx="9" cy="21" r="1" />
						<circle cx="20" cy="21" r="1" />
						<path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
					</svg>
					{ totalItems > 0 && (
						<span className="tma-jw-nav__badge">{ totalItems > 99 ? '99+' : totalItems }</span>
					) }
				</span>
			),
		},
		{
			id:    'orders',
			label: 'Orders',
			icon:  (
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
					<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
					<polyline points="14 2 14 8 20 8" />
					<line x1="16" y1="13" x2="8" y2="13" />
					<line x1="16" y1="17" x2="8" y2="17" />
				</svg>
			),
		},
	];

	return (
		<nav className="tma-jw-nav" aria-label="Main navigation">
			{ tabs.map( ( tab ) => (
				<button
					key={ tab.id }
					className={ `tma-jw-nav__btn${ activePage === tab.id ? ' active' : '' }` }
					onClick={ () => go( tab.id ) }
					aria-label={ tab.label }
					aria-current={ activePage === tab.id ? 'page' : undefined }
				>
					<span className="tma-jw-nav__icon">{ tab.icon }</span>
					<span className="tma-jw-nav__label">{ tab.label }</span>
				</button>
			) ) }
		</nav>
	);
}
