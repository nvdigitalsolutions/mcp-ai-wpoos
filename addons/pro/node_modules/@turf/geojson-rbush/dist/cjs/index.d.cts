import { Geometry, GeoJsonProperties, Feature, FeatureCollection, BBox } from 'geojson';

declare class RBush<G extends Geometry, P extends GeoJsonProperties> {
    private tree;
    constructor(maxEntries?: number);
    /**
     * [insert](https://github.com/mourner/rbush#data-format)
     *
     * @memberof rbush
     * @param {Feature} feature insert single GeoJSON Feature
     * @returns {RBush} GeoJSON RBush
     * @example
     * var poly = turf.polygon([[[-78, 41], [-67, 41], [-67, 48], [-78, 48], [-78, 41]]]);
     * tree.insert(poly)
     */
    insert(feature: Feature<G, P>): RBush<G, P>;
    /**
     * [load](https://github.com/mourner/rbush#bulk-inserting-data)
     *
     * @memberof rbush
     * @param {FeatureCollection|Array<Feature>} features load entire GeoJSON FeatureCollection
     * @returns {RBush} GeoJSON RBush
     * @example
     * var polys = turf.polygons([
     *     [[[-78, 41], [-67, 41], [-67, 48], [-78, 48], [-78, 41]]],
     *     [[[-93, 32], [-83, 32], [-83, 39], [-93, 39], [-93, 32]]]
     * ]);
     * tree.load(polys);
     */
    load(features: FeatureCollection<G, P> | Feature<G, P>[]): RBush<G, P>;
    /**
     * [remove](https://github.com/mourner/rbush#removing-data)
     *
     * @memberof rbush
     * @param {Feature} feature remove single GeoJSON Feature
     * @param {Function} equals Pass a custom equals function to compare by value for removal.
     * @returns {RBush} GeoJSON RBush
     * @example
     * var poly = turf.polygon([[[-78, 41], [-67, 41], [-67, 48], [-78, 48], [-78, 41]]]);
     *
     * tree.remove(poly);
     */
    remove(feature: Feature<G, P>, equals?: (a: Feature<G, P>, b: Feature<G, P>) => boolean): this;
    /**
     * [clear](https://github.com/mourner/rbush#removing-data)
     *
     * @memberof rbush
     * @returns {RBush} GeoJSON Rbush
     * @example
     * tree.clear()
     */
    clear(): this;
    /**
     * [search](https://github.com/mourner/rbush#search)
     *
     * @memberof rbush
     * @param {BBox|FeatureCollection|Feature} geojson search with GeoJSON
     * @returns {FeatureCollection} all features that intersects with the given GeoJSON.
     * @example
     * var poly = turf.polygon([[[-78, 41], [-67, 41], [-67, 48], [-78, 48], [-78, 41]]]);
     *
     * tree.search(poly);
     */
    search(geojson: Feature | FeatureCollection | BBox): FeatureCollection<G, P>;
    /**
     * [collides](https://github.com/mourner/rbush#collisions)
     *
     * @memberof rbush
     * @param {BBox|FeatureCollection|Feature} geojson collides with GeoJSON
     * @returns {boolean} true if there are any items intersecting the given GeoJSON, otherwise false.
     * @example
     * var poly = turf.polygon([[[-78, 41], [-67, 41], [-67, 48], [-78, 48], [-78, 41]]]);
     *
     * tree.collides(poly);
     */
    collides(geojson: Feature | FeatureCollection | BBox): boolean;
    /**
     * [all](https://github.com/mourner/rbush#search)
     *
     * @memberof rbush
     * @returns {FeatureCollection} all the features in RBush
     * @example
     * tree.all()
     */
    all(): FeatureCollection<G, P>;
    /**
     * [toJSON](https://github.com/mourner/rbush#export-and-import)
     *
     * @memberof rbush
     * @returns {any} export data as JSON object
     * @example
     * var exported = tree.toJSON()
     */
    toJSON(): any;
    /**
     * [fromJSON](https://github.com/mourner/rbush#export-and-import)
     *
     * @memberof rbush
     * @param {any} json import previously exported data
     * @returns {RBush} GeoJSON RBush
     * @example
     * var exported = {
     *   "children": [
     *     {
     *       "type": "Feature",
     *       "geometry": {
     *         "type": "Point",
     *         "coordinates": [110, 50]
     *       },
     *       "properties": {},
     *       "bbox": [110, 50, 110, 50]
     *     }
     *   ],
     *   "height": 1,
     *   "leaf": true,
     *   "minX": 110,
     *   "minY": 50,
     *   "maxX": 110,
     *   "maxY": 50
     * }
     * tree.fromJSON(exported)
     */
    fromJSON(json: any): RBush<G, P>;
}
/**
 * GeoJSON implementation of [RBush](https://github.com/mourner/rbush#rbush) spatial index.
 *
 * @function rbush
 * @param {number} [maxEntries=9] defines the maximum number of entries in a tree node. 9 (used by default) is a
 * reasonable choice for most applications. Higher value means faster insertion and slower search, and vice versa.
 * @returns {RBush} GeoJSON RBush
 * @example
 * var geojsonRbush = require('geojson-rbush').default;
 * var tree = geojsonRbush();
 */
declare function geojsonRbush<G extends Geometry, P extends GeoJsonProperties = GeoJsonProperties>(maxEntries?: number): RBush<G, P>;

export { geojsonRbush as default, geojsonRbush };
