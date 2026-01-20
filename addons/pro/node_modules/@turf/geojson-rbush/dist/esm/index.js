// index.ts
import rbush from "rbush";
import { featureCollection } from "@turf/helpers";
import { featureEach } from "@turf/meta";
import { bbox as turfBBox } from "@turf/bbox";
function toBBox(geojson) {
  var bbox;
  if (geojson.bbox) bbox = geojson.bbox;
  else if (Array.isArray(geojson) && geojson.length === 4) bbox = geojson;
  else if (Array.isArray(geojson) && geojson.length === 6)
    bbox = [geojson[0], geojson[1], geojson[3], geojson[4]];
  else if (geojson.type === "Feature") bbox = turfBBox(geojson);
  else if (geojson.type === "FeatureCollection") bbox = turfBBox(geojson);
  else throw new Error("invalid geojson");
  return {
    minX: bbox[0],
    minY: bbox[1],
    maxX: bbox[2],
    maxY: bbox[3]
  };
}
var RBush = class {
  constructor(maxEntries = 9) {
    this.tree = new rbush(maxEntries);
    this.tree.toBBox = toBBox;
  }
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
  insert(feature) {
    if (feature.type !== "Feature") throw new Error("invalid feature");
    feature.bbox = feature.bbox ? feature.bbox : turfBBox(feature);
    this.tree.insert(feature);
    return this;
  }
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
  load(features) {
    var load = [];
    if (Array.isArray(features)) {
      features.forEach(function(feature) {
        if (feature.type !== "Feature") throw new Error("invalid features");
        feature.bbox = feature.bbox ? feature.bbox : turfBBox(feature);
        load.push(feature);
      });
    } else {
      featureEach(features, function(feature) {
        if (feature.type !== "Feature") throw new Error("invalid features");
        feature.bbox = feature.bbox ? feature.bbox : turfBBox(feature);
        load.push(feature);
      });
    }
    this.tree.load(load);
    return this;
  }
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
  remove(feature, equals) {
    if (feature.type !== "Feature") throw new Error("invalid feature");
    feature.bbox = feature.bbox ? feature.bbox : turfBBox(feature);
    this.tree.remove(feature, equals);
    return this;
  }
  /**
   * [clear](https://github.com/mourner/rbush#removing-data)
   *
   * @memberof rbush
   * @returns {RBush} GeoJSON Rbush
   * @example
   * tree.clear()
   */
  clear() {
    this.tree.clear();
    return this;
  }
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
  search(geojson) {
    var features = this.tree.search(toBBox(geojson));
    return featureCollection(features);
  }
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
  collides(geojson) {
    return this.tree.collides(toBBox(geojson));
  }
  /**
   * [all](https://github.com/mourner/rbush#search)
   *
   * @memberof rbush
   * @returns {FeatureCollection} all the features in RBush
   * @example
   * tree.all()
   */
  all() {
    const features = this.tree.all();
    return featureCollection(features);
  }
  /**
   * [toJSON](https://github.com/mourner/rbush#export-and-import)
   *
   * @memberof rbush
   * @returns {any} export data as JSON object
   * @example
   * var exported = tree.toJSON()
   */
  toJSON() {
    return this.tree.toJSON();
  }
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
  fromJSON(json) {
    this.tree.fromJSON(json);
    return this;
  }
};
function geojsonRbush(maxEntries) {
  return new RBush(maxEntries);
}
var index_default = geojsonRbush;
export {
  index_default as default,
  geojsonRbush
};
//# sourceMappingURL=index.js.map