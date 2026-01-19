import { GeoJsonProperties, BBox, Feature, Polygon, MultiPolygon, FeatureCollection } from 'geojson';
import { Units } from '@turf/helpers';

/**
 * Takes a bounding box and the diameter of the cell and returns a {@link FeatureCollection} of flat-topped
 * hexagons or triangles ({@link Polygon} features) aligned in an "odd-q" vertical grid as
 * described in [Hexagonal Grids](http://www.redblobgames.com/grids/hexagons/).
 *
 * @function
 * @param {BBox} bbox extent in [minX, minY, maxX, maxY] order
 * @param {number} cellSide length of the side of the the hexagons or triangles, in units. It will also coincide with the
 * radius of the circumcircle of the hexagons.
 * @param {Object} [options={}] Optional parameters
 * @param {Units} [options.units='kilometers'] used in calculating cell size. Supports all valid Turf {@link https://turfjs.org/docs/api/types/Units Units}.
 * @param {Object} [options.properties={}] passed to each hexagon or triangle of the grid
 * @param {Feature<Polygon | MultiPolygon>} [options.mask] if passed a Polygon or MultiPolygon, the grid Points will be created only inside it
 * @param {boolean} [options.triangles=false] whether to return as triangles instead of hexagons
 * @returns {FeatureCollection<Polygon>} a hexagonal grid
 * @example
 * var bbox = [-96,31,-84,40];
 * var cellSide = 50;
 * var options = {units: 'miles'};
 *
 * var hexgrid = turf.hexGrid(bbox, cellSide, options);
 *
 * //addToMap
 * var addToMap = [hexgrid];
 */
declare function hexGrid<P extends GeoJsonProperties = GeoJsonProperties>(bbox: BBox, cellSide: number, options?: {
    units?: Units;
    triangles?: boolean;
    properties?: P;
    mask?: Feature<Polygon | MultiPolygon>;
}): FeatureCollection<Polygon, P>;

export { hexGrid as default, hexGrid };
