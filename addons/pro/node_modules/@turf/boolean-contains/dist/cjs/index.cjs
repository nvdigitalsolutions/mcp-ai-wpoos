"use strict";Object.defineProperty(exports, "__esModule", {value: true});// index.ts
var _bbox = require('@turf/bbox');
var _booleanpointinpolygon = require('@turf/boolean-point-in-polygon');
var _booleanpointonline = require('@turf/boolean-point-on-line');
var _invariant = require('@turf/invariant');
var _helpers = require('@turf/helpers');
var _linesplit = require('@turf/line-split');
function booleanContains(feature1, feature2) {
  const geom1 = _invariant.getGeom.call(void 0, feature1);
  const geom2 = _invariant.getGeom.call(void 0, feature2);
  const type1 = geom1.type;
  const type2 = geom2.type;
  const coords1 = geom1.coordinates;
  const coords2 = geom2.coordinates;
  switch (type1) {
    case "Point":
      switch (type2) {
        case "Point":
          return compareCoords(coords1, coords2);
        default:
          throw new Error("feature2 " + type2 + " geometry not supported");
      }
    case "MultiPoint":
      switch (type2) {
        case "Point":
          return isPointInMultiPoint(geom1, geom2);
        case "MultiPoint":
          return isMultiPointInMultiPoint(geom1, geom2);
        default:
          throw new Error("feature2 " + type2 + " geometry not supported");
      }
    case "LineString":
      switch (type2) {
        case "Point":
          return _booleanpointonline.booleanPointOnLine.call(void 0, geom2, geom1, { ignoreEndVertices: true });
        case "LineString":
          return isLineOnLine(geom1, geom2);
        case "MultiPoint":
          return isMultiPointOnLine(geom1, geom2);
        default:
          throw new Error("feature2 " + type2 + " geometry not supported");
      }
    case "Polygon":
      switch (type2) {
        case "Point":
          return _booleanpointinpolygon.booleanPointInPolygon.call(void 0, geom2, geom1, { ignoreBoundary: true });
        case "LineString":
          return isLineInPoly(geom1, geom2);
        case "Polygon":
          return isPolyInPoly(geom1, geom2);
        case "MultiPoint":
          return isMultiPointInPoly(geom1, geom2);
        case "MultiPolygon":
          return isMultiPolyInPoly(geom1, geom2);
        default:
          throw new Error("feature2 " + type2 + " geometry not supported");
      }
    case "MultiPolygon":
      switch (type2) {
        case "Polygon":
          return isPolygonInMultiPolygon(geom1, geom2);
        default:
          throw new Error("feature2 " + type2 + " geometry not supported");
      }
    default:
      throw new Error("feature1 " + type1 + " geometry not supported");
  }
}
function isPolygonInMultiPolygon(multiPolygon, polygon) {
  return multiPolygon.coordinates.some(
    (coords) => isPolyInPoly({ type: "Polygon", coordinates: coords }, polygon)
  );
}
function isMultiPolyInPoly(polygon, multiPolygon) {
  return multiPolygon.coordinates.every(
    (coords) => isPolyInPoly(polygon, { type: "Polygon", coordinates: coords })
  );
}
function isPointInMultiPoint(multiPoint, pt) {
  let i;
  let output = false;
  for (i = 0; i < multiPoint.coordinates.length; i++) {
    if (compareCoords(multiPoint.coordinates[i], pt.coordinates)) {
      output = true;
      break;
    }
  }
  return output;
}
function isMultiPointInMultiPoint(multiPoint1, multiPoint2) {
  for (const coord2 of multiPoint2.coordinates) {
    let matchFound = false;
    for (const coord1 of multiPoint1.coordinates) {
      if (compareCoords(coord2, coord1)) {
        matchFound = true;
        break;
      }
    }
    if (!matchFound) {
      return false;
    }
  }
  return true;
}
function isMultiPointOnLine(lineString2, multiPoint) {
  let haveFoundInteriorPoint = false;
  for (const coord of multiPoint.coordinates) {
    if (_booleanpointonline.booleanPointOnLine.call(void 0, coord, lineString2, { ignoreEndVertices: true })) {
      haveFoundInteriorPoint = true;
    }
    if (!_booleanpointonline.booleanPointOnLine.call(void 0, coord, lineString2)) {
      return false;
    }
  }
  if (haveFoundInteriorPoint) {
    return true;
  }
  return false;
}
function isMultiPointInPoly(polygon, multiPoint) {
  for (const coord of multiPoint.coordinates) {
    if (!_booleanpointinpolygon.booleanPointInPolygon.call(void 0, coord, polygon, { ignoreBoundary: true })) {
      return false;
    }
  }
  return true;
}
function isLineOnLine(lineString1, lineString2) {
  let haveFoundInteriorPoint = false;
  for (const coords of lineString2.coordinates) {
    if (_booleanpointonline.booleanPointOnLine.call(void 0, { type: "Point", coordinates: coords }, lineString1, {
      ignoreEndVertices: true
    })) {
      haveFoundInteriorPoint = true;
    }
    if (!_booleanpointonline.booleanPointOnLine.call(void 0, { type: "Point", coordinates: coords }, lineString1, {
      ignoreEndVertices: false
    })) {
      return false;
    }
  }
  return haveFoundInteriorPoint;
}
function splitLineIntoSegmentsOnPolygon(linestring, polygon) {
  const coords = linestring.coordinates;
  const outputSegments = [];
  for (let i = 0; i < coords.length - 1; i++) {
    const seg = _helpers.lineString.call(void 0, [coords[i], coords[i + 1]]);
    const split = _linesplit.lineSplit.call(void 0, seg, _helpers.feature.call(void 0, polygon));
    if (split.features.length === 0) {
      outputSegments.push(seg);
    } else {
      outputSegments.push(...split.features);
    }
  }
  return _helpers.featureCollection.call(void 0, outputSegments);
}
function isLineInPoly(polygon, linestring) {
  const polyBbox = _bbox.bbox.call(void 0, polygon);
  const lineBbox = _bbox.bbox.call(void 0, linestring);
  if (!doBBoxOverlap(polyBbox, lineBbox)) {
    return false;
  }
  for (const coord of linestring.coordinates) {
    if (!_booleanpointinpolygon.booleanPointInPolygon.call(void 0, coord, polygon)) {
      return false;
    }
  }
  let isContainedByPolygonBoundary = false;
  const lineSegments = splitLineIntoSegmentsOnPolygon(linestring, polygon);
  for (const lineSegment of lineSegments.features) {
    const midpoint = getMidpoint(
      lineSegment.geometry.coordinates[0],
      lineSegment.geometry.coordinates[1]
    );
    if (!_booleanpointinpolygon.booleanPointInPolygon.call(void 0, midpoint, polygon)) {
      return false;
    }
    if (!isContainedByPolygonBoundary && _booleanpointinpolygon.booleanPointInPolygon.call(void 0, midpoint, polygon, { ignoreBoundary: true })) {
      isContainedByPolygonBoundary = true;
    }
  }
  return isContainedByPolygonBoundary;
}
function isPolyInPoly(feature1, feature2) {
  if (feature1.type === "Feature" && feature1.geometry === null) {
    return false;
  }
  if (feature2.type === "Feature" && feature2.geometry === null) {
    return false;
  }
  const poly1Bbox = _bbox.bbox.call(void 0, feature1);
  const poly2Bbox = _bbox.bbox.call(void 0, feature2);
  if (!doBBoxOverlap(poly1Bbox, poly2Bbox)) {
    return false;
  }
  const coords = _invariant.getGeom.call(void 0, feature2).coordinates;
  for (const ring of coords) {
    for (const coord of ring) {
      if (!_booleanpointinpolygon.booleanPointInPolygon.call(void 0, coord, feature1)) {
        return false;
      }
    }
  }
  return true;
}
function doBBoxOverlap(bbox1, bbox2) {
  if (bbox1[0] > bbox2[0]) {
    return false;
  }
  if (bbox1[2] < bbox2[2]) {
    return false;
  }
  if (bbox1[1] > bbox2[1]) {
    return false;
  }
  if (bbox1[3] < bbox2[3]) {
    return false;
  }
  return true;
}
function compareCoords(pair1, pair2) {
  return pair1[0] === pair2[0] && pair1[1] === pair2[1];
}
function getMidpoint(pair1, pair2) {
  return [(pair1[0] + pair2[0]) / 2, (pair1[1] + pair2[1]) / 2];
}
var index_default = booleanContains;















exports.booleanContains = booleanContains; exports.compareCoords = compareCoords; exports.default = index_default; exports.doBBoxOverlap = doBBoxOverlap; exports.getMidpoint = getMidpoint; exports.isLineInPoly = isLineInPoly; exports.isLineOnLine = isLineOnLine; exports.isMultiPointInMultiPoint = isMultiPointInMultiPoint; exports.isMultiPointInPoly = isMultiPointInPoly; exports.isMultiPointOnLine = isMultiPointOnLine; exports.isMultiPolyInPoly = isMultiPolyInPoly; exports.isPointInMultiPoint = isPointInMultiPoint; exports.isPolyInPoly = isPolyInPoly; exports.isPolygonInMultiPolygon = isPolygonInMultiPolygon;
//# sourceMappingURL=index.cjs.map