"use strict";Object.defineProperty(exports, "__esModule", {value: true});// index.ts





var _helpers = require('@turf/helpers');
var _invariant = require('@turf/invariant');
function destination(origin, distance, bearing, options = {}) {
  const coordinates1 = _invariant.getCoord.call(void 0, origin);
  const longitude1 = _helpers.degreesToRadians.call(void 0, coordinates1[0]);
  const latitude1 = _helpers.degreesToRadians.call(void 0, coordinates1[1]);
  const bearingRad = _helpers.degreesToRadians.call(void 0, bearing);
  const radians = _helpers.lengthToRadians.call(void 0, distance, options.units);
  const latitude2 = Math.asin(
    Math.sin(latitude1) * Math.cos(radians) + Math.cos(latitude1) * Math.sin(radians) * Math.cos(bearingRad)
  );
  const longitude2 = longitude1 + Math.atan2(
    Math.sin(bearingRad) * Math.sin(radians) * Math.cos(latitude1),
    Math.cos(radians) - Math.sin(latitude1) * Math.sin(latitude2)
  );
  const lng = _helpers.radiansToDegrees.call(void 0, longitude2);
  const lat = _helpers.radiansToDegrees.call(void 0, latitude2);
  if (coordinates1[2] !== void 0) {
    return _helpers.point.call(void 0, [lng, lat, coordinates1[2]], options.properties);
  }
  return _helpers.point.call(void 0, [lng, lat], options.properties);
}
var index_default = destination;



exports.default = index_default; exports.destination = destination;
//# sourceMappingURL=index.cjs.map