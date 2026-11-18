/**
 * QuickBooks query-language helpers.
 *
 * The query language is SQL-like and interpolates literals, so any free-text
 * term must be escaped. Single quotes are escaped with a backslash, and the
 * backslash itself has to be escaped first or the two rules fight each other.
 *
 * Searchable entities are declared in a table rather than hardcoded: party
 * entities (Customer/Vendor) filter on `DisplayName`, while Account and Item
 * filter on `Name` and carry a type discriminator.
 */

const MAX_LIMIT = 1000;
const DEFAULT_LIMIT = 50;
const MAX_TERM_LENGTH = 100;

/** AccountType values accepted by the QuickBooks Online API. */
export const ACCOUNT_TYPES = [
	'Bank',
	'Other Current Asset',
	'Fixed Asset',
	'Other Asset',
	'Accounts Receivable',
	'Equity',
	'Expense',
	'Other Expense',
	'Cost of Goods Sold',
	'Accounts Payable',
	'Credit Card',
	'Long Term Liability',
	'Other Current Liability',
	'Income',
	'Other Income',
];

/** Item Type values accepted by the QuickBooks Online API. */
export const ITEM_TYPES = [ 'Inventory', 'NonInventory', 'Service', 'Category' ];

const SEARCHABLE = {
	Customer: { nameField: 'DisplayName', typeField: null },
	Vendor: { nameField: 'DisplayName', typeField: null },
	Account: { nameField: 'Name', typeField: 'AccountType', types: ACCOUNT_TYPES },
	Item: { nameField: 'Name', typeField: 'Type', types: ITEM_TYPES },
};

/**
 * Escape a value for use inside a single-quoted query literal.
 *
 * @param {string} value Raw value.
 * @return {string} Escaped value.
 */
export function escapeQueryLiteral( value ) {
	return String( value )
		.replace( /\\/g, '\\\\' )
		.replace( /'/g, "\\'" );
}

/**
 * Whether an entity can be searched with this builder.
 *
 * @param {string} entity Entity name.
 * @return {boolean} True when searchable.
 */
export function isSearchableEntity( entity ) {
	return Object.hasOwn( SEARCHABLE, entity );
}

/**
 * Build a Customer/Vendor/Account/Item search statement.
 *
 * @param {string} entity Entity name.
 * @param {Object} [filters]           Filter inputs.
 * @param {string} [filters.name]      Partial match on the entity's name field.
 * @param {boolean} [filters.active]   Active flag filter.
 * @param {string} [filters.type]      Type discriminator (Account/Item only).
 * @param {number} [filters.limit]     Max rows.
 * @return {string} Query statement.
 * @throws {Error} On an unknown entity or a type outside the allowlist.
 */
export function buildEntitySearchQuery( entity, { name, active, type, limit } = {} ) {
	if ( ! isSearchableEntity( entity ) ) {
		throw new Error( `Unsupported search entity: ${ entity }` );
	}

	const spec = SEARCHABLE[ entity ];
	const where = [];
	const term =
		'string' === typeof name
			? name.replace( /[\u0000-\u001f]/g, ' ' ).trim().slice( 0, MAX_TERM_LENGTH )
			: '';

	if ( '' !== term ) {
		where.push( `${ spec.nameField } like '%${ escapeQueryLiteral( term ) }%'` );
	}

	if ( 'boolean' === typeof active ) {
		where.push( `Active = ${ active ? 'true' : 'false' }` );
	}

	if ( undefined !== type && null !== type && '' !== type ) {
		if ( ! spec.typeField ) {
			throw new Error( `${ entity } has no type filter.` );
		}

		if ( ! spec.types.includes( type ) ) {
			throw new Error( `Unsupported ${ spec.typeField } for ${ entity }: ${ type }` );
		}

		where.push( `${ spec.typeField } = '${ escapeQueryLiteral( type ) }'` );
	}

	const requested = Number( limit );
	const max = Number.isFinite( requested )
		? Math.min( Math.max( Math.trunc( requested ), 1 ), MAX_LIMIT )
		: DEFAULT_LIMIT;

	return (
		`select * from ${ entity }` +
		( where.length ? ` where ${ where.join( ' and ' ) }` : '' ) +
		` orderby ${ spec.nameField } maxresults ${ max }`
	);
}
