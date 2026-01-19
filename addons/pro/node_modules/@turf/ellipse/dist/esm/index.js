// index.ts
import {
  polygon,
  isObject,
  isNumber,
  point,
  radiansToDegrees
} from "@turf/helpers";
import { destination } from "@turf/destination";
import { transformRotate } from "@turf/transform-rotate";
import { getCoord } from "@turf/invariant";
function ellipse(center, xSemiAxis, ySemiAxis, options) {
  options = options || {};
  let steps = options.steps || 64;
  const units = options.units || "kilometers";
  let angle = options.angle || 0;
  const pivot = options.pivot || center;
  const properties = options.properties || {};
  if (!center) throw new Error("center is required");
  if (!xSemiAxis) throw new Error("xSemiAxis is required");
  if (!ySemiAxis) throw new Error("ySemiAxis is required");
  if (!isObject(options)) throw new Error("options must be an object");
  if (!isNumber(steps)) throw new Error("steps must be a number");
  if (!isNumber(angle)) throw new Error("angle must be a number");
  const centerCoords = getCoord(
    transformRotate(point(getCoord(center)), angle, { pivot })
  );
  angle = -90 + angle;
  steps = Math.ceil(steps / 4);
  let quadrantParameters = [];
  let parameters = [];
  const a = xSemiAxis;
  const b = ySemiAxis;
  const c = b;
  const m = (a - b) / (Math.PI / 2);
  const A = (a + b) * Math.PI / 4;
  const v = 0.5;
  const k = steps;
  let w = 0;
  let x = 0;
  for (let i = 0; i < steps; i++) {
    x += w;
    if (m === 0) {
      w = A / k / c;
    } else {
      w = (-(m * x + c) + Math.sqrt(Math.pow(m * x + c, 2) - 4 * (v * m) * -(A / k))) / (2 * (v * m));
    }
    if (x != 0) {
      quadrantParameters.push(x);
    }
  }
  parameters.push(0);
  for (let i = 0; i < quadrantParameters.length; i++) {
    parameters.push(quadrantParameters[i]);
  }
  parameters.push(Math.PI / 2);
  for (let i = 0; i < quadrantParameters.length; i++) {
    parameters.push(
      Math.PI - quadrantParameters[quadrantParameters.length - i - 1]
    );
  }
  parameters.push(Math.PI);
  for (let i = 0; i < quadrantParameters.length; i++) {
    parameters.push(Math.PI + quadrantParameters[i]);
  }
  parameters.push(3 * Math.PI / 2);
  for (let i = 0; i < quadrantParameters.length; i++) {
    parameters.push(
      2 * Math.PI - quadrantParameters[quadrantParameters.length - i - 1]
    );
  }
  parameters.push(0);
  const coords = [];
  for (const param of parameters) {
    const theta = Math.atan2(b * Math.sin(param), a * Math.cos(param));
    const r = Math.sqrt(
      Math.pow(a, 2) * Math.pow(b, 2) / (Math.pow(a * Math.sin(theta), 2) + Math.pow(b * Math.cos(theta), 2))
    );
    coords.push(
      destination(centerCoords, r, angle + radiansToDegrees(theta), {
        units
      }).geometry.coordinates
    );
  }
  return polygon([coords], properties);
}
var index_default = ellipse;
export {
  index_default as default,
  ellipse
};
//# sourceMappingURL=index.js.map