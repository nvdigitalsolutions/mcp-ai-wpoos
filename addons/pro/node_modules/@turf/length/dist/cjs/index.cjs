"use strict";Object.defineProperty(exports, "__esModule", {value: true});// index.ts
var _distance = require('@turf/distance');
var _meta = require('@turf/meta');
function length(geojson, options = {}) {
  return _meta.segmentReduce.call(void 0, 
    geojson,
    (previousValue, segment) => {
      const coords = segment.geometry.coordinates;
      return previousValue + _distance.distance.call(void 0, coords[0], coords[1], options);
    },
    0
  );
}
var index_default = length;



exports.default = index_default; exports.length = length;
//# sourceMappingURL=index.cjs.map