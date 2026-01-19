"use strict";Object.defineProperty(exports, "__esModule", {value: true});// index.ts
var _rectanglegrid = require('@turf/rectangle-grid');
function squareGrid(bbox, cellSide, options = {}) {
  return _rectanglegrid.rectangleGrid.call(void 0, bbox, cellSide, cellSide, options);
}
var index_default = squareGrid;



exports.default = index_default; exports.squareGrid = squareGrid;
//# sourceMappingURL=index.cjs.map