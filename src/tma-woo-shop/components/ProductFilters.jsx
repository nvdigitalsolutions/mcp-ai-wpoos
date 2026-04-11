/**
 * ProductFilters component
 *
 * Provides a horizontal scrollable row of category pills and a sort dropdown.
 * All state lives in the parent (ShopPage) – this is a pure controlled component.
 *
 * @package WP_MCP_AI
 * @since   1.1.5
 */

/**
 * @param {{
 *   categories: object[],
 *   activeCategoryId: number|string,
 *   onCategory: Function,
 *   sortBy: string,
 *   onSort: Function,
 * }} props
 * @return {JSX.Element}
 */
export default function ProductFilters( {
	categories,
	activeCategoryId,
	onCategory,
	sortBy,
	onSort,
} ) {
	return (
		<div className="tma-woo-filters">
			{ /* Category pills */ }
			<div className="tma-woo-filters__cats">
				<button
					className={ `tma-woo-cat-pill${ ! activeCategoryId ? ' active' : '' }` }
					onClick={ () => onCategory( '' ) }
				>
					All
				</button>
				{ categories.map( ( cat ) => (
					<button
						key={ cat.id }
						className={ `tma-woo-cat-pill${ activeCategoryId === cat.id ? ' active' : '' }` }
						onClick={ () => onCategory( cat.id ) }
					>
						{ cat.name }
					</button>
				) ) }
			</div>
			{ /* Sort control */ }
			<div className="tma-woo-filters__sort">
				<select
					className="tma-woo-sort-select"
					value={ sortBy }
					onChange={ ( e ) => onSort( e.target.value ) }
					aria-label="Sort products"
				>
					<option value="menu_order">Featured</option>
					<option value="popularity">Popular</option>
					<option value="rating">Top Rated</option>
					<option value="price">Price: Low → High</option>
					<option value="price-desc">Price: High → Low</option>
					<option value="date">Newest</option>
				</select>
			</div>
		</div>
	);
}
