import { Feature, Point, MultiPoint, LineString, MultiLineString, Polygon, MultiPolygon, FeatureCollection } from 'geojson';

type Splitter = Feature<Point | MultiPoint | LineString | MultiLineString | Polygon | MultiPolygon>;
/**
 * Split a LineString by another GeoJSON Feature.
 *
 * @function
 * @param {Feature<LineString>} line LineString Feature to split
 * @param {Feature<any>} splitter Feature used to split line
 * @returns {FeatureCollection<LineString>} Split LineStrings
 * @example
 * var line = turf.lineString([[120, -25], [145, -25]]);
 * var splitter = turf.lineString([[130, -15], [130, -35]]);
 *
 * var split = turf.lineSplit(line, splitter);
 *
 * //addToMap
 * var addToMap = [line, splitter, split]
 *
 * split.features[0].properties.stroke = "red";
 * split.features[1].properties.stroke = "blue";
 */
declare function lineSplit<T extends LineString>(line: Feature<T> | T, splitter: Splitter): FeatureCollection<T>;

export { type Splitter, lineSplit as default, lineSplit };
