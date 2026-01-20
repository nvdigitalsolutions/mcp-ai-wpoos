import { Coord, Units } from '@turf/helpers';

/**
 * Calculates the distance along a rhumb line between two {@link Point|points} in {@link https://turfjs.org/docs/api/types/Units Units}
 *
 * @function
 * @param {Coord} from origin point
 * @param {Coord} to destination point
 * @param {Object} [options] Optional parameters
 * @param {Units} [options.units='kilometers'] Supports all valid Turf {@link https://turfjs.org/docs/api/types/Units Units}
 * @returns {number} distance between the two points
 * @example
 * var from = turf.point([-75.343, 39.984]);
 * var to = turf.point([-75.534, 39.123]);
 * var options = {units: 'miles'};
 *
 * var distance = turf.rhumbDistance(from, to, options);
 *
 * //addToMap
 * var addToMap = [from, to];
 * from.properties.distance = distance;
 * to.properties.distance = distance;
 */
declare function rhumbDistance(from: Coord, to: Coord, options?: {
    units?: Units;
}): number;

export { rhumbDistance as default, rhumbDistance };
