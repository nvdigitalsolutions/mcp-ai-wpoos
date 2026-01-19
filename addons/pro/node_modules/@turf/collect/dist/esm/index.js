// index.ts
import { bbox as turfbbox } from "@turf/bbox";
import { booleanPointInPolygon } from "@turf/boolean-point-in-polygon";
import rbush from "rbush";
function collect(polygons, points, inProperty, outProperty) {
  var rtree = new rbush(6);
  var treeItems = points.features.map(function(item) {
    var _a;
    return {
      minX: item.geometry.coordinates[0],
      minY: item.geometry.coordinates[1],
      maxX: item.geometry.coordinates[0],
      maxY: item.geometry.coordinates[1],
      property: (_a = item.properties) == null ? void 0 : _a[inProperty]
    };
  });
  rtree.load(treeItems);
  polygons.features.forEach(function(poly) {
    if (!poly.properties) {
      poly.properties = {};
    }
    var bbox = turfbbox(poly);
    var potentialPoints = rtree.search({
      minX: bbox[0],
      minY: bbox[1],
      maxX: bbox[2],
      maxY: bbox[3]
    });
    var values = [];
    potentialPoints.forEach(function(pt) {
      if (booleanPointInPolygon([pt.minX, pt.minY], poly)) {
        values.push(pt.property);
      }
    });
    poly.properties[outProperty] = values;
  });
  return polygons;
}
var index_default = collect;
export {
  collect,
  index_default as default
};
//# sourceMappingURL=index.js.map