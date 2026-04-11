/**
 * CollectionFilter component
 *
 * Horizontal scrollable pill list of Shopify collections. Includes an "All"
 * default pill. Clicking a collection notifies the parent which filters
 * products accordingly.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

/**
 * @param {{
 *   collections: object[],
 *   activeId: string,
 *   onSelect: Function,
 * }} props
 * @return {JSX.Element}
 */
export default function CollectionFilter( {
	collections,
	activeId,
	onSelect,
} ) {
	return (
		<div className="tma-shopify-filters">
			<div className="tma-shopify-filters__pills">
				<button
					className={ `tma-shopify-pill${ ! activeId ? ' active' : '' }` }
					onClick={ () => onSelect( '' ) }
				>
					All
				</button>
				{ collections.map( ( col ) => (
					<button
						key={ col.id }
						className={ `tma-shopify-pill${ activeId === col.id ? ' active' : '' }` }
						onClick={ () => onSelect( col.id ) }
					>
						{ col.title ?? col.name ?? 'Collection' }
					</button>
				) ) }
			</div>
		</div>
	);
}
