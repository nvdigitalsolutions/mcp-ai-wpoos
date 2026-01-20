import { FeatureCollection, Polygon, MultiPolygon, Feature } from 'geojson';

/**
 * Smooths a {@link Polygon} or {@link MultiPolygon}. Based on [Chaikin's algorithm](https://www.cs.unc.edu/~dm/UNC/COMP258/LECTURES/Chaikins-Algorithm.pdf).
 * Warning: may create degenerate polygons.
 *
 * @function
 * @param {FeatureCollection<Polygon|MultiPolygon>|Feature<Polygon|MultiPolygon>|Polygon|MultiPolygon} inputPolys (Multi)Polygon(s) to smooth
 * @param {Object} [options={}] Optional parameters
 * @param {string} [options.iterations=1] The number of times to smooth the polygon. A higher value means a smoother polygon.
 * @returns {FeatureCollection<Polygon|MultiPolygon>} FeatureCollection containing the smoothed polygon/multipoylgons
 * @example
 * var polygon = turf.polygon([[[11, 0], [22, 4], [31, 0], [31, 11], [21, 15], [11, 11], [11, 0]]]);
 *
 * var smoothed = turf.polygonSmooth(polygon, {iterations: 3})
 *
 * //addToMap
 * var addToMap = [smoothed, polygon];
 */
declare function polygonSmooth(inputPolys: FeatureCollection<Polygon | MultiPolygon> | Feature<Polygon | MultiPolygon> | Polygon | MultiPolygon, options?: {
    iterations?: number;
}): FeatureCollection<Polygon | MultiPolygon>;

export { polygonSmooth as default, polygonSmooth };
