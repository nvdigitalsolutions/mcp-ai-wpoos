import { AllGeoJSON } from '@turf/helpers';

/**
 * Simplifies the geometries in a GeoJSON object. Uses the 2d version of
 * [simplify-js](https://mourner.github.io/simplify-js/).
 *
 * @function
 * @param {GeoJSON} geojson GeoJSON object to be simplified
 * @param {Object} [options={}] Optional parameters
 * @param {number} [options.tolerance=1] Simplification tolerance
 * @param {boolean} [options.highQuality=false] Produce a higher-quality simplification using a slower algorithm
 * @param {boolean} [options.mutate=false] Allow GeoJSON input to be mutated (significant performance improvement if true)
 * @returns {GeoJSON} Simplified GeoJSON
 * @example
 * const geojson = turf.polygon([[
 *   [-70.603637, -33.399918],
 *   [-70.614624, -33.395332],
 *   [-70.639343, -33.392466],
 *   [-70.659942, -33.394759],
 *   [-70.683975, -33.404504],
 *   [-70.697021, -33.419406],
 *   [-70.701141, -33.434306],
 *   [-70.700454, -33.446339],
 *   [-70.694274, -33.458369],
 *   [-70.682601, -33.465816],
 *   [-70.668869, -33.472117],
 *   [-70.646209, -33.473835],
 *   [-70.624923, -33.472117],
 *   [-70.609817, -33.468107],
 *   [-70.595397, -33.458369],
 *   [-70.587158, -33.442901],
 *   [-70.587158, -33.426283],
 *   [-70.590591, -33.414248],
 *   [-70.594711, -33.406224],
 *   [-70.603637, -33.399918]
 * ]]);
 * const result0_01 = turf.simplify(geojson, {tolerance: 0.01 });
 * const result0_005 = turf.simplify(geojson, {tolerance: 0.005 });
 *
 * //addToMap
 * const addToMap = [geojson, result0_01, result0_005]
 */
declare function simplify<T extends AllGeoJSON>(geojson: T, options?: {
    tolerance?: number;
    highQuality?: boolean;
    mutate?: boolean;
}): T;

export { simplify as default, simplify };
