var PageAgent = (() => {
  var __defProp = Object.defineProperty;
  var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
  var __getOwnPropNames = Object.getOwnPropertyNames;
  var __hasOwnProp = Object.prototype.hasOwnProperty;
  var __typeError = (msg) => {
    throw TypeError(msg);
  };
  var __defNormalProp = (obj, key, value) => key in obj ? __defProp(obj, key, { enumerable: true, configurable: true, writable: true, value }) : obj[key] = value;
  var __esm = (fn, res, err) => function __init() {
    if (err) throw err[0];
    try {
      return fn && (res = (0, fn[__getOwnPropNames(fn)[0]])(fn = 0)), res;
    } catch (e) {
      throw err = [e], e;
    }
  };
  var __export = (target, all) => {
    for (var name in all)
      __defProp(target, name, { get: all[name], enumerable: true });
  };
  var __copyProps = (to, from, except, desc) => {
    if (from && typeof from === "object" || typeof from === "function") {
      for (let key of __getOwnPropNames(from))
        if (!__hasOwnProp.call(to, key) && key !== except)
          __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
    }
    return to;
  };
  var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);
  var __publicField = (obj, key, value) => __defNormalProp(obj, typeof key !== "symbol" ? key + "" : key, value);
  var __accessCheck = (obj, member, msg) => member.has(obj) || __typeError("Cannot " + msg);
  var __privateGet = (obj, member, getter) => (__accessCheck(obj, member, "read from private field"), getter ? getter.call(obj) : member.get(obj));
  var __privateAdd = (obj, member, value) => member.has(obj) ? __typeError("Cannot add the same private member more than once") : member instanceof WeakSet ? member.add(obj) : member.set(obj, value);
  var __privateSet = (obj, member, value, setter) => (__accessCheck(obj, member, "write to private field"), setter ? setter.call(obj, value) : member.set(obj, value), value);
  var __privateMethod = (obj, member, method) => (__accessCheck(obj, member, "access private method"), method);

  // node_modules/ai-motion/build/Motion.js
  function computeBorderGeometry(pixelWidth, pixelHeight, borderWidth, glowWidth) {
    const shortSide = Math.max(1, Math.min(pixelWidth, pixelHeight));
    const borderWidthPx = Math.min(borderWidth, 20);
    const glowWidthPx = glowWidth;
    const totalThick = Math.min(borderWidthPx + glowWidthPx, shortSide);
    const insetX = Math.min(totalThick, Math.floor(pixelWidth / 2));
    const insetY = Math.min(totalThick, Math.floor(pixelHeight / 2));
    const toClipX = (x) => x / pixelWidth * 2 - 1;
    const toClipY = (y) => y / pixelHeight * 2 - 1;
    const x0 = 0;
    const x1 = pixelWidth;
    const y0 = 0;
    const y1 = pixelHeight;
    const xi0 = insetX;
    const xi1 = pixelWidth - insetX;
    const yi0 = insetY;
    const yi1 = pixelHeight - insetY;
    const X0 = toClipX(x0);
    const X1 = toClipX(x1);
    const Y0 = toClipY(y0);
    const Y1 = toClipY(y1);
    const Xi0 = toClipX(xi0);
    const Xi1 = toClipX(xi1);
    const Yi0 = toClipY(yi0);
    const Yi1 = toClipY(yi1);
    const u0 = 0;
    const v0 = 0;
    const u1 = 1;
    const v1 = 1;
    const ui0 = insetX / pixelWidth;
    const ui1 = 1 - insetX / pixelWidth;
    const vi0 = insetY / pixelHeight;
    const vi1 = 1 - insetY / pixelHeight;
    const positions = new Float32Array([
      // Top strip
      X0,
      Y0,
      X1,
      Y0,
      X0,
      Yi0,
      X0,
      Yi0,
      X1,
      Y0,
      X1,
      Yi0,
      // Bottom strip
      X0,
      Yi1,
      X1,
      Yi1,
      X0,
      Y1,
      X0,
      Y1,
      X1,
      Yi1,
      X1,
      Y1,
      // Left strip
      X0,
      Yi0,
      Xi0,
      Yi0,
      X0,
      Yi1,
      X0,
      Yi1,
      Xi0,
      Yi0,
      Xi0,
      Yi1,
      // Right strip
      Xi1,
      Yi0,
      X1,
      Yi0,
      Xi1,
      Yi1,
      Xi1,
      Yi1,
      X1,
      Yi0,
      X1,
      Yi1
    ]);
    const uvs = new Float32Array([
      // Top strip
      u0,
      v0,
      u1,
      v0,
      u0,
      vi0,
      u0,
      vi0,
      u1,
      v0,
      u1,
      vi0,
      // Bottom strip
      u0,
      vi1,
      u1,
      vi1,
      u0,
      v1,
      u0,
      v1,
      u1,
      vi1,
      u1,
      v1,
      // Left strip
      u0,
      vi0,
      ui0,
      vi0,
      u0,
      vi1,
      u0,
      vi1,
      ui0,
      vi0,
      ui0,
      vi1,
      // Right strip
      ui1,
      vi0,
      u1,
      vi0,
      ui1,
      vi1,
      ui1,
      vi1,
      u1,
      vi0,
      u1,
      vi1
    ]);
    return { positions, uvs };
  }
  function compileShader(gl, type, source) {
    const shader = gl.createShader(type);
    if (!shader) throw new Error("Failed to create shader");
    gl.shaderSource(shader, source);
    gl.compileShader(shader);
    if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
      const info = gl.getShaderInfoLog(shader) || "Unknown shader error";
      gl.deleteShader(shader);
      throw new Error(info);
    }
    return shader;
  }
  function createProgram(gl, vertexSource, fragmentSource) {
    const vs = compileShader(gl, gl.VERTEX_SHADER, vertexSource);
    const fs = compileShader(gl, gl.FRAGMENT_SHADER, fragmentSource);
    const program = gl.createProgram();
    if (!program) throw new Error("Failed to create program");
    gl.attachShader(program, vs);
    gl.attachShader(program, fs);
    gl.linkProgram(program);
    if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
      const info = gl.getProgramInfoLog(program) || "Unknown link error";
      gl.deleteProgram(program);
      gl.deleteShader(vs);
      gl.deleteShader(fs);
      throw new Error(info);
    }
    gl.deleteShader(vs);
    gl.deleteShader(fs);
    return program;
  }
  function parseColor(colorStr) {
    const match = colorStr.match(/rgb\((\d+),\s*(\d+),\s*(\d+)\)/);
    if (!match) {
      throw new Error(`Invalid color format: ${colorStr}`);
    }
    const [, r, g, b] = match;
    return [parseInt(r) / 255, parseInt(g) / 255, parseInt(b) / 255];
  }
  var fragmentShaderSource, vertexShaderSource, DEFAULT_COLORS, Motion;
  var init_Motion = __esm({
    "node_modules/ai-motion/build/Motion.js"() {
      fragmentShaderSource = `#version 300 es
precision lowp float;
in vec2 vUV;
out vec4 outColor;
uniform vec2 uResolution;
uniform float uTime;
uniform float uBorderWidth;
uniform float uGlowWidth;
uniform float uBorderRadius;
uniform vec3 uColors[4];
uniform float uGlowExponent;
uniform float uGlowFactor;
const float PI = 3.14159265359;
const float TWO_PI = 2.0 * PI;
const float HALF_PI = 0.5 * PI;
const vec4 startPositions = vec4(0.0, PI, HALF_PI, 1.5 * PI);
const vec4 speeds = vec4(-1.9, -1.9, -1.5, 2.1);
const vec4 innerRadius = vec4(PI * 0.8, PI * 0.7, PI * 0.3, PI * 0.1);
const vec4 outerRadius = vec4(PI * 1.2, PI * 0.9, PI * 0.6, PI * 0.4);
float random(vec2 st) {
return fract(sin(dot(st.xy, vec2(12.9898, 78.233))) * 43758.5453123);
}
vec2 random2(vec2 st) {
return vec2(random(st), random(st + 1.0));
}
float aaStep(float edge, float d) {
float width = fwidth(d);
return smoothstep(edge - width * 0.5, edge + width * 0.5, d);
}
float aaFract(float x) {
float f = fract(x);
float w = fwidth(x);
float smooth_f = f * (1.0 - smoothstep(1.0 - w, 1.0, f));
return smooth_f;
}
float sdRoundedBox(in vec2 p, in vec2 b, in float r) {
vec2 q = abs(p) - b + r;
return min(max(q.x, q.y), 0.0) + length(max(q, 0.0)) - r;
}
float getInnerGlow(vec2 p, vec2 b, float radius) {
float dist_x = b.x - abs(p.x);
float dist_y = b.y - abs(p.y);
float glow_x = smoothstep(radius, 0.0, dist_x);
float glow_y = smoothstep(radius, 0.0, dist_y);
return 1.0 - (1.0 - glow_x) * (1.0 - glow_y);
}
float getVignette(vec2 uv) {
vec2 vignetteUv = uv;
vignetteUv = vignetteUv * (1.0 - vignetteUv);
float vignette = vignetteUv.x * vignetteUv.y * 25.0;
vignette = pow(vignette, 0.16);
vignette = 1.0 - vignette;
return vignette;
}
float uvToAngle(vec2 uv) {
vec2 center = vec2(0.5);
vec2 dir = uv - center;
return atan(dir.y, dir.x) + PI;
}
void main() {
vec2 uv = vUV;
vec2 pos = uv * uResolution;
vec2 centeredPos = pos - uResolution * 0.5;
vec2 size = uResolution - uBorderWidth;
vec2 halfSize = size * 0.5;
float dBorderBox = sdRoundedBox(centeredPos, halfSize, uBorderRadius);
float border = aaStep(0.0, dBorderBox);
float glow = getInnerGlow(centeredPos, halfSize, uGlowWidth);
float vignette = getVignette(uv);
glow *= vignette;
float posAngle = uvToAngle(uv);
vec4 lightCenter = mod(startPositions + speeds * uTime, TWO_PI);
vec4 angleDist = abs(posAngle - lightCenter);
vec4 disToLight = min(angleDist, TWO_PI - angleDist) / TWO_PI;
float intensityBorder[4];
intensityBorder[0] = 1.0;
intensityBorder[1] = smoothstep(0.4, 0.0, disToLight.y);
intensityBorder[2] = smoothstep(0.4, 0.0, disToLight.z);
intensityBorder[3] = smoothstep(0.2, 0.0, disToLight.w) * 0.5;
vec3 borderColor = vec3(0.0);
for(int i = 0; i < 4; i++) {
borderColor = mix(borderColor, uColors[i], intensityBorder[i]);
}
borderColor *= 1.1;
borderColor = clamp(borderColor, 0.0, 1.0);
float intensityGlow[4];
intensityGlow[0] = smoothstep(0.9, 0.0, disToLight.x);
intensityGlow[1] = smoothstep(0.7, 0.0, disToLight.y);
intensityGlow[2] = smoothstep(0.4, 0.0, disToLight.z);
intensityGlow[3] = smoothstep(0.1, 0.0, disToLight.w) * 0.7;
vec4 breath = smoothstep(0.0, 1.0, sin(uTime * 1.0 + startPositions * PI) * 0.2 + 0.8);
vec3 glowColor = vec3(0.0);
glowColor += uColors[0] * intensityGlow[0] * breath.x;
glowColor += uColors[1] * intensityGlow[1] * breath.y;
glowColor += uColors[2] * intensityGlow[2] * breath.z;
glowColor += uColors[3] * intensityGlow[3] * breath.w * glow;
glow = pow(glow, uGlowExponent);
glow *= random(pos + uTime) * 0.1 + 1.0;
glowColor *= glow * uGlowFactor;
glowColor = clamp(glowColor, 0.0, 1.0);
vec3 color = mix(glowColor, borderColor + glowColor * 0.2, border);
float alpha = mix(glow, 1.0, border);
outColor = vec4(color, alpha);
}`;
      vertexShaderSource = `#version 300 es
in vec2 aPosition;
in vec2 aUV;
out vec2 vUV;
void main() {
vUV = aUV;
gl_Position = vec4(aPosition, 0.0, 1.0);
}`;
      DEFAULT_COLORS = [
        "rgb(57, 182, 255)",
        "rgb(189, 69, 251)",
        "rgb(255, 87, 51)",
        "rgb(255, 214, 0)"
      ];
      Motion = class {
        constructor(options = {}) {
          __publicField(this, "element");
          __publicField(this, "canvas");
          __publicField(this, "options");
          __publicField(this, "running", false);
          __publicField(this, "disposed", false);
          __publicField(this, "startTime", 0);
          __publicField(this, "lastTime", 0);
          __publicField(this, "rafId", null);
          __publicField(this, "glr");
          __publicField(this, "observer");
          this.options = {
            width: options.width ?? 600,
            height: options.height ?? 600,
            ratio: options.ratio ?? window.devicePixelRatio ?? 1,
            borderWidth: options.borderWidth ?? 8,
            glowWidth: options.glowWidth ?? 200,
            borderRadius: options.borderRadius ?? 8,
            mode: options.mode ?? "light",
            ...options
          };
          this.canvas = document.createElement("canvas");
          if (this.options.classNames) {
            this.canvas.className = this.options.classNames;
          }
          if (this.options.styles) {
            Object.assign(this.canvas.style, this.options.styles);
          }
          this.canvas.style.display = "block";
          this.canvas.style.transformOrigin = "center";
          this.canvas.style.pointerEvents = "none";
          this.element = this.canvas;
          this.setupGL();
          if (!this.options.skipGreeting) this.greet();
        }
        start() {
          if (this.disposed) throw new Error("Motion instance has been disposed.");
          if (this.running) return;
          if (!this.glr) {
            console.error("WebGL resources are not initialized.");
            return;
          }
          this.running = true;
          this.startTime = performance.now();
          this.resize(this.options.width ?? 600, this.options.height ?? 600, this.options.ratio);
          this.glr.gl.viewport(0, 0, this.canvas.width, this.canvas.height);
          this.glr.gl.useProgram(this.glr.program);
          this.glr.gl.uniform2f(this.glr.uResolution, this.canvas.width, this.canvas.height);
          this.checkGLError(this.glr.gl, "start: after initial setup");
          const loop = () => {
            if (!this.running || !this.glr) return;
            this.rafId = requestAnimationFrame(loop);
            const now = performance.now();
            const delta = now - this.lastTime;
            if (delta < 1e3 / 32) return;
            this.lastTime = now;
            const t = (now - this.startTime) * 1e-3;
            this.render(t);
          };
          this.rafId = requestAnimationFrame(loop);
        }
        pause() {
          if (this.disposed) throw new Error("Motion instance has been disposed.");
          this.running = false;
          if (this.rafId !== null) cancelAnimationFrame(this.rafId);
        }
        dispose() {
          if (this.disposed) return;
          this.disposed = true;
          this.running = false;
          if (this.rafId !== null) cancelAnimationFrame(this.rafId);
          const { gl, vao, positionBuffer, uvBuffer, program } = this.glr;
          if (vao) gl.deleteVertexArray(vao);
          if (positionBuffer) gl.deleteBuffer(positionBuffer);
          if (uvBuffer) gl.deleteBuffer(uvBuffer);
          gl.deleteProgram(program);
          if (this.observer) this.observer.disconnect();
          this.canvas.remove();
        }
        resize(width, height, ratio) {
          if (this.disposed) throw new Error("Motion instance has been disposed.");
          this.options.width = width;
          this.options.height = height;
          if (ratio) this.options.ratio = ratio;
          if (!this.running) return;
          const { gl, program, vao, positionBuffer, uvBuffer, uResolution } = this.glr;
          const dpr = ratio ?? this.options.ratio ?? window.devicePixelRatio ?? 1;
          const desiredWidth = Math.max(1, Math.floor(width * dpr));
          const desiredHeight = Math.max(1, Math.floor(height * dpr));
          this.canvas.style.width = `${width}px`;
          this.canvas.style.height = `${height}px`;
          if (this.canvas.width !== desiredWidth || this.canvas.height !== desiredHeight) {
            this.canvas.width = desiredWidth;
            this.canvas.height = desiredHeight;
          }
          gl.viewport(0, 0, this.canvas.width, this.canvas.height);
          this.checkGLError(gl, "resize: after viewport setup");
          const { positions, uvs } = computeBorderGeometry(
            this.canvas.width,
            this.canvas.height,
            this.options.borderWidth * dpr,
            this.options.glowWidth * dpr
          );
          gl.bindVertexArray(vao);
          gl.bindBuffer(gl.ARRAY_BUFFER, positionBuffer);
          gl.bufferData(gl.ARRAY_BUFFER, positions, gl.STATIC_DRAW);
          const aPosition = gl.getAttribLocation(program, "aPosition");
          gl.enableVertexAttribArray(aPosition);
          gl.vertexAttribPointer(aPosition, 2, gl.FLOAT, false, 0, 0);
          this.checkGLError(gl, "resize: after position buffer update");
          gl.bindBuffer(gl.ARRAY_BUFFER, uvBuffer);
          gl.bufferData(gl.ARRAY_BUFFER, uvs, gl.STATIC_DRAW);
          const aUV = gl.getAttribLocation(program, "aUV");
          gl.enableVertexAttribArray(aUV);
          gl.vertexAttribPointer(aUV, 2, gl.FLOAT, false, 0, 0);
          this.checkGLError(gl, "resize: after UV buffer update");
          gl.useProgram(program);
          gl.uniform2f(uResolution, this.canvas.width, this.canvas.height);
          gl.uniform1f(this.glr.uBorderWidth, this.options.borderWidth * dpr);
          gl.uniform1f(this.glr.uGlowWidth, this.options.glowWidth * dpr);
          gl.uniform1f(this.glr.uBorderRadius, this.options.borderRadius * dpr);
          this.checkGLError(gl, "resize: after uniform updates");
          const now = performance.now();
          this.lastTime = now;
          const t = (now - this.startTime) * 1e-3;
          this.render(t);
        }
        /**
         * Automatically resizes the canvas to match the dimensions of the given element.
         * @note using ResizeObserver
         */
        autoResize(sourceElement) {
          if (this.observer) {
            this.observer.disconnect();
          }
          this.observer = new ResizeObserver(() => {
            const rect = sourceElement.getBoundingClientRect();
            this.resize(rect.width, rect.height);
          });
          this.observer.observe(sourceElement);
        }
        fadeIn() {
          if (this.disposed) throw new Error("Motion instance has been disposed.");
          return new Promise((resolve, reject) => {
            const animation = this.canvas.animate(
              [
                { opacity: 0, transform: "scale(1.2)" },
                { opacity: 1, transform: "scale(1)" }
              ],
              { duration: 300, easing: "ease-out", fill: "forwards" }
            );
            animation.onfinish = () => resolve();
            animation.oncancel = () => reject("canceled");
          });
        }
        fadeOut() {
          if (this.disposed) throw new Error("Motion instance has been disposed.");
          return new Promise((resolve, reject) => {
            const animation = this.canvas.animate(
              [
                { opacity: 1, transform: "scale(1)" },
                { opacity: 0, transform: "scale(1.2)" }
              ],
              { duration: 300, easing: "ease-in", fill: "forwards" }
            );
            animation.onfinish = () => resolve();
            animation.oncancel = () => reject("canceled");
          });
        }
        checkGLError(gl, context) {
          let error2 = gl.getError();
          if (error2 !== gl.NO_ERROR) {
            console.group(`\u{1F534} WebGL Error in ${context}`);
            while (error2 !== gl.NO_ERROR) {
              const errorName = this.getGLErrorName(gl, error2);
              console.error(`${errorName} (0x${error2.toString(16)})`);
              error2 = gl.getError();
            }
            console.groupEnd();
          }
        }
        getGLErrorName(gl, error2) {
          switch (error2) {
            case gl.INVALID_ENUM:
              return "INVALID_ENUM";
            case gl.INVALID_VALUE:
              return "INVALID_VALUE";
            case gl.INVALID_OPERATION:
              return "INVALID_OPERATION";
            case gl.INVALID_FRAMEBUFFER_OPERATION:
              return "INVALID_FRAMEBUFFER_OPERATION";
            case gl.OUT_OF_MEMORY:
              return "OUT_OF_MEMORY";
            case gl.CONTEXT_LOST_WEBGL:
              return "CONTEXT_LOST_WEBGL";
            default:
              return "UNKNOWN_ERROR";
          }
        }
        setupGL() {
          const gl = this.canvas.getContext("webgl2", { antialias: false, alpha: true });
          if (!gl) {
            throw new Error("WebGL2 is required but not available.");
          }
          const program = createProgram(gl, vertexShaderSource, fragmentShaderSource);
          this.checkGLError(gl, "setupGL: after createProgram");
          const vao = gl.createVertexArray();
          gl.bindVertexArray(vao);
          this.checkGLError(gl, "setupGL: after VAO creation");
          const pw = this.canvas.width || 2;
          const ph = this.canvas.height || 2;
          const { positions, uvs } = computeBorderGeometry(
            pw,
            ph,
            this.options.borderWidth,
            this.options.glowWidth
          );
          const positionBuffer = gl.createBuffer();
          gl.bindBuffer(gl.ARRAY_BUFFER, positionBuffer);
          gl.bufferData(gl.ARRAY_BUFFER, positions, gl.STATIC_DRAW);
          const aPosition = gl.getAttribLocation(program, "aPosition");
          gl.enableVertexAttribArray(aPosition);
          gl.vertexAttribPointer(aPosition, 2, gl.FLOAT, false, 0, 0);
          this.checkGLError(gl, "setupGL: after position buffer setup");
          const uvBuffer = gl.createBuffer();
          gl.bindBuffer(gl.ARRAY_BUFFER, uvBuffer);
          gl.bufferData(gl.ARRAY_BUFFER, uvs, gl.STATIC_DRAW);
          const aUV = gl.getAttribLocation(program, "aUV");
          gl.enableVertexAttribArray(aUV);
          gl.vertexAttribPointer(aUV, 2, gl.FLOAT, false, 0, 0);
          this.checkGLError(gl, "setupGL: after UV buffer setup");
          const uResolution = gl.getUniformLocation(program, "uResolution");
          const uTime = gl.getUniformLocation(program, "uTime");
          const uBorderWidth = gl.getUniformLocation(program, "uBorderWidth");
          const uGlowWidth = gl.getUniformLocation(program, "uGlowWidth");
          const uBorderRadius = gl.getUniformLocation(program, "uBorderRadius");
          const uColors = gl.getUniformLocation(program, "uColors");
          const uGlowExponent = gl.getUniformLocation(program, "uGlowExponent");
          const uGlowFactor = gl.getUniformLocation(program, "uGlowFactor");
          gl.useProgram(program);
          gl.uniform1f(uBorderWidth, this.options.borderWidth);
          gl.uniform1f(uGlowWidth, this.options.glowWidth);
          gl.uniform1f(uBorderRadius, this.options.borderRadius);
          if (this.options.mode === "dark") {
            gl.uniform1f(uGlowExponent, 2);
            gl.uniform1f(uGlowFactor, 1.8);
          } else {
            gl.uniform1f(uGlowExponent, 1);
            gl.uniform1f(uGlowFactor, 1);
          }
          const colorVecs = (this.options.colors || DEFAULT_COLORS).map(parseColor);
          for (let i = 0; i < colorVecs.length; i++) {
            gl.uniform3f(gl.getUniformLocation(program, `uColors[${i}]`), ...colorVecs[i]);
          }
          this.checkGLError(gl, "setupGL: after uniform setup");
          gl.bindVertexArray(null);
          gl.bindBuffer(gl.ARRAY_BUFFER, null);
          this.glr = {
            gl,
            program,
            vao,
            positionBuffer,
            uvBuffer,
            uResolution,
            uTime,
            uBorderWidth,
            uGlowWidth,
            uBorderRadius,
            uColors
          };
        }
        render(t) {
          if (!this.glr) return;
          const { gl, program, vao, uTime } = this.glr;
          gl.useProgram(program);
          gl.bindVertexArray(vao);
          gl.uniform1f(uTime, t);
          gl.disable(gl.DEPTH_TEST);
          gl.disable(gl.CULL_FACE);
          gl.disable(gl.BLEND);
          gl.clearColor(0, 0, 0, 0);
          gl.clear(gl.COLOR_BUFFER_BIT);
          gl.drawArrays(gl.TRIANGLES, 0, 24);
          this.checkGLError(gl, "render: after draw call");
          gl.bindVertexArray(null);
        }
        greet() {
          console.log(
            `%c\u{1F308} ai-motion ${"0.4.8"} \u{1F308}`,
            "background: linear-gradient(90deg, #39b6ff, #bd45fb, #ff5733, #ffd600); color: white; text-shadow: 0 0 2px rgba(0, 0, 0, 0.2); font-weight: bold; font-size: 1em; padding: 2px 12px; border-radius: 6px;"
          );
        }
      };
    }
  });

  // node_modules/@page-agent/page-controller/dist/lib/SimulatorMask-BHVXyogh.js
  var SimulatorMask_BHVXyogh_exports = {};
  __export(SimulatorMask_BHVXyogh_exports, {
    SimulatorMask: () => SimulatorMask
  });
  function isPageDark() {
    try {
      if (hasDarkModeClass()) return true;
      if (hasDarkModeDataAttribute()) return true;
      if (isColorSchemeDark()) return true;
      if (isBackgroundDark()) return true;
      if (isMainContentBackgroundDark()) return true;
      if (isTextColorLight()) return true;
      return false;
    } catch (error2) {
      console.warn("Error determining if page is dark:", error2);
      return false;
    }
  }
  function hasDarkModeClass() {
    const DEFAULT_DARK_MODE_CLASSES = [
      "dark",
      "dark-mode",
      "theme-dark",
      "night",
      "night-mode"
    ];
    const htmlElement = document.documentElement;
    const bodyElement = document.body || document.documentElement;
    for (const className of DEFAULT_DARK_MODE_CLASSES) if (htmlElement.classList.contains(className) || bodyElement?.classList.contains(className)) return true;
    return false;
  }
  function hasDarkModeDataAttribute() {
    const htmlElement = document.documentElement;
    const bodyElement = document.body || document.documentElement;
    for (const attr of [
      "data-theme",
      "data-color-mode",
      "data-bs-theme",
      "data-mui-color-scheme"
    ]) {
      const bodyValue = bodyElement?.getAttribute(attr);
      const htmlValue = htmlElement.getAttribute(attr);
      if (bodyValue?.toLowerCase() === "dark" || htmlValue?.toLowerCase() === "dark") return true;
    }
    return false;
  }
  function isColorSchemeDark() {
    const metaContent = document.querySelector('meta[name="color-scheme"]')?.content.toLowerCase();
    if (metaContent === "dark" || metaContent === "only dark") return true;
    const colorScheme = window.getComputedStyle(document.documentElement).getPropertyValue("color-scheme").trim().toLowerCase();
    return colorScheme === "dark" || colorScheme === "only dark";
  }
  function isBackgroundDark() {
    const htmlStyle = window.getComputedStyle(document.documentElement);
    const bodyStyle = window.getComputedStyle(document.body || document.documentElement);
    const htmlBgColor = htmlStyle.backgroundColor;
    const bodyBgColor = bodyStyle.backgroundColor;
    if (isColorDark(bodyBgColor)) return true;
    else if (bodyBgColor === "transparent" || bodyBgColor.startsWith("rgba(0, 0, 0, 0)")) return isColorDark(htmlBgColor);
    return false;
  }
  function isTextColorLight() {
    const LIGHT_TEXT_LUMINANCE = 200;
    const luminance = getLuminance(window.getComputedStyle(document.body || document.documentElement).color);
    return luminance !== null && luminance > LIGHT_TEXT_LUMINANCE;
  }
  function isMainContentBackgroundDark() {
    const { innerWidth: vw, innerHeight: vh } = window;
    const minArea = vw * vh * 0.5;
    for (const selector of [
      "#app",
      "#root",
      "#__next"
    ]) {
      const el = document.querySelector(selector);
      if (!el) continue;
      const rect = el.getBoundingClientRect();
      if (rect.width * rect.height < minArea) continue;
      if (isColorDark(window.getComputedStyle(el).backgroundColor)) return true;
    }
    return false;
  }
  function parseRgbColor(colorString) {
    const rgbMatch = /rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(colorString);
    if (!rgbMatch) return null;
    return {
      r: parseInt(rgbMatch[1]),
      g: parseInt(rgbMatch[2]),
      b: parseInt(rgbMatch[3])
    };
  }
  function getLuminance(colorString) {
    if (!colorString || colorString === "transparent" || colorString.startsWith("rgba(0, 0, 0, 0)")) return null;
    const rgb = parseRgbColor(colorString);
    if (!rgb) return null;
    return 0.299 * rgb.r + 0.587 * rgb.g + 0.114 * rgb.b;
  }
  function isColorDark(colorString, threshold = 128) {
    const luminance = getLuminance(colorString);
    return luminance !== null && luminance < threshold;
  }
  var SimulatorMask_module_default, cursor_module_default, _disposed, _cursor, _currentCursorX, _currentCursorY, _targetCursorX, _targetCursorY, _SimulatorMask_instances, createCursor_fn, moveCursorToTarget_fn, _a4, SimulatorMask;
  var init_SimulatorMask_BHVXyogh = __esm({
    "node_modules/@page-agent/page-controller/dist/lib/SimulatorMask-BHVXyogh.js"() {
      init_Motion();
      (function() {
        try {
          if (typeof document != "undefined") {
            var elementStyle = document.createElement("style");
            elementStyle.appendChild(document.createTextNode(`._wrapper_1ooyb_1 {
	position: fixed;
	inset: 0;
	z-index: 2147483641; /* \u786E\u4FDD\u5728\u6240\u6709\u5143\u7D20\u4E4B\u4E0A\uFF0C\u9664\u4E86 panel */
	cursor: wait;
	overflow: hidden;

	display: none;
}

._wrapper_1ooyb_1._visible_1ooyb_11 {
	display: block;
}
/* AI \u5149\u6807\u6837\u5F0F */
._cursor_1dgwb_2 {
	position: absolute;
	width: var(--cursor-size, 75px);
	height: var(--cursor-size, 75px);
	pointer-events: none;
	z-index: 10000;
}

._cursorBorder_1dgwb_10 {
	position: absolute;
	width: 100%;
	height: 100%;
	background: linear-gradient(45deg, rgb(57, 182, 255), rgb(189, 69, 251));
	mask-image: url("data:image/svg+xml,%3csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%20100%20100'%20fill='none'%3e%3cg%3e%3cpath%20d='M%2015%2042%20L%2015%2036.99%20Q%2015%2031.99%2023.7%2031.99%20L%2028.05%2031.99%20Q%2032.41%2031.99%2032.41%2021.99%20L%2032.41%2017%20Q%2032.41%2012%2041.09%2016.95%20L%2076.31%2037.05%20Q%2085%2042%2076.31%2046.95%20L%2041.09%2067.05%20Q%2032.41%2072%2032.41%2062.01%20L%2032.41%2057.01%20Q%2032.41%2052.01%2023.7%2052.01%20L%2019.35%2052.01%20Q%2015%2052.01%2015%2047.01%20Z'%20fill='none'%20stroke='%23000000'%20stroke-width='6'%20stroke-miterlimit='10'%20style='stroke:%20light-dark(rgb(0,%200,%200),%20rgb(255,%20255,%20255));'/%3e%3c/g%3e%3c/svg%3e");
	mask-size: 100% 100%;
	mask-repeat: no-repeat;

	transform-origin: center;
	transform: rotate(-135deg) scale(1.2);
	margin-left: -10px;
	margin-top: -18px;
}

._cursorFilling_1dgwb_25 {
	position: absolute;
	width: 100%;
	height: 100%;
	background: url("data:image/svg+xml,%3csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%20100%20100'%3e%3cdefs%3e%3c/defs%3e%3cg%20xmlns='http://www.w3.org/2000/svg'%20style='filter:%20drop-shadow(light-dark(rgba(0,%200,%200,%200.4),%20rgba(237,%20237,%20237,%200.4))%203px%204px%204px);'%3e%3cpath%20d='M%2015%2042%20L%2015%2036.99%20Q%2015%2031.99%2023.7%2031.99%20L%2028.05%2031.99%20Q%2032.41%2031.99%2032.41%2021.99%20L%2032.41%2017%20Q%2032.41%2012%2041.09%2016.95%20L%2076.31%2037.05%20Q%2085%2042%2076.31%2046.95%20L%2041.09%2067.05%20Q%2032.41%2072%2032.41%2062.01%20L%2032.41%2057.01%20Q%2032.41%2052.01%2023.7%2052.01%20L%2019.35%2052.01%20Q%2015%2052.01%2015%2047.01%20Z'%20fill='%23ffffff'%20stroke='none'%20style='fill:%20%23ffffff;'/%3e%3c/g%3e%3c/svg%3e");
	background-size: 100% 100%;
	background-repeat: no-repeat;

	transform-origin: center;
	transform: rotate(-135deg) scale(1.2);
	margin-left: -10px;
	margin-top: -18px;
}

._cursorRipple_1dgwb_39 {
	position: absolute;
	width: 100%;
	height: 100%;
	pointer-events: none;
	margin-left: -50%;
	margin-top: -50%;

	&::after {
		content: '';
		opacity: 0;
		position: absolute;
		inset: 0;
		border: 4px solid rgba(57, 182, 255, 1);
		border-radius: 50%;
	}
}

._cursor_1dgwb_2._clicking_1dgwb_57 ._cursorRipple_1dgwb_39::after {
	animation: _cursor-ripple_1dgwb_1 300ms ease-out forwards;
}

@keyframes _cursor-ripple_1dgwb_1 {
	0% {
		transform: scale(0);
		opacity: 1;
	}
	100% {
		transform: scale(2);
		opacity: 0;
	}
}`));
            document.head.appendChild(elementStyle);
          }
        } catch (e) {
          console.error("vite-plugin-css-injected-by-js", e);
        }
      })();
      (function() {
        try {
          if (typeof document != "undefined") {
            var elementStyle = document.createElement("style");
            elementStyle.appendChild(document.createTextNode(`._wrapper_1ooyb_1 {
	position: fixed;
	inset: 0;
	z-index: 2147483641; /* \u786E\u4FDD\u5728\u6240\u6709\u5143\u7D20\u4E4B\u4E0A\uFF0C\u9664\u4E86 panel */
	cursor: wait;
	overflow: hidden;

	display: none;
}

._wrapper_1ooyb_1._visible_1ooyb_11 {
	display: block;
}
/* AI \u5149\u6807\u6837\u5F0F */
._cursor_1dgwb_2 {
	position: absolute;
	width: var(--cursor-size, 75px);
	height: var(--cursor-size, 75px);
	pointer-events: none;
	z-index: 10000;
}

._cursorBorder_1dgwb_10 {
	position: absolute;
	width: 100%;
	height: 100%;
	background: linear-gradient(45deg, rgb(57, 182, 255), rgb(189, 69, 251));
	mask-image: url("data:image/svg+xml,%3csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%20100%20100'%20fill='none'%3e%3cg%3e%3cpath%20d='M%2015%2042%20L%2015%2036.99%20Q%2015%2031.99%2023.7%2031.99%20L%2028.05%2031.99%20Q%2032.41%2031.99%2032.41%2021.99%20L%2032.41%2017%20Q%2032.41%2012%2041.09%2016.95%20L%2076.31%2037.05%20Q%2085%2042%2076.31%2046.95%20L%2041.09%2067.05%20Q%2032.41%2072%2032.41%2062.01%20L%2032.41%2057.01%20Q%2032.41%2052.01%2023.7%2052.01%20L%2019.35%2052.01%20Q%2015%2052.01%2015%2047.01%20Z'%20fill='none'%20stroke='%23000000'%20stroke-width='6'%20stroke-miterlimit='10'%20style='stroke:%20light-dark(rgb(0,%200,%200),%20rgb(255,%20255,%20255));'/%3e%3c/g%3e%3c/svg%3e");
	mask-size: 100% 100%;
	mask-repeat: no-repeat;

	transform-origin: center;
	transform: rotate(-135deg) scale(1.2);
	margin-left: -10px;
	margin-top: -18px;
}

._cursorFilling_1dgwb_25 {
	position: absolute;
	width: 100%;
	height: 100%;
	background: url("data:image/svg+xml,%3csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%20100%20100'%3e%3cdefs%3e%3c/defs%3e%3cg%20xmlns='http://www.w3.org/2000/svg'%20style='filter:%20drop-shadow(light-dark(rgba(0,%200,%200,%200.4),%20rgba(237,%20237,%20237,%200.4))%203px%204px%204px);'%3e%3cpath%20d='M%2015%2042%20L%2015%2036.99%20Q%2015%2031.99%2023.7%2031.99%20L%2028.05%2031.99%20Q%2032.41%2031.99%2032.41%2021.99%20L%2032.41%2017%20Q%2032.41%2012%2041.09%2016.95%20L%2076.31%2037.05%20Q%2085%2042%2076.31%2046.95%20L%2041.09%2067.05%20Q%2032.41%2072%2032.41%2062.01%20L%2032.41%2057.01%20Q%2032.41%2052.01%2023.7%2052.01%20L%2019.35%2052.01%20Q%2015%2052.01%2015%2047.01%20Z'%20fill='%23ffffff'%20stroke='none'%20style='fill:%20%23ffffff;'/%3e%3c/g%3e%3c/svg%3e");
	background-size: 100% 100%;
	background-repeat: no-repeat;

	transform-origin: center;
	transform: rotate(-135deg) scale(1.2);
	margin-left: -10px;
	margin-top: -18px;
}

._cursorRipple_1dgwb_39 {
	position: absolute;
	width: 100%;
	height: 100%;
	pointer-events: none;
	margin-left: -50%;
	margin-top: -50%;

	&::after {
		content: '';
		opacity: 0;
		position: absolute;
		inset: 0;
		border: 4px solid rgba(57, 182, 255, 1);
		border-radius: 50%;
	}
}

._cursor_1dgwb_2._clicking_1dgwb_57 ._cursorRipple_1dgwb_39::after {
	animation: _cursor-ripple_1dgwb_1 300ms ease-out forwards;
}

@keyframes _cursor-ripple_1dgwb_1 {
	0% {
		transform: scale(0);
		opacity: 1;
	}
	100% {
		transform: scale(2);
		opacity: 0;
	}
}`));
            document.head.appendChild(elementStyle);
          }
        } catch (e) {
          console.error("vite-plugin-css-injected-by-js", e);
        }
      })();
      SimulatorMask_module_default = {
        wrapper: "_wrapper_1ooyb_1",
        visible: "_visible_1ooyb_11"
      };
      cursor_module_default = {
        cursor: "_cursor_1dgwb_2",
        cursorBorder: "_cursorBorder_1dgwb_10",
        cursorFilling: "_cursorFilling_1dgwb_25",
        cursorRipple: "_cursorRipple_1dgwb_39",
        clicking: "_clicking_1dgwb_57",
        "cursor-ripple": "_cursor-ripple_1dgwb_1"
      };
      SimulatorMask = (_a4 = class extends EventTarget {
        constructor() {
          super();
          __privateAdd(this, _SimulatorMask_instances);
          __publicField(this, "shown", false);
          __publicField(this, "wrapper", document.createElement("div"));
          __publicField(this, "motion", null);
          __privateAdd(this, _disposed, false);
          __privateAdd(this, _cursor, document.createElement("div"));
          __privateAdd(this, _currentCursorX, 0);
          __privateAdd(this, _currentCursorY, 0);
          __privateAdd(this, _targetCursorX, 0);
          __privateAdd(this, _targetCursorY, 0);
          this.wrapper.id = "page-agent-runtime_simulator-mask";
          this.wrapper.className = SimulatorMask_module_default.wrapper;
          this.wrapper.setAttribute("data-browser-use-ignore", "true");
          this.wrapper.setAttribute("data-page-agent-ignore", "true");
          try {
            const motion = new Motion({
              mode: isPageDark() ? "dark" : "light",
              styles: {
                position: "absolute",
                inset: "0"
              }
            });
            this.motion = motion;
            this.wrapper.appendChild(motion.element);
            motion.autoResize(this.wrapper);
          } catch (e) {
            console.warn("[SimulatorMask] Motion overlay unavailable:", e);
          }
          this.wrapper.addEventListener("click", (e) => {
            e.stopPropagation();
            e.preventDefault();
          });
          this.wrapper.addEventListener("mousedown", (e) => {
            e.stopPropagation();
            e.preventDefault();
          });
          this.wrapper.addEventListener("mouseup", (e) => {
            e.stopPropagation();
            e.preventDefault();
          });
          this.wrapper.addEventListener("mousemove", (e) => {
            e.stopPropagation();
            e.preventDefault();
          });
          this.wrapper.addEventListener("wheel", (e) => {
            e.stopPropagation();
            e.preventDefault();
          });
          this.wrapper.addEventListener("keydown", (e) => {
            e.stopPropagation();
            e.preventDefault();
          });
          this.wrapper.addEventListener("keyup", (e) => {
            e.stopPropagation();
            e.preventDefault();
          });
          __privateMethod(this, _SimulatorMask_instances, createCursor_fn).call(this);
          document.body.appendChild(this.wrapper);
          __privateMethod(this, _SimulatorMask_instances, moveCursorToTarget_fn).call(this);
          const movePointerToListener = (event) => {
            const { x, y } = event.detail;
            this.setCursorPosition(x, y);
          };
          const clickPointerListener = () => {
            this.triggerClickAnimation();
          };
          const enablePassThroughListener = () => {
            this.wrapper.style.pointerEvents = "none";
          };
          const disablePassThroughListener = () => {
            this.wrapper.style.pointerEvents = "auto";
          };
          window.addEventListener("PageAgent::MovePointerTo", movePointerToListener);
          window.addEventListener("PageAgent::ClickPointer", clickPointerListener);
          window.addEventListener("PageAgent::EnablePassThrough", enablePassThroughListener);
          window.addEventListener("PageAgent::DisablePassThrough", disablePassThroughListener);
          this.addEventListener("dispose", () => {
            window.removeEventListener("PageAgent::MovePointerTo", movePointerToListener);
            window.removeEventListener("PageAgent::ClickPointer", clickPointerListener);
            window.removeEventListener("PageAgent::EnablePassThrough", enablePassThroughListener);
            window.removeEventListener("PageAgent::DisablePassThrough", disablePassThroughListener);
          });
        }
        setCursorPosition(x, y) {
          if (__privateGet(this, _disposed)) return;
          __privateSet(this, _targetCursorX, x);
          __privateSet(this, _targetCursorY, y);
        }
        triggerClickAnimation() {
          if (__privateGet(this, _disposed)) return;
          __privateGet(this, _cursor).classList.remove(cursor_module_default.clicking);
          __privateGet(this, _cursor).offsetHeight;
          __privateGet(this, _cursor).classList.add(cursor_module_default.clicking);
        }
        show() {
          if (this.shown || __privateGet(this, _disposed)) return;
          this.shown = true;
          this.motion?.start();
          this.motion?.fadeIn();
          this.wrapper.classList.add(SimulatorMask_module_default.visible);
          __privateSet(this, _currentCursorX, window.innerWidth / 2);
          __privateSet(this, _currentCursorY, window.innerHeight / 2);
          __privateSet(this, _targetCursorX, __privateGet(this, _currentCursorX));
          __privateSet(this, _targetCursorY, __privateGet(this, _currentCursorY));
          __privateGet(this, _cursor).style.left = `${__privateGet(this, _currentCursorX)}px`;
          __privateGet(this, _cursor).style.top = `${__privateGet(this, _currentCursorY)}px`;
        }
        hide() {
          if (!this.shown || __privateGet(this, _disposed)) return;
          this.shown = false;
          this.motion?.fadeOut();
          this.motion?.pause();
          __privateGet(this, _cursor).classList.remove(cursor_module_default.clicking);
          setTimeout(() => {
            this.wrapper.classList.remove(SimulatorMask_module_default.visible);
          }, 800);
        }
        dispose() {
          __privateSet(this, _disposed, true);
          this.motion?.dispose();
          this.wrapper.remove();
          this.dispatchEvent(new Event("dispose"));
        }
      }, _disposed = new WeakMap(), _cursor = new WeakMap(), _currentCursorX = new WeakMap(), _currentCursorY = new WeakMap(), _targetCursorX = new WeakMap(), _targetCursorY = new WeakMap(), _SimulatorMask_instances = new WeakSet(), createCursor_fn = function() {
        __privateGet(this, _cursor).className = cursor_module_default.cursor;
        const rippleContainer = document.createElement("div");
        rippleContainer.className = cursor_module_default.cursorRipple;
        __privateGet(this, _cursor).appendChild(rippleContainer);
        const fillingLayer = document.createElement("div");
        fillingLayer.className = cursor_module_default.cursorFilling;
        __privateGet(this, _cursor).appendChild(fillingLayer);
        const borderLayer = document.createElement("div");
        borderLayer.className = cursor_module_default.cursorBorder;
        __privateGet(this, _cursor).appendChild(borderLayer);
        this.wrapper.appendChild(__privateGet(this, _cursor));
      }, moveCursorToTarget_fn = function() {
        if (__privateGet(this, _disposed)) return;
        const newX = __privateGet(this, _currentCursorX) + (__privateGet(this, _targetCursorX) - __privateGet(this, _currentCursorX)) * 0.2;
        const newY = __privateGet(this, _currentCursorY) + (__privateGet(this, _targetCursorY) - __privateGet(this, _currentCursorY)) * 0.2;
        const xDistance = Math.abs(newX - __privateGet(this, _targetCursorX));
        if (xDistance > 0) {
          if (xDistance < 2) __privateSet(this, _currentCursorX, __privateGet(this, _targetCursorX));
          else __privateSet(this, _currentCursorX, newX);
          __privateGet(this, _cursor).style.left = `${__privateGet(this, _currentCursorX)}px`;
        }
        const yDistance = Math.abs(newY - __privateGet(this, _targetCursorY));
        if (yDistance > 0) {
          if (yDistance < 2) __privateSet(this, _currentCursorY, __privateGet(this, _targetCursorY));
          else __privateSet(this, _currentCursorY, newY);
          __privateGet(this, _cursor).style.top = `${__privateGet(this, _currentCursorY)}px`;
        }
        requestAnimationFrame(() => __privateMethod(this, _SimulatorMask_instances, moveCursorToTarget_fn).call(this));
      }, _a4);
    }
  });

  // node_modules/page-agent/dist/esm/page-agent.js
  var page_agent_exports = {};
  __export(page_agent_exports, {
    PageAgent: () => PageAgent,
    PageAgentCore: () => PageAgentCore,
    tool: () => tool
  });

  // node_modules/zod/v4/core/core.js
  var _a;
  // @__NO_SIDE_EFFECTS__
  function $constructor(name, initializer3, params) {
    function init(inst, def) {
      if (!inst._zod) {
        Object.defineProperty(inst, "_zod", {
          value: {
            def,
            constr: _,
            traits: /* @__PURE__ */ new Set()
          },
          enumerable: false
        });
      }
      if (inst._zod.traits.has(name)) {
        return;
      }
      inst._zod.traits.add(name);
      initializer3(inst, def);
      const proto2 = _.prototype;
      const keys = Object.keys(proto2);
      for (let i = 0; i < keys.length; i++) {
        const k = keys[i];
        if (!(k in inst)) {
          inst[k] = proto2[k].bind(inst);
        }
      }
    }
    const Parent = params?.Parent ?? Object;
    class Definition extends Parent {
    }
    Object.defineProperty(Definition, "name", { value: name });
    function _(def) {
      var _a6;
      const inst = params?.Parent ? new Definition() : this;
      init(inst, def);
      (_a6 = inst._zod).deferred ?? (_a6.deferred = []);
      for (const fn of inst._zod.deferred) {
        fn();
      }
      return inst;
    }
    Object.defineProperty(_, "init", { value: init });
    Object.defineProperty(_, Symbol.hasInstance, {
      value: (inst) => {
        if (params?.Parent && inst instanceof params.Parent)
          return true;
        return inst?._zod?.traits?.has(name);
      }
    });
    Object.defineProperty(_, "name", { value: name });
    return _;
  }
  var $ZodAsyncError = class extends Error {
    constructor() {
      super(`Encountered Promise during synchronous parse. Use .parseAsync() instead.`);
    }
  };
  var $ZodEncodeError = class extends Error {
    constructor(name) {
      super(`Encountered unidirectional transform during encode: ${name}`);
      this.name = "ZodEncodeError";
    }
  };
  (_a = globalThis).__zod_globalConfig ?? (_a.__zod_globalConfig = {});
  var globalConfig = globalThis.__zod_globalConfig;
  function config(newConfig) {
    if (newConfig)
      Object.assign(globalConfig, newConfig);
    return globalConfig;
  }

  // node_modules/zod/v4/core/util.js
  var util_exports = {};
  __export(util_exports, {
    BIGINT_FORMAT_RANGES: () => BIGINT_FORMAT_RANGES,
    Class: () => Class,
    NUMBER_FORMAT_RANGES: () => NUMBER_FORMAT_RANGES,
    aborted: () => aborted,
    allowsEval: () => allowsEval,
    assert: () => assert,
    assertEqual: () => assertEqual,
    assertIs: () => assertIs,
    assertNever: () => assertNever,
    assertNotEqual: () => assertNotEqual,
    assignProp: () => assignProp,
    base64ToUint8Array: () => base64ToUint8Array,
    base64urlToUint8Array: () => base64urlToUint8Array,
    cached: () => cached,
    captureStackTrace: () => captureStackTrace,
    cleanEnum: () => cleanEnum,
    cleanRegex: () => cleanRegex,
    clone: () => clone,
    cloneDef: () => cloneDef,
    createTransparentProxy: () => createTransparentProxy,
    defineLazy: () => defineLazy,
    esc: () => esc,
    escapeRegex: () => escapeRegex,
    explicitlyAborted: () => explicitlyAborted,
    extend: () => extend,
    finalizeIssue: () => finalizeIssue,
    floatSafeRemainder: () => floatSafeRemainder,
    getElementAtPath: () => getElementAtPath,
    getEnumValues: () => getEnumValues,
    getLengthableOrigin: () => getLengthableOrigin,
    getParsedType: () => getParsedType,
    getSizableOrigin: () => getSizableOrigin,
    hexToUint8Array: () => hexToUint8Array,
    isObject: () => isObject,
    isPlainObject: () => isPlainObject,
    issue: () => issue,
    joinValues: () => joinValues,
    jsonStringifyReplacer: () => jsonStringifyReplacer,
    merge: () => merge,
    mergeDefs: () => mergeDefs,
    normalizeParams: () => normalizeParams,
    nullish: () => nullish,
    numKeys: () => numKeys,
    objectClone: () => objectClone,
    omit: () => omit,
    optionalKeys: () => optionalKeys,
    parsedType: () => parsedType,
    partial: () => partial,
    pick: () => pick,
    prefixIssues: () => prefixIssues,
    primitiveTypes: () => primitiveTypes,
    promiseAllObject: () => promiseAllObject,
    propertyKeyTypes: () => propertyKeyTypes,
    randomString: () => randomString,
    required: () => required,
    safeExtend: () => safeExtend,
    shallowClone: () => shallowClone,
    slugify: () => slugify,
    stringifyPrimitive: () => stringifyPrimitive,
    uint8ArrayToBase64: () => uint8ArrayToBase64,
    uint8ArrayToBase64url: () => uint8ArrayToBase64url,
    uint8ArrayToHex: () => uint8ArrayToHex,
    unwrapMessage: () => unwrapMessage
  });
  function assertEqual(val) {
    return val;
  }
  function assertNotEqual(val) {
    return val;
  }
  function assertIs(_arg) {
  }
  function assertNever(_x) {
    throw new Error("Unexpected value in exhaustive check");
  }
  function assert(_) {
  }
  function getEnumValues(entries) {
    const numericValues = Object.values(entries).filter((v) => typeof v === "number");
    const values = Object.entries(entries).filter(([k, _]) => numericValues.indexOf(+k) === -1).map(([_, v]) => v);
    return values;
  }
  function joinValues(array2, separator = "|") {
    return array2.map((val) => stringifyPrimitive(val)).join(separator);
  }
  function jsonStringifyReplacer(_, value) {
    if (typeof value === "bigint")
      return value.toString();
    return value;
  }
  function cached(getter) {
    const set = false;
    return {
      get value() {
        if (!set) {
          const value = getter();
          Object.defineProperty(this, "value", { value });
          return value;
        }
        throw new Error("cached value already set");
      }
    };
  }
  function nullish(input) {
    return input === null || input === void 0;
  }
  function cleanRegex(source) {
    const start = source.startsWith("^") ? 1 : 0;
    const end = source.endsWith("$") ? source.length - 1 : source.length;
    return source.slice(start, end);
  }
  function floatSafeRemainder(val, step) {
    const ratio = val / step;
    const roundedRatio = Math.round(ratio);
    const tolerance = Number.EPSILON * Math.max(Math.abs(ratio), 1);
    if (Math.abs(ratio - roundedRatio) < tolerance)
      return 0;
    return ratio - roundedRatio;
  }
  var EVALUATING = /* @__PURE__ */ Symbol("evaluating");
  function defineLazy(object2, key, getter) {
    let value = void 0;
    Object.defineProperty(object2, key, {
      get() {
        if (value === EVALUATING) {
          return void 0;
        }
        if (value === void 0) {
          value = EVALUATING;
          value = getter();
        }
        return value;
      },
      set(v) {
        Object.defineProperty(object2, key, {
          value: v
          // configurable: true,
        });
      },
      configurable: true
    });
  }
  function objectClone(obj) {
    return Object.create(Object.getPrototypeOf(obj), Object.getOwnPropertyDescriptors(obj));
  }
  function assignProp(target, prop, value) {
    Object.defineProperty(target, prop, {
      value,
      writable: true,
      enumerable: true,
      configurable: true
    });
  }
  function mergeDefs(...defs) {
    const mergedDescriptors = {};
    for (const def of defs) {
      const descriptors = Object.getOwnPropertyDescriptors(def);
      Object.assign(mergedDescriptors, descriptors);
    }
    return Object.defineProperties({}, mergedDescriptors);
  }
  function cloneDef(schema) {
    return mergeDefs(schema._zod.def);
  }
  function getElementAtPath(obj, path) {
    if (!path)
      return obj;
    return path.reduce((acc, key) => acc?.[key], obj);
  }
  function promiseAllObject(promisesObj) {
    const keys = Object.keys(promisesObj);
    const promises = keys.map((key) => promisesObj[key]);
    return Promise.all(promises).then((results) => {
      const resolvedObj = {};
      for (let i = 0; i < keys.length; i++) {
        resolvedObj[keys[i]] = results[i];
      }
      return resolvedObj;
    });
  }
  function randomString(length = 10) {
    const chars = "abcdefghijklmnopqrstuvwxyz";
    let str = "";
    for (let i = 0; i < length; i++) {
      str += chars[Math.floor(Math.random() * chars.length)];
    }
    return str;
  }
  function esc(str) {
    return JSON.stringify(str);
  }
  function slugify(input) {
    return input.toLowerCase().trim().replace(/[^\w\s-]/g, "").replace(/[\s_-]+/g, "-").replace(/^-+|-+$/g, "");
  }
  var captureStackTrace = "captureStackTrace" in Error ? Error.captureStackTrace : (..._args) => {
  };
  function isObject(data) {
    return typeof data === "object" && data !== null && !Array.isArray(data);
  }
  var allowsEval = /* @__PURE__ */ cached(() => {
    if (globalConfig.jitless) {
      return false;
    }
    if (typeof navigator !== "undefined" && navigator?.userAgent?.includes("Cloudflare")) {
      return false;
    }
    try {
      const F = Function;
      new F("");
      return true;
    } catch (_) {
      return false;
    }
  });
  function isPlainObject(o) {
    if (isObject(o) === false)
      return false;
    const ctor = o.constructor;
    if (ctor === void 0)
      return true;
    if (typeof ctor !== "function")
      return true;
    const prot = ctor.prototype;
    if (isObject(prot) === false)
      return false;
    if (Object.prototype.hasOwnProperty.call(prot, "isPrototypeOf") === false) {
      return false;
    }
    return true;
  }
  function shallowClone(o) {
    if (isPlainObject(o))
      return { ...o };
    if (Array.isArray(o))
      return [...o];
    if (o instanceof Map)
      return new Map(o);
    if (o instanceof Set)
      return new Set(o);
    return o;
  }
  function numKeys(data) {
    let keyCount = 0;
    for (const key in data) {
      if (Object.prototype.hasOwnProperty.call(data, key)) {
        keyCount++;
      }
    }
    return keyCount;
  }
  var getParsedType = (data) => {
    const t = typeof data;
    switch (t) {
      case "undefined":
        return "undefined";
      case "string":
        return "string";
      case "number":
        return Number.isNaN(data) ? "nan" : "number";
      case "boolean":
        return "boolean";
      case "function":
        return "function";
      case "bigint":
        return "bigint";
      case "symbol":
        return "symbol";
      case "object":
        if (Array.isArray(data)) {
          return "array";
        }
        if (data === null) {
          return "null";
        }
        if (data.then && typeof data.then === "function" && data.catch && typeof data.catch === "function") {
          return "promise";
        }
        if (typeof Map !== "undefined" && data instanceof Map) {
          return "map";
        }
        if (typeof Set !== "undefined" && data instanceof Set) {
          return "set";
        }
        if (typeof Date !== "undefined" && data instanceof Date) {
          return "date";
        }
        if (typeof File !== "undefined" && data instanceof File) {
          return "file";
        }
        return "object";
      default:
        throw new Error(`Unknown data type: ${t}`);
    }
  };
  var propertyKeyTypes = /* @__PURE__ */ new Set(["string", "number", "symbol"]);
  var primitiveTypes = /* @__PURE__ */ new Set([
    "string",
    "number",
    "bigint",
    "boolean",
    "symbol",
    "undefined"
  ]);
  function escapeRegex(str) {
    return str.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  }
  function clone(inst, def, params) {
    const cl = new inst._zod.constr(def ?? inst._zod.def);
    if (!def || params?.parent)
      cl._zod.parent = inst;
    return cl;
  }
  function normalizeParams(_params) {
    const params = _params;
    if (!params)
      return {};
    if (typeof params === "string")
      return { error: () => params };
    if (params?.message !== void 0) {
      if (params?.error !== void 0)
        throw new Error("Cannot specify both `message` and `error` params");
      params.error = params.message;
    }
    delete params.message;
    if (typeof params.error === "string")
      return { ...params, error: () => params.error };
    return params;
  }
  function createTransparentProxy(getter) {
    let target;
    return new Proxy({}, {
      get(_, prop, receiver) {
        target ?? (target = getter());
        return Reflect.get(target, prop, receiver);
      },
      set(_, prop, value, receiver) {
        target ?? (target = getter());
        return Reflect.set(target, prop, value, receiver);
      },
      has(_, prop) {
        target ?? (target = getter());
        return Reflect.has(target, prop);
      },
      deleteProperty(_, prop) {
        target ?? (target = getter());
        return Reflect.deleteProperty(target, prop);
      },
      ownKeys(_) {
        target ?? (target = getter());
        return Reflect.ownKeys(target);
      },
      getOwnPropertyDescriptor(_, prop) {
        target ?? (target = getter());
        return Reflect.getOwnPropertyDescriptor(target, prop);
      },
      defineProperty(_, prop, descriptor) {
        target ?? (target = getter());
        return Reflect.defineProperty(target, prop, descriptor);
      }
    });
  }
  function stringifyPrimitive(value) {
    if (typeof value === "bigint")
      return value.toString() + "n";
    if (typeof value === "string")
      return `"${value}"`;
    return `${value}`;
  }
  function optionalKeys(shape) {
    return Object.keys(shape).filter((k) => {
      return shape[k]._zod.optin === "optional" && shape[k]._zod.optout === "optional";
    });
  }
  var NUMBER_FORMAT_RANGES = {
    safeint: [Number.MIN_SAFE_INTEGER, Number.MAX_SAFE_INTEGER],
    int32: [-2147483648, 2147483647],
    uint32: [0, 4294967295],
    float32: [-34028234663852886e22, 34028234663852886e22],
    float64: [-Number.MAX_VALUE, Number.MAX_VALUE]
  };
  var BIGINT_FORMAT_RANGES = {
    int64: [/* @__PURE__ */ BigInt("-9223372036854775808"), /* @__PURE__ */ BigInt("9223372036854775807")],
    uint64: [/* @__PURE__ */ BigInt(0), /* @__PURE__ */ BigInt("18446744073709551615")]
  };
  function pick(schema, mask) {
    const currDef = schema._zod.def;
    const checks = currDef.checks;
    const hasChecks = checks && checks.length > 0;
    if (hasChecks) {
      throw new Error(".pick() cannot be used on object schemas containing refinements");
    }
    const def = mergeDefs(schema._zod.def, {
      get shape() {
        const newShape = {};
        for (const key in mask) {
          if (!(key in currDef.shape)) {
            throw new Error(`Unrecognized key: "${key}"`);
          }
          if (!mask[key])
            continue;
          newShape[key] = currDef.shape[key];
        }
        assignProp(this, "shape", newShape);
        return newShape;
      },
      checks: []
    });
    return clone(schema, def);
  }
  function omit(schema, mask) {
    const currDef = schema._zod.def;
    const checks = currDef.checks;
    const hasChecks = checks && checks.length > 0;
    if (hasChecks) {
      throw new Error(".omit() cannot be used on object schemas containing refinements");
    }
    const def = mergeDefs(schema._zod.def, {
      get shape() {
        const newShape = { ...schema._zod.def.shape };
        for (const key in mask) {
          if (!(key in currDef.shape)) {
            throw new Error(`Unrecognized key: "${key}"`);
          }
          if (!mask[key])
            continue;
          delete newShape[key];
        }
        assignProp(this, "shape", newShape);
        return newShape;
      },
      checks: []
    });
    return clone(schema, def);
  }
  function extend(schema, shape) {
    if (!isPlainObject(shape)) {
      throw new Error("Invalid input to extend: expected a plain object");
    }
    const checks = schema._zod.def.checks;
    const hasChecks = checks && checks.length > 0;
    if (hasChecks) {
      const existingShape = schema._zod.def.shape;
      for (const key in shape) {
        if (Object.getOwnPropertyDescriptor(existingShape, key) !== void 0) {
          throw new Error("Cannot overwrite keys on object schemas containing refinements. Use `.safeExtend()` instead.");
        }
      }
    }
    const def = mergeDefs(schema._zod.def, {
      get shape() {
        const _shape = { ...schema._zod.def.shape, ...shape };
        assignProp(this, "shape", _shape);
        return _shape;
      }
    });
    return clone(schema, def);
  }
  function safeExtend(schema, shape) {
    if (!isPlainObject(shape)) {
      throw new Error("Invalid input to safeExtend: expected a plain object");
    }
    const def = mergeDefs(schema._zod.def, {
      get shape() {
        const _shape = { ...schema._zod.def.shape, ...shape };
        assignProp(this, "shape", _shape);
        return _shape;
      }
    });
    return clone(schema, def);
  }
  function merge(a, b) {
    if (a._zod.def.checks?.length) {
      throw new Error(".merge() cannot be used on object schemas containing refinements. Use .safeExtend() instead.");
    }
    const def = mergeDefs(a._zod.def, {
      get shape() {
        const _shape = { ...a._zod.def.shape, ...b._zod.def.shape };
        assignProp(this, "shape", _shape);
        return _shape;
      },
      get catchall() {
        return b._zod.def.catchall;
      },
      checks: b._zod.def.checks ?? []
    });
    return clone(a, def);
  }
  function partial(Class2, schema, mask) {
    const currDef = schema._zod.def;
    const checks = currDef.checks;
    const hasChecks = checks && checks.length > 0;
    if (hasChecks) {
      throw new Error(".partial() cannot be used on object schemas containing refinements");
    }
    const def = mergeDefs(schema._zod.def, {
      get shape() {
        const oldShape = schema._zod.def.shape;
        const shape = { ...oldShape };
        if (mask) {
          for (const key in mask) {
            if (!(key in oldShape)) {
              throw new Error(`Unrecognized key: "${key}"`);
            }
            if (!mask[key])
              continue;
            shape[key] = Class2 ? new Class2({
              type: "optional",
              innerType: oldShape[key]
            }) : oldShape[key];
          }
        } else {
          for (const key in oldShape) {
            shape[key] = Class2 ? new Class2({
              type: "optional",
              innerType: oldShape[key]
            }) : oldShape[key];
          }
        }
        assignProp(this, "shape", shape);
        return shape;
      },
      checks: []
    });
    return clone(schema, def);
  }
  function required(Class2, schema, mask) {
    const def = mergeDefs(schema._zod.def, {
      get shape() {
        const oldShape = schema._zod.def.shape;
        const shape = { ...oldShape };
        if (mask) {
          for (const key in mask) {
            if (!(key in shape)) {
              throw new Error(`Unrecognized key: "${key}"`);
            }
            if (!mask[key])
              continue;
            shape[key] = new Class2({
              type: "nonoptional",
              innerType: oldShape[key]
            });
          }
        } else {
          for (const key in oldShape) {
            shape[key] = new Class2({
              type: "nonoptional",
              innerType: oldShape[key]
            });
          }
        }
        assignProp(this, "shape", shape);
        return shape;
      }
    });
    return clone(schema, def);
  }
  function aborted(x, startIndex = 0) {
    if (x.aborted === true)
      return true;
    for (let i = startIndex; i < x.issues.length; i++) {
      if (x.issues[i]?.continue !== true) {
        return true;
      }
    }
    return false;
  }
  function explicitlyAborted(x, startIndex = 0) {
    if (x.aborted === true)
      return true;
    for (let i = startIndex; i < x.issues.length; i++) {
      if (x.issues[i]?.continue === false) {
        return true;
      }
    }
    return false;
  }
  function prefixIssues(path, issues) {
    return issues.map((iss) => {
      var _a6;
      (_a6 = iss).path ?? (_a6.path = []);
      iss.path.unshift(path);
      return iss;
    });
  }
  function unwrapMessage(message) {
    return typeof message === "string" ? message : message?.message;
  }
  function finalizeIssue(iss, ctx, config2) {
    const message = iss.message ? iss.message : unwrapMessage(iss.inst?._zod.def?.error?.(iss)) ?? unwrapMessage(ctx?.error?.(iss)) ?? unwrapMessage(config2.customError?.(iss)) ?? unwrapMessage(config2.localeError?.(iss)) ?? "Invalid input";
    const { inst: _inst, continue: _continue, input: _input, ...rest } = iss;
    rest.path ?? (rest.path = []);
    rest.message = message;
    if (ctx?.reportInput) {
      rest.input = _input;
    }
    return rest;
  }
  function getSizableOrigin(input) {
    if (input instanceof Set)
      return "set";
    if (input instanceof Map)
      return "map";
    if (input instanceof File)
      return "file";
    return "unknown";
  }
  function getLengthableOrigin(input) {
    if (Array.isArray(input))
      return "array";
    if (typeof input === "string")
      return "string";
    return "unknown";
  }
  function parsedType(data) {
    const t = typeof data;
    switch (t) {
      case "number": {
        return Number.isNaN(data) ? "nan" : "number";
      }
      case "object": {
        if (data === null) {
          return "null";
        }
        if (Array.isArray(data)) {
          return "array";
        }
        const obj = data;
        if (obj && Object.getPrototypeOf(obj) !== Object.prototype && "constructor" in obj && obj.constructor) {
          return obj.constructor.name;
        }
      }
    }
    return t;
  }
  function issue(...args) {
    const [iss, input, inst] = args;
    if (typeof iss === "string") {
      return {
        message: iss,
        code: "custom",
        input,
        inst
      };
    }
    return { ...iss };
  }
  function cleanEnum(obj) {
    return Object.entries(obj).filter(([k, _]) => {
      return Number.isNaN(Number.parseInt(k, 10));
    }).map((el) => el[1]);
  }
  function base64ToUint8Array(base642) {
    const binaryString = atob(base642);
    const bytes = new Uint8Array(binaryString.length);
    for (let i = 0; i < binaryString.length; i++) {
      bytes[i] = binaryString.charCodeAt(i);
    }
    return bytes;
  }
  function uint8ArrayToBase64(bytes) {
    let binaryString = "";
    for (let i = 0; i < bytes.length; i++) {
      binaryString += String.fromCharCode(bytes[i]);
    }
    return btoa(binaryString);
  }
  function base64urlToUint8Array(base64url2) {
    const base642 = base64url2.replace(/-/g, "+").replace(/_/g, "/");
    const padding = "=".repeat((4 - base642.length % 4) % 4);
    return base64ToUint8Array(base642 + padding);
  }
  function uint8ArrayToBase64url(bytes) {
    return uint8ArrayToBase64(bytes).replace(/\+/g, "-").replace(/\//g, "_").replace(/=/g, "");
  }
  function hexToUint8Array(hex) {
    const cleanHex = hex.replace(/^0x/, "");
    if (cleanHex.length % 2 !== 0) {
      throw new Error("Invalid hex string length");
    }
    const bytes = new Uint8Array(cleanHex.length / 2);
    for (let i = 0; i < cleanHex.length; i += 2) {
      bytes[i / 2] = Number.parseInt(cleanHex.slice(i, i + 2), 16);
    }
    return bytes;
  }
  function uint8ArrayToHex(bytes) {
    return Array.from(bytes).map((b) => b.toString(16).padStart(2, "0")).join("");
  }
  var Class = class {
    constructor(..._args) {
    }
  };

  // node_modules/zod/v4/core/errors.js
  var initializer = (inst, def) => {
    inst.name = "$ZodError";
    Object.defineProperty(inst, "_zod", {
      value: inst._zod,
      enumerable: false
    });
    Object.defineProperty(inst, "issues", {
      value: def,
      enumerable: false
    });
    inst.message = JSON.stringify(def, jsonStringifyReplacer, 2);
    Object.defineProperty(inst, "toString", {
      value: () => inst.message,
      enumerable: false
    });
  };
  var $ZodError = $constructor("$ZodError", initializer);
  var $ZodRealError = $constructor("$ZodError", initializer, { Parent: Error });
  function flattenError(error2, mapper = (issue2) => issue2.message) {
    const fieldErrors = {};
    const formErrors = [];
    for (const sub of error2.issues) {
      if (sub.path.length > 0) {
        fieldErrors[sub.path[0]] = fieldErrors[sub.path[0]] || [];
        fieldErrors[sub.path[0]].push(mapper(sub));
      } else {
        formErrors.push(mapper(sub));
      }
    }
    return { formErrors, fieldErrors };
  }
  function formatError(error2, mapper = (issue2) => issue2.message) {
    const fieldErrors = { _errors: [] };
    const processError = (error3, path = []) => {
      for (const issue2 of error3.issues) {
        if (issue2.code === "invalid_union" && issue2.errors.length) {
          issue2.errors.map((issues) => processError({ issues }, [...path, ...issue2.path]));
        } else if (issue2.code === "invalid_key") {
          processError({ issues: issue2.issues }, [...path, ...issue2.path]);
        } else if (issue2.code === "invalid_element") {
          processError({ issues: issue2.issues }, [...path, ...issue2.path]);
        } else {
          const fullpath = [...path, ...issue2.path];
          if (fullpath.length === 0) {
            fieldErrors._errors.push(mapper(issue2));
          } else {
            let curr = fieldErrors;
            let i = 0;
            while (i < fullpath.length) {
              const el = fullpath[i];
              const terminal = i === fullpath.length - 1;
              if (!terminal) {
                curr[el] = curr[el] || { _errors: [] };
              } else {
                curr[el] = curr[el] || { _errors: [] };
                curr[el]._errors.push(mapper(issue2));
              }
              curr = curr[el];
              i++;
            }
          }
        }
      }
    };
    processError(error2);
    return fieldErrors;
  }
  function toDotPath(_path) {
    const segs = [];
    const path = _path.map((seg) => typeof seg === "object" ? seg.key : seg);
    for (const seg of path) {
      if (typeof seg === "number")
        segs.push(`[${seg}]`);
      else if (typeof seg === "symbol")
        segs.push(`[${JSON.stringify(String(seg))}]`);
      else if (/[^\w$]/.test(seg))
        segs.push(`[${JSON.stringify(seg)}]`);
      else {
        if (segs.length)
          segs.push(".");
        segs.push(seg);
      }
    }
    return segs.join("");
  }
  function prettifyError(error2) {
    const lines = [];
    const issues = [...error2.issues].sort((a, b) => (a.path ?? []).length - (b.path ?? []).length);
    for (const issue2 of issues) {
      lines.push(`\u2716 ${issue2.message}`);
      if (issue2.path?.length)
        lines.push(`  \u2192 at ${toDotPath(issue2.path)}`);
    }
    return lines.join("\n");
  }

  // node_modules/zod/v4/core/parse.js
  var _parse = (_Err) => (schema, value, _ctx, _params) => {
    const ctx = _ctx ? { ..._ctx, async: false } : { async: false };
    const result2 = schema._zod.run({ value, issues: [] }, ctx);
    if (result2 instanceof Promise) {
      throw new $ZodAsyncError();
    }
    if (result2.issues.length) {
      const e = new (_params?.Err ?? _Err)(result2.issues.map((iss) => finalizeIssue(iss, ctx, config())));
      captureStackTrace(e, _params?.callee);
      throw e;
    }
    return result2.value;
  };
  var _parseAsync = (_Err) => async (schema, value, _ctx, params) => {
    const ctx = _ctx ? { ..._ctx, async: true } : { async: true };
    let result2 = schema._zod.run({ value, issues: [] }, ctx);
    if (result2 instanceof Promise)
      result2 = await result2;
    if (result2.issues.length) {
      const e = new (params?.Err ?? _Err)(result2.issues.map((iss) => finalizeIssue(iss, ctx, config())));
      captureStackTrace(e, params?.callee);
      throw e;
    }
    return result2.value;
  };
  var _safeParse = (_Err) => (schema, value, _ctx) => {
    const ctx = _ctx ? { ..._ctx, async: false } : { async: false };
    const result2 = schema._zod.run({ value, issues: [] }, ctx);
    if (result2 instanceof Promise) {
      throw new $ZodAsyncError();
    }
    return result2.issues.length ? {
      success: false,
      error: new (_Err ?? $ZodError)(result2.issues.map((iss) => finalizeIssue(iss, ctx, config())))
    } : { success: true, data: result2.value };
  };
  var safeParse = /* @__PURE__ */ _safeParse($ZodRealError);
  var _safeParseAsync = (_Err) => async (schema, value, _ctx) => {
    const ctx = _ctx ? { ..._ctx, async: true } : { async: true };
    let result2 = schema._zod.run({ value, issues: [] }, ctx);
    if (result2 instanceof Promise)
      result2 = await result2;
    return result2.issues.length ? {
      success: false,
      error: new _Err(result2.issues.map((iss) => finalizeIssue(iss, ctx, config())))
    } : { success: true, data: result2.value };
  };
  var safeParseAsync = /* @__PURE__ */ _safeParseAsync($ZodRealError);
  var _encode = (_Err) => (schema, value, _ctx) => {
    const ctx = _ctx ? { ..._ctx, direction: "backward" } : { direction: "backward" };
    return _parse(_Err)(schema, value, ctx);
  };
  var _decode = (_Err) => (schema, value, _ctx) => {
    return _parse(_Err)(schema, value, _ctx);
  };
  var _encodeAsync = (_Err) => async (schema, value, _ctx) => {
    const ctx = _ctx ? { ..._ctx, direction: "backward" } : { direction: "backward" };
    return _parseAsync(_Err)(schema, value, ctx);
  };
  var _decodeAsync = (_Err) => async (schema, value, _ctx) => {
    return _parseAsync(_Err)(schema, value, _ctx);
  };
  var _safeEncode = (_Err) => (schema, value, _ctx) => {
    const ctx = _ctx ? { ..._ctx, direction: "backward" } : { direction: "backward" };
    return _safeParse(_Err)(schema, value, ctx);
  };
  var _safeDecode = (_Err) => (schema, value, _ctx) => {
    return _safeParse(_Err)(schema, value, _ctx);
  };
  var _safeEncodeAsync = (_Err) => async (schema, value, _ctx) => {
    const ctx = _ctx ? { ..._ctx, direction: "backward" } : { direction: "backward" };
    return _safeParseAsync(_Err)(schema, value, ctx);
  };
  var _safeDecodeAsync = (_Err) => async (schema, value, _ctx) => {
    return _safeParseAsync(_Err)(schema, value, _ctx);
  };

  // node_modules/zod/v4/core/regexes.js
  var cuid = /^[cC][0-9a-z]{6,}$/;
  var cuid2 = /^[0-9a-z]+$/;
  var ulid = /^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/;
  var xid = /^[0-9a-vA-V]{20}$/;
  var ksuid = /^[A-Za-z0-9]{27}$/;
  var nanoid = /^[a-zA-Z0-9_-]{21}$/;
  var duration = /^P(?:(\d+W)|(?!.*W)(?=\d|T\d)(\d+Y)?(\d+M)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+([.,]\d+)?S)?)?)$/;
  var guid = /^([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})$/;
  var uuid = (version2) => {
    if (!version2)
      return /^([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}|00000000-0000-0000-0000-000000000000|ffffffff-ffff-ffff-ffff-ffffffffffff)$/;
    return new RegExp(`^([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-${version2}[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12})$`);
  };
  var email = /^(?!\.)(?!.*\.\.)([A-Za-z0-9_'+\-\.]*)[A-Za-z0-9_+-]@([A-Za-z0-9][A-Za-z0-9\-]*\.)+[A-Za-z]{2,}$/;
  var _emoji = `^(\\p{Extended_Pictographic}|\\p{Emoji_Component})+$`;
  function emoji() {
    return new RegExp(_emoji, "u");
  }
  var ipv4 = /^(?:(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])\.){3}(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])$/;
  var ipv6 = /^(([0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,7}:|([0-9a-fA-F]{1,4}:){1,6}:[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,5}(:[0-9a-fA-F]{1,4}){1,2}|([0-9a-fA-F]{1,4}:){1,4}(:[0-9a-fA-F]{1,4}){1,3}|([0-9a-fA-F]{1,4}:){1,3}(:[0-9a-fA-F]{1,4}){1,4}|([0-9a-fA-F]{1,4}:){1,2}(:[0-9a-fA-F]{1,4}){1,5}|[0-9a-fA-F]{1,4}:((:[0-9a-fA-F]{1,4}){1,6})|:((:[0-9a-fA-F]{1,4}){1,7}|:))$/;
  var cidrv4 = /^((25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])\.){3}(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])\/([0-9]|[1-2][0-9]|3[0-2])$/;
  var cidrv6 = /^(([0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}|::|([0-9a-fA-F]{1,4})?::([0-9a-fA-F]{1,4}:?){0,6})\/(12[0-8]|1[01][0-9]|[1-9]?[0-9])$/;
  var base64 = /^$|^(?:[0-9a-zA-Z+/]{4})*(?:(?:[0-9a-zA-Z+/]{2}==)|(?:[0-9a-zA-Z+/]{3}=))?$/;
  var base64url = /^[A-Za-z0-9_-]*$/;
  var httpProtocol = /^https?$/;
  var e164 = /^\+[1-9]\d{6,14}$/;
  var dateSource = `(?:(?:\\d\\d[2468][048]|\\d\\d[13579][26]|\\d\\d0[48]|[02468][048]00|[13579][26]00)-02-29|\\d{4}-(?:(?:0[13578]|1[02])-(?:0[1-9]|[12]\\d|3[01])|(?:0[469]|11)-(?:0[1-9]|[12]\\d|30)|(?:02)-(?:0[1-9]|1\\d|2[0-8])))`;
  var date = /* @__PURE__ */ new RegExp(`^${dateSource}$`);
  function timeSource(args) {
    const hhmm = `(?:[01]\\d|2[0-3]):[0-5]\\d`;
    const regex = typeof args.precision === "number" ? args.precision === -1 ? `${hhmm}` : args.precision === 0 ? `${hhmm}:[0-5]\\d` : `${hhmm}:[0-5]\\d\\.\\d{${args.precision}}` : `${hhmm}(?::[0-5]\\d(?:\\.\\d+)?)?`;
    return regex;
  }
  function time(args) {
    return new RegExp(`^${timeSource(args)}$`);
  }
  function datetime(args) {
    const time3 = timeSource({ precision: args.precision });
    const opts = ["Z"];
    if (args.local)
      opts.push("");
    if (args.offset)
      opts.push(`([+-](?:[01]\\d|2[0-3]):[0-5]\\d)`);
    const timeRegex = `${time3}(?:${opts.join("|")})`;
    return new RegExp(`^${dateSource}T(?:${timeRegex})$`);
  }
  var string = (params) => {
    const regex = params ? `[\\s\\S]{${params?.minimum ?? 0},${params?.maximum ?? ""}}` : `[\\s\\S]*`;
    return new RegExp(`^${regex}$`);
  };
  var integer = /^-?\d+$/;
  var number = /^-?\d+(?:\.\d+)?$/;
  var boolean = /^(?:true|false)$/i;
  var lowercase = /^[^A-Z]*$/;
  var uppercase = /^[^a-z]*$/;

  // node_modules/zod/v4/core/checks.js
  var $ZodCheck = /* @__PURE__ */ $constructor("$ZodCheck", (inst, def) => {
    var _a6;
    inst._zod ?? (inst._zod = {});
    inst._zod.def = def;
    (_a6 = inst._zod).onattach ?? (_a6.onattach = []);
  });
  var numericOriginMap = {
    number: "number",
    bigint: "bigint",
    object: "date"
  };
  var $ZodCheckLessThan = /* @__PURE__ */ $constructor("$ZodCheckLessThan", (inst, def) => {
    $ZodCheck.init(inst, def);
    const origin = numericOriginMap[typeof def.value];
    inst._zod.onattach.push((inst2) => {
      const bag = inst2._zod.bag;
      const curr = (def.inclusive ? bag.maximum : bag.exclusiveMaximum) ?? Number.POSITIVE_INFINITY;
      if (def.value < curr) {
        if (def.inclusive)
          bag.maximum = def.value;
        else
          bag.exclusiveMaximum = def.value;
      }
    });
    inst._zod.check = (payload) => {
      if (def.inclusive ? payload.value <= def.value : payload.value < def.value) {
        return;
      }
      payload.issues.push({
        origin,
        code: "too_big",
        maximum: typeof def.value === "object" ? def.value.getTime() : def.value,
        input: payload.value,
        inclusive: def.inclusive,
        inst,
        continue: !def.abort
      });
    };
  });
  var $ZodCheckGreaterThan = /* @__PURE__ */ $constructor("$ZodCheckGreaterThan", (inst, def) => {
    $ZodCheck.init(inst, def);
    const origin = numericOriginMap[typeof def.value];
    inst._zod.onattach.push((inst2) => {
      const bag = inst2._zod.bag;
      const curr = (def.inclusive ? bag.minimum : bag.exclusiveMinimum) ?? Number.NEGATIVE_INFINITY;
      if (def.value > curr) {
        if (def.inclusive)
          bag.minimum = def.value;
        else
          bag.exclusiveMinimum = def.value;
      }
    });
    inst._zod.check = (payload) => {
      if (def.inclusive ? payload.value >= def.value : payload.value > def.value) {
        return;
      }
      payload.issues.push({
        origin,
        code: "too_small",
        minimum: typeof def.value === "object" ? def.value.getTime() : def.value,
        input: payload.value,
        inclusive: def.inclusive,
        inst,
        continue: !def.abort
      });
    };
  });
  var $ZodCheckMultipleOf = /* @__PURE__ */ $constructor("$ZodCheckMultipleOf", (inst, def) => {
    $ZodCheck.init(inst, def);
    inst._zod.onattach.push((inst2) => {
      var _a6;
      (_a6 = inst2._zod.bag).multipleOf ?? (_a6.multipleOf = def.value);
    });
    inst._zod.check = (payload) => {
      if (typeof payload.value !== typeof def.value)
        throw new Error("Cannot mix number and bigint in multiple_of check.");
      const isMultiple = typeof payload.value === "bigint" ? payload.value % def.value === BigInt(0) : floatSafeRemainder(payload.value, def.value) === 0;
      if (isMultiple)
        return;
      payload.issues.push({
        origin: typeof payload.value,
        code: "not_multiple_of",
        divisor: def.value,
        input: payload.value,
        inst,
        continue: !def.abort
      });
    };
  });
  var $ZodCheckNumberFormat = /* @__PURE__ */ $constructor("$ZodCheckNumberFormat", (inst, def) => {
    $ZodCheck.init(inst, def);
    def.format = def.format || "float64";
    const isInt = def.format?.includes("int");
    const origin = isInt ? "int" : "number";
    const [minimum, maximum] = NUMBER_FORMAT_RANGES[def.format];
    inst._zod.onattach.push((inst2) => {
      const bag = inst2._zod.bag;
      bag.format = def.format;
      bag.minimum = minimum;
      bag.maximum = maximum;
      if (isInt)
        bag.pattern = integer;
    });
    inst._zod.check = (payload) => {
      const input = payload.value;
      if (isInt) {
        if (!Number.isInteger(input)) {
          payload.issues.push({
            expected: origin,
            format: def.format,
            code: "invalid_type",
            continue: false,
            input,
            inst
          });
          return;
        }
        if (!Number.isSafeInteger(input)) {
          if (input > 0) {
            payload.issues.push({
              input,
              code: "too_big",
              maximum: Number.MAX_SAFE_INTEGER,
              note: "Integers must be within the safe integer range.",
              inst,
              origin,
              inclusive: true,
              continue: !def.abort
            });
          } else {
            payload.issues.push({
              input,
              code: "too_small",
              minimum: Number.MIN_SAFE_INTEGER,
              note: "Integers must be within the safe integer range.",
              inst,
              origin,
              inclusive: true,
              continue: !def.abort
            });
          }
          return;
        }
      }
      if (input < minimum) {
        payload.issues.push({
          origin: "number",
          input,
          code: "too_small",
          minimum,
          inclusive: true,
          inst,
          continue: !def.abort
        });
      }
      if (input > maximum) {
        payload.issues.push({
          origin: "number",
          input,
          code: "too_big",
          maximum,
          inclusive: true,
          inst,
          continue: !def.abort
        });
      }
    };
  });
  var $ZodCheckMaxLength = /* @__PURE__ */ $constructor("$ZodCheckMaxLength", (inst, def) => {
    var _a6;
    $ZodCheck.init(inst, def);
    (_a6 = inst._zod.def).when ?? (_a6.when = (payload) => {
      const val = payload.value;
      return !nullish(val) && val.length !== void 0;
    });
    inst._zod.onattach.push((inst2) => {
      const curr = inst2._zod.bag.maximum ?? Number.POSITIVE_INFINITY;
      if (def.maximum < curr)
        inst2._zod.bag.maximum = def.maximum;
    });
    inst._zod.check = (payload) => {
      const input = payload.value;
      const length = input.length;
      if (length <= def.maximum)
        return;
      const origin = getLengthableOrigin(input);
      payload.issues.push({
        origin,
        code: "too_big",
        maximum: def.maximum,
        inclusive: true,
        input,
        inst,
        continue: !def.abort
      });
    };
  });
  var $ZodCheckMinLength = /* @__PURE__ */ $constructor("$ZodCheckMinLength", (inst, def) => {
    var _a6;
    $ZodCheck.init(inst, def);
    (_a6 = inst._zod.def).when ?? (_a6.when = (payload) => {
      const val = payload.value;
      return !nullish(val) && val.length !== void 0;
    });
    inst._zod.onattach.push((inst2) => {
      const curr = inst2._zod.bag.minimum ?? Number.NEGATIVE_INFINITY;
      if (def.minimum > curr)
        inst2._zod.bag.minimum = def.minimum;
    });
    inst._zod.check = (payload) => {
      const input = payload.value;
      const length = input.length;
      if (length >= def.minimum)
        return;
      const origin = getLengthableOrigin(input);
      payload.issues.push({
        origin,
        code: "too_small",
        minimum: def.minimum,
        inclusive: true,
        input,
        inst,
        continue: !def.abort
      });
    };
  });
  var $ZodCheckLengthEquals = /* @__PURE__ */ $constructor("$ZodCheckLengthEquals", (inst, def) => {
    var _a6;
    $ZodCheck.init(inst, def);
    (_a6 = inst._zod.def).when ?? (_a6.when = (payload) => {
      const val = payload.value;
      return !nullish(val) && val.length !== void 0;
    });
    inst._zod.onattach.push((inst2) => {
      const bag = inst2._zod.bag;
      bag.minimum = def.length;
      bag.maximum = def.length;
      bag.length = def.length;
    });
    inst._zod.check = (payload) => {
      const input = payload.value;
      const length = input.length;
      if (length === def.length)
        return;
      const origin = getLengthableOrigin(input);
      const tooBig = length > def.length;
      payload.issues.push({
        origin,
        ...tooBig ? { code: "too_big", maximum: def.length } : { code: "too_small", minimum: def.length },
        inclusive: true,
        exact: true,
        input: payload.value,
        inst,
        continue: !def.abort
      });
    };
  });
  var $ZodCheckStringFormat = /* @__PURE__ */ $constructor("$ZodCheckStringFormat", (inst, def) => {
    var _a6, _b;
    $ZodCheck.init(inst, def);
    inst._zod.onattach.push((inst2) => {
      const bag = inst2._zod.bag;
      bag.format = def.format;
      if (def.pattern) {
        bag.patterns ?? (bag.patterns = /* @__PURE__ */ new Set());
        bag.patterns.add(def.pattern);
      }
    });
    if (def.pattern)
      (_a6 = inst._zod).check ?? (_a6.check = (payload) => {
        def.pattern.lastIndex = 0;
        if (def.pattern.test(payload.value))
          return;
        payload.issues.push({
          origin: "string",
          code: "invalid_format",
          format: def.format,
          input: payload.value,
          ...def.pattern ? { pattern: def.pattern.toString() } : {},
          inst,
          continue: !def.abort
        });
      });
    else
      (_b = inst._zod).check ?? (_b.check = () => {
      });
  });
  var $ZodCheckRegex = /* @__PURE__ */ $constructor("$ZodCheckRegex", (inst, def) => {
    $ZodCheckStringFormat.init(inst, def);
    inst._zod.check = (payload) => {
      def.pattern.lastIndex = 0;
      if (def.pattern.test(payload.value))
        return;
      payload.issues.push({
        origin: "string",
        code: "invalid_format",
        format: "regex",
        input: payload.value,
        pattern: def.pattern.toString(),
        inst,
        continue: !def.abort
      });
    };
  });
  var $ZodCheckLowerCase = /* @__PURE__ */ $constructor("$ZodCheckLowerCase", (inst, def) => {
    def.pattern ?? (def.pattern = lowercase);
    $ZodCheckStringFormat.init(inst, def);
  });
  var $ZodCheckUpperCase = /* @__PURE__ */ $constructor("$ZodCheckUpperCase", (inst, def) => {
    def.pattern ?? (def.pattern = uppercase);
    $ZodCheckStringFormat.init(inst, def);
  });
  var $ZodCheckIncludes = /* @__PURE__ */ $constructor("$ZodCheckIncludes", (inst, def) => {
    $ZodCheck.init(inst, def);
    const escapedRegex = escapeRegex(def.includes);
    const pattern = new RegExp(typeof def.position === "number" ? `^.{${def.position}}${escapedRegex}` : escapedRegex);
    def.pattern = pattern;
    inst._zod.onattach.push((inst2) => {
      const bag = inst2._zod.bag;
      bag.patterns ?? (bag.patterns = /* @__PURE__ */ new Set());
      bag.patterns.add(pattern);
    });
    inst._zod.check = (payload) => {
      if (payload.value.includes(def.includes, def.position))
        return;
      payload.issues.push({
        origin: "string",
        code: "invalid_format",
        format: "includes",
        includes: def.includes,
        input: payload.value,
        inst,
        continue: !def.abort
      });
    };
  });
  var $ZodCheckStartsWith = /* @__PURE__ */ $constructor("$ZodCheckStartsWith", (inst, def) => {
    $ZodCheck.init(inst, def);
    const pattern = new RegExp(`^${escapeRegex(def.prefix)}.*`);
    def.pattern ?? (def.pattern = pattern);
    inst._zod.onattach.push((inst2) => {
      const bag = inst2._zod.bag;
      bag.patterns ?? (bag.patterns = /* @__PURE__ */ new Set());
      bag.patterns.add(pattern);
    });
    inst._zod.check = (payload) => {
      if (payload.value.startsWith(def.prefix))
        return;
      payload.issues.push({
        origin: "string",
        code: "invalid_format",
        format: "starts_with",
        prefix: def.prefix,
        input: payload.value,
        inst,
        continue: !def.abort
      });
    };
  });
  var $ZodCheckEndsWith = /* @__PURE__ */ $constructor("$ZodCheckEndsWith", (inst, def) => {
    $ZodCheck.init(inst, def);
    const pattern = new RegExp(`.*${escapeRegex(def.suffix)}$`);
    def.pattern ?? (def.pattern = pattern);
    inst._zod.onattach.push((inst2) => {
      const bag = inst2._zod.bag;
      bag.patterns ?? (bag.patterns = /* @__PURE__ */ new Set());
      bag.patterns.add(pattern);
    });
    inst._zod.check = (payload) => {
      if (payload.value.endsWith(def.suffix))
        return;
      payload.issues.push({
        origin: "string",
        code: "invalid_format",
        format: "ends_with",
        suffix: def.suffix,
        input: payload.value,
        inst,
        continue: !def.abort
      });
    };
  });
  var $ZodCheckOverwrite = /* @__PURE__ */ $constructor("$ZodCheckOverwrite", (inst, def) => {
    $ZodCheck.init(inst, def);
    inst._zod.check = (payload) => {
      payload.value = def.tx(payload.value);
    };
  });

  // node_modules/zod/v4/core/doc.js
  var Doc = class {
    constructor(args = []) {
      this.content = [];
      this.indent = 0;
      if (this)
        this.args = args;
    }
    indented(fn) {
      this.indent += 1;
      fn(this);
      this.indent -= 1;
    }
    write(arg) {
      if (typeof arg === "function") {
        arg(this, { execution: "sync" });
        arg(this, { execution: "async" });
        return;
      }
      const content = arg;
      const lines = content.split("\n").filter((x) => x);
      const minIndent = Math.min(...lines.map((x) => x.length - x.trimStart().length));
      const dedented = lines.map((x) => x.slice(minIndent)).map((x) => " ".repeat(this.indent * 2) + x);
      for (const line of dedented) {
        this.content.push(line);
      }
    }
    compile() {
      const F = Function;
      const args = this?.args;
      const content = this?.content ?? [``];
      const lines = [...content.map((x) => `  ${x}`)];
      return new F(...args, lines.join("\n"));
    }
  };

  // node_modules/zod/v4/core/versions.js
  var version = {
    major: 4,
    minor: 4,
    patch: 3
  };

  // node_modules/zod/v4/core/schemas.js
  var $ZodType = /* @__PURE__ */ $constructor("$ZodType", (inst, def) => {
    var _a6;
    inst ?? (inst = {});
    inst._zod.def = def;
    inst._zod.bag = inst._zod.bag || {};
    inst._zod.version = version;
    const checks = [...inst._zod.def.checks ?? []];
    if (inst._zod.traits.has("$ZodCheck")) {
      checks.unshift(inst);
    }
    for (const ch of checks) {
      for (const fn of ch._zod.onattach) {
        fn(inst);
      }
    }
    if (checks.length === 0) {
      (_a6 = inst._zod).deferred ?? (_a6.deferred = []);
      inst._zod.deferred?.push(() => {
        inst._zod.run = inst._zod.parse;
      });
    } else {
      const runChecks = (payload, checks2, ctx) => {
        let isAborted = aborted(payload);
        let asyncResult;
        for (const ch of checks2) {
          if (ch._zod.def.when) {
            if (explicitlyAborted(payload))
              continue;
            const shouldRun = ch._zod.def.when(payload);
            if (!shouldRun)
              continue;
          } else if (isAborted) {
            continue;
          }
          const currLen = payload.issues.length;
          const _ = ch._zod.check(payload);
          if (_ instanceof Promise && ctx?.async === false) {
            throw new $ZodAsyncError();
          }
          if (asyncResult || _ instanceof Promise) {
            asyncResult = (asyncResult ?? Promise.resolve()).then(async () => {
              await _;
              const nextLen = payload.issues.length;
              if (nextLen === currLen)
                return;
              if (!isAborted)
                isAborted = aborted(payload, currLen);
            });
          } else {
            const nextLen = payload.issues.length;
            if (nextLen === currLen)
              continue;
            if (!isAborted)
              isAborted = aborted(payload, currLen);
          }
        }
        if (asyncResult) {
          return asyncResult.then(() => {
            return payload;
          });
        }
        return payload;
      };
      const handleCanaryResult = (canary, payload, ctx) => {
        if (aborted(canary)) {
          canary.aborted = true;
          return canary;
        }
        const checkResult = runChecks(payload, checks, ctx);
        if (checkResult instanceof Promise) {
          if (ctx.async === false)
            throw new $ZodAsyncError();
          return checkResult.then((checkResult2) => inst._zod.parse(checkResult2, ctx));
        }
        return inst._zod.parse(checkResult, ctx);
      };
      inst._zod.run = (payload, ctx) => {
        if (ctx.skipChecks) {
          return inst._zod.parse(payload, ctx);
        }
        if (ctx.direction === "backward") {
          const canary = inst._zod.parse({ value: payload.value, issues: [] }, { ...ctx, skipChecks: true });
          if (canary instanceof Promise) {
            return canary.then((canary2) => {
              return handleCanaryResult(canary2, payload, ctx);
            });
          }
          return handleCanaryResult(canary, payload, ctx);
        }
        const result2 = inst._zod.parse(payload, ctx);
        if (result2 instanceof Promise) {
          if (ctx.async === false)
            throw new $ZodAsyncError();
          return result2.then((result3) => runChecks(result3, checks, ctx));
        }
        return runChecks(result2, checks, ctx);
      };
    }
    defineLazy(inst, "~standard", () => ({
      validate: (value) => {
        try {
          const r = safeParse(inst, value);
          return r.success ? { value: r.data } : { issues: r.error?.issues };
        } catch (_) {
          return safeParseAsync(inst, value).then((r) => r.success ? { value: r.data } : { issues: r.error?.issues });
        }
      },
      vendor: "zod",
      version: 1
    }));
  });
  var $ZodString = /* @__PURE__ */ $constructor("$ZodString", (inst, def) => {
    $ZodType.init(inst, def);
    inst._zod.pattern = [...inst?._zod.bag?.patterns ?? []].pop() ?? string(inst._zod.bag);
    inst._zod.parse = (payload, _) => {
      if (def.coerce)
        try {
          payload.value = String(payload.value);
        } catch (_2) {
        }
      if (typeof payload.value === "string")
        return payload;
      payload.issues.push({
        expected: "string",
        code: "invalid_type",
        input: payload.value,
        inst
      });
      return payload;
    };
  });
  var $ZodStringFormat = /* @__PURE__ */ $constructor("$ZodStringFormat", (inst, def) => {
    $ZodCheckStringFormat.init(inst, def);
    $ZodString.init(inst, def);
  });
  var $ZodGUID = /* @__PURE__ */ $constructor("$ZodGUID", (inst, def) => {
    def.pattern ?? (def.pattern = guid);
    $ZodStringFormat.init(inst, def);
  });
  var $ZodUUID = /* @__PURE__ */ $constructor("$ZodUUID", (inst, def) => {
    if (def.version) {
      const versionMap = {
        v1: 1,
        v2: 2,
        v3: 3,
        v4: 4,
        v5: 5,
        v6: 6,
        v7: 7,
        v8: 8
      };
      const v = versionMap[def.version];
      if (v === void 0)
        throw new Error(`Invalid UUID version: "${def.version}"`);
      def.pattern ?? (def.pattern = uuid(v));
    } else
      def.pattern ?? (def.pattern = uuid());
    $ZodStringFormat.init(inst, def);
  });
  var $ZodEmail = /* @__PURE__ */ $constructor("$ZodEmail", (inst, def) => {
    def.pattern ?? (def.pattern = email);
    $ZodStringFormat.init(inst, def);
  });
  var $ZodURL = /* @__PURE__ */ $constructor("$ZodURL", (inst, def) => {
    $ZodStringFormat.init(inst, def);
    inst._zod.check = (payload) => {
      try {
        const trimmed = payload.value.trim();
        if (!def.normalize && def.protocol?.source === httpProtocol.source) {
          if (!/^https?:\/\//i.test(trimmed)) {
            payload.issues.push({
              code: "invalid_format",
              format: "url",
              note: "Invalid URL format",
              input: payload.value,
              inst,
              continue: !def.abort
            });
            return;
          }
        }
        const url = new URL(trimmed);
        if (def.hostname) {
          def.hostname.lastIndex = 0;
          if (!def.hostname.test(url.hostname)) {
            payload.issues.push({
              code: "invalid_format",
              format: "url",
              note: "Invalid hostname",
              pattern: def.hostname.source,
              input: payload.value,
              inst,
              continue: !def.abort
            });
          }
        }
        if (def.protocol) {
          def.protocol.lastIndex = 0;
          if (!def.protocol.test(url.protocol.endsWith(":") ? url.protocol.slice(0, -1) : url.protocol)) {
            payload.issues.push({
              code: "invalid_format",
              format: "url",
              note: "Invalid protocol",
              pattern: def.protocol.source,
              input: payload.value,
              inst,
              continue: !def.abort
            });
          }
        }
        if (def.normalize) {
          payload.value = url.href;
        } else {
          payload.value = trimmed;
        }
        return;
      } catch (_) {
        payload.issues.push({
          code: "invalid_format",
          format: "url",
          input: payload.value,
          inst,
          continue: !def.abort
        });
      }
    };
  });
  var $ZodEmoji = /* @__PURE__ */ $constructor("$ZodEmoji", (inst, def) => {
    def.pattern ?? (def.pattern = emoji());
    $ZodStringFormat.init(inst, def);
  });
  var $ZodNanoID = /* @__PURE__ */ $constructor("$ZodNanoID", (inst, def) => {
    def.pattern ?? (def.pattern = nanoid);
    $ZodStringFormat.init(inst, def);
  });
  var $ZodCUID = /* @__PURE__ */ $constructor("$ZodCUID", (inst, def) => {
    def.pattern ?? (def.pattern = cuid);
    $ZodStringFormat.init(inst, def);
  });
  var $ZodCUID2 = /* @__PURE__ */ $constructor("$ZodCUID2", (inst, def) => {
    def.pattern ?? (def.pattern = cuid2);
    $ZodStringFormat.init(inst, def);
  });
  var $ZodULID = /* @__PURE__ */ $constructor("$ZodULID", (inst, def) => {
    def.pattern ?? (def.pattern = ulid);
    $ZodStringFormat.init(inst, def);
  });
  var $ZodXID = /* @__PURE__ */ $constructor("$ZodXID", (inst, def) => {
    def.pattern ?? (def.pattern = xid);
    $ZodStringFormat.init(inst, def);
  });
  var $ZodKSUID = /* @__PURE__ */ $constructor("$ZodKSUID", (inst, def) => {
    def.pattern ?? (def.pattern = ksuid);
    $ZodStringFormat.init(inst, def);
  });
  var $ZodISODateTime = /* @__PURE__ */ $constructor("$ZodISODateTime", (inst, def) => {
    def.pattern ?? (def.pattern = datetime(def));
    $ZodStringFormat.init(inst, def);
  });
  var $ZodISODate = /* @__PURE__ */ $constructor("$ZodISODate", (inst, def) => {
    def.pattern ?? (def.pattern = date);
    $ZodStringFormat.init(inst, def);
  });
  var $ZodISOTime = /* @__PURE__ */ $constructor("$ZodISOTime", (inst, def) => {
    def.pattern ?? (def.pattern = time(def));
    $ZodStringFormat.init(inst, def);
  });
  var $ZodISODuration = /* @__PURE__ */ $constructor("$ZodISODuration", (inst, def) => {
    def.pattern ?? (def.pattern = duration);
    $ZodStringFormat.init(inst, def);
  });
  var $ZodIPv4 = /* @__PURE__ */ $constructor("$ZodIPv4", (inst, def) => {
    def.pattern ?? (def.pattern = ipv4);
    $ZodStringFormat.init(inst, def);
    inst._zod.bag.format = `ipv4`;
  });
  var $ZodIPv6 = /* @__PURE__ */ $constructor("$ZodIPv6", (inst, def) => {
    def.pattern ?? (def.pattern = ipv6);
    $ZodStringFormat.init(inst, def);
    inst._zod.bag.format = `ipv6`;
    inst._zod.check = (payload) => {
      try {
        new URL(`http://[${payload.value}]`);
      } catch {
        payload.issues.push({
          code: "invalid_format",
          format: "ipv6",
          input: payload.value,
          inst,
          continue: !def.abort
        });
      }
    };
  });
  var $ZodCIDRv4 = /* @__PURE__ */ $constructor("$ZodCIDRv4", (inst, def) => {
    def.pattern ?? (def.pattern = cidrv4);
    $ZodStringFormat.init(inst, def);
  });
  var $ZodCIDRv6 = /* @__PURE__ */ $constructor("$ZodCIDRv6", (inst, def) => {
    def.pattern ?? (def.pattern = cidrv6);
    $ZodStringFormat.init(inst, def);
    inst._zod.check = (payload) => {
      const parts = payload.value.split("/");
      try {
        if (parts.length !== 2)
          throw new Error();
        const [address, prefix] = parts;
        if (!prefix)
          throw new Error();
        const prefixNum = Number(prefix);
        if (`${prefixNum}` !== prefix)
          throw new Error();
        if (prefixNum < 0 || prefixNum > 128)
          throw new Error();
        new URL(`http://[${address}]`);
      } catch {
        payload.issues.push({
          code: "invalid_format",
          format: "cidrv6",
          input: payload.value,
          inst,
          continue: !def.abort
        });
      }
    };
  });
  function isValidBase64(data) {
    if (data === "")
      return true;
    if (/\s/.test(data))
      return false;
    if (data.length % 4 !== 0)
      return false;
    try {
      atob(data);
      return true;
    } catch {
      return false;
    }
  }
  var $ZodBase64 = /* @__PURE__ */ $constructor("$ZodBase64", (inst, def) => {
    def.pattern ?? (def.pattern = base64);
    $ZodStringFormat.init(inst, def);
    inst._zod.bag.contentEncoding = "base64";
    inst._zod.check = (payload) => {
      if (isValidBase64(payload.value))
        return;
      payload.issues.push({
        code: "invalid_format",
        format: "base64",
        input: payload.value,
        inst,
        continue: !def.abort
      });
    };
  });
  function isValidBase64URL(data) {
    if (!base64url.test(data))
      return false;
    const base642 = data.replace(/[-_]/g, (c) => c === "-" ? "+" : "/");
    const padded = base642.padEnd(Math.ceil(base642.length / 4) * 4, "=");
    return isValidBase64(padded);
  }
  var $ZodBase64URL = /* @__PURE__ */ $constructor("$ZodBase64URL", (inst, def) => {
    def.pattern ?? (def.pattern = base64url);
    $ZodStringFormat.init(inst, def);
    inst._zod.bag.contentEncoding = "base64url";
    inst._zod.check = (payload) => {
      if (isValidBase64URL(payload.value))
        return;
      payload.issues.push({
        code: "invalid_format",
        format: "base64url",
        input: payload.value,
        inst,
        continue: !def.abort
      });
    };
  });
  var $ZodE164 = /* @__PURE__ */ $constructor("$ZodE164", (inst, def) => {
    def.pattern ?? (def.pattern = e164);
    $ZodStringFormat.init(inst, def);
  });
  function isValidJWT(token, algorithm = null) {
    try {
      const tokensParts = token.split(".");
      if (tokensParts.length !== 3)
        return false;
      const [header] = tokensParts;
      if (!header)
        return false;
      const parsedHeader = JSON.parse(atob(header));
      if ("typ" in parsedHeader && parsedHeader?.typ !== "JWT")
        return false;
      if (!parsedHeader.alg)
        return false;
      if (algorithm && (!("alg" in parsedHeader) || parsedHeader.alg !== algorithm))
        return false;
      return true;
    } catch {
      return false;
    }
  }
  var $ZodJWT = /* @__PURE__ */ $constructor("$ZodJWT", (inst, def) => {
    $ZodStringFormat.init(inst, def);
    inst._zod.check = (payload) => {
      if (isValidJWT(payload.value, def.alg))
        return;
      payload.issues.push({
        code: "invalid_format",
        format: "jwt",
        input: payload.value,
        inst,
        continue: !def.abort
      });
    };
  });
  var $ZodNumber = /* @__PURE__ */ $constructor("$ZodNumber", (inst, def) => {
    $ZodType.init(inst, def);
    inst._zod.pattern = inst._zod.bag.pattern ?? number;
    inst._zod.parse = (payload, _ctx) => {
      if (def.coerce)
        try {
          payload.value = Number(payload.value);
        } catch (_) {
        }
      const input = payload.value;
      if (typeof input === "number" && !Number.isNaN(input) && Number.isFinite(input)) {
        return payload;
      }
      const received = typeof input === "number" ? Number.isNaN(input) ? "NaN" : !Number.isFinite(input) ? "Infinity" : void 0 : void 0;
      payload.issues.push({
        expected: "number",
        code: "invalid_type",
        input,
        inst,
        ...received ? { received } : {}
      });
      return payload;
    };
  });
  var $ZodNumberFormat = /* @__PURE__ */ $constructor("$ZodNumberFormat", (inst, def) => {
    $ZodCheckNumberFormat.init(inst, def);
    $ZodNumber.init(inst, def);
  });
  var $ZodBoolean = /* @__PURE__ */ $constructor("$ZodBoolean", (inst, def) => {
    $ZodType.init(inst, def);
    inst._zod.pattern = boolean;
    inst._zod.parse = (payload, _ctx) => {
      if (def.coerce)
        try {
          payload.value = Boolean(payload.value);
        } catch (_) {
        }
      const input = payload.value;
      if (typeof input === "boolean")
        return payload;
      payload.issues.push({
        expected: "boolean",
        code: "invalid_type",
        input,
        inst
      });
      return payload;
    };
  });
  var $ZodUnknown = /* @__PURE__ */ $constructor("$ZodUnknown", (inst, def) => {
    $ZodType.init(inst, def);
    inst._zod.parse = (payload) => payload;
  });
  var $ZodNever = /* @__PURE__ */ $constructor("$ZodNever", (inst, def) => {
    $ZodType.init(inst, def);
    inst._zod.parse = (payload, _ctx) => {
      payload.issues.push({
        expected: "never",
        code: "invalid_type",
        input: payload.value,
        inst
      });
      return payload;
    };
  });
  function handleArrayResult(result2, final, index) {
    if (result2.issues.length) {
      final.issues.push(...prefixIssues(index, result2.issues));
    }
    final.value[index] = result2.value;
  }
  var $ZodArray = /* @__PURE__ */ $constructor("$ZodArray", (inst, def) => {
    $ZodType.init(inst, def);
    inst._zod.parse = (payload, ctx) => {
      const input = payload.value;
      if (!Array.isArray(input)) {
        payload.issues.push({
          expected: "array",
          code: "invalid_type",
          input,
          inst
        });
        return payload;
      }
      payload.value = Array(input.length);
      const proms = [];
      for (let i = 0; i < input.length; i++) {
        const item = input[i];
        const result2 = def.element._zod.run({
          value: item,
          issues: []
        }, ctx);
        if (result2 instanceof Promise) {
          proms.push(result2.then((result3) => handleArrayResult(result3, payload, i)));
        } else {
          handleArrayResult(result2, payload, i);
        }
      }
      if (proms.length) {
        return Promise.all(proms).then(() => payload);
      }
      return payload;
    };
  });
  function handlePropertyResult(result2, final, key, input, isOptionalIn, isOptionalOut) {
    const isPresent = key in input;
    if (result2.issues.length) {
      if (isOptionalIn && isOptionalOut && !isPresent) {
        return;
      }
      final.issues.push(...prefixIssues(key, result2.issues));
    }
    if (!isPresent && !isOptionalIn) {
      if (!result2.issues.length) {
        final.issues.push({
          code: "invalid_type",
          expected: "nonoptional",
          input: void 0,
          path: [key]
        });
      }
      return;
    }
    if (result2.value === void 0) {
      if (isPresent) {
        final.value[key] = void 0;
      }
    } else {
      final.value[key] = result2.value;
    }
  }
  function normalizeDef(def) {
    const keys = Object.keys(def.shape);
    for (const k of keys) {
      if (!def.shape?.[k]?._zod?.traits?.has("$ZodType")) {
        throw new Error(`Invalid element at key "${k}": expected a Zod schema`);
      }
    }
    const okeys = optionalKeys(def.shape);
    return {
      ...def,
      keys,
      keySet: new Set(keys),
      numKeys: keys.length,
      optionalKeys: new Set(okeys)
    };
  }
  function handleCatchall(proms, input, payload, ctx, def, inst) {
    const unrecognized = [];
    const keySet = def.keySet;
    const _catchall = def.catchall._zod;
    const t = _catchall.def.type;
    const isOptionalIn = _catchall.optin === "optional";
    const isOptionalOut = _catchall.optout === "optional";
    for (const key in input) {
      if (key === "__proto__")
        continue;
      if (keySet.has(key))
        continue;
      if (t === "never") {
        unrecognized.push(key);
        continue;
      }
      const r = _catchall.run({ value: input[key], issues: [] }, ctx);
      if (r instanceof Promise) {
        proms.push(r.then((r2) => handlePropertyResult(r2, payload, key, input, isOptionalIn, isOptionalOut)));
      } else {
        handlePropertyResult(r, payload, key, input, isOptionalIn, isOptionalOut);
      }
    }
    if (unrecognized.length) {
      payload.issues.push({
        code: "unrecognized_keys",
        keys: unrecognized,
        input,
        inst
      });
    }
    if (!proms.length)
      return payload;
    return Promise.all(proms).then(() => {
      return payload;
    });
  }
  var $ZodObject = /* @__PURE__ */ $constructor("$ZodObject", (inst, def) => {
    $ZodType.init(inst, def);
    const desc = Object.getOwnPropertyDescriptor(def, "shape");
    if (!desc?.get) {
      const sh = def.shape;
      Object.defineProperty(def, "shape", {
        get: () => {
          const newSh = { ...sh };
          Object.defineProperty(def, "shape", {
            value: newSh
          });
          return newSh;
        }
      });
    }
    const _normalized = cached(() => normalizeDef(def));
    defineLazy(inst._zod, "propValues", () => {
      const shape = def.shape;
      const propValues = {};
      for (const key in shape) {
        const field = shape[key]._zod;
        if (field.values) {
          propValues[key] ?? (propValues[key] = /* @__PURE__ */ new Set());
          for (const v of field.values)
            propValues[key].add(v);
        }
      }
      return propValues;
    });
    const isObject2 = isObject;
    const catchall = def.catchall;
    let value;
    inst._zod.parse = (payload, ctx) => {
      value ?? (value = _normalized.value);
      const input = payload.value;
      if (!isObject2(input)) {
        payload.issues.push({
          expected: "object",
          code: "invalid_type",
          input,
          inst
        });
        return payload;
      }
      payload.value = {};
      const proms = [];
      const shape = value.shape;
      for (const key of value.keys) {
        const el = shape[key];
        const isOptionalIn = el._zod.optin === "optional";
        const isOptionalOut = el._zod.optout === "optional";
        const r = el._zod.run({ value: input[key], issues: [] }, ctx);
        if (r instanceof Promise) {
          proms.push(r.then((r2) => handlePropertyResult(r2, payload, key, input, isOptionalIn, isOptionalOut)));
        } else {
          handlePropertyResult(r, payload, key, input, isOptionalIn, isOptionalOut);
        }
      }
      if (!catchall) {
        return proms.length ? Promise.all(proms).then(() => payload) : payload;
      }
      return handleCatchall(proms, input, payload, ctx, _normalized.value, inst);
    };
  });
  var $ZodObjectJIT = /* @__PURE__ */ $constructor("$ZodObjectJIT", (inst, def) => {
    $ZodObject.init(inst, def);
    const superParse = inst._zod.parse;
    const _normalized = cached(() => normalizeDef(def));
    const generateFastpass = (shape) => {
      const doc = new Doc(["shape", "payload", "ctx"]);
      const normalized = _normalized.value;
      const parseStr = (key) => {
        const k = esc(key);
        return `shape[${k}]._zod.run({ value: input[${k}], issues: [] }, ctx)`;
      };
      doc.write(`const input = payload.value;`);
      const ids2 = /* @__PURE__ */ Object.create(null);
      let counter = 0;
      for (const key of normalized.keys) {
        ids2[key] = `key_${counter++}`;
      }
      doc.write(`const newResult = {};`);
      for (const key of normalized.keys) {
        const id = ids2[key];
        const k = esc(key);
        const schema = shape[key];
        const isOptionalIn = schema?._zod?.optin === "optional";
        const isOptionalOut = schema?._zod?.optout === "optional";
        doc.write(`const ${id} = ${parseStr(key)};`);
        if (isOptionalIn && isOptionalOut) {
          doc.write(`
        if (${id}.issues.length) {
          if (${k} in input) {
            payload.issues = payload.issues.concat(${id}.issues.map(iss => ({
              ...iss,
              path: iss.path ? [${k}, ...iss.path] : [${k}]
            })));
          }
        }
        
        if (${id}.value === undefined) {
          if (${k} in input) {
            newResult[${k}] = undefined;
          }
        } else {
          newResult[${k}] = ${id}.value;
        }
        
      `);
        } else if (!isOptionalIn) {
          doc.write(`
        const ${id}_present = ${k} in input;
        if (${id}.issues.length) {
          payload.issues = payload.issues.concat(${id}.issues.map(iss => ({
            ...iss,
            path: iss.path ? [${k}, ...iss.path] : [${k}]
          })));
        }
        if (!${id}_present && !${id}.issues.length) {
          payload.issues.push({
            code: "invalid_type",
            expected: "nonoptional",
            input: undefined,
            path: [${k}]
          });
        }

        if (${id}_present) {
          if (${id}.value === undefined) {
            newResult[${k}] = undefined;
          } else {
            newResult[${k}] = ${id}.value;
          }
        }

      `);
        } else {
          doc.write(`
        if (${id}.issues.length) {
          payload.issues = payload.issues.concat(${id}.issues.map(iss => ({
            ...iss,
            path: iss.path ? [${k}, ...iss.path] : [${k}]
          })));
        }
        
        if (${id}.value === undefined) {
          if (${k} in input) {
            newResult[${k}] = undefined;
          }
        } else {
          newResult[${k}] = ${id}.value;
        }
        
      `);
        }
      }
      doc.write(`payload.value = newResult;`);
      doc.write(`return payload;`);
      const fn = doc.compile();
      return (payload, ctx) => fn(shape, payload, ctx);
    };
    let fastpass;
    const isObject2 = isObject;
    const jit = !globalConfig.jitless;
    const allowsEval2 = allowsEval;
    const fastEnabled = jit && allowsEval2.value;
    const catchall = def.catchall;
    let value;
    inst._zod.parse = (payload, ctx) => {
      value ?? (value = _normalized.value);
      const input = payload.value;
      if (!isObject2(input)) {
        payload.issues.push({
          expected: "object",
          code: "invalid_type",
          input,
          inst
        });
        return payload;
      }
      if (jit && fastEnabled && ctx?.async === false && ctx.jitless !== true) {
        if (!fastpass)
          fastpass = generateFastpass(def.shape);
        payload = fastpass(payload, ctx);
        if (!catchall)
          return payload;
        return handleCatchall([], input, payload, ctx, value, inst);
      }
      return superParse(payload, ctx);
    };
  });
  function handleUnionResults(results, final, inst, ctx) {
    for (const result2 of results) {
      if (result2.issues.length === 0) {
        final.value = result2.value;
        return final;
      }
    }
    const nonaborted = results.filter((r) => !aborted(r));
    if (nonaborted.length === 1) {
      final.value = nonaborted[0].value;
      return nonaborted[0];
    }
    final.issues.push({
      code: "invalid_union",
      input: final.value,
      inst,
      errors: results.map((result2) => result2.issues.map((iss) => finalizeIssue(iss, ctx, config())))
    });
    return final;
  }
  var $ZodUnion = /* @__PURE__ */ $constructor("$ZodUnion", (inst, def) => {
    $ZodType.init(inst, def);
    defineLazy(inst._zod, "optin", () => def.options.some((o) => o._zod.optin === "optional") ? "optional" : void 0);
    defineLazy(inst._zod, "optout", () => def.options.some((o) => o._zod.optout === "optional") ? "optional" : void 0);
    defineLazy(inst._zod, "values", () => {
      if (def.options.every((o) => o._zod.values)) {
        return new Set(def.options.flatMap((option) => Array.from(option._zod.values)));
      }
      return void 0;
    });
    defineLazy(inst._zod, "pattern", () => {
      if (def.options.every((o) => o._zod.pattern)) {
        const patterns = def.options.map((o) => o._zod.pattern);
        return new RegExp(`^(${patterns.map((p) => cleanRegex(p.source)).join("|")})$`);
      }
      return void 0;
    });
    const first = def.options.length === 1 ? def.options[0]._zod.run : null;
    inst._zod.parse = (payload, ctx) => {
      if (first) {
        return first(payload, ctx);
      }
      let async = false;
      const results = [];
      for (const option of def.options) {
        const result2 = option._zod.run({
          value: payload.value,
          issues: []
        }, ctx);
        if (result2 instanceof Promise) {
          results.push(result2);
          async = true;
        } else {
          if (result2.issues.length === 0)
            return result2;
          results.push(result2);
        }
      }
      if (!async)
        return handleUnionResults(results, payload, inst, ctx);
      return Promise.all(results).then((results2) => {
        return handleUnionResults(results2, payload, inst, ctx);
      });
    };
  });
  var $ZodIntersection = /* @__PURE__ */ $constructor("$ZodIntersection", (inst, def) => {
    $ZodType.init(inst, def);
    inst._zod.parse = (payload, ctx) => {
      const input = payload.value;
      const left = def.left._zod.run({ value: input, issues: [] }, ctx);
      const right = def.right._zod.run({ value: input, issues: [] }, ctx);
      const async = left instanceof Promise || right instanceof Promise;
      if (async) {
        return Promise.all([left, right]).then(([left2, right2]) => {
          return handleIntersectionResults(payload, left2, right2);
        });
      }
      return handleIntersectionResults(payload, left, right);
    };
  });
  function mergeValues(a, b) {
    if (a === b) {
      return { valid: true, data: a };
    }
    if (a instanceof Date && b instanceof Date && +a === +b) {
      return { valid: true, data: a };
    }
    if (isPlainObject(a) && isPlainObject(b)) {
      const bKeys = Object.keys(b);
      const sharedKeys = Object.keys(a).filter((key) => bKeys.indexOf(key) !== -1);
      const newObj = { ...a, ...b };
      for (const key of sharedKeys) {
        const sharedValue = mergeValues(a[key], b[key]);
        if (!sharedValue.valid) {
          return {
            valid: false,
            mergeErrorPath: [key, ...sharedValue.mergeErrorPath]
          };
        }
        newObj[key] = sharedValue.data;
      }
      return { valid: true, data: newObj };
    }
    if (Array.isArray(a) && Array.isArray(b)) {
      if (a.length !== b.length) {
        return { valid: false, mergeErrorPath: [] };
      }
      const newArray = [];
      for (let index = 0; index < a.length; index++) {
        const itemA = a[index];
        const itemB = b[index];
        const sharedValue = mergeValues(itemA, itemB);
        if (!sharedValue.valid) {
          return {
            valid: false,
            mergeErrorPath: [index, ...sharedValue.mergeErrorPath]
          };
        }
        newArray.push(sharedValue.data);
      }
      return { valid: true, data: newArray };
    }
    return { valid: false, mergeErrorPath: [] };
  }
  function handleIntersectionResults(result2, left, right) {
    const unrecKeys = /* @__PURE__ */ new Map();
    let unrecIssue;
    for (const iss of left.issues) {
      if (iss.code === "unrecognized_keys") {
        unrecIssue ?? (unrecIssue = iss);
        for (const k of iss.keys) {
          if (!unrecKeys.has(k))
            unrecKeys.set(k, {});
          unrecKeys.get(k).l = true;
        }
      } else {
        result2.issues.push(iss);
      }
    }
    for (const iss of right.issues) {
      if (iss.code === "unrecognized_keys") {
        for (const k of iss.keys) {
          if (!unrecKeys.has(k))
            unrecKeys.set(k, {});
          unrecKeys.get(k).r = true;
        }
      } else {
        result2.issues.push(iss);
      }
    }
    const bothKeys = [...unrecKeys].filter(([, f]) => f.l && f.r).map(([k]) => k);
    if (bothKeys.length && unrecIssue) {
      result2.issues.push({ ...unrecIssue, keys: bothKeys });
    }
    if (aborted(result2))
      return result2;
    const merged = mergeValues(left.value, right.value);
    if (!merged.valid) {
      throw new Error(`Unmergable intersection. Error path: ${JSON.stringify(merged.mergeErrorPath)}`);
    }
    result2.value = merged.data;
    return result2;
  }
  var $ZodEnum = /* @__PURE__ */ $constructor("$ZodEnum", (inst, def) => {
    $ZodType.init(inst, def);
    const values = getEnumValues(def.entries);
    const valuesSet = new Set(values);
    inst._zod.values = valuesSet;
    inst._zod.pattern = new RegExp(`^(${values.filter((k) => propertyKeyTypes.has(typeof k)).map((o) => typeof o === "string" ? escapeRegex(o) : o.toString()).join("|")})$`);
    inst._zod.parse = (payload, _ctx) => {
      const input = payload.value;
      if (valuesSet.has(input)) {
        return payload;
      }
      payload.issues.push({
        code: "invalid_value",
        values,
        input,
        inst
      });
      return payload;
    };
  });
  var $ZodTransform = /* @__PURE__ */ $constructor("$ZodTransform", (inst, def) => {
    $ZodType.init(inst, def);
    inst._zod.optin = "optional";
    inst._zod.parse = (payload, ctx) => {
      if (ctx.direction === "backward") {
        throw new $ZodEncodeError(inst.constructor.name);
      }
      const _out = def.transform(payload.value, payload);
      if (ctx.async) {
        const output = _out instanceof Promise ? _out : Promise.resolve(_out);
        return output.then((output2) => {
          payload.value = output2;
          payload.fallback = true;
          return payload;
        });
      }
      if (_out instanceof Promise) {
        throw new $ZodAsyncError();
      }
      payload.value = _out;
      payload.fallback = true;
      return payload;
    };
  });
  function handleOptionalResult(result2, input) {
    if (input === void 0 && (result2.issues.length || result2.fallback)) {
      return { issues: [], value: void 0 };
    }
    return result2;
  }
  var $ZodOptional = /* @__PURE__ */ $constructor("$ZodOptional", (inst, def) => {
    $ZodType.init(inst, def);
    inst._zod.optin = "optional";
    inst._zod.optout = "optional";
    defineLazy(inst._zod, "values", () => {
      return def.innerType._zod.values ? /* @__PURE__ */ new Set([...def.innerType._zod.values, void 0]) : void 0;
    });
    defineLazy(inst._zod, "pattern", () => {
      const pattern = def.innerType._zod.pattern;
      return pattern ? new RegExp(`^(${cleanRegex(pattern.source)})?$`) : void 0;
    });
    inst._zod.parse = (payload, ctx) => {
      if (def.innerType._zod.optin === "optional") {
        const input = payload.value;
        const result2 = def.innerType._zod.run(payload, ctx);
        if (result2 instanceof Promise)
          return result2.then((r) => handleOptionalResult(r, input));
        return handleOptionalResult(result2, input);
      }
      if (payload.value === void 0) {
        return payload;
      }
      return def.innerType._zod.run(payload, ctx);
    };
  });
  var $ZodExactOptional = /* @__PURE__ */ $constructor("$ZodExactOptional", (inst, def) => {
    $ZodOptional.init(inst, def);
    defineLazy(inst._zod, "values", () => def.innerType._zod.values);
    defineLazy(inst._zod, "pattern", () => def.innerType._zod.pattern);
    inst._zod.parse = (payload, ctx) => {
      return def.innerType._zod.run(payload, ctx);
    };
  });
  var $ZodNullable = /* @__PURE__ */ $constructor("$ZodNullable", (inst, def) => {
    $ZodType.init(inst, def);
    defineLazy(inst._zod, "optin", () => def.innerType._zod.optin);
    defineLazy(inst._zod, "optout", () => def.innerType._zod.optout);
    defineLazy(inst._zod, "pattern", () => {
      const pattern = def.innerType._zod.pattern;
      return pattern ? new RegExp(`^(${cleanRegex(pattern.source)}|null)$`) : void 0;
    });
    defineLazy(inst._zod, "values", () => {
      return def.innerType._zod.values ? /* @__PURE__ */ new Set([...def.innerType._zod.values, null]) : void 0;
    });
    inst._zod.parse = (payload, ctx) => {
      if (payload.value === null)
        return payload;
      return def.innerType._zod.run(payload, ctx);
    };
  });
  var $ZodDefault = /* @__PURE__ */ $constructor("$ZodDefault", (inst, def) => {
    $ZodType.init(inst, def);
    inst._zod.optin = "optional";
    defineLazy(inst._zod, "values", () => def.innerType._zod.values);
    inst._zod.parse = (payload, ctx) => {
      if (ctx.direction === "backward") {
        return def.innerType._zod.run(payload, ctx);
      }
      if (payload.value === void 0) {
        payload.value = def.defaultValue;
        return payload;
      }
      const result2 = def.innerType._zod.run(payload, ctx);
      if (result2 instanceof Promise) {
        return result2.then((result3) => handleDefaultResult(result3, def));
      }
      return handleDefaultResult(result2, def);
    };
  });
  function handleDefaultResult(payload, def) {
    if (payload.value === void 0) {
      payload.value = def.defaultValue;
    }
    return payload;
  }
  var $ZodPrefault = /* @__PURE__ */ $constructor("$ZodPrefault", (inst, def) => {
    $ZodType.init(inst, def);
    inst._zod.optin = "optional";
    defineLazy(inst._zod, "values", () => def.innerType._zod.values);
    inst._zod.parse = (payload, ctx) => {
      if (ctx.direction === "backward") {
        return def.innerType._zod.run(payload, ctx);
      }
      if (payload.value === void 0) {
        payload.value = def.defaultValue;
      }
      return def.innerType._zod.run(payload, ctx);
    };
  });
  var $ZodNonOptional = /* @__PURE__ */ $constructor("$ZodNonOptional", (inst, def) => {
    $ZodType.init(inst, def);
    defineLazy(inst._zod, "values", () => {
      const v = def.innerType._zod.values;
      return v ? new Set([...v].filter((x) => x !== void 0)) : void 0;
    });
    inst._zod.parse = (payload, ctx) => {
      const result2 = def.innerType._zod.run(payload, ctx);
      if (result2 instanceof Promise) {
        return result2.then((result3) => handleNonOptionalResult(result3, inst));
      }
      return handleNonOptionalResult(result2, inst);
    };
  });
  function handleNonOptionalResult(payload, inst) {
    if (!payload.issues.length && payload.value === void 0) {
      payload.issues.push({
        code: "invalid_type",
        expected: "nonoptional",
        input: payload.value,
        inst
      });
    }
    return payload;
  }
  var $ZodCatch = /* @__PURE__ */ $constructor("$ZodCatch", (inst, def) => {
    $ZodType.init(inst, def);
    inst._zod.optin = "optional";
    defineLazy(inst._zod, "optout", () => def.innerType._zod.optout);
    defineLazy(inst._zod, "values", () => def.innerType._zod.values);
    inst._zod.parse = (payload, ctx) => {
      if (ctx.direction === "backward") {
        return def.innerType._zod.run(payload, ctx);
      }
      const result2 = def.innerType._zod.run(payload, ctx);
      if (result2 instanceof Promise) {
        return result2.then((result3) => {
          payload.value = result3.value;
          if (result3.issues.length) {
            payload.value = def.catchValue({
              ...payload,
              error: {
                issues: result3.issues.map((iss) => finalizeIssue(iss, ctx, config()))
              },
              input: payload.value
            });
            payload.issues = [];
            payload.fallback = true;
          }
          return payload;
        });
      }
      payload.value = result2.value;
      if (result2.issues.length) {
        payload.value = def.catchValue({
          ...payload,
          error: {
            issues: result2.issues.map((iss) => finalizeIssue(iss, ctx, config()))
          },
          input: payload.value
        });
        payload.issues = [];
        payload.fallback = true;
      }
      return payload;
    };
  });
  var $ZodPipe = /* @__PURE__ */ $constructor("$ZodPipe", (inst, def) => {
    $ZodType.init(inst, def);
    defineLazy(inst._zod, "values", () => def.in._zod.values);
    defineLazy(inst._zod, "optin", () => def.in._zod.optin);
    defineLazy(inst._zod, "optout", () => def.out._zod.optout);
    defineLazy(inst._zod, "propValues", () => def.in._zod.propValues);
    inst._zod.parse = (payload, ctx) => {
      if (ctx.direction === "backward") {
        const right = def.out._zod.run(payload, ctx);
        if (right instanceof Promise) {
          return right.then((right2) => handlePipeResult(right2, def.in, ctx));
        }
        return handlePipeResult(right, def.in, ctx);
      }
      const left = def.in._zod.run(payload, ctx);
      if (left instanceof Promise) {
        return left.then((left2) => handlePipeResult(left2, def.out, ctx));
      }
      return handlePipeResult(left, def.out, ctx);
    };
  });
  function handlePipeResult(left, next, ctx) {
    if (left.issues.length) {
      left.aborted = true;
      return left;
    }
    return next._zod.run({ value: left.value, issues: left.issues, fallback: left.fallback }, ctx);
  }
  var $ZodReadonly = /* @__PURE__ */ $constructor("$ZodReadonly", (inst, def) => {
    $ZodType.init(inst, def);
    defineLazy(inst._zod, "propValues", () => def.innerType._zod.propValues);
    defineLazy(inst._zod, "values", () => def.innerType._zod.values);
    defineLazy(inst._zod, "optin", () => def.innerType?._zod?.optin);
    defineLazy(inst._zod, "optout", () => def.innerType?._zod?.optout);
    inst._zod.parse = (payload, ctx) => {
      if (ctx.direction === "backward") {
        return def.innerType._zod.run(payload, ctx);
      }
      const result2 = def.innerType._zod.run(payload, ctx);
      if (result2 instanceof Promise) {
        return result2.then(handleReadonlyResult);
      }
      return handleReadonlyResult(result2);
    };
  });
  function handleReadonlyResult(payload) {
    payload.value = Object.freeze(payload.value);
    return payload;
  }
  var $ZodCustom = /* @__PURE__ */ $constructor("$ZodCustom", (inst, def) => {
    $ZodCheck.init(inst, def);
    $ZodType.init(inst, def);
    inst._zod.parse = (payload, _) => {
      return payload;
    };
    inst._zod.check = (payload) => {
      const input = payload.value;
      const r = def.fn(input);
      if (r instanceof Promise) {
        return r.then((r2) => handleRefineResult(r2, payload, input, inst));
      }
      handleRefineResult(r, payload, input, inst);
      return;
    };
  });
  function handleRefineResult(result2, payload, input, inst) {
    if (!result2) {
      const _iss = {
        code: "custom",
        input,
        inst,
        // incorporates params.error into issue reporting
        path: [...inst._zod.def.path ?? []],
        // incorporates params.error into issue reporting
        continue: !inst._zod.def.abort
        // params: inst._zod.def.params,
      };
      if (inst._zod.def.params)
        _iss.params = inst._zod.def.params;
      payload.issues.push(issue(_iss));
    }
  }

  // node_modules/zod/v4/locales/en.js
  var error = () => {
    const Sizable = {
      string: { unit: "characters", verb: "to have" },
      file: { unit: "bytes", verb: "to have" },
      array: { unit: "items", verb: "to have" },
      set: { unit: "items", verb: "to have" },
      map: { unit: "entries", verb: "to have" }
    };
    function getSizing(origin) {
      return Sizable[origin] ?? null;
    }
    const FormatDictionary = {
      regex: "input",
      email: "email address",
      url: "URL",
      emoji: "emoji",
      uuid: "UUID",
      uuidv4: "UUIDv4",
      uuidv6: "UUIDv6",
      nanoid: "nanoid",
      guid: "GUID",
      cuid: "cuid",
      cuid2: "cuid2",
      ulid: "ULID",
      xid: "XID",
      ksuid: "KSUID",
      datetime: "ISO datetime",
      date: "ISO date",
      time: "ISO time",
      duration: "ISO duration",
      ipv4: "IPv4 address",
      ipv6: "IPv6 address",
      mac: "MAC address",
      cidrv4: "IPv4 range",
      cidrv6: "IPv6 range",
      base64: "base64-encoded string",
      base64url: "base64url-encoded string",
      json_string: "JSON string",
      e164: "E.164 number",
      jwt: "JWT",
      template_literal: "input"
    };
    const TypeDictionary = {
      // Compatibility: "nan" -> "NaN" for display
      nan: "NaN"
      // All other type names omitted - they fall back to raw values via ?? operator
    };
    return (issue2) => {
      switch (issue2.code) {
        case "invalid_type": {
          const expected = TypeDictionary[issue2.expected] ?? issue2.expected;
          const receivedType = parsedType(issue2.input);
          const received = TypeDictionary[receivedType] ?? receivedType;
          return `Invalid input: expected ${expected}, received ${received}`;
        }
        case "invalid_value":
          if (issue2.values.length === 1)
            return `Invalid input: expected ${stringifyPrimitive(issue2.values[0])}`;
          return `Invalid option: expected one of ${joinValues(issue2.values, "|")}`;
        case "too_big": {
          const adj = issue2.inclusive ? "<=" : "<";
          const sizing = getSizing(issue2.origin);
          if (sizing)
            return `Too big: expected ${issue2.origin ?? "value"} to have ${adj}${issue2.maximum.toString()} ${sizing.unit ?? "elements"}`;
          return `Too big: expected ${issue2.origin ?? "value"} to be ${adj}${issue2.maximum.toString()}`;
        }
        case "too_small": {
          const adj = issue2.inclusive ? ">=" : ">";
          const sizing = getSizing(issue2.origin);
          if (sizing) {
            return `Too small: expected ${issue2.origin} to have ${adj}${issue2.minimum.toString()} ${sizing.unit}`;
          }
          return `Too small: expected ${issue2.origin} to be ${adj}${issue2.minimum.toString()}`;
        }
        case "invalid_format": {
          const _issue = issue2;
          if (_issue.format === "starts_with") {
            return `Invalid string: must start with "${_issue.prefix}"`;
          }
          if (_issue.format === "ends_with")
            return `Invalid string: must end with "${_issue.suffix}"`;
          if (_issue.format === "includes")
            return `Invalid string: must include "${_issue.includes}"`;
          if (_issue.format === "regex")
            return `Invalid string: must match pattern ${_issue.pattern}`;
          return `Invalid ${FormatDictionary[_issue.format] ?? issue2.format}`;
        }
        case "not_multiple_of":
          return `Invalid number: must be a multiple of ${issue2.divisor}`;
        case "unrecognized_keys":
          return `Unrecognized key${issue2.keys.length > 1 ? "s" : ""}: ${joinValues(issue2.keys, ", ")}`;
        case "invalid_key":
          return `Invalid key in ${issue2.origin}`;
        case "invalid_union":
          if (issue2.options && Array.isArray(issue2.options) && issue2.options.length > 0) {
            const opts = issue2.options.map((o) => `'${o}'`).join(" | ");
            return `Invalid discriminator value. Expected ${opts}`;
          }
          return "Invalid input";
        case "invalid_element":
          return `Invalid value in ${issue2.origin}`;
        default:
          return `Invalid input`;
      }
    };
  };
  function en_default() {
    return {
      localeError: error()
    };
  }

  // node_modules/zod/v4/core/registries.js
  var _a2;
  var $ZodRegistry = class {
    constructor() {
      this._map = /* @__PURE__ */ new WeakMap();
      this._idmap = /* @__PURE__ */ new Map();
    }
    add(schema, ..._meta) {
      const meta2 = _meta[0];
      this._map.set(schema, meta2);
      if (meta2 && typeof meta2 === "object" && "id" in meta2) {
        this._idmap.set(meta2.id, schema);
      }
      return this;
    }
    clear() {
      this._map = /* @__PURE__ */ new WeakMap();
      this._idmap = /* @__PURE__ */ new Map();
      return this;
    }
    remove(schema) {
      const meta2 = this._map.get(schema);
      if (meta2 && typeof meta2 === "object" && "id" in meta2) {
        this._idmap.delete(meta2.id);
      }
      this._map.delete(schema);
      return this;
    }
    get(schema) {
      const p = schema._zod.parent;
      if (p) {
        const pm = { ...this.get(p) ?? {} };
        delete pm.id;
        const f = { ...pm, ...this._map.get(schema) };
        return Object.keys(f).length ? f : void 0;
      }
      return this._map.get(schema);
    }
    has(schema) {
      return this._map.has(schema);
    }
  };
  function registry() {
    return new $ZodRegistry();
  }
  (_a2 = globalThis).__zod_globalRegistry ?? (_a2.__zod_globalRegistry = registry());
  var globalRegistry = globalThis.__zod_globalRegistry;

  // node_modules/zod/v4/core/api.js
  // @__NO_SIDE_EFFECTS__
  function _string(Class2, params) {
    return new Class2({
      type: "string",
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _email(Class2, params) {
    return new Class2({
      type: "string",
      format: "email",
      check: "string_format",
      abort: false,
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _guid(Class2, params) {
    return new Class2({
      type: "string",
      format: "guid",
      check: "string_format",
      abort: false,
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _uuid(Class2, params) {
    return new Class2({
      type: "string",
      format: "uuid",
      check: "string_format",
      abort: false,
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _uuidv4(Class2, params) {
    return new Class2({
      type: "string",
      format: "uuid",
      check: "string_format",
      abort: false,
      version: "v4",
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _uuidv6(Class2, params) {
    return new Class2({
      type: "string",
      format: "uuid",
      check: "string_format",
      abort: false,
      version: "v6",
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _uuidv7(Class2, params) {
    return new Class2({
      type: "string",
      format: "uuid",
      check: "string_format",
      abort: false,
      version: "v7",
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _url(Class2, params) {
    return new Class2({
      type: "string",
      format: "url",
      check: "string_format",
      abort: false,
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _emoji2(Class2, params) {
    return new Class2({
      type: "string",
      format: "emoji",
      check: "string_format",
      abort: false,
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _nanoid(Class2, params) {
    return new Class2({
      type: "string",
      format: "nanoid",
      check: "string_format",
      abort: false,
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _cuid(Class2, params) {
    return new Class2({
      type: "string",
      format: "cuid",
      check: "string_format",
      abort: false,
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _cuid2(Class2, params) {
    return new Class2({
      type: "string",
      format: "cuid2",
      check: "string_format",
      abort: false,
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _ulid(Class2, params) {
    return new Class2({
      type: "string",
      format: "ulid",
      check: "string_format",
      abort: false,
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _xid(Class2, params) {
    return new Class2({
      type: "string",
      format: "xid",
      check: "string_format",
      abort: false,
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _ksuid(Class2, params) {
    return new Class2({
      type: "string",
      format: "ksuid",
      check: "string_format",
      abort: false,
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _ipv4(Class2, params) {
    return new Class2({
      type: "string",
      format: "ipv4",
      check: "string_format",
      abort: false,
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _ipv6(Class2, params) {
    return new Class2({
      type: "string",
      format: "ipv6",
      check: "string_format",
      abort: false,
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _cidrv4(Class2, params) {
    return new Class2({
      type: "string",
      format: "cidrv4",
      check: "string_format",
      abort: false,
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _cidrv6(Class2, params) {
    return new Class2({
      type: "string",
      format: "cidrv6",
      check: "string_format",
      abort: false,
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _base64(Class2, params) {
    return new Class2({
      type: "string",
      format: "base64",
      check: "string_format",
      abort: false,
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _base64url(Class2, params) {
    return new Class2({
      type: "string",
      format: "base64url",
      check: "string_format",
      abort: false,
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _e164(Class2, params) {
    return new Class2({
      type: "string",
      format: "e164",
      check: "string_format",
      abort: false,
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _jwt(Class2, params) {
    return new Class2({
      type: "string",
      format: "jwt",
      check: "string_format",
      abort: false,
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _isoDateTime(Class2, params) {
    return new Class2({
      type: "string",
      format: "datetime",
      check: "string_format",
      offset: false,
      local: false,
      precision: null,
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _isoDate(Class2, params) {
    return new Class2({
      type: "string",
      format: "date",
      check: "string_format",
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _isoTime(Class2, params) {
    return new Class2({
      type: "string",
      format: "time",
      check: "string_format",
      precision: null,
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _isoDuration(Class2, params) {
    return new Class2({
      type: "string",
      format: "duration",
      check: "string_format",
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _number(Class2, params) {
    return new Class2({
      type: "number",
      checks: [],
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _int(Class2, params) {
    return new Class2({
      type: "number",
      check: "number_format",
      abort: false,
      format: "safeint",
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _boolean(Class2, params) {
    return new Class2({
      type: "boolean",
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _unknown(Class2) {
    return new Class2({
      type: "unknown"
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _never(Class2, params) {
    return new Class2({
      type: "never",
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _lt(value, params) {
    return new $ZodCheckLessThan({
      check: "less_than",
      ...normalizeParams(params),
      value,
      inclusive: false
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _lte(value, params) {
    return new $ZodCheckLessThan({
      check: "less_than",
      ...normalizeParams(params),
      value,
      inclusive: true
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _gt(value, params) {
    return new $ZodCheckGreaterThan({
      check: "greater_than",
      ...normalizeParams(params),
      value,
      inclusive: false
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _gte(value, params) {
    return new $ZodCheckGreaterThan({
      check: "greater_than",
      ...normalizeParams(params),
      value,
      inclusive: true
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _multipleOf(value, params) {
    return new $ZodCheckMultipleOf({
      check: "multiple_of",
      ...normalizeParams(params),
      value
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _maxLength(maximum, params) {
    const ch = new $ZodCheckMaxLength({
      check: "max_length",
      ...normalizeParams(params),
      maximum
    });
    return ch;
  }
  // @__NO_SIDE_EFFECTS__
  function _minLength(minimum, params) {
    return new $ZodCheckMinLength({
      check: "min_length",
      ...normalizeParams(params),
      minimum
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _length(length, params) {
    return new $ZodCheckLengthEquals({
      check: "length_equals",
      ...normalizeParams(params),
      length
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _regex(pattern, params) {
    return new $ZodCheckRegex({
      check: "string_format",
      format: "regex",
      ...normalizeParams(params),
      pattern
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _lowercase(params) {
    return new $ZodCheckLowerCase({
      check: "string_format",
      format: "lowercase",
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _uppercase(params) {
    return new $ZodCheckUpperCase({
      check: "string_format",
      format: "uppercase",
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _includes(includes, params) {
    return new $ZodCheckIncludes({
      check: "string_format",
      format: "includes",
      ...normalizeParams(params),
      includes
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _startsWith(prefix, params) {
    return new $ZodCheckStartsWith({
      check: "string_format",
      format: "starts_with",
      ...normalizeParams(params),
      prefix
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _endsWith(suffix, params) {
    return new $ZodCheckEndsWith({
      check: "string_format",
      format: "ends_with",
      ...normalizeParams(params),
      suffix
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _overwrite(tx) {
    return new $ZodCheckOverwrite({
      check: "overwrite",
      tx
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _normalize(form) {
    return /* @__PURE__ */ _overwrite((input) => input.normalize(form));
  }
  // @__NO_SIDE_EFFECTS__
  function _trim() {
    return /* @__PURE__ */ _overwrite((input) => input.trim());
  }
  // @__NO_SIDE_EFFECTS__
  function _toLowerCase() {
    return /* @__PURE__ */ _overwrite((input) => input.toLowerCase());
  }
  // @__NO_SIDE_EFFECTS__
  function _toUpperCase() {
    return /* @__PURE__ */ _overwrite((input) => input.toUpperCase());
  }
  // @__NO_SIDE_EFFECTS__
  function _slugify() {
    return /* @__PURE__ */ _overwrite((input) => slugify(input));
  }
  // @__NO_SIDE_EFFECTS__
  function _array(Class2, element, params) {
    return new Class2({
      type: "array",
      element,
      // get element() {
      //   return element;
      // },
      ...normalizeParams(params)
    });
  }
  // @__NO_SIDE_EFFECTS__
  function _refine(Class2, fn, _params) {
    const schema = new Class2({
      type: "custom",
      check: "custom",
      fn,
      ...normalizeParams(_params)
    });
    return schema;
  }
  // @__NO_SIDE_EFFECTS__
  function _superRefine(fn, params) {
    const ch = /* @__PURE__ */ _check((payload) => {
      payload.addIssue = (issue2) => {
        if (typeof issue2 === "string") {
          payload.issues.push(issue(issue2, payload.value, ch._zod.def));
        } else {
          const _issue = issue2;
          if (_issue.fatal)
            _issue.continue = false;
          _issue.code ?? (_issue.code = "custom");
          _issue.input ?? (_issue.input = payload.value);
          _issue.inst ?? (_issue.inst = ch);
          _issue.continue ?? (_issue.continue = !ch._zod.def.abort);
          payload.issues.push(issue(_issue));
        }
      };
      return fn(payload.value, payload);
    }, params);
    return ch;
  }
  // @__NO_SIDE_EFFECTS__
  function _check(fn, params) {
    const ch = new $ZodCheck({
      check: "custom",
      ...normalizeParams(params)
    });
    ch._zod.check = fn;
    return ch;
  }

  // node_modules/zod/v4/core/to-json-schema.js
  function initializeContext(params) {
    let target = params?.target ?? "draft-2020-12";
    if (target === "draft-4")
      target = "draft-04";
    if (target === "draft-7")
      target = "draft-07";
    return {
      processors: params.processors ?? {},
      metadataRegistry: params?.metadata ?? globalRegistry,
      target,
      unrepresentable: params?.unrepresentable ?? "throw",
      override: params?.override ?? (() => {
      }),
      io: params?.io ?? "output",
      counter: 0,
      seen: /* @__PURE__ */ new Map(),
      cycles: params?.cycles ?? "ref",
      reused: params?.reused ?? "inline",
      external: params?.external ?? void 0
    };
  }
  function process(schema, ctx, _params = { path: [], schemaPath: [] }) {
    var _a6;
    const def = schema._zod.def;
    const seen = ctx.seen.get(schema);
    if (seen) {
      seen.count++;
      const isCycle = _params.schemaPath.includes(schema);
      if (isCycle) {
        seen.cycle = _params.path;
      }
      return seen.schema;
    }
    const result2 = { schema: {}, count: 1, cycle: void 0, path: _params.path };
    ctx.seen.set(schema, result2);
    const overrideSchema = schema._zod.toJSONSchema?.();
    if (overrideSchema) {
      result2.schema = overrideSchema;
    } else {
      const params = {
        ..._params,
        schemaPath: [..._params.schemaPath, schema],
        path: _params.path
      };
      if (schema._zod.processJSONSchema) {
        schema._zod.processJSONSchema(ctx, result2.schema, params);
      } else {
        const _json = result2.schema;
        const processor = ctx.processors[def.type];
        if (!processor) {
          throw new Error(`[toJSONSchema]: Non-representable type encountered: ${def.type}`);
        }
        processor(schema, ctx, _json, params);
      }
      const parent = schema._zod.parent;
      if (parent) {
        if (!result2.ref)
          result2.ref = parent;
        process(parent, ctx, params);
        ctx.seen.get(parent).isParent = true;
      }
    }
    const meta2 = ctx.metadataRegistry.get(schema);
    if (meta2)
      Object.assign(result2.schema, meta2);
    if (ctx.io === "input" && isTransforming(schema)) {
      delete result2.schema.examples;
      delete result2.schema.default;
    }
    if (ctx.io === "input" && "_prefault" in result2.schema)
      (_a6 = result2.schema).default ?? (_a6.default = result2.schema._prefault);
    delete result2.schema._prefault;
    const _result = ctx.seen.get(schema);
    return _result.schema;
  }
  function extractDefs(ctx, schema) {
    const root = ctx.seen.get(schema);
    if (!root)
      throw new Error("Unprocessed schema. This is a bug in Zod.");
    const idToSchema = /* @__PURE__ */ new Map();
    for (const entry of ctx.seen.entries()) {
      const id = ctx.metadataRegistry.get(entry[0])?.id;
      if (id) {
        const existing = idToSchema.get(id);
        if (existing && existing !== entry[0]) {
          throw new Error(`Duplicate schema id "${id}" detected during JSON Schema conversion. Two different schemas cannot share the same id when converted together.`);
        }
        idToSchema.set(id, entry[0]);
      }
    }
    const makeURI = (entry) => {
      const defsSegment = ctx.target === "draft-2020-12" ? "$defs" : "definitions";
      if (ctx.external) {
        const externalId = ctx.external.registry.get(entry[0])?.id;
        const uriGenerator = ctx.external.uri ?? ((id2) => id2);
        if (externalId) {
          return { ref: uriGenerator(externalId) };
        }
        const id = entry[1].defId ?? entry[1].schema.id ?? `schema${ctx.counter++}`;
        entry[1].defId = id;
        return { defId: id, ref: `${uriGenerator("__shared")}#/${defsSegment}/${id}` };
      }
      if (entry[1] === root) {
        return { ref: "#" };
      }
      const uriPrefix = `#`;
      const defUriPrefix = `${uriPrefix}/${defsSegment}/`;
      const defId = entry[1].schema.id ?? `__schema${ctx.counter++}`;
      return { defId, ref: defUriPrefix + defId };
    };
    const extractToDef = (entry) => {
      if (entry[1].schema.$ref) {
        return;
      }
      const seen = entry[1];
      const { ref, defId } = makeURI(entry);
      seen.def = { ...seen.schema };
      if (defId)
        seen.defId = defId;
      const schema2 = seen.schema;
      for (const key in schema2) {
        delete schema2[key];
      }
      schema2.$ref = ref;
    };
    if (ctx.cycles === "throw") {
      for (const entry of ctx.seen.entries()) {
        const seen = entry[1];
        if (seen.cycle) {
          throw new Error(`Cycle detected: #/${seen.cycle?.join("/")}/<root>

Set the \`cycles\` parameter to \`"ref"\` to resolve cyclical schemas with defs.`);
        }
      }
    }
    for (const entry of ctx.seen.entries()) {
      const seen = entry[1];
      if (schema === entry[0]) {
        extractToDef(entry);
        continue;
      }
      if (ctx.external) {
        const ext = ctx.external.registry.get(entry[0])?.id;
        if (schema !== entry[0] && ext) {
          extractToDef(entry);
          continue;
        }
      }
      const id = ctx.metadataRegistry.get(entry[0])?.id;
      if (id) {
        extractToDef(entry);
        continue;
      }
      if (seen.cycle) {
        extractToDef(entry);
        continue;
      }
      if (seen.count > 1) {
        if (ctx.reused === "ref") {
          extractToDef(entry);
          continue;
        }
      }
    }
  }
  function finalize(ctx, schema) {
    const root = ctx.seen.get(schema);
    if (!root)
      throw new Error("Unprocessed schema. This is a bug in Zod.");
    const flattenRef = (zodSchema) => {
      const seen = ctx.seen.get(zodSchema);
      if (seen.ref === null)
        return;
      const schema2 = seen.def ?? seen.schema;
      const _cached = { ...schema2 };
      const ref = seen.ref;
      seen.ref = null;
      if (ref) {
        flattenRef(ref);
        const refSeen = ctx.seen.get(ref);
        const refSchema = refSeen.schema;
        if (refSchema.$ref && (ctx.target === "draft-07" || ctx.target === "draft-04" || ctx.target === "openapi-3.0")) {
          schema2.allOf = schema2.allOf ?? [];
          schema2.allOf.push(refSchema);
        } else {
          Object.assign(schema2, refSchema);
        }
        Object.assign(schema2, _cached);
        const isParentRef = zodSchema._zod.parent === ref;
        if (isParentRef) {
          for (const key in schema2) {
            if (key === "$ref" || key === "allOf")
              continue;
            if (!(key in _cached)) {
              delete schema2[key];
            }
          }
        }
        if (refSchema.$ref && refSeen.def) {
          for (const key in schema2) {
            if (key === "$ref" || key === "allOf")
              continue;
            if (key in refSeen.def && JSON.stringify(schema2[key]) === JSON.stringify(refSeen.def[key])) {
              delete schema2[key];
            }
          }
        }
      }
      const parent = zodSchema._zod.parent;
      if (parent && parent !== ref) {
        flattenRef(parent);
        const parentSeen = ctx.seen.get(parent);
        if (parentSeen?.schema.$ref) {
          schema2.$ref = parentSeen.schema.$ref;
          if (parentSeen.def) {
            for (const key in schema2) {
              if (key === "$ref" || key === "allOf")
                continue;
              if (key in parentSeen.def && JSON.stringify(schema2[key]) === JSON.stringify(parentSeen.def[key])) {
                delete schema2[key];
              }
            }
          }
        }
      }
      ctx.override({
        zodSchema,
        jsonSchema: schema2,
        path: seen.path ?? []
      });
    };
    for (const entry of [...ctx.seen.entries()].reverse()) {
      flattenRef(entry[0]);
    }
    const result2 = {};
    if (ctx.target === "draft-2020-12") {
      result2.$schema = "https://json-schema.org/draft/2020-12/schema";
    } else if (ctx.target === "draft-07") {
      result2.$schema = "http://json-schema.org/draft-07/schema#";
    } else if (ctx.target === "draft-04") {
      result2.$schema = "http://json-schema.org/draft-04/schema#";
    } else if (ctx.target === "openapi-3.0") {
    } else {
    }
    if (ctx.external?.uri) {
      const id = ctx.external.registry.get(schema)?.id;
      if (!id)
        throw new Error("Schema is missing an `id` property");
      result2.$id = ctx.external.uri(id);
    }
    Object.assign(result2, root.def ?? root.schema);
    const rootMetaId = ctx.metadataRegistry.get(schema)?.id;
    if (rootMetaId !== void 0 && result2.id === rootMetaId)
      delete result2.id;
    const defs = ctx.external?.defs ?? {};
    for (const entry of ctx.seen.entries()) {
      const seen = entry[1];
      if (seen.def && seen.defId) {
        if (seen.def.id === seen.defId)
          delete seen.def.id;
        defs[seen.defId] = seen.def;
      }
    }
    if (ctx.external) {
    } else {
      if (Object.keys(defs).length > 0) {
        if (ctx.target === "draft-2020-12") {
          result2.$defs = defs;
        } else {
          result2.definitions = defs;
        }
      }
    }
    try {
      const finalized = JSON.parse(JSON.stringify(result2));
      Object.defineProperty(finalized, "~standard", {
        value: {
          ...schema["~standard"],
          jsonSchema: {
            input: createStandardJSONSchemaMethod(schema, "input", ctx.processors),
            output: createStandardJSONSchemaMethod(schema, "output", ctx.processors)
          }
        },
        enumerable: false,
        writable: false
      });
      return finalized;
    } catch (_err) {
      throw new Error("Error converting schema to JSON.");
    }
  }
  function isTransforming(_schema, _ctx) {
    const ctx = _ctx ?? { seen: /* @__PURE__ */ new Set() };
    if (ctx.seen.has(_schema))
      return false;
    ctx.seen.add(_schema);
    const def = _schema._zod.def;
    if (def.type === "transform")
      return true;
    if (def.type === "array")
      return isTransforming(def.element, ctx);
    if (def.type === "set")
      return isTransforming(def.valueType, ctx);
    if (def.type === "lazy")
      return isTransforming(def.getter(), ctx);
    if (def.type === "promise" || def.type === "optional" || def.type === "nonoptional" || def.type === "nullable" || def.type === "readonly" || def.type === "default" || def.type === "prefault") {
      return isTransforming(def.innerType, ctx);
    }
    if (def.type === "intersection") {
      return isTransforming(def.left, ctx) || isTransforming(def.right, ctx);
    }
    if (def.type === "record" || def.type === "map") {
      return isTransforming(def.keyType, ctx) || isTransforming(def.valueType, ctx);
    }
    if (def.type === "pipe") {
      if (_schema._zod.traits.has("$ZodCodec"))
        return true;
      return isTransforming(def.in, ctx) || isTransforming(def.out, ctx);
    }
    if (def.type === "object") {
      for (const key in def.shape) {
        if (isTransforming(def.shape[key], ctx))
          return true;
      }
      return false;
    }
    if (def.type === "union") {
      for (const option of def.options) {
        if (isTransforming(option, ctx))
          return true;
      }
      return false;
    }
    if (def.type === "tuple") {
      for (const item of def.items) {
        if (isTransforming(item, ctx))
          return true;
      }
      if (def.rest && isTransforming(def.rest, ctx))
        return true;
      return false;
    }
    return false;
  }
  var createToJSONSchemaMethod = (schema, processors = {}) => (params) => {
    const ctx = initializeContext({ ...params, processors });
    process(schema, ctx);
    extractDefs(ctx, schema);
    return finalize(ctx, schema);
  };
  var createStandardJSONSchemaMethod = (schema, io, processors = {}) => (params) => {
    const { libraryOptions, target } = params ?? {};
    const ctx = initializeContext({ ...libraryOptions ?? {}, target, io, processors });
    process(schema, ctx);
    extractDefs(ctx, schema);
    return finalize(ctx, schema);
  };

  // node_modules/zod/v4/core/json-schema-processors.js
  var formatMap = {
    guid: "uuid",
    url: "uri",
    datetime: "date-time",
    json_string: "json-string",
    regex: ""
    // do not set
  };
  var stringProcessor = (schema, ctx, _json, _params) => {
    const json = _json;
    json.type = "string";
    const { minimum, maximum, format, patterns, contentEncoding } = schema._zod.bag;
    if (typeof minimum === "number")
      json.minLength = minimum;
    if (typeof maximum === "number")
      json.maxLength = maximum;
    if (format) {
      json.format = formatMap[format] ?? format;
      if (json.format === "")
        delete json.format;
      if (format === "time") {
        delete json.format;
      }
    }
    if (contentEncoding)
      json.contentEncoding = contentEncoding;
    if (patterns && patterns.size > 0) {
      const regexes = [...patterns];
      if (regexes.length === 1)
        json.pattern = regexes[0].source;
      else if (regexes.length > 1) {
        json.allOf = [
          ...regexes.map((regex) => ({
            ...ctx.target === "draft-07" || ctx.target === "draft-04" || ctx.target === "openapi-3.0" ? { type: "string" } : {},
            pattern: regex.source
          }))
        ];
      }
    }
  };
  var numberProcessor = (schema, ctx, _json, _params) => {
    const json = _json;
    const { minimum, maximum, format, multipleOf, exclusiveMaximum, exclusiveMinimum } = schema._zod.bag;
    if (typeof format === "string" && format.includes("int"))
      json.type = "integer";
    else
      json.type = "number";
    const exMin = typeof exclusiveMinimum === "number" && exclusiveMinimum >= (minimum ?? Number.NEGATIVE_INFINITY);
    const exMax = typeof exclusiveMaximum === "number" && exclusiveMaximum <= (maximum ?? Number.POSITIVE_INFINITY);
    const legacy = ctx.target === "draft-04" || ctx.target === "openapi-3.0";
    if (exMin) {
      if (legacy) {
        json.minimum = exclusiveMinimum;
        json.exclusiveMinimum = true;
      } else {
        json.exclusiveMinimum = exclusiveMinimum;
      }
    } else if (typeof minimum === "number") {
      json.minimum = minimum;
    }
    if (exMax) {
      if (legacy) {
        json.maximum = exclusiveMaximum;
        json.exclusiveMaximum = true;
      } else {
        json.exclusiveMaximum = exclusiveMaximum;
      }
    } else if (typeof maximum === "number") {
      json.maximum = maximum;
    }
    if (typeof multipleOf === "number")
      json.multipleOf = multipleOf;
  };
  var booleanProcessor = (_schema, _ctx, json, _params) => {
    json.type = "boolean";
  };
  var bigintProcessor = (_schema, ctx, _json, _params) => {
    if (ctx.unrepresentable === "throw") {
      throw new Error("BigInt cannot be represented in JSON Schema");
    }
  };
  var symbolProcessor = (_schema, ctx, _json, _params) => {
    if (ctx.unrepresentable === "throw") {
      throw new Error("Symbols cannot be represented in JSON Schema");
    }
  };
  var nullProcessor = (_schema, ctx, json, _params) => {
    if (ctx.target === "openapi-3.0") {
      json.type = "string";
      json.nullable = true;
      json.enum = [null];
    } else {
      json.type = "null";
    }
  };
  var undefinedProcessor = (_schema, ctx, _json, _params) => {
    if (ctx.unrepresentable === "throw") {
      throw new Error("Undefined cannot be represented in JSON Schema");
    }
  };
  var voidProcessor = (_schema, ctx, _json, _params) => {
    if (ctx.unrepresentable === "throw") {
      throw new Error("Void cannot be represented in JSON Schema");
    }
  };
  var neverProcessor = (_schema, _ctx, json, _params) => {
    json.not = {};
  };
  var anyProcessor = (_schema, _ctx, _json, _params) => {
  };
  var unknownProcessor = (_schema, _ctx, _json, _params) => {
  };
  var dateProcessor = (_schema, ctx, _json, _params) => {
    if (ctx.unrepresentable === "throw") {
      throw new Error("Date cannot be represented in JSON Schema");
    }
  };
  var enumProcessor = (schema, _ctx, json, _params) => {
    const def = schema._zod.def;
    const values = getEnumValues(def.entries);
    if (values.every((v) => typeof v === "number"))
      json.type = "number";
    if (values.every((v) => typeof v === "string"))
      json.type = "string";
    json.enum = values;
  };
  var literalProcessor = (schema, ctx, json, _params) => {
    const def = schema._zod.def;
    const vals = [];
    for (const val of def.values) {
      if (val === void 0) {
        if (ctx.unrepresentable === "throw") {
          throw new Error("Literal `undefined` cannot be represented in JSON Schema");
        } else {
        }
      } else if (typeof val === "bigint") {
        if (ctx.unrepresentable === "throw") {
          throw new Error("BigInt literals cannot be represented in JSON Schema");
        } else {
          vals.push(Number(val));
        }
      } else {
        vals.push(val);
      }
    }
    if (vals.length === 0) {
    } else if (vals.length === 1) {
      const val = vals[0];
      json.type = val === null ? "null" : typeof val;
      if (ctx.target === "draft-04" || ctx.target === "openapi-3.0") {
        json.enum = [val];
      } else {
        json.const = val;
      }
    } else {
      if (vals.every((v) => typeof v === "number"))
        json.type = "number";
      if (vals.every((v) => typeof v === "string"))
        json.type = "string";
      if (vals.every((v) => typeof v === "boolean"))
        json.type = "boolean";
      if (vals.every((v) => v === null))
        json.type = "null";
      json.enum = vals;
    }
  };
  var nanProcessor = (_schema, ctx, _json, _params) => {
    if (ctx.unrepresentable === "throw") {
      throw new Error("NaN cannot be represented in JSON Schema");
    }
  };
  var templateLiteralProcessor = (schema, _ctx, json, _params) => {
    const _json = json;
    const pattern = schema._zod.pattern;
    if (!pattern)
      throw new Error("Pattern not found in template literal");
    _json.type = "string";
    _json.pattern = pattern.source;
  };
  var fileProcessor = (schema, _ctx, json, _params) => {
    const _json = json;
    const file = {
      type: "string",
      format: "binary",
      contentEncoding: "binary"
    };
    const { minimum, maximum, mime } = schema._zod.bag;
    if (minimum !== void 0)
      file.minLength = minimum;
    if (maximum !== void 0)
      file.maxLength = maximum;
    if (mime) {
      if (mime.length === 1) {
        file.contentMediaType = mime[0];
        Object.assign(_json, file);
      } else {
        Object.assign(_json, file);
        _json.anyOf = mime.map((m) => ({ contentMediaType: m }));
      }
    } else {
      Object.assign(_json, file);
    }
  };
  var successProcessor = (_schema, _ctx, json, _params) => {
    json.type = "boolean";
  };
  var customProcessor = (_schema, ctx, _json, _params) => {
    if (ctx.unrepresentable === "throw") {
      throw new Error("Custom types cannot be represented in JSON Schema");
    }
  };
  var functionProcessor = (_schema, ctx, _json, _params) => {
    if (ctx.unrepresentable === "throw") {
      throw new Error("Function types cannot be represented in JSON Schema");
    }
  };
  var transformProcessor = (_schema, ctx, _json, _params) => {
    if (ctx.unrepresentable === "throw") {
      throw new Error("Transforms cannot be represented in JSON Schema");
    }
  };
  var mapProcessor = (_schema, ctx, _json, _params) => {
    if (ctx.unrepresentable === "throw") {
      throw new Error("Map cannot be represented in JSON Schema");
    }
  };
  var setProcessor = (_schema, ctx, _json, _params) => {
    if (ctx.unrepresentable === "throw") {
      throw new Error("Set cannot be represented in JSON Schema");
    }
  };
  var arrayProcessor = (schema, ctx, _json, params) => {
    const json = _json;
    const def = schema._zod.def;
    const { minimum, maximum } = schema._zod.bag;
    if (typeof minimum === "number")
      json.minItems = minimum;
    if (typeof maximum === "number")
      json.maxItems = maximum;
    json.type = "array";
    json.items = process(def.element, ctx, {
      ...params,
      path: [...params.path, "items"]
    });
  };
  var objectProcessor = (schema, ctx, _json, params) => {
    const json = _json;
    const def = schema._zod.def;
    json.type = "object";
    json.properties = {};
    const shape = def.shape;
    for (const key in shape) {
      json.properties[key] = process(shape[key], ctx, {
        ...params,
        path: [...params.path, "properties", key]
      });
    }
    const allKeys = new Set(Object.keys(shape));
    const requiredKeys = new Set([...allKeys].filter((key) => {
      const v = def.shape[key]._zod;
      if (ctx.io === "input") {
        return v.optin === void 0;
      } else {
        return v.optout === void 0;
      }
    }));
    if (requiredKeys.size > 0) {
      json.required = Array.from(requiredKeys);
    }
    if (def.catchall?._zod.def.type === "never") {
      json.additionalProperties = false;
    } else if (!def.catchall) {
      if (ctx.io === "output")
        json.additionalProperties = false;
    } else if (def.catchall) {
      json.additionalProperties = process(def.catchall, ctx, {
        ...params,
        path: [...params.path, "additionalProperties"]
      });
    }
  };
  var unionProcessor = (schema, ctx, json, params) => {
    const def = schema._zod.def;
    const isExclusive = def.inclusive === false;
    const options = def.options.map((x, i) => process(x, ctx, {
      ...params,
      path: [...params.path, isExclusive ? "oneOf" : "anyOf", i]
    }));
    if (isExclusive) {
      json.oneOf = options;
    } else {
      json.anyOf = options;
    }
  };
  var intersectionProcessor = (schema, ctx, json, params) => {
    const def = schema._zod.def;
    const a = process(def.left, ctx, {
      ...params,
      path: [...params.path, "allOf", 0]
    });
    const b = process(def.right, ctx, {
      ...params,
      path: [...params.path, "allOf", 1]
    });
    const isSimpleIntersection = (val) => "allOf" in val && Object.keys(val).length === 1;
    const allOf = [
      ...isSimpleIntersection(a) ? a.allOf : [a],
      ...isSimpleIntersection(b) ? b.allOf : [b]
    ];
    json.allOf = allOf;
  };
  var tupleProcessor = (schema, ctx, _json, params) => {
    const json = _json;
    const def = schema._zod.def;
    json.type = "array";
    const prefixPath = ctx.target === "draft-2020-12" ? "prefixItems" : "items";
    const restPath = ctx.target === "draft-2020-12" ? "items" : ctx.target === "openapi-3.0" ? "items" : "additionalItems";
    const prefixItems = def.items.map((x, i) => process(x, ctx, {
      ...params,
      path: [...params.path, prefixPath, i]
    }));
    const rest = def.rest ? process(def.rest, ctx, {
      ...params,
      path: [...params.path, restPath, ...ctx.target === "openapi-3.0" ? [def.items.length] : []]
    }) : null;
    if (ctx.target === "draft-2020-12") {
      json.prefixItems = prefixItems;
      if (rest) {
        json.items = rest;
      }
    } else if (ctx.target === "openapi-3.0") {
      json.items = {
        anyOf: prefixItems
      };
      if (rest) {
        json.items.anyOf.push(rest);
      }
      json.minItems = prefixItems.length;
      if (!rest) {
        json.maxItems = prefixItems.length;
      }
    } else {
      json.items = prefixItems;
      if (rest) {
        json.additionalItems = rest;
      }
    }
    const { minimum, maximum } = schema._zod.bag;
    if (typeof minimum === "number")
      json.minItems = minimum;
    if (typeof maximum === "number")
      json.maxItems = maximum;
  };
  var recordProcessor = (schema, ctx, _json, params) => {
    const json = _json;
    const def = schema._zod.def;
    json.type = "object";
    const keyType = def.keyType;
    const keyBag = keyType._zod.bag;
    const patterns = keyBag?.patterns;
    if (def.mode === "loose" && patterns && patterns.size > 0) {
      const valueSchema = process(def.valueType, ctx, {
        ...params,
        path: [...params.path, "patternProperties", "*"]
      });
      json.patternProperties = {};
      for (const pattern of patterns) {
        json.patternProperties[pattern.source] = valueSchema;
      }
    } else {
      if (ctx.target === "draft-07" || ctx.target === "draft-2020-12") {
        json.propertyNames = process(def.keyType, ctx, {
          ...params,
          path: [...params.path, "propertyNames"]
        });
      }
      json.additionalProperties = process(def.valueType, ctx, {
        ...params,
        path: [...params.path, "additionalProperties"]
      });
    }
    const keyValues = keyType._zod.values;
    if (keyValues) {
      const validKeyValues = [...keyValues].filter((v) => typeof v === "string" || typeof v === "number");
      if (validKeyValues.length > 0) {
        json.required = validKeyValues;
      }
    }
  };
  var nullableProcessor = (schema, ctx, json, params) => {
    const def = schema._zod.def;
    const inner = process(def.innerType, ctx, params);
    const seen = ctx.seen.get(schema);
    if (ctx.target === "openapi-3.0") {
      seen.ref = def.innerType;
      json.nullable = true;
    } else {
      json.anyOf = [inner, { type: "null" }];
    }
  };
  var nonoptionalProcessor = (schema, ctx, _json, params) => {
    const def = schema._zod.def;
    process(def.innerType, ctx, params);
    const seen = ctx.seen.get(schema);
    seen.ref = def.innerType;
  };
  var defaultProcessor = (schema, ctx, json, params) => {
    const def = schema._zod.def;
    process(def.innerType, ctx, params);
    const seen = ctx.seen.get(schema);
    seen.ref = def.innerType;
    json.default = JSON.parse(JSON.stringify(def.defaultValue));
  };
  var prefaultProcessor = (schema, ctx, json, params) => {
    const def = schema._zod.def;
    process(def.innerType, ctx, params);
    const seen = ctx.seen.get(schema);
    seen.ref = def.innerType;
    if (ctx.io === "input")
      json._prefault = JSON.parse(JSON.stringify(def.defaultValue));
  };
  var catchProcessor = (schema, ctx, json, params) => {
    const def = schema._zod.def;
    process(def.innerType, ctx, params);
    const seen = ctx.seen.get(schema);
    seen.ref = def.innerType;
    let catchValue;
    try {
      catchValue = def.catchValue(void 0);
    } catch {
      throw new Error("Dynamic catch values are not supported in JSON Schema");
    }
    json.default = catchValue;
  };
  var pipeProcessor = (schema, ctx, _json, params) => {
    const def = schema._zod.def;
    const inIsTransform = def.in._zod.traits.has("$ZodTransform");
    const innerType = ctx.io === "input" ? inIsTransform ? def.out : def.in : def.out;
    process(innerType, ctx, params);
    const seen = ctx.seen.get(schema);
    seen.ref = innerType;
  };
  var readonlyProcessor = (schema, ctx, json, params) => {
    const def = schema._zod.def;
    process(def.innerType, ctx, params);
    const seen = ctx.seen.get(schema);
    seen.ref = def.innerType;
    json.readOnly = true;
  };
  var promiseProcessor = (schema, ctx, _json, params) => {
    const def = schema._zod.def;
    process(def.innerType, ctx, params);
    const seen = ctx.seen.get(schema);
    seen.ref = def.innerType;
  };
  var optionalProcessor = (schema, ctx, _json, params) => {
    const def = schema._zod.def;
    process(def.innerType, ctx, params);
    const seen = ctx.seen.get(schema);
    seen.ref = def.innerType;
  };
  var lazyProcessor = (schema, ctx, _json, params) => {
    const innerType = schema._zod.innerType;
    process(innerType, ctx, params);
    const seen = ctx.seen.get(schema);
    seen.ref = innerType;
  };
  var allProcessors = {
    string: stringProcessor,
    number: numberProcessor,
    boolean: booleanProcessor,
    bigint: bigintProcessor,
    symbol: symbolProcessor,
    null: nullProcessor,
    undefined: undefinedProcessor,
    void: voidProcessor,
    never: neverProcessor,
    any: anyProcessor,
    unknown: unknownProcessor,
    date: dateProcessor,
    enum: enumProcessor,
    literal: literalProcessor,
    nan: nanProcessor,
    template_literal: templateLiteralProcessor,
    file: fileProcessor,
    success: successProcessor,
    custom: customProcessor,
    function: functionProcessor,
    transform: transformProcessor,
    map: mapProcessor,
    set: setProcessor,
    array: arrayProcessor,
    object: objectProcessor,
    union: unionProcessor,
    intersection: intersectionProcessor,
    tuple: tupleProcessor,
    record: recordProcessor,
    nullable: nullableProcessor,
    nonoptional: nonoptionalProcessor,
    default: defaultProcessor,
    prefault: prefaultProcessor,
    catch: catchProcessor,
    pipe: pipeProcessor,
    readonly: readonlyProcessor,
    promise: promiseProcessor,
    optional: optionalProcessor,
    lazy: lazyProcessor
  };
  function toJSONSchema(input, params) {
    if ("_idmap" in input) {
      const registry2 = input;
      const ctx2 = initializeContext({ ...params, processors: allProcessors });
      const defs = {};
      for (const entry of registry2._idmap.entries()) {
        const [_, schema] = entry;
        process(schema, ctx2);
      }
      const schemas = {};
      const external = {
        registry: registry2,
        uri: params?.uri,
        defs
      };
      ctx2.external = external;
      for (const entry of registry2._idmap.entries()) {
        const [key, schema] = entry;
        extractDefs(ctx2, schema);
        schemas[key] = finalize(ctx2, schema);
      }
      if (Object.keys(defs).length > 0) {
        const defsSegment = ctx2.target === "draft-2020-12" ? "$defs" : "definitions";
        schemas.__shared = {
          [defsSegment]: defs
        };
      }
      return { schemas };
    }
    const ctx = initializeContext({ ...params, processors: allProcessors });
    process(input, ctx);
    extractDefs(ctx, input);
    return finalize(ctx, input);
  }

  // node_modules/zod/v4/classic/iso.js
  var ZodISODateTime = /* @__PURE__ */ $constructor("ZodISODateTime", (inst, def) => {
    $ZodISODateTime.init(inst, def);
    ZodStringFormat.init(inst, def);
  });
  function datetime2(params) {
    return _isoDateTime(ZodISODateTime, params);
  }
  var ZodISODate = /* @__PURE__ */ $constructor("ZodISODate", (inst, def) => {
    $ZodISODate.init(inst, def);
    ZodStringFormat.init(inst, def);
  });
  function date2(params) {
    return _isoDate(ZodISODate, params);
  }
  var ZodISOTime = /* @__PURE__ */ $constructor("ZodISOTime", (inst, def) => {
    $ZodISOTime.init(inst, def);
    ZodStringFormat.init(inst, def);
  });
  function time2(params) {
    return _isoTime(ZodISOTime, params);
  }
  var ZodISODuration = /* @__PURE__ */ $constructor("ZodISODuration", (inst, def) => {
    $ZodISODuration.init(inst, def);
    ZodStringFormat.init(inst, def);
  });
  function duration2(params) {
    return _isoDuration(ZodISODuration, params);
  }

  // node_modules/zod/v4/classic/errors.js
  var initializer2 = (inst, issues) => {
    $ZodError.init(inst, issues);
    inst.name = "ZodError";
    Object.defineProperties(inst, {
      format: {
        value: (mapper) => formatError(inst, mapper)
        // enumerable: false,
      },
      flatten: {
        value: (mapper) => flattenError(inst, mapper)
        // enumerable: false,
      },
      addIssue: {
        value: (issue2) => {
          inst.issues.push(issue2);
          inst.message = JSON.stringify(inst.issues, jsonStringifyReplacer, 2);
        }
        // enumerable: false,
      },
      addIssues: {
        value: (issues2) => {
          inst.issues.push(...issues2);
          inst.message = JSON.stringify(inst.issues, jsonStringifyReplacer, 2);
        }
        // enumerable: false,
      },
      isEmpty: {
        get() {
          return inst.issues.length === 0;
        }
        // enumerable: false,
      }
    });
  };
  var ZodRealError = /* @__PURE__ */ $constructor("ZodError", initializer2, {
    Parent: Error
  });

  // node_modules/zod/v4/classic/parse.js
  var parse2 = /* @__PURE__ */ _parse(ZodRealError);
  var parseAsync2 = /* @__PURE__ */ _parseAsync(ZodRealError);
  var safeParse2 = /* @__PURE__ */ _safeParse(ZodRealError);
  var safeParseAsync2 = /* @__PURE__ */ _safeParseAsync(ZodRealError);
  var encode = /* @__PURE__ */ _encode(ZodRealError);
  var decode = /* @__PURE__ */ _decode(ZodRealError);
  var encodeAsync = /* @__PURE__ */ _encodeAsync(ZodRealError);
  var decodeAsync = /* @__PURE__ */ _decodeAsync(ZodRealError);
  var safeEncode = /* @__PURE__ */ _safeEncode(ZodRealError);
  var safeDecode = /* @__PURE__ */ _safeDecode(ZodRealError);
  var safeEncodeAsync = /* @__PURE__ */ _safeEncodeAsync(ZodRealError);
  var safeDecodeAsync = /* @__PURE__ */ _safeDecodeAsync(ZodRealError);

  // node_modules/zod/v4/classic/schemas.js
  var _installedGroups = /* @__PURE__ */ new WeakMap();
  function _installLazyMethods(inst, group, methods) {
    const proto2 = Object.getPrototypeOf(inst);
    let installed = _installedGroups.get(proto2);
    if (!installed) {
      installed = /* @__PURE__ */ new Set();
      _installedGroups.set(proto2, installed);
    }
    if (installed.has(group))
      return;
    installed.add(group);
    for (const key in methods) {
      const fn = methods[key];
      Object.defineProperty(proto2, key, {
        configurable: true,
        enumerable: false,
        get() {
          const bound = fn.bind(this);
          Object.defineProperty(this, key, {
            configurable: true,
            writable: true,
            enumerable: true,
            value: bound
          });
          return bound;
        },
        set(v) {
          Object.defineProperty(this, key, {
            configurable: true,
            writable: true,
            enumerable: true,
            value: v
          });
        }
      });
    }
  }
  var ZodType = /* @__PURE__ */ $constructor("ZodType", (inst, def) => {
    $ZodType.init(inst, def);
    Object.assign(inst["~standard"], {
      jsonSchema: {
        input: createStandardJSONSchemaMethod(inst, "input"),
        output: createStandardJSONSchemaMethod(inst, "output")
      }
    });
    inst.toJSONSchema = createToJSONSchemaMethod(inst, {});
    inst.def = def;
    inst.type = def.type;
    Object.defineProperty(inst, "_def", { value: def });
    inst.parse = (data, params) => parse2(inst, data, params, { callee: inst.parse });
    inst.safeParse = (data, params) => safeParse2(inst, data, params);
    inst.parseAsync = async (data, params) => parseAsync2(inst, data, params, { callee: inst.parseAsync });
    inst.safeParseAsync = async (data, params) => safeParseAsync2(inst, data, params);
    inst.spa = inst.safeParseAsync;
    inst.encode = (data, params) => encode(inst, data, params);
    inst.decode = (data, params) => decode(inst, data, params);
    inst.encodeAsync = async (data, params) => encodeAsync(inst, data, params);
    inst.decodeAsync = async (data, params) => decodeAsync(inst, data, params);
    inst.safeEncode = (data, params) => safeEncode(inst, data, params);
    inst.safeDecode = (data, params) => safeDecode(inst, data, params);
    inst.safeEncodeAsync = async (data, params) => safeEncodeAsync(inst, data, params);
    inst.safeDecodeAsync = async (data, params) => safeDecodeAsync(inst, data, params);
    _installLazyMethods(inst, "ZodType", {
      check(...chks) {
        const def2 = this.def;
        return this.clone(util_exports.mergeDefs(def2, {
          checks: [
            ...def2.checks ?? [],
            ...chks.map((ch) => typeof ch === "function" ? { _zod: { check: ch, def: { check: "custom" }, onattach: [] } } : ch)
          ]
        }), { parent: true });
      },
      with(...chks) {
        return this.check(...chks);
      },
      clone(def2, params) {
        return clone(this, def2, params);
      },
      brand() {
        return this;
      },
      register(reg, meta2) {
        reg.add(this, meta2);
        return this;
      },
      refine(check, params) {
        return this.check(refine(check, params));
      },
      superRefine(refinement, params) {
        return this.check(superRefine(refinement, params));
      },
      overwrite(fn) {
        return this.check(_overwrite(fn));
      },
      optional() {
        return optional(this);
      },
      exactOptional() {
        return exactOptional(this);
      },
      nullable() {
        return nullable(this);
      },
      nullish() {
        return optional(nullable(this));
      },
      nonoptional(params) {
        return nonoptional(this, params);
      },
      array() {
        return array(this);
      },
      or(arg) {
        return union([this, arg]);
      },
      and(arg) {
        return intersection(this, arg);
      },
      transform(tx) {
        return pipe(this, transform(tx));
      },
      default(d) {
        return _default(this, d);
      },
      prefault(d) {
        return prefault(this, d);
      },
      catch(params) {
        return _catch(this, params);
      },
      pipe(target) {
        return pipe(this, target);
      },
      readonly() {
        return readonly(this);
      },
      describe(description) {
        const cl = this.clone();
        globalRegistry.add(cl, { description });
        return cl;
      },
      meta(...args) {
        if (args.length === 0)
          return globalRegistry.get(this);
        const cl = this.clone();
        globalRegistry.add(cl, args[0]);
        return cl;
      },
      isOptional() {
        return this.safeParse(void 0).success;
      },
      isNullable() {
        return this.safeParse(null).success;
      },
      apply(fn) {
        return fn(this);
      }
    });
    Object.defineProperty(inst, "description", {
      get() {
        return globalRegistry.get(inst)?.description;
      },
      configurable: true
    });
    return inst;
  });
  var _ZodString = /* @__PURE__ */ $constructor("_ZodString", (inst, def) => {
    $ZodString.init(inst, def);
    ZodType.init(inst, def);
    inst._zod.processJSONSchema = (ctx, json, params) => stringProcessor(inst, ctx, json, params);
    const bag = inst._zod.bag;
    inst.format = bag.format ?? null;
    inst.minLength = bag.minimum ?? null;
    inst.maxLength = bag.maximum ?? null;
    _installLazyMethods(inst, "_ZodString", {
      regex(...args) {
        return this.check(_regex(...args));
      },
      includes(...args) {
        return this.check(_includes(...args));
      },
      startsWith(...args) {
        return this.check(_startsWith(...args));
      },
      endsWith(...args) {
        return this.check(_endsWith(...args));
      },
      min(...args) {
        return this.check(_minLength(...args));
      },
      max(...args) {
        return this.check(_maxLength(...args));
      },
      length(...args) {
        return this.check(_length(...args));
      },
      nonempty(...args) {
        return this.check(_minLength(1, ...args));
      },
      lowercase(params) {
        return this.check(_lowercase(params));
      },
      uppercase(params) {
        return this.check(_uppercase(params));
      },
      trim() {
        return this.check(_trim());
      },
      normalize(...args) {
        return this.check(_normalize(...args));
      },
      toLowerCase() {
        return this.check(_toLowerCase());
      },
      toUpperCase() {
        return this.check(_toUpperCase());
      },
      slugify() {
        return this.check(_slugify());
      }
    });
  });
  var ZodString = /* @__PURE__ */ $constructor("ZodString", (inst, def) => {
    $ZodString.init(inst, def);
    _ZodString.init(inst, def);
    inst.email = (params) => inst.check(_email(ZodEmail, params));
    inst.url = (params) => inst.check(_url(ZodURL, params));
    inst.jwt = (params) => inst.check(_jwt(ZodJWT, params));
    inst.emoji = (params) => inst.check(_emoji2(ZodEmoji, params));
    inst.guid = (params) => inst.check(_guid(ZodGUID, params));
    inst.uuid = (params) => inst.check(_uuid(ZodUUID, params));
    inst.uuidv4 = (params) => inst.check(_uuidv4(ZodUUID, params));
    inst.uuidv6 = (params) => inst.check(_uuidv6(ZodUUID, params));
    inst.uuidv7 = (params) => inst.check(_uuidv7(ZodUUID, params));
    inst.nanoid = (params) => inst.check(_nanoid(ZodNanoID, params));
    inst.guid = (params) => inst.check(_guid(ZodGUID, params));
    inst.cuid = (params) => inst.check(_cuid(ZodCUID, params));
    inst.cuid2 = (params) => inst.check(_cuid2(ZodCUID2, params));
    inst.ulid = (params) => inst.check(_ulid(ZodULID, params));
    inst.base64 = (params) => inst.check(_base64(ZodBase64, params));
    inst.base64url = (params) => inst.check(_base64url(ZodBase64URL, params));
    inst.xid = (params) => inst.check(_xid(ZodXID, params));
    inst.ksuid = (params) => inst.check(_ksuid(ZodKSUID, params));
    inst.ipv4 = (params) => inst.check(_ipv4(ZodIPv4, params));
    inst.ipv6 = (params) => inst.check(_ipv6(ZodIPv6, params));
    inst.cidrv4 = (params) => inst.check(_cidrv4(ZodCIDRv4, params));
    inst.cidrv6 = (params) => inst.check(_cidrv6(ZodCIDRv6, params));
    inst.e164 = (params) => inst.check(_e164(ZodE164, params));
    inst.datetime = (params) => inst.check(datetime2(params));
    inst.date = (params) => inst.check(date2(params));
    inst.time = (params) => inst.check(time2(params));
    inst.duration = (params) => inst.check(duration2(params));
  });
  function string2(params) {
    return _string(ZodString, params);
  }
  var ZodStringFormat = /* @__PURE__ */ $constructor("ZodStringFormat", (inst, def) => {
    $ZodStringFormat.init(inst, def);
    _ZodString.init(inst, def);
  });
  var ZodEmail = /* @__PURE__ */ $constructor("ZodEmail", (inst, def) => {
    $ZodEmail.init(inst, def);
    ZodStringFormat.init(inst, def);
  });
  var ZodGUID = /* @__PURE__ */ $constructor("ZodGUID", (inst, def) => {
    $ZodGUID.init(inst, def);
    ZodStringFormat.init(inst, def);
  });
  var ZodUUID = /* @__PURE__ */ $constructor("ZodUUID", (inst, def) => {
    $ZodUUID.init(inst, def);
    ZodStringFormat.init(inst, def);
  });
  var ZodURL = /* @__PURE__ */ $constructor("ZodURL", (inst, def) => {
    $ZodURL.init(inst, def);
    ZodStringFormat.init(inst, def);
  });
  var ZodEmoji = /* @__PURE__ */ $constructor("ZodEmoji", (inst, def) => {
    $ZodEmoji.init(inst, def);
    ZodStringFormat.init(inst, def);
  });
  var ZodNanoID = /* @__PURE__ */ $constructor("ZodNanoID", (inst, def) => {
    $ZodNanoID.init(inst, def);
    ZodStringFormat.init(inst, def);
  });
  var ZodCUID = /* @__PURE__ */ $constructor("ZodCUID", (inst, def) => {
    $ZodCUID.init(inst, def);
    ZodStringFormat.init(inst, def);
  });
  var ZodCUID2 = /* @__PURE__ */ $constructor("ZodCUID2", (inst, def) => {
    $ZodCUID2.init(inst, def);
    ZodStringFormat.init(inst, def);
  });
  var ZodULID = /* @__PURE__ */ $constructor("ZodULID", (inst, def) => {
    $ZodULID.init(inst, def);
    ZodStringFormat.init(inst, def);
  });
  var ZodXID = /* @__PURE__ */ $constructor("ZodXID", (inst, def) => {
    $ZodXID.init(inst, def);
    ZodStringFormat.init(inst, def);
  });
  var ZodKSUID = /* @__PURE__ */ $constructor("ZodKSUID", (inst, def) => {
    $ZodKSUID.init(inst, def);
    ZodStringFormat.init(inst, def);
  });
  var ZodIPv4 = /* @__PURE__ */ $constructor("ZodIPv4", (inst, def) => {
    $ZodIPv4.init(inst, def);
    ZodStringFormat.init(inst, def);
  });
  var ZodIPv6 = /* @__PURE__ */ $constructor("ZodIPv6", (inst, def) => {
    $ZodIPv6.init(inst, def);
    ZodStringFormat.init(inst, def);
  });
  var ZodCIDRv4 = /* @__PURE__ */ $constructor("ZodCIDRv4", (inst, def) => {
    $ZodCIDRv4.init(inst, def);
    ZodStringFormat.init(inst, def);
  });
  var ZodCIDRv6 = /* @__PURE__ */ $constructor("ZodCIDRv6", (inst, def) => {
    $ZodCIDRv6.init(inst, def);
    ZodStringFormat.init(inst, def);
  });
  var ZodBase64 = /* @__PURE__ */ $constructor("ZodBase64", (inst, def) => {
    $ZodBase64.init(inst, def);
    ZodStringFormat.init(inst, def);
  });
  var ZodBase64URL = /* @__PURE__ */ $constructor("ZodBase64URL", (inst, def) => {
    $ZodBase64URL.init(inst, def);
    ZodStringFormat.init(inst, def);
  });
  var ZodE164 = /* @__PURE__ */ $constructor("ZodE164", (inst, def) => {
    $ZodE164.init(inst, def);
    ZodStringFormat.init(inst, def);
  });
  var ZodJWT = /* @__PURE__ */ $constructor("ZodJWT", (inst, def) => {
    $ZodJWT.init(inst, def);
    ZodStringFormat.init(inst, def);
  });
  var ZodNumber = /* @__PURE__ */ $constructor("ZodNumber", (inst, def) => {
    $ZodNumber.init(inst, def);
    ZodType.init(inst, def);
    inst._zod.processJSONSchema = (ctx, json, params) => numberProcessor(inst, ctx, json, params);
    _installLazyMethods(inst, "ZodNumber", {
      gt(value, params) {
        return this.check(_gt(value, params));
      },
      gte(value, params) {
        return this.check(_gte(value, params));
      },
      min(value, params) {
        return this.check(_gte(value, params));
      },
      lt(value, params) {
        return this.check(_lt(value, params));
      },
      lte(value, params) {
        return this.check(_lte(value, params));
      },
      max(value, params) {
        return this.check(_lte(value, params));
      },
      int(params) {
        return this.check(int(params));
      },
      safe(params) {
        return this.check(int(params));
      },
      positive(params) {
        return this.check(_gt(0, params));
      },
      nonnegative(params) {
        return this.check(_gte(0, params));
      },
      negative(params) {
        return this.check(_lt(0, params));
      },
      nonpositive(params) {
        return this.check(_lte(0, params));
      },
      multipleOf(value, params) {
        return this.check(_multipleOf(value, params));
      },
      step(value, params) {
        return this.check(_multipleOf(value, params));
      },
      finite() {
        return this;
      }
    });
    const bag = inst._zod.bag;
    inst.minValue = Math.max(bag.minimum ?? Number.NEGATIVE_INFINITY, bag.exclusiveMinimum ?? Number.NEGATIVE_INFINITY) ?? null;
    inst.maxValue = Math.min(bag.maximum ?? Number.POSITIVE_INFINITY, bag.exclusiveMaximum ?? Number.POSITIVE_INFINITY) ?? null;
    inst.isInt = (bag.format ?? "").includes("int") || Number.isSafeInteger(bag.multipleOf ?? 0.5);
    inst.isFinite = true;
    inst.format = bag.format ?? null;
  });
  function number2(params) {
    return _number(ZodNumber, params);
  }
  var ZodNumberFormat = /* @__PURE__ */ $constructor("ZodNumberFormat", (inst, def) => {
    $ZodNumberFormat.init(inst, def);
    ZodNumber.init(inst, def);
  });
  function int(params) {
    return _int(ZodNumberFormat, params);
  }
  var ZodBoolean = /* @__PURE__ */ $constructor("ZodBoolean", (inst, def) => {
    $ZodBoolean.init(inst, def);
    ZodType.init(inst, def);
    inst._zod.processJSONSchema = (ctx, json, params) => booleanProcessor(inst, ctx, json, params);
  });
  function boolean2(params) {
    return _boolean(ZodBoolean, params);
  }
  var ZodUnknown = /* @__PURE__ */ $constructor("ZodUnknown", (inst, def) => {
    $ZodUnknown.init(inst, def);
    ZodType.init(inst, def);
    inst._zod.processJSONSchema = (ctx, json, params) => unknownProcessor(inst, ctx, json, params);
  });
  function unknown() {
    return _unknown(ZodUnknown);
  }
  var ZodNever = /* @__PURE__ */ $constructor("ZodNever", (inst, def) => {
    $ZodNever.init(inst, def);
    ZodType.init(inst, def);
    inst._zod.processJSONSchema = (ctx, json, params) => neverProcessor(inst, ctx, json, params);
  });
  function never(params) {
    return _never(ZodNever, params);
  }
  var ZodArray = /* @__PURE__ */ $constructor("ZodArray", (inst, def) => {
    $ZodArray.init(inst, def);
    ZodType.init(inst, def);
    inst._zod.processJSONSchema = (ctx, json, params) => arrayProcessor(inst, ctx, json, params);
    inst.element = def.element;
    _installLazyMethods(inst, "ZodArray", {
      min(n, params) {
        return this.check(_minLength(n, params));
      },
      nonempty(params) {
        return this.check(_minLength(1, params));
      },
      max(n, params) {
        return this.check(_maxLength(n, params));
      },
      length(n, params) {
        return this.check(_length(n, params));
      },
      unwrap() {
        return this.element;
      }
    });
  });
  function array(element, params) {
    return _array(ZodArray, element, params);
  }
  var ZodObject = /* @__PURE__ */ $constructor("ZodObject", (inst, def) => {
    $ZodObjectJIT.init(inst, def);
    ZodType.init(inst, def);
    inst._zod.processJSONSchema = (ctx, json, params) => objectProcessor(inst, ctx, json, params);
    util_exports.defineLazy(inst, "shape", () => {
      return def.shape;
    });
    _installLazyMethods(inst, "ZodObject", {
      keyof() {
        return _enum(Object.keys(this._zod.def.shape));
      },
      catchall(catchall) {
        return this.clone({ ...this._zod.def, catchall });
      },
      passthrough() {
        return this.clone({ ...this._zod.def, catchall: unknown() });
      },
      loose() {
        return this.clone({ ...this._zod.def, catchall: unknown() });
      },
      strict() {
        return this.clone({ ...this._zod.def, catchall: never() });
      },
      strip() {
        return this.clone({ ...this._zod.def, catchall: void 0 });
      },
      extend(incoming) {
        return util_exports.extend(this, incoming);
      },
      safeExtend(incoming) {
        return util_exports.safeExtend(this, incoming);
      },
      merge(other) {
        return util_exports.merge(this, other);
      },
      pick(mask) {
        return util_exports.pick(this, mask);
      },
      omit(mask) {
        return util_exports.omit(this, mask);
      },
      partial(...args) {
        return util_exports.partial(ZodOptional, this, args[0]);
      },
      required(...args) {
        return util_exports.required(ZodNonOptional, this, args[0]);
      }
    });
  });
  function object(shape, params) {
    const def = {
      type: "object",
      shape: shape ?? {},
      ...util_exports.normalizeParams(params)
    };
    return new ZodObject(def);
  }
  var ZodUnion = /* @__PURE__ */ $constructor("ZodUnion", (inst, def) => {
    $ZodUnion.init(inst, def);
    ZodType.init(inst, def);
    inst._zod.processJSONSchema = (ctx, json, params) => unionProcessor(inst, ctx, json, params);
    inst.options = def.options;
  });
  function union(options, params) {
    return new ZodUnion({
      type: "union",
      options,
      ...util_exports.normalizeParams(params)
    });
  }
  var ZodIntersection = /* @__PURE__ */ $constructor("ZodIntersection", (inst, def) => {
    $ZodIntersection.init(inst, def);
    ZodType.init(inst, def);
    inst._zod.processJSONSchema = (ctx, json, params) => intersectionProcessor(inst, ctx, json, params);
  });
  function intersection(left, right) {
    return new ZodIntersection({
      type: "intersection",
      left,
      right
    });
  }
  var ZodEnum = /* @__PURE__ */ $constructor("ZodEnum", (inst, def) => {
    $ZodEnum.init(inst, def);
    ZodType.init(inst, def);
    inst._zod.processJSONSchema = (ctx, json, params) => enumProcessor(inst, ctx, json, params);
    inst.enum = def.entries;
    inst.options = Object.values(def.entries);
    const keys = new Set(Object.keys(def.entries));
    inst.extract = (values, params) => {
      const newEntries = {};
      for (const value of values) {
        if (keys.has(value)) {
          newEntries[value] = def.entries[value];
        } else
          throw new Error(`Key ${value} not found in enum`);
      }
      return new ZodEnum({
        ...def,
        checks: [],
        ...util_exports.normalizeParams(params),
        entries: newEntries
      });
    };
    inst.exclude = (values, params) => {
      const newEntries = { ...def.entries };
      for (const value of values) {
        if (keys.has(value)) {
          delete newEntries[value];
        } else
          throw new Error(`Key ${value} not found in enum`);
      }
      return new ZodEnum({
        ...def,
        checks: [],
        ...util_exports.normalizeParams(params),
        entries: newEntries
      });
    };
  });
  function _enum(values, params) {
    const entries = Array.isArray(values) ? Object.fromEntries(values.map((v) => [v, v])) : values;
    return new ZodEnum({
      type: "enum",
      entries,
      ...util_exports.normalizeParams(params)
    });
  }
  var ZodTransform = /* @__PURE__ */ $constructor("ZodTransform", (inst, def) => {
    $ZodTransform.init(inst, def);
    ZodType.init(inst, def);
    inst._zod.processJSONSchema = (ctx, json, params) => transformProcessor(inst, ctx, json, params);
    inst._zod.parse = (payload, _ctx) => {
      if (_ctx.direction === "backward") {
        throw new $ZodEncodeError(inst.constructor.name);
      }
      payload.addIssue = (issue2) => {
        if (typeof issue2 === "string") {
          payload.issues.push(util_exports.issue(issue2, payload.value, def));
        } else {
          const _issue = issue2;
          if (_issue.fatal)
            _issue.continue = false;
          _issue.code ?? (_issue.code = "custom");
          _issue.input ?? (_issue.input = payload.value);
          _issue.inst ?? (_issue.inst = inst);
          payload.issues.push(util_exports.issue(_issue));
        }
      };
      const output = def.transform(payload.value, payload);
      if (output instanceof Promise) {
        return output.then((output2) => {
          payload.value = output2;
          payload.fallback = true;
          return payload;
        });
      }
      payload.value = output;
      payload.fallback = true;
      return payload;
    };
  });
  function transform(fn) {
    return new ZodTransform({
      type: "transform",
      transform: fn
    });
  }
  var ZodOptional = /* @__PURE__ */ $constructor("ZodOptional", (inst, def) => {
    $ZodOptional.init(inst, def);
    ZodType.init(inst, def);
    inst._zod.processJSONSchema = (ctx, json, params) => optionalProcessor(inst, ctx, json, params);
    inst.unwrap = () => inst._zod.def.innerType;
  });
  function optional(innerType) {
    return new ZodOptional({
      type: "optional",
      innerType
    });
  }
  var ZodExactOptional = /* @__PURE__ */ $constructor("ZodExactOptional", (inst, def) => {
    $ZodExactOptional.init(inst, def);
    ZodType.init(inst, def);
    inst._zod.processJSONSchema = (ctx, json, params) => optionalProcessor(inst, ctx, json, params);
    inst.unwrap = () => inst._zod.def.innerType;
  });
  function exactOptional(innerType) {
    return new ZodExactOptional({
      type: "optional",
      innerType
    });
  }
  var ZodNullable = /* @__PURE__ */ $constructor("ZodNullable", (inst, def) => {
    $ZodNullable.init(inst, def);
    ZodType.init(inst, def);
    inst._zod.processJSONSchema = (ctx, json, params) => nullableProcessor(inst, ctx, json, params);
    inst.unwrap = () => inst._zod.def.innerType;
  });
  function nullable(innerType) {
    return new ZodNullable({
      type: "nullable",
      innerType
    });
  }
  var ZodDefault = /* @__PURE__ */ $constructor("ZodDefault", (inst, def) => {
    $ZodDefault.init(inst, def);
    ZodType.init(inst, def);
    inst._zod.processJSONSchema = (ctx, json, params) => defaultProcessor(inst, ctx, json, params);
    inst.unwrap = () => inst._zod.def.innerType;
    inst.removeDefault = inst.unwrap;
  });
  function _default(innerType, defaultValue) {
    return new ZodDefault({
      type: "default",
      innerType,
      get defaultValue() {
        return typeof defaultValue === "function" ? defaultValue() : util_exports.shallowClone(defaultValue);
      }
    });
  }
  var ZodPrefault = /* @__PURE__ */ $constructor("ZodPrefault", (inst, def) => {
    $ZodPrefault.init(inst, def);
    ZodType.init(inst, def);
    inst._zod.processJSONSchema = (ctx, json, params) => prefaultProcessor(inst, ctx, json, params);
    inst.unwrap = () => inst._zod.def.innerType;
  });
  function prefault(innerType, defaultValue) {
    return new ZodPrefault({
      type: "prefault",
      innerType,
      get defaultValue() {
        return typeof defaultValue === "function" ? defaultValue() : util_exports.shallowClone(defaultValue);
      }
    });
  }
  var ZodNonOptional = /* @__PURE__ */ $constructor("ZodNonOptional", (inst, def) => {
    $ZodNonOptional.init(inst, def);
    ZodType.init(inst, def);
    inst._zod.processJSONSchema = (ctx, json, params) => nonoptionalProcessor(inst, ctx, json, params);
    inst.unwrap = () => inst._zod.def.innerType;
  });
  function nonoptional(innerType, params) {
    return new ZodNonOptional({
      type: "nonoptional",
      innerType,
      ...util_exports.normalizeParams(params)
    });
  }
  var ZodCatch = /* @__PURE__ */ $constructor("ZodCatch", (inst, def) => {
    $ZodCatch.init(inst, def);
    ZodType.init(inst, def);
    inst._zod.processJSONSchema = (ctx, json, params) => catchProcessor(inst, ctx, json, params);
    inst.unwrap = () => inst._zod.def.innerType;
    inst.removeCatch = inst.unwrap;
  });
  function _catch(innerType, catchValue) {
    return new ZodCatch({
      type: "catch",
      innerType,
      catchValue: typeof catchValue === "function" ? catchValue : () => catchValue
    });
  }
  var ZodPipe = /* @__PURE__ */ $constructor("ZodPipe", (inst, def) => {
    $ZodPipe.init(inst, def);
    ZodType.init(inst, def);
    inst._zod.processJSONSchema = (ctx, json, params) => pipeProcessor(inst, ctx, json, params);
    inst.in = def.in;
    inst.out = def.out;
  });
  function pipe(in_, out) {
    return new ZodPipe({
      type: "pipe",
      in: in_,
      out
      // ...util.normalizeParams(params),
    });
  }
  var ZodReadonly = /* @__PURE__ */ $constructor("ZodReadonly", (inst, def) => {
    $ZodReadonly.init(inst, def);
    ZodType.init(inst, def);
    inst._zod.processJSONSchema = (ctx, json, params) => readonlyProcessor(inst, ctx, json, params);
    inst.unwrap = () => inst._zod.def.innerType;
  });
  function readonly(innerType) {
    return new ZodReadonly({
      type: "readonly",
      innerType
    });
  }
  var ZodCustom = /* @__PURE__ */ $constructor("ZodCustom", (inst, def) => {
    $ZodCustom.init(inst, def);
    ZodType.init(inst, def);
    inst._zod.processJSONSchema = (ctx, json, params) => customProcessor(inst, ctx, json, params);
  });
  function refine(fn, _params = {}) {
    return _refine(ZodCustom, fn, _params);
  }
  function superRefine(fn, params) {
    return _superRefine(fn, params);
  }

  // node_modules/zod/v4/classic/external.js
  config(en_default());

  // node_modules/chalk/source/vendor/ansi-styles/index.js
  var ANSI_BACKGROUND_OFFSET = 10;
  var wrapAnsi16 = (offset = 0) => (code) => `\x1B[${code + offset}m`;
  var wrapAnsi256 = (offset = 0) => (code) => `\x1B[${38 + offset};5;${code}m`;
  var wrapAnsi16m = (offset = 0) => (red, green, blue) => `\x1B[${38 + offset};2;${red};${green};${blue}m`;
  var styles = {
    modifier: {
      reset: [0, 0],
      // 21 isn't widely supported and 22 does the same thing
      bold: [1, 22],
      dim: [2, 22],
      italic: [3, 23],
      underline: [4, 24],
      overline: [53, 55],
      inverse: [7, 27],
      hidden: [8, 28],
      strikethrough: [9, 29]
    },
    color: {
      black: [30, 39],
      red: [31, 39],
      green: [32, 39],
      yellow: [33, 39],
      blue: [34, 39],
      magenta: [35, 39],
      cyan: [36, 39],
      white: [37, 39],
      // Bright color
      blackBright: [90, 39],
      gray: [90, 39],
      // Alias of `blackBright`
      grey: [90, 39],
      // Alias of `blackBright`
      redBright: [91, 39],
      greenBright: [92, 39],
      yellowBright: [93, 39],
      blueBright: [94, 39],
      magentaBright: [95, 39],
      cyanBright: [96, 39],
      whiteBright: [97, 39]
    },
    bgColor: {
      bgBlack: [40, 49],
      bgRed: [41, 49],
      bgGreen: [42, 49],
      bgYellow: [43, 49],
      bgBlue: [44, 49],
      bgMagenta: [45, 49],
      bgCyan: [46, 49],
      bgWhite: [47, 49],
      // Bright color
      bgBlackBright: [100, 49],
      bgGray: [100, 49],
      // Alias of `bgBlackBright`
      bgGrey: [100, 49],
      // Alias of `bgBlackBright`
      bgRedBright: [101, 49],
      bgGreenBright: [102, 49],
      bgYellowBright: [103, 49],
      bgBlueBright: [104, 49],
      bgMagentaBright: [105, 49],
      bgCyanBright: [106, 49],
      bgWhiteBright: [107, 49]
    }
  };
  var modifierNames = Object.keys(styles.modifier);
  var foregroundColorNames = Object.keys(styles.color);
  var backgroundColorNames = Object.keys(styles.bgColor);
  var colorNames = [...foregroundColorNames, ...backgroundColorNames];
  function assembleStyles() {
    const codes = /* @__PURE__ */ new Map();
    for (const [groupName, group] of Object.entries(styles)) {
      for (const [styleName, style] of Object.entries(group)) {
        styles[styleName] = {
          open: `\x1B[${style[0]}m`,
          close: `\x1B[${style[1]}m`
        };
        group[styleName] = styles[styleName];
        codes.set(style[0], style[1]);
      }
      Object.defineProperty(styles, groupName, {
        value: group,
        enumerable: false
      });
    }
    Object.defineProperty(styles, "codes", {
      value: codes,
      enumerable: false
    });
    styles.color.close = "\x1B[39m";
    styles.bgColor.close = "\x1B[49m";
    styles.color.ansi = wrapAnsi16();
    styles.color.ansi256 = wrapAnsi256();
    styles.color.ansi16m = wrapAnsi16m();
    styles.bgColor.ansi = wrapAnsi16(ANSI_BACKGROUND_OFFSET);
    styles.bgColor.ansi256 = wrapAnsi256(ANSI_BACKGROUND_OFFSET);
    styles.bgColor.ansi16m = wrapAnsi16m(ANSI_BACKGROUND_OFFSET);
    Object.defineProperties(styles, {
      rgbToAnsi256: {
        value(red, green, blue) {
          if (red === green && green === blue) {
            if (red < 8) {
              return 16;
            }
            if (red > 248) {
              return 231;
            }
            return Math.round((red - 8) / 247 * 24) + 232;
          }
          return 16 + 36 * Math.round(red / 255 * 5) + 6 * Math.round(green / 255 * 5) + Math.round(blue / 255 * 5);
        },
        enumerable: false
      },
      hexToRgb: {
        value(hex) {
          const matches = /[a-f\d]{6}|[a-f\d]{3}/i.exec(hex.toString(16));
          if (!matches) {
            return [0, 0, 0];
          }
          let [colorString] = matches;
          if (colorString.length === 3) {
            colorString = [...colorString].map((character) => character + character).join("");
          }
          const integer2 = Number.parseInt(colorString, 16);
          return [
            /* eslint-disable no-bitwise */
            integer2 >> 16 & 255,
            integer2 >> 8 & 255,
            integer2 & 255
            /* eslint-enable no-bitwise */
          ];
        },
        enumerable: false
      },
      hexToAnsi256: {
        value: (hex) => styles.rgbToAnsi256(...styles.hexToRgb(hex)),
        enumerable: false
      },
      ansi256ToAnsi: {
        value(code) {
          if (code < 8) {
            return 30 + code;
          }
          if (code < 16) {
            return 90 + (code - 8);
          }
          let red;
          let green;
          let blue;
          if (code >= 232) {
            red = ((code - 232) * 10 + 8) / 255;
            green = red;
            blue = red;
          } else {
            code -= 16;
            const remainder = code % 36;
            red = Math.floor(code / 36) / 5;
            green = Math.floor(remainder / 6) / 5;
            blue = remainder % 6 / 5;
          }
          const value = Math.max(red, green, blue) * 2;
          if (value === 0) {
            return 30;
          }
          let result2 = 30 + (Math.round(blue) << 2 | Math.round(green) << 1 | Math.round(red));
          if (value === 2) {
            result2 += 60;
          }
          return result2;
        },
        enumerable: false
      },
      rgbToAnsi: {
        value: (red, green, blue) => styles.ansi256ToAnsi(styles.rgbToAnsi256(red, green, blue)),
        enumerable: false
      },
      hexToAnsi: {
        value: (hex) => styles.ansi256ToAnsi(styles.hexToAnsi256(hex)),
        enumerable: false
      }
    });
    return styles;
  }
  var ansiStyles = assembleStyles();
  var ansi_styles_default = ansiStyles;

  // node_modules/chalk/source/vendor/supports-color/browser.js
  var level = (() => {
    if (!("navigator" in globalThis)) {
      return 0;
    }
    if (globalThis.navigator.userAgentData) {
      const brand = navigator.userAgentData.brands.find(({ brand: brand2 }) => brand2 === "Chromium");
      if (brand && brand.version > 93) {
        return 3;
      }
    }
    if (/\b(Chrome|Chromium)\//.test(globalThis.navigator.userAgent)) {
      return 1;
    }
    return 0;
  })();
  var colorSupport = level !== 0 && {
    level,
    hasBasic: true,
    has256: level >= 2,
    has16m: level >= 3
  };
  var supportsColor = {
    stdout: colorSupport,
    stderr: colorSupport
  };
  var browser_default = supportsColor;

  // node_modules/chalk/source/utilities.js
  function stringReplaceAll(string3, substring, replacer) {
    let index = string3.indexOf(substring);
    if (index === -1) {
      return string3;
    }
    const substringLength = substring.length;
    let endIndex = 0;
    let returnValue = "";
    do {
      returnValue += string3.slice(endIndex, index) + substring + replacer;
      endIndex = index + substringLength;
      index = string3.indexOf(substring, endIndex);
    } while (index !== -1);
    returnValue += string3.slice(endIndex);
    return returnValue;
  }
  function stringEncaseCRLFWithFirstIndex(string3, prefix, postfix, index) {
    let endIndex = 0;
    let returnValue = "";
    do {
      const gotCR = string3[index - 1] === "\r";
      returnValue += string3.slice(endIndex, gotCR ? index - 1 : index) + prefix + (gotCR ? "\r\n" : "\n") + postfix;
      endIndex = index + 1;
      index = string3.indexOf("\n", endIndex);
    } while (index !== -1);
    returnValue += string3.slice(endIndex);
    return returnValue;
  }

  // node_modules/chalk/source/index.js
  var { stdout: stdoutColor, stderr: stderrColor } = browser_default;
  var GENERATOR = /* @__PURE__ */ Symbol("GENERATOR");
  var STYLER = /* @__PURE__ */ Symbol("STYLER");
  var IS_EMPTY = /* @__PURE__ */ Symbol("IS_EMPTY");
  var levelMapping = [
    "ansi",
    "ansi",
    "ansi256",
    "ansi16m"
  ];
  var styles2 = /* @__PURE__ */ Object.create(null);
  var applyOptions = (object2, options = {}) => {
    if (options.level && !(Number.isInteger(options.level) && options.level >= 0 && options.level <= 3)) {
      throw new Error("The `level` option should be an integer from 0 to 3");
    }
    const colorLevel = stdoutColor ? stdoutColor.level : 0;
    object2.level = options.level === void 0 ? colorLevel : options.level;
  };
  var chalkFactory = (options) => {
    const chalk2 = (...strings) => strings.join(" ");
    applyOptions(chalk2, options);
    Object.setPrototypeOf(chalk2, createChalk.prototype);
    return chalk2;
  };
  function createChalk(options) {
    return chalkFactory(options);
  }
  Object.setPrototypeOf(createChalk.prototype, Function.prototype);
  for (const [styleName, style] of Object.entries(ansi_styles_default)) {
    styles2[styleName] = {
      get() {
        const builder = createBuilder(this, createStyler(style.open, style.close, this[STYLER]), this[IS_EMPTY]);
        Object.defineProperty(this, styleName, { value: builder });
        return builder;
      }
    };
  }
  styles2.visible = {
    get() {
      const builder = createBuilder(this, this[STYLER], true);
      Object.defineProperty(this, "visible", { value: builder });
      return builder;
    }
  };
  var getModelAnsi = (model, level2, type, ...arguments_) => {
    if (model === "rgb") {
      if (level2 === "ansi16m") {
        return ansi_styles_default[type].ansi16m(...arguments_);
      }
      if (level2 === "ansi256") {
        return ansi_styles_default[type].ansi256(ansi_styles_default.rgbToAnsi256(...arguments_));
      }
      return ansi_styles_default[type].ansi(ansi_styles_default.rgbToAnsi(...arguments_));
    }
    if (model === "hex") {
      return getModelAnsi("rgb", level2, type, ...ansi_styles_default.hexToRgb(...arguments_));
    }
    return ansi_styles_default[type][model](...arguments_);
  };
  var usedModels = ["rgb", "hex", "ansi256"];
  for (const model of usedModels) {
    styles2[model] = {
      get() {
        const { level: level2 } = this;
        return function(...arguments_) {
          const styler = createStyler(getModelAnsi(model, levelMapping[level2], "color", ...arguments_), ansi_styles_default.color.close, this[STYLER]);
          return createBuilder(this, styler, this[IS_EMPTY]);
        };
      }
    };
    const bgModel = "bg" + model[0].toUpperCase() + model.slice(1);
    styles2[bgModel] = {
      get() {
        const { level: level2 } = this;
        return function(...arguments_) {
          const styler = createStyler(getModelAnsi(model, levelMapping[level2], "bgColor", ...arguments_), ansi_styles_default.bgColor.close, this[STYLER]);
          return createBuilder(this, styler, this[IS_EMPTY]);
        };
      }
    };
  }
  var proto = Object.defineProperties(() => {
  }, {
    ...styles2,
    level: {
      enumerable: true,
      get() {
        return this[GENERATOR].level;
      },
      set(level2) {
        this[GENERATOR].level = level2;
      }
    }
  });
  var createStyler = (open, close, parent) => {
    let openAll;
    let closeAll;
    if (parent === void 0) {
      openAll = open;
      closeAll = close;
    } else {
      openAll = parent.openAll + open;
      closeAll = close + parent.closeAll;
    }
    return {
      open,
      close,
      openAll,
      closeAll,
      parent
    };
  };
  var createBuilder = (self, _styler, _isEmpty) => {
    const builder = (...arguments_) => applyStyle(builder, arguments_.length === 1 ? "" + arguments_[0] : arguments_.join(" "));
    Object.setPrototypeOf(builder, proto);
    builder[GENERATOR] = self;
    builder[STYLER] = _styler;
    builder[IS_EMPTY] = _isEmpty;
    return builder;
  };
  var applyStyle = (self, string3) => {
    if (self.level <= 0 || !string3) {
      return self[IS_EMPTY] ? "" : string3;
    }
    let styler = self[STYLER];
    if (styler === void 0) {
      return string3;
    }
    const { openAll, closeAll } = styler;
    if (string3.includes("\x1B")) {
      while (styler !== void 0) {
        string3 = stringReplaceAll(string3, styler.close, styler.open);
        styler = styler.parent;
      }
    }
    const lfIndex = string3.indexOf("\n");
    if (lfIndex !== -1) {
      string3 = stringEncaseCRLFWithFirstIndex(string3, closeAll, openAll, lfIndex);
    }
    return openAll + string3 + closeAll;
  };
  Object.defineProperties(createChalk.prototype, styles2);
  var chalk = createChalk();
  var chalkStderr = createChalk({ level: stderrColor ? stderrColor.level : 0 });
  var source_default = chalk;

  // node_modules/@page-agent/llms/dist/lib/page-agent-llms.js
  var InvokeErrorTypes = {
    NETWORK_ERROR: "network_error",
    RATE_LIMIT: "rate_limit",
    SERVER_ERROR: "server_error",
    NO_TOOL_CALL: "no_tool_call",
    INVALID_TOOL_ARGS: "invalid_tool_args",
    TOOL_EXECUTION_ERROR: "tool_execution_error",
    INVALID_RESPONSE: "invalid_response",
    INVALID_SCHEMA: "invalid_schema",
    UNKNOWN: "unknown",
    CONFIG_ERROR: "config_error",
    AUTH_ERROR: "auth_error",
    CONTEXT_LENGTH: "context_length",
    CONTENT_FILTER: "content_filter"
  };
  var RETRYABLE_TYPES = [
    InvokeErrorTypes.NETWORK_ERROR,
    InvokeErrorTypes.RATE_LIMIT,
    InvokeErrorTypes.SERVER_ERROR,
    InvokeErrorTypes.NO_TOOL_CALL,
    InvokeErrorTypes.INVALID_TOOL_ARGS,
    InvokeErrorTypes.TOOL_EXECUTION_ERROR,
    InvokeErrorTypes.INVALID_RESPONSE,
    InvokeErrorTypes.INVALID_SCHEMA,
    InvokeErrorTypes.UNKNOWN
  ];
  var InvokeError = class extends Error {
    constructor(type, message, rawError, rawResponse) {
      super(message);
      __publicField(this, "type");
      __publicField(this, "retryable");
      __publicField(this, "statusCode");
      __publicField(this, "rawError");
      __publicField(this, "rawResponse");
      this.name = "InvokeError";
      this.type = type;
      this.retryable = RETRYABLE_TYPES.includes(type);
      this.rawError = rawError;
      this.rawResponse = rawResponse;
    }
  };
  var debug = console.debug.bind(console, source_default.gray("[LLM]"));
  function zodToOpenAITool(name, tool2) {
    return {
      type: "function",
      function: {
        name,
        description: tool2.description,
        parameters: toJSONSchema(tool2.inputSchema, { target: "openapi-3.0" })
      }
    };
  }
  function modelPatch(body, baseURL) {
    const model = body.model || "";
    if (!model) return body;
    const provider = getProvider(baseURL);
    const modelName = normalizeModelName(model);
    if (modelName.startsWith("qwen")) {
      debug("Patch Qwen: disable thinking");
      body.enable_thinking = false;
      if (body.temperature === void 0 && !/max|plus/.test(modelName)) {
        debug("Patch Qwen: raise temperature to 1.0");
        body.temperature = 1;
      }
    }
    if (modelName.startsWith("deepseek")) {
      debug("Patch DeepSeek: disable thinking, remove tool_choice");
      body.thinking = { type: "disabled" };
      delete body.tool_choice;
    }
    if (modelName.startsWith("gpt")) {
      if (modelName.startsWith("gpt-5")) {
        body.verbosity = "low";
        body.reasoning_effort = /^gpt-5(-|$)/.test(modelName) ? "minimal" : "none";
        debug(`Patch GPT-5: verbosity=low, reasoning_effort=${body.reasoning_effort}`);
      }
      if (modelName.includes("chat-latest")) {
        debug("Omitting reasoning_effort and temperature for chat-latest");
        delete body.reasoning_effort;
        delete body.temperature;
      }
    }
    if (modelName.startsWith("claude")) if (/opus|sonnet|haiku/.test(modelName)) {
      debug("Patch Claude: disable thinking");
      body.thinking = { type: "disabled" };
      if (provider !== "openrouter") {
        if (body.tool_choice === "required") {
          debug('Applying Claude patch: convert tool_choice "required" to { type: "any" }');
          body.tool_choice = { type: "any" };
        } else if (body.tool_choice?.function?.name) {
          debug("Applying Claude patch: convert tool_choice format");
          body.tool_choice = {
            type: "tool",
            name: body.tool_choice.function.name
          };
        }
      }
    } else {
      debug("Patch Claude: reasoning_effort=low");
      body.reasoning_effort = "low";
      delete body.tool_choice;
    }
    if (modelName.startsWith("gemini")) {
      debug("Patch Gemini: reasoning_effort=low");
      body.reasoning_effort = "low";
      if (/^gemini-25(?!.*pro)/.test(modelName)) {
        debug("Patch Gemini 2.5 non-Pro: reasoning_effort=none");
        body.reasoning_effort = "none";
      } else if (modelName.startsWith("gemini-35-flash") || modelName.startsWith("gemini-31-flash-lite") || modelName.startsWith("gemini-3-flash")) {
        debug("Patch Gemini 3.x Flash/Lite: reasoning_effort=minimal");
        body.reasoning_effort = "minimal";
      }
    }
    if (modelName.startsWith("glm")) {
      debug("Patch GLM: disable thinking");
      body.thinking = { type: "disabled" };
    }
    if (modelName.startsWith("grok")) {
      if (/^grok-4-?3/.test(modelName)) {
        debug("Patch Grok 4.3: reasoning_effort=none");
        body.reasoning_effort = "none";
      } else if (modelName.startsWith("grok-3-mini") || modelName.startsWith("grok-code-fast")) {
        debug("Patch Grok mini/code: reasoning_effort=low");
        body.reasoning_effort = "low";
      }
    }
    if (modelName.startsWith("kimi")) {
      if (!modelName.includes("code")) {
        debug("Patch Kimi: disable thinking");
        body.thinking = { type: "disabled" };
      }
    }
    if (modelName.startsWith("minimax")) {
      debug("Patch MiniMax: remove parallel_tool_calls");
      delete body.parallel_tool_calls;
      if (modelName.includes("m3")) {
        debug("Patch MiniMax: disable thinking");
        body.thinking = { type: "disabled" };
      }
    }
    if (provider === "openrouter") {
      const reasoningEffort = body.reasoning_effort;
      if (body.thinking?.type === "disabled" || body.enable_thinking === false || reasoningEffort === "none") body.reasoning = { enabled: false };
      else if (reasoningEffort) body.reasoning = {
        enabled: true,
        effort: reasoningEffort
      };
    }
    return body;
  }
  function normalizeModelName(modelName) {
    let normalizedName = modelName.toLowerCase();
    if (normalizedName.includes("/")) normalizedName = normalizedName.split("/")[1];
    normalizedName = normalizedName.replace(/_/g, "");
    normalizedName = normalizedName.replace(/\./g, "");
    return normalizedName;
  }
  function getProvider(baseURL) {
    if (!baseURL) return void 0;
    try {
      if (new URL(baseURL).hostname === "openrouter.ai") return "openrouter";
      return;
    } catch (e) {
      return;
    }
  }
  var OpenAIClient = class {
    constructor(config2) {
      __publicField(this, "config");
      __publicField(this, "fetch");
      this.config = config2;
      this.fetch = config2.customFetch;
    }
    async invoke(messages, tools2, abortSignal, options) {
      abortSignal?.throwIfAborted();
      const openaiTools = Object.entries(tools2).map(([name, t]) => zodToOpenAITool(name, t));
      let toolChoice = "required";
      if (options?.toolChoiceName && !this.config.disableNamedToolChoice) toolChoice = {
        type: "function",
        function: { name: options.toolChoiceName }
      };
      const requestBody = {
        model: this.config.model,
        messages,
        tools: openaiTools,
        parallel_tool_calls: false,
        tool_choice: toolChoice
      };
      if (this.config.temperature !== void 0) requestBody.temperature = this.config.temperature;
      modelPatch(requestBody, this.config.baseURL);
      let transformedBody;
      try {
        transformedBody = this.config.transformRequestBody(requestBody);
      } catch (error2) {
        throw new InvokeError(InvokeErrorTypes.CONFIG_ERROR, `transformRequestBody failed: ${error2.message}`, error2);
      }
      const finalRequestBody = transformedBody ?? requestBody;
      let response;
      try {
        response = await this.fetch(`${this.config.baseURL}/chat/completions`, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            ...this.config.apiKey && { Authorization: `Bearer ${this.config.apiKey}` }
          },
          body: JSON.stringify(finalRequestBody),
          signal: abortSignal
        });
      } catch (error2) {
        if (error2?.name === "AbortError") throw error2;
        console.error(error2);
        throw new InvokeError(InvokeErrorTypes.NETWORK_ERROR, "Network request failed", error2);
      }
      if (!response.ok) {
        let errorData;
        try {
          errorData = await response.json();
        } catch (error2) {
          if (error2?.name === "AbortError") throw error2;
        }
        const errorMessage = errorData?.error?.message || response.statusText;
        if (response.status === 401 || response.status === 403) throw new InvokeError(InvokeErrorTypes.AUTH_ERROR, `Authentication failed: ${errorMessage}`, errorData);
        if (response.status === 429) throw new InvokeError(InvokeErrorTypes.RATE_LIMIT, `Rate limit exceeded: ${errorMessage}`, errorData);
        if (response.status >= 500) throw new InvokeError(InvokeErrorTypes.SERVER_ERROR, `Server error: ${errorMessage}`, errorData);
        throw new InvokeError(InvokeErrorTypes.UNKNOWN, `HTTP ${response.status}: ${errorMessage}`, errorData);
      }
      let data;
      try {
        data = await response.json();
      } catch (error2) {
        if (error2?.name === "AbortError") throw error2;
        throw new InvokeError(InvokeErrorTypes.INVALID_RESPONSE, "Response body is not valid JSON", error2);
      }
      const choice = data.choices?.[0];
      if (!choice) throw new InvokeError(InvokeErrorTypes.INVALID_SCHEMA, "No choices in response", data);
      switch (choice.finish_reason) {
        case "tool_calls":
        case "function_call":
        case "stop":
          break;
        case "length":
          throw new InvokeError(InvokeErrorTypes.CONTEXT_LENGTH, "Response truncated: max tokens reached", void 0, data);
        case "content_filter":
          throw new InvokeError(InvokeErrorTypes.CONTENT_FILTER, "Content filtered by safety system", void 0, data);
        default:
          throw new InvokeError(InvokeErrorTypes.INVALID_SCHEMA, `Unexpected finish_reason: ${choice.finish_reason}`, void 0, data);
      }
      const normalizedChoice = (options?.normalizeResponse ? options.normalizeResponse(data) : data).choices?.[0];
      const toolCallName = normalizedChoice?.message?.tool_calls?.[0]?.function?.name;
      if (!toolCallName) throw new InvokeError(InvokeErrorTypes.NO_TOOL_CALL, "No tool call found in response", void 0, data);
      const tool2 = tools2[toolCallName];
      if (!tool2) throw new InvokeError(InvokeErrorTypes.UNKNOWN, `Tool "${toolCallName}" not found in tools`, void 0, data);
      const argString = normalizedChoice.message?.tool_calls?.[0]?.function?.arguments;
      if (!argString) throw new InvokeError(InvokeErrorTypes.INVALID_TOOL_ARGS, "No tool call arguments found", void 0, data);
      let parsedArgs;
      try {
        parsedArgs = JSON.parse(argString);
      } catch (error2) {
        throw new InvokeError(InvokeErrorTypes.INVALID_TOOL_ARGS, "Failed to parse tool arguments as JSON", error2, data);
      }
      const validation = tool2.inputSchema.safeParse(parsedArgs);
      if (!validation.success) {
        console.error(prettifyError(validation.error));
        throw new InvokeError(InvokeErrorTypes.INVALID_TOOL_ARGS, "Tool arguments validation failed", validation.error, data);
      }
      const toolInput = validation.data;
      let toolResult;
      try {
        toolResult = await tool2.execute(toolInput);
      } catch (error2) {
        if (error2?.name === "AbortError") throw error2;
        throw new InvokeError(InvokeErrorTypes.TOOL_EXECUTION_ERROR, `Tool execution failed: ${error2?.message}`, error2, data);
      }
      return {
        toolCall: {
          name: toolCallName,
          args: toolInput
        },
        toolResult,
        usage: {
          promptTokens: data.usage?.prompt_tokens ?? 0,
          completionTokens: data.usage?.completion_tokens ?? 0,
          totalTokens: data.usage?.total_tokens ?? 0,
          cachedTokens: data.usage?.prompt_tokens_details?.cached_tokens,
          reasoningTokens: data.usage?.completion_tokens_details?.reasoning_tokens
        },
        rawResponse: data,
        rawRequest: finalRequestBody
      };
    }
  };
  var LLM = class extends EventTarget {
    constructor(config2) {
      super();
      __publicField(this, "config");
      __publicField(this, "client");
      this.config = parseLLMConfig(config2);
      this.client = new OpenAIClient(this.config);
    }
    /**
    * - call llm api *once*
    * - invoke tool call *once*
    * - return the result of the tool
    */
    async invoke(messages, tools2, abortSignal, options) {
      return await withRetry(async () => this.client.invoke(messages, tools2, abortSignal, options), {
        maxRetries: this.config.maxRetries,
        onRetry: (attempt, lastError) => {
          this.dispatchEvent(new CustomEvent("retry", { detail: {
            attempt,
            maxAttempts: this.config.maxRetries,
            lastError
          } }));
        }
      });
    }
  };
  async function withRetry(fn, settings) {
    let attempt = 0;
    while (true) try {
      return await fn();
    } catch (error2) {
      if (error2?.name === "AbortError") throw error2;
      if (error2 instanceof InvokeError && !error2.retryable) throw error2;
      attempt++;
      if (attempt > settings.maxRetries) throw error2;
      console.debug("[LLM] retryable failure, will retry:", error2);
      settings.onRetry(attempt, error2);
      await new Promise((resolve) => setTimeout(resolve, 100));
    }
  }
  function parseLLMConfig(config2) {
    if (!config2.baseURL || !config2.model) throw new Error("[PageAgent] LLM configuration required. Please provide: baseURL, model. See: https://alibaba.github.io/page-agent/docs/features/models");
    if (config2.temperature !== void 0) console.warn("[PageAgent] LLMConfig.temperature is deprecated and will be removed in a future version. Use transformRequestBody to set it only for models you have verified accept it.");
    return {
      baseURL: config2.baseURL,
      model: config2.model,
      apiKey: config2.apiKey || "",
      temperature: config2.temperature,
      maxRetries: config2.maxRetries ?? 2,
      transformRequestBody: config2.transformRequestBody ?? ((requestBody) => requestBody),
      disableNamedToolChoice: config2.disableNamedToolChoice ?? false,
      customFetch: (config2.customFetch ?? fetch).bind(globalThis)
    };
  }

  // node_modules/@page-agent/core/dist/esm/page-agent-core.js
  var system_prompt_default = 'You are an AI agent designed to operate in an iterative loop to automate browser tasks. Your ultimate goal is accomplishing the task provided in <user_request>.\n\n<intro>\nYou excel at following tasks:\n1. Navigating complex websites and extracting precise information\n2. Automating form submissions and interactive web actions\n3. Gathering and saving information \n4. Operate effectively in an agent loop\n5. Efficiently performing diverse web tasks\n</intro>\n\n<language_settings>\n- Default working language: **English**\n- Use the language that user is using. Return in user\'s language.\n</language_settings>\n\n<input>\nAt every step, your input will consist of: \n1. <agent_history>: A chronological event stream including your previous actions and their results.\n2. <agent_state>: Current <user_request> and <step_info>.\n3. <browser_state>: Current URL, interactive elements indexed for actions, and visible page content.\n</input>\n\n<agent_history>\nAgent history will be given as a list of step information as follows:\n\n<step_{step_number}>:\nEvaluation of Previous Step: Assessment of last action\nMemory: Your memory of this step\nNext Goal: Your goal for this step\nAction Results: Your actions and their results\n</step_{step_number}>\n\nand system messages wrapped in <sys> tag.\n</agent_history>\n\n<user_request>\nUSER REQUEST: This is your ultimate objective and always remains visible.\n- This has the highest priority. Make the user happy.\n- If the user request is very specific - then carefully follow each step and don\'t skip or hallucinate steps.\n- If the task is open ended you can plan yourself how to get it done.\n</user_request>\n\n<browser_state>\n1. Browser State will be given as:\n\nCurrent URL: URL of the page you are currently viewing.\nInteractive Elements: All interactive elements will be provided in format as [index]<type>text</type> where\n- index: Numeric identifier for interaction\n- type: HTML element type (button, input, etc.)\n- text: Element description\n\nExamples:\n[33]<div>User form</div>\n\\t*[35]<button aria-label=\'Submit form\'>Submit</button>\n\nNote that:\n- Only elements with numeric indexes in [] are interactive\n- (stacked) indentation (with \\t) is important and means that the element is a (html) child of the element above (with a lower index)\n- Elements tagged with `*[` are the new clickable elements that appeared on the website since the last step - if url has not changed.\n- Pure text elements without [] are not interactive.\n</browser_state>\n\n<browser_rules>\nStrictly follow these rules while using the browser and navigating the web:\n- Only interact with elements that have a numeric [index] assigned.\n- Only use indexes that are explicitly provided.\n- If the page changes after, for example, an input text action, analyze if you need to interact with new elements, e.g. selecting the right option from the list.\n- By default, only elements in the visible viewport are listed. Use scrolling actions if you suspect relevant content is offscreen which you need to interact with. Scroll ONLY if there are more pixels below or above the page.\n- You can scroll by a specific number of pages using the num_pages parameter (e.g., 0.5 for half page, 2.0 for two pages).\n- All the elements that are scrollable are marked with `data-scrollable` attribute. Including the scrollable distance in every directions. You can scroll *the element* in case some area are overflowed.\n- If a captcha appears, tell user you can not solve captcha. Finish the task and ask user to solve it.\n- If the page is not fully loaded, use the `wait` action.\n- Do not repeat one action for more than 3 times unless some conditions changed.\n- If you fill an input field and your action sequence is interrupted, most often something changed e.g. suggestions popped up under the field.\n- If the <user_request> includes specific page information such as product type, rating, price, location, etc., try to apply filters to be more efficient.\n- The <user_request> is the ultimate goal. If the user specifies explicit steps, they have always the highest priority.\n- If you input_text into a field, you might need to press enter, click the search button, or select from dropdown for completion.\n- Don\'t login into a page if you don\'t have to. Don\'t login if you don\'t have the credentials. \n- There are 2 types of tasks always first think which type of request you are dealing with:\n1. Very specific step by step instructions:\n- Follow them as very precise and don\'t skip steps. Try to complete everything as requested.\n2. Open ended tasks. Plan yourself, be creative in achieving them.\n- If you get stuck e.g. with logins or captcha in open-ended tasks you can re-evaluate the task and try alternative ways, e.g. sometimes accidentally login pops up, even though there some part of the page is accessible or you get some information via web search.\n</browser_rules>\n\n<capability>\n- You can only handle single page app. Do not jump out of current page.\n- Do not click on link if it will open in a new page (e.g., <a target="_blank">)\n- It is ok to fail the task.\n	- User can be wrong. If the request of user is not achievable, inappropriate or you do not have enough information or tools to achieve it. Tell user to make a better request.\n	- Webpage can be broken. All webpages or apps have bugs. Some bug will make it hard for your job. It\'s encouraged to tell user the problem of current page. Your feedbacks (including failing) are valuable for user.\n	- Trying too hard can be harmful. Repeating some action back and forth or pushing for a complex procedure with little knowledge can cause unwanted results and harmful side-effects. User would rather you complete the task with a fail.\n- If you do not have knowledge for the current webpage or task. You must require user to give specific instructions and detailed steps.\n</capability>\n\n<task_completion_rules>\nYou must call the `done` action in one of three cases:\n- When you have fully completed the USER REQUEST.\n- When you reach the final allowed step (`max_steps`), even if the task is incomplete.\n- When you feel stuck or unable to solve user request. Or user request is not clear or contains inappropriate content.\n- If it is ABSOLUTELY IMPOSSIBLE to continue.\n\nThe `done` action is your opportunity to terminate and share your findings with the user.\n- Set `success` to `true` only if the full USER REQUEST has been completed with no missing components.\n- If any part of the request is missing, incomplete, or uncertain, set `success` to `false`.\n- You can use the `text` field of the `done` action to communicate your findings and to provide a coherent reply to the user and fulfill the USER REQUEST.\n- You are ONLY ALLOWED to call `done` as a single action. Don\'t call it together with other actions.\n- If the user asks for specified format, such as "return JSON with following structure", "return a list of format...", MAKE sure to use the right format in your answer.\n- If the user asks for a structured output, your `done` action\'s schema may be modified. Take this schema into account when solving the task!\n</task_completion_rules>\n\n<reasoning_rules>\nExhibit the following reasoning patterns to successfully achieve the <user_request>:\n\n- Reason about <agent_history> to track progress and context toward <user_request>.\n- Analyze the most recent "Next Goal" and "Action Result" in <agent_history> and clearly state what you previously tried to achieve.\n- Analyze all relevant items in <agent_history> and <browser_state> to understand your state.\n- Explicitly judge success/failure/uncertainty of the last action. Never assume an action succeeded just because it appears to be executed in your last step in <agent_history>. If the expected change is missing, mark the last action as failed (or uncertain) and plan a recovery.\n- Analyze whether you are stuck, e.g. when you repeat the same actions multiple times without any progress. Then consider alternative approaches e.g. scrolling for more context or ask user for help.\n- Ask user for help if you have any difficulty. Keep user in the loop.\n- If you see information relevant to <user_request>, plan saving the information to memory.\n- Always reason about the <user_request>. Make sure to carefully analyze the specific steps and information required. E.g. specific filters, specific form fields, specific information to search. Make sure to always compare the current trajectory with the user request and think carefully if thats how the user requested it.\n</reasoning_rules>\n\n<examples>\nHere are examples of good output patterns. Use them as reference but never copy them directly.\n\n<evaluation_examples>\n"evaluation_previous_goal": "Successfully navigated to the product page and found the target information. Verdict: Success"\n"evaluation_previous_goal": "Clicked the login button and user authentication form appeared. Verdict: Success"\n</evaluation_examples>\n\n<memory_examples>\n"memory": "Found many pending reports that need to be analyzed in the main page. Successfully processed the first 2 reports on quarterly sales data and moving on to inventory analysis and customer feedback reports."\n</memory_examples>\n\n<next_goal_examples>\n"next_goal": "Click on the \'Add to Cart\' button to proceed with the purchase flow."\n</next_goal_examples>\n</examples>\n\n<output>\n{\n  "evaluation_previous_goal": "Concise one-sentence analysis of your last action. Clearly state success, failure, or uncertain.",\n  "memory": "1-3 concise sentences of specific memory of this step and overall progress. You should put here everything that will help you track progress in future steps. Like counting pages visited, items found, etc.",\n  "next_goal": "State the next immediate goal and action to achieve it, in one clear sentence.",\n  "action":{\n    "Action name": {// Action parameters}\n  }\n}\n</output>\n';
  var log = console.log.bind(console, source_default.yellow("[autoFixer]"));
  function normalizeResponse(response, tools2) {
    let resolvedArguments;
    const choice = response.choices?.[0];
    if (!choice) throw new Error("No choices in response");
    const message = choice.message;
    if (!message) throw new Error("No message in choice");
    const toolCall = message.tool_calls?.[0];
    if (toolCall?.function?.arguments) {
      resolvedArguments = safeJsonParse(toolCall.function.arguments);
      if (toolCall.function.name && toolCall.function.name !== "AgentOutput") {
        log(`#1: fixing tool_call`);
        resolvedArguments = { action: safeJsonParse(resolvedArguments) };
      }
    } else if (message.content) {
      const jsonInContent = retrieveJsonFromString(message.content.trim());
      if (jsonInContent) {
        resolvedArguments = safeJsonParse(jsonInContent);
        if (resolvedArguments?.name === "AgentOutput") {
          log(`#2: fixing tool_call`);
          resolvedArguments = safeJsonParse(resolvedArguments.arguments);
        }
        if (resolvedArguments?.type === "function") {
          log(`#3: fixing tool_call`);
          resolvedArguments = safeJsonParse(resolvedArguments.function.arguments);
        }
        if (!resolvedArguments?.action && !resolvedArguments?.evaluation_previous_goal && !resolvedArguments?.memory && !resolvedArguments?.next_goal && !resolvedArguments?.thinking) {
          log(`#4: fixing tool_call`);
          resolvedArguments = { action: safeJsonParse(resolvedArguments) };
        }
      } else throw new Error("No tool_call and the message content does not contain valid JSON");
    } else throw new Error("No tool_call nor message content is present");
    resolvedArguments = safeJsonParse(resolvedArguments);
    if (resolvedArguments.action) resolvedArguments.action = safeJsonParse(resolvedArguments.action);
    if (resolvedArguments.action && tools2) resolvedArguments.action = validateAction(resolvedArguments.action, tools2);
    if (!resolvedArguments.action) {
      log(`#5: fixing tool_call`);
      resolvedArguments.action = { wait: { seconds: 1 } };
    }
    return {
      ...response,
      choices: [{
        ...choice,
        message: {
          ...message,
          tool_calls: [{
            ...toolCall || {},
            function: {
              ...toolCall?.function || {},
              name: "AgentOutput",
              arguments: JSON.stringify(resolvedArguments)
            }
          }]
        }
      }]
    };
  }
  function validateAction(action, tools2) {
    if (typeof action !== "object" || action === null) return action;
    const toolName = Object.keys(action)[0];
    if (!toolName) return action;
    const tool2 = tools2.get(toolName);
    if (!tool2) {
      const available = Array.from(tools2.keys()).join(", ");
      throw new InvokeError(InvokeErrorTypes.INVALID_TOOL_ARGS, `Unknown action "${toolName}". Available: ${available}`);
    }
    let value = action[toolName];
    const schema = tool2.inputSchema;
    if (schema instanceof ZodObject && value !== null && typeof value !== "object") {
      const requiredKey = Object.keys(schema.shape).find((k) => !schema.shape[k].safeParse(void 0).success);
      if (requiredKey) {
        log(`coercing primitive action input for "${toolName}"`);
        value = { [requiredKey]: value };
      }
    }
    const result2 = schema.safeParse(value);
    if (!result2.success) throw new InvokeError(InvokeErrorTypes.INVALID_TOOL_ARGS, `Invalid input for action "${toolName}": ${prettifyError(result2.error)}`);
    return { [toolName]: result2.data };
  }
  function safeJsonParse(input) {
    if (typeof input === "string") try {
      return JSON.parse(input.trim());
    } catch {
      return input;
    }
    return input;
  }
  function retrieveJsonFromString(str) {
    try {
      const json = /({[\s\S]*})/.exec(str) ?? [];
      if (json.length === 0) return null;
      return JSON.parse(json[0]);
    } catch {
      return null;
    }
  }
  async function waitFor(seconds, signal2) {
    if (!signal2) {
      await new Promise((resolve) => setTimeout(resolve, seconds * 1e3));
      return;
    }
    signal2.throwIfAborted();
    await new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        signal2.removeEventListener("abort", onAbort);
        resolve();
      }, seconds * 1e3);
      const onAbort = () => {
        clearTimeout(timer);
        reject(signal2.reason);
      };
      signal2.addEventListener("abort", onAbort, { once: true });
    });
  }
  function truncate(text, maxLength) {
    if (text.length > maxLength) return text.substring(0, maxLength) + "...";
    return text;
  }
  function randomID(existingIDs) {
    let id = Math.random().toString(36).substring(2, 11);
    if (!existingIDs) return id;
    const MAX_TRY = 1e3;
    let tryCount = 0;
    while (existingIDs.includes(id)) {
      id = Math.random().toString(36).substring(2, 11);
      tryCount++;
      if (tryCount > MAX_TRY) throw new Error("randomID: too many tries");
    }
    return id;
  }
  var _global = globalThis;
  if (!_global.__PAGE_AGENT_IDS__) _global.__PAGE_AGENT_IDS__ = [];
  var ids = _global.__PAGE_AGENT_IDS__;
  function uid() {
    const id = randomID(ids);
    ids.push(id);
    return id;
  }
  var llmsTxtCache = /* @__PURE__ */ new Map();
  async function fetchLlmsTxt(url) {
    let origin;
    try {
      origin = new URL(url).origin;
    } catch {
      return null;
    }
    if (origin === "null") return null;
    if (llmsTxtCache.has(origin)) return llmsTxtCache.get(origin);
    const endpoint = `${origin}/llms.txt`;
    let result2 = null;
    try {
      console.log(source_default.gray(`[llms.txt] Fetching ${endpoint}`));
      const res = await fetch(endpoint, { signal: AbortSignal.timeout(3e3) });
      if (res.ok) {
        result2 = await res.text();
        console.log(source_default.green(`[llms.txt] Found (${result2.length} chars)`));
        if (result2.length > 1e3) {
          console.log(source_default.yellow(`[llms.txt] Truncating to 1000 chars`));
          result2 = truncate(result2, 1e3);
        }
      } else console.debug(source_default.gray(`[llms.txt] ${res.status} for ${endpoint}`));
    } catch (e) {
      console.debug(source_default.gray(`[llms.txt] not found for ${endpoint}`), e);
    }
    llmsTxtCache.set(origin, result2);
    return result2;
  }
  function assert2(condition, message, silent) {
    if (!condition) {
      const errorMessage = message ?? "Assertion failed";
      if (!silent) console.error(source_default.red(`\u274C assert: ${errorMessage}`));
      throw new Error(errorMessage);
    }
  }
  async function suppress(fn) {
    try {
      return await fn();
    } catch (error2) {
      console.error(error2);
      return;
    }
  }
  function tool(options) {
    return options;
  }
  var tools = /* @__PURE__ */ new Map();
  tools.set("done", tool({
    description: "Complete task. Text is your final response to the user \u2014 keep it concise unless the user explicitly asks for detail.",
    inputSchema: object({
      text: string2(),
      success: boolean2().default(true)
    }),
    execute: async function(input) {
      return Promise.resolve("Task completed");
    }
  }));
  tools.set("wait", tool({
    description: "Wait for x seconds. Can be used to wait until the page or data is fully loaded.",
    inputSchema: object({ seconds: number2().min(1).max(10).default(1) }),
    execute: async function(input, { signal: signal2 }) {
      const lastTimeUpdate = await this.pageController.getLastUpdateTime();
      const secondsSinceLastUpdate = (Date.now() - lastTimeUpdate) / 1e3;
      const actualWaitTime = Math.max(0, input.seconds - secondsSinceLastUpdate);
      console.log(`actualWaitTime: ${actualWaitTime} seconds`);
      await waitFor(actualWaitTime, signal2);
      return `\u2705 Waited for ${(secondsSinceLastUpdate + actualWaitTime).toFixed(2)} seconds.`;
    }
  }));
  tools.set("ask_user", tool({
    description: "Ask the user a question and wait for their answer. Use this if you need more information or clarification.",
    inputSchema: object({ question: string2() }),
    execute: async function(input, { signal: signal2 }) {
      if (!this.onAskUser) throw new Error("ask_user tool requires onAskUser callback to be set");
      return `User answered: ${await this.onAskUser(input.question, { signal: signal2 })}`;
    }
  }));
  tools.set("click_element_by_index", tool({
    description: "Click element by index",
    inputSchema: object({ index: int().min(0) }),
    execute: async function(input) {
      return (await this.pageController.clickElement(input.index)).message;
    }
  }));
  tools.set("input_text", tool({
    description: "Click and type text into an interactive input element",
    inputSchema: object({
      index: int().min(0),
      text: string2()
    }),
    execute: async function(input) {
      return (await this.pageController.inputText(input.index, input.text)).message;
    }
  }));
  tools.set("select_dropdown_option", tool({
    description: "Select dropdown option for interactive element index by the text of the option you want to select",
    inputSchema: object({
      index: int().min(0),
      text: string2()
    }),
    execute: async function(input) {
      return (await this.pageController.selectOption(input.index, input.text)).message;
    }
  }));
  tools.set("scroll", tool({
    description: "Scroll vertically. Without index: scrolls the document. With index: scrolls the container at that index (or its nearest scrollable ancestor). Use index of a data-scrollable element to scroll a specific area.",
    inputSchema: object({
      down: boolean2().default(true),
      num_pages: number2().min(0).max(10).optional().default(0.1),
      pixels: number2().int().min(0).optional(),
      index: number2().int().min(0).optional()
    }),
    execute: async function(input) {
      return (await this.pageController.scroll({
        ...input,
        numPages: input.num_pages
      })).message;
    }
  }));
  tools.set("scroll_horizontally", tool({
    description: "Scroll horizontally. Without index: scrolls the document. With index: scrolls the container at that index (or its nearest scrollable ancestor). Use index of a data-scrollable element to scroll a specific area.",
    inputSchema: object({
      right: boolean2().default(true),
      pixels: number2().int().min(0),
      index: number2().int().min(0).optional()
    }),
    execute: async function(input) {
      return (await this.pageController.scrollHorizontally(input)).message;
    }
  }));
  tools.set("execute_javascript", tool({
    description: "Execute JavaScript code on the current page. Supports async/await syntax. Use with caution! An `AbortSignal` named `signal` is available in scope: long-running async code MUST honor it (e.g. `await fetch(url, { signal })`, or `signal.throwIfAborted()` in loops)",
    inputSchema: object({ script: string2() }),
    execute: async function(input, { signal: signal2 }) {
      const result2 = await this.pageController.executeJavascript(input.script, signal2);
      signal2.throwIfAborted();
      return result2.message;
    }
  }));
  var _status, _llm, _abortController, _observations, _running, _lastResult, _states, _PageAgentCore_instances, emitStatusChange_fn, emitHistoryChange_fn, emitActivity_fn, setStatus_fn, packMacroTool_fn, getSystemPrompt_fn, getInstructions_fn, handleObservations_fn, assembleUserPrompt_fn, _a3;
  var PageAgentCore = (_a3 = class extends EventTarget {
    constructor(config2) {
      super();
      __privateAdd(this, _PageAgentCore_instances);
      __publicField(this, "id", uid());
      __publicField(this, "config");
      __publicField(this, "tools");
      /** PageController for DOM operations */
      __publicField(this, "pageController");
      __publicField(this, "task", "");
      __publicField(this, "taskId", "");
      /** History events */
      __publicField(this, "history", []);
      /** Whether this agent has been disposed */
      __publicField(this, "disposed", false);
      /**
      * Called when the agent needs to ask the user questions.
      * If unset, the `ask_user` tool will be disabled.
      * Implementations should reject the promise when `signal` aborts.
      * @example onAskUser: (q) => window.prompt(q) || ''
      */
      __publicField(this, "onAskUser");
      __privateAdd(this, _status, "idle");
      __privateAdd(this, _llm);
      /**
      * Task cancellation primitive: its signal reaches the LLM fetch, tools
      * (via `ctx.signal`) and async callbacks. Aborted only by `stop`/`dispose`
      * (during a task) or task setup, always WITHOUT a reason so `signal.reason`
      * stays a standard `AbortError`.
      */
      __privateAdd(this, _abortController, new AbortController());
      __privateAdd(this, _observations, []);
      /** Resolves when the current run has fully settled. Awaited by `stop()`. */
      __privateAdd(this, _running, Promise.resolve());
      __privateAdd(this, _lastResult, null);
      /** internal states during a single task execution */
      __privateAdd(this, _states, {
        /** Accumulated wait time in seconds */
        totalWaitTime: 0,
        /** For detecting navigation */
        lastURL: "",
        /** Browser state */
        browserState: null
      });
      this.config = {
        ...config2,
        maxSteps: config2.maxSteps ?? 40
      };
      __privateSet(this, _llm, new LLM(this.config));
      this.tools = new Map(tools);
      this.pageController = config2.pageController;
      __privateGet(this, _llm).addEventListener("retry", (e) => {
        const { attempt, maxAttempts, lastError } = e.detail;
        __privateMethod(this, _PageAgentCore_instances, emitActivity_fn).call(this, {
          type: "retrying",
          attempt,
          maxAttempts
        });
        this.history.push({
          type: "error",
          message: String(lastError),
          rawResponse: lastError.rawResponse
        });
        this.history.push({
          type: "retry",
          message: `LLM retry attempt ${attempt} of ${maxAttempts}`,
          attempt,
          maxAttempts
        });
        __privateMethod(this, _PageAgentCore_instances, emitHistoryChange_fn).call(this);
      });
      if (this.config.customTools) for (const [name, tool2] of Object.entries(this.config.customTools)) {
        if (tool2 === null) {
          this.tools.delete(name);
          continue;
        }
        this.tools.set(name, tool2);
      }
      if (!this.config.experimentalScriptExecutionTool) this.tools.delete("execute_javascript");
    }
    /** Get current agent status */
    get status() {
      return __privateGet(this, _status);
    }
    /** Result of the most recent run, or `null` before the first run completes. */
    get lastResult() {
      return __privateGet(this, _lastResult);
    }
    /**
    * Push an observation message to the history event stream.
    * This will be visible in <agent_history> and remain persistent in memory across steps.
    * @experimental @internal
    * @note history change will be emitted before next step starts
    */
    pushObservation(content) {
      __privateGet(this, _observations).push(content);
    }
    /**
    * Stop the current task and wait until the run has fully settled (including lifecycle hooks).
    * @note never await .stop() in a lifecycle hook.
    */
    async stop() {
      if (__privateGet(this, _status) !== "running") return;
      __privateGet(this, _abortController).abort();
      await __privateGet(this, _running);
    }
    /**
    * external errors (pre-checks/config/hooks) will threw;
    * agent errors will be caught and added to history, and return a failed result
    */
    async execute(task) {
      if (this.disposed) throw new Error("PageAgent has been disposed. Create a new instance.");
      if (__privateGet(this, _status) === "running") throw new Error("A task is already running.");
      if (!task) throw new Error("Task is required");
      this.task = task;
      this.taskId = uid();
      this.history = [];
      __privateSet(this, _observations, []);
      __privateSet(this, _states, {
        totalWaitTime: 0,
        lastURL: "",
        browserState: null
      });
      __privateSet(this, _abortController, new AbortController());
      const signal2 = __privateGet(this, _abortController).signal;
      let resolveRunning;
      __privateSet(this, _running, new Promise((r) => resolveRunning = r));
      __privateMethod(this, _PageAgentCore_instances, setStatus_fn).call(this, "running");
      __privateMethod(this, _PageAgentCore_instances, emitHistoryChange_fn).call(this);
      if (!this.onAskUser) this.tools.delete("ask_user");
      const onBeforeStep = this.config.onBeforeStep;
      const onAfterStep = this.config.onAfterStep;
      const onBeforeTask = this.config.onBeforeTask;
      const onAfterTask = this.config.onAfterTask;
      const stepDelay = this.config.stepDelay ?? 0.4;
      const maxSteps = this.config.maxSteps;
      let step = 0;
      let taskResult;
      let finalStatus = "error";
      await suppress(() => this.pageController.showMask());
      try {
        await onBeforeTask?.(this);
        while (true) {
          await onBeforeStep?.(this, step);
          try {
            console.group(`step: ${step}`);
            if (step > 0) await waitFor(stepDelay, signal2);
            signal2.throwIfAborted();
            console.log(source_default.blue.bold("\u{1F440} Observing..."));
            __privateGet(this, _states).browserState = await this.pageController.getBrowserState();
            await __privateMethod(this, _PageAgentCore_instances, handleObservations_fn).call(this, step);
            const messages = [{
              role: "system",
              content: __privateMethod(this, _PageAgentCore_instances, getSystemPrompt_fn).call(this)
            }, {
              role: "user",
              content: await __privateMethod(this, _PageAgentCore_instances, assembleUserPrompt_fn).call(this)
            }];
            const macroTool = { AgentOutput: __privateMethod(this, _PageAgentCore_instances, packMacroTool_fn).call(this) };
            console.log(source_default.blue.bold("\u{1F9E0} Thinking..."));
            __privateMethod(this, _PageAgentCore_instances, emitActivity_fn).call(this, { type: "thinking" });
            const result2 = await __privateGet(this, _llm).invoke(messages, macroTool, signal2, {
              toolChoiceName: "AgentOutput",
              normalizeResponse: (res) => normalizeResponse(res, this.tools)
            });
            const macroResult = result2.toolResult;
            const input = macroResult.input;
            const output = macroResult.output;
            const reflection = {
              evaluation_previous_goal: input.evaluation_previous_goal,
              memory: input.memory,
              next_goal: input.next_goal
            };
            const actionName = Object.keys(input.action)[0];
            const action = {
              name: actionName,
              input: input.action[actionName],
              output
            };
            __privateMethod(this, _PageAgentCore_instances, emitHistoryChange_fn).call(this, {
              type: "step",
              stepIndex: step,
              reflection,
              action,
              usage: result2.usage,
              rawResponse: result2.rawResponse,
              rawRequest: result2.rawRequest
            });
            if (actionName === "done") {
              const success = action.input?.success ?? false;
              const data = action.input?.text || "no text provided";
              console.log(source_default.green.bold("Task completed"), success, data);
              taskResult = {
                success,
                data,
                history: this.history
              };
              __privateSet(this, _lastResult, taskResult);
              finalStatus = "completed";
              break;
            }
          } catch (error2) {
            const isAbortError = error2?.name === "AbortError";
            if (!isAbortError) console.error("Task failed", error2);
            const message = isAbortError ? "Task aborted" : String(error2);
            __privateMethod(this, _PageAgentCore_instances, emitActivity_fn).call(this, {
              type: "error",
              message
            });
            __privateMethod(this, _PageAgentCore_instances, emitHistoryChange_fn).call(this, {
              type: "error",
              message,
              rawResponse: error2
            });
            taskResult = {
              success: false,
              data: message,
              history: this.history
            };
            __privateSet(this, _lastResult, taskResult);
            finalStatus = isAbortError ? "stopped" : "error";
            break;
          } finally {
            console.groupEnd();
            await onAfterStep?.(this, this.history);
          }
          step++;
          if (step > maxSteps) {
            const message = "Step count exceeded maximum limit";
            console.error(message);
            __privateMethod(this, _PageAgentCore_instances, emitActivity_fn).call(this, {
              type: "error",
              message
            });
            __privateMethod(this, _PageAgentCore_instances, emitHistoryChange_fn).call(this, {
              type: "error",
              message
            });
            taskResult = {
              success: false,
              data: message,
              history: this.history
            };
            __privateSet(this, _lastResult, taskResult);
            finalStatus = "error";
            break;
          }
        }
        await onAfterTask?.(this, taskResult);
        return taskResult;
      } catch (error2) {
        __privateMethod(this, _PageAgentCore_instances, emitActivity_fn).call(this, {
          type: "error",
          message: String(error2)
        });
        finalStatus = "error";
        throw error2;
      } finally {
        await suppress(() => this.pageController.cleanUpHighlights());
        await suppress(() => this.pageController.hideMask());
        __privateGet(this, _abortController).abort();
        resolveRunning();
        __privateMethod(this, _PageAgentCore_instances, setStatus_fn).call(this, finalStatus);
      }
    }
    dispose() {
      console.log("Disposing PageAgent...");
      this.disposed = true;
      this.pageController.dispose();
      __privateGet(this, _abortController).abort();
      this.dispatchEvent(new Event("dispose"));
      this.config.onDispose?.(this);
    }
  }, _status = new WeakMap(), _llm = new WeakMap(), _abortController = new WeakMap(), _observations = new WeakMap(), _running = new WeakMap(), _lastResult = new WeakMap(), _states = new WeakMap(), _PageAgentCore_instances = new WeakSet(), /** Emit statuschange event */
  emitStatusChange_fn = function() {
    this.dispatchEvent(new Event("statuschange"));
  }, /** Emit historychange event */
  emitHistoryChange_fn = function(pushHistoricalEvent) {
    if (pushHistoricalEvent) this.history.push(pushHistoricalEvent);
    this.dispatchEvent(new Event("historychange"));
  }, /**
  * Emit activity event - for transient UI feedback
  * @param activity - Current agent activity
  */
  emitActivity_fn = function(activity) {
    this.dispatchEvent(new CustomEvent("activity", { detail: activity }));
  }, /** Update status and emit event */
  setStatus_fn = function(status) {
    if (__privateGet(this, _status) !== status) {
      __privateSet(this, _status, status);
      __privateMethod(this, _PageAgentCore_instances, emitStatusChange_fn).call(this);
    }
  }, /**
  * Merge all tools into a single MacroTool with the following input:
  * - thinking: string
  * - evaluation_previous_goal: string
  * - memory: string
  * - next_goal: string
  * - action: { toolName: toolInput }
  * where action must be selected from tools defined in this.tools
  */
  packMacroTool_fn = function() {
    const tools2 = this.tools;
    const actionSchemas = Array.from(tools2.entries()).map(([toolName, tool2]) => {
      return object({ [toolName]: tool2.inputSchema }).describe(tool2.description);
    });
    const actionSchema = union(actionSchemas);
    return {
      description: "You MUST call this tool every step!",
      inputSchema: object({
        evaluation_previous_goal: string2().optional(),
        memory: string2().optional(),
        next_goal: string2().optional(),
        action: actionSchema
      }),
      execute: async (input) => {
        const signal2 = __privateGet(this, _abortController).signal;
        signal2.throwIfAborted();
        console.log(source_default.blue.bold("MacroTool input"), input);
        const action = input.action;
        const toolName = Object.keys(action)[0];
        const toolInput = action[toolName];
        const reflectionLines = [];
        if (input.evaluation_previous_goal) reflectionLines.push(`\u2705: ${input.evaluation_previous_goal}`);
        if (input.memory) reflectionLines.push(`\u{1F4BE}: ${input.memory}`);
        if (input.next_goal) reflectionLines.push(`\u{1F3AF}: ${input.next_goal}`);
        const reflectionText = reflectionLines.length > 0 ? reflectionLines.join("\n") : "";
        if (reflectionText) console.log(reflectionText);
        const tool2 = tools2.get(toolName);
        assert2(tool2, `Tool ${toolName} not found`);
        console.log(source_default.blue.bold(`Executing tool: ${toolName}`), toolInput);
        __privateMethod(this, _PageAgentCore_instances, emitActivity_fn).call(this, {
          type: "executing",
          tool: toolName,
          input: toolInput
        });
        const startTime = Date.now();
        const result2 = await tool2.execute.bind(this)(toolInput, { signal: signal2 });
        signal2.throwIfAborted();
        const duration3 = Date.now() - startTime;
        console.log(source_default.green.bold(`Tool (${toolName}) executed for ${duration3}ms`), result2);
        __privateMethod(this, _PageAgentCore_instances, emitActivity_fn).call(this, {
          type: "executed",
          tool: toolName,
          input: toolInput,
          output: result2,
          duration: duration3
        });
        if (toolName === "wait") __privateGet(this, _states).totalWaitTime += toolInput?.seconds || 0;
        else __privateGet(this, _states).totalWaitTime = 0;
        return {
          input,
          output: result2
        };
      }
    };
  }, /**
  * Get system prompt, dynamically replace language settings based on configured language
  */
  getSystemPrompt_fn = function() {
    if (this.config.customSystemPrompt) return this.config.customSystemPrompt;
    const targetLanguage = this.config.language === "zh-CN" ? "\u4E2D\u6587" : "English";
    return system_prompt_default.replace(/Default working language: \*\*.*?\*\*/, `Default working language: **${targetLanguage}**`);
  }, getInstructions_fn = async function() {
    const { instructions, experimentalLlmsTxt } = this.config;
    const systemInstructions = instructions?.system?.trim();
    let pageInstructions;
    const url = __privateGet(this, _states).browserState?.url || "";
    if (instructions?.getPageInstructions && url) try {
      pageInstructions = instructions.getPageInstructions(url)?.trim();
    } catch (error2) {
      console.error(source_default.red("[PageAgent] Failed to execute getPageInstructions callback:"), error2);
    }
    const llmsTxt = experimentalLlmsTxt && url ? await fetchLlmsTxt(url) : void 0;
    if (!systemInstructions && !pageInstructions && !llmsTxt) return "";
    let result2 = "<instructions>\n";
    if (systemInstructions) result2 += `<system_instructions>
${systemInstructions}
</system_instructions>
`;
    if (pageInstructions) result2 += `<page_instructions>
${pageInstructions}
</page_instructions>
`;
    if (llmsTxt) result2 += `<llms_txt>
${llmsTxt}
</llms_txt>
`;
    result2 += "</instructions>\n\n";
    return result2;
  }, handleObservations_fn = async function(step) {
    if (__privateGet(this, _states).totalWaitTime >= 3) this.pushObservation(`You have waited ${__privateGet(this, _states).totalWaitTime} seconds accumulatively. DO NOT wait any longer unless you have a good reason.`);
    const currentURL = __privateGet(this, _states).browserState?.url || "";
    if (currentURL !== __privateGet(this, _states).lastURL) {
      this.pushObservation(`Page navigated to \u2192 ${currentURL}`);
      __privateGet(this, _states).lastURL = currentURL;
      await waitFor(0.5);
    }
    const remaining = this.config.maxSteps - step;
    if (remaining === 5) this.pushObservation(`\u26A0\uFE0F Only ${remaining} steps remaining. Consider wrapping up or calling done with partial results.`);
    else if (remaining === 2) this.pushObservation(`\u26A0\uFE0F Critical: Only ${remaining} steps left! You must finish the task or call done immediately.`);
    if (__privateGet(this, _observations).length > 0) {
      for (const content of __privateGet(this, _observations)) {
        this.history.push({
          type: "observation",
          content
        });
        console.log(source_default.cyan("Observation:"), content);
      }
      __privateSet(this, _observations, []);
      __privateMethod(this, _PageAgentCore_instances, emitHistoryChange_fn).call(this);
    }
  }, assembleUserPrompt_fn = async function() {
    const browserState = __privateGet(this, _states).browserState;
    let prompt = "";
    prompt += await __privateMethod(this, _PageAgentCore_instances, getInstructions_fn).call(this);
    const stepCount = this.history.filter((e) => e.type === "step").length;
    prompt += "<agent_state>\n";
    prompt += "<user_request>\n";
    prompt += `${this.task}
`;
    prompt += "</user_request>\n";
    prompt += "<step_info>\n";
    prompt += `Step ${stepCount + 1} of ${this.config.maxSteps} max possible steps
`;
    prompt += `Current time: ${(/* @__PURE__ */ new Date()).toLocaleString()}
`;
    prompt += "</step_info>\n";
    prompt += "</agent_state>\n\n";
    prompt += "<agent_history>\n";
    let stepIndex = 0;
    for (const event of this.history) if (event.type === "step") {
      stepIndex++;
      prompt += `<step_${stepIndex}>
`;
      prompt += `Evaluation of Previous Step: ${event.reflection.evaluation_previous_goal}
`;
      prompt += `Memory: ${event.reflection.memory}
`;
      prompt += `Next Goal: ${event.reflection.next_goal}
`;
      prompt += `Action Results: ${event.action.output}
`;
      prompt += `</step_${stepIndex}>
`;
    } else if (event.type === "observation") prompt += `<sys>${event.content}</sys>
`;
    else if (event.type === "user_takeover") prompt += `<sys>User took over control and made changes to the page</sys>
`;
    else if (event.type === "error") {
    }
    prompt += "</agent_history>\n\n";
    let pageContent = browserState.content;
    if (this.config.transformPageContent) pageContent = await this.config.transformPageContent(pageContent);
    prompt += "<browser_state>\n";
    prompt += browserState.header + "\n";
    prompt += pageContent + "\n";
    prompt += browserState.footer + "\n\n";
    prompt += "</browser_state>\n\n";
    return prompt;
  }, _a3);

  // node_modules/@page-agent/page-controller/dist/lib/page-controller.js
  var __defProp2 = Object.defineProperty;
  var __exportAll = (all, no_symbols) => {
    let target = {};
    for (var name in all) __defProp2(target, name, {
      get: all[name],
      enumerable: true
    });
    if (!no_symbols) __defProp2(target, Symbol.toStringTag, { value: "Module" });
    return target;
  };
  function isHTMLElement(el) {
    return !!el && el.nodeType === 1;
  }
  function isInputElement(el) {
    return el?.nodeType === 1 && el.tagName === "INPUT";
  }
  function isTextAreaElement(el) {
    return el?.nodeType === 1 && el.tagName === "TEXTAREA";
  }
  function isSelectElement(el) {
    return el?.nodeType === 1 && el.tagName === "SELECT";
  }
  function isAnchorElement(el) {
    return el?.nodeType === 1 && el.tagName === "A";
  }
  function getIframeOffset(element) {
    const frame = element.ownerDocument.defaultView?.frameElement;
    if (!frame) return {
      x: 0,
      y: 0
    };
    const rect = frame.getBoundingClientRect();
    return {
      x: rect.left,
      y: rect.top
    };
  }
  function getNativeValueSetter(element) {
    return Object.getOwnPropertyDescriptor(Object.getPrototypeOf(element), "value").set;
  }
  async function waitFor2(seconds) {
    await new Promise((resolve) => setTimeout(resolve, seconds * 1e3));
  }
  async function movePointerToElement(element, x, y) {
    const offset = getIframeOffset(element);
    window.dispatchEvent(new CustomEvent("PageAgent::MovePointerTo", { detail: {
      x: x + offset.x,
      y: y + offset.y
    } }));
    await waitFor2(0.3);
  }
  async function clickPointer() {
    window.dispatchEvent(new CustomEvent("PageAgent::ClickPointer"));
  }
  async function enablePassThrough() {
    window.dispatchEvent(new CustomEvent("PageAgent::EnablePassThrough"));
  }
  async function disablePassThrough() {
    window.dispatchEvent(new CustomEvent("PageAgent::DisablePassThrough"));
  }
  function getElementByIndex(selectorMap, index) {
    const interactiveNode = selectorMap.get(index);
    if (!interactiveNode) throw new Error(`No interactive element found at index ${index}`);
    const element = interactiveNode.ref;
    if (!element) throw new Error(`Element at index ${index} does not have a reference`);
    if (!isHTMLElement(element)) throw new Error(`Element at index ${index} is not an HTMLElement`);
    return element;
  }
  var lastClickedElement = null;
  function blurLastClickedElement() {
    if (lastClickedElement) {
      lastClickedElement.dispatchEvent(new PointerEvent("pointerout", { bubbles: true }));
      lastClickedElement.dispatchEvent(new PointerEvent("pointerleave", { bubbles: false }));
      lastClickedElement.dispatchEvent(new MouseEvent("mouseout", { bubbles: true }));
      lastClickedElement.dispatchEvent(new MouseEvent("mouseleave", { bubbles: false }));
      lastClickedElement.blur();
      lastClickedElement = null;
    }
  }
  async function clickElement(element) {
    blurLastClickedElement();
    lastClickedElement = element;
    await scrollIntoViewIfNeeded(element);
    const frame = element.ownerDocument.defaultView?.frameElement;
    if (frame) await scrollIntoViewIfNeeded(frame);
    const rect = element.getBoundingClientRect();
    const x = rect.left + rect.width / 2;
    const y = rect.top + rect.height / 2;
    await movePointerToElement(element, x, y);
    await clickPointer();
    await waitFor2(0.1);
    const doc = element.ownerDocument;
    await enablePassThrough();
    const hitTarget = doc.elementFromPoint(x, y);
    await disablePassThrough();
    const target = hitTarget instanceof HTMLElement && element.contains(hitTarget) ? hitTarget : element;
    const pointerOpts = {
      bubbles: true,
      cancelable: true,
      clientX: x,
      clientY: y,
      pointerType: "mouse"
    };
    const mouseOpts = {
      bubbles: true,
      cancelable: true,
      clientX: x,
      clientY: y,
      button: 0
    };
    target.dispatchEvent(new PointerEvent("pointerover", pointerOpts));
    target.dispatchEvent(new PointerEvent("pointerenter", {
      ...pointerOpts,
      bubbles: false
    }));
    target.dispatchEvent(new MouseEvent("mouseover", mouseOpts));
    target.dispatchEvent(new MouseEvent("mouseenter", {
      ...mouseOpts,
      bubbles: false
    }));
    target.dispatchEvent(new PointerEvent("pointerdown", pointerOpts));
    target.dispatchEvent(new MouseEvent("mousedown", mouseOpts));
    element.focus({ preventScroll: true });
    target.dispatchEvent(new PointerEvent("pointerup", pointerOpts));
    target.dispatchEvent(new MouseEvent("mouseup", mouseOpts));
    target.click();
    await waitFor2(0.2);
  }
  async function inputTextElement(element, text) {
    const isContentEditable = element.isContentEditable;
    if (!isInputElement(element) && !isTextAreaElement(element) && !isContentEditable) throw new Error("Element is not an input, textarea, or contenteditable");
    await clickElement(element);
    if (isContentEditable) {
      if (element.dispatchEvent(new InputEvent("beforeinput", {
        bubbles: true,
        cancelable: true,
        inputType: "deleteContent"
      }))) {
        element.innerText = "";
        element.dispatchEvent(new InputEvent("input", {
          bubbles: true,
          inputType: "deleteContent"
        }));
      }
      if (element.dispatchEvent(new InputEvent("beforeinput", {
        bubbles: true,
        cancelable: true,
        inputType: "insertText",
        data: text
      }))) {
        element.innerText = text;
        element.dispatchEvent(new InputEvent("input", {
          bubbles: true,
          inputType: "insertText",
          data: text
        }));
      }
      if (!(element.innerText.trim() === text.trim())) {
        element.focus();
        const doc = element.ownerDocument;
        const selection = (doc.defaultView || window).getSelection();
        const range = doc.createRange();
        range.selectNodeContents(element);
        selection?.removeAllRanges();
        selection?.addRange(range);
        doc.execCommand("delete", false);
        doc.execCommand("insertText", false, text);
      }
      element.dispatchEvent(new Event("change", { bubbles: true }));
      element.blur();
    } else getNativeValueSetter(element).call(element, text);
    if (!isContentEditable) element.dispatchEvent(new Event("input", { bubbles: true }));
    await waitFor2(0.1);
    blurLastClickedElement();
  }
  async function selectOptionElement(selectElement, optionText) {
    if (!isSelectElement(selectElement)) throw new Error("Element is not a select element");
    const option = Array.from(selectElement.options).find((opt) => opt.textContent?.trim() === optionText.trim());
    if (!option) throw new Error(`Option with text "${optionText}" not found in select element`);
    selectElement.value = option.value;
    selectElement.dispatchEvent(new Event("change", { bubbles: true }));
    await waitFor2(0.1);
  }
  async function scrollIntoViewIfNeeded(element) {
    const el = element;
    if (typeof el.scrollIntoViewIfNeeded === "function") el.scrollIntoViewIfNeeded();
    else element.scrollIntoView({
      behavior: "auto",
      block: "center",
      inline: "nearest"
    });
  }
  async function scrollVertically(scroll_amount, element) {
    if (element) {
      const targetElement = element;
      let currentElement = targetElement;
      let scrollSuccess = false;
      let scrolledElement = null;
      let scrollDelta = 0;
      let attempts = 0;
      const dy2 = scroll_amount;
      while (currentElement && attempts < 10) {
        const computedStyle = window.getComputedStyle(currentElement);
        const hasScrollableY = /(auto|scroll|overlay)/.test(computedStyle.overflowY) || computedStyle.scrollbarWidth && computedStyle.scrollbarWidth !== "auto" || computedStyle.scrollbarGutter && computedStyle.scrollbarGutter !== "auto";
        const canScrollVertically = currentElement.scrollHeight > currentElement.clientHeight;
        if (hasScrollableY && canScrollVertically) {
          const beforeScroll = currentElement.scrollTop;
          const maxScroll = currentElement.scrollHeight - currentElement.clientHeight;
          let scrollAmount = dy2 / 3;
          if (scrollAmount > 0) scrollAmount = Math.min(scrollAmount, maxScroll - beforeScroll);
          else scrollAmount = Math.max(scrollAmount, -beforeScroll);
          currentElement.scrollTop = beforeScroll + scrollAmount;
          const actualScrollDelta = currentElement.scrollTop - beforeScroll;
          if (Math.abs(actualScrollDelta) > 0.5) {
            scrollSuccess = true;
            scrolledElement = currentElement;
            scrollDelta = actualScrollDelta;
            break;
          }
        }
        if (currentElement === document.body || currentElement === document.documentElement) break;
        currentElement = currentElement.parentElement;
        attempts++;
      }
      if (scrollSuccess) return `Scrolled container (${scrolledElement?.tagName}) by ${scrollDelta}px`;
      else return `No scrollable container found for element (${targetElement.tagName})`;
    }
    const dy = scroll_amount;
    const bigEnough = (el2) => el2.clientHeight >= window.innerHeight * 0.5;
    const canScroll = (el2) => Boolean(el2 && /(auto|scroll|overlay)/.test(getComputedStyle(el2).overflowY) && el2.scrollHeight > el2.clientHeight && bigEnough(el2));
    let el = document.activeElement;
    while (el && !canScroll(el) && el !== document.body) el = el.parentElement;
    el = canScroll(el) ? el : Array.from(document.querySelectorAll("*")).find(canScroll) || document.scrollingElement || document.documentElement;
    if (el === document.scrollingElement || el === document.documentElement || el === document.body) {
      const scrollBefore = window.scrollY;
      const scrollMax = document.documentElement.scrollHeight - window.innerHeight;
      window.scrollBy(0, dy);
      const scrollAfter = window.scrollY;
      const scrolled = scrollAfter - scrollBefore;
      if (Math.abs(scrolled) < 1) return dy > 0 ? `\u26A0\uFE0F Already at the bottom of the page, cannot scroll down further.` : `\u26A0\uFE0F Already at the top of the page, cannot scroll up further.`;
      const reachedBottom = dy > 0 && scrollAfter >= scrollMax - 1;
      const reachedTop = dy < 0 && scrollAfter <= 1;
      if (reachedBottom) return `\u2705 Scrolled page by ${scrolled}px. Reached the bottom of the page.`;
      if (reachedTop) return `\u2705 Scrolled page by ${scrolled}px. Reached the top of the page.`;
      return `\u2705 Scrolled page by ${scrolled}px.`;
    } else {
      const warningMsg = `The document is not scrollable. Falling back to container scroll.`;
      console.log(`[PageController] ${warningMsg}`);
      const scrollBefore = el.scrollTop;
      const scrollMax = el.scrollHeight - el.clientHeight;
      el.scrollBy({
        top: dy,
        behavior: "smooth"
      });
      await waitFor2(0.1);
      const scrollAfter = el.scrollTop;
      const scrolled = scrollAfter - scrollBefore;
      if (Math.abs(scrolled) < 1) return dy > 0 ? `\u26A0\uFE0F ${warningMsg} Already at the bottom of container (${el.tagName}), cannot scroll down further.` : `\u26A0\uFE0F ${warningMsg} Already at the top of container (${el.tagName}), cannot scroll up further.`;
      const reachedBottom = dy > 0 && scrollAfter >= scrollMax - 1;
      const reachedTop = dy < 0 && scrollAfter <= 1;
      if (reachedBottom) return `\u2705 ${warningMsg} Scrolled container (${el.tagName}) by ${scrolled}px. Reached the bottom.`;
      if (reachedTop) return `\u2705 ${warningMsg} Scrolled container (${el.tagName}) by ${scrolled}px. Reached the top.`;
      return `\u2705 ${warningMsg} Scrolled container (${el.tagName}) by ${scrolled}px.`;
    }
  }
  async function scrollHorizontally(scroll_amount, element) {
    if (element) {
      const targetElement = element;
      let currentElement = targetElement;
      let scrollSuccess = false;
      let scrolledElement = null;
      let scrollDelta = 0;
      let attempts = 0;
      const dx2 = scroll_amount;
      while (currentElement && attempts < 10) {
        const computedStyle = window.getComputedStyle(currentElement);
        const hasScrollableX = /(auto|scroll|overlay)/.test(computedStyle.overflowX) || computedStyle.scrollbarWidth && computedStyle.scrollbarWidth !== "auto" || computedStyle.scrollbarGutter && computedStyle.scrollbarGutter !== "auto";
        const canScrollHorizontally = currentElement.scrollWidth > currentElement.clientWidth;
        if (hasScrollableX && canScrollHorizontally) {
          const beforeScroll = currentElement.scrollLeft;
          const maxScroll = currentElement.scrollWidth - currentElement.clientWidth;
          let scrollAmount = dx2 / 3;
          if (scrollAmount > 0) scrollAmount = Math.min(scrollAmount, maxScroll - beforeScroll);
          else scrollAmount = Math.max(scrollAmount, -beforeScroll);
          currentElement.scrollLeft = beforeScroll + scrollAmount;
          const actualScrollDelta = currentElement.scrollLeft - beforeScroll;
          if (Math.abs(actualScrollDelta) > 0.5) {
            scrollSuccess = true;
            scrolledElement = currentElement;
            scrollDelta = actualScrollDelta;
            break;
          }
        }
        if (currentElement === document.body || currentElement === document.documentElement) break;
        currentElement = currentElement.parentElement;
        attempts++;
      }
      if (scrollSuccess) return `Scrolled container (${scrolledElement?.tagName}) horizontally by ${scrollDelta}px`;
      else return `No horizontally scrollable container found for element (${targetElement.tagName})`;
    }
    const dx = scroll_amount;
    const bigEnough = (el2) => el2.clientWidth >= window.innerWidth * 0.5;
    const canScroll = (el2) => Boolean(el2 && /(auto|scroll|overlay)/.test(getComputedStyle(el2).overflowX) && el2.scrollWidth > el2.clientWidth && bigEnough(el2));
    let el = document.activeElement;
    while (el && !canScroll(el) && el !== document.body) el = el.parentElement;
    el = canScroll(el) ? el : Array.from(document.querySelectorAll("*")).find(canScroll) || document.scrollingElement || document.documentElement;
    if (el === document.scrollingElement || el === document.documentElement || el === document.body) {
      const scrollBefore = window.scrollX;
      const scrollMax = document.documentElement.scrollWidth - window.innerWidth;
      window.scrollBy(dx, 0);
      const scrollAfter = window.scrollX;
      const scrolled = scrollAfter - scrollBefore;
      if (Math.abs(scrolled) < 1) return dx > 0 ? `\u26A0\uFE0F Already at the right edge of the page, cannot scroll right further.` : `\u26A0\uFE0F Already at the left edge of the page, cannot scroll left further.`;
      const reachedRight = dx > 0 && scrollAfter >= scrollMax - 1;
      const reachedLeft = dx < 0 && scrollAfter <= 1;
      if (reachedRight) return `\u2705 Scrolled page by ${scrolled}px. Reached the right edge of the page.`;
      if (reachedLeft) return `\u2705 Scrolled page by ${scrolled}px. Reached the left edge of the page.`;
      return `\u2705 Scrolled page horizontally by ${scrolled}px.`;
    } else {
      const warningMsg = `The document is not scrollable. Falling back to container scroll.`;
      console.log(`[PageController] ${warningMsg}`);
      const scrollBefore = el.scrollLeft;
      const scrollMax = el.scrollWidth - el.clientWidth;
      el.scrollBy({
        left: dx,
        behavior: "smooth"
      });
      await waitFor2(0.1);
      const scrollAfter = el.scrollLeft;
      const scrolled = scrollAfter - scrollBefore;
      if (Math.abs(scrolled) < 1) return dx > 0 ? `\u26A0\uFE0F ${warningMsg} Already at the right edge of container (${el.tagName}), cannot scroll right further.` : `\u26A0\uFE0F ${warningMsg} Already at the left edge of container (${el.tagName}), cannot scroll left further.`;
      const reachedRight = dx > 0 && scrollAfter >= scrollMax - 1;
      const reachedLeft = dx < 0 && scrollAfter <= 1;
      if (reachedRight) return `\u2705 ${warningMsg} Scrolled container (${el.tagName}) by ${scrolled}px. Reached the right edge.`;
      if (reachedLeft) return `\u2705 ${warningMsg} Scrolled container (${el.tagName}) by ${scrolled}px. Reached the left edge.`;
      return `\u2705 ${warningMsg} Scrolled container (${el.tagName}) horizontally by ${scrolled}px.`;
    }
  }
  var dom_tree_default = (args = {
    doHighlightElements: true,
    focusHighlightIndex: -1,
    viewportExpansion: 0,
    debugMode: false,
    /**
    * @edit
    */
    /** @type {Element[]} */
    interactiveBlacklist: [],
    /** @type {Element[]} */
    interactiveWhitelist: [],
    highlightOpacity: 0.1,
    highlightLabelOpacity: 0.5
  }) => {
    const { interactiveBlacklist, interactiveWhitelist, highlightOpacity, highlightLabelOpacity } = args;
    const { doHighlightElements, focusHighlightIndex, viewportExpansion, debugMode } = args;
    let highlightIndex = 0;
    const extraData = /* @__PURE__ */ new WeakMap();
    function addExtraData(element, data) {
      if (!element || element.nodeType !== Node.ELEMENT_NODE) return;
      extraData.set(element, {
        ...extraData.get(element),
        ...data
      });
    }
    const DOM_CACHE = {
      boundingRects: /* @__PURE__ */ new WeakMap(),
      clientRects: /* @__PURE__ */ new WeakMap(),
      computedStyles: /* @__PURE__ */ new WeakMap(),
      clearCache: () => {
        DOM_CACHE.boundingRects = /* @__PURE__ */ new WeakMap();
        DOM_CACHE.clientRects = /* @__PURE__ */ new WeakMap();
        DOM_CACHE.computedStyles = /* @__PURE__ */ new WeakMap();
      }
    };
    function getCachedBoundingRect(element) {
      if (!element) return null;
      if (DOM_CACHE.boundingRects.has(element)) return DOM_CACHE.boundingRects.get(element);
      const rect = element.getBoundingClientRect();
      if (rect) DOM_CACHE.boundingRects.set(element, rect);
      return rect;
    }
    function getCachedComputedStyle(element) {
      if (!element) return null;
      if (DOM_CACHE.computedStyles.has(element)) return DOM_CACHE.computedStyles.get(element);
      const style = window.getComputedStyle(element);
      if (style) DOM_CACHE.computedStyles.set(element, style);
      return style;
    }
    function getCachedClientRects(element) {
      if (!element) return null;
      if (DOM_CACHE.clientRects.has(element)) return DOM_CACHE.clientRects.get(element);
      const rects = element.getClientRects();
      if (rects) DOM_CACHE.clientRects.set(element, rects);
      return rects;
    }
    const DOM_HASH_MAP = {};
    const ID = { current: 0 };
    const HIGHLIGHT_CONTAINER_ID = "playwright-highlight-container";
    function highlightElement(element, index, parentIframe = null) {
      if (!element) return index;
      const overlays = [];
      let label = null;
      let labelWidth = 20;
      let labelHeight = 16;
      let cleanupFn = null;
      try {
        let container = document.getElementById(HIGHLIGHT_CONTAINER_ID);
        if (!container) {
          container = document.createElement("div");
          container.id = HIGHLIGHT_CONTAINER_ID;
          container.style.position = "fixed";
          container.style.pointerEvents = "none";
          container.style.top = "0";
          container.style.left = "0";
          container.style.width = "100%";
          container.style.height = "100%";
          container.style.zIndex = "2147483640";
          container.style.backgroundColor = "transparent";
          document.body.appendChild(container);
        }
        const rects = element.getClientRects();
        if (!rects || rects.length === 0) return index;
        const colors = [
          "#FF0000",
          "#00FF00",
          "#0000FF",
          "#FFA500",
          "#800080",
          "#008080",
          "#FF69B4",
          "#4B0082",
          "#FF4500",
          "#2E8B57",
          "#DC143C",
          "#4682B4"
        ];
        let baseColor = colors[index % colors.length];
        const backgroundColor = baseColor + Math.floor(highlightOpacity * 255).toString(16).padStart(2, "0");
        baseColor = baseColor + Math.floor(highlightLabelOpacity * 255).toString(16).padStart(2, "0");
        let iframeOffset = {
          x: 0,
          y: 0
        };
        if (parentIframe) {
          const iframeRect = parentIframe.getBoundingClientRect();
          iframeOffset.x = iframeRect.left;
          iframeOffset.y = iframeRect.top;
        }
        const fragment = document.createDocumentFragment();
        for (const rect of rects) {
          if (rect.width === 0 || rect.height === 0) continue;
          const overlay = document.createElement("div");
          overlay.style.position = "fixed";
          overlay.style.border = `2px solid ${baseColor}`;
          overlay.style.backgroundColor = backgroundColor;
          overlay.style.pointerEvents = "none";
          overlay.style.boxSizing = "border-box";
          const top = rect.top + iframeOffset.y;
          const left = rect.left + iframeOffset.x;
          overlay.style.top = `${top}px`;
          overlay.style.left = `${left}px`;
          overlay.style.width = `${rect.width}px`;
          overlay.style.height = `${rect.height}px`;
          fragment.appendChild(overlay);
          overlays.push({
            element: overlay,
            initialRect: rect
          });
        }
        const firstRect = rects[0];
        label = document.createElement("div");
        label.className = "playwright-highlight-label";
        label.style.position = "fixed";
        label.style.background = baseColor;
        label.style.color = "white";
        label.style.padding = "1px 4px";
        label.style.borderRadius = "4px";
        label.style.fontSize = `${Math.min(12, Math.max(8, firstRect.height / 2))}px`;
        label.textContent = index.toString();
        labelWidth = label.offsetWidth > 0 ? label.offsetWidth : labelWidth;
        labelHeight = label.offsetHeight > 0 ? label.offsetHeight : labelHeight;
        const firstRectTop = firstRect.top + iframeOffset.y;
        const firstRectLeft = firstRect.left + iframeOffset.x;
        let labelTop = firstRectTop + 2;
        let labelLeft = firstRectLeft + firstRect.width - labelWidth - 2;
        if (firstRect.width < labelWidth + 4 || firstRect.height < labelHeight + 4) {
          labelTop = firstRectTop - labelHeight - 2;
          labelLeft = firstRectLeft + firstRect.width - labelWidth;
          if (labelLeft < iframeOffset.x) labelLeft = firstRectLeft;
        }
        labelTop = Math.max(0, Math.min(labelTop, window.innerHeight - labelHeight));
        labelLeft = Math.max(0, Math.min(labelLeft, window.innerWidth - labelWidth));
        label.style.top = `${labelTop}px`;
        label.style.left = `${labelLeft}px`;
        fragment.appendChild(label);
        const updatePositions = () => {
          const newRects = element.getClientRects();
          let newIframeOffset = {
            x: 0,
            y: 0
          };
          if (parentIframe) {
            const iframeRect = parentIframe.getBoundingClientRect();
            newIframeOffset.x = iframeRect.left;
            newIframeOffset.y = iframeRect.top;
          }
          overlays.forEach((overlayData, i) => {
            if (i < newRects.length) {
              const newRect = newRects[i];
              const newTop = newRect.top + newIframeOffset.y;
              const newLeft = newRect.left + newIframeOffset.x;
              overlayData.element.style.top = `${newTop}px`;
              overlayData.element.style.left = `${newLeft}px`;
              overlayData.element.style.width = `${newRect.width}px`;
              overlayData.element.style.height = `${newRect.height}px`;
              overlayData.element.style.display = newRect.width === 0 || newRect.height === 0 ? "none" : "block";
            } else overlayData.element.style.display = "none";
          });
          if (newRects.length < overlays.length) for (let i = newRects.length; i < overlays.length; i++) overlays[i].element.style.display = "none";
          if (label && newRects.length > 0) {
            const firstNewRect = newRects[0];
            const firstNewRectTop = firstNewRect.top + newIframeOffset.y;
            const firstNewRectLeft = firstNewRect.left + newIframeOffset.x;
            let newLabelTop = firstNewRectTop + 2;
            let newLabelLeft = firstNewRectLeft + firstNewRect.width - labelWidth - 2;
            if (firstNewRect.width < labelWidth + 4 || firstNewRect.height < labelHeight + 4) {
              newLabelTop = firstNewRectTop - labelHeight - 2;
              newLabelLeft = firstNewRectLeft + firstNewRect.width - labelWidth;
              if (newLabelLeft < newIframeOffset.x) newLabelLeft = firstNewRectLeft;
            }
            newLabelTop = Math.max(0, Math.min(newLabelTop, window.innerHeight - labelHeight));
            newLabelLeft = Math.max(0, Math.min(newLabelLeft, window.innerWidth - labelWidth));
            label.style.top = `${newLabelTop}px`;
            label.style.left = `${newLabelLeft}px`;
            label.style.display = "block";
          } else if (label) label.style.display = "none";
        };
        const throttleFunction = (func, delay) => {
          let lastCall = 0;
          return (...args2) => {
            const now = performance.now();
            if (now - lastCall < delay) return;
            lastCall = now;
            return func(...args2);
          };
        };
        const throttledUpdatePositions = throttleFunction(updatePositions, 16);
        window.addEventListener("scroll", throttledUpdatePositions, true);
        window.addEventListener("resize", throttledUpdatePositions);
        cleanupFn = () => {
          window.removeEventListener("scroll", throttledUpdatePositions, true);
          window.removeEventListener("resize", throttledUpdatePositions);
          overlays.forEach((overlay) => overlay.element.remove());
          if (label) label.remove();
        };
        container.appendChild(fragment);
        return index + 1;
      } finally {
        if (cleanupFn) (window._highlightCleanupFunctions = window._highlightCleanupFunctions || []).push(cleanupFn);
      }
    }
    function isScrollableElement(element) {
      if (!element || element.nodeType !== Node.ELEMENT_NODE) return null;
      const style = getCachedComputedStyle(element);
      if (!style) return null;
      const display = style.display;
      if (display === "inline" || display === "inline-block") return null;
      const overflowX = style.overflowX;
      const overflowY = style.overflowY;
      const hasScrollbarSignal = style.scrollbarWidth && style.scrollbarWidth !== "auto" || style.scrollbarGutter && style.scrollbarGutter !== "auto";
      const scrollableX = overflowX === "auto" || overflowX === "scroll";
      const scrollableY = overflowY === "auto" || overflowY === "scroll";
      if (!scrollableX && !scrollableY && !hasScrollbarSignal) return null;
      const scrollWidth = element.scrollWidth - element.clientWidth;
      const scrollHeight = element.scrollHeight - element.clientHeight;
      const threshold = 4;
      if (scrollWidth < threshold && scrollHeight < threshold) return null;
      if (!scrollableY && !hasScrollbarSignal && scrollWidth < threshold) return null;
      if (!scrollableX && !hasScrollbarSignal && scrollHeight < threshold) return null;
      const distanceToTop = element.scrollTop;
      const distanceToLeft = element.scrollLeft;
      const scrollData = {
        top: distanceToTop,
        right: element.scrollWidth - element.clientWidth - element.scrollLeft,
        bottom: element.scrollHeight - element.clientHeight - element.scrollTop,
        left: distanceToLeft
      };
      addExtraData(element, {
        scrollable: true,
        scrollData
      });
      return scrollData;
    }
    function isTextNodeVisible(textNode) {
      try {
        if (viewportExpansion === -1) {
          const parentElement2 = textNode.parentElement;
          if (!parentElement2) return false;
          try {
            return parentElement2.checkVisibility({
              checkOpacity: true,
              checkVisibilityCSS: true
            });
          } catch (e) {
            const style = window.getComputedStyle(parentElement2);
            return style.display !== "none" && style.visibility !== "hidden" && style.opacity !== "0";
          }
        }
        const range = document.createRange();
        range.selectNodeContents(textNode);
        const rects = range.getClientRects();
        if (!rects || rects.length === 0) return false;
        let isAnyRectVisible = false;
        let isAnyRectInViewport = false;
        for (const rect of rects) if (rect.width > 0 && rect.height > 0) {
          isAnyRectVisible = true;
          if (!(rect.bottom < -viewportExpansion || rect.top > window.innerHeight + viewportExpansion || rect.right < -viewportExpansion || rect.left > window.innerWidth + viewportExpansion)) {
            isAnyRectInViewport = true;
            break;
          }
        }
        if (!isAnyRectVisible || !isAnyRectInViewport) return false;
        const parentElement = textNode.parentElement;
        if (!parentElement) return false;
        try {
          return parentElement.checkVisibility({
            checkOpacity: true,
            checkVisibilityCSS: true
          });
        } catch (e) {
          const style = window.getComputedStyle(parentElement);
          return style.display !== "none" && style.visibility !== "hidden" && style.opacity !== "0";
        }
      } catch (e) {
        console.warn("Error checking text node visibility:", e);
        return false;
      }
    }
    function isElementAccepted(element) {
      if (!element || !element.tagName) return false;
      const alwaysAccept = /* @__PURE__ */ new Set([
        "body",
        "div",
        "main",
        "article",
        "section",
        "nav",
        "header",
        "footer"
      ]);
      const tagName = element.tagName.toLowerCase();
      if (alwaysAccept.has(tagName)) return true;
      return !(/* @__PURE__ */ new Set([
        "svg",
        "script",
        "style",
        "link",
        "meta",
        "noscript",
        "template"
      ])).has(tagName);
    }
    function isElementVisible(element) {
      const style = getCachedComputedStyle(element);
      return element.offsetWidth > 0 && element.offsetHeight > 0 && style?.visibility !== "hidden" && style?.display !== "none";
    }
    function isInteractiveElement(element) {
      if (!element || element.nodeType !== Node.ELEMENT_NODE) return false;
      if (interactiveBlacklist.includes(element)) return false;
      if (interactiveWhitelist.includes(element)) return true;
      const tagName = element.tagName.toLowerCase();
      const style = getCachedComputedStyle(element);
      const interactiveCursors = /* @__PURE__ */ new Set([
        "pointer",
        "move",
        "text",
        "grab",
        "grabbing",
        "cell",
        "copy",
        "alias",
        "all-scroll",
        "col-resize",
        "context-menu",
        "crosshair",
        "e-resize",
        "ew-resize",
        "help",
        "n-resize",
        "ne-resize",
        "nesw-resize",
        "ns-resize",
        "nw-resize",
        "nwse-resize",
        "row-resize",
        "s-resize",
        "se-resize",
        "sw-resize",
        "vertical-text",
        "w-resize",
        "zoom-in",
        "zoom-out"
      ]);
      const nonInteractiveCursors = /* @__PURE__ */ new Set([
        "not-allowed",
        "no-drop",
        "wait",
        "progress",
        "initial",
        "inherit"
      ]);
      function doesElementHaveInteractivePointer(element2) {
        if (element2.tagName.toLowerCase() === "html") return false;
        if (style?.cursor && interactiveCursors.has(style.cursor)) return true;
        return false;
      }
      if (doesElementHaveInteractivePointer(element)) return true;
      const interactiveElements = /* @__PURE__ */ new Set([
        "a",
        "button",
        "input",
        "select",
        "textarea",
        "details",
        "summary",
        "label",
        "option",
        "optgroup",
        "fieldset",
        "legend"
      ]);
      const explicitDisableTags = /* @__PURE__ */ new Set(["disabled", "readonly"]);
      if (interactiveElements.has(tagName)) {
        if (style?.cursor && nonInteractiveCursors.has(style.cursor)) return false;
        for (const disableTag of explicitDisableTags) if (element.hasAttribute(disableTag) || element.getAttribute(disableTag) === "true" || element.getAttribute(disableTag) === "") return false;
        if (element.disabled) return false;
        if (element.readOnly) return false;
        if (element.inert) return false;
        return true;
      }
      const role = element.getAttribute("role");
      const ariaRole = element.getAttribute("aria-role");
      if (element.getAttribute("contenteditable") === "true" || element.isContentEditable) return true;
      if (element.classList && (element.classList.contains("button") || element.classList.contains("dropdown-toggle") || element.getAttribute("data-index") || element.getAttribute("data-toggle") === "dropdown" || element.getAttribute("aria-haspopup") === "true")) return true;
      const interactiveRoles = /* @__PURE__ */ new Set([
        "button",
        "menu",
        "menubar",
        "menuitem",
        "menuitemradio",
        "menuitemcheckbox",
        "radio",
        "checkbox",
        "tab",
        "switch",
        "slider",
        "spinbutton",
        "combobox",
        "searchbox",
        "textbox",
        "listbox",
        "option",
        "scrollbar"
      ]);
      if (interactiveElements.has(tagName) || role && interactiveRoles.has(role) || ariaRole && interactiveRoles.has(ariaRole)) return true;
      try {
        if (typeof getEventListeners === "function") {
          const listeners = getEventListeners(element);
          for (const eventType of [
            "click",
            "mousedown",
            "mouseup",
            "dblclick"
          ]) if (listeners[eventType] && listeners[eventType].length > 0) return true;
        }
        const getEventListenersForNode = element?.ownerDocument?.defaultView?.getEventListenersForNode || window.getEventListenersForNode;
        if (typeof getEventListenersForNode === "function") {
          const listeners = getEventListenersForNode(element);
          for (const eventType of [
            "click",
            "mousedown",
            "mouseup",
            "keydown",
            "keyup",
            "submit",
            "change",
            "input",
            "focus",
            "blur"
          ]) for (const listener of listeners) if (listener.type === eventType) return true;
        }
        for (const attr of [
          "onclick",
          "onmousedown",
          "onmouseup",
          "ondblclick"
        ]) if (element.hasAttribute(attr) || typeof element[attr] === "function") return true;
      } catch (e) {
      }
      if (isScrollableElement(element)) return true;
      return false;
    }
    function isTopElement(element) {
      if (viewportExpansion === -1) return true;
      const rects = getCachedClientRects(element);
      if (!rects || rects.length === 0) return false;
      let isAnyRectInViewport = false;
      for (const rect2 of rects) if (rect2.width > 0 && rect2.height > 0 && !(rect2.bottom < -viewportExpansion || rect2.top > window.innerHeight + viewportExpansion || rect2.right < -viewportExpansion || rect2.left > window.innerWidth + viewportExpansion)) {
        isAnyRectInViewport = true;
        break;
      }
      if (!isAnyRectInViewport) return false;
      if (element.ownerDocument !== window.document) return true;
      let rect = Array.from(rects).find((r) => r.width > 0 && r.height > 0);
      if (!rect) return false;
      const shadowRoot = element.getRootNode();
      if (shadowRoot instanceof ShadowRoot) {
        const centerX = rect.left + rect.width / 2;
        const centerY = rect.top + rect.height / 2;
        try {
          const topEl = shadowRoot.elementFromPoint(centerX, centerY);
          if (!topEl) return false;
          let current = topEl;
          while (current && current !== shadowRoot) {
            if (current === element) return true;
            current = current.parentElement;
          }
          return false;
        } catch (e) {
          return true;
        }
      }
      const margin = 5;
      return [
        {
          x: rect.left + rect.width / 2,
          y: rect.top + rect.height / 2
        },
        {
          x: rect.left + margin,
          y: rect.top + margin
        },
        {
          x: rect.right - margin,
          y: rect.bottom - margin
        }
      ].some(({ x, y }) => {
        try {
          const topEl = document.elementFromPoint(x, y);
          if (!topEl) return false;
          let current = topEl;
          while (current && current !== document.documentElement) {
            if (current === element) return true;
            current = current.parentElement;
          }
          return false;
        } catch (e) {
          return true;
        }
      });
    }
    function isInExpandedViewport(element, viewportExpansion2) {
      if (viewportExpansion2 === -1) return true;
      const rects = element.getClientRects();
      if (!rects || rects.length === 0) {
        const boundingRect = getCachedBoundingRect(element);
        if (!boundingRect || boundingRect.width === 0 || boundingRect.height === 0) return false;
        return !(boundingRect.bottom < -viewportExpansion2 || boundingRect.top > window.innerHeight + viewportExpansion2 || boundingRect.right < -viewportExpansion2 || boundingRect.left > window.innerWidth + viewportExpansion2);
      }
      for (const rect of rects) {
        if (rect.width === 0 || rect.height === 0) continue;
        if (!(rect.bottom < -viewportExpansion2 || rect.top > window.innerHeight + viewportExpansion2 || rect.right < -viewportExpansion2 || rect.left > window.innerWidth + viewportExpansion2)) return true;
      }
      return false;
    }
    const INTERACTIVE_ARIA_ATTRS = [
      "aria-expanded",
      "aria-checked",
      "aria-selected",
      "aria-pressed",
      "aria-haspopup",
      "aria-controls",
      "aria-owns",
      "aria-activedescendant",
      "aria-valuenow",
      "aria-valuetext",
      "aria-valuemax",
      "aria-valuemin",
      "aria-autocomplete"
    ];
    function hasInteractiveAria(el) {
      for (let i = 0; i < INTERACTIVE_ARIA_ATTRS.length; i++) if (el.hasAttribute(INTERACTIVE_ARIA_ATTRS[i])) return true;
      return false;
    }
    function isInteractiveCandidate(element) {
      if (!element || element.nodeType !== Node.ELEMENT_NODE) return false;
      const tagName = element.tagName.toLowerCase();
      if ((/* @__PURE__ */ new Set([
        "a",
        "button",
        "input",
        "select",
        "textarea",
        "details",
        "summary",
        "label"
      ])).has(tagName)) return true;
      return element.hasAttribute("onclick") || element.hasAttribute("role") || element.hasAttribute("tabindex") || hasInteractiveAria(element) || element.hasAttribute("data-action") || element.getAttribute("contenteditable") === "true";
    }
    const DISTINCT_INTERACTIVE_TAGS = /* @__PURE__ */ new Set([
      "a",
      "button",
      "input",
      "select",
      "textarea",
      "summary",
      "details",
      "label",
      "option",
      "li"
    ]);
    const DISTINCT_INTERACTIVE_ROLES = /* @__PURE__ */ new Set([
      "button",
      "link",
      "menuitem",
      "menuitemradio",
      "menuitemcheckbox",
      "radio",
      "checkbox",
      "tab",
      "switch",
      "slider",
      "spinbutton",
      "combobox",
      "searchbox",
      "textbox",
      "listbox",
      "listitem",
      "treeitem",
      "row",
      "option",
      "scrollbar"
    ]);
    function isHeuristicallyInteractive(element) {
      if (!element || element.nodeType !== Node.ELEMENT_NODE) return false;
      if (!isElementVisible(element)) return false;
      const hasInteractiveAttributes = element.hasAttribute("role") || element.hasAttribute("tabindex") || element.hasAttribute("onclick") || typeof element.onclick === "function";
      const hasInteractiveClass = /\b(btn|clickable|menu|item|entry|link)\b/i.test(element.className || "");
      const isInKnownContainer = Boolean(element.closest('button,a,[role="button"],.menu,.dropdown,.list,.toolbar'));
      const hasVisibleChildren = [...element.children].some(isElementVisible);
      const isParentBody = element.parentElement && element.parentElement.isSameNode(document.body);
      return (isInteractiveElement(element) || hasInteractiveAttributes || hasInteractiveClass) && hasVisibleChildren && isInKnownContainer && !isParentBody;
    }
    function isElementDistinctInteraction(element) {
      if (!element || element.nodeType !== Node.ELEMENT_NODE) return false;
      const tagName = element.tagName.toLowerCase();
      const role = element.getAttribute("role");
      if (tagName === "iframe") return true;
      if (DISTINCT_INTERACTIVE_TAGS.has(tagName)) return true;
      if (role && DISTINCT_INTERACTIVE_ROLES.has(role)) return true;
      if (element.isContentEditable || element.getAttribute("contenteditable") === "true") return true;
      if (element.hasAttribute("data-testid") || element.hasAttribute("data-cy") || element.hasAttribute("data-test")) return true;
      if (element.hasAttribute("onclick") || typeof element.onclick === "function") return true;
      if (hasInteractiveAria(element)) return true;
      try {
        const getEventListenersForNode = element?.ownerDocument?.defaultView?.getEventListenersForNode || window.getEventListenersForNode;
        if (typeof getEventListenersForNode === "function") {
          const listeners = getEventListenersForNode(element);
          for (const eventType of [
            "click",
            "mousedown",
            "mouseup",
            "keydown",
            "keyup",
            "submit",
            "change",
            "input",
            "focus",
            "blur"
          ]) for (const listener of listeners) if (listener.type === eventType) return true;
        }
        if ([
          "onmousedown",
          "onmouseup",
          "onkeydown",
          "onkeyup",
          "onsubmit",
          "onchange",
          "oninput",
          "onfocus",
          "onblur"
        ].some((attr) => element.hasAttribute(attr))) return true;
      } catch (e) {
      }
      if (isHeuristicallyInteractive(element)) return true;
      if (extraData.get(element)?.scrollable) return true;
      return false;
    }
    function handleHighlighting(nodeData, node, parentIframe, isParentHighlighted) {
      if (!nodeData.isInteractive) return false;
      let shouldHighlight = false;
      if (!isParentHighlighted) shouldHighlight = true;
      else if (isElementDistinctInteraction(node)) shouldHighlight = true;
      else shouldHighlight = false;
      if (shouldHighlight) {
        nodeData.isInViewport = isInExpandedViewport(node, viewportExpansion);
        if (nodeData.isInViewport || viewportExpansion === -1) {
          nodeData.highlightIndex = highlightIndex++;
          if (doHighlightElements) {
            if (focusHighlightIndex >= 0) {
              if (focusHighlightIndex === nodeData.highlightIndex) highlightElement(node, nodeData.highlightIndex, parentIframe);
            } else highlightElement(node, nodeData.highlightIndex, parentIframe);
            return true;
          }
        }
      }
      return false;
    }
    function buildDomTree(node, parentIframe = null, isParentHighlighted = false) {
      if (!node || node.id === HIGHLIGHT_CONTAINER_ID || node.nodeType !== Node.ELEMENT_NODE && node.nodeType !== Node.TEXT_NODE) return null;
      if (!node || node.id === HIGHLIGHT_CONTAINER_ID) return null;
      if (node.dataset?.browserUseIgnore === "true" || node.dataset?.pageAgentIgnore === "true") return null;
      if (node.getAttribute && node.getAttribute("aria-hidden") === "true") return null;
      if (node === document.body) {
        const nodeData2 = {
          tagName: "body",
          attributes: {},
          xpath: "/body",
          children: []
        };
        for (const child of node.childNodes) {
          const domElement = buildDomTree(child, parentIframe, false);
          if (domElement) nodeData2.children.push(domElement);
        }
        const id2 = `${ID.current++}`;
        DOM_HASH_MAP[id2] = nodeData2;
        return id2;
      }
      if (node.nodeType !== Node.ELEMENT_NODE && node.nodeType !== Node.TEXT_NODE) return null;
      if (node.nodeType === Node.TEXT_NODE) {
        const textContent = node.textContent?.trim();
        if (!textContent) return null;
        const parentElement = node.parentElement;
        if (!parentElement || parentElement.tagName.toLowerCase() === "script") return null;
        const id2 = `${ID.current++}`;
        DOM_HASH_MAP[id2] = {
          type: "TEXT_NODE",
          text: textContent,
          isVisible: isTextNodeVisible(node)
        };
        return id2;
      }
      if (node.nodeType === Node.ELEMENT_NODE && !isElementAccepted(node)) return null;
      if (viewportExpansion !== -1 && !node.shadowRoot) {
        const rect = getCachedBoundingRect(node);
        const style = getCachedComputedStyle(node);
        const isFixedOrSticky = style && (style.position === "fixed" || style.position === "sticky");
        const hasSize = node.offsetWidth > 0 || node.offsetHeight > 0;
        if (!rect || !isFixedOrSticky && !hasSize && (rect.bottom < -viewportExpansion || rect.top > window.innerHeight + viewportExpansion || rect.right < -viewportExpansion || rect.left > window.innerWidth + viewportExpansion)) return null;
      }
      const nodeData = {
        tagName: node.tagName.toLowerCase(),
        attributes: {},
        /**
        * @edit no need for xpath
        */
        children: []
      };
      if (isInteractiveCandidate(node) || node.tagName.toLowerCase() === "iframe" || node.tagName.toLowerCase() === "body") {
        const attributeNames = node.getAttributeNames?.() || [];
        for (const name of attributeNames) {
          const value = node.getAttribute(name);
          nodeData.attributes[name] = value;
        }
        if (node.tagName.toLowerCase() === "input" && (node.type === "checkbox" || node.type === "radio")) nodeData.attributes.checked = node.checked ? "true" : "false";
      }
      let nodeWasHighlighted = false;
      if (node.nodeType === Node.ELEMENT_NODE) {
        nodeData.isVisible = isElementVisible(node);
        if (nodeData.isVisible) {
          nodeData.isTopElement = isTopElement(node);
          const role = node.getAttribute("role");
          const isMenuContainer = role === "menu" || role === "menubar" || role === "listbox";
          if (nodeData.isTopElement || isMenuContainer) {
            nodeData.isInteractive = isInteractiveElement(node);
            nodeWasHighlighted = handleHighlighting(nodeData, node, parentIframe, isParentHighlighted);
            nodeData.ref = node;
            if (nodeData.isInteractive && Object.keys(nodeData.attributes).length === 0) {
              const attributeNames = node.getAttributeNames?.() || [];
              for (const name of attributeNames) {
                const value = node.getAttribute(name);
                nodeData.attributes[name] = value;
              }
            }
          }
        }
      }
      if (node.tagName) {
        const tagName = node.tagName.toLowerCase();
        if (tagName === "iframe") try {
          const iframeDoc = node.contentDocument || node.contentWindow?.document;
          if (iframeDoc) for (const child of iframeDoc.childNodes) {
            const domElement = buildDomTree(child, node, false);
            if (domElement) nodeData.children.push(domElement);
          }
        } catch (e) {
          console.warn("Unable to access iframe:", e);
        }
        else if (node.isContentEditable || node.getAttribute("contenteditable") === "true" || node.id === "tinymce" || node.classList.contains("mce-content-body") || tagName === "body" && node.getAttribute("data-id")?.startsWith("mce_")) for (const child of node.childNodes) {
          const domElement = buildDomTree(child, parentIframe, nodeWasHighlighted);
          if (domElement) nodeData.children.push(domElement);
        }
        else {
          if (node.shadowRoot) {
            nodeData.shadowRoot = true;
            for (const child of node.shadowRoot.childNodes) {
              const domElement = buildDomTree(child, parentIframe, nodeWasHighlighted);
              if (domElement) nodeData.children.push(domElement);
            }
          }
          for (const child of node.childNodes) {
            const domElement = buildDomTree(child, parentIframe, nodeWasHighlighted || isParentHighlighted);
            if (domElement) nodeData.children.push(domElement);
          }
        }
      }
      if (nodeData.tagName === "a" && nodeData.children.length === 0 && !nodeData.attributes.href) {
        const rect = getCachedBoundingRect(node);
        if (!(rect && rect.width > 0 && rect.height > 0 || node.offsetWidth > 0 || node.offsetHeight > 0)) return null;
      }
      nodeData.extra = extraData.get(node) || null;
      const id = `${ID.current++}`;
      DOM_HASH_MAP[id] = nodeData;
      return id;
    }
    const rootId = buildDomTree(document.body);
    DOM_CACHE.clearCache();
    return {
      rootId,
      map: DOM_HASH_MAP
    };
  };
  var dom_exports = /* @__PURE__ */ __exportAll({
    cleanUpHighlights: () => cleanUpHighlights,
    flatTreeToString: () => flatTreeToString,
    getAllTextTillNextClickableElement: () => getAllTextTillNextClickableElement,
    getElementTextMap: () => getElementTextMap,
    getFlatTree: () => getFlatTree,
    getSelectorMap: () => getSelectorMap,
    resolveViewportExpansion: () => resolveViewportExpansion
  });
  var DEFAULT_VIEWPORT_EXPANSION = -1;
  function resolveViewportExpansion(viewportExpansion) {
    return viewportExpansion ?? DEFAULT_VIEWPORT_EXPANSION;
  }
  var SEMANTIC_TAGS = /* @__PURE__ */ new Set([
    "nav",
    "menu",
    "header",
    "footer",
    "aside",
    "dialog"
  ]);
  var newElementsCache = /* @__PURE__ */ new WeakMap();
  function getFlatTree(config2) {
    const viewportExpansion = resolveViewportExpansion(config2.viewportExpansion);
    const interactiveBlacklist = [];
    for (const item of config2.interactiveBlacklist || []) if (typeof item === "function") interactiveBlacklist.push(item());
    else interactiveBlacklist.push(item);
    const interactiveWhitelist = [];
    for (const item of config2.interactiveWhitelist || []) if (typeof item === "function") interactiveWhitelist.push(item());
    else interactiveWhitelist.push(item);
    const elements = dom_tree_default({
      doHighlightElements: true,
      debugMode: true,
      focusHighlightIndex: -1,
      viewportExpansion,
      interactiveBlacklist,
      interactiveWhitelist,
      highlightOpacity: config2.highlightOpacity ?? 0,
      highlightLabelOpacity: config2.highlightLabelOpacity ?? 0.1
    });
    const currentUrl = window.location.href;
    for (const nodeId in elements.map) {
      const node = elements.map[nodeId];
      if (node.isInteractive && node.ref) {
        const ref = node.ref;
        if (!newElementsCache.has(ref)) {
          newElementsCache.set(ref, currentUrl);
          node.isNew = true;
        }
      }
    }
    return elements;
  }
  var globRegexCache = /* @__PURE__ */ new Map();
  function globToRegex(pattern) {
    let regex = globRegexCache.get(pattern);
    if (!regex) {
      const escaped = pattern.replace(/[.+^${}()|[\]\\]/g, "\\$&");
      regex = new RegExp(`^${escaped.replace(/\*/g, ".*")}$`);
      globRegexCache.set(pattern, regex);
    }
    return regex;
  }
  function matchAttributes(attrs, patterns) {
    const result2 = {};
    for (const pattern of patterns) if (pattern.includes("*")) {
      const regex = globToRegex(pattern);
      for (const key of Object.keys(attrs)) if (regex.test(key) && attrs[key].trim()) result2[key] = attrs[key].trim();
    } else {
      const value = attrs[pattern];
      if (value && value.trim()) result2[pattern] = value.trim();
    }
    return result2;
  }
  function flatTreeToString(flatTree, includeAttributes = [], keepSemanticTags = false) {
    const DEFAULT_INCLUDE_ATTRIBUTES = [
      "title",
      "type",
      "checked",
      "name",
      "role",
      "value",
      "placeholder",
      "data-date-format",
      "alt",
      "aria-label",
      "aria-expanded",
      "data-state",
      "aria-checked",
      "id",
      "for",
      "target",
      "aria-haspopup",
      "aria-controls",
      "aria-owns",
      "contenteditable"
    ];
    const includeAttrs = [...includeAttributes, ...DEFAULT_INCLUDE_ATTRIBUTES];
    const capTextLength = (text, maxLength) => {
      if (text.length > maxLength) return text.substring(0, maxLength) + "...";
      return text;
    };
    const buildTreeNode = (nodeId) => {
      const node = flatTree.map[nodeId];
      if (!node) return null;
      if (node.type === "TEXT_NODE") {
        const textNode = node;
        return {
          type: "text",
          text: textNode.text,
          isVisible: textNode.isVisible,
          parent: null,
          children: []
        };
      } else {
        const elementNode = node;
        const children = [];
        if (elementNode.children) for (const childId of elementNode.children) {
          const child = buildTreeNode(childId);
          if (child) {
            child.parent = null;
            children.push(child);
          }
        }
        return {
          type: "element",
          tagName: elementNode.tagName,
          attributes: elementNode.attributes ?? {},
          isVisible: elementNode.isVisible ?? false,
          isInteractive: elementNode.isInteractive ?? false,
          isTopElement: elementNode.isTopElement ?? false,
          isNew: elementNode.isNew ?? false,
          highlightIndex: elementNode.highlightIndex,
          parent: null,
          children,
          extra: elementNode.extra ?? {}
        };
      }
    };
    const setParentReferences = (node, parent = null) => {
      node.parent = parent;
      for (const child of node.children) setParentReferences(child, node);
    };
    const rootNode = buildTreeNode(flatTree.rootId);
    if (!rootNode) return "";
    setParentReferences(rootNode);
    const hasParentWithHighlightIndex = (node) => {
      let current = node.parent;
      while (current) {
        if (current.type === "element" && current.highlightIndex !== void 0) return true;
        current = current.parent;
      }
      return false;
    };
    const processNode = (node, depth, result3) => {
      let nextDepth = depth;
      const depthStr = "	".repeat(depth);
      if (node.type === "element") {
        const isSemantic = keepSemanticTags && node.tagName && SEMANTIC_TAGS.has(node.tagName);
        if (node.highlightIndex !== void 0) {
          nextDepth += 1;
          const text = getAllTextTillNextClickableElement(node);
          let attributesHtmlStr = "";
          if (includeAttrs.length > 0 && node.attributes) {
            const attributesToInclude = matchAttributes(node.attributes, includeAttrs);
            const keys = Object.keys(attributesToInclude);
            if (keys.length > 1) {
              const keysToRemove = /* @__PURE__ */ new Set();
              const seenValues = {};
              for (const key of keys) {
                const value = attributesToInclude[key];
                if (value.length > 5) if (value in seenValues) keysToRemove.add(key);
                else seenValues[value] = key;
              }
              for (const key of keysToRemove) delete attributesToInclude[key];
            }
            if (attributesToInclude.role === node.tagName) delete attributesToInclude.role;
            for (const attr of [
              "aria-label",
              "placeholder",
              "title"
            ]) if (attributesToInclude[attr] && attributesToInclude[attr].toLowerCase().trim() === text.toLowerCase().trim()) delete attributesToInclude[attr];
            if (Object.keys(attributesToInclude).length > 0) attributesHtmlStr = Object.entries(attributesToInclude).map(([key, value]) => `${key}=${capTextLength(value, 20)}`).join(" ");
          }
          let line = `${depthStr}${node.isNew ? `*[${node.highlightIndex}]` : `[${node.highlightIndex}]`}<${node.tagName ?? ""}`;
          if (attributesHtmlStr) line += ` ${attributesHtmlStr}`;
          if (node.extra) {
            if (node.extra.scrollable) {
              let scrollDataText = "";
              if (node.extra.scrollData?.left) scrollDataText += `left=${node.extra.scrollData.left}, `;
              if (node.extra.scrollData?.top) scrollDataText += `top=${node.extra.scrollData.top}, `;
              if (node.extra.scrollData?.right) scrollDataText += `right=${node.extra.scrollData.right}, `;
              if (node.extra.scrollData?.bottom) scrollDataText += `bottom=${node.extra.scrollData.bottom}`;
              line += ` data-scrollable="${scrollDataText}"`;
            }
          }
          if (text) {
            const trimmedText = text.trim();
            if (!attributesHtmlStr) line += " ";
            line += `>${trimmedText}`;
          } else if (!attributesHtmlStr) line += " ";
          line += " />";
          result3.push(line);
        }
        const emitSemantic = isSemantic && node.highlightIndex === void 0;
        const mark = emitSemantic ? result3.length : -1;
        if (emitSemantic) {
          result3.push(`${depthStr}<${node.tagName}>`);
          nextDepth += 1;
        }
        for (const child of node.children) processNode(child, nextDepth, result3);
        if (emitSemantic) if (result3.length === mark + 1) result3.pop();
        else result3.push(`${depthStr}</${node.tagName}>`);
      } else if (node.type === "text") {
        if (hasParentWithHighlightIndex(node)) return;
        if (node.parent && node.parent.type === "element" && node.parent.isVisible && node.parent.isTopElement) result3.push(`${depthStr}${node.text ?? ""}`);
      }
    };
    const result2 = [];
    processNode(rootNode, 0, result2);
    return result2.join("\n");
  }
  var getAllTextTillNextClickableElement = (node, maxDepth = -1) => {
    const textParts = [];
    const collectText = (currentNode, currentDepth) => {
      if (maxDepth !== -1 && currentDepth > maxDepth) return;
      if (currentNode.type === "element" && currentNode !== node && currentNode.highlightIndex !== void 0) return;
      if (currentNode.type === "text" && currentNode.text) textParts.push(currentNode.text);
      else if (currentNode.type === "element") for (const child of currentNode.children) collectText(child, currentDepth + 1);
    };
    collectText(node, 0);
    return textParts.join("\n").trim();
  };
  function getSelectorMap(flatTree) {
    const selectorMap = /* @__PURE__ */ new Map();
    const keys = Object.keys(flatTree.map);
    for (const key of keys) {
      const node = flatTree.map[key];
      if (node.isInteractive && typeof node.highlightIndex === "number") selectorMap.set(node.highlightIndex, node);
    }
    return selectorMap;
  }
  function getElementTextMap(simplifiedHTML) {
    const lines = simplifiedHTML.split("\n").map((line) => line.trim()).filter((line) => line.length > 0);
    const elementTextMap = /* @__PURE__ */ new Map();
    for (const line of lines) {
      const match = /^\[(\d+)\]<[^>]+>([^<]*)/.exec(line);
      if (match) {
        const index = parseInt(match[1], 10);
        elementTextMap.set(index, line);
      }
    }
    return elementTextMap;
  }
  function cleanUpHighlights() {
    const cleanupFunctions = window._highlightCleanupFunctions || [];
    for (const cleanup of cleanupFunctions) if (typeof cleanup === "function") cleanup();
    window._highlightCleanupFunctions = [];
  }
  window.addEventListener("popstate", () => {
    cleanUpHighlights();
  });
  window.addEventListener("hashchange", () => {
    cleanUpHighlights();
  });
  window.addEventListener("beforeunload", () => {
    cleanUpHighlights();
  });
  var navigation = window.navigation;
  if (navigation && typeof navigation.addEventListener === "function") navigation.addEventListener("navigate", () => {
    cleanUpHighlights();
  });
  else {
    let currentUrl = window.location.href;
    setInterval(() => {
      if (window.location.href !== currentUrl) {
        currentUrl = window.location.href;
        cleanUpHighlights();
      }
    }, 500);
  }
  function getPageInfo() {
    const viewport_width = window.innerWidth;
    const viewport_height = window.innerHeight;
    const page_width = Math.max(document.documentElement.scrollWidth, document.body.scrollWidth || 0);
    const page_height = Math.max(document.documentElement.scrollHeight, document.body.scrollHeight || 0);
    const scroll_x = window.scrollX || window.pageXOffset || document.documentElement.scrollLeft || 0;
    const scroll_y = window.scrollY || window.pageYOffset || document.documentElement.scrollTop || 0;
    const pixels_below = Math.max(0, page_height - (window.innerHeight + scroll_y));
    const pixels_right = Math.max(0, page_width - (window.innerWidth + scroll_x));
    return {
      viewport_width,
      viewport_height,
      page_width,
      page_height,
      scroll_x,
      scroll_y,
      pixels_above: scroll_y,
      pixels_below,
      pages_above: viewport_height > 0 ? scroll_y / viewport_height : 0,
      pages_below: viewport_height > 0 ? pixels_below / viewport_height : 0,
      total_pages: viewport_height > 0 ? page_height / viewport_height : 0,
      current_page_position: scroll_y / Math.max(1, page_height - viewport_height),
      pixels_left: scroll_x,
      pixels_right
    };
  }
  function patchReact(pageController) {
    const reactRootElements = document.querySelectorAll('[data-reactroot], [data-reactid], [data-react-checksum], #root, #app, [id^="root-"], [id^="app-"], #adex-wrapper, #adex-root');
    for (const element of reactRootElements) element.setAttribute("data-page-agent-not-interactive", "true");
  }
  var PageController = class extends EventTarget {
    constructor(config2 = {}) {
      super();
      __publicField(this, "config");
      /** Corresponds to eval_page in browser-use */
      __publicField(this, "flatTree", null);
      /**
      * All highlighted index-mapped interactive elements
      * Corresponds to DOMState.selector_map in browser-use
      */
      __publicField(this, "selectorMap", /* @__PURE__ */ new Map());
      /** Index -> element text description mapping */
      __publicField(this, "elementTextMap", /* @__PURE__ */ new Map());
      /**
      * Simplified HTML for LLM consumption.
      * Corresponds to clickable_elements_to_string in browser-use
      */
      __publicField(this, "simplifiedHTML", "<EMPTY>");
      /** last time the tree was updated */
      __publicField(this, "lastTimeUpdate", 0);
      /** Whether the tree has been indexed at least once */
      __publicField(this, "isIndexed", false);
      /** Visual mask overlay for blocking user interaction during automation */
      __publicField(this, "mask", null);
      __publicField(this, "maskReady", null);
      this.config = config2;
      patchReact(this);
      if (config2.enableMask) this.initMask();
    }
    /**
    * Initialize mask asynchronously (dynamic import to avoid CSS loading in Node)
    */
    initMask() {
      if (this.maskReady !== null) return;
      this.maskReady = (async () => {
        const { SimulatorMask: SimulatorMask2 } = await Promise.resolve().then(() => (init_SimulatorMask_BHVXyogh(), SimulatorMask_BHVXyogh_exports));
        this.mask = new SimulatorMask2();
      })();
    }
    /**
    * Get current page URL
    */
    async getCurrentUrl() {
      return window.location.href;
    }
    /**
    * Get last tree update timestamp
    */
    async getLastUpdateTime() {
      return this.lastTimeUpdate;
    }
    /**
    * Get structured browser state for LLM consumption.
    * Automatically calls updateTree() to refresh the DOM state.
    */
    async getBrowserState() {
      const url = window.location.href;
      const title = document.title;
      const pi = getPageInfo();
      const viewportExpansion = resolveViewportExpansion(this.config.viewportExpansion);
      await this.updateTree();
      const content = this.simplifiedHTML;
      return {
        url,
        title,
        header: `${`Current Page: [${title}](${url})`}
${`Page info: ${pi.viewport_width}x${pi.viewport_height}px viewport, ${pi.page_width}x${pi.page_height}px total page size, ${pi.pages_above.toFixed(1)} pages above, ${pi.pages_below.toFixed(1)} pages below, ${pi.total_pages.toFixed(1)} total pages, at ${(pi.current_page_position * 100).toFixed(0)}% of page`}

${viewportExpansion === -1 ? "Interactive elements from top layer of the current page (full page):" : "Interactive elements from top layer of the current page inside the viewport:"}

${pi.pixels_above > 4 && viewportExpansion !== -1 ? `... ${pi.pixels_above} pixels above (${pi.pages_above.toFixed(1)} pages) - scroll to see more ...` : "[Start of page]"}`,
        content,
        footer: pi.pixels_below > 4 && viewportExpansion !== -1 ? `... ${pi.pixels_below} pixels below (${pi.pages_below.toFixed(1)} pages) - scroll to see more ...` : "[End of page]"
      };
    }
    /**
    * Update DOM tree, returns simplified HTML for LLM.
    * This is the main method to refresh the page state.
    * Automatically bypasses mask during DOM extraction if enabled.
    */
    async updateTree() {
      this.dispatchEvent(new Event("beforeUpdate"));
      this.lastTimeUpdate = Date.now();
      if (this.mask) this.mask.wrapper.style.pointerEvents = "none";
      cleanUpHighlights();
      const blacklist = [...this.config.interactiveBlacklist || [], ...Array.from(document.querySelectorAll("[data-page-agent-not-interactive]"))];
      this.flatTree = getFlatTree({
        ...this.config,
        interactiveBlacklist: blacklist
      });
      this.simplifiedHTML = flatTreeToString(this.flatTree, this.config.includeAttributes, this.config.keepSemanticTags);
      this.selectorMap.clear();
      this.selectorMap = getSelectorMap(this.flatTree);
      this.elementTextMap.clear();
      this.elementTextMap = getElementTextMap(this.simplifiedHTML);
      this.isIndexed = true;
      if (this.mask) this.mask.wrapper.style.pointerEvents = "auto";
      this.dispatchEvent(new Event("afterUpdate"));
      return this.simplifiedHTML;
    }
    /**
    * Clean up all element highlights
    */
    async cleanUpHighlights() {
      console.log("[PageController] cleanUpHighlights");
      cleanUpHighlights();
    }
    /**
    * Ensure the tree has been indexed before any index-based operation.
    * Throws if updateTree() hasn't been called yet.
    */
    assertIndexed() {
      if (!this.isIndexed) throw new Error("DOM tree not indexed yet. Can not perform actions on elements.");
    }
    /**
    * Click element by index
    */
    async clickElement(index) {
      try {
        this.assertIndexed();
        const element = getElementByIndex(this.selectorMap, index);
        const elemText = this.elementTextMap.get(index);
        await clickElement(element);
        if (isAnchorElement(element) && element.target === "_blank") return {
          success: true,
          message: `\u2705 Clicked element (${elemText ?? index}). \u26A0\uFE0F Link opened in a new tab.`
        };
        return {
          success: true,
          message: `\u2705 Clicked element (${elemText ?? index}).`
        };
      } catch (error2) {
        return {
          success: false,
          message: `\u274C Failed to click element: ${error2}`
        };
      }
    }
    /**
    * Input text into element by index
    */
    async inputText(index, text) {
      try {
        this.assertIndexed();
        const element = getElementByIndex(this.selectorMap, index);
        const elemText = this.elementTextMap.get(index);
        await inputTextElement(element, text);
        return {
          success: true,
          message: `\u2705 Input text (${text}) into element (${elemText ?? index}).`
        };
      } catch (error2) {
        return {
          success: false,
          message: `\u274C Failed to input text: ${error2}`
        };
      }
    }
    /**
    * Select dropdown option by index and option text
    */
    async selectOption(index, optionText) {
      try {
        this.assertIndexed();
        const element = getElementByIndex(this.selectorMap, index);
        const elemText = this.elementTextMap.get(index);
        await selectOptionElement(element, optionText);
        return {
          success: true,
          message: `\u2705 Selected option (${optionText}) in element (${elemText ?? index}).`
        };
      } catch (error2) {
        return {
          success: false,
          message: `\u274C Failed to select option: ${error2}`
        };
      }
    }
    /**
    * Scroll vertically
    */
    async scroll(options) {
      try {
        const { down, numPages, pixels, index } = options;
        this.assertIndexed();
        return {
          success: true,
          message: await scrollVertically((pixels ?? numPages * window.innerHeight) * (down ? 1 : -1), index !== void 0 ? getElementByIndex(this.selectorMap, index) : null)
        };
      } catch (error2) {
        return {
          success: false,
          message: `\u274C Failed to scroll: ${error2}`
        };
      }
    }
    /**
    * Scroll horizontally
    */
    async scrollHorizontally(options) {
      try {
        const { right, pixels, index } = options;
        this.assertIndexed();
        return {
          success: true,
          message: await scrollHorizontally(pixels * (right ? 1 : -1), index !== void 0 ? getElementByIndex(this.selectorMap, index) : null)
        };
      } catch (error2) {
        return {
          success: false,
          message: `\u274C Failed to scroll horizontally: ${error2}`
        };
      }
    }
    /**
    * Execute arbitrary JavaScript on the page.
    * The optional `signal` is exposed to the script scope so cooperative code
    * can abort promptly when the task is stopped.
    */
    async executeJavascript(script, signal) {
      try {
        const asyncFunction = eval(`(async (signal) => { ${script} })`);
        const result = await asyncFunction(signal);
        return {
          success: true,
          message: `\u2705 Executed JavaScript. Result: ${result}`
        };
      } catch (error2) {
        return {
          success: false,
          message: `\u274C Error executing JavaScript: ${error2}`
        };
      }
    }
    /**
    * Show the visual mask overlay.
    * Only works after mask is setup.
    */
    async showMask() {
      await this.maskReady;
      this.mask?.show();
    }
    /**
    * Hide the visual mask overlay.
    * Only works after mask is setup.
    */
    async hideMask() {
      await this.maskReady;
      this.mask?.hide();
    }
    /**
    * Dispose and clean up resources
    */
    dispose() {
      cleanUpHighlights();
      this.flatTree = null;
      this.selectorMap.clear();
      this.elementTextMap.clear();
      this.simplifiedHTML = "<EMPTY>";
      this.isIndexed = false;
      this.mask?.dispose();
      this.mask = null;
    }
  };

  // node_modules/@page-agent/ui/dist/lib/page-agent-ui.js
  (function() {
    try {
      if (typeof document != "undefined") {
        var elementStyle = document.createElement("style");
        elementStyle.appendChild(document.createTextNode("._wrapper_1tu05_1 {\n	position: fixed;\n	bottom: 100px;\n	left: 50%;\n	transform: translateX(-50%) translateY(20px);\n	opacity: 0;\n	z-index: 2147483642; /* \u6BD4 SimulatorMask \u9AD8\u4E00\u5C42 */\n	box-sizing: border-box;\n\n	overflow: visible;\n\n	* {\n		box-sizing: border-box;\n	}\n\n	--width: 360px;\n	--height: 40px;\n	--border-radius: 12px;\n\n	--side-space: 12px; /* \u63A7\u5236\u680F\u4E24\u4FA7\u7684\u95F4\u8DDD */\n	--history-width: calc(var(--width) - var(--side-space) * 2);\n\n	--color-1: rgb(57, 182, 255);\n	--color-2: rgb(189, 69, 251);\n	--color-3: rgb(255, 87, 51);\n	--color-4: rgb(255, 214, 0);\n\n	width: var(--width);\n	height: var(--height);\n\n	transition: all 0.3s ease-in-out;\n\n	/* \u54CD\u5E94\u5F0F\u8BBE\u8BA1 */\n	@media (max-width: 480px) {\n		width: calc(100vw - 40px);\n		--width: calc(100vw - 40px);\n	}\n\n	._background_1tu05_39 {\n		position: absolute;\n		inset: -2px -8px;\n		border-radius: calc(var(--border-radius) + 4px);\n		filter: blur(16px);\n		overflow: hidden;\n		/* mix-blend-mode: lighten; */\n		/* display: none; */\n\n		&::before {\n			content: '';\n			z-index: -1;\n			pointer-events: none;\n			position: absolute;\n			width: 100%;\n			height: 100%;\n			/* left: -100%; */\n			left: 0;\n			top: 0;\n\n			background-image: linear-gradient(\n				to bottom left,\n				var(--color-1),\n				var(--color-2),\n				var(--color-1)\n			);\n			animation: _mask-running_1tu05_1 2s linear infinite;\n		}\n		&::after {\n			content: '';\n			z-index: -1;\n			pointer-events: none;\n			position: absolute;\n			width: 100%;\n			height: 100%;\n			left: 0;\n			top: 0;\n\n			background-image: linear-gradient(\n				to bottom left,\n				var(--color-2),\n				var(--color-1),\n				var(--color-2)\n			);\n			animation: _mask-running_1tu05_1 2s linear infinite;\n			animation-delay: 1s;\n		}\n	}\n}\n\n@keyframes _mask-running_1tu05_1 {\n	from {\n		transform: translateX(-100%);\n	}\n	to {\n		transform: translateX(100%);\n	}\n}\n\n/* \u63A7\u5236\u680F */\n._header_1tu05_99 {\n	display: flex;\n	align-items: center;\n	justify-content: space-between;\n	padding: 8px 12px;\n	user-select: none;\n\n	position: absolute;\n	inset: 0;\n\n	cursor: pointer;\n	flex-shrink: 0; /* \u9632\u6B62 header \u88AB\u538B\u7F29 */\n\n	background: rgba(0, 0, 0, 0.5);\n	backdrop-filter: blur(10px);\n	border-radius: var(--border-radius);\n	background-clip: padding-box;\n\n	box-shadow:\n		0 0 0px 2px rgba(255, 255, 255, 0.4),\n		0 0 5px 1px rgba(255, 255, 255, 0.3);\n\n	._statusSection_1tu05_121 {\n		display: flex;\n		align-items: center;\n		gap: 8px;\n		flex: 1;\n		min-height: 24px; /* \u786E\u4FDD\u5782\u76F4\u5C45\u4E2D */\n\n		._indicator_1tu05_128 {\n			width: 6px;\n			height: 6px;\n			border-radius: 50%;\n			background: rgba(255, 255, 255, 0.5);\n			flex-shrink: 0;\n			animation: none; /* \u9ED8\u8BA4\u65E0\u52A8\u753B */\n\n			/* \u8FD0\u884C\u72B6\u6001 - \u6709\u52A8\u753B */\n			&._thinking_1tu05_137 {\n				background: rgb(57, 182, 255);\n				animation: _pulse_1tu05_1 0.8s ease-in-out infinite;\n			}\n\n			&._tool_executing_1tu05_142 {\n				background: rgb(189, 69, 251);\n				animation: _pulse_1tu05_1 0.6s ease-in-out infinite;\n			}\n\n			&._retry_1tu05_147 {\n				background: rgb(255, 214, 0);\n				animation: _retryPulse_1tu05_1 1s ease-in-out infinite;\n			}\n\n			/* \u9759\u6B62\u72B6\u6001 - \u65E0\u52A8\u753B */\n			&._completed_1tu05_153,\n			&._input_1tu05_154,\n			&._output_1tu05_155 {\n				background: rgb(34, 197, 94);\n				animation: none;\n			}\n\n			&._error_1tu05_160 {\n				background: rgb(239, 68, 68);\n				animation: none;\n			}\n		}\n\n		._statusText_1tu05_166 {\n			color: white;\n			font-size: 12px;\n			line-height: 1;\n			font-weight: 500;\n			transition: all 0.3s ease-in-out;\n			position: relative;\n			overflow: hidden;\n			display: flex;\n			align-items: center;\n			min-height: 24px; /* \u786E\u4FDD\u5782\u76F4\u5C45\u4E2D */\n\n			&._fadeOut_1tu05_178 {\n				animation: _statusTextFadeOut_1tu05_1 0.3s ease forwards;\n			}\n\n			&._fadeIn_1tu05_182 {\n				animation: _statusTextFadeIn_1tu05_1 0.3s ease forwards;\n			}\n		}\n	}\n\n	._controls_1tu05_188 {\n		display: flex;\n		align-items: center;\n		gap: 4px;\n\n		._controlButton_1tu05_193 {\n			width: 24px;\n			height: 24px;\n			border: none;\n			border-radius: 4px;\n			background: rgba(255, 255, 255, 0.1);\n			color: white;\n			cursor: pointer;\n			display: flex;\n			align-items: center;\n			justify-content: center;\n			font-size: 12px;\n			line-height: 1;\n\n			&:hover {\n				background: rgba(255, 255, 255, 0.2);\n			}\n		}\n\n		._stopButton_1tu05_212 {\n			background: rgba(239, 68, 68, 0.2);\n			color: rgb(255, 41, 41);\n			font-weight: 600;\n\n			&:hover {\n				background: rgba(239, 68, 68, 0.3);\n			}\n		}\n	}\n}\n\n@keyframes _statusTextFadeIn_1tu05_1 {\n	0% {\n		opacity: 0;\n		transform: translateY(5px);\n	}\n	100% {\n		opacity: 1;\n		transform: translateY(0);\n	}\n}\n\n@keyframes _statusTextFadeOut_1tu05_1 {\n	0% {\n		opacity: 1;\n		transform: translateY(0);\n	}\n	100% {\n		opacity: 0;\n		transform: translateY(-5px);\n	}\n}\n\n._historySectionWrapper_1tu05_246 {\n	position: absolute;\n	width: var(--history-width);\n	bottom: var(--height);\n	left: var(--side-space);\n	z-index: -2;\n\n	padding-top: 0px;\n	visibility: collapse;\n	overflow: hidden;\n\n	transition: all 0.2s;\n\n	background: rgba(2, 0, 20, 0.5);\n	/* background: rgba(186, 186, 186, 0.2); */\n	backdrop-filter: blur(10px);\n\n	text-shadow: 0 0 1px rgba(0, 0, 0, 0.2);\n\n	border-top-left-radius: calc(var(--border-radius) + 4px);\n	border-top-right-radius: calc(var(--border-radius) + 4px);\n\n	/* border: 2px solid rgba(255, 255, 255, 0.8); */\n	border: 2px solid rgba(255, 255, 255, 0.4);\n	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.6);\n\n	/* @media (prefers-color-scheme: dark) {\n		box-shadow:\n			0 8px 32px 0 rgba(0, 0, 0, 0.85),\n			0 2px 12px 0 rgba(57, 182, 255, 0.1);\n	} */\n\n	._expanded_1tu05_278 & {\n		padding-top: 8px;\n		visibility: visible;\n	}\n\n	._historySection_1tu05_246 {\n		position: relative;\n		overflow-y: auto;\n		overscroll-behavior: contain;\n		scrollbar-width: none;\n		max-height: 0;\n		padding-inline: 8px;\n\n		transition: max-height 0.2s;\n\n		._expanded_1tu05_278 & {\n			max-height: min(500px, calc(100vh - 200px - var(--height)));\n		}\n\n		._historyItem_1tu05_297 {\n			/* backdrop-filter: blur(10px); */\n			padding: 8px 10px;\n			margin-bottom: 6px;\n			background: linear-gradient(135deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0.03));\n			border-radius: 8px;\n			border-left: 2px solid rgba(57, 182, 255, 0.5);\n			font-size: 12px;\n			color: white;\n			/* color: black; */\n			line-height: 1.3;\n			position: relative;\n			overflow: hidden;\n\n			/* \u5FAE\u5999\u7684\u5185\u9634\u5F71 */\n			box-shadow:\n				inset 0 1px 0 rgba(255, 255, 255, 0.1),\n				0 1px 3px rgba(0, 0, 0, 0.1);\n\n			&::before {\n				content: '';\n				position: absolute;\n				top: 0;\n				left: 0;\n				right: 0;\n				height: 1px;\n				background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);\n			}\n\n			&:hover {\n				background: linear-gradient(135deg, rgba(255, 255, 255, 0.12), rgba(255, 255, 255, 0.06));\n				/* transform: translateY(-1px); */\n				box-shadow:\n					inset 0 1px 0 rgba(255, 255, 255, 0.15),\n					0 2px 4px rgba(0, 0, 0, 0.15);\n			}\n\n			&:last-child {\n				margin-bottom: 10px;\n			}\n\n			&._completed_1tu05_153,\n			&._input_1tu05_154,\n			&._output_1tu05_155 {\n				border-left-color: rgb(34, 197, 94);\n				background: linear-gradient(135deg, rgba(34, 197, 94, 0.1), rgba(34, 197, 94, 0.05));\n			}\n\n			&._error_1tu05_160 {\n				border-left-color: rgb(239, 68, 68);\n				background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(239, 68, 68, 0.05));\n			}\n\n			&._retry_1tu05_147 {\n				border-left-color: rgb(255, 214, 0);\n				background: linear-gradient(135deg, rgba(255, 214, 0, 0.1), rgba(255, 214, 0, 0.05));\n			}\n\n			&._observation_1tu05_355 {\n				border-left-color: rgb(147, 51, 234);\n				background: linear-gradient(135deg, rgba(147, 51, 234, 0.1), rgba(147, 51, 234, 0.05));\n			}\n\n			&._question_1tu05_360 {\n				border-left-color: rgb(255, 159, 67);\n				background: linear-gradient(135deg, rgba(255, 159, 67, 0.15), rgba(255, 159, 67, 0.08));\n			}\n\n			/* \u7A81\u51FA\u663E\u793A done \u6210\u529F\u7ED3\u679C */\n			&._doneSuccess_1tu05_366 {\n				background: linear-gradient(\n					135deg,\n					rgba(34, 197, 94, 0.25),\n					rgba(34, 197, 94, 0.15),\n					rgba(34, 197, 94, 0.08)\n				);\n				border: none;\n				border-left: 4px solid rgb(34, 197, 94);\n				box-shadow:\n					0 4px 12px rgba(34, 197, 94, 0.3),\n					inset 0 1px 0 rgba(255, 255, 255, 0.2),\n					0 0 20px rgba(34, 197, 94, 0.1);\n				font-weight: 600;\n				color: rgb(220, 252, 231);\n				padding: 10px 12px;\n				margin-bottom: 8px;\n				border-radius: 8px;\n				position: relative;\n				overflow: hidden;\n\n				&::before {\n					background: linear-gradient(90deg, transparent, rgba(34, 197, 94, 0.4), transparent);\n				}\n\n				&::after {\n					content: '';\n					position: absolute;\n					top: 0;\n					left: -100%;\n					width: 100%;\n					height: 100%;\n					background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);\n					animation: _shimmer_1tu05_1 2s ease-in-out infinite;\n				}\n\n				._historyContent_1tu05_402 {\n					._statusIcon_1tu05_403 {\n						font-size: 16px;\n						animation: _celebrate_1tu05_1 0.8s ease-in-out;\n						filter: drop-shadow(0 2px 4px rgba(34, 197, 94, 0.5));\n					}\n				}\n			}\n\n			/* \u7A81\u51FA\u663E\u793A done \u5931\u8D25\u7ED3\u679C */\n			&._doneError_1tu05_412 {\n				background: linear-gradient(\n					135deg,\n					rgba(239, 68, 68, 0.25),\n					rgba(239, 68, 68, 0.15),\n					rgba(239, 68, 68, 0.08)\n				);\n				border: none;\n				border-left: 4px solid rgb(239, 68, 68);\n				box-shadow:\n					0 4px 12px rgba(239, 68, 68, 0.3),\n					inset 0 1px 0 rgba(255, 255, 255, 0.2),\n					0 0 20px rgba(239, 68, 68, 0.1);\n				font-weight: 600;\n				color: rgb(254, 226, 226);\n				padding: 10px 12px;\n				margin-bottom: 8px;\n				border-radius: 8px;\n				position: relative;\n				overflow: hidden;\n\n				&::before {\n					background: linear-gradient(90deg, transparent, rgba(239, 68, 68, 0.4), transparent);\n				}\n\n				._historyContent_1tu05_402 {\n					._statusIcon_1tu05_403 {\n						font-size: 16px;\n						filter: drop-shadow(0 2px 4px rgba(239, 68, 68, 0.5));\n					}\n				}\n			}\n\n			._historyContent_1tu05_402 {\n				display: flex;\n				align-items: flex-start;\n				gap: 8px;\n\n				word-break: break-all;\n				white-space: pre-wrap;\n\n				/* overflow-x: auto; */\n\n				._statusIcon_1tu05_403 {\n					font-size: 12px;\n					flex-shrink: 0;\n					line-height: 1;\n					transition: all 0.3s ease;\n				}\n\n				._reflectionLines_1tu05_462 {\n					display: flex;\n					flex-direction: column;\n					gap: 4px;\n				}\n			}\n\n			._historyMeta_1tu05_469 {\n				font-size: 10px;\n				color: rgba(255, 255, 255, 0.6);\n				/* color: rgb(61, 61, 61); */\n				margin-top: 8px;\n				line-height: 1;\n			}\n		}\n	}\n}\n\n/* \u52A8\u753B\u5173\u952E\u5E27 - \u66F4\u5FEB\u7684\u95EA\u70C1 */\n@keyframes _pulse_1tu05_1 {\n	0%,\n	100% {\n		opacity: 1;\n		transform: scale(1);\n	}\n	50% {\n		opacity: 0.4;\n		transform: scale(1.3);\n	}\n}\n\n/* \u91CD\u8BD5\u52A8\u753B - \u65CB\u8F6C\u8109\u51B2 */\n@keyframes _retryPulse_1tu05_1 {\n	0%,\n	100% {\n		opacity: 1;\n		transform: scale(1) rotate(0deg);\n	}\n	25% {\n		opacity: 0.6;\n		transform: scale(1.2) rotate(90deg);\n	}\n	50% {\n		opacity: 0.8;\n		transform: scale(1.1) rotate(180deg);\n	}\n	75% {\n		opacity: 0.6;\n		transform: scale(1.2) rotate(270deg);\n	}\n}\n\n/* \u5E86\u795D\u52A8\u753B */\n@keyframes _celebrate_1tu05_1 {\n	0%,\n	100% {\n		transform: scale(1);\n	}\n	25% {\n		transform: scale(1.2) rotate(-5deg);\n	}\n	75% {\n		transform: scale(1.2) rotate(5deg);\n	}\n}\n\n/* done \u5361\u7247\u7684\u5149\u6CFD\u6548\u679C */\n@keyframes _shimmer_1tu05_1 {\n	0% {\n		left: -100%;\n	}\n	100% {\n		left: 100%;\n	}\n}\n\n/* \u8F93\u5165\u533A\u57DF\u6837\u5F0F */\n._inputSectionWrapper_1tu05_539 {\n	position: absolute;\n	width: var(--history-width);\n	top: var(--height);\n	left: var(--side-space);\n	z-index: -1;\n\n	visibility: visible;\n	overflow: hidden;\n\n	height: 48px;\n\n	transition: all 0.2s;\n\n	background: rgba(186, 186, 186, 0.2);\n	backdrop-filter: blur(10px);\n\n	border-bottom-left-radius: calc(var(--border-radius) + 4px);\n	border-bottom-right-radius: calc(var(--border-radius) + 4px);\n\n	border: 2px solid rgba(255, 255, 255, 0.3);\n	box-shadow: 0 1px 16px rgba(0, 0, 0, 0.4);\n\n	&._hidden_1tu05_562 {\n		visibility: collapse;\n		height: 0;\n	}\n\n	._inputSection_1tu05_539 {\n		display: flex;\n		align-items: center;\n		gap: 4px;\n		padding: 8px 8px;\n\n		._taskInput_1tu05_573 {\n			flex: 1;\n			background: rgba(255, 255, 255, 0.4);\n			border: 1px solid rgba(255, 255, 255, 0.3);\n			border-radius: 10px;\n			padding-inline: 10px;\n			color: rgb(20, 20, 20);\n			font-size: 12px;\n			height: 28px;\n			line-height: 1;\n			outline: none;\n			transition: all 0.2s ease;\n\n			/* text-shadow: 0 0 2px rgba(255, 255, 255, 0.8); */\n\n			/* border-color: rgba(57, 182, 255, 0.3); */\n\n			&::placeholder {\n				color: rgb(53, 53, 53);\n			}\n\n			&:focus {\n				background: rgba(255, 255, 255, 0.8);\n				border-color: rgba(57, 182, 255, 0.6);\n				box-shadow: 0 0 0 2px rgba(57, 182, 255, 0.2);\n			}\n		}\n	}\n}"));
        document.head.appendChild(elementStyle);
      }
    } catch (e) {
      console.error("vite-plugin-css-injected-by-js", e);
    }
  })();
  (function() {
    try {
      if (typeof document != "undefined") {
        var elementStyle = document.createElement("style");
        elementStyle.appendChild(document.createTextNode("._wrapper_1tu05_1 {\n	position: fixed;\n	bottom: 100px;\n	left: 50%;\n	transform: translateX(-50%) translateY(20px);\n	opacity: 0;\n	z-index: 2147483642; /* \u6BD4 SimulatorMask \u9AD8\u4E00\u5C42 */\n	box-sizing: border-box;\n\n	overflow: visible;\n\n	* {\n		box-sizing: border-box;\n	}\n\n	--width: 360px;\n	--height: 40px;\n	--border-radius: 12px;\n\n	--side-space: 12px; /* \u63A7\u5236\u680F\u4E24\u4FA7\u7684\u95F4\u8DDD */\n	--history-width: calc(var(--width) - var(--side-space) * 2);\n\n	--color-1: rgb(57, 182, 255);\n	--color-2: rgb(189, 69, 251);\n	--color-3: rgb(255, 87, 51);\n	--color-4: rgb(255, 214, 0);\n\n	width: var(--width);\n	height: var(--height);\n\n	transition: all 0.3s ease-in-out;\n\n	/* \u54CD\u5E94\u5F0F\u8BBE\u8BA1 */\n	@media (max-width: 480px) {\n		width: calc(100vw - 40px);\n		--width: calc(100vw - 40px);\n	}\n\n	._background_1tu05_39 {\n		position: absolute;\n		inset: -2px -8px;\n		border-radius: calc(var(--border-radius) + 4px);\n		filter: blur(16px);\n		overflow: hidden;\n		/* mix-blend-mode: lighten; */\n		/* display: none; */\n\n		&::before {\n			content: '';\n			z-index: -1;\n			pointer-events: none;\n			position: absolute;\n			width: 100%;\n			height: 100%;\n			/* left: -100%; */\n			left: 0;\n			top: 0;\n\n			background-image: linear-gradient(\n				to bottom left,\n				var(--color-1),\n				var(--color-2),\n				var(--color-1)\n			);\n			animation: _mask-running_1tu05_1 2s linear infinite;\n		}\n		&::after {\n			content: '';\n			z-index: -1;\n			pointer-events: none;\n			position: absolute;\n			width: 100%;\n			height: 100%;\n			left: 0;\n			top: 0;\n\n			background-image: linear-gradient(\n				to bottom left,\n				var(--color-2),\n				var(--color-1),\n				var(--color-2)\n			);\n			animation: _mask-running_1tu05_1 2s linear infinite;\n			animation-delay: 1s;\n		}\n	}\n}\n\n@keyframes _mask-running_1tu05_1 {\n	from {\n		transform: translateX(-100%);\n	}\n	to {\n		transform: translateX(100%);\n	}\n}\n\n/* \u63A7\u5236\u680F */\n._header_1tu05_99 {\n	display: flex;\n	align-items: center;\n	justify-content: space-between;\n	padding: 8px 12px;\n	user-select: none;\n\n	position: absolute;\n	inset: 0;\n\n	cursor: pointer;\n	flex-shrink: 0; /* \u9632\u6B62 header \u88AB\u538B\u7F29 */\n\n	background: rgba(0, 0, 0, 0.5);\n	backdrop-filter: blur(10px);\n	border-radius: var(--border-radius);\n	background-clip: padding-box;\n\n	box-shadow:\n		0 0 0px 2px rgba(255, 255, 255, 0.4),\n		0 0 5px 1px rgba(255, 255, 255, 0.3);\n\n	._statusSection_1tu05_121 {\n		display: flex;\n		align-items: center;\n		gap: 8px;\n		flex: 1;\n		min-height: 24px; /* \u786E\u4FDD\u5782\u76F4\u5C45\u4E2D */\n\n		._indicator_1tu05_128 {\n			width: 6px;\n			height: 6px;\n			border-radius: 50%;\n			background: rgba(255, 255, 255, 0.5);\n			flex-shrink: 0;\n			animation: none; /* \u9ED8\u8BA4\u65E0\u52A8\u753B */\n\n			/* \u8FD0\u884C\u72B6\u6001 - \u6709\u52A8\u753B */\n			&._thinking_1tu05_137 {\n				background: rgb(57, 182, 255);\n				animation: _pulse_1tu05_1 0.8s ease-in-out infinite;\n			}\n\n			&._tool_executing_1tu05_142 {\n				background: rgb(189, 69, 251);\n				animation: _pulse_1tu05_1 0.6s ease-in-out infinite;\n			}\n\n			&._retry_1tu05_147 {\n				background: rgb(255, 214, 0);\n				animation: _retryPulse_1tu05_1 1s ease-in-out infinite;\n			}\n\n			/* \u9759\u6B62\u72B6\u6001 - \u65E0\u52A8\u753B */\n			&._completed_1tu05_153,\n			&._input_1tu05_154,\n			&._output_1tu05_155 {\n				background: rgb(34, 197, 94);\n				animation: none;\n			}\n\n			&._error_1tu05_160 {\n				background: rgb(239, 68, 68);\n				animation: none;\n			}\n		}\n\n		._statusText_1tu05_166 {\n			color: white;\n			font-size: 12px;\n			line-height: 1;\n			font-weight: 500;\n			transition: all 0.3s ease-in-out;\n			position: relative;\n			overflow: hidden;\n			display: flex;\n			align-items: center;\n			min-height: 24px; /* \u786E\u4FDD\u5782\u76F4\u5C45\u4E2D */\n\n			&._fadeOut_1tu05_178 {\n				animation: _statusTextFadeOut_1tu05_1 0.3s ease forwards;\n			}\n\n			&._fadeIn_1tu05_182 {\n				animation: _statusTextFadeIn_1tu05_1 0.3s ease forwards;\n			}\n		}\n	}\n\n	._controls_1tu05_188 {\n		display: flex;\n		align-items: center;\n		gap: 4px;\n\n		._controlButton_1tu05_193 {\n			width: 24px;\n			height: 24px;\n			border: none;\n			border-radius: 4px;\n			background: rgba(255, 255, 255, 0.1);\n			color: white;\n			cursor: pointer;\n			display: flex;\n			align-items: center;\n			justify-content: center;\n			font-size: 12px;\n			line-height: 1;\n\n			&:hover {\n				background: rgba(255, 255, 255, 0.2);\n			}\n		}\n\n		._stopButton_1tu05_212 {\n			background: rgba(239, 68, 68, 0.2);\n			color: rgb(255, 41, 41);\n			font-weight: 600;\n\n			&:hover {\n				background: rgba(239, 68, 68, 0.3);\n			}\n		}\n	}\n}\n\n@keyframes _statusTextFadeIn_1tu05_1 {\n	0% {\n		opacity: 0;\n		transform: translateY(5px);\n	}\n	100% {\n		opacity: 1;\n		transform: translateY(0);\n	}\n}\n\n@keyframes _statusTextFadeOut_1tu05_1 {\n	0% {\n		opacity: 1;\n		transform: translateY(0);\n	}\n	100% {\n		opacity: 0;\n		transform: translateY(-5px);\n	}\n}\n\n._historySectionWrapper_1tu05_246 {\n	position: absolute;\n	width: var(--history-width);\n	bottom: var(--height);\n	left: var(--side-space);\n	z-index: -2;\n\n	padding-top: 0px;\n	visibility: collapse;\n	overflow: hidden;\n\n	transition: all 0.2s;\n\n	background: rgba(2, 0, 20, 0.5);\n	/* background: rgba(186, 186, 186, 0.2); */\n	backdrop-filter: blur(10px);\n\n	text-shadow: 0 0 1px rgba(0, 0, 0, 0.2);\n\n	border-top-left-radius: calc(var(--border-radius) + 4px);\n	border-top-right-radius: calc(var(--border-radius) + 4px);\n\n	/* border: 2px solid rgba(255, 255, 255, 0.8); */\n	border: 2px solid rgba(255, 255, 255, 0.4);\n	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.6);\n\n	/* @media (prefers-color-scheme: dark) {\n		box-shadow:\n			0 8px 32px 0 rgba(0, 0, 0, 0.85),\n			0 2px 12px 0 rgba(57, 182, 255, 0.1);\n	} */\n\n	._expanded_1tu05_278 & {\n		padding-top: 8px;\n		visibility: visible;\n	}\n\n	._historySection_1tu05_246 {\n		position: relative;\n		overflow-y: auto;\n		overscroll-behavior: contain;\n		scrollbar-width: none;\n		max-height: 0;\n		padding-inline: 8px;\n\n		transition: max-height 0.2s;\n\n		._expanded_1tu05_278 & {\n			max-height: min(500px, calc(100vh - 200px - var(--height)));\n		}\n\n		._historyItem_1tu05_297 {\n			/* backdrop-filter: blur(10px); */\n			padding: 8px 10px;\n			margin-bottom: 6px;\n			background: linear-gradient(135deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0.03));\n			border-radius: 8px;\n			border-left: 2px solid rgba(57, 182, 255, 0.5);\n			font-size: 12px;\n			color: white;\n			/* color: black; */\n			line-height: 1.3;\n			position: relative;\n			overflow: hidden;\n\n			/* \u5FAE\u5999\u7684\u5185\u9634\u5F71 */\n			box-shadow:\n				inset 0 1px 0 rgba(255, 255, 255, 0.1),\n				0 1px 3px rgba(0, 0, 0, 0.1);\n\n			&::before {\n				content: '';\n				position: absolute;\n				top: 0;\n				left: 0;\n				right: 0;\n				height: 1px;\n				background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);\n			}\n\n			&:hover {\n				background: linear-gradient(135deg, rgba(255, 255, 255, 0.12), rgba(255, 255, 255, 0.06));\n				/* transform: translateY(-1px); */\n				box-shadow:\n					inset 0 1px 0 rgba(255, 255, 255, 0.15),\n					0 2px 4px rgba(0, 0, 0, 0.15);\n			}\n\n			&:last-child {\n				margin-bottom: 10px;\n			}\n\n			&._completed_1tu05_153,\n			&._input_1tu05_154,\n			&._output_1tu05_155 {\n				border-left-color: rgb(34, 197, 94);\n				background: linear-gradient(135deg, rgba(34, 197, 94, 0.1), rgba(34, 197, 94, 0.05));\n			}\n\n			&._error_1tu05_160 {\n				border-left-color: rgb(239, 68, 68);\n				background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(239, 68, 68, 0.05));\n			}\n\n			&._retry_1tu05_147 {\n				border-left-color: rgb(255, 214, 0);\n				background: linear-gradient(135deg, rgba(255, 214, 0, 0.1), rgba(255, 214, 0, 0.05));\n			}\n\n			&._observation_1tu05_355 {\n				border-left-color: rgb(147, 51, 234);\n				background: linear-gradient(135deg, rgba(147, 51, 234, 0.1), rgba(147, 51, 234, 0.05));\n			}\n\n			&._question_1tu05_360 {\n				border-left-color: rgb(255, 159, 67);\n				background: linear-gradient(135deg, rgba(255, 159, 67, 0.15), rgba(255, 159, 67, 0.08));\n			}\n\n			/* \u7A81\u51FA\u663E\u793A done \u6210\u529F\u7ED3\u679C */\n			&._doneSuccess_1tu05_366 {\n				background: linear-gradient(\n					135deg,\n					rgba(34, 197, 94, 0.25),\n					rgba(34, 197, 94, 0.15),\n					rgba(34, 197, 94, 0.08)\n				);\n				border: none;\n				border-left: 4px solid rgb(34, 197, 94);\n				box-shadow:\n					0 4px 12px rgba(34, 197, 94, 0.3),\n					inset 0 1px 0 rgba(255, 255, 255, 0.2),\n					0 0 20px rgba(34, 197, 94, 0.1);\n				font-weight: 600;\n				color: rgb(220, 252, 231);\n				padding: 10px 12px;\n				margin-bottom: 8px;\n				border-radius: 8px;\n				position: relative;\n				overflow: hidden;\n\n				&::before {\n					background: linear-gradient(90deg, transparent, rgba(34, 197, 94, 0.4), transparent);\n				}\n\n				&::after {\n					content: '';\n					position: absolute;\n					top: 0;\n					left: -100%;\n					width: 100%;\n					height: 100%;\n					background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);\n					animation: _shimmer_1tu05_1 2s ease-in-out infinite;\n				}\n\n				._historyContent_1tu05_402 {\n					._statusIcon_1tu05_403 {\n						font-size: 16px;\n						animation: _celebrate_1tu05_1 0.8s ease-in-out;\n						filter: drop-shadow(0 2px 4px rgba(34, 197, 94, 0.5));\n					}\n				}\n			}\n\n			/* \u7A81\u51FA\u663E\u793A done \u5931\u8D25\u7ED3\u679C */\n			&._doneError_1tu05_412 {\n				background: linear-gradient(\n					135deg,\n					rgba(239, 68, 68, 0.25),\n					rgba(239, 68, 68, 0.15),\n					rgba(239, 68, 68, 0.08)\n				);\n				border: none;\n				border-left: 4px solid rgb(239, 68, 68);\n				box-shadow:\n					0 4px 12px rgba(239, 68, 68, 0.3),\n					inset 0 1px 0 rgba(255, 255, 255, 0.2),\n					0 0 20px rgba(239, 68, 68, 0.1);\n				font-weight: 600;\n				color: rgb(254, 226, 226);\n				padding: 10px 12px;\n				margin-bottom: 8px;\n				border-radius: 8px;\n				position: relative;\n				overflow: hidden;\n\n				&::before {\n					background: linear-gradient(90deg, transparent, rgba(239, 68, 68, 0.4), transparent);\n				}\n\n				._historyContent_1tu05_402 {\n					._statusIcon_1tu05_403 {\n						font-size: 16px;\n						filter: drop-shadow(0 2px 4px rgba(239, 68, 68, 0.5));\n					}\n				}\n			}\n\n			._historyContent_1tu05_402 {\n				display: flex;\n				align-items: flex-start;\n				gap: 8px;\n\n				word-break: break-all;\n				white-space: pre-wrap;\n\n				/* overflow-x: auto; */\n\n				._statusIcon_1tu05_403 {\n					font-size: 12px;\n					flex-shrink: 0;\n					line-height: 1;\n					transition: all 0.3s ease;\n				}\n\n				._reflectionLines_1tu05_462 {\n					display: flex;\n					flex-direction: column;\n					gap: 4px;\n				}\n			}\n\n			._historyMeta_1tu05_469 {\n				font-size: 10px;\n				color: rgba(255, 255, 255, 0.6);\n				/* color: rgb(61, 61, 61); */\n				margin-top: 8px;\n				line-height: 1;\n			}\n		}\n	}\n}\n\n/* \u52A8\u753B\u5173\u952E\u5E27 - \u66F4\u5FEB\u7684\u95EA\u70C1 */\n@keyframes _pulse_1tu05_1 {\n	0%,\n	100% {\n		opacity: 1;\n		transform: scale(1);\n	}\n	50% {\n		opacity: 0.4;\n		transform: scale(1.3);\n	}\n}\n\n/* \u91CD\u8BD5\u52A8\u753B - \u65CB\u8F6C\u8109\u51B2 */\n@keyframes _retryPulse_1tu05_1 {\n	0%,\n	100% {\n		opacity: 1;\n		transform: scale(1) rotate(0deg);\n	}\n	25% {\n		opacity: 0.6;\n		transform: scale(1.2) rotate(90deg);\n	}\n	50% {\n		opacity: 0.8;\n		transform: scale(1.1) rotate(180deg);\n	}\n	75% {\n		opacity: 0.6;\n		transform: scale(1.2) rotate(270deg);\n	}\n}\n\n/* \u5E86\u795D\u52A8\u753B */\n@keyframes _celebrate_1tu05_1 {\n	0%,\n	100% {\n		transform: scale(1);\n	}\n	25% {\n		transform: scale(1.2) rotate(-5deg);\n	}\n	75% {\n		transform: scale(1.2) rotate(5deg);\n	}\n}\n\n/* done \u5361\u7247\u7684\u5149\u6CFD\u6548\u679C */\n@keyframes _shimmer_1tu05_1 {\n	0% {\n		left: -100%;\n	}\n	100% {\n		left: 100%;\n	}\n}\n\n/* \u8F93\u5165\u533A\u57DF\u6837\u5F0F */\n._inputSectionWrapper_1tu05_539 {\n	position: absolute;\n	width: var(--history-width);\n	top: var(--height);\n	left: var(--side-space);\n	z-index: -1;\n\n	visibility: visible;\n	overflow: hidden;\n\n	height: 48px;\n\n	transition: all 0.2s;\n\n	background: rgba(186, 186, 186, 0.2);\n	backdrop-filter: blur(10px);\n\n	border-bottom-left-radius: calc(var(--border-radius) + 4px);\n	border-bottom-right-radius: calc(var(--border-radius) + 4px);\n\n	border: 2px solid rgba(255, 255, 255, 0.3);\n	box-shadow: 0 1px 16px rgba(0, 0, 0, 0.4);\n\n	&._hidden_1tu05_562 {\n		visibility: collapse;\n		height: 0;\n	}\n\n	._inputSection_1tu05_539 {\n		display: flex;\n		align-items: center;\n		gap: 4px;\n		padding: 8px 8px;\n\n		._taskInput_1tu05_573 {\n			flex: 1;\n			background: rgba(255, 255, 255, 0.4);\n			border: 1px solid rgba(255, 255, 255, 0.3);\n			border-radius: 10px;\n			padding-inline: 10px;\n			color: rgb(20, 20, 20);\n			font-size: 12px;\n			height: 28px;\n			line-height: 1;\n			outline: none;\n			transition: all 0.2s ease;\n\n			/* text-shadow: 0 0 2px rgba(255, 255, 255, 0.8); */\n\n			/* border-color: rgba(57, 182, 255, 0.3); */\n\n			&::placeholder {\n				color: rgb(53, 53, 53);\n			}\n\n			&:focus {\n				background: rgba(255, 255, 255, 0.8);\n				border-color: rgba(57, 182, 255, 0.6);\n				box-shadow: 0 0 0 2px rgba(57, 182, 255, 0.2);\n			}\n		}\n	}\n}"));
        document.head.appendChild(elementStyle);
      }
    } catch (e) {
      console.error("vite-plugin-css-injected-by-js", e);
    }
  })();
  var locales = {
    "en-US": { ui: {
      panel: {
        ready: "Ready",
        thinking: "Thinking...",
        taskInput: "Enter new task, describe steps in detail, press Enter to submit",
        userAnswerPrompt: "Please answer the question above, press Enter to submit",
        taskTerminated: "Task terminated",
        taskCompleted: "Task completed",
        userAnswer: "User answer: {{input}}",
        question: "Question: {{question}}",
        waitingPlaceholder: "Waiting for task to start...",
        stop: "Stop",
        close: "Close",
        expand: "Expand history",
        collapse: "Collapse history",
        step: "Step {{number}}"
      },
      tools: {
        clicking: "Clicking element [{{index}}]...",
        inputting: "Inputting text to element [{{index}}]...",
        selecting: 'Selecting option "{{text}}"...',
        scrolling: "Scrolling page...",
        waiting: "Waiting {{seconds}} seconds...",
        askingUser: "Asking user...",
        done: "Task done",
        clicked: "\u{1F5B1}\uFE0F Clicked element [{{index}}]",
        inputted: '\u2328\uFE0F Inputted text "{{text}}"',
        selected: '\u2611\uFE0F Selected option "{{text}}"',
        scrolled: "\u{1F6DE} Page scrolled",
        waited: "\u231B\uFE0F Wait completed",
        executing: "Executing {{toolName}}...",
        resultSuccess: "success",
        resultFailure: "failed",
        resultError: "error"
      },
      errors: {
        elementNotFound: "No interactive element found at index {{index}}",
        taskRequired: "Task description is required",
        executionFailed: "Task execution failed",
        notInputElement: "Element is not an input or textarea",
        notSelectElement: "Element is not a select element",
        optionNotFound: 'Option "{{text}}" not found'
      }
    } },
    "zh-CN": { ui: {
      panel: {
        ready: "\u51C6\u5907\u5C31\u7EEA",
        thinking: "\u6B63\u5728\u601D\u8003...",
        taskInput: "\u8F93\u5165\u65B0\u4EFB\u52A1\uFF0C\u8BE6\u7EC6\u63CF\u8FF0\u6B65\u9AA4\uFF0C\u56DE\u8F66\u63D0\u4EA4",
        userAnswerPrompt: "\u8BF7\u56DE\u7B54\u4E0A\u9762\u95EE\u9898\uFF0C\u56DE\u8F66\u63D0\u4EA4",
        taskTerminated: "\u4EFB\u52A1\u5DF2\u7EC8\u6B62",
        taskCompleted: "\u4EFB\u52A1\u7ED3\u675F",
        userAnswer: "\u7528\u6237\u56DE\u7B54: {{input}}",
        question: "\u8BE2\u95EE: {{question}}",
        waitingPlaceholder: "\u7B49\u5F85\u4EFB\u52A1\u5F00\u59CB...",
        stop: "\u7EC8\u6B62",
        close: "\u5173\u95ED",
        expand: "\u5C55\u5F00\u5386\u53F2",
        collapse: "\u6536\u8D77\u5386\u53F2",
        step: "\u6B65\u9AA4 {{number}}"
      },
      tools: {
        clicking: "\u6B63\u5728\u70B9\u51FB\u5143\u7D20 [{{index}}]...",
        inputting: "\u6B63\u5728\u8F93\u5165\u6587\u672C\u5230\u5143\u7D20 [{{index}}]...",
        selecting: '\u6B63\u5728\u9009\u62E9\u9009\u9879 "{{text}}"...',
        scrolling: "\u6B63\u5728\u6EDA\u52A8\u9875\u9762...",
        waiting: "\u7B49\u5F85 {{seconds}} \u79D2...",
        askingUser: "\u6B63\u5728\u8BE2\u95EE\u7528\u6237...",
        done: "\u7ED3\u675F\u4EFB\u52A1",
        clicked: "\u{1F5B1}\uFE0F \u5DF2\u70B9\u51FB\u5143\u7D20 [{{index}}]",
        inputted: '\u2328\uFE0F \u5DF2\u8F93\u5165\u6587\u672C "{{text}}"',
        selected: '\u2611\uFE0F \u5DF2\u9009\u62E9\u9009\u9879 "{{text}}"',
        scrolled: "\u{1F6DE} \u9875\u9762\u6EDA\u52A8\u5B8C\u6210",
        waited: "\u231B\uFE0F \u7B49\u5F85\u5B8C\u6210",
        executing: "\u6B63\u5728\u6267\u884C {{toolName}}...",
        resultSuccess: "\u6210\u529F",
        resultFailure: "\u5931\u8D25",
        resultError: "\u9519\u8BEF"
      },
      errors: {
        elementNotFound: "\u672A\u627E\u5230\u7D22\u5F15\u4E3A {{index}} \u7684\u4EA4\u4E92\u5143\u7D20",
        taskRequired: "\u4EFB\u52A1\u63CF\u8FF0\u4E0D\u80FD\u4E3A\u7A7A",
        executionFailed: "\u4EFB\u52A1\u6267\u884C\u5931\u8D25",
        notInputElement: "\u5143\u7D20\u4E0D\u662F\u8F93\u5165\u6846\u6216\u6587\u672C\u57DF",
        notSelectElement: "\u5143\u7D20\u4E0D\u662F\u9009\u62E9\u6846",
        optionNotFound: '\u672A\u627E\u5230\u9009\u9879 "{{text}}"'
      }
    } }
  };
  var I18n = class {
    constructor(language = "en-US") {
      __publicField(this, "language");
      __publicField(this, "translations");
      this.language = language in locales ? language : "en-US";
      this.translations = locales[this.language];
    }
    t(key, params) {
      const value = this.getNestedValue(this.translations, key);
      if (!value) {
        console.warn(`Translation key "${key}" not found for language "${this.language}"`);
        return key;
      }
      if (params) return this.interpolate(value, params);
      return value;
    }
    getNestedValue(obj, path) {
      return path.split(".").reduce((current, key) => current?.[key], obj);
    }
    interpolate(template, params) {
      return template.replace(/\{\{(\w+)\}\}/g, (match, key) => {
        return params[key] != null ? params[key].toString() : match;
      });
    }
    getLanguage() {
      return this.language;
    }
  };
  function truncate2(text, maxLength) {
    if (text.length > maxLength) return text.substring(0, maxLength) + "...";
    return text;
  }
  function escapeHtml(text) {
    return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
  }
  var Panel_module_default = {
    wrapper: "_wrapper_1tu05_1",
    "mask-running": "_mask-running_1tu05_1",
    background: "_background_1tu05_39",
    header: "_header_1tu05_99",
    pulse: "_pulse_1tu05_1",
    retryPulse: "_retryPulse_1tu05_1",
    statusTextFadeOut: "_statusTextFadeOut_1tu05_1",
    statusTextFadeIn: "_statusTextFadeIn_1tu05_1",
    statusSection: "_statusSection_1tu05_121",
    indicator: "_indicator_1tu05_128",
    thinking: "_thinking_1tu05_137",
    tool_executing: "_tool_executing_1tu05_142",
    retry: "_retry_1tu05_147",
    completed: "_completed_1tu05_153",
    input: "_input_1tu05_154",
    output: "_output_1tu05_155",
    error: "_error_1tu05_160",
    statusText: "_statusText_1tu05_166",
    fadeOut: "_fadeOut_1tu05_178",
    fadeIn: "_fadeIn_1tu05_182",
    controls: "_controls_1tu05_188",
    controlButton: "_controlButton_1tu05_193",
    stopButton: "_stopButton_1tu05_212",
    historySectionWrapper: "_historySectionWrapper_1tu05_246",
    shimmer: "_shimmer_1tu05_1",
    celebrate: "_celebrate_1tu05_1",
    expanded: "_expanded_1tu05_278",
    historySection: "_historySection_1tu05_246",
    historyItem: "_historyItem_1tu05_297",
    observation: "_observation_1tu05_355",
    question: "_question_1tu05_360",
    doneSuccess: "_doneSuccess_1tu05_366",
    historyContent: "_historyContent_1tu05_402",
    statusIcon: "_statusIcon_1tu05_403",
    doneError: "_doneError_1tu05_412",
    reflectionLines: "_reflectionLines_1tu05_462",
    historyMeta: "_historyMeta_1tu05_469",
    inputSectionWrapper: "_inputSectionWrapper_1tu05_539",
    hidden: "_hidden_1tu05_562",
    inputSection: "_inputSection_1tu05_539",
    taskInput: "_taskInput_1tu05_573"
  };
  function createCard({ icon, content, meta: meta2, type }) {
    const typeClass = type ? Panel_module_default[type] : "";
    const contentHtml = Array.isArray(content) ? `<div class="${Panel_module_default.reflectionLines}">${content.map((line) => `<span>${escapeHtml(line)}</span>`).join("")}</div>` : `<span>${escapeHtml(content)}</span>`;
    return `
		<div class="${Panel_module_default.historyItem} ${typeClass}">
			<div class="${Panel_module_default.historyContent}">
				<span class="${Panel_module_default.statusIcon}">${icon}</span>
				${contentHtml}
			</div>
			${meta2 ? `<div class="${Panel_module_default.historyMeta}">${meta2}</div>` : ""}
		</div>
	`;
  }
  function createReflectionLines(reflection) {
    const lines = [];
    if (reflection.evaluation_previous_goal) lines.push(`\u{1F50D} ${reflection.evaluation_previous_goal}`);
    if (reflection.memory) lines.push(`\u{1F4BE} ${reflection.memory}`);
    if (reflection.next_goal) lines.push(`\u{1F3AF} ${reflection.next_goal}`);
    return lines;
  }
  var _wrapper, _indicator, _statusText, _historySection, _expandButton, _actionButton, _inputSection, _taskInput, _agent, _config, _isExpanded, _i18n, _userAnswerResolver, _isWaitingForUserAnswer, _headerUpdateTimer, _pendingHeaderText, _isAnimating, _onStatusChange, _onHistoryChange, _onActivity, _onAgentDispose, _Panel_instances, handleStatusChange_fn, handleHistoryChange_fn, handleActivity_fn, askUser_fn, removeTempCards_fn, getToolExecutingText_fn, handleActionButton_fn, submitTask_fn, handleUserAnswer_fn, showInputArea_fn, hideInputArea_fn, shouldShowInputArea_fn, createWrapper_fn, setupEventListeners_fn, toggle_fn, expand_fn, collapse_fn, startHeaderUpdateLoop_fn, stopHeaderUpdateLoop_fn, checkAndUpdateHeader_fn, animateTextChange_fn, updateStatusIndicator_fn, scrollToBottom_fn, renderHistory_fn, createTaskCard_fn, createHistoryCards_fn, createActionCards_fn, _a5;
  var Panel = (_a5 = class {
    /**
    * Create a Panel bound to an agent
    * @param agent - Agent instance that implements PanelAgentAdapter
    * @param config - Optional panel configuration
    */
    constructor(agent, config2 = {}) {
      __privateAdd(this, _Panel_instances);
      __privateAdd(this, _wrapper);
      __privateAdd(this, _indicator);
      __privateAdd(this, _statusText);
      __privateAdd(this, _historySection);
      __privateAdd(this, _expandButton);
      __privateAdd(this, _actionButton);
      __privateAdd(this, _inputSection);
      __privateAdd(this, _taskInput);
      __privateAdd(this, _agent);
      __privateAdd(this, _config);
      __privateAdd(this, _isExpanded, false);
      __privateAdd(this, _i18n);
      __privateAdd(this, _userAnswerResolver, null);
      __privateAdd(this, _isWaitingForUserAnswer, false);
      __privateAdd(this, _headerUpdateTimer, null);
      __privateAdd(this, _pendingHeaderText, null);
      __privateAdd(this, _isAnimating, false);
      __privateAdd(this, _onStatusChange, () => __privateMethod(this, _Panel_instances, handleStatusChange_fn).call(this));
      __privateAdd(this, _onHistoryChange, () => __privateMethod(this, _Panel_instances, handleHistoryChange_fn).call(this));
      __privateAdd(this, _onActivity, (e) => __privateMethod(this, _Panel_instances, handleActivity_fn).call(this, e.detail));
      __privateAdd(this, _onAgentDispose, () => this.dispose());
      __privateSet(this, _agent, agent);
      __privateSet(this, _config, config2);
      __privateSet(this, _i18n, new I18n(config2.language ?? "en-US"));
      __privateGet(this, _agent).onAskUser = (question, options) => __privateMethod(this, _Panel_instances, askUser_fn).call(this, question, options?.signal);
      __privateSet(this, _wrapper, __privateMethod(this, _Panel_instances, createWrapper_fn).call(this));
      __privateSet(this, _indicator, __privateGet(this, _wrapper).querySelector(`.${Panel_module_default.indicator}`));
      __privateSet(this, _statusText, __privateGet(this, _wrapper).querySelector(`.${Panel_module_default.statusText}`));
      __privateSet(this, _historySection, __privateGet(this, _wrapper).querySelector(`.${Panel_module_default.historySection}`));
      __privateSet(this, _expandButton, __privateGet(this, _wrapper).querySelector(`.${Panel_module_default.expandButton}`));
      __privateSet(this, _actionButton, __privateGet(this, _wrapper).querySelector(`.${Panel_module_default.stopButton}`));
      __privateSet(this, _inputSection, __privateGet(this, _wrapper).querySelector(`.${Panel_module_default.inputSectionWrapper}`));
      __privateSet(this, _taskInput, __privateGet(this, _wrapper).querySelector(`.${Panel_module_default.taskInput}`));
      __privateGet(this, _agent).addEventListener("statuschange", __privateGet(this, _onStatusChange));
      __privateGet(this, _agent).addEventListener("historychange", __privateGet(this, _onHistoryChange));
      __privateGet(this, _agent).addEventListener("activity", __privateGet(this, _onActivity));
      __privateGet(this, _agent).addEventListener("dispose", __privateGet(this, _onAgentDispose));
      __privateMethod(this, _Panel_instances, setupEventListeners_fn).call(this);
      __privateMethod(this, _Panel_instances, startHeaderUpdateLoop_fn).call(this);
      __privateMethod(this, _Panel_instances, showInputArea_fn).call(this);
      this.hide();
    }
    get wrapper() {
      return __privateGet(this, _wrapper);
    }
    show() {
      this.wrapper.style.display = "block";
      this.wrapper.offsetHeight;
      this.wrapper.style.opacity = "1";
      this.wrapper.style.transform = "translateX(-50%) translateY(0)";
    }
    hide() {
      this.wrapper.style.opacity = "0";
      this.wrapper.style.transform = "translateX(-50%) translateY(20px)";
      this.wrapper.style.display = "none";
    }
    reset() {
      __privateGet(this, _statusText).textContent = __privateGet(this, _i18n).t("ui.panel.ready");
      __privateMethod(this, _Panel_instances, updateStatusIndicator_fn).call(this, "thinking");
      __privateMethod(this, _Panel_instances, renderHistory_fn).call(this);
      __privateMethod(this, _Panel_instances, collapse_fn).call(this);
      __privateSet(this, _isWaitingForUserAnswer, false);
      __privateSet(this, _userAnswerResolver, null);
      __privateMethod(this, _Panel_instances, showInputArea_fn).call(this);
    }
    expand() {
      __privateMethod(this, _Panel_instances, expand_fn).call(this);
    }
    collapse() {
      __privateMethod(this, _Panel_instances, collapse_fn).call(this);
    }
    /**
    * Dispose panel and clean up event listeners
    */
    dispose() {
      __privateGet(this, _agent).removeEventListener("statuschange", __privateGet(this, _onStatusChange));
      __privateGet(this, _agent).removeEventListener("historychange", __privateGet(this, _onHistoryChange));
      __privateGet(this, _agent).removeEventListener("activity", __privateGet(this, _onActivity));
      __privateGet(this, _agent).removeEventListener("dispose", __privateGet(this, _onAgentDispose));
      __privateSet(this, _isWaitingForUserAnswer, false);
      __privateMethod(this, _Panel_instances, stopHeaderUpdateLoop_fn).call(this);
      this.wrapper.remove();
    }
  }, _wrapper = new WeakMap(), _indicator = new WeakMap(), _statusText = new WeakMap(), _historySection = new WeakMap(), _expandButton = new WeakMap(), _actionButton = new WeakMap(), _inputSection = new WeakMap(), _taskInput = new WeakMap(), _agent = new WeakMap(), _config = new WeakMap(), _isExpanded = new WeakMap(), _i18n = new WeakMap(), _userAnswerResolver = new WeakMap(), _isWaitingForUserAnswer = new WeakMap(), _headerUpdateTimer = new WeakMap(), _pendingHeaderText = new WeakMap(), _isAnimating = new WeakMap(), _onStatusChange = new WeakMap(), _onHistoryChange = new WeakMap(), _onActivity = new WeakMap(), _onAgentDispose = new WeakMap(), _Panel_instances = new WeakSet(), /** Handle agent status change */
  handleStatusChange_fn = function() {
    const status = __privateGet(this, _agent).status;
    const failed = status === "completed" && __privateGet(this, _agent).lastResult?.success === false;
    __privateMethod(this, _Panel_instances, updateStatusIndicator_fn).call(this, failed ? "error" : status);
    if (status === "running") {
      __privateGet(this, _actionButton).textContent = "\u25A0";
      __privateGet(this, _actionButton).title = __privateGet(this, _i18n).t("ui.panel.stop");
    } else {
      __privateGet(this, _actionButton).textContent = "X";
      __privateGet(this, _actionButton).title = __privateGet(this, _i18n).t("ui.panel.close");
    }
    if (status === "running") {
      this.show();
      __privateMethod(this, _Panel_instances, hideInputArea_fn).call(this);
    }
    if (status === "completed" || status === "error" || status === "stopped") {
      if (!__privateGet(this, _isExpanded)) __privateMethod(this, _Panel_instances, expand_fn).call(this);
      if (__privateMethod(this, _Panel_instances, shouldShowInputArea_fn).call(this)) __privateMethod(this, _Panel_instances, showInputArea_fn).call(this);
    }
  }, /** Handle agent history change - re-render history list from agent.history */
  handleHistoryChange_fn = function() {
    __privateMethod(this, _Panel_instances, renderHistory_fn).call(this);
  }, /**
  * Handle agent activity - transient state for immediate UI feedback
  * Activity events are NOT persisted in history, only used for header bar updates
  */
  handleActivity_fn = function(activity) {
    switch (activity.type) {
      case "thinking":
        __privateSet(this, _pendingHeaderText, __privateGet(this, _i18n).t("ui.panel.thinking"));
        __privateMethod(this, _Panel_instances, updateStatusIndicator_fn).call(this, "thinking");
        break;
      case "executing":
        __privateSet(this, _pendingHeaderText, __privateMethod(this, _Panel_instances, getToolExecutingText_fn).call(this, activity.tool, activity.input));
        __privateMethod(this, _Panel_instances, updateStatusIndicator_fn).call(this, "executing");
        break;
      case "executed":
        __privateSet(this, _pendingHeaderText, truncate2(activity.output, 50));
        break;
      case "retrying":
        __privateSet(this, _pendingHeaderText, `Retrying (${activity.attempt}/${activity.maxAttempts})`);
        __privateMethod(this, _Panel_instances, updateStatusIndicator_fn).call(this, "retrying");
        break;
      case "error":
        __privateSet(this, _pendingHeaderText, truncate2(activity.message, 50));
        __privateMethod(this, _Panel_instances, updateStatusIndicator_fn).call(this, "error");
        break;
    }
  }, /**
  * Ask for user input (internal, called by agent via onAskUser).
  * Rejects when `signal` aborts (task stopped or disposed), cleaning up the
  * question card and pending state so the agent loop can settle.
  */
  askUser_fn = function(question, signal2) {
    return new Promise((resolve, reject) => {
      __privateSet(this, _isWaitingForUserAnswer, true);
      __privateSet(this, _userAnswerResolver, resolve);
      if (!__privateGet(this, _isExpanded)) __privateMethod(this, _Panel_instances, expand_fn).call(this);
      const tempCard = document.createElement("div");
      tempCard.innerHTML = createCard({
        icon: "\u2753",
        content: `Question: ${question}`,
        type: "question"
      });
      const cardElement = tempCard.firstElementChild;
      cardElement.setAttribute("data-temp-card", "true");
      __privateGet(this, _historySection).appendChild(cardElement);
      __privateMethod(this, _Panel_instances, scrollToBottom_fn).call(this);
      __privateMethod(this, _Panel_instances, showInputArea_fn).call(this, __privateGet(this, _i18n).t("ui.panel.userAnswerPrompt"));
      signal2?.addEventListener("abort", () => {
        __privateMethod(this, _Panel_instances, removeTempCards_fn).call(this);
        __privateSet(this, _isWaitingForUserAnswer, false);
        __privateSet(this, _userAnswerResolver, null);
        reject(signal2.reason);
      }, { once: true });
    });
  }, /** Remove temporary question cards (only direct children for safety) */
  removeTempCards_fn = function() {
    Array.from(__privateGet(this, _historySection).children).forEach((child) => {
      if (child.getAttribute("data-temp-card") === "true") child.remove();
    });
  }, getToolExecutingText_fn = function(toolName, args) {
    const a = args;
    switch (toolName) {
      case "click_element_by_index":
        return __privateGet(this, _i18n).t("ui.tools.clicking", { index: a.index });
      case "input_text":
        return __privateGet(this, _i18n).t("ui.tools.inputting", { index: a.index });
      case "select_dropdown_option":
        return __privateGet(this, _i18n).t("ui.tools.selecting", { text: a.text });
      case "scroll":
        return __privateGet(this, _i18n).t("ui.tools.scrolling");
      case "wait":
        return __privateGet(this, _i18n).t("ui.tools.waiting", { seconds: a.seconds });
      case "ask_user":
        return __privateGet(this, _i18n).t("ui.tools.askingUser");
      case "done":
        return __privateGet(this, _i18n).t("ui.tools.done");
      default:
        return __privateGet(this, _i18n).t("ui.tools.executing", { toolName });
    }
  }, /**
  * Action button handler: stop when running, close (dispose) when idle
  */
  handleActionButton_fn = function() {
    if (__privateGet(this, _agent).status === "running") __privateGet(this, _agent).stop();
    else __privateGet(this, _agent).dispose();
  }, /**
  * Submit task
  */
  submitTask_fn = function() {
    const input = __privateGet(this, _taskInput).value.trim();
    if (!input) return;
    __privateMethod(this, _Panel_instances, hideInputArea_fn).call(this);
    if (__privateGet(this, _isWaitingForUserAnswer)) __privateMethod(this, _Panel_instances, handleUserAnswer_fn).call(this, input);
    else __privateGet(this, _agent).execute(input);
  }, /**
  * Handle user answer
  */
  handleUserAnswer_fn = function(input) {
    __privateMethod(this, _Panel_instances, removeTempCards_fn).call(this);
    __privateSet(this, _isWaitingForUserAnswer, false);
    if (__privateGet(this, _userAnswerResolver)) {
      __privateGet(this, _userAnswerResolver).call(this, input);
      __privateSet(this, _userAnswerResolver, null);
    }
  }, /**
  * Show input area
  */
  showInputArea_fn = function(placeholder) {
    __privateGet(this, _taskInput).value = "";
    __privateGet(this, _taskInput).placeholder = placeholder || __privateGet(this, _i18n).t("ui.panel.taskInput");
    __privateGet(this, _inputSection).classList.remove(Panel_module_default.hidden);
    setTimeout(() => {
      __privateGet(this, _taskInput).focus();
    }, 100);
  }, /**
  * Hide input area
  */
  hideInputArea_fn = function() {
    __privateGet(this, _inputSection).classList.add(Panel_module_default.hidden);
  }, /**
  * Check if input area should be shown
  */
  shouldShowInputArea_fn = function() {
    if (__privateGet(this, _isWaitingForUserAnswer)) return true;
    if (__privateGet(this, _agent).history.length === 0) return true;
    const status = __privateGet(this, _agent).status;
    if (status === "completed" || status === "error" || status === "stopped") return __privateGet(this, _config).promptForNextTask ?? true;
    return false;
  }, createWrapper_fn = function() {
    const taskInputMaxLength = 1e3;
    const wrapper = document.createElement("div");
    wrapper.id = "page-agent-runtime_agent-panel";
    wrapper.className = Panel_module_default.wrapper;
    wrapper.setAttribute("data-browser-use-ignore", "true");
    wrapper.setAttribute("data-page-agent-ignore", "true");
    wrapper.innerHTML = `
			<div class="${Panel_module_default.background}"></div>
			<div class="${Panel_module_default.historySectionWrapper}">
				<div class="${Panel_module_default.historySection}">
					<div class="${Panel_module_default.historyItem}">
						<div class="${Panel_module_default.historyContent}">
							<span class="${Panel_module_default.statusIcon}">\u{1F9E0}</span>
							<span>${__privateGet(this, _i18n).t("ui.panel.waitingPlaceholder")}</span>
						</div>
					</div>
				</div>
			</div>
			<div class="${Panel_module_default.header}">
				<div class="${Panel_module_default.statusSection}">
					<div class="${Panel_module_default.indicator} ${Panel_module_default.thinking}"></div>
					<div class="${Panel_module_default.statusText}">${__privateGet(this, _i18n).t("ui.panel.ready")}</div>
				</div>
				<div class="${Panel_module_default.controls}">
					<button class="${Panel_module_default.controlButton} ${Panel_module_default.expandButton}" title="${__privateGet(this, _i18n).t("ui.panel.expand")}">
						\u25BC
					</button>
					<button class="${Panel_module_default.controlButton} ${Panel_module_default.stopButton}" title="${__privateGet(this, _i18n).t("ui.panel.close")}">
						X
					</button>
				</div>
			</div>
			<div class="${Panel_module_default.inputSectionWrapper} ${Panel_module_default.hidden}">
				<div class="${Panel_module_default.inputSection}">
					<input 
						type="text" 
						class="${Panel_module_default.taskInput}" 
						maxlength="${taskInputMaxLength}"
					/>
				</div>
			</div>
		`;
    document.body.appendChild(wrapper);
    return wrapper;
  }, setupEventListeners_fn = function() {
    this.wrapper.querySelector(`.${Panel_module_default.header}`).addEventListener("click", (e) => {
      if (e.target.closest(`.${Panel_module_default.controlButton}`)) return;
      __privateMethod(this, _Panel_instances, toggle_fn).call(this);
    });
    __privateGet(this, _expandButton).addEventListener("click", (e) => {
      e.stopPropagation();
      __privateMethod(this, _Panel_instances, toggle_fn).call(this);
    });
    __privateGet(this, _actionButton).addEventListener("click", (e) => {
      e.stopPropagation();
      __privateMethod(this, _Panel_instances, handleActionButton_fn).call(this);
    });
    __privateGet(this, _taskInput).addEventListener("keydown", (e) => {
      if (e.isComposing) return;
      if (e.key === "Enter") {
        e.preventDefault();
        __privateMethod(this, _Panel_instances, submitTask_fn).call(this);
      }
    });
    __privateGet(this, _inputSection).addEventListener("click", (e) => {
      e.stopPropagation();
    });
  }, toggle_fn = function() {
    if (__privateGet(this, _isExpanded)) __privateMethod(this, _Panel_instances, collapse_fn).call(this);
    else __privateMethod(this, _Panel_instances, expand_fn).call(this);
  }, expand_fn = function() {
    __privateSet(this, _isExpanded, true);
    this.wrapper.classList.add(Panel_module_default.expanded);
    __privateGet(this, _expandButton).textContent = "\u25B2";
  }, collapse_fn = function() {
    __privateSet(this, _isExpanded, false);
    this.wrapper.classList.remove(Panel_module_default.expanded);
    __privateGet(this, _expandButton).textContent = "\u25BC";
  }, /**
  * Start periodic header update loop
  */
  startHeaderUpdateLoop_fn = function() {
    __privateSet(this, _headerUpdateTimer, setInterval(() => {
      __privateMethod(this, _Panel_instances, checkAndUpdateHeader_fn).call(this);
    }, 450));
  }, /**
  * Stop periodic header update loop
  */
  stopHeaderUpdateLoop_fn = function() {
    if (__privateGet(this, _headerUpdateTimer)) {
      clearInterval(__privateGet(this, _headerUpdateTimer));
      __privateSet(this, _headerUpdateTimer, null);
    }
  }, /**
  * Check if header needs update and trigger animation if not currently animating
  */
  checkAndUpdateHeader_fn = function() {
    if (!__privateGet(this, _pendingHeaderText) || __privateGet(this, _isAnimating)) return;
    if (__privateGet(this, _statusText).textContent === __privateGet(this, _pendingHeaderText)) {
      __privateSet(this, _pendingHeaderText, null);
      return;
    }
    const textToShow = __privateGet(this, _pendingHeaderText);
    __privateSet(this, _pendingHeaderText, null);
    __privateMethod(this, _Panel_instances, animateTextChange_fn).call(this, textToShow);
  }, /**
  * Animate text change with fade out/in effect
  */
  animateTextChange_fn = function(newText) {
    __privateSet(this, _isAnimating, true);
    __privateGet(this, _statusText).classList.add(Panel_module_default.fadeOut);
    setTimeout(() => {
      __privateGet(this, _statusText).textContent = newText;
      __privateGet(this, _statusText).classList.remove(Panel_module_default.fadeOut);
      __privateGet(this, _statusText).classList.add(Panel_module_default.fadeIn);
      setTimeout(() => {
        __privateGet(this, _statusText).classList.remove(Panel_module_default.fadeIn);
        __privateSet(this, _isAnimating, false);
      }, 300);
    }, 150);
  }, updateStatusIndicator_fn = function(type) {
    const variant = type === "running" ? "thinking" : type;
    __privateGet(this, _indicator).className = Panel_module_default.indicator;
    if (variant !== "idle" && variant !== "stopped") __privateGet(this, _indicator).classList.add(Panel_module_default[variant]);
  }, scrollToBottom_fn = function() {
    setTimeout(() => {
      __privateGet(this, _historySection).scrollTop = __privateGet(this, _historySection).scrollHeight;
    }, 0);
  }, /**
  * Render history directly from agent.history
  *
  * Renders:
  * 1. Task (first item, from agent.task)
  * 2. Reflection cards (evaluation, memory, next_goal)
  * 3. Tool execution with output
  * 4. Observations
  */
  renderHistory_fn = function() {
    const items = [];
    const task = __privateGet(this, _agent).task;
    if (task) items.push(__privateMethod(this, _Panel_instances, createTaskCard_fn).call(this, task));
    const history = __privateGet(this, _agent).history;
    for (const event of history) items.push(...__privateMethod(this, _Panel_instances, createHistoryCards_fn).call(this, event));
    __privateGet(this, _historySection).innerHTML = items.join("");
    __privateMethod(this, _Panel_instances, scrollToBottom_fn).call(this);
  }, createTaskCard_fn = function(task) {
    return createCard({
      icon: "\u{1F3AF}",
      content: task,
      type: "input"
    });
  }, /** Create cards for a history event */
  createHistoryCards_fn = function(event) {
    const cards = [];
    const meta2 = event.type === "step" && event.stepIndex !== void 0 ? __privateGet(this, _i18n).t("ui.panel.step", { number: (event.stepIndex + 1).toString() }) : void 0;
    if (event.type === "step") {
      if (event.reflection) {
        const lines = createReflectionLines(event.reflection);
        if (lines.length > 0) cards.push(createCard({
          icon: "\u{1F9E0}",
          content: lines,
          meta: meta2
        }));
      }
      const action = event.action;
      if (action) cards.push(...__privateMethod(this, _Panel_instances, createActionCards_fn).call(this, action, meta2));
    } else if (event.type === "observation") cards.push(createCard({
      icon: "\u{1F441}\uFE0F",
      content: event.content || "",
      meta: meta2,
      type: "observation"
    }));
    else if (event.type === "user_takeover") cards.push(createCard({
      icon: "\u{1F464}",
      content: "User takeover",
      meta: meta2,
      type: "input"
    }));
    else if (event.type === "retry") {
      const retryInfo = `${event.message || "Retrying"} (${event.attempt}/${event.maxAttempts})`;
      cards.push(createCard({
        icon: "\u{1F504}",
        content: retryInfo,
        meta: meta2,
        type: "observation"
      }));
    } else if (event.type === "error") cards.push(createCard({
      icon: "\u274C",
      content: event.message || "Error",
      meta: meta2,
      type: "observation"
    }));
    return cards;
  }, /** Create cards for an action */
  createActionCards_fn = function(action, meta2) {
    const cards = [];
    if (action.name === "done") {
      const text = action.input.text || action.output || "";
      if (text) cards.push(createCard({
        icon: "\u{1F916}",
        content: text,
        meta: meta2,
        type: "output"
      }));
    } else if (action.name === "ask_user") {
      const input = action.input;
      const answer = action.output.replace(/^User answered:\s*/i, "");
      cards.push(createCard({
        icon: "\u2753",
        content: `Question: ${input.question || ""}`,
        meta: meta2,
        type: "question"
      }));
      cards.push(createCard({
        icon: "\u{1F4AC}",
        content: `Answer: ${answer}`,
        meta: meta2,
        type: "input"
      }));
    } else {
      const toolText = __privateMethod(this, _Panel_instances, getToolExecutingText_fn).call(this, action.name, action.input);
      cards.push(createCard({
        icon: "\u{1F528}",
        content: toolText,
        meta: meta2
      }));
      if (action.output?.length > 0) cards.push(createCard({
        icon: "\u{1F528}",
        content: action.output,
        meta: meta2,
        type: "output"
      }));
    }
    return cards;
  }, _a5);

  // node_modules/page-agent/dist/esm/page-agent.js
  var PageAgent = class extends PageAgentCore {
    constructor(config2) {
      const pageController = new PageController({
        ...config2,
        enableMask: config2.enableMask ?? true
      });
      super({
        ...config2,
        pageController
      });
      __publicField(this, "panel");
      this.panel = new Panel(this, {
        language: config2.language,
        promptForNextTask: config2.promptForNextTask
      });
    }
  };
  return __toCommonJS(page_agent_exports);
})();
/*! Bundled license information:

ai-motion/build/Motion.js:
  (**
   * AI Motion - WebGL2 animated border with AI-style glow effects
   *
   * @author Simon<gaomeng1900@gmail.com>
   * @license MIT
   * @repository https://github.com/gaomeng1900/ai-motion
   *)
*/
