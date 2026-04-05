/**
 * Cart Context
 *
 * Manages the in-browser shopping cart via `useReducer`. Cart items are
 * persisted to `sessionStorage` so they survive TMA navigation but are
 * cleared when the session ends (appropriate for an ephemeral Mini App).
 *
 * Each item stores a Shopify variant GID so that checkout can reference the
 * correct variant when placing the order.
 *
 * Actions:
 *   ADD_ITEM     – add or increment a product/variant
 *   REMOVE_ITEM  – remove one line item by key
 *   UPDATE_QTY   – set the quantity for a line item
 *   CLEAR        – empty the cart
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import {
	createContext,
	useContext,
	useReducer,
	useEffect,
} from 'react';

const STORAGE_KEY = 'tma_shopify_cart';

/** @typedef {{ id:string, variantId:string, title:string, price:number, quantity:number, image:string }} CartItem */

/**
 * Load persisted cart from sessionStorage.
 *
 * @return {CartItem[]}
 */
function loadCart() {
	try {
		const stored = sessionStorage.getItem( STORAGE_KEY );
		if ( stored ) {
			return JSON.parse( stored );
		}
	} catch ( _e ) {
		// Storage unavailable or corrupt JSON – start fresh.
	}
	return [];
}

/**
 * Cart reducer.
 *
 * @param {CartItem[]} state
 * @param {{ type: string, payload?: any }} action
 * @return {CartItem[]}
 */
function cartReducer( state, action ) {
	switch ( action.type ) {
		case 'ADD_ITEM': {
			const { id, variantId, title, price, quantity, image } = action.payload;
			const key = variantId || id;
			const existing = state.find( ( i ) => ( i.variantId || i.id ) === key );
			if ( existing ) {
				return state.map( ( i ) =>
					( i.variantId || i.id ) === key
						? { ...i, quantity: i.quantity + ( quantity ?? 1 ) }
						: i
				);
			}
			return [ ...state, { id, variantId, title, price, quantity: quantity ?? 1, image } ];
		}
		case 'REMOVE_ITEM': {
			const key = action.payload.variantId || action.payload.id;
			return state.filter( ( i ) => ( i.variantId || i.id ) !== key );
		}
		case 'UPDATE_QTY': {
			const key = action.payload.variantId || action.payload.id;
			const qty = action.payload.quantity;
			if ( qty <= 0 ) {
				return state.filter( ( i ) => ( i.variantId || i.id ) !== key );
			}
			return state.map( ( i ) =>
				( i.variantId || i.id ) === key ? { ...i, quantity: qty } : i
			);
		}
		case 'CLEAR':
			return [];
		default:
			return state;
	}
}

/** @type {React.Context<{items:CartItem[], addItem:Function, removeItem:Function, updateQty:Function, clearCart:Function, itemCount:number, subtotal:number}>} */
const CartContext = createContext( {
	items: [],
	addItem: () => {},
	removeItem: () => {},
	updateQty: () => {},
	clearCart: () => {},
	itemCount: 0,
	subtotal: 0,
} );

/**
 * CartProvider – wraps the app with cart state.
 *
 * @param {{ children: React.ReactNode }} props
 * @return {JSX.Element}
 */
export function CartProvider( { children } ) {
	const [ items, dispatch ] = useReducer( cartReducer, [], loadCart );

	// Persist cart on change.
	useEffect( () => {
		try {
			sessionStorage.setItem( STORAGE_KEY, JSON.stringify( items ) );
		} catch ( _e ) {
			// Storage quota exceeded – silently fail.
		}
	}, [ items ] );

	const addItem = ( item ) => dispatch( { type: 'ADD_ITEM', payload: item } );
	const removeItem = ( item ) => dispatch( { type: 'REMOVE_ITEM', payload: item } );
	const updateQty = ( item, quantity ) => dispatch( { type: 'UPDATE_QTY', payload: { ...item, quantity } } );
	const clearCart = () => dispatch( { type: 'CLEAR' } );

	const itemCount = items.reduce( ( sum, i ) => sum + i.quantity, 0 );
	const subtotal = items.reduce( ( sum, i ) => sum + i.price * i.quantity, 0 );

	return (
		<CartContext.Provider value={ { items, addItem, removeItem, updateQty, clearCart, itemCount, subtotal } }>
			{ children }
		</CartContext.Provider>
	);
}

/**
 * Hook to consume the Cart context.
 *
 * @return {{ items:CartItem[], addItem:Function, removeItem:Function, updateQty:Function, clearCart:Function, itemCount:number, subtotal:number }}
 */
export function useCart() {
	return useContext( CartContext );
}
