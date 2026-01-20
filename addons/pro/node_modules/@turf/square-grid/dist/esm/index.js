// index.ts
import { rectangleGrid } from "@turf/rectangle-grid";
function squareGrid(bbox, cellSide, options = {}) {
  return rectangleGrid(bbox, cellSide, cellSide, options);
}
var index_default = squareGrid;
export {
  index_default as default,
  squareGrid
};
//# sourceMappingURL=index.js.map