"use strict";Object.defineProperty(exports, "__esModule", {value: true});// index.ts
var _helpers = require('@turf/helpers');
var _invariant = require('@turf/invariant');
var _booleanpointonline = require('@turf/boolean-point-on-line');

function cleanCoords(geojson, options = {}) {
  var mutate = typeof options === "object" ? options.mutate : options;
  if (!geojson) throw new Error("geojson is required");
  var type = _invariant.getType.call(void 0, geojson);
  var newCoords = [];
  switch (type) {
    case "LineString":
      newCoords = cleanLine(geojson, type);
      break;
    case "MultiLineString":
    case "Polygon":
      _invariant.getCoords.call(void 0, geojson).forEach(function(line) {
        newCoords.push(cleanLine(line, type));
      });
      break;
    case "MultiPolygon":
      _invariant.getCoords.call(void 0, geojson).forEach(function(polygons) {
        var polyPoints = [];
        polygons.forEach(function(ring) {
          polyPoints.push(cleanLine(ring, type));
        });
        newCoords.push(polyPoints);
      });
      break;
    case "Point":
      return geojson;
    case "MultiPoint":
      var existing = {};
      _invariant.getCoords.call(void 0, geojson).forEach(function(coord) {
        var key = coord.join("-");
        if (!Object.prototype.hasOwnProperty.call(existing, key)) {
          newCoords.push(coord);
          existing[key] = true;
        }
      });
      break;
    default:
      throw new Error(type + " geometry not supported");
  }
  if (geojson.coordinates) {
    if (mutate === true) {
      geojson.coordinates = newCoords;
      return geojson;
    }
    return { type, coordinates: newCoords };
  } else {
    if (mutate === true) {
      geojson.geometry.coordinates = newCoords;
      return geojson;
    }
    return _helpers.feature.call(void 0, { type, coordinates: newCoords }, geojson.properties, {
      bbox: geojson.bbox,
      id: geojson.id
    });
  }
}
function cleanLine(line, type) {
  const points = _invariant.getCoords.call(void 0, line);
  if (points.length === 2 && !equals(points[0], points[1])) return points;
  const newPoints = [];
  let a = 0, b = 1, c = 2;
  newPoints.push(points[a]);
  while (c < points.length) {
    if (_booleanpointonline.booleanPointOnLine.call(void 0, points[b], _helpers.lineString.call(void 0, [points[a], points[c]]))) {
      b = c;
    } else {
      newPoints.push(points[b]);
      a = b;
      b++;
      c = b;
    }
    c++;
  }
  newPoints.push(points[b]);
  if (type === "Polygon" || type === "MultiPolygon") {
    if (_booleanpointonline.booleanPointOnLine.call(void 0, 
      newPoints[0],
      _helpers.lineString.call(void 0, [newPoints[1], newPoints[newPoints.length - 2]])
    )) {
      newPoints.shift();
      newPoints.pop();
      newPoints.push(newPoints[0]);
    }
    if (newPoints.length < 4) {
      throw new Error("invalid polygon, fewer than 4 points");
    }
    if (!equals(newPoints[0], newPoints[newPoints.length - 1])) {
      throw new Error("invalid polygon, first and last points not equal");
    }
  }
  return newPoints;
}
function equals(pt1, pt2) {
  return pt1[0] === pt2[0] && pt1[1] === pt2[1];
}
var index_default = cleanCoords;



exports.cleanCoords = cleanCoords; exports.default = index_default;
//# sourceMappingURL=index.cjs.map