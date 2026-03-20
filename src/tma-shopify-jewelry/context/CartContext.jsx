/**
 * Cart Context
 *
 * Manages the in-browser shopping cart via `useReducer`. Cart items are
 * persisted to `sessionStorage` so they survive TMA navigation but are
 * cleared when the session ends.
 *
 * Actions:
 *   ADD_ITEM    – add or increment a product/variant
 *   REMOVE_ITEM – remove one line item by key
 *   UPDATE_QTY  – set the quantity for a line item
 *   CLEAR       – empty the cart
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import {
	createContext,
	useContext,
	useReducer,
	useEffect,
} from '@wordpress/element';

const STORAGE_KEY = 'wpTmaJewelryCart';

/** @typedef {{ key:string, productId:string, variantId:string|null, name:string, price:number, qty:number, image:string }} CartItem */

/**
 * Generate a unique line-item key from product/variant IDs.
 *
 * @param {string}      productId
 * @param {string|null} variantId
 * @return {string}
 */
const makeKey = ( productId, variantId ) =>
	variantId ? `${ productId }:${ variantId }` : String( productId );

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
			const { item } = action.payload;
			const key      = makeKey( item.productId, item.variantId );
			const existing = state.find( ( i ) => i.key === key );
			if ( existing ) {
				return state.map( ( i ) =>
					i.key === key ? { ...i, qty: i.qty + ( item.qty ?? 1 ) } : i
				);
			}
			return [ ...state, { ...item, key, qty: item.qty ?? 1 } ];
		}
		case 'REMOVE_ITEM':
			return state.filter( ( i ) => i.key !== action.payload.key );
		case 'UPDATE_QTY': {
			const { key, qty } = action.payload;
			if ( qty <= 0 ) {
				return state.filter( ( i ) => i.key !== key );
			}
			return state.map( ( i ) => ( i.key === key ? { ...i, qty } : i ) );
		}
		case 'CLEAR':
			return [];
		default:
			return state;
	}
}

/** @type {React.Context<{items:CartItem[], dispatch:Function, totalItems:number, subtotal:number}>} */
const CartContext = createContext( {
	items:      [],
	dispatch:   () => {},
	totalItems: 0,
	subtotal:   0,
} );

/**
 * CartProvider – wraps the app with cart state.
 *
 * @param {{ children: React.ReactNode }} props
 * @return {JSX.Element}
 */
export function CartProvider( { children } ) {
	const [ items, dispatch ] = useReducer( cartReducer, [], loadCart );

	useEffect( () => {
		try {
			sessionStorage.setItem( STORAGE_KEY, JSON.stringify( items ) );
		} catch ( _e ) {
			// Storage quota exceeded – silently fail.
		}
	}, [ items ] );

	const totalItems = items.reduce( ( sum, i ) => sum + i.qty, 0 );
	const subtotal   = items.reduce( ( sum, i ) => sum + i.price * i.qty, 0 );

	return (
		<CartContext.Provider value={ { items, dispatch, totalItems, subtotal } }>
			{ children }
		</CartContext.Provider>
	);
}

/**
 * Hook to consume the Cart context.
 *
 * @return {{ items:CartItem[], dispatch:Function, totalItems:number, subtotal:number }}
 */
export function useCart() {
	return useContext( CartContext );
}
