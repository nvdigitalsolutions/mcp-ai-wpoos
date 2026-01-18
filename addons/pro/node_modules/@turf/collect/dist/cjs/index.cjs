"use strict";Object.defineProperty(exports, "__esModule", {value: true}); function _interopRequireDefault(obj) { return obj && obj.__esModule ? obj : { default: obj }; }// index.ts
var _bbox = require('@turf/bbox');
var _booleanpointinpolygon = require('@turf/boolean-point-in-polygon');
var _rbush = require('rbush'); var _rbush2 = _interopRequireDefault(_rbush);
function collect(polygons, points, inProperty, outProperty) {
  var rtree = new (0, _rbush2.default)(6);
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
    var bbox = _bbox.bbox.call(void 0, poly);
    var potentialPoints = rtree.search({
      minX: bbox[0],
      minY: bbox[1],
      maxX: bbox[2],
      maxY: bbox[3]
    });
    var values = [];
    potentialPoints.forEach(function(pt) {
      if (_booleanpointinpolygon.booleanPointInPolygon.call(void 0, [pt.minX, pt.minY], poly)) {
        values.push(pt.property);
      }
    });
    poly.properties[outProperty] = values;
  });
  return polygons;
}
var index_default = collect;



exports.collect = collect; exports.default = index_default;
//# sourceMappingURL=index.cjs.map