var __defProp = Object.defineProperty;
var __export = (target, all) => {
  for (var name in all)
    __defProp(target, name, {
      get: all[name],
      enumerable: true,
      configurable: true,
      set: (newValue) => all[name] = () => newValue
    });
};

// src/_check-rsc.ts
import { createContext } from "react";
if (typeof createContext !== "function") {
  const err = [
    'Remotion requires React.createContext, but it is "undefined".',
    'If you are in a React Server Component, turn it into a client component by adding "use client" at the top of the file.',
    "",
    "Before:",
    '  import {useCurrentFrame} from "remotion";',
    "",
    "After:",
    '  "use client";',
    '  import {useCurrentFrame} from "remotion";'
  ];
  throw new Error(err.join(`
`));
}

// src/Clipper.tsx
var Clipper = () => {
  throw new Error("<Clipper> has been removed as of Remotion v4.0.228. The native clipping APIs were experimental and subject to removal at any time. We removed them because they were sparingly used and made rendering often slower rather than faster.");
};

// src/Composition.tsx
import { Suspense, useCallback as useCallback4, useContext as useContext9, useEffect as useEffect2 } from "react";
import { createPortal } from "react-dom";

// src/CanUseRemotionHooks.tsx
import { createContext as createContext2 } from "react";
import { jsx } from "react/jsx-runtime";
var CanUseRemotionHooks = createContext2(false);
var CanUseRemotionHooksProvider = ({ children }) => {
  return /* @__PURE__ */ jsx(CanUseRemotionHooks.Provider, {
    value: true,
    children
  });
};

// src/composition-render-error-context.ts
import { createContext as createContext3 } from "react";
var CompositionRenderErrorContext = createContext3({
  setError: () => {},
  clearError: () => {}
});

// src/CompositionErrorBoundary.tsx
import React2 from "react";

class CompositionErrorBoundary extends React2.Component {
  state = { hasError: false };
  static getDerivedStateFromError() {
    return { hasError: true };
  }
  componentDidCatch(error) {
    this.props.onError(error);
  }
  componentDidUpdate(_prevProps) {
    if (!this.state.hasError) {
      this.props.onClear();
    }
  }
  render() {
    if (this.state.hasError) {
      return null;
    }
    return this.props.children;
  }
}

// src/CompositionManagerContext.tsx
import { createContext as createContext4 } from "react";
var CompositionManager = createContext4({
  compositions: [],
  folders: [],
  currentCompositionMetadata: null,
  canvasContent: null
});
var CompositionSetters = createContext4({
  registerComposition: () => {
    return;
  },
  unregisterComposition: () => {
    return;
  },
  registerFolder: () => {
    return;
  },
  unregisterFolder: () => {
    return;
  },
  setCanvasContent: () => {
    return;
  },
  onlyRenderComposition: null
});

// src/Folder.tsx
import { createContext as createContext6, useContext as useContext2, useEffect, useMemo as useMemo2 } from "react";

// src/nonce.ts
import { createContext as createContext5, useCallback, useContext, useMemo, useRef } from "react";
var NonceContext = createContext5({
  getNonce: () => 0
});
var fastRefreshNonce = 0;
try {
  if (typeof __webpack_module__ !== "undefined") {
    if (__webpack_module__.hot) {
      __webpack_module__.hot.addStatusHandler((status) => {
        if (status === "idle") {
          fastRefreshNonce++;
        }
      });
    }
  }
} catch {}
var useNonce = () => {
  const context = useContext(NonceContext);
  const nonce = context.getNonce();
  const nonceRef = useRef(nonce);
  nonceRef.current = nonce;
  const history = useRef([[fastRefreshNonce, nonce]]);
  const get = useCallback(() => {
    if (fastRefreshNonce !== history.current[history.current.length - 1][0]) {
      history.current = [
        ...history.current,
        [fastRefreshNonce, nonceRef.current]
      ];
    }
    return history.current;
  }, [history]);
  return useMemo(() => {
    return { get };
  }, [get]);
};

// src/truthy.ts
function truthy(value) {
  return Boolean(value);
}

// src/validation/validate-folder-name.ts
var getRegex = () => /^([a-zA-Z0-9-\u4E00-\u9FFF])+$/g;
var isFolderNameValid = (name) => name.match(getRegex());
var validateFolderName = (name) => {
  if (name === undefined || name === null) {
    throw new TypeError("You must pass a name to a <Folder />.");
  }
  if (typeof name !== "string") {
    throw new TypeError(`The "name" you pass into <Folder /> must be a string. Got: ${typeof name}`);
  }
  if (!isFolderNameValid(name)) {
    throw new Error(`Folder name can only contain a-z, A-Z, 0-9 and -. You passed ${name}`);
  }
};
var invalidFolderNameErrorMessage = `Folder name must match ${String(getRegex())}`;

// src/Folder.tsx
import { jsx as jsx2 } from "react/jsx-runtime";
var FolderContext = createContext6({
  folderName: null,
  parentName: null
});
var Folder = ({ name, children }) => {
  const parent = useContext2(FolderContext);
  const { registerFolder, unregisterFolder } = useContext2(CompositionSetters);
  const nonce = useNonce();
  validateFolderName(name);
  const parentNameArr = [parent.parentName, parent.folderName].filter(truthy);
  const parentName = parentNameArr.length === 0 ? null : parentNameArr.join("/");
  const value = useMemo2(() => {
    return {
      folderName: name,
      parentName
    };
  }, [name, parentName]);
  useEffect(() => {
    registerFolder(name, parentName, nonce.get());
    return () => {
      unregisterFolder(name, parentName);
    };
  }, [
    name,
    parent.folderName,
    parentName,
    registerFolder,
    unregisterFolder,
    nonce
  ]);
  return /* @__PURE__ */ jsx2(FolderContext.Provider, {
    value,
    children
  });
};

// src/get-remotion-environment.ts
function getNodeEnvString() {
  return ["NOD", "E_EN", "V"].join("");
}
var getEnvString = () => {
  return ["e", "nv"].join("");
};
var getRemotionEnvironment = () => {
  const isPlayer = typeof window !== "undefined" && window.remotion_isPlayer;
  const isRendering = typeof window !== "undefined" && typeof window.process !== "undefined" && typeof window.process.env !== "undefined" && (window.process[getEnvString()][getNodeEnvString()] === "test" || window.process[getEnvString()][getNodeEnvString()] === "production" && typeof window !== "undefined" && typeof window.remotion_puppeteerTimeout !== "undefined");
  const isStudio = typeof window !== "undefined" && window.remotion_isStudio;
  const isReadOnlyStudio = typeof window !== "undefined" && window.remotion_isReadOnlyStudio;
  return {
    isStudio,
    isRendering,
    isPlayer,
    isReadOnlyStudio,
    isClientSideRendering: false
  };
};

// src/input-props-serialization.ts
var DATE_TOKEN = "remotion-date:";
var FILE_TOKEN = "remotion-file:";
var serializeJSONWithSpecialTypes = ({
  data,
  indent,
  staticBase
}) => {
  let customDateUsed = false;
  let customFileUsed = false;
  let mapUsed = false;
  let setUsed = false;
  try {
    const serializedString = JSON.stringify(data, function(key, value) {
      const item = this[key];
      if (item instanceof Date) {
        customDateUsed = true;
        return `${DATE_TOKEN}${item.toISOString()}`;
      }
      if (item instanceof Map) {
        mapUsed = true;
        return value;
      }
      if (item instanceof Set) {
        setUsed = true;
        return value;
      }
      if (typeof item === "string" && staticBase !== null && item.startsWith(staticBase)) {
        customFileUsed = true;
        return `${FILE_TOKEN}${item.replace(staticBase + "/", "")}`;
      }
      return value;
    }, indent);
    return { serializedString, customDateUsed, customFileUsed, mapUsed, setUsed };
  } catch (err) {
    throw new Error("Could not serialize the passed input props to JSON: " + err.message);
  }
};
var deserializeJSONWithSpecialTypes = (data) => {
  return JSON.parse(data, (_, value) => {
    if (typeof value === "string" && value.startsWith(DATE_TOKEN)) {
      return new Date(value.replace(DATE_TOKEN, ""));
    }
    if (typeof value === "string" && value.startsWith(FILE_TOKEN)) {
      return `${window.remotion_staticBase}/${value.replace(FILE_TOKEN, "")}`;
    }
    return value;
  });
};
var serializeThenDeserialize = (props) => {
  return deserializeJSONWithSpecialTypes(serializeJSONWithSpecialTypes({
    data: props,
    indent: 2,
    staticBase: window.remotion_staticBase
  }).serializedString);
};
var serializeThenDeserializeInStudio = (props) => {
  if (getRemotionEnvironment().isStudio) {
    return serializeThenDeserialize(props);
  }
  return props;
};

// src/is-player.tsx
import { createContext as createContext7, useContext as useContext3 } from "react";
import { jsx as jsx3 } from "react/jsx-runtime";
var IsPlayerContext = createContext7(false);
var IsPlayerContextProvider = ({
  children
}) => {
  return /* @__PURE__ */ jsx3(IsPlayerContext.Provider, {
    value: true,
    children
  });
};
var useIsPlayer = () => {
  return useContext3(IsPlayerContext);
};

// src/AbsoluteFill.tsx
import { forwardRef, useMemo as useMemo3 } from "react";
import { jsx as jsx4 } from "react/jsx-runtime";
var hasTailwindClassName = ({
  className,
  classPrefix,
  type
}) => {
  if (!className) {
    return false;
  }
  if (type === "exact") {
    const split = className.split(" ");
    return classPrefix.some((token) => {
      return split.some((part) => {
        return part.trim() === token || part.trim().endsWith(`:${token}`) || part.trim().endsWith(`!${token}`);
      });
    });
  }
  return classPrefix.some((prefix) => {
    return className.startsWith(prefix) || className.includes(` ${prefix}`) || className.includes(`!${prefix}`) || className.includes(`:${prefix}`);
  });
};
var AbsoluteFillRefForwarding = (props, ref) => {
  const { style, ...other } = props;
  const actualStyle = useMemo3(() => {
    return {
      position: "absolute",
      top: hasTailwindClassName({
        className: other.className,
        classPrefix: ["top-", "inset-"],
        type: "prefix"
      }) ? undefined : 0,
      left: hasTailwindClassName({
        className: other.className,
        classPrefix: ["left-", "inset-"],
        type: "prefix"
      }) ? undefined : 0,
      right: hasTailwindClassName({
        className: other.className,
        classPrefix: ["right-", "inset-"],
        type: "prefix"
      }) ? undefined : 0,
      bottom: hasTailwindClassName({
        className: other.className,
        classPrefix: ["bottom-", "inset-"],
        type: "prefix"
      }) ? undefined : 0,
      width: hasTailwindClassName({
        className: other.className,
        classPrefix: ["w-"],
        type: "prefix"
      }) ? undefined : "100%",
      height: hasTailwindClassName({
        className: other.className,
        classPrefix: ["h-"],
        type: "prefix"
      }) ? undefined : "100%",
      display: hasTailwindClassName({
        className: other.className,
        classPrefix: [
          "block",
          "inline-block",
          "inline",
          "flex",
          "inline-flex",
          "flow-root",
          "grid",
          "inline-grid",
          "contents",
          "list-item",
          "hidden"
        ],
        type: "exact"
      }) ? undefined : "flex",
      flexDirection: hasTailwindClassName({
        className: other.className,
        classPrefix: [
          "flex-row",
          "flex-col",
          "flex-row-reverse",
          "flex-col-reverse"
        ],
        type: "exact"
      }) ? undefined : "column",
      ...style
    };
  }, [other.className, style]);
  return /* @__PURE__ */ jsx4("div", {
    ref,
    style: actualStyle,
    ...other
  });
};
var AbsoluteFill = forwardRef(AbsoluteFillRefForwarding);

// src/loading-indicator.tsx
import { jsx as jsx5, jsxs } from "react/jsx-runtime";
var rotate = {
  transform: `rotate(90deg)`
};
var ICON_SIZE = 40;
var label = {
  color: "white",
  fontSize: 14,
  fontFamily: "sans-serif"
};
var container = {
  justifyContent: "center",
  alignItems: "center"
};
var Loading = () => {
  return /* @__PURE__ */ jsxs(AbsoluteFill, {
    style: container,
    id: "remotion-comp-loading",
    children: [
      /* @__PURE__ */ jsx5("style", {
        type: "text/css",
        children: `
				@keyframes anim {
					from {
						opacity: 0
					}
					to {
						opacity: 1
					}
				}
				#remotion-comp-loading {
					animation: anim 2s;
					animation-fill-mode: forwards;
				}
			`
      }),
      /* @__PURE__ */ jsx5("svg", {
        width: ICON_SIZE,
        height: ICON_SIZE,
        viewBox: "-100 -100 400 400",
        style: rotate,
        children: /* @__PURE__ */ jsx5("path", {
          fill: "#555",
          stroke: "#555",
          strokeWidth: "100",
          strokeLinejoin: "round",
          d: "M 2 172 a 196 100 0 0 0 195 5 A 196 240 0 0 0 100 2.259 A 196 240 0 0 0 2 172 z"
        })
      }),
      /* @__PURE__ */ jsxs("p", {
        style: label,
        children: [
          "Resolving ",
          "<Suspense>",
          "..."
        ]
      })
    ]
  });
};

// src/portal-node.ts
var _portalNode = null;
var portalNode = () => {
  if (!_portalNode) {
    if (typeof document === "undefined") {
      throw new Error("Tried to call an API that only works in the browser from outside the browser");
    }
    _portalNode = document.createElement("div");
    _portalNode.style.position = "absolute";
    _portalNode.style.top = "0px";
    _portalNode.style.left = "0px";
    _portalNode.style.right = "0px";
    _portalNode.style.bottom = "0px";
    _portalNode.style.width = "100%";
    _portalNode.style.height = "100%";
    _portalNode.style.display = "flex";
    _portalNode.style.flexDirection = "column";
    const containerNode = document.createElement("div");
    containerNode.style.position = "fixed";
    containerNode.style.top = -999999 + "px";
    containerNode.appendChild(_portalNode);
    document.body.appendChild(containerNode);
  }
  return _portalNode;
};

// src/ResolveCompositionConfig.tsx
import { createContext as createContext9, createRef, useContext as useContext5, useMemo as useMemo5 } from "react";

// src/input-props-override.ts
var getKey = () => {
  return `remotion_inputPropsOverride` + window.location.origin;
};
var getInputPropsOverride = () => {
  if (typeof localStorage === "undefined")
    return null;
  const override = localStorage.getItem(getKey());
  if (!override)
    return null;
  return JSON.parse(override);
};
var setInputPropsOverride = (override) => {
  if (typeof localStorage === "undefined")
    return;
  if (override === null) {
    localStorage.removeItem(getKey());
    return;
  }
  localStorage.setItem(getKey(), JSON.stringify(override));
};

// src/config/input-props.ts
var didWarnSSRImport = false;
var warnOnceSSRImport = () => {
  if (didWarnSSRImport) {
    return;
  }
  didWarnSSRImport = true;
  console.warn("Called `getInputProps()` on the server. This function is not available server-side and has returned an empty object.");
  console.warn("To hide this warning, don't call this function on the server:");
  console.warn("  typeof window === 'undefined' ? {} : getInputProps()");
};
var getInputProps = () => {
  if (typeof window === "undefined") {
    warnOnceSSRImport();
    return {};
  }
  if (getRemotionEnvironment().isPlayer) {
    throw new Error("You cannot call `getInputProps()` from a <Player>. Instead, the props are available as React props from component that you passed as `component` prop.");
  }
  const override = getInputPropsOverride();
  if (override) {
    return override;
  }
  if (typeof window === "undefined" || typeof window.remotion_inputProps === "undefined") {
    throw new Error("Cannot call `getInputProps()` - window.remotion_inputProps is not set. This API is only available if you are in the Studio, or while you are rendering server-side.");
  }
  const param = window.remotion_inputProps;
  if (!param) {
    return {};
  }
  const parsed = deserializeJSONWithSpecialTypes(param);
  return parsed;
};

// src/EditorProps.tsx
import React4, { createContext as createContext8, useCallback as useCallback2, useMemo as useMemo4 } from "react";
import { jsx as jsx6 } from "react/jsx-runtime";
var EditorPropsContext = createContext8({
  props: {},
  updateProps: () => {
    throw new Error("Not implemented");
  }
});
var timeValueRef = React4.createRef();
var EditorPropsProvider = ({ children }) => {
  const [props, setProps] = React4.useState({});
  const updateProps = useCallback2(({
    defaultProps,
    id,
    newProps
  }) => {
    setProps((prev) => {
      return {
        ...prev,
        [id]: typeof newProps === "function" ? newProps(prev[id] ?? defaultProps) : newProps
      };
    });
  }, []);
  const ctx = useMemo4(() => {
    return { props, updateProps };
  }, [props, updateProps]);
  return /* @__PURE__ */ jsx6(EditorPropsContext.Provider, {
    value: ctx,
    children
  });
};

// src/use-remotion-environment.ts
import { useContext as useContext4, useState } from "react";

// src/remotion-environment-context.ts
import React5 from "react";
var RemotionEnvironmentContext = React5.createContext(null);

// src/use-remotion-environment.ts
var useRemotionEnvironment = () => {
  const context = useContext4(RemotionEnvironmentContext);
  const [env] = useState(() => getRemotionEnvironment());
  return context ?? env;
};

// src/validation/validate-dimensions.ts
function validateDimension(amount, nameOfProp, location) {
  if (typeof amount !== "number") {
    throw new Error(`The "${nameOfProp}" prop ${location} must be a number, but you passed a value of type ${typeof amount}`);
  }
  if (isNaN(amount)) {
    throw new TypeError(`The "${nameOfProp}" prop ${location} must not be NaN, but is NaN.`);
  }
  if (!Number.isFinite(amount)) {
    throw new TypeError(`The "${nameOfProp}" prop ${location} must be finite, but is ${amount}.`);
  }
  if (amount % 1 !== 0) {
    throw new TypeError(`The "${nameOfProp}" prop ${location} must be an integer, but is ${amount}.`);
  }
  if (amount <= 0) {
    throw new TypeError(`The "${nameOfProp}" prop ${location} must be positive, but got ${amount}.`);
  }
}

// src/validation/validate-duration-in-frames.ts
function validateDurationInFrames(durationInFrames, options) {
  const { allowFloats, component } = options;
  if (typeof durationInFrames === "undefined") {
    throw new Error(`The "durationInFrames" prop ${component} is missing.`);
  }
  if (typeof durationInFrames !== "number") {
    throw new Error(`The "durationInFrames" prop ${component} must be a number, but you passed a value of type ${typeof durationInFrames}`);
  }
  if (durationInFrames <= 0) {
    throw new TypeError(`The "durationInFrames" prop ${component} must be positive, but got ${durationInFrames}.`);
  }
  if (!allowFloats && durationInFrames % 1 !== 0) {
    throw new TypeError(`The "durationInFrames" prop ${component} must be an integer, but got ${durationInFrames}.`);
  }
  if (!Number.isFinite(durationInFrames)) {
    throw new TypeError(`The "durationInFrames" prop ${component} must be finite, but got ${durationInFrames}.`);
  }
}

// src/validation/validate-fps.ts
function validateFps(fps, location, isGif) {
  if (typeof fps !== "number") {
    throw new Error(`"fps" must be a number, but you passed a value of type ${typeof fps} ${location}`);
  }
  if (!Number.isFinite(fps)) {
    throw new Error(`"fps" must be a finite, but you passed ${fps} ${location}`);
  }
  if (isNaN(fps)) {
    throw new Error(`"fps" must not be NaN, but got ${fps} ${location}`);
  }
  if (fps <= 0) {
    throw new TypeError(`"fps" must be positive, but got ${fps} ${location}`);
  }
  if (isGif && fps > 50) {
    throw new TypeError(`The FPS for a GIF cannot be higher than 50. Use the --every-nth-frame option to lower the FPS: https://remotion.dev/docs/render-as-gif`);
  }
}

// src/ResolveCompositionConfig.tsx
var ResolveCompositionContext = createContext9(null);
var resolveCompositionsRef = createRef();
var needsResolution = (composition) => {
  return Boolean(composition.calculateMetadata);
};
var useResolvedVideoConfig = (preferredCompositionId) => {
  const context = useContext5(ResolveCompositionContext);
  const { props: allEditorProps } = useContext5(EditorPropsContext);
  const { compositions, canvasContent, currentCompositionMetadata } = useContext5(CompositionManager);
  const currentComposition = canvasContent?.type === "composition" ? canvasContent.compositionId : null;
  const compositionId = preferredCompositionId ?? currentComposition;
  const composition = compositions.find((c) => c.id === compositionId);
  const selectedEditorProps = useMemo5(() => {
    return composition ? allEditorProps[composition.id] ?? {} : {};
  }, [allEditorProps, composition]);
  const env = useRemotionEnvironment();
  return useMemo5(() => {
    if (!composition) {
      return null;
    }
    if (currentCompositionMetadata) {
      return {
        type: "success",
        result: {
          ...currentCompositionMetadata,
          id: composition.id,
          defaultProps: composition.defaultProps ?? {}
        }
      };
    }
    if (!needsResolution(composition)) {
      validateDurationInFrames(composition.durationInFrames, {
        allowFloats: false,
        component: `in <Composition id="${composition.id}">`
      });
      validateFps(composition.fps, `in <Composition id="${composition.id}">`, false);
      validateDimension(composition.width, "width", `in <Composition id="${composition.id}">`);
      validateDimension(composition.height, "height", `in <Composition id="${composition.id}">`);
      return {
        type: "success",
        result: {
          width: composition.width,
          height: composition.height,
          fps: composition.fps,
          id: composition.id,
          durationInFrames: composition.durationInFrames,
          defaultProps: composition.defaultProps ?? {},
          props: {
            ...composition.defaultProps ?? {},
            ...selectedEditorProps ?? {},
            ...typeof window === "undefined" || env.isPlayer || !window.remotion_inputProps ? {} : getInputProps() ?? {}
          },
          defaultCodec: null,
          defaultOutName: null,
          defaultVideoImageFormat: null,
          defaultPixelFormat: null,
          defaultProResProfile: null,
          defaultSampleRate: null
        }
      };
    }
    if (!context) {
      return null;
    }
    if (!context[composition.id]) {
      return null;
    }
    return context[composition.id];
  }, [
    composition,
    context,
    currentCompositionMetadata,
    selectedEditorProps,
    env.isPlayer
  ]);
};

// src/use-delay-render.tsx
import { createContext as createContext11, useCallback as useCallback3, useContext as useContext7 } from "react";

// src/cancel-render.ts
var getErrorStackWithMessage = (error) => {
  const stack = error.stack ?? "";
  return stack.startsWith("Error:") ? stack : `${error.message}
${stack}`;
};
var isErrorLike = (err) => {
  if (err instanceof Error) {
    return true;
  }
  if (err === null) {
    return false;
  }
  if (typeof err !== "object") {
    return false;
  }
  if (!("stack" in err)) {
    return false;
  }
  if (typeof err.stack !== "string") {
    return false;
  }
  if (!("message" in err)) {
    return false;
  }
  if (typeof err.message !== "string") {
    return false;
  }
  return true;
};
function cancelRenderInternal(scope, err) {
  let error;
  if (isErrorLike(err)) {
    error = err;
    if (!error.stack) {
      error.stack = new Error(error.message).stack;
    }
  } else if (typeof err === "string") {
    error = Error(err);
  } else {
    error = Error("Rendering was cancelled");
  }
  if (scope) {
    scope.remotion_cancelledError = getErrorStackWithMessage(error);
  }
  throw error;
}
function cancelRender(err) {
  return cancelRenderInternal(typeof window !== "undefined" ? window : undefined, err);
}

// src/log.ts
var logLevels = ["trace", "verbose", "info", "warn", "error"];
var getNumberForLogLevel = (level) => {
  return logLevels.indexOf(level);
};
var isEqualOrBelowLogLevel = (currentLevel, level) => {
  return getNumberForLogLevel(currentLevel) <= getNumberForLogLevel(level);
};
var transformArgs = ({
  args,
  logLevel,
  tag
}) => {
  const arr = [...args];
  if (getRemotionEnvironment().isRendering && !getRemotionEnvironment().isClientSideRendering) {
    arr.unshift(Symbol.for(`__remotion_level_${logLevel}`));
  }
  if (tag && getRemotionEnvironment().isRendering && !getRemotionEnvironment().isClientSideRendering) {
    arr.unshift(Symbol.for(`__remotion_tag_${tag}`));
  }
  return arr;
};
var verbose = (options, ...args) => {
  if (isEqualOrBelowLogLevel(options.logLevel, "verbose")) {
    return console.debug(...transformArgs({ args, logLevel: "verbose", tag: options.tag }));
  }
};
var trace = (options, ...args) => {
  if (isEqualOrBelowLogLevel(options.logLevel, "trace")) {
    return console.debug(...transformArgs({ args, logLevel: "trace", tag: options.tag }));
  }
};
var info = (options, ...args) => {
  if (isEqualOrBelowLogLevel(options.logLevel, "info")) {
    return console.log(...transformArgs({ args, logLevel: "info", tag: options.tag }));
  }
};
var warn = (options, ...args) => {
  if (isEqualOrBelowLogLevel(options.logLevel, "warn")) {
    return console.warn(...transformArgs({ args, logLevel: "warn", tag: options.tag }));
  }
};
var error = (options, ...args) => {
  return console.error(...transformArgs({ args, logLevel: "error", tag: options.tag }));
};
var Log = {
  trace,
  verbose,
  info,
  warn,
  error
};

// src/delay-render.ts
if (typeof window !== "undefined") {
  window.remotion_renderReady = false;
  if (!window.remotion_delayRenderTimeouts) {
    window.remotion_delayRenderTimeouts = {};
  }
  window.remotion_delayRenderHandles = [];
}
var DELAY_RENDER_CALLSTACK_TOKEN = "The delayRender was called:";
var DELAY_RENDER_RETRIES_LEFT = "Retries left: ";
var DELAY_RENDER_RETRY_TOKEN = "- Rendering the frame will be retried.";
var DELAY_RENDER_CLEAR_TOKEN = "handle was cleared after";
var defaultTimeout = 30000;
var delayRenderInternal = ({
  scope,
  environment,
  label: label2,
  options
}) => {
  if (typeof label2 !== "string" && label2 !== null) {
    throw new Error("The label parameter of delayRender() must be a string or undefined, got: " + JSON.stringify(label2));
  }
  const handle = Math.random();
  scope.remotion_delayRenderHandles.push(handle);
  const called = Error().stack?.replace(/^Error/g, "") ?? "";
  if (environment.isRendering) {
    const timeoutToUse = (options?.timeoutInMilliseconds ?? scope.remotion_puppeteerTimeout ?? defaultTimeout) - 2000;
    const retriesLeft = (options?.retries ?? 0) - (scope.remotion_attempt - 1);
    scope.remotion_delayRenderTimeouts[handle] = {
      label: label2 ?? null,
      startTime: Date.now(),
      timeout: setTimeout(() => {
        const message = [
          `A delayRender()`,
          label2 ? `"${label2}"` : null,
          `was called but not cleared after ${timeoutToUse}ms. See https://remotion.dev/docs/timeout for help.`,
          retriesLeft > 0 ? DELAY_RENDER_RETRIES_LEFT + retriesLeft : null,
          retriesLeft > 0 ? DELAY_RENDER_RETRY_TOKEN : null,
          DELAY_RENDER_CALLSTACK_TOKEN,
          called
        ].filter(truthy).join(" ");
        if (environment.isClientSideRendering) {
          scope.remotion_cancelledError = getErrorStackWithMessage(Error(message));
        } else {
          cancelRenderInternal(scope, Error(message));
        }
      }, timeoutToUse)
    };
  }
  scope.remotion_renderReady = false;
  return handle;
};
var delayRender = (label2, options) => {
  if (typeof window === "undefined") {
    return Math.random();
  }
  return delayRenderInternal({
    scope: window,
    environment: getRemotionEnvironment(),
    label: label2 ?? null,
    options: options ?? {}
  });
};
var continueRenderInternal = ({
  scope,
  handle,
  environment,
  logLevel
}) => {
  if (typeof handle === "undefined") {
    throw new TypeError("The continueRender() method must be called with a parameter that is the return value of delayRender(). No value was passed.");
  }
  if (typeof handle !== "number") {
    throw new TypeError("The parameter passed into continueRender() must be the return value of delayRender() which is a number. Got: " + JSON.stringify(handle));
  }
  scope.remotion_delayRenderHandles = scope.remotion_delayRenderHandles.filter((h) => {
    if (h === handle) {
      if (environment.isRendering && scope !== undefined) {
        if (!scope.remotion_delayRenderTimeouts[handle]) {
          return false;
        }
        const { label: label2, startTime, timeout } = scope.remotion_delayRenderTimeouts[handle];
        clearTimeout(timeout);
        const message = [
          label2 ? `"${label2}"` : "A handle",
          DELAY_RENDER_CLEAR_TOKEN,
          `${Date.now() - startTime}ms`
        ].filter(truthy).join(" ");
        Log.verbose({ logLevel, tag: "delayRender()" }, message);
        delete scope.remotion_delayRenderTimeouts[handle];
      }
      return false;
    }
    return true;
  });
  if (scope.remotion_delayRenderHandles.length === 0) {
    scope.remotion_renderReady = true;
  }
};
var continueRender = (handle) => {
  if (typeof window === "undefined") {
    return;
  }
  continueRenderInternal({
    scope: window,
    handle,
    environment: getRemotionEnvironment(),
    logLevel: window.remotion_logLevel ?? "info"
  });
};

// src/log-level-context.tsx
import { createContext as createContext10 } from "react";
import * as React6 from "react";
var LogLevelContext = createContext10({
  logLevel: "info",
  mountTime: 0
});
var useLogLevel = () => {
  const { logLevel } = React6.useContext(LogLevelContext);
  if (logLevel === null) {
    throw new Error("useLogLevel must be used within a LogLevelProvider");
  }
  return logLevel;
};
var useMountTime = () => {
  const { mountTime } = React6.useContext(LogLevelContext);
  if (mountTime === null) {
    throw new Error("useMountTime must be used within a LogLevelProvider");
  }
  return mountTime;
};

// src/use-delay-render.tsx
var DelayRenderContextType = createContext11(null);
var useDelayRender = () => {
  const environment = useRemotionEnvironment();
  const scope = useContext7(DelayRenderContextType) ?? (typeof window !== "undefined" ? window : undefined);
  const logLevel = useLogLevel();
  const delayRender2 = useCallback3((label2, options) => {
    if (!scope) {
      return Math.random();
    }
    return delayRenderInternal({
      scope,
      environment,
      label: label2 ?? null,
      options: options ?? {}
    });
  }, [environment, scope]);
  const continueRender2 = useCallback3((handle) => {
    if (!scope) {
      return;
    }
    continueRenderInternal({
      scope,
      handle,
      environment,
      logLevel
    });
  }, [environment, logLevel, scope]);
  const cancelRender2 = useCallback3((err) => {
    return cancelRenderInternal(scope ?? (typeof window !== "undefined" ? window : undefined), err);
  }, [scope]);
  return { delayRender: delayRender2, continueRender: continueRender2, cancelRender: cancelRender2 };
};

// src/use-lazy-component.ts
import React7, { useMemo as useMemo6, useRef as useRef2 } from "react";
var useLazyComponent = ({
  compProps,
  componentName,
  noSuspense
}) => {
  const componentRef = useRef2(null);
  if ("component" in compProps) {
    componentRef.current = compProps.component;
  }
  const lazy = useMemo6(() => {
    if ("component" in compProps) {
      if (typeof document === "undefined" || noSuspense) {
        return compProps.component;
      }
      if (typeof compProps.component === "undefined") {
        throw new Error(`A value of \`undefined\` was passed to the \`component\` prop. Check the value you are passing to the <${componentName}/> component.`);
      }
      const Wrapper = (props) => {
        const Comp = componentRef.current;
        return React7.createElement(Comp, props);
      };
      return Wrapper;
    }
    if ("lazyComponent" in compProps && typeof compProps.lazyComponent !== "undefined") {
      if (typeof compProps.lazyComponent === "undefined") {
        throw new Error(`A value of \`undefined\` was passed to the \`lazyComponent\` prop. Check the value you are passing to the <${componentName}/> component.`);
      }
      return React7.lazy(compProps.lazyComponent);
    }
    throw new Error("You must pass either 'component' or 'lazyComponent'");
  }, [compProps.lazyComponent]);
  return lazy;
};

// src/use-video.ts
import { useContext as useContext8, useMemo as useMemo7 } from "react";
var useVideo = () => {
  const { canvasContent, compositions, currentCompositionMetadata } = useContext8(CompositionManager);
  const selected = compositions.find((c) => {
    return canvasContent?.type === "composition" && c.id === canvasContent.compositionId;
  });
  const resolved = useResolvedVideoConfig(selected?.id ?? null);
  return useMemo7(() => {
    if (!resolved) {
      return null;
    }
    if (resolved.type === "error") {
      return null;
    }
    if (resolved.type === "loading") {
      return null;
    }
    if (!selected) {
      return null;
    }
    return {
      ...resolved.result,
      defaultProps: selected.defaultProps ?? {},
      id: selected.id,
      ...currentCompositionMetadata ?? {},
      component: selected.component
    };
  }, [currentCompositionMetadata, resolved, selected]);
};

// src/validation/validate-composition-id.ts
var getRegex2 = () => /^([a-zA-Z0-9-\u4E00-\u9FFF])+$/g;
var isCompositionIdValid = (id) => id.match(getRegex2());
var validateCompositionId = (id) => {
  if (!isCompositionIdValid(id)) {
    throw new Error(`Composition id can only contain a-z, A-Z, 0-9, CJK characters and -. You passed ${id}`);
  }
};
var invalidCompositionErrorMessage = `Composition ID must match ${String(getRegex2())}`;

// src/validation/validate-default-props.ts
var validateDefaultAndInputProps = (defaultProps, name, compositionId) => {
  if (!defaultProps) {
    return;
  }
  if (typeof defaultProps !== "object") {
    throw new Error(`"${name}" must be an object, but you passed a value of type ${typeof defaultProps}`);
  }
  if (Array.isArray(defaultProps)) {
    throw new Error(`"${name}" must be an object, an array was passed ${compositionId ? `for composition "${compositionId}"` : ""}`);
  }
};

// src/Composition.tsx
import { jsx as jsx7 } from "react/jsx-runtime";
var Fallback = () => {
  const { continueRender: continueRender2, delayRender: delayRender2 } = useDelayRender();
  useEffect2(() => {
    const fallback = delayRender2("Waiting for Root component to unsuspend");
    return () => continueRender2(fallback);
  }, [continueRender2, delayRender2]);
  return null;
};
var InnerComposition = ({
  width,
  height,
  fps,
  durationInFrames,
  id,
  defaultProps,
  schema,
  ...compProps
}) => {
  const compManager = useContext9(CompositionSetters);
  const { registerComposition, unregisterComposition } = compManager;
  const video = useVideo();
  const lazy = useLazyComponent({
    compProps,
    componentName: "Composition",
    noSuspense: false
  });
  const nonce = useNonce();
  const isPlayer = useIsPlayer();
  const environment = useRemotionEnvironment();
  const canUseComposition = useContext9(CanUseRemotionHooks);
  if (typeof window !== "undefined") {
    window.remotion_seenCompositionIds = Array.from(new Set([...window.remotion_seenCompositionIds ?? [], id]));
  }
  if (canUseComposition) {
    if (isPlayer) {
      throw new Error("<Composition> was mounted inside the `component` that was passed to the <Player>. See https://remotion.dev/docs/wrong-composition-mount for help.");
    }
    throw new Error("<Composition> mounted inside another composition. See https://remotion.dev/docs/wrong-composition-mount for help.");
  }
  const { folderName, parentName } = useContext9(FolderContext);
  const stack = compProps.stack ?? null;
  useEffect2(() => {
    if (!id) {
      throw new Error("No id for composition passed.");
    }
    validateCompositionId(id);
    validateDefaultAndInputProps(defaultProps, "defaultProps", id);
    registerComposition({
      durationInFrames: durationInFrames ?? undefined,
      fps: fps ?? undefined,
      height: height ?? undefined,
      width: width ?? undefined,
      id,
      folderName,
      component: lazy,
      defaultProps: serializeThenDeserializeInStudio(defaultProps ?? {}),
      nonce: nonce.get(),
      parentFolderName: parentName,
      schema: schema ?? null,
      calculateMetadata: compProps.calculateMetadata ?? null,
      stack
    });
    return () => {
      unregisterComposition(id);
    };
  }, [
    durationInFrames,
    fps,
    height,
    lazy,
    id,
    folderName,
    defaultProps,
    width,
    nonce,
    parentName,
    schema,
    compProps.calculateMetadata,
    stack,
    registerComposition,
    unregisterComposition
  ]);
  const resolved = useResolvedVideoConfig(id);
  const { setError, clearError } = useContext9(CompositionRenderErrorContext);
  const onError = useCallback4((error2) => {
    setError(error2);
  }, [setError]);
  const onClear = useCallback4(() => {
    clearError();
  }, [clearError]);
  if (environment.isStudio && video && video.component === lazy && video.id === id) {
    const Comp = lazy;
    if (resolved === null || resolved.type !== "success" && resolved.type !== "success-and-refreshing") {
      return null;
    }
    return createPortal(/* @__PURE__ */ jsx7(CanUseRemotionHooksProvider, {
      children: /* @__PURE__ */ jsx7(CompositionErrorBoundary, {
        onError,
        onClear,
        children: /* @__PURE__ */ jsx7(Suspense, {
          fallback: /* @__PURE__ */ jsx7(Loading, {}),
          children: /* @__PURE__ */ jsx7(Comp, {
            ...resolved.result.props ?? {}
          })
        })
      })
    }), portalNode());
  }
  if (environment.isRendering && video && video.component === lazy && video.id === id) {
    const Comp = lazy;
    if (resolved === null || resolved.type !== "success" && resolved.type !== "success-and-refreshing") {
      return null;
    }
    return createPortal(/* @__PURE__ */ jsx7(CanUseRemotionHooksProvider, {
      children: /* @__PURE__ */ jsx7(Suspense, {
        fallback: /* @__PURE__ */ jsx7(Fallback, {}),
        children: /* @__PURE__ */ jsx7(Comp, {
          ...resolved.result.props ?? {}
        })
      })
    }), portalNode());
  }
  return null;
};
var Composition = (props) => {
  const { onlyRenderComposition } = useContext9(CompositionSetters);
  if (onlyRenderComposition && onlyRenderComposition !== props.id) {
    return null;
  }
  return /* @__PURE__ */ jsx7(InnerComposition, {
    ...props
  });
};

// src/effects/Solid.tsx
import { useEffect as useEffect4, useMemo as useMemo11, useState as useState3 } from "react";

// src/use-current-frame.ts
import { useContext as useContext11 } from "react";

// src/SequenceContext.tsx
import { createContext as createContext12 } from "react";
var SequenceContext = createContext12(null);

// src/timeline-position-state.ts
var exports_timeline_position_state = {};
__export(exports_timeline_position_state, {
  useTimelineSetFrame: () => useTimelineSetFrame,
  useTimelinePosition: () => useTimelinePosition,
  useTimelineContext: () => useTimelineContext,
  usePlayingState: () => usePlayingState,
  usePlaybackRate: () => usePlaybackRate,
  useAbsoluteTimelinePosition: () => useAbsoluteTimelinePosition,
  persistCurrentFrame: () => persistCurrentFrame,
  getInitialFrameState: () => getInitialFrameState,
  getFrameForComposition: () => getFrameForComposition
});
import { useContext as useContext10, useMemo as useMemo9 } from "react";

// src/TimelineContext.tsx
import {
  createContext as createContext13,
  useLayoutEffect,
  useMemo as useMemo8,
  useRef as useRef3,
  useState as useState2
} from "react";

// src/random.ts
function mulberry32(a) {
  let t = a + 1831565813;
  t = Math.imul(t ^ t >>> 15, t | 1);
  t ^= t + Math.imul(t ^ t >>> 7, t | 61);
  return ((t ^ t >>> 14) >>> 0) / 4294967296;
}
function hashCode(str) {
  let i = 0;
  let chr = 0;
  let hash = 0;
  for (i = 0;i < str.length; i++) {
    chr = str.charCodeAt(i);
    hash = (hash << 5) - hash + chr;
    hash |= 0;
  }
  return hash;
}
var random = (seed, dummy) => {
  if (dummy !== undefined) {
    throw new TypeError("random() takes only one argument");
  }
  if (seed === null) {
    return Math.random();
  }
  if (typeof seed === "string") {
    return mulberry32(hashCode(seed));
  }
  if (typeof seed === "number") {
    return mulberry32(seed * 10000000000);
  }
  throw new Error("random() argument must be a number or a string");
};

// src/TimelineContext.tsx
import { jsx as jsx8 } from "react/jsx-runtime";
var SetTimelineContext = createContext13({
  setFrame: () => {
    throw new Error("default");
  },
  setPlaying: () => {
    throw new Error("default");
  }
});
var TimelineContext = createContext13(null);
var PlaybackRateContext = createContext13(null);
var AbsoluteTimeContext = createContext13(null);
var TimelineContextProvider = ({ children, frameState }) => {
  const [playing, setPlaying] = useState2(false);
  const imperativePlaying = useRef3(false);
  const [playbackRate, setPlaybackRate] = useState2(1);
  const audioAndVideoTags = useRef3([]);
  const [remotionRootId] = useState2(() => String(random(null)));
  const [_frame, setFrame] = useState2(() => getInitialFrameState());
  const frame = frameState ?? _frame;
  const { delayRender: delayRender2, continueRender: continueRender2 } = useDelayRender();
  if (typeof window !== "undefined") {
    useLayoutEffect(() => {
      window.remotion_setFrame = (f, composition, attempt) => {
        window.remotion_attempt = attempt;
        const id = delayRender2(`Setting the current frame to ${f}`);
        let asyncUpdate = true;
        setFrame((s) => {
          const currentFrame = s[composition] ?? window.remotion_initialFrame;
          if (currentFrame === f) {
            asyncUpdate = false;
            return s;
          }
          return {
            ...s,
            [composition]: f
          };
        });
        if (asyncUpdate) {
          requestAnimationFrame(() => continueRender2(id));
        } else {
          continueRender2(id);
        }
      };
      window.remotion_isPlayer = false;
    }, [continueRender2, delayRender2]);
  }
  const timelineContextValue = useMemo8(() => {
    return {
      frame,
      playing,
      imperativePlaying,
      rootId: remotionRootId,
      audioAndVideoTags
    };
  }, [frame, playing, remotionRootId]);
  const playbackRateContextValue = useMemo8(() => {
    return {
      playbackRate,
      setPlaybackRate
    };
  }, [playbackRate]);
  const setTimelineContextValue = useMemo8(() => {
    return {
      setFrame,
      setPlaying
    };
  }, []);
  return /* @__PURE__ */ jsx8(AbsoluteTimeContext.Provider, {
    value: timelineContextValue,
    children: /* @__PURE__ */ jsx8(PlaybackRateContext.Provider, {
      value: playbackRateContextValue,
      children: /* @__PURE__ */ jsx8(TimelineContext.Provider, {
        value: timelineContextValue,
        children: /* @__PURE__ */ jsx8(SetTimelineContext.Provider, {
          value: setTimelineContextValue,
          children
        })
      })
    })
  });
};

// src/timeline-position-state.ts
var makeKey = () => {
  return `remotion.time-all`;
};
var persistCurrentFrame = (time) => {
  localStorage.setItem(makeKey(), JSON.stringify(time));
};
var getInitialFrameState = () => {
  const item = localStorage.getItem(makeKey()) ?? "{}";
  const obj = JSON.parse(item);
  return obj;
};
var getFrameForComposition = (composition) => {
  const item = localStorage.getItem(makeKey()) ?? "{}";
  const obj = JSON.parse(item);
  if (obj[composition] !== undefined) {
    return Number(obj[composition]);
  }
  if (typeof window === "undefined") {
    return 0;
  }
  return window.remotion_initialFrame ?? 0;
};
var useTimelinePositionFromContext = (state) => {
  const videoConfig = useVideo();
  const env = useRemotionEnvironment();
  if (!videoConfig) {
    return typeof window === "undefined" ? 0 : window.remotion_initialFrame ?? 0;
  }
  const unclamped = state.frame[videoConfig.id] ?? (env.isPlayer ? 0 : getFrameForComposition(videoConfig.id));
  return Math.min(videoConfig.durationInFrames - 1, unclamped);
};
var useTimelineContext = () => {
  const state = useContext10(TimelineContext);
  if (state === null) {
    throw new Error("TimelineContext is not available. This hook must be used inside a <Player> or the Remotion Studio.");
  }
  return state;
};
var usePlaybackRate = () => {
  const state = useContext10(PlaybackRateContext);
  if (state === null) {
    throw new Error("PlaybackRateContext is not available. This hook must be used inside a <Player> or the Remotion Studio.");
  }
  return state;
};
var useTimelinePosition = () => {
  const state = useTimelineContext();
  return useTimelinePositionFromContext(state);
};
var useAbsoluteTimelinePosition = () => {
  const state = useContext10(AbsoluteTimeContext);
  if (state === null) {
    throw new Error("AbsoluteTimeContext is not available. This hook must be used inside a <Player> or the Remotion Studio.");
  }
  return useTimelinePositionFromContext(state);
};
var useTimelineSetFrame = () => {
  const { setFrame } = useContext10(SetTimelineContext);
  return setFrame;
};
var usePlayingState = () => {
  const { playing, imperativePlaying } = useTimelineContext();
  const { setPlaying } = useContext10(SetTimelineContext);
  return useMemo9(() => [playing, setPlaying, imperativePlaying], [imperativePlaying, playing, setPlaying]);
};

// src/use-current-frame.ts
var useCurrentFrame = () => {
  const canUseRemotionHooks = useContext11(CanUseRemotionHooks);
  const env = useRemotionEnvironment();
  if (!canUseRemotionHooks) {
    if (env.isPlayer) {
      throw new Error(`useCurrentFrame can only be called inside a component that was passed to <Player>. See: https://www.remotion.dev/docs/player/examples`);
    }
    throw new Error(`useCurrentFrame() can only be called inside a component that was registered as a composition. See https://www.remotion.dev/docs/the-fundamentals#defining-compositions`);
  }
  const frame = useTimelinePosition();
  const context = useContext11(SequenceContext);
  const contextOffset = context ? context.cumulatedFrom + context.relativeFrom : 0;
  return frame - contextOffset;
};

// src/effects/canvas-pool.ts
class CanvasPool {
  width;
  height;
  pairs = new Map;
  lostContexts = new Set;
  constructor(width, height) {
    this.width = width;
    this.height = height;
  }
  getPair(backend) {
    const existing = this.pairs.get(backend);
    if (existing) {
      return existing;
    }
    const pair = [
      this.allocateCanvas(backend),
      this.allocateCanvas(backend)
    ];
    this.pairs.set(backend, pair);
    return pair;
  }
  assertContextNotLost(canvas) {
    if (this.lostContexts.has(canvas)) {
      throw new Error("WebGL context was lost during canvas effect rendering. " + "This typically happens in headless or memory-constrained environments (e.g. Remotion Lambda). " + "Try reducing concurrency or increasing the Lambda function memory.");
    }
  }
  allocateCanvas(backend) {
    const canvas = document.createElement("canvas");
    canvas.width = this.width;
    canvas.height = this.height;
    switch (backend) {
      case "2d": {
        const ctx = canvas.getContext("2d", {
          colorSpace: "srgb"
        });
        if (!ctx) {
          throw new Error("Failed to acquire 2D context for canvas effect");
        }
        return canvas;
      }
      case "webgl2": {
        const ctx = canvas.getContext("webgl2", {
          premultipliedAlpha: true,
          alpha: true,
          preserveDrawingBuffer: true
        });
        if (!ctx) {
          throw new Error("Failed to acquire WebGL2 context for canvas effect");
        }
        canvas.addEventListener("webglcontextlost", (e) => {
          e.preventDefault();
          this.lostContexts.add(canvas);
        });
        canvas.addEventListener("webglcontextrestored", () => {
          this.lostContexts.delete(canvas);
        });
        ctx.pixelStorei(ctx.UNPACK_PREMULTIPLY_ALPHA_WEBGL, true);
        return canvas;
      }
      case "webgpu": {
        if (typeof navigator === "undefined" || !("gpu" in navigator)) {
          throw new Error("WebGPU is not available in this environment for canvas effect");
        }
        return canvas;
      }
      default: {
        const exhaustive = backend;
        throw new Error(`Unknown effect backend: ${exhaustive}`);
      }
    }
  }
}

// src/effects/effect-internals.ts
var flattenEffects = (effects) => {
  const out = [];
  for (const item of effects) {
    if (Array.isArray(item)) {
      for (const inner of item) {
        out.push(inner);
      }
    } else {
      out.push(item);
    }
  }
  return out;
};
var groupByBackend = (effects) => {
  const runs = [];
  let current = [];
  let currentBackend = null;
  for (const eff of effects) {
    const { backend } = eff.definition;
    if (currentBackend === null || backend === currentBackend) {
      current.push(eff);
      currentBackend = backend;
    } else {
      runs.push({ backend: currentBackend, effects: current });
      current = [eff];
      currentBackend = backend;
    }
  }
  if (currentBackend !== null && current.length > 0) {
    runs.push({ backend: currentBackend, effects: current });
  }
  return runs;
};

// src/effects/gpu-device.ts
var devicePromise = null;
var getGpuDevice = () => {
  if (devicePromise) {
    return devicePromise;
  }
  devicePromise = (async () => {
    if (typeof navigator === "undefined" || !("gpu" in navigator)) {
      throw new Error("WebGPU is not available in this environment");
    }
    const { gpu } = navigator;
    const adapter = await gpu.requestAdapter();
    if (!adapter) {
      throw new Error("No WebGPU adapter available");
    }
    return adapter.requestDevice();
  })();
  return devicePromise;
};

// src/effects/run-effect-chain.ts
var createEffectChainState = (width, height) => ({
  pool: new CanvasPool(width, height),
  setupCache: new WeakMap,
  cleanupRegistry: [],
  currentRunId: 0
});
var cleanupEffectChainState = (state) => {
  state.currentRunId++;
  for (const entry of state.cleanupRegistry) {
    entry.definition.cleanup(entry.state);
  }
};
var ensureSetup = (state, def, target) => {
  const widened = def;
  if (state.setupCache.has(widened)) {
    return state.setupCache.get(widened);
  }
  const setupState = def.setup(target);
  state.setupCache.set(widened, setupState);
  state.cleanupRegistry.push({ definition: widened, state: setupState });
  return setupState;
};
var runEffectChain = async ({
  state,
  source,
  effects,
  output,
  frame,
  width,
  height
}) => {
  const runId = ++state.currentRunId;
  const isCancelled = () => state.currentRunId !== runId;
  const flattened = flattenEffects(effects);
  const runs = groupByBackend(flattened);
  let currentImage = source;
  let lastTarget = null;
  if (runs.length === 0) {
    if (source === output) {
      return true;
    }
    const ctx = output.getContext("2d");
    if (!ctx) {
      throw new Error("Failed to acquire 2D context for output canvas");
    }
    ctx.clearRect(0, 0, width, height);
    ctx.drawImage(currentImage, 0, 0, width, height);
    return true;
  }
  let needsGpuDevice = false;
  for (const run of runs) {
    if (run.backend === "webgpu") {
      needsGpuDevice = true;
      break;
    }
  }
  const gpuDevice = needsGpuDevice ? await getGpuDevice() : null;
  if (isCancelled()) {
    return false;
  }
  for (let runIndex = 0;runIndex < runs.length; runIndex++) {
    const run = runs[runIndex];
    const [a, b] = state.pool.getPair(run.backend);
    let dst = a;
    for (const eff of run.effects) {
      const def = eff.definition;
      const setupState = ensureSetup(state, def, dst);
      def.apply({
        source: currentImage,
        target: dst,
        state: setupState,
        params: eff.params,
        frame,
        width,
        height,
        gpuDevice
      });
      if (run.backend === "webgl2") {
        state.pool.assertContextNotLost(dst);
      }
      currentImage = dst;
      dst = dst === a ? b : a;
    }
    lastTarget = currentImage ?? lastTarget;
    const nextRun = runs[runIndex + 1];
    if (nextRun && nextRun.backend !== run.backend && lastTarget) {
      const bitmap = await createImageBitmap(lastTarget);
      if (isCancelled()) {
        bitmap.close();
        return false;
      }
      currentImage = bitmap;
    }
  }
  if (!lastTarget) {
    return true;
  }
  const outCtx = output.getContext("2d");
  if (!outCtx) {
    throw new Error("Failed to acquire 2D context for output canvas");
  }
  outCtx.clearRect(0, 0, width, height);
  outCtx.drawImage(lastTarget, 0, 0, width, height);
  return true;
};

// src/effects/use-effect-chain-state.ts
import { useEffect as useEffect3, useMemo as useMemo10, useRef as useRef4 } from "react";
var useEffectChainState = () => {
  const chainStateRef = useRef4(null);
  const sizeRef = useRef4(null);
  useEffect3(() => {
    return () => {
      if (chainStateRef.current) {
        cleanupEffectChainState(chainStateRef.current);
      }
    };
  }, []);
  return useMemo10(() => ({
    get: (width, height) => {
      if (!sizeRef.current || sizeRef.current.width !== width || sizeRef.current.height !== height) {
        if (chainStateRef.current) {
          cleanupEffectChainState(chainStateRef.current);
        }
        chainStateRef.current = createEffectChainState(width, height);
        sizeRef.current = { width, height };
      }
      return chainStateRef.current;
    }
  }), []);
};

// src/effects/Solid.tsx
import { jsx as jsx9 } from "react/jsx-runtime";
var Solid = ({
  color,
  width,
  height,
  _experimentalEffects: experimentalEffects = [],
  className,
  style,
  pixelRatio = 1
}) => {
  const frame = useCurrentFrame();
  const { delayRender: delayRender2, continueRender: continueRender2, cancelRender: cancelRender2 } = useDelayRender();
  const [outputCanvas, setOutputCanvas] = useState3(null);
  const sourceCanvas = useMemo11(() => {
    if (typeof document === "undefined") {
      return null;
    }
    const canvas = document.createElement("canvas");
    canvas.width = 1;
    canvas.height = 1;
    return canvas;
  }, []);
  const chainState = useEffectChainState();
  useEffect4(() => {
    if (!outputCanvas || !sourceCanvas) {
      return;
    }
    const handle = delayRender2(`Solid effect chain (frame ${frame})`);
    if (!chainState) {
      continueRender2(handle);
      return () => {
        continueRender2(handle);
      };
    }
    const ctx = sourceCanvas.getContext("2d", { colorSpace: "srgb" });
    if (!ctx) {
      cancelRender2(new Error("Failed to acquire 2D context for <Solid> source"));
      return;
    }
    ctx.fillStyle = color;
    ctx.fillRect(0, 0, 1, 1);
    runEffectChain({
      state: chainState.get(width, height),
      source: sourceCanvas,
      effects: experimentalEffects,
      output: outputCanvas,
      frame,
      width,
      height
    }).then((completed) => {
      if (completed) {
        continueRender2(handle);
      }
    }).catch((err) => {
      cancelRender2(err);
    });
    return () => {
      continueRender2(handle);
    };
  }, [
    frame,
    color,
    experimentalEffects,
    outputCanvas,
    sourceCanvas,
    chainState,
    width,
    height,
    pixelRatio,
    delayRender2,
    continueRender2,
    cancelRender2
  ]);
  return /* @__PURE__ */ jsx9("canvas", {
    ref: setOutputCanvas,
    width,
    height,
    className,
    style
  });
};

// src/enable-sequence-stack-traces.ts
var componentsToAddStacksTo = [];
var getComponentsToAddStacksTo = () => componentsToAddStacksTo;
var addSequenceStackTraces = (component) => {
  componentsToAddStacksTo.push(component);
};

// src/version.ts
var VERSION = "4.0.459";

// src/multiple-versions-warning.ts
var checkMultipleRemotionVersions = () => {
  if (typeof globalThis === "undefined") {
    return;
  }
  const set = () => {
    globalThis.remotion_imported = VERSION;
    if (typeof window !== "undefined") {
      window.remotion_imported = VERSION;
    }
  };
  const alreadyImported = globalThis.remotion_imported || typeof window !== "undefined" && window.remotion_imported;
  if (alreadyImported) {
    if (alreadyImported === VERSION) {
      return;
    }
    if (typeof alreadyImported === "string" && alreadyImported.includes("webcodecs")) {
      set();
      return;
    }
    throw new TypeError(`\uD83D\uDEA8 Multiple versions of Remotion detected: ${[
      VERSION,
      typeof alreadyImported === "string" ? alreadyImported : "an older version"
    ].filter(truthy).join(" and ")}. This will cause things to break in an unexpected way.
Check that all your Remotion packages are on the same version. If your dependencies depend on Remotion, make them peer dependencies. You can also run \`npx remotion versions\` from your terminal to see which versions are mismatching.`);
  }
  set();
};

// src/Null.tsx
var Null = () => {
  throw new Error("<Null> has been removed as of Remotion v4.0.228. The native clipping APIs were experimental and subject to removal at any time. We removed them because they were sparingly used and made rendering often slower rather than faster.");
};

// src/Sequence.tsx
import {
  forwardRef as forwardRef3,
  useContext as useContext16,
  useEffect as useEffect5,
  useMemo as useMemo16,
  useState as useState6
} from "react";

// src/effects/use-memoized-effects.ts
import { useRef as useRef5 } from "react";
var useMemoizedEffects = (effects) => {
  const previousRef = useRef5(null);
  const previous = previousRef.current;
  const isSame = previous !== null && previous.length === effects.length && previous.every((p, i) => p.definition === effects[i].definition && p.stack === effects[i].stack);
  if (isSame) {
    return previous;
  }
  const next = effects.map((e) => ({
    definition: e.definition,
    stack: e.stack
  }));
  previousRef.current = next;
  return next;
};

// src/freeze.tsx
import { useContext as useContext14, useMemo as useMemo13 } from "react";

// src/use-video-config.ts
import { useContext as useContext13 } from "react";

// src/use-unsafe-video-config.ts
import { useContext as useContext12, useMemo as useMemo12 } from "react";
var useUnsafeVideoConfig = () => {
  const context = useContext12(SequenceContext);
  const ctxWidth = context?.width ?? null;
  const ctxHeight = context?.height ?? null;
  const ctxDuration = context?.durationInFrames ?? null;
  const video = useVideo();
  return useMemo12(() => {
    if (!video) {
      return null;
    }
    const {
      id,
      durationInFrames,
      fps,
      height,
      width,
      defaultProps,
      props,
      defaultCodec,
      defaultOutName,
      defaultVideoImageFormat,
      defaultPixelFormat,
      defaultProResProfile,
      defaultSampleRate
    } = video;
    return {
      id,
      width: ctxWidth ?? width,
      height: ctxHeight ?? height,
      fps,
      durationInFrames: ctxDuration ?? durationInFrames,
      defaultProps,
      props,
      defaultCodec,
      defaultOutName,
      defaultVideoImageFormat,
      defaultPixelFormat,
      defaultProResProfile,
      defaultSampleRate
    };
  }, [ctxDuration, ctxHeight, ctxWidth, video]);
};

// src/use-video-config.ts
var useVideoConfig = () => {
  const videoConfig = useUnsafeVideoConfig();
  const context = useContext13(CanUseRemotionHooks);
  const isPlayer = useIsPlayer();
  if (!videoConfig) {
    if (typeof window !== "undefined" && window.remotion_isPlayer || isPlayer) {
      throw new Error([
        "No video config found. Likely reasons:",
        "- You are probably calling useVideoConfig() from outside the component passed to <Player />. See https://www.remotion.dev/docs/player/examples for how to set up the Player correctly.",
        "- You have multiple versions of Remotion installed which causes the React context to get lost."
      ].join("-"));
    }
    throw new Error("No video config found. You are probably calling useVideoConfig() from a component which has not been registered as a <Composition />. See https://www.remotion.dev/docs/the-fundamentals#defining-compositions for more information.");
  }
  if (!context) {
    throw new Error("Called useVideoConfig() outside a Remotion composition.");
  }
  return videoConfig;
};

// src/freeze.tsx
import { jsx as jsx10 } from "react/jsx-runtime";
var Freeze = ({
  frame: frameToFreeze,
  children,
  active = true
}) => {
  const frame = useCurrentFrame();
  const videoConfig = useVideoConfig();
  if (typeof frameToFreeze === "undefined") {
    throw new Error(`The <Freeze /> component requires a 'frame' prop, but none was passed.`);
  }
  if (typeof frameToFreeze !== "number") {
    throw new Error(`The 'frame' prop of <Freeze /> must be a number, but is of type ${typeof frameToFreeze}`);
  }
  if (Number.isNaN(frameToFreeze)) {
    throw new Error(`The 'frame' prop of <Freeze /> must be a real number, but it is NaN.`);
  }
  if (!Number.isFinite(frameToFreeze)) {
    throw new Error(`The 'frame' prop of <Freeze /> must be a finite number, but it is ${frameToFreeze}.`);
  }
  const isActive = useMemo13(() => {
    if (typeof active === "boolean") {
      return active;
    }
    if (typeof active === "function") {
      return active(frame);
    }
  }, [active, frame]);
  const timelineContext = useTimelineContext();
  const sequenceContext = useContext14(SequenceContext);
  const relativeFrom = sequenceContext?.relativeFrom ?? 0;
  const timelineValue = useMemo13(() => {
    if (!isActive) {
      return timelineContext;
    }
    return {
      ...timelineContext,
      playing: false,
      imperativePlaying: {
        current: false
      },
      frame: {
        [videoConfig.id]: frameToFreeze + relativeFrom
      }
    };
  }, [isActive, timelineContext, videoConfig.id, frameToFreeze, relativeFrom]);
  const newSequenceContext = useMemo13(() => {
    if (!sequenceContext) {
      return null;
    }
    if (!isActive) {
      return sequenceContext;
    }
    return {
      ...sequenceContext,
      cumulatedFrom: 0
    };
  }, [sequenceContext, isActive]);
  return /* @__PURE__ */ jsx10(TimelineContext.Provider, {
    value: timelineValue,
    children: /* @__PURE__ */ jsx10(SequenceContext.Provider, {
      value: newSequenceContext,
      children
    })
  });
};

// src/PremountContext.tsx
import { createContext as createContext14 } from "react";
var PremountContext = createContext14({
  premountFramesRemaining: 0
});

// src/sequence-field-schema.ts
var sequenceStyleSchema = {
  "style.translate": {
    type: "translate",
    step: 1,
    default: "0px 0px",
    description: "Offset"
  },
  "style.scale": {
    type: "number",
    min: 0.05,
    max: 100,
    step: 0.01,
    default: 1,
    description: "Scale"
  },
  "style.rotate": {
    type: "rotation",
    step: 1,
    default: "0deg",
    description: "Rotation"
  },
  "style.opacity": {
    type: "number",
    min: 0,
    max: 1,
    step: 0.01,
    default: 1,
    description: "Opacity"
  }
};
var sequenceSchema = {
  layout: {
    type: "enum",
    default: "absolute-fill",
    description: "Layout",
    variants: {
      "absolute-fill": sequenceStyleSchema,
      none: {}
    }
  }
};
var sequenceSchemaDefaultLayoutNone = {
  ...sequenceSchema,
  layout: {
    ...sequenceSchema.layout,
    default: "none"
  }
};

// src/SequenceManager.tsx
import React12, { useCallback as useCallback5, useMemo as useMemo14, useRef as useRef6, useState as useState4 } from "react";
import { jsx as jsx11 } from "react/jsx-runtime";
var SequenceManager = React12.createContext({
  registerSequence: () => {
    throw new Error("SequenceManagerContext not initialized");
  },
  unregisterSequence: () => {
    throw new Error("SequenceManagerContext not initialized");
  },
  sequences: []
});
var SequenceVisibilityToggleContext = React12.createContext({
  hidden: {},
  setHidden: () => {
    throw new Error("SequenceVisibilityToggle not initialized");
  }
});
var VisualModeOverridesContext = React12.createContext({
  dragOverrides: {},
  setDragOverrides: () => {
    throw new Error("VisualModeOverridesContext not initialized");
  },
  clearDragOverrides: () => {
    throw new Error("VisualModeOverridesContext not initialized");
  },
  codeValues: {},
  setCodeValues: () => {
    throw new Error("VisualModeOverridesContext not initialized");
  },
  visualModeEnabled: false
});
var SequenceManagerProvider = ({ children, visualModeEnabled }) => {
  const [sequences, setSequences] = useState4([]);
  const [hidden, setHidden] = useState4({});
  const [dragOverrides, setControlOverrides] = useState4({});
  const controlOverridesRef = useRef6(dragOverrides);
  controlOverridesRef.current = dragOverrides;
  const [codeValues, setCodeValuesMapState] = useState4({});
  const setDragOverrides = useCallback5((sequenceId, key, value) => {
    setControlOverrides((prev) => ({
      ...prev,
      [sequenceId]: {
        ...prev[sequenceId],
        [key]: value
      }
    }));
  }, []);
  const clearDragOverrides = useCallback5((sequenceId) => {
    setControlOverrides((prev) => {
      if (!prev[sequenceId]) {
        return prev;
      }
      const next = { ...prev };
      delete next[sequenceId];
      return next;
    });
  }, []);
  const setCodeValues = useCallback5((sequenceId, values) => {
    setCodeValuesMapState((prev) => {
      if (prev[sequenceId] === values) {
        return prev;
      }
      if (values === null) {
        if (!(sequenceId in prev)) {
          return prev;
        }
        const next = { ...prev };
        delete next[sequenceId];
        return next;
      }
      return { ...prev, [sequenceId]: values };
    });
  }, []);
  const registerSequence = useCallback5((seq) => {
    setSequences((seqs) => {
      return [...seqs, seq];
    });
  }, []);
  const unregisterSequence = useCallback5((seq) => {
    setSequences((seqs) => seqs.filter((s) => s.id !== seq));
  }, []);
  const sequenceContext = useMemo14(() => {
    return {
      registerSequence,
      sequences,
      unregisterSequence
    };
  }, [registerSequence, sequences, unregisterSequence]);
  const hiddenContext = useMemo14(() => {
    return {
      hidden,
      setHidden
    };
  }, [hidden]);
  const overrideContext = useMemo14(() => {
    return {
      visualModeEnabled,
      dragOverrides,
      setDragOverrides,
      clearDragOverrides,
      codeValues,
      setCodeValues
    };
  }, [
    visualModeEnabled,
    dragOverrides,
    setDragOverrides,
    clearDragOverrides,
    codeValues,
    setCodeValues
  ]);
  return /* @__PURE__ */ jsx11(SequenceManager.Provider, {
    value: sequenceContext,
    children: /* @__PURE__ */ jsx11(SequenceVisibilityToggleContext.Provider, {
      value: hiddenContext,
      children: /* @__PURE__ */ jsx11(VisualModeOverridesContext.Provider, {
        value: overrideContext,
        children
      })
    })
  });
};

// src/v5-flag.ts
var ENABLE_V5_BREAKING_CHANGES = false;

// src/wrap-in-schema.ts
import React13, { forwardRef as forwardRef2, useState as useState5, useContext as useContext15, useMemo as useMemo15 } from "react";

// src/flatten-schema.ts
var flattenActiveSchema = (schema, resolve) => {
  const out = {};
  for (const key of Object.keys(schema)) {
    const field = schema[key];
    if (field.type === "enum") {
      out[key] = field;
      const current = resolve(key) ?? field.default;
      const variant = field.variants[current];
      if (variant) {
        Object.assign(out, flattenActiveSchema(variant, resolve));
      }
    } else {
      out[key] = field;
    }
  }
  return out;
};
var getFlatSchemaWithAllKeys = (schema) => {
  const out = {};
  const addKey = (key, field) => {
    if (key in out) {
      throw new Error(`Duplicate key "${key}" in schema: discriminated union variants must not share keys`);
    }
    out[key] = field;
  };
  for (const key of Object.keys(schema)) {
    const field = schema[key];
    addKey(key, field);
    if (field.type === "enum") {
      for (const variant of Object.values(field.variants)) {
        const flatVariant = getFlatSchemaWithAllKeys(variant);
        for (const variantKey of Object.keys(flatVariant)) {
          addKey(variantKey, flatVariant[variantKey]);
        }
      }
    }
  }
  return out;
};

// src/get-effective-visual-mode-value.ts
var getEffectiveVisualModeValue = ({
  codeValue,
  runtimeValue,
  dragOverrideValue,
  defaultValue,
  shouldResortToDefaultValueIfUndefined = false
}) => {
  if (dragOverrideValue !== undefined) {
    return dragOverrideValue;
  }
  if (!codeValue) {
    return runtimeValue;
  }
  if (!codeValue.canUpdate) {
    return runtimeValue;
  }
  if (codeValue.codeValue === undefined && shouldResortToDefaultValueIfUndefined) {
    return defaultValue;
  }
  return codeValue.codeValue;
};

// src/use-schema.ts
var findFieldInSchema = (schema, key) => {
  if (key in schema) {
    return schema[key];
  }
  for (const field of Object.values(schema)) {
    if (field.type !== "enum") {
      continue;
    }
    for (const variant of Object.values(field.variants)) {
      const found = findFieldInSchema(variant, key);
      if (found) {
        return found;
      }
    }
  }
  return;
};
var computeEffectiveSchemaValuesDotNotation = ({
  schema,
  currentValue,
  overrideValues,
  propStatus
}) => {
  const merged = {};
  for (const key of Object.keys(currentValue)) {
    const codeValueStatus = propStatus?.[key] ?? null;
    merged[key] = getEffectiveVisualModeValue({
      codeValue: codeValueStatus,
      runtimeValue: currentValue[key],
      dragOverrideValue: overrideValues[key],
      defaultValue: findFieldInSchema(schema, key)?.default,
      shouldResortToDefaultValueIfUndefined: false
    });
  }
  return merged;
};

// src/wrap-in-schema.ts
var getNestedValue = (obj, key) => {
  const parts = key.split(".");
  let current = obj;
  for (const part of parts) {
    if (current === null || current === undefined || typeof current !== "object")
      return;
    current = current[part];
  }
  return current;
};
var readValuesFromProps = (props, keys) => {
  const out = {};
  for (const key of keys) {
    out[key] = getNestedValue(props, key);
  }
  return out;
};
var selectActiveKeys = (schema, values) => {
  return Object.keys(flattenActiveSchema(schema, (key) => values[key]));
};
var mergeValues = ({
  props,
  valuesDotNotation,
  schemaKeys
}) => {
  const merged = { ...props };
  for (const key of schemaKeys) {
    const value = valuesDotNotation[key];
    const parts = key.split(".");
    if (parts.length === 1) {
      merged[key] = value;
      continue;
    }
    let current = merged;
    for (let i = 0;i < parts.length - 1; i++) {
      const part = parts[i];
      if (typeof current[part] === "object" && current[part] !== null) {
        current[part] = { ...current[part] };
      } else {
        current[part] = {};
      }
      current = current[part];
    }
    current[parts[parts.length - 1]] = value;
  }
  return merged;
};
var wrapInSchema = (Component, schema) => {
  if (typeof process === "undefined" || !process.env?.EXPERIMENTAL_VISUAL_MODE_ENABLED) {
    return Component;
  }
  const flatSchema = getFlatSchemaWithAllKeys(schema);
  const flatKeys = Object.keys(flatSchema);
  const Wrapped = forwardRef2((props, ref) => {
    const env = useRemotionEnvironment();
    const { visualModeEnabled, dragOverrides, codeValues } = useContext15(VisualModeOverridesContext);
    if (!env.isStudio || env.isReadOnlyStudio || env.isRendering || !visualModeEnabled) {
      return React13.createElement(Component, {
        ...props,
        _experimentalControls: null,
        ref
      });
    }
    if (props._experimentalControls) {
      return React13.createElement(Component, {
        ...props,
        ref
      });
    }
    const [overrideId] = useState5(() => String(Math.random()));
    const runtimeValues = flatKeys.map((k) => getNestedValue(props, k));
    const currentRuntimeValueDotNotation = useMemo15(() => readValuesFromProps(props, flatKeys), runtimeValues);
    const controls = useMemo15(() => {
      return {
        schema,
        currentRuntimeValueDotNotation,
        overrideId
      };
    }, [currentRuntimeValueDotNotation, overrideId]);
    const valuesDotNotation = useMemo15(() => {
      return computeEffectiveSchemaValuesDotNotation({
        schema,
        currentValue: currentRuntimeValueDotNotation,
        overrideValues: dragOverrides[overrideId] ?? {},
        propStatus: codeValues[overrideId]
      });
    }, [currentRuntimeValueDotNotation, dragOverrides, overrideId, codeValues]);
    const activeKeys = selectActiveKeys(schema, valuesDotNotation);
    const mergedProps = mergeValues({
      props,
      valuesDotNotation,
      schemaKeys: activeKeys
    });
    return React13.createElement(Component, {
      ...mergedProps,
      _experimentalControls: controls,
      ref
    });
  });
  Wrapped.displayName = `wrapInSchema(${Component.displayName || Component.name || "Component"})`;
  return Wrapped;
};

// src/Sequence.tsx
import { jsx as jsx12 } from "react/jsx-runtime";
var RegularSequenceRefForwardingFunction = ({
  from = 0,
  durationInFrames = Infinity,
  children,
  name,
  height,
  width,
  showInTimeline = true,
  _experimentalControls: controls,
  _experimentalEffects,
  _remotionInternalLoopDisplay: loopDisplay,
  _remotionInternalStack: stack,
  _remotionInternalPremountDisplay: premountDisplay,
  _remotionInternalPostmountDisplay: postmountDisplay,
  _remotionInternalIsMedia: isMedia,
  ...other
}, ref) => {
  const { layout = "absolute-fill" } = other;
  const [id] = useState6(() => String(Math.random()));
  const parentSequence = useContext16(SequenceContext);
  const { rootId } = useTimelineContext();
  const cumulatedFrom = parentSequence ? parentSequence.cumulatedFrom + parentSequence.relativeFrom : 0;
  const nonce = useNonce();
  if (layout !== "absolute-fill" && layout !== "none") {
    throw new TypeError(`The layout prop of <Sequence /> expects either "absolute-fill" or "none", but you passed: ${layout}`);
  }
  if (layout === "none" && typeof other.style !== "undefined") {
    throw new TypeError('If layout="none", you may not pass a style. Passed: ' + JSON.stringify(other.style));
  }
  if (typeof durationInFrames !== "number") {
    throw new TypeError(`You passed to durationInFrames an argument of type ${typeof durationInFrames}, but it must be a number.`);
  }
  if (durationInFrames <= 0) {
    throw new TypeError(`durationInFrames must be positive, but got ${durationInFrames}`);
  }
  if (typeof from !== "number") {
    throw new TypeError(`You passed to the "from" props of your <Sequence> an argument of type ${typeof from}, but it must be a number.`);
  }
  if (!Number.isFinite(from)) {
    throw new TypeError(`The "from" prop of a sequence must be finite, but got ${from}.`);
  }
  const absoluteFrame = useTimelinePosition();
  const videoConfig = useVideoConfig();
  const parentSequenceDuration = parentSequence ? Math.min(parentSequence.durationInFrames - from, durationInFrames) : durationInFrames;
  const actualDurationInFrames = Math.max(0, Math.min(videoConfig.durationInFrames - from, parentSequenceDuration));
  const { registerSequence, unregisterSequence } = useContext16(SequenceManager);
  const { hidden } = useContext16(SequenceVisibilityToggleContext);
  const premounting = useMemo16(() => {
    return parentSequence?.premounting || Boolean(other._remotionInternalIsPremounting);
  }, [other._remotionInternalIsPremounting, parentSequence?.premounting]);
  const postmounting = useMemo16(() => {
    return parentSequence?.postmounting || Boolean(other._remotionInternalIsPostmounting);
  }, [other._remotionInternalIsPostmounting, parentSequence?.postmounting]);
  const contextValue = useMemo16(() => {
    return {
      cumulatedFrom,
      relativeFrom: from,
      durationInFrames: actualDurationInFrames,
      parentFrom: parentSequence?.relativeFrom ?? 0,
      id,
      height: height ?? parentSequence?.height ?? null,
      width: width ?? parentSequence?.width ?? null,
      premounting,
      postmounting,
      premountDisplay: premountDisplay ?? null,
      postmountDisplay: postmountDisplay ?? null
    };
  }, [
    cumulatedFrom,
    from,
    actualDurationInFrames,
    parentSequence,
    id,
    height,
    width,
    premounting,
    postmounting,
    premountDisplay,
    postmountDisplay
  ]);
  const timelineClipName = useMemo16(() => {
    return name ?? "";
  }, [name]);
  const env = useRemotionEnvironment();
  const inheritedStack = other?.stack ?? null;
  const memoizedEffects = useMemoizedEffects(flattenEffects(_experimentalEffects ?? []));
  useEffect5(() => {
    if (!env.isStudio) {
      return;
    }
    if (isMedia) {
      registerSequence({
        type: isMedia.type,
        controls: controls ?? null,
        effects: memoizedEffects,
        displayName: timelineClipName,
        doesVolumeChange: isMedia.data.doesVolumeChange,
        duration: actualDurationInFrames,
        from,
        id,
        loopDisplay,
        nonce: nonce.get(),
        parent: parentSequence?.id ?? null,
        playbackRate: isMedia.data.playbackRate,
        postmountDisplay: postmountDisplay ?? null,
        premountDisplay: premountDisplay ?? null,
        rootId,
        showInTimeline,
        src: isMedia.data.src,
        stack: stack ?? inheritedStack,
        startMediaFrom: isMedia.data.startMediaFrom,
        volume: isMedia.data.volumes
      });
      return () => {
        unregisterSequence(id);
      };
    }
    registerSequence({
      from,
      duration: actualDurationInFrames,
      id,
      displayName: timelineClipName,
      parent: parentSequence?.id ?? null,
      type: "sequence",
      rootId,
      showInTimeline,
      nonce: nonce.get(),
      loopDisplay,
      stack: stack ?? inheritedStack,
      premountDisplay: premountDisplay ?? null,
      postmountDisplay: postmountDisplay ?? null,
      controls: controls ?? null,
      effects: memoizedEffects
    });
    return () => {
      unregisterSequence(id);
    };
  }, [
    durationInFrames,
    id,
    name,
    registerSequence,
    timelineClipName,
    unregisterSequence,
    parentSequence?.id,
    actualDurationInFrames,
    rootId,
    from,
    showInTimeline,
    nonce,
    loopDisplay,
    stack,
    premountDisplay,
    postmountDisplay,
    env.isStudio,
    inheritedStack,
    controls,
    memoizedEffects,
    isMedia
  ]);
  const endThreshold = Math.ceil(cumulatedFrom + from + durationInFrames - 1);
  const content = absoluteFrame < cumulatedFrom + from ? null : absoluteFrame > endThreshold ? null : children;
  const styleIfThere = other.layout === "none" ? undefined : other.style;
  const defaultStyle = useMemo16(() => {
    return {
      flexDirection: undefined,
      ...width ? { width } : {},
      ...height ? { height } : {},
      ...styleIfThere ?? {}
    };
  }, [height, styleIfThere, width]);
  if (ref !== null && layout === "none") {
    throw new TypeError('It is not supported to pass both a `ref` and `layout="none"` to <Sequence />.');
  }
  const isSequenceHidden = hidden[id] ?? false;
  if (isSequenceHidden) {
    return null;
  }
  return /* @__PURE__ */ jsx12(SequenceContext.Provider, {
    value: contextValue,
    children: content === null ? null : other.layout === "none" ? content : /* @__PURE__ */ jsx12(AbsoluteFill, {
      ref,
      style: defaultStyle,
      className: other.className,
      children: content
    })
  });
};
var RegularSequence = forwardRef3(RegularSequenceRefForwardingFunction);
var PremountedPostmountedSequenceRefForwardingFunction = (props, ref) => {
  const parentPremountContext = useContext16(PremountContext);
  const frame = useCurrentFrame() - parentPremountContext.premountFramesRemaining;
  if (props.layout === "none") {
    throw new Error('`<Sequence>` with `premountFor` and `postmountFor` props does not support layout="none"');
  }
  const {
    style: passedStyle,
    from = 0,
    durationInFrames = Infinity,
    premountFor = 0,
    postmountFor = 0,
    styleWhilePremounted,
    styleWhilePostmounted,
    ...otherProps
  } = props;
  const endThreshold = Math.ceil(from + durationInFrames - 1);
  const premountingActive = frame < from && frame >= from - premountFor;
  const postmountingActive = frame > endThreshold && frame <= endThreshold + postmountFor;
  const freezeFrame = premountingActive ? from : postmountingActive ? from + durationInFrames - 1 : 0;
  const isFreezingActive = premountingActive || postmountingActive;
  const style = useMemo16(() => {
    return {
      ...passedStyle,
      opacity: premountingActive || postmountingActive ? 0 : 1,
      pointerEvents: premountingActive || postmountingActive ? "none" : passedStyle?.pointerEvents ?? undefined,
      ...premountingActive ? styleWhilePremounted : {},
      ...postmountingActive ? styleWhilePostmounted : {}
    };
  }, [
    passedStyle,
    premountingActive,
    postmountingActive,
    styleWhilePremounted,
    styleWhilePostmounted
  ]);
  return /* @__PURE__ */ jsx12(Freeze, {
    frame: freezeFrame,
    active: isFreezingActive,
    children: /* @__PURE__ */ jsx12(SequenceInner, {
      ref,
      from,
      durationInFrames,
      style,
      _remotionInternalPremountDisplay: premountFor,
      _remotionInternalPostmountDisplay: postmountFor,
      _remotionInternalIsPremounting: premountingActive,
      _remotionInternalIsPostmounting: postmountingActive,
      ...otherProps
    })
  });
};
var PremountedPostmountedSequence = forwardRef3(PremountedPostmountedSequenceRefForwardingFunction);
var SequenceRefForwardingFunction = (props, ref) => {
  const env = useRemotionEnvironment();
  const { fps } = useVideoConfig();
  if (props.layout !== "none" && !env.isRendering) {
    const effectivePremountFor = ENABLE_V5_BREAKING_CHANGES ? props.premountFor ?? fps : props.premountFor;
    if (effectivePremountFor || props.postmountFor) {
      return /* @__PURE__ */ jsx12(PremountedPostmountedSequence, {
        ref,
        ...props,
        premountFor: effectivePremountFor
      });
    }
  }
  return /* @__PURE__ */ jsx12(RegularSequence, {
    ...props,
    ref
  });
};
var SequenceInner = forwardRef3(SequenceRefForwardingFunction);
var Sequence = wrapInSchema(SequenceInner, sequenceSchema);
// src/animated-image/AnimatedImage.tsx
import {
  forwardRef as forwardRef4,
  useEffect as useEffect6,
  useImperativeHandle as useImperativeHandle2,
  useLayoutEffect as useLayoutEffect2,
  useRef as useRef8,
  useState as useState7
} from "react";

// src/animated-image/canvas.tsx
import React15, { useCallback as useCallback6, useImperativeHandle, useRef as useRef7 } from "react";
import { jsx as jsx13 } from "react/jsx-runtime";
var calcArgs = (fit, frameSize, canvasSize) => {
  switch (fit) {
    case "fill": {
      return [
        0,
        0,
        frameSize.width,
        frameSize.height,
        0,
        0,
        canvasSize.width,
        canvasSize.height
      ];
    }
    case "contain": {
      const ratio = Math.min(canvasSize.width / frameSize.width, canvasSize.height / frameSize.height);
      const centerX = (canvasSize.width - frameSize.width * ratio) / 2;
      const centerY = (canvasSize.height - frameSize.height * ratio) / 2;
      return [
        0,
        0,
        frameSize.width,
        frameSize.height,
        centerX,
        centerY,
        frameSize.width * ratio,
        frameSize.height * ratio
      ];
    }
    case "cover": {
      const ratio = Math.max(canvasSize.width / frameSize.width, canvasSize.height / frameSize.height);
      const centerX = (canvasSize.width - frameSize.width * ratio) / 2;
      const centerY = (canvasSize.height - frameSize.height * ratio) / 2;
      return [
        0,
        0,
        frameSize.width,
        frameSize.height,
        centerX,
        centerY,
        frameSize.width * ratio,
        frameSize.height * ratio
      ];
    }
    default:
      throw new Error("Unknown fit: " + fit);
  }
};
var CanvasRefForwardingFunction = ({ width, height, fit, className, style }, ref) => {
  const canvasRef = useRef7(null);
  const draw = useCallback6((imageData) => {
    const canvas = canvasRef.current;
    const canvasWidth = width ?? imageData.displayWidth;
    const canvasHeight = height ?? imageData.displayHeight;
    if (!canvas) {
      throw new Error("Canvas ref is not set");
    }
    const ctx = canvasRef.current?.getContext("2d");
    if (!ctx) {
      throw new Error("Could not get 2d context");
    }
    canvas.width = canvasWidth;
    canvas.height = canvasHeight;
    ctx.drawImage(imageData, ...calcArgs(fit, {
      height: imageData.displayHeight,
      width: imageData.displayWidth
    }, {
      width: canvasWidth,
      height: canvasHeight
    }));
  }, [fit, height, width]);
  useImperativeHandle(ref, () => {
    return {
      draw,
      getCanvas: () => {
        if (!canvasRef.current) {
          throw new Error("Canvas ref is not set");
        }
        return canvasRef.current;
      },
      clear: () => {
        const ctx = canvasRef.current?.getContext("2d");
        if (!ctx) {
          throw new Error("Could not get 2d context");
        }
        ctx.clearRect(0, 0, canvasRef.current.width, canvasRef.current.height);
      }
    };
  }, [draw]);
  return /* @__PURE__ */ jsx13("canvas", {
    ref: canvasRef,
    className,
    style
  });
};
var Canvas = React15.forwardRef(CanvasRefForwardingFunction);

// src/animated-image/decode-image.ts
var CACHE_SIZE = 5;
var getActualTime = ({
  loopBehavior,
  durationFound,
  timeInSec
}) => {
  return loopBehavior === "loop" ? durationFound ? timeInSec % durationFound : timeInSec : Math.min(timeInSec, durationFound || Infinity);
};
var decodeImage = async ({
  resolvedSrc,
  signal,
  currentTime,
  initialLoopBehavior
}) => {
  if (typeof ImageDecoder === "undefined") {
    throw new Error("Your browser does not support the WebCodecs ImageDecoder API.");
  }
  const res = await fetch(resolvedSrc, { signal });
  const { body } = res;
  if (!body) {
    throw new Error("Got no body");
  }
  const decoder = new ImageDecoder({
    data: body,
    type: res.headers.get("Content-Type") || "image/gif"
  });
  await decoder.completed;
  const { selectedTrack } = decoder.tracks;
  if (!selectedTrack) {
    throw new Error("No selected track");
  }
  const cache = [];
  let durationFound = null;
  const getFrameByIndex = async (frameIndex) => {
    const foundInCache = cache.find((c) => c.frameIndex === frameIndex);
    if (foundInCache && foundInCache.frame) {
      return foundInCache;
    }
    const frame = await decoder.decode({
      frameIndex,
      completeFramesOnly: true
    });
    if (foundInCache) {
      foundInCache.frame = frame.image;
    } else {
      cache.push({
        frame: frame.image,
        frameIndex,
        timeInSeconds: frame.image.timestamp / 1e6
      });
    }
    return {
      frame: frame.image,
      frameIndex,
      timeInSeconds: frame.image.timestamp / 1e6
    };
  };
  const clearCache = (closeToTimeInSec) => {
    const itemsInCache = cache.filter((c) => c.frame);
    const sortByClosestToCurrentTime = itemsInCache.sort((a, b) => {
      const aDiff = Math.abs(a.timeInSeconds - closeToTimeInSec);
      const bDiff = Math.abs(b.timeInSeconds - closeToTimeInSec);
      return aDiff - bDiff;
    });
    for (let i = 0;i < sortByClosestToCurrentTime.length; i++) {
      if (i < CACHE_SIZE) {
        continue;
      }
      const item = sortByClosestToCurrentTime[i];
      item.frame = null;
    }
  };
  const ensureFrameBeforeAndAfter = async ({
    timeInSec,
    loopBehavior
  }) => {
    const actualTimeInSec = getActualTime({
      durationFound,
      loopBehavior,
      timeInSec
    });
    const framesBefore = cache.filter((c) => c.timeInSeconds <= actualTimeInSec);
    const biggestIndex = framesBefore.map((c) => c.frameIndex).reduce((a, b) => Math.max(a, b), 0);
    let i = biggestIndex;
    while (true) {
      const f = await getFrameByIndex(i);
      i++;
      if (!f.frame) {
        throw new Error("No frame found");
      }
      if (!f.frame.duration) {
        break;
      }
      if (i === selectedTrack.frameCount && durationFound === null) {
        const duration = (f.frame.timestamp + f.frame.duration) / 1e6;
        durationFound = duration;
      }
      if (f.timeInSeconds > actualTimeInSec || i === selectedTrack.frameCount) {
        break;
      }
    }
    if (selectedTrack.frameCount - biggestIndex < 3 && loopBehavior === "loop") {
      await getFrameByIndex(0);
    }
    clearCache(actualTimeInSec);
  };
  await ensureFrameBeforeAndAfter({
    timeInSec: currentTime,
    loopBehavior: initialLoopBehavior
  });
  await ensureFrameBeforeAndAfter({
    timeInSec: currentTime,
    loopBehavior: initialLoopBehavior
  });
  const getFrame = async (timeInSec, loopBehavior) => {
    if (durationFound !== null && timeInSec > durationFound && loopBehavior === "clear-after-finish") {
      return null;
    }
    const actualTimeInSec = getActualTime({
      loopBehavior,
      durationFound,
      timeInSec
    });
    await ensureFrameBeforeAndAfter({ timeInSec: actualTimeInSec, loopBehavior });
    const itemsInCache = cache.filter((c) => c.frame);
    const closest = itemsInCache.reduce((a, b) => {
      const aDiff = Math.abs(a.timeInSeconds - actualTimeInSec);
      const bDiff = Math.abs(b.timeInSeconds - actualTimeInSec);
      return aDiff < bDiff ? a : b;
    });
    if (!closest.frame) {
      throw new Error("No frame found");
    }
    return closest;
  };
  return {
    getFrame,
    frameCount: selectedTrack.frameCount
  };
};

// src/animated-image/resolve-image-source.tsx
var resolveAnimatedImageSource = (src) => {
  if (typeof window === "undefined") {
    return src;
  }
  return new URL(src, window.origin).href;
};

// src/animated-image/AnimatedImage.tsx
import { jsx as jsx14 } from "react/jsx-runtime";
var AnimatedImage = forwardRef4(({
  src,
  width,
  height,
  onError,
  loopBehavior = "loop",
  playbackRate = 1,
  fit = "fill",
  ...props
}, canvasRef) => {
  const mountState = useRef8({ isMounted: true });
  useEffect6(() => {
    const { current } = mountState;
    current.isMounted = true;
    return () => {
      current.isMounted = false;
    };
  }, []);
  const resolvedSrc = resolveAnimatedImageSource(src);
  const [imageDecoder, setImageDecoder] = useState7(null);
  const { delayRender: delayRender2, continueRender: continueRender2 } = useDelayRender();
  const [decodeHandle] = useState7(() => delayRender2(`Rendering <AnimatedImage/> with src="${resolvedSrc}"`));
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const currentTime = frame / playbackRate / fps;
  const currentTimeRef = useRef8(currentTime);
  currentTimeRef.current = currentTime;
  const ref = useRef8(null);
  useImperativeHandle2(canvasRef, () => {
    const c = ref.current?.getCanvas();
    if (!c) {
      throw new Error("Canvas ref is not set");
    }
    return c;
  }, []);
  const [initialLoopBehavior] = useState7(() => loopBehavior);
  useEffect6(() => {
    const controller = new AbortController;
    decodeImage({
      resolvedSrc,
      signal: controller.signal,
      currentTime: currentTimeRef.current,
      initialLoopBehavior
    }).then((d) => {
      setImageDecoder(d);
      continueRender2(decodeHandle);
    }).catch((err) => {
      if (err.name === "AbortError") {
        continueRender2(decodeHandle);
        return;
      }
      if (onError) {
        onError?.(err);
        continueRender2(decodeHandle);
      } else {
        cancelRender(err);
      }
    });
    return () => {
      controller.abort();
    };
  }, [
    resolvedSrc,
    decodeHandle,
    onError,
    initialLoopBehavior,
    continueRender2
  ]);
  useLayoutEffect2(() => {
    if (!imageDecoder) {
      return;
    }
    const delay = delayRender2(`Rendering frame at ${currentTime} of <AnimatedImage src="${src}"/>`);
    imageDecoder.getFrame(currentTime, loopBehavior).then((videoFrame) => {
      if (mountState.current.isMounted) {
        if (videoFrame === null) {
          ref.current?.clear();
        } else {
          ref.current?.draw(videoFrame.frame);
        }
      }
      continueRender2(delay);
    }).catch((err) => {
      if (onError) {
        onError(err);
        continueRender2(delay);
      } else {
        cancelRender(err);
      }
    });
  }, [
    currentTime,
    imageDecoder,
    loopBehavior,
    onError,
    src,
    continueRender2,
    delayRender2
  ]);
  return /* @__PURE__ */ jsx14(Canvas, {
    ref,
    width,
    height,
    fit,
    ...props
  });
});
// src/HtmlInCanvas.tsx
import {
  createContext as createContext15,
  forwardRef as forwardRef5,
  useCallback as useCallback7,
  useContext as useContext17,
  useLayoutEffect as useLayoutEffect3,
  useMemo as useMemo17,
  useRef as useRef9,
  useState as useState8
} from "react";
import { jsx as jsx15 } from "react/jsx-runtime";
var cachedSupport = null;
var isHtmlInCanvasSupported = () => {
  if (cachedSupport !== null) {
    return cachedSupport;
  }
  if (typeof document === "undefined") {
    return false;
  }
  const canvas = document.createElement("canvas");
  const ctx = canvas.getContext("2d");
  cachedSupport = typeof ctx?.drawElementImage === "function" && typeof canvas.requestPaint === "function" && typeof canvas.captureElementImage === "function";
  return cachedSupport;
};
function assertHtmlInCanvasDimensions(width, height) {
  if (typeof width !== "number" || typeof height !== "number") {
    throw new Error(`HtmlInCanvas: \`width\` and \`height\` must be numbers. Received width=${String(width)}, height=${String(height)}.`);
  }
  if (!Number.isInteger(width) || width <= 0) {
    throw new Error(`HtmlInCanvas: \`width\` must be a positive integer. Received: ${String(width)}.`);
  }
  if (!Number.isInteger(height) || height <= 0) {
    throw new Error(`HtmlInCanvas: \`height\` must be a positive integer. Received: ${String(height)}.`);
  }
}
var defaultOnPaint = ({
  canvas,
  element,
  elementImage
}) => {
  const ctx = canvas.getContext("2d");
  if (!ctx) {
    throw new Error("Failed to acquire 2D context for <HtmlInCanvas> canvas");
  }
  ctx.reset();
  const transform = ctx.drawElementImage(elementImage, 0, 0);
  element.style.transform = transform.toString();
};
var HtmlInCanvasAncestorContext = createContext15(false);
var HtmlInCanvasInner = forwardRef5(({
  width,
  height,
  _experimentalEffects: effects = [],
  children,
  onPaint,
  onInit,
  _experimentalControls: controls,
  style,
  durationInFrames,
  ...sequenceProps
}, ref) => {
  const isInsideAncestorHtmlInCanvas = useContext17(HtmlInCanvasAncestorContext);
  assertHtmlInCanvasDimensions(width, height);
  const { continueRender: continueRender2, cancelRender: cancelRender2 } = useDelayRender();
  if (!isHtmlInCanvasSupported()) {
    cancelRender2(new Error("HTML in Canvas is not supported. Open this page in Chrome Canary with chrome://flags/#canvas-draw-element enabled."));
  }
  const { durationInFrames: videoDuration } = useVideoConfig();
  const resolvedDuration = durationInFrames ?? videoDuration;
  const frame = useCurrentFrame();
  const canvas2dRef = useRef9(null);
  const divRef = useRef9(null);
  const setLayoutCanvasRef = useCallback7((node) => {
    canvas2dRef.current = node;
    if (typeof ref === "function") {
      ref(node);
    } else if (ref) {
      ref.current = node;
    }
  }, [ref]);
  const [offscreenCanvas] = useState8(() => new OffscreenCanvas(1, 1));
  const chainState = useEffectChainState();
  const effectsRef = useRef9(effects);
  effectsRef.current = effects;
  const frameRef = useRef9(frame);
  frameRef.current = frame;
  const onPaintRef = useRef9(onPaint);
  onPaintRef.current = onPaint;
  const onInitRef = useRef9(onInit);
  onInitRef.current = onInit;
  const initializedRef = useRef9(false);
  const onInitCleanupRef = useRef9(null);
  const unmountedRef = useRef9(false);
  const onPaintCb = useCallback7(async () => {
    const element = divRef.current;
    if (!element) {
      throw new Error("Canvas or scene element not found");
    }
    offscreenCanvas.width = width;
    offscreenCanvas.height = height;
    try {
      const layoutCanvas = canvas2dRef.current;
      if (!layoutCanvas) {
        throw new Error("Canvas not found");
      }
      const layout2d = layoutCanvas.getContext("2d");
      if (!layout2d) {
        throw new Error("Failed to acquire 2D context for <HtmlInCanvas> layout canvas");
      }
      const handle = delayRender("onPaint");
      if (!initializedRef.current) {
        initializedRef.current = true;
        const initImage = layoutCanvas.captureElementImage(element);
        const currentOnInit = onInitRef.current;
        if (currentOnInit) {
          const cleanup = await currentOnInit({
            canvas: offscreenCanvas,
            element,
            elementImage: initImage
          });
          if (typeof cleanup !== "function") {
            throw new Error("HtmlInCanvas: when `onInit` is provided, it must return a cleanup function, or a Promise that resolves to one.");
          }
          if (unmountedRef.current) {
            cleanup();
          } else {
            onInitCleanupRef.current = cleanup;
          }
        }
      }
      const handler = onPaintRef.current ?? defaultOnPaint;
      const elImage = layoutCanvas.captureElementImage(element);
      await handler({
        canvas: offscreenCanvas,
        element,
        elementImage: elImage
      });
      await runEffectChain({
        state: chainState.get(width, height),
        source: offscreenCanvas,
        effects: effectsRef.current,
        output: canvas2dRef.current,
        frame: frameRef.current,
        width,
        height
      });
      continueRender2(handle);
    } catch (error2) {
      cancelRender2(error2);
    }
  }, [
    chainState,
    continueRender2,
    cancelRender2,
    width,
    height,
    offscreenCanvas
  ]);
  useLayoutEffect3(() => {
    const canvas = canvas2dRef.current;
    if (!canvas) {
      throw new Error("Canvas not found");
    }
    canvas.layoutSubtree = true;
    canvas.addEventListener("paint", onPaintCb);
    return () => {
      canvas.removeEventListener("paint", onPaintCb);
      unmountedRef.current = true;
      onInitCleanupRef.current?.();
      onInitCleanupRef.current = null;
    };
  }, [onPaintCb, cancelRender2]);
  const onPaintChangedRef = useRef9(false);
  useLayoutEffect3(() => {
    if (!onPaintChangedRef.current) {
      onPaintChangedRef.current = true;
      return;
    }
    const canvas = canvas2dRef.current;
    if (!canvas) {
      return;
    }
    canvas.requestPaint?.();
  }, [onPaint]);
  useLayoutEffect3(() => {
    const canvas = canvas2dRef.current;
    if (!canvas) {
      return;
    }
    const handle = delayRender("waiting for first paint after canvas resize");
    canvas.addEventListener("paint", () => {
      continueRender2(handle);
    }, { once: true });
    return () => {
      continueRender2(handle);
    };
  }, [width, height, continueRender2]);
  const innerStyle = useMemo17(() => {
    return {
      width,
      height
    };
  }, [width, height]);
  if (isInsideAncestorHtmlInCanvas) {
    throw new Error("<HtmlInCanvas> effects cannot be nested together. Chrome will only display the outer effect. Consider merging the effects into one if you can.");
  }
  return /* @__PURE__ */ jsx15(Sequence, {
    durationInFrames: resolvedDuration,
    name: "<HtmlInCanvas>",
    _experimentalControls: controls,
    _experimentalEffects: effects,
    layout: "none",
    ...sequenceProps,
    children: /* @__PURE__ */ jsx15(HtmlInCanvasAncestorContext.Provider, {
      value: true,
      children: /* @__PURE__ */ jsx15("canvas", {
        ref: setLayoutCanvasRef,
        width,
        height,
        style,
        children: /* @__PURE__ */ jsx15("div", {
          ref: divRef,
          style: innerStyle,
          children
        })
      })
    })
  });
});
HtmlInCanvasInner.displayName = "HtmlInCanvas";
var HtmlInCanvasWrapped = wrapInSchema(HtmlInCanvasInner, sequenceStyleSchema);
var HtmlInCanvas = Object.assign(HtmlInCanvasWrapped, {
  isSupported: isHtmlInCanvasSupported
});
HtmlInCanvas.displayName = "HtmlInCanvas";
addSequenceStackTraces(HtmlInCanvas);
// src/Artifact.tsx
import { useContext as useContext18, useLayoutEffect as useLayoutEffect5, useState as useState10 } from "react";

// src/RenderAssetManager.tsx
import {
  createContext as createContext16,
  useCallback as useCallback8,
  useImperativeHandle as useImperativeHandle3,
  useLayoutEffect as useLayoutEffect4,
  useMemo as useMemo18,
  useRef as useRef10,
  useState as useState9
} from "react";

// src/validation/validate-artifact.ts
var validateArtifactFilename = (filename) => {
  if (typeof filename !== "string") {
    throw new TypeError(`The "filename" must be a string, but you passed a value of type ${typeof filename}`);
  }
  if (filename.trim() === "") {
    throw new Error("The `filename` must not be empty");
  }
  if (!filename.match(/^([0-9a-zA-Z-!_.*'()/:&$@=;+,?]+)/g)) {
    throw new Error('The `filename` must match "/^([0-9a-zA-Z-!_.*\'()/:&$@=;+,?]+)/g". Use forward slashes only, even on Windows.');
  }
};
var validateContent = (content) => {
  if (typeof content !== "string" && !(content instanceof Uint8Array)) {
    throw new TypeError(`The "content" must be a string or Uint8Array, but you passed a value of type ${typeof content}`);
  }
  if (typeof content === "string" && content.trim() === "") {
    throw new Error("The `content` must not be empty");
  }
};
var validateRenderAsset = (artifact) => {
  if (artifact.type !== "artifact") {
    return;
  }
  validateArtifactFilename(artifact.filename);
  if (artifact.contentType === "thumbnail") {
    return;
  }
  validateContent(artifact.content);
};

// src/RenderAssetManager.tsx
import { jsx as jsx16 } from "react/jsx-runtime";
var RenderAssetManager = createContext16({
  registerRenderAsset: () => {
    return;
  },
  unregisterRenderAsset: () => {
    return;
  },
  renderAssets: []
});
var RenderAssetManagerProvider = ({ children, collectAssets }) => {
  const [renderAssets, setRenderAssets] = useState9([]);
  const renderAssetsRef = useRef10([]);
  const registerRenderAsset = useCallback8((renderAsset) => {
    validateRenderAsset(renderAsset);
    renderAssetsRef.current = [...renderAssetsRef.current, renderAsset];
    setRenderAssets(renderAssetsRef.current);
  }, []);
  if (collectAssets) {
    useImperativeHandle3(collectAssets, () => {
      return {
        collectAssets: () => {
          const assets = renderAssetsRef.current;
          renderAssetsRef.current = [];
          setRenderAssets([]);
          return assets;
        }
      };
    }, []);
  }
  const unregisterRenderAsset = useCallback8((id) => {
    renderAssetsRef.current = renderAssetsRef.current.filter((a) => a.id !== id);
    setRenderAssets(renderAssetsRef.current);
  }, []);
  useLayoutEffect4(() => {
    if (typeof window !== "undefined") {
      window.remotion_collectAssets = () => {
        const assets = renderAssetsRef.current;
        renderAssetsRef.current = [];
        setRenderAssets([]);
        return assets;
      };
    }
  }, []);
  const contextValue = useMemo18(() => {
    return {
      registerRenderAsset,
      unregisterRenderAsset,
      renderAssets
    };
  }, [renderAssets, registerRenderAsset, unregisterRenderAsset]);
  return /* @__PURE__ */ jsx16(RenderAssetManager.Provider, {
    value: contextValue,
    children
  });
};

// src/Artifact.tsx
var ArtifactThumbnail = Symbol("Thumbnail");
var Artifact = ({ filename, content, downloadBehavior }) => {
  const { registerRenderAsset, unregisterRenderAsset } = useContext18(RenderAssetManager);
  const env = useRemotionEnvironment();
  const frame = useCurrentFrame();
  const [id] = useState10(() => {
    return String(Math.random());
  });
  useLayoutEffect5(() => {
    if (!env.isRendering) {
      return;
    }
    if (content instanceof Uint8Array) {
      registerRenderAsset({
        type: "artifact",
        id,
        content: btoa(new TextDecoder("utf8").decode(content)),
        filename,
        frame,
        contentType: "binary",
        downloadBehavior: downloadBehavior ?? null
      });
    } else if (content === ArtifactThumbnail) {
      registerRenderAsset({
        type: "artifact",
        id,
        filename,
        frame,
        contentType: "thumbnail",
        downloadBehavior: downloadBehavior ?? null
      });
    } else {
      registerRenderAsset({
        type: "artifact",
        id,
        content,
        filename,
        frame,
        contentType: "text",
        downloadBehavior: downloadBehavior ?? null
      });
    }
    return () => {
      return unregisterRenderAsset(id);
    };
  }, [
    content,
    env.isRendering,
    filename,
    frame,
    id,
    registerRenderAsset,
    unregisterRenderAsset,
    downloadBehavior
  ]);
  return null;
};
Artifact.Thumbnail = ArtifactThumbnail;
// src/audio/html5-audio.tsx
import { forwardRef as forwardRef8, useCallback as useCallback13, useContext as useContext30 } from "react";

// src/absolute-src.ts
var getAbsoluteSrc = (relativeSrc) => {
  if (typeof window === "undefined") {
    return relativeSrc;
  }
  if (relativeSrc.startsWith("http://") || relativeSrc.startsWith("https://") || relativeSrc.startsWith("file://") || relativeSrc.startsWith("blob:") || relativeSrc.startsWith("data:")) {
    return relativeSrc;
  }
  return new URL(relativeSrc, window.origin).href;
};

// src/calculate-media-duration.ts
var calculateMediaDuration = ({
  trimAfter,
  mediaDurationInFrames,
  playbackRate,
  trimBefore
}) => {
  let duration = mediaDurationInFrames;
  if (typeof trimAfter !== "undefined") {
    duration = trimAfter;
  }
  if (typeof trimBefore !== "undefined") {
    duration -= trimBefore;
  }
  const actualDuration = duration / playbackRate;
  return Math.floor(actualDuration);
};

// src/loop/index.tsx
import React17, { createContext as createContext17, useMemo as useMemo19 } from "react";
import { jsx as jsx17 } from "react/jsx-runtime";
var LoopContext = createContext17(null);
var useLoop = () => {
  return React17.useContext(LoopContext);
};
var Loop = ({
  durationInFrames,
  times = Infinity,
  children,
  name,
  showInTimeline,
  ...props
}) => {
  const currentFrame = useCurrentFrame();
  const { durationInFrames: compDuration } = useVideoConfig();
  validateDurationInFrames(durationInFrames, {
    component: "of the <Loop /> component",
    allowFloats: true
  });
  if (typeof times !== "number") {
    throw new TypeError(`You passed to "times" an argument of type ${typeof times}, but it must be a number.`);
  }
  if (times !== Infinity && times % 1 !== 0) {
    throw new TypeError(`The "times" prop of a loop must be an integer, but got ${times}.`);
  }
  if (times < 0) {
    throw new TypeError(`The "times" prop of a loop must be at least 0, but got ${times}`);
  }
  const maxTimes = Math.ceil(compDuration / durationInFrames);
  const actualTimes = Math.min(maxTimes, times);
  const style = props.layout === "none" ? undefined : props.style;
  const maxFrame = durationInFrames * (actualTimes - 1);
  const iteration = Math.floor(currentFrame / durationInFrames);
  const start = iteration * durationInFrames;
  const from = Math.min(start, maxFrame);
  const loopDisplay = useMemo19(() => {
    return {
      numberOfTimes: Math.min(compDuration / durationInFrames, times),
      startOffset: -from,
      durationInFrames
    };
  }, [compDuration, durationInFrames, from, times]);
  const loopContext = useMemo19(() => {
    return {
      iteration: Math.floor(currentFrame / durationInFrames),
      durationInFrames
    };
  }, [currentFrame, durationInFrames]);
  return /* @__PURE__ */ jsx17(LoopContext.Provider, {
    value: loopContext,
    children: /* @__PURE__ */ jsx17(Sequence, {
      durationInFrames,
      from,
      name: name ?? "<Loop>",
      _remotionInternalLoopDisplay: loopDisplay,
      layout: props.layout,
      style,
      showInTimeline,
      children
    })
  });
};
Loop.useLoop = useLoop;

// src/prefetch.ts
import { useContext as useContext19 } from "react";

// src/playback-logging.ts
var playbackLogging = ({
  logLevel,
  tag,
  message,
  mountTime
}) => {
  const tags = [mountTime ? Date.now() - mountTime + "ms " : null, tag].filter(Boolean).join(" ");
  Log.trace({ logLevel, tag: null }, `[${tags}]`, message);
};

// src/prefetch-state.tsx
import { createContext as createContext18, useEffect as useEffect7, useState as useState11 } from "react";
import { jsx as jsx18 } from "react/jsx-runtime";
var PreloadContext = createContext18({});
var preloads = {};
var updaters = [];
var setPreloads = (updater) => {
  preloads = updater(preloads);
  updaters.forEach((u) => u());
};
var PrefetchProvider = ({ children }) => {
  const [_preloads, _setPreloads] = useState11(() => preloads);
  useEffect7(() => {
    const updaterFunction = () => {
      _setPreloads(preloads);
    };
    updaters.push(updaterFunction);
    return () => {
      updaters = updaters.filter((u) => u !== updaterFunction);
    };
  }, []);
  return /* @__PURE__ */ jsx18(PreloadContext.Provider, {
    value: _preloads,
    children
  });
};

// src/prefetch.ts
var removeAndGetHashFragment = (src) => {
  const hashIndex = src.indexOf("#");
  if (hashIndex === -1) {
    return null;
  }
  return hashIndex;
};
var getSrcWithoutHash = (src) => {
  const hashIndex = removeAndGetHashFragment(src);
  if (hashIndex === null) {
    return src;
  }
  return src.slice(0, hashIndex);
};
var usePreload = (src) => {
  const preloads2 = useContext19(PreloadContext);
  const hashFragmentIndex = removeAndGetHashFragment(src);
  const withoutHashFragment = getSrcWithoutHash(src);
  if (!preloads2[withoutHashFragment]) {
    return src;
  }
  if (hashFragmentIndex !== null) {
    return preloads2[withoutHashFragment] + src.slice(hashFragmentIndex);
  }
  return preloads2[withoutHashFragment];
};
var blobToBase64 = function(blob) {
  const reader = new FileReader;
  return new Promise((resolve, reject) => {
    reader.onload = function() {
      const dataUrl = reader.result;
      resolve(dataUrl);
    };
    reader.onerror = (err) => {
      return reject(err);
    };
    reader.readAsDataURL(blob);
  });
};
var getBlobFromReader = async ({
  reader,
  contentType,
  contentLength,
  onProgress
}) => {
  let receivedLength = 0;
  const chunks = [];
  while (true) {
    const { done, value } = await reader.read();
    if (done) {
      break;
    }
    chunks.push(value);
    receivedLength += value.length;
    if (onProgress) {
      onProgress({ loadedBytes: receivedLength, totalBytes: contentLength });
    }
  }
  const chunksAll = new Uint8Array(receivedLength);
  let position = 0;
  for (const chunk of chunks) {
    chunksAll.set(chunk, position);
    position += chunk.length;
  }
  return new Blob([chunksAll], {
    type: contentType ?? undefined
  });
};
var prefetch = (src, options) => {
  const method = options?.method ?? "blob-url";
  const logLevel = options?.logLevel ?? "info";
  const srcWithoutHash = getSrcWithoutHash(src);
  if (getRemotionEnvironment().isRendering) {
    return {
      free: () => {
        return;
      },
      waitUntilDone: () => Promise.resolve(srcWithoutHash)
    };
  }
  Log.verbose({ logLevel, tag: "prefetch" }, `Starting prefetch ${srcWithoutHash}`);
  let canceled = false;
  let objectUrl = null;
  let resolve = () => {
    return;
  };
  let reject = () => {
    return;
  };
  const waitUntilDone = new Promise((res, rej) => {
    resolve = res;
    reject = rej;
  });
  const controller = new AbortController;
  let canBeAborted = true;
  fetch(srcWithoutHash, {
    signal: controller.signal,
    credentials: options?.credentials ?? undefined
  }).then((res) => {
    canBeAborted = false;
    if (canceled) {
      return null;
    }
    if (!res.ok) {
      throw new Error(`HTTP error, status = ${res.status}`);
    }
    const headerContentType = res.headers.get("Content-Type");
    const contentType = options?.contentType ?? headerContentType;
    const hasProperContentType = contentType && (contentType.startsWith("video/") || contentType.startsWith("audio/") || contentType.startsWith("image/"));
    if (!hasProperContentType) {
      console.warn(`Called prefetch() on ${srcWithoutHash} which returned a "Content-Type" of ${headerContentType}. Prefetched content should have a proper content type (video/... or audio/...) or a contentType passed the options of prefetch(). Otherwise, prefetching will not work properly in all browsers.`);
    }
    if (!res.body) {
      throw new Error(`HTTP response of ${srcWithoutHash} has no body`);
    }
    const reader = res.body.getReader();
    return getBlobFromReader({
      reader,
      contentType: options?.contentType ?? headerContentType ?? null,
      contentLength: res.headers.get("Content-Length") ? parseInt(res.headers.get("Content-Length"), 10) : null,
      onProgress: options?.onProgress
    });
  }).then((buf) => {
    if (!buf) {
      return;
    }
    const actualBlob = options?.contentType ? new Blob([buf], { type: options.contentType }) : buf;
    if (method === "base64") {
      return blobToBase64(actualBlob);
    }
    return URL.createObjectURL(actualBlob);
  }).then((url) => {
    if (canceled) {
      return;
    }
    playbackLogging({
      logLevel,
      tag: "prefetch",
      message: `Finished prefetch ${srcWithoutHash} with method ${method}`,
      mountTime: null
    });
    objectUrl = url;
    setPreloads((p) => ({
      ...p,
      [srcWithoutHash]: objectUrl
    }));
    resolve(objectUrl);
  }).catch((err) => {
    if (err?.message.includes("free() called")) {
      return;
    }
    reject(err);
  });
  return {
    free: () => {
      playbackLogging({
        logLevel,
        tag: "prefetch",
        message: `Freeing ${srcWithoutHash}`,
        mountTime: null
      });
      if (objectUrl) {
        if (method === "blob-url") {
          URL.revokeObjectURL(objectUrl);
        }
        setPreloads((p) => {
          const copy = { ...p };
          delete copy[srcWithoutHash];
          return copy;
        });
      } else {
        canceled = true;
        if (canBeAborted) {
          try {
            controller.abort(new Error("free() called"));
          } catch {}
        }
      }
    },
    waitUntilDone: () => {
      return waitUntilDone;
    }
  };
};

// src/validate-media-props.ts
var validateMediaProps = (props, component) => {
  if (typeof props.volume !== "number" && typeof props.volume !== "function" && typeof props.volume !== "undefined") {
    throw new TypeError(`You have passed a volume of type ${typeof props.volume} to your <${component} /> component. Volume must be a number or a function with the signature '(frame: number) => number' undefined.`);
  }
  if (typeof props.volume === "number" && props.volume < 0) {
    throw new TypeError(`You have passed a volume below 0 to your <${component} /> component. Volume must be between 0 and 1`);
  }
  if (typeof props.playbackRate !== "number" && typeof props.playbackRate !== "undefined") {
    throw new TypeError(`You have passed a playbackRate of type ${typeof props.playbackRate} to your <${component} /> component. Playback rate must a real number or undefined.`);
  }
  if (typeof props.playbackRate === "number" && (isNaN(props.playbackRate) || !Number.isFinite(props.playbackRate) || props.playbackRate <= 0)) {
    throw new TypeError(`You have passed a playbackRate of ${props.playbackRate} to your <${component} /> component. Playback rate must be a real number above 0.`);
  }
};

// src/validate-start-from-props.ts
var validateStartFromProps = (startFrom, endAt) => {
  if (typeof startFrom !== "undefined") {
    if (typeof startFrom !== "number") {
      throw new TypeError(`type of startFrom prop must be a number, instead got type ${typeof startFrom}.`);
    }
    if (isNaN(startFrom) || startFrom === Infinity) {
      throw new TypeError("startFrom prop can not be NaN or Infinity.");
    }
    if (startFrom < 0) {
      throw new TypeError(`startFrom must be greater than equal to 0 instead got ${startFrom}.`);
    }
  }
  if (typeof endAt !== "undefined") {
    if (typeof endAt !== "number") {
      throw new TypeError(`type of endAt prop must be a number, instead got type ${typeof endAt}.`);
    }
    if (isNaN(endAt)) {
      throw new TypeError("endAt prop can not be NaN.");
    }
    if (endAt <= 0) {
      throw new TypeError(`endAt must be a positive number, instead got ${endAt}.`);
    }
  }
  if (endAt < startFrom) {
    throw new TypeError("endAt prop must be greater than startFrom prop.");
  }
};
var validateTrimProps = (trimBefore, trimAfter) => {
  if (typeof trimBefore !== "undefined") {
    if (typeof trimBefore !== "number") {
      throw new TypeError(`type of trimBefore prop must be a number, instead got type ${typeof trimBefore}.`);
    }
    if (isNaN(trimBefore) || trimBefore === Infinity) {
      throw new TypeError("trimBefore prop can not be NaN or Infinity.");
    }
    if (trimBefore < 0) {
      throw new TypeError(`trimBefore must be greater than equal to 0 instead got ${trimBefore}.`);
    }
  }
  if (typeof trimAfter !== "undefined") {
    if (typeof trimAfter !== "number") {
      throw new TypeError(`type of trimAfter prop must be a number, instead got type ${typeof trimAfter}.`);
    }
    if (isNaN(trimAfter)) {
      throw new TypeError("trimAfter prop can not be NaN.");
    }
    if (trimAfter <= 0) {
      throw new TypeError(`trimAfter must be a positive number, instead got ${trimAfter}.`);
    }
  }
  if (trimAfter <= trimBefore) {
    throw new TypeError("trimAfter prop must be greater than trimBefore prop.");
  }
};
var validateMediaTrimProps = ({
  startFrom,
  endAt,
  trimBefore,
  trimAfter
}) => {
  if (typeof startFrom !== "undefined" && typeof trimBefore !== "undefined") {
    throw new TypeError("Cannot use both startFrom and trimBefore props. Use trimBefore instead as startFrom is deprecated.");
  }
  if (typeof endAt !== "undefined" && typeof trimAfter !== "undefined") {
    throw new TypeError("Cannot use both endAt and trimAfter props. Use trimAfter instead as endAt is deprecated.");
  }
  const hasNewProps = typeof trimBefore !== "undefined" || typeof trimAfter !== "undefined";
  const hasOldProps = typeof startFrom !== "undefined" || typeof endAt !== "undefined";
  if (hasNewProps) {
    validateTrimProps(trimBefore, trimAfter);
  } else if (hasOldProps) {
    validateStartFromProps(startFrom, endAt);
  }
};
var resolveTrimProps = ({
  startFrom,
  endAt,
  trimBefore,
  trimAfter
}) => {
  const trimBeforeValue = trimBefore ?? startFrom ?? undefined;
  const trimAfterValue = trimAfter ?? endAt ?? undefined;
  return { trimBeforeValue, trimAfterValue };
};

// src/video/duration-state.tsx
import { createContext as createContext19, useMemo as useMemo20, useReducer } from "react";
import { jsx as jsx19 } from "react/jsx-runtime";
var durationReducer = (state, action) => {
  switch (action.type) {
    case "got-duration": {
      const absoluteSrc = getAbsoluteSrc(action.src);
      if (state[absoluteSrc] === action.durationInSeconds) {
        return state;
      }
      return {
        ...state,
        [absoluteSrc]: action.durationInSeconds
      };
    }
    default:
      return state;
  }
};
var DurationsContext = createContext19({
  durations: {},
  setDurations: () => {
    throw new Error("context missing");
  }
});
var DurationsContextProvider = ({ children }) => {
  const [durations, setDurations] = useReducer(durationReducer, {});
  const value = useMemo20(() => {
    return {
      durations,
      setDurations
    };
  }, [durations]);
  return /* @__PURE__ */ jsx19(DurationsContext.Provider, {
    value,
    children
  });
};

// src/audio/AudioForPreview.tsx
import React23, {
  forwardRef as forwardRef6,
  useContext as useContext28,
  useEffect as useEffect14,
  useImperativeHandle as useImperativeHandle4,
  useMemo as useMemo28,
  useRef as useRef18,
  useState as useState16
} from "react";

// src/get-cross-origin-value.ts
var getCrossOriginValue = ({
  crossOrigin,
  requestsVideoFrame,
  isClientSideRendering
}) => {
  if (crossOrigin !== undefined && crossOrigin !== null) {
    return crossOrigin;
  }
  if (isClientSideRendering) {
    return "anonymous";
  }
  if (requestsVideoFrame) {
    return "anonymous";
  }
  return;
};

// src/use-amplification.ts
import { useContext as useContext21, useLayoutEffect as useLayoutEffect6, useRef as useRef13 } from "react";

// src/audio/shared-audio-tags.tsx
import React20, {
  createContext as createContext20,
  createRef as createRef2,
  useCallback as useCallback9,
  useContext as useContext20,
  useMemo as useMemo22,
  useRef as useRef11,
  useState as useState12
} from "react";

// src/play-and-handle-not-allowed-error.ts
var playAndHandleNotAllowedError = ({
  mediaRef,
  mediaType,
  onAutoPlayError,
  logLevel,
  mountTime,
  reason,
  isPlayer
}) => {
  const { current } = mediaRef;
  if (!current) {
    return;
  }
  playbackLogging({
    logLevel,
    tag: "play",
    message: `Attempting to play ${current.src}. Reason: ${reason}`,
    mountTime
  });
  const prom = current.play();
  if (!prom.catch) {
    return;
  }
  prom.catch((err) => {
    if (!current) {
      return;
    }
    if (err.message.includes("request was interrupted by a call to pause")) {
      return;
    }
    if (err.message.includes("The operation was aborted.")) {
      return;
    }
    if (err.message.includes("The fetching process for the media resource was aborted by the user agent")) {
      return;
    }
    if (err.message.includes("request was interrupted by a new load request")) {
      return;
    }
    if (err.message.includes("because the media was removed from the document")) {
      return;
    }
    if (err.message.includes("user didn't interact with the document") && current.muted) {
      return;
    }
    console.log(`Could not play ${mediaType} due to following error: `, err);
    if (!current.muted) {
      if (onAutoPlayError) {
        onAutoPlayError();
        return;
      }
      if (mediaType === "video" && isPlayer) {
        Log.info({ logLevel, tag: "<" + mediaType + ">" }, `The video will be muted and we'll retry playing it.`);
        Log.info({ logLevel, tag: "<" + mediaType + ">" }, "Use onAutoPlayError() to handle this error yourself.");
        current.muted = true;
        current.play();
      }
    }
  });
};

// src/audio/shared-element-source-node.ts
var makeSharedElementSourceNode = ({
  audioContext,
  ref
}) => {
  let connected = null;
  let disposed = false;
  return {
    attemptToConnect: () => {
      if (disposed) {
        throw new Error("SharedElementSourceNode has been disposed");
      }
      if (!connected && ref.current) {
        const mediaElementSourceNode = audioContext.createMediaElementSource(ref.current);
        connected = mediaElementSourceNode;
      }
    },
    get: () => {
      if (!connected) {
        throw new Error("Audio element not connected");
      }
      return connected;
    },
    cleanup: () => {
      if (connected) {
        connected.disconnect();
        connected = null;
      }
      disposed = true;
    }
  };
};

// src/audio/use-audio-context.ts
import { useMemo as useMemo21 } from "react";
var warned = false;
var warnOnce = (logLevel) => {
  if (warned) {
    return;
  }
  warned = true;
  if (typeof window !== "undefined") {
    Log.warn({ logLevel, tag: null }, "AudioContext is not supported in this browser");
  }
};
var useSingletonAudioContext = ({
  logLevel,
  latencyHint,
  audioEnabled
}) => {
  const env = useRemotionEnvironment();
  const context = useMemo21(() => {
    if (env.isRendering) {
      return null;
    }
    if (!audioEnabled) {
      return null;
    }
    if (typeof AudioContext === "undefined") {
      warnOnce(logLevel);
      return null;
    }
    const audioContext = new AudioContext({
      latencyHint,
      sampleRate: 48000
    });
    const gainNode = audioContext.createGain();
    gainNode.connect(audioContext.destination);
    Log.trace({ logLevel, tag: "audio" }, "Creating new audio context");
    audioContext.suspend();
    return {
      audioContext,
      gainNode
    };
  }, [logLevel, latencyHint, env.isRendering, audioEnabled]);
  return context;
};

// src/audio/wait-until-actually-resumed.ts
var waitUntilActuallyResumed = (audioContext, logLevel) => {
  return new Promise((resolve) => {
    const startCurrentTime = audioContext.currentTime;
    const start = audioContext.getOutputTimestamp();
    const startOutputPerformanceTime = start.performanceTime;
    const startWallClock = performance.now();
    const check = () => {
      const { currentTime } = audioContext;
      const outputTimestamp = audioContext.getOutputTimestamp();
      const elapsedWallClock = performance.now() - startWallClock;
      if (startOutputPerformanceTime !== undefined && outputTimestamp.performanceTime !== undefined && outputTimestamp.performanceTime > startOutputPerformanceTime && outputTimestamp.contextTime !== undefined && outputTimestamp.contextTime > startCurrentTime) {
        Log.verbose({ logLevel, tag: "audio" }, `waitUntilActuallyResumed: getOutputTimestamp.performanceTime advanced from ${startOutputPerformanceTime.toFixed(6)} to ${outputTimestamp.performanceTime.toFixed(6)} after ${elapsedWallClock.toFixed(1)}ms. currentTime=${currentTime.toFixed(6)} (advanced by ${(currentTime - startCurrentTime).toFixed(6)}), getOutputTimestamp.performanceTime=${outputTimestamp.performanceTime?.toFixed(1) ?? "undefined"}`);
        resolve();
        return;
      }
      requestAnimationFrame(check);
    };
    requestAnimationFrame(check);
  });
};

// src/audio/shared-audio-tags.tsx
import { jsx as jsx20, jsxs as jsxs2 } from "react/jsx-runtime";
var EMPTY_AUDIO = "data:audio/mp3;base64,/+MYxAAJcAV8AAgAABn//////+/gQ5BAMA+D4Pg+BAQBAEAwD4Pg+D4EBAEAQDAPg++hYBH///hUFQVBUFREDQNHmf///////+MYxBUGkAGIMAAAAP/29Xt6lUxBTUUzLjEwMFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV/+MYxDUAAANIAAAAAFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV";
var compareProps = (obj1, obj2) => {
  const keysA = Object.keys(obj1).sort();
  const keysB = Object.keys(obj2).sort();
  if (keysA.length !== keysB.length) {
    return false;
  }
  for (let i = 0;i < keysA.length; i++) {
    if (keysA[i] !== keysB[i]) {
      return false;
    }
    if (obj1[keysA[i]] !== obj2[keysB[i]]) {
      return false;
    }
  }
  return true;
};
var didPropChange = (key, newProp, prevProp) => {
  if (key === "src" && !prevProp.startsWith("data:") && !newProp.startsWith("data:")) {
    return new URL(prevProp, window.origin).toString() !== new URL(newProp, window.origin).toString();
  }
  if (prevProp === newProp) {
    return false;
  }
  return true;
};
var SharedAudioContext = createContext20(null);
var SharedAudioTagsContext = createContext20(null);
var SharedAudioContextProvider = ({ children, audioLatencyHint, audioEnabled }) => {
  const logLevel = useLogLevel();
  const ctxAndGain = useSingletonAudioContext({
    logLevel,
    latencyHint: audioLatencyHint,
    audioEnabled
  });
  const audioContextIsPlayingEventually = useRef11(false);
  const isResuming = useRef11(null);
  const audioSyncAnchor = useMemo22(() => ({ value: 0 }), []);
  const audioSyncAnchorListeners = useRef11([]);
  const audioSyncAnchorEmitter = useMemo22(() => {
    return {
      dispatch: (event) => {
        audioSyncAnchorListeners.current.forEach((l) => l(event));
      },
      subscribe: (listener) => {
        audioSyncAnchorListeners.current.push(listener);
        return {
          remove: () => {
            audioSyncAnchorListeners.current = audioSyncAnchorListeners.current.filter((l) => l !== listener);
          }
        };
      }
    };
  }, []);
  const prevEndTimes = useRef11({ scheduledEndTime: null, mediaEndTime: null });
  const nodesToResume = useRef11(new Map);
  const unscheduleAudioNode = useCallback9((node) => {
    nodesToResume.current.delete(node);
  }, []);
  const scheduleAudioNode = useMemo22(() => {
    return ({
      node,
      mediaTimestamp,
      currentTime,
      scheduledTime,
      duration,
      offset,
      originalUnloopedMediaTimestamp
    }) => {
      if (!ctxAndGain) {
        throw new Error("Audio context not found");
      }
      if (duration > 0) {
        if (ctxAndGain.audioContext.state === "suspended") {
          nodesToResume.current.set(node, {
            scheduledTime,
            offset,
            duration
          });
        } else {
          node.start(scheduledTime, offset, duration);
        }
      }
      const scheduledEndTime = scheduledTime + duration / node.playbackRate.value;
      const mediaTime = mediaTimestamp + offset;
      const mediaEndTime = mediaTime + duration;
      const latency = ctxAndGain.audioContext.baseLatency + ctxAndGain.audioContext.outputLatency;
      const timeDiff = scheduledTime - ctxAndGain.audioContext.currentTime;
      const prev = prevEndTimes.current;
      const scheduledMismatch = prev.scheduledEndTime !== null && Math.abs(scheduledTime - prev.scheduledEndTime) > 0.001;
      const mediaMismatch = prev.mediaEndTime !== null && Math.abs(mediaTime - prev.mediaEndTime) > 0.001;
      Log.verbose({ logLevel, tag: "audio-scheduling" }, "scheduled %c%s%c %s %c%s%c %s %c%s%c %s %s %s", scheduledMismatch ? "color: red; font-weight: bold" : "", scheduledTime.toFixed(4), "", scheduledEndTime.toFixed(4), mediaMismatch ? "color: red; font-weight: bold" : "", mediaTime.toFixed(4), "", mediaEndTime.toFixed(4), duration < 0 ? "color: red; font-weight: bold" : timeDiff < 0 ? "color: red; font-weight: bold" : "color: blue; font-weight: bold", duration < 0 ? "missed " + Math.abs(offset).toFixed(2) + "s" : Math.abs(timeDiff).toFixed(2) + (timeDiff < 0 ? " delay" : " ahead"), "", "current=" + currentTime.toFixed(4), "offset=" + offset.toFixed(4), "latency=" + latency.toFixed(4), "state=" + ctxAndGain.audioContext.state, originalUnloopedMediaTimestamp !== mediaTime ? "original_ts=" + originalUnloopedMediaTimestamp.toFixed(4) : "");
      prev.scheduledEndTime = scheduledEndTime;
      prev.mediaEndTime = mediaEndTime;
      return duration > 0 ? {
        type: "started",
        scheduledTime
      } : {
        type: "not-started",
        reason: "missed " + Math.abs(offset).toFixed(2) + "s"
      };
    };
  }, [ctxAndGain, logLevel]);
  const resume = useCallback9(() => {
    if (!ctxAndGain) {
      return Promise.resolve();
    }
    if (audioContextIsPlayingEventually.current) {
      return Promise.resolve();
    }
    audioContextIsPlayingEventually.current = true;
    const resumePromise = ctxAndGain.audioContext.resume();
    isResuming.current = new Promise((resolve) => {
      waitUntilActuallyResumed(ctxAndGain.audioContext, logLevel).then(resolve);
      resumePromise.catch((err) => {
        Log.warn({ logLevel, tag: "audio" }, "AudioContext resume rejected, continuing without audio sync", err);
        resolve();
      });
    }).finally(() => {
      isResuming.current = null;
    });
    ctxAndGain.gainNode.gain.cancelScheduledValues(ctxAndGain.audioContext.currentTime);
    ctxAndGain.gainNode.gain.setValueAtTime(0, ctxAndGain.audioContext.currentTime);
    ctxAndGain.gainNode.gain.linearRampToValueAtTime(1, ctxAndGain.audioContext.currentTime + 0.03);
    nodesToResume.current.forEach((r, node) => node.start(r.scheduledTime, r.offset, r.duration));
    nodesToResume.current.clear();
    return resumePromise.catch(() => {});
  }, [ctxAndGain, logLevel]);
  const getIsResumingAudioContext = useCallback9(() => {
    return isResuming.current;
  }, []);
  const suspend = useCallback9(() => {
    if (!ctxAndGain) {
      return;
    }
    if (!audioContextIsPlayingEventually.current) {
      return;
    }
    audioContextIsPlayingEventually.current = false;
    ctxAndGain.audioContext.suspend();
  }, [ctxAndGain]);
  const audioContextValue = useMemo22(() => {
    return {
      audioContext: ctxAndGain?.audioContext ?? null,
      gainNode: ctxAndGain?.gainNode ?? null,
      audioSyncAnchor,
      audioSyncAnchorEmitter,
      scheduleAudioNode,
      resume,
      suspend,
      getIsResumingAudioContext,
      unscheduleAudioNode
    };
  }, [
    ctxAndGain,
    audioSyncAnchor,
    audioSyncAnchorEmitter,
    scheduleAudioNode,
    resume,
    suspend,
    getIsResumingAudioContext,
    unscheduleAudioNode
  ]);
  return /* @__PURE__ */ jsx20(SharedAudioContext.Provider, {
    value: audioContextValue,
    children
  });
};
var SharedAudioTagsContextProvider = ({ children, numberOfAudioTags }) => {
  const audios = useRef11([]);
  const [initialNumberOfAudioTags] = useState12(numberOfAudioTags);
  if (numberOfAudioTags !== initialNumberOfAudioTags) {
    throw new Error("The number of shared audio tags has changed dynamically. Once you have set this property, you cannot change it afterwards.");
  }
  const logLevel = useLogLevel();
  const mountTime = useMountTime();
  const env = useRemotionEnvironment();
  const audioCtx = useContext20(SharedAudioContext);
  const audioContext = audioCtx?.audioContext ?? null;
  const resume = audioCtx?.resume;
  const refs = useMemo22(() => {
    return new Array(numberOfAudioTags).fill(true).map(() => {
      const ref = createRef2();
      return {
        id: Math.random(),
        ref,
        mediaElementSourceNode: audioContext ? makeSharedElementSourceNode({
          audioContext,
          ref
        }) : null
      };
    });
  }, [audioContext, numberOfAudioTags]);
  const effectToUse = React20.useInsertionEffect ?? React20.useLayoutEffect;
  effectToUse(() => {
    return () => {
      requestAnimationFrame(() => {
        refs.forEach(({ mediaElementSourceNode }) => {
          mediaElementSourceNode?.cleanup();
        });
      });
    };
  }, [refs]);
  const takenAudios = useRef11(new Array(numberOfAudioTags).fill(false));
  const rerenderAudios = useCallback9(() => {
    refs.forEach(({ ref, id }) => {
      const data = audios.current?.find((a) => a.id === id);
      const { current } = ref;
      if (!current) {
        return;
      }
      if (data === undefined) {
        current.src = EMPTY_AUDIO;
        return;
      }
      if (!data) {
        throw new TypeError("Expected audio data to be there");
      }
      Object.keys(data.props).forEach((key) => {
        if (didPropChange(key, data.props[key], current[key])) {
          current[key] = data.props[key];
        }
      });
    });
  }, [refs]);
  const registerAudio = useCallback9((options) => {
    const { aud, audioId, premounting, postmounting } = options;
    const found = audios.current?.find((a) => a.audioId === audioId);
    if (found) {
      return found;
    }
    const firstFreeAudio = takenAudios.current.findIndex((a) => a === false);
    if (firstFreeAudio === -1) {
      throw new Error(`Tried to simultaneously mount ${numberOfAudioTags + 1} <Html5Audio /> tags at the same time. With the current settings, the maximum amount of <Html5Audio /> tags is limited to ${numberOfAudioTags} at the same time. Remotion pre-mounts silent audio tags to help avoid browser autoplay restrictions. See https://remotion.dev/docs/player/autoplay#using-the-numberofsharedaudiotags-prop for more information on how to increase this limit.`);
    }
    const { id, ref, mediaElementSourceNode } = refs[firstFreeAudio];
    const cloned = [...takenAudios.current];
    cloned[firstFreeAudio] = id;
    takenAudios.current = cloned;
    const newElem = {
      props: aud,
      id,
      el: ref,
      audioId,
      mediaElementSourceNode,
      premounting,
      audioMounted: Boolean(ref.current),
      postmounting,
      cleanupOnMediaTagUnmount: () => {}
    };
    audios.current?.push(newElem);
    rerenderAudios();
    return newElem;
  }, [numberOfAudioTags, refs, rerenderAudios]);
  const unregisterAudio = useCallback9((id) => {
    const cloned = [...takenAudios.current];
    const index = refs.findIndex((r) => r.id === id);
    if (index === -1) {
      throw new TypeError("Error occured in ");
    }
    cloned[index] = false;
    takenAudios.current = cloned;
    audios.current = audios.current?.filter((a) => a.id !== id);
    rerenderAudios();
  }, [refs, rerenderAudios]);
  const updateAudio = useCallback9(({
    aud,
    audioId,
    id,
    premounting,
    postmounting
  }) => {
    let changed = false;
    audios.current = audios.current?.map((prevA) => {
      const audioMounted = Boolean(prevA.el.current);
      if (prevA.audioMounted !== audioMounted) {
        changed = true;
      }
      if (prevA.id === id) {
        const isTheSame = compareProps(aud, prevA.props) && prevA.premounting === premounting && prevA.postmounting === postmounting;
        if (isTheSame) {
          return prevA;
        }
        changed = true;
        return {
          ...prevA,
          props: aud,
          premounting,
          postmounting,
          audioId,
          audioMounted
        };
      }
      return prevA;
    });
    if (changed) {
      rerenderAudios();
    }
  }, [rerenderAudios]);
  const playAllAudios = useCallback9(() => {
    refs.forEach((ref) => {
      const audio = audios.current.find((a) => a.el === ref.ref);
      if (audio?.premounting) {
        return;
      }
      playAndHandleNotAllowedError({
        mediaRef: ref.ref,
        mediaType: "audio",
        onAutoPlayError: null,
        logLevel,
        mountTime,
        reason: "playing all audios",
        isPlayer: env.isPlayer
      });
    });
    resume?.();
  }, [logLevel, mountTime, refs, env.isPlayer, resume]);
  const audioTagsValue = useMemo22(() => {
    return {
      registerAudio,
      unregisterAudio,
      updateAudio,
      playAllAudios,
      numberOfAudioTags
    };
  }, [
    numberOfAudioTags,
    playAllAudios,
    registerAudio,
    unregisterAudio,
    updateAudio
  ]);
  return /* @__PURE__ */ jsxs2(SharedAudioTagsContext.Provider, {
    value: audioTagsValue,
    children: [
      refs.map(({ id, ref }) => {
        return /* @__PURE__ */ jsx20("audio", {
          ref,
          preload: "metadata",
          src: EMPTY_AUDIO
        }, id);
      }),
      children
    ]
  });
};
var useSharedAudio = ({
  aud,
  audioId,
  premounting,
  postmounting
}) => {
  const audioCtx = useContext20(SharedAudioContext);
  const tagsCtx = useContext20(SharedAudioTagsContext);
  const [elem] = useState12(() => {
    if (tagsCtx && tagsCtx.numberOfAudioTags > 0) {
      return tagsCtx.registerAudio({ aud, audioId, premounting, postmounting });
    }
    const el = React20.createRef();
    const mediaElementSourceNode = audioCtx?.audioContext ? makeSharedElementSourceNode({
      audioContext: audioCtx.audioContext,
      ref: el
    }) : null;
    return {
      el,
      id: Math.random(),
      props: aud,
      audioId,
      mediaElementSourceNode,
      premounting,
      audioMounted: Boolean(el.current),
      postmounting,
      cleanupOnMediaTagUnmount: () => {
        mediaElementSourceNode?.cleanup();
      }
    };
  });
  const effectToUse = React20.useInsertionEffect ?? React20.useLayoutEffect;
  if (typeof document !== "undefined") {
    effectToUse(() => {
      if (tagsCtx && tagsCtx.numberOfAudioTags > 0) {
        tagsCtx.updateAudio({
          id: elem.id,
          aud,
          audioId,
          premounting,
          postmounting
        });
      }
    }, [aud, tagsCtx, elem.id, audioId, premounting, postmounting]);
    effectToUse(() => {
      return () => {
        if (tagsCtx && tagsCtx.numberOfAudioTags > 0) {
          tagsCtx.unregisterAudio(elem.id);
        }
      };
    }, [tagsCtx, elem.id]);
  }
  return elem;
};

// src/is-approximately-the-same.ts
var FLOATING_POINT_ERROR_THRESHOLD = 0.00001;
var isApproximatelyTheSame = (num1, num2) => {
  return Math.abs(num1 - num2) < FLOATING_POINT_ERROR_THRESHOLD;
};

// src/video/video-fragment.ts
import { useRef as useRef12 } from "react";
var toSeconds = (time, fps) => {
  return Math.round(time / fps * 100) / 100;
};
var isSafari = () => {
  if (typeof window === "undefined") {
    return false;
  }
  const isAppleWebKit = /AppleWebKit/.test(window.navigator.userAgent);
  if (!isAppleWebKit) {
    return false;
  }
  const isNotChrome = !window.navigator.userAgent.includes("Chrome/");
  return isNotChrome;
};
var isIosSafari = () => {
  if (typeof window === "undefined") {
    return false;
  }
  const isIpadIPodIPhone = /iP(ad|od|hone)/i.test(window.navigator.userAgent);
  return isIpadIPodIPhone && isSafari();
};
var isIOSSafariAndBlob = (actualSrc) => {
  return isIosSafari() && actualSrc.startsWith("blob:");
};
var getVideoFragmentStart = ({
  actualFrom,
  fps
}) => {
  return toSeconds(Math.max(0, -actualFrom), fps);
};
var getVideoFragmentEnd = ({
  duration,
  fps
}) => {
  return toSeconds(duration, fps);
};
var appendVideoFragment = ({
  actualSrc,
  actualFrom,
  duration,
  fps
}) => {
  if (isIOSSafariAndBlob(actualSrc)) {
    return actualSrc;
  }
  if (actualSrc.startsWith("data:")) {
    return actualSrc;
  }
  const existingHash = Boolean(new URL(actualSrc, (typeof window === "undefined" ? null : window.location.href) ?? "http://localhost:3000").hash);
  if (existingHash) {
    return actualSrc;
  }
  if (!Number.isFinite(actualFrom)) {
    return actualSrc;
  }
  const withStartHash = `${actualSrc}#t=${getVideoFragmentStart({ actualFrom, fps })}`;
  if (!Number.isFinite(duration)) {
    return withStartHash;
  }
  return `${withStartHash},${getVideoFragmentEnd({ duration, fps })}`;
};
var isSubsetOfDuration = ({
  prevStartFrom,
  newStartFrom,
  prevDuration,
  newDuration,
  fps
}) => {
  const previousFrom = getVideoFragmentStart({ actualFrom: prevStartFrom, fps });
  const newFrom = getVideoFragmentStart({ actualFrom: newStartFrom, fps });
  const previousEnd = getVideoFragmentEnd({ duration: prevDuration, fps });
  const newEnd = getVideoFragmentEnd({ duration: newDuration, fps });
  if (newFrom < previousFrom) {
    return false;
  }
  if (newEnd > previousEnd) {
    return false;
  }
  return true;
};
var useAppendVideoFragment = ({
  actualSrc: initialActualSrc,
  actualFrom: initialActualFrom,
  duration: initialDuration,
  fps
}) => {
  const actualFromRef = useRef12(initialActualFrom);
  const actualDuration = useRef12(initialDuration);
  const actualSrc = useRef12(initialActualSrc);
  if (!isSubsetOfDuration({
    prevStartFrom: actualFromRef.current,
    newStartFrom: initialActualFrom,
    prevDuration: actualDuration.current,
    newDuration: initialDuration,
    fps
  }) || initialActualSrc !== actualSrc.current) {
    actualFromRef.current = initialActualFrom;
    actualDuration.current = initialDuration;
    actualSrc.current = initialActualSrc;
  }
  const appended = appendVideoFragment({
    actualSrc: actualSrc.current,
    actualFrom: actualFromRef.current,
    duration: actualDuration.current,
    fps
  });
  return appended;
};

// src/use-amplification.ts
var warned2 = false;
var warnSafariOnce = (logLevel) => {
  if (warned2) {
    return;
  }
  warned2 = true;
  Log.warn({ logLevel, tag: null }, "In Safari, setting a volume and a playback rate at the same time is buggy.");
  Log.warn({ logLevel, tag: null }, "In Desktop Safari, only volumes <= 1 will be applied.");
  Log.warn({ logLevel, tag: null }, logLevel, "In Mobile Safari, the volume will be ignored and set to 1 if a playbackRate is set.");
};
var useVolume = ({
  mediaRef,
  volume,
  logLevel,
  source,
  shouldUseWebAudioApi
}) => {
  const audioStuffRef = useRef13(null);
  const currentVolumeRef = useRef13(volume);
  currentVolumeRef.current = volume;
  const sharedAudioContext = useContext21(SharedAudioContext);
  if (!sharedAudioContext) {
    throw new Error("useAmplification must be used within a SharedAudioContext");
  }
  const { audioContext, gainNode: masterGainNode } = sharedAudioContext;
  if (typeof window !== "undefined") {
    useLayoutEffect6(() => {
      if (!audioContext) {
        return;
      }
      if (!mediaRef.current) {
        return;
      }
      if (!shouldUseWebAudioApi) {
        return;
      }
      if (mediaRef.current.playbackRate !== 1 && isSafari()) {
        warnSafariOnce(logLevel);
        return;
      }
      if (!source) {
        return;
      }
      if (!masterGainNode) {
        return;
      }
      const gainNode = new GainNode(audioContext, {
        gain: currentVolumeRef.current
      });
      source.attemptToConnect();
      source.get().connect(gainNode);
      gainNode.connect(masterGainNode);
      audioStuffRef.current = {
        gainNode
      };
      Log.trace({ logLevel, tag: null }, `Starting to amplify ${mediaRef.current?.src}. Gain = ${currentVolumeRef.current}, playbackRate = ${mediaRef.current?.playbackRate}`);
      return () => {
        audioStuffRef.current = null;
        gainNode.disconnect();
        source.get().disconnect();
      };
    }, [
      logLevel,
      mediaRef,
      audioContext,
      source,
      shouldUseWebAudioApi,
      masterGainNode
    ]);
  }
  if (audioStuffRef.current) {
    const valueToSet = volume;
    if (!isApproximatelyTheSame(audioStuffRef.current.gainNode.gain.value, valueToSet)) {
      audioStuffRef.current.gainNode.gain.value = valueToSet;
      Log.trace({ logLevel, tag: null }, `Setting gain to ${valueToSet} for ${mediaRef.current?.src}`);
    }
  }
  const safariCase = isSafari() && mediaRef.current && mediaRef.current?.playbackRate !== 1;
  const shouldUseTraditionalVolume = safariCase || !shouldUseWebAudioApi;
  if (shouldUseTraditionalVolume && mediaRef.current && !isApproximatelyTheSame(volume, mediaRef.current?.volume)) {
    mediaRef.current.volume = Math.min(volume, 1);
  }
  return audioStuffRef;
};

// src/use-media-in-timeline.ts
import { useContext as useContext23, useEffect as useEffect8, useMemo as useMemo23, useState as useState13 } from "react";

// src/audio/use-audio-frame.ts
import { useContext as useContext22 } from "react";
var useMediaStartsAt = () => {
  const parentSequence = useContext22(SequenceContext);
  const startsAt = Math.min(0, parentSequence?.relativeFrom ?? 0);
  return startsAt;
};
var useFrameForVolumeProp = (behavior) => {
  const loop = Loop.useLoop();
  const frame = useCurrentFrame();
  const startsAt = useMediaStartsAt();
  if (behavior === "repeat" || loop === null) {
    return frame + startsAt;
  }
  return frame + startsAt + loop.durationInFrames * loop.iteration;
};

// src/get-asset-file-name.ts
var getAssetDisplayName = (filename) => {
  if (/data:|blob:/.test(filename.substring(0, 5))) {
    return "Data URL";
  }
  const splitted = filename.split("/").map((s) => s.split("\\")).flat(1);
  return splitted[splitted.length - 1];
};

// src/get-timeline-duration.ts
var getTimelineDuration = ({
  compositionDurationInFrames,
  playbackRate,
  trimBefore,
  trimAfter,
  parentSequenceDurationInFrames,
  loop
}) => {
  if (loop) {
    return compositionDurationInFrames;
  }
  const mediaDuration = calculateMediaDuration({
    mediaDurationInFrames: compositionDurationInFrames * playbackRate + (trimBefore ?? 0),
    playbackRate,
    trimBefore,
    trimAfter
  });
  if (parentSequenceDurationInFrames !== null) {
    return Math.floor(Math.min(parentSequenceDurationInFrames * playbackRate, mediaDuration));
  }
  return mediaDuration;
};

// src/volume-prop.ts
var evaluateVolume = ({
  frame,
  volume,
  mediaVolume = 1
}) => {
  if (typeof volume === "number") {
    return volume * mediaVolume;
  }
  if (typeof volume === "undefined") {
    return Number(mediaVolume);
  }
  const evaluated = volume(frame) * mediaVolume;
  if (typeof evaluated !== "number") {
    throw new TypeError(`You passed in a a function to the volume prop but it did not return a number but a value of type ${typeof evaluated} for frame ${frame}`);
  }
  if (Number.isNaN(evaluated)) {
    throw new TypeError(`You passed in a function to the volume prop but it returned NaN for frame ${frame}.`);
  }
  if (!Number.isFinite(evaluated)) {
    throw new TypeError(`You passed in a function to the volume prop but it returned a non-finite number for frame ${frame}.`);
  }
  return Math.max(0, evaluated);
};

// src/use-media-in-timeline.ts
var didWarn = {};
var warnOnce2 = (message) => {
  if (didWarn[message]) {
    return;
  }
  console.warn(message);
  didWarn[message] = true;
};
var useBasicMediaInTimeline = ({
  volume,
  mediaVolume,
  mediaType,
  src,
  displayName,
  trimBefore,
  trimAfter,
  playbackRate,
  sequenceDurationInFrames,
  mediaStartsAt,
  loop
}) => {
  if (!src) {
    throw new Error("No src passed");
  }
  const parentSequence = useContext23(SequenceContext);
  const [initialVolume] = useState13(() => volume);
  const duration = getTimelineDuration({
    compositionDurationInFrames: sequenceDurationInFrames,
    playbackRate,
    trimBefore,
    trimAfter,
    parentSequenceDurationInFrames: parentSequence?.durationInFrames ?? null,
    loop
  });
  const volumes = useMemo23(() => {
    if (typeof volume === "number") {
      return volume;
    }
    return new Array(Math.floor(Math.max(0, duration + mediaStartsAt))).fill(true).map((_, i) => {
      return evaluateVolume({
        frame: i + mediaStartsAt,
        volume,
        mediaVolume
      });
    }).join(",");
  }, [duration, mediaStartsAt, volume, mediaVolume]);
  useEffect8(() => {
    if (typeof volume === "number" && volume !== initialVolume) {
      warnOnce2(`Remotion: The ${mediaType} with src ${src} has changed it's volume. Prefer the callback syntax for setting volume to get better timeline display: https://www.remotion.dev/docs/audio/volume`);
    }
  }, [initialVolume, mediaType, src, volume]);
  const doesVolumeChange = typeof volume === "function";
  const nonce = useNonce();
  const { rootId } = useTimelineContext();
  const startMediaFrom = 0 - mediaStartsAt + (trimBefore ?? 0);
  const memoizedResult = useMemo23(() => {
    return {
      volumes,
      duration,
      doesVolumeChange,
      nonce,
      rootId,
      finalDisplayName: displayName ?? getAssetDisplayName(src),
      startMediaFrom,
      src,
      playbackRate
    };
  }, [
    volumes,
    duration,
    doesVolumeChange,
    nonce,
    rootId,
    displayName,
    src,
    startMediaFrom,
    playbackRate
  ]);
  return memoizedResult;
};
var useImageInTimeline = ({
  src,
  displayName,
  id,
  stack,
  showInTimeline,
  premountDisplay,
  postmountDisplay,
  loopDisplay,
  controls
}) => {
  const parentSequence = useContext23(SequenceContext);
  const { registerSequence, unregisterSequence } = useContext23(SequenceManager);
  const { durationInFrames } = useVideoConfig();
  const mediaStartsAt = useMediaStartsAt();
  const { duration, nonce, rootId, finalDisplayName } = useBasicMediaInTimeline({
    volume: undefined,
    mediaVolume: 0,
    mediaType: "image",
    src,
    displayName,
    trimAfter: undefined,
    trimBefore: undefined,
    playbackRate: 1,
    sequenceDurationInFrames: durationInFrames,
    mediaStartsAt,
    loop: false
  });
  const { isStudio } = useRemotionEnvironment();
  useEffect8(() => {
    if (!src) {
      throw new Error("No src passed");
    }
    if (!isStudio && window.process?.env?.NODE_ENV !== "test") {
      return;
    }
    if (!showInTimeline) {
      return;
    }
    registerSequence({
      type: "image",
      src,
      id,
      duration,
      from: 0,
      parent: parentSequence?.id ?? null,
      displayName: finalDisplayName,
      rootId,
      showInTimeline: true,
      nonce: nonce.get(),
      loopDisplay,
      stack,
      premountDisplay,
      postmountDisplay,
      controls,
      effects: []
    });
    return () => {
      unregisterSequence(id);
    };
  }, [
    duration,
    id,
    parentSequence,
    src,
    registerSequence,
    unregisterSequence,
    nonce,
    stack,
    showInTimeline,
    premountDisplay,
    postmountDisplay,
    isStudio,
    loopDisplay,
    rootId,
    finalDisplayName,
    controls
  ]);
};
var useMediaInTimeline = ({
  volume,
  mediaVolume,
  src,
  mediaType,
  playbackRate,
  displayName,
  id,
  stack,
  showInTimeline,
  premountDisplay,
  postmountDisplay,
  loopDisplay
}) => {
  const parentSequence = useContext23(SequenceContext);
  const startsAt = useMediaStartsAt();
  const { registerSequence, unregisterSequence } = useContext23(SequenceManager);
  const { durationInFrames } = useVideoConfig();
  const mediaStartsAt = useMediaStartsAt();
  const { volumes, duration, doesVolumeChange, nonce, rootId, finalDisplayName } = useBasicMediaInTimeline({
    volume,
    mediaVolume,
    mediaType,
    src,
    displayName,
    trimAfter: undefined,
    trimBefore: undefined,
    playbackRate,
    sequenceDurationInFrames: durationInFrames,
    mediaStartsAt,
    loop: false
  });
  const { isStudio } = useRemotionEnvironment();
  useEffect8(() => {
    if (!src) {
      throw new Error("No src passed");
    }
    if (!isStudio && window.process?.env?.NODE_ENV !== "test") {
      return;
    }
    if (!showInTimeline) {
      return;
    }
    registerSequence({
      type: mediaType,
      src,
      id,
      duration,
      from: 0,
      parent: parentSequence?.id ?? null,
      displayName: finalDisplayName,
      rootId,
      volume: volumes,
      showInTimeline: true,
      nonce: nonce.get(),
      startMediaFrom: 0 - startsAt,
      doesVolumeChange,
      loopDisplay,
      playbackRate,
      stack,
      premountDisplay,
      postmountDisplay,
      controls: null,
      effects: []
    });
    return () => {
      unregisterSequence(id);
    };
  }, [
    duration,
    id,
    parentSequence,
    src,
    registerSequence,
    unregisterSequence,
    volumes,
    doesVolumeChange,
    nonce,
    mediaType,
    startsAt,
    playbackRate,
    stack,
    showInTimeline,
    premountDisplay,
    postmountDisplay,
    loopDisplay,
    rootId,
    finalDisplayName,
    isStudio
  ]);
};

// src/use-media-playback.ts
import {
  useCallback as useCallback12,
  useContext as useContext26,
  useEffect as useEffect12,
  useLayoutEffect as useLayoutEffect8,
  useRef as useRef17
} from "react";

// src/buffer-until-first-frame.ts
import { useCallback as useCallback11, useMemo as useMemo26, useRef as useRef15 } from "react";

// src/use-buffer-state.ts
import { useContext as useContext25, useMemo as useMemo25 } from "react";

// src/buffering.tsx
import React21, {
  useCallback as useCallback10,
  useContext as useContext24,
  useEffect as useEffect9,
  useLayoutEffect as useLayoutEffect7,
  useMemo as useMemo24,
  useRef as useRef14,
  useState as useState14
} from "react";
import { jsx as jsx21 } from "react/jsx-runtime";
var useBufferManager = (logLevel, mountTime) => {
  const [blocks, setBlocks] = useState14([]);
  const [onBufferingCallbacks, setOnBufferingCallbacks] = useState14([]);
  const [onResumeCallbacks, setOnResumeCallbacks] = useState14([]);
  const env = useRemotionEnvironment();
  const rendering = env.isRendering;
  const buffering = useRef14(false);
  const addBlock = useCallback10((block) => {
    if (rendering) {
      return {
        unblock: () => {
          return;
        }
      };
    }
    setBlocks((b) => [...b, block]);
    return {
      unblock: () => {
        setBlocks((b) => {
          const newArr = b.filter((bx) => bx !== block);
          if (newArr.length === b.length) {
            return b;
          }
          return newArr;
        });
      }
    };
  }, [rendering]);
  const listenForBuffering = useCallback10((callback) => {
    setOnBufferingCallbacks((c) => [...c, callback]);
    return {
      remove: () => {
        setOnBufferingCallbacks((c) => c.filter((cb) => cb !== callback));
      }
    };
  }, []);
  const listenForResume = useCallback10((callback) => {
    setOnResumeCallbacks((c) => [...c, callback]);
    return {
      remove: () => {
        setOnResumeCallbacks((c) => c.filter((cb) => cb !== callback));
      }
    };
  }, []);
  useEffect9(() => {
    if (rendering) {
      return;
    }
    if (blocks.length > 0) {
      onBufferingCallbacks.forEach((c) => c());
      playbackLogging({
        logLevel,
        message: "Player is entering buffer state",
        mountTime,
        tag: "player"
      });
    }
  }, [blocks]);
  if (typeof window !== "undefined") {
    useLayoutEffect7(() => {
      if (rendering) {
        return;
      }
      if (blocks.length === 0) {
        onResumeCallbacks.forEach((c) => c());
        playbackLogging({
          logLevel,
          message: "Player is exiting buffer state",
          mountTime,
          tag: "player"
        });
      }
    }, [blocks]);
  }
  return useMemo24(() => {
    return { addBlock, listenForBuffering, listenForResume, buffering };
  }, [addBlock, buffering, listenForBuffering, listenForResume]);
};
var BufferingContextReact = React21.createContext(null);
var BufferingProvider = ({ children }) => {
  const { logLevel, mountTime } = useContext24(LogLevelContext);
  const bufferManager = useBufferManager(logLevel ?? "info", mountTime);
  return /* @__PURE__ */ jsx21(BufferingContextReact.Provider, {
    value: bufferManager,
    children
  });
};
var useIsPlayerBuffering = (bufferManager) => {
  const [isBuffering, setIsBuffering] = useState14(bufferManager.buffering.current);
  useEffect9(() => {
    const onBuffer = () => {
      setIsBuffering(true);
    };
    const onResume = () => {
      setIsBuffering(false);
    };
    bufferManager.listenForBuffering(onBuffer);
    bufferManager.listenForResume(onResume);
    return () => {
      bufferManager.listenForBuffering(() => {
        return;
      });
      bufferManager.listenForResume(() => {
        return;
      });
    };
  }, [bufferManager]);
  return isBuffering;
};

// src/use-buffer-state.ts
var useBufferState = () => {
  const buffer = useContext25(BufferingContextReact);
  const addBlock = buffer ? buffer.addBlock : null;
  return useMemo25(() => ({
    delayPlayback: () => {
      if (!addBlock) {
        throw new Error("Tried to enable the buffering state, but a Remotion context was not found. This API can only be called in a component that was passed to the Remotion Player or a <Composition>. Or you might have experienced a version mismatch - run `npx remotion versions` and ensure all packages have the same version. This error is thrown by the buffer state https://remotion.dev/docs/player/buffer-state");
      }
      const { unblock } = addBlock({
        id: String(Math.random())
      });
      return { unblock };
    }
  }), [addBlock]);
};

// src/buffer-until-first-frame.ts
var isSafariWebkit = () => {
  const isSafari2 = /^((?!chrome|android).)*safari/i.test(window.navigator.userAgent);
  return isSafari2;
};
var useBufferUntilFirstFrame = ({
  mediaRef,
  mediaType,
  onVariableFpsVideoDetected,
  pauseWhenBuffering,
  logLevel,
  mountTime
}) => {
  const bufferingRef = useRef15(false);
  const { delayPlayback } = useBufferState();
  const bufferUntilFirstFrame = useCallback11((requestedTime) => {
    if (mediaType !== "video") {
      return;
    }
    if (!pauseWhenBuffering) {
      return;
    }
    const current = mediaRef.current;
    if (!current) {
      return;
    }
    if (current.readyState >= current.HAVE_FUTURE_DATA && !isSafariWebkit()) {
      playbackLogging({
        logLevel,
        message: `Not using buffer until first frame, because readyState is ${current.readyState} and is not Safari or Desktop Chrome`,
        mountTime,
        tag: "buffer"
      });
      return;
    }
    if (!current.requestVideoFrameCallback) {
      playbackLogging({
        logLevel,
        message: `Not using buffer until first frame, because requestVideoFrameCallback is not supported`,
        mountTime,
        tag: "buffer"
      });
      return;
    }
    bufferingRef.current = true;
    playbackLogging({
      logLevel,
      message: `Buffering ${mediaRef.current?.src} until the first frame is received`,
      mountTime,
      tag: "buffer"
    });
    const playback = delayPlayback();
    const unblock = () => {
      playback.unblock();
      current.removeEventListener("ended", unblock, {
        once: true
      });
      current.removeEventListener("pause", unblock, {
        once: true
      });
      bufferingRef.current = false;
    };
    const onEndedOrPauseOrCanPlay = () => {
      unblock();
    };
    current.requestVideoFrameCallback((_, info2) => {
      const differenceFromRequested = Math.abs(info2.mediaTime - requestedTime);
      if (differenceFromRequested > 0.5) {
        onVariableFpsVideoDetected();
      }
      unblock();
    });
    current.addEventListener("ended", onEndedOrPauseOrCanPlay, { once: true });
    current.addEventListener("pause", onEndedOrPauseOrCanPlay, { once: true });
    current.addEventListener("canplay", onEndedOrPauseOrCanPlay, {
      once: true
    });
  }, [
    delayPlayback,
    logLevel,
    mediaRef,
    mediaType,
    mountTime,
    onVariableFpsVideoDetected,
    pauseWhenBuffering
  ]);
  return useMemo26(() => {
    return {
      isBuffering: () => bufferingRef.current,
      bufferUntilFirstFrame
    };
  }, [bufferUntilFirstFrame]);
};

// src/media-tag-current-time-timestamp.ts
import React22 from "react";
var useCurrentTimeOfMediaTagWithUpdateTimeStamp = (mediaRef) => {
  const lastUpdate = React22.useRef({
    time: mediaRef.current?.currentTime ?? 0,
    lastUpdate: performance.now()
  });
  const nowCurrentTime = mediaRef.current?.currentTime ?? null;
  if (nowCurrentTime !== null) {
    if (lastUpdate.current.time !== nowCurrentTime) {
      lastUpdate.current.time = nowCurrentTime;
      lastUpdate.current.lastUpdate = performance.now();
    }
  }
  return lastUpdate;
};

// src/seek.ts
var seek = ({
  mediaRef,
  time,
  logLevel,
  why,
  mountTime
}) => {
  const timeToSet = isIosSafari() ? Number(time.toFixed(1)) : time;
  playbackLogging({
    logLevel,
    tag: "seek",
    message: `Seeking from ${mediaRef.currentTime} to ${timeToSet}. src= ${mediaRef.src} Reason: ${why}`,
    mountTime
  });
  mediaRef.currentTime = timeToSet;
  return timeToSet;
};

// src/use-media-buffering.ts
import { useEffect as useEffect10, useState as useState15 } from "react";
var useMediaBuffering = ({
  element,
  shouldBuffer,
  isPremounting,
  isPostmounting,
  logLevel,
  mountTime,
  src
}) => {
  const buffer = useBufferState();
  const [isBuffering, setIsBuffering] = useState15(false);
  useEffect10(() => {
    let cleanupFns = [];
    const { current } = element;
    if (!current) {
      return;
    }
    if (!shouldBuffer) {
      return;
    }
    if (isPremounting || isPostmounting) {
      if ((isPremounting || isPostmounting) && current.readyState < current.HAVE_FUTURE_DATA) {
        if (!navigator.userAgent.includes("Firefox/")) {
          playbackLogging({
            logLevel,
            message: `Calling .load() on ${current.src} because readyState is ${current.readyState} and it is not Firefox. Element is premounted ${current.playbackRate}`,
            tag: "load",
            mountTime
          });
          const previousPlaybackRate = current.playbackRate;
          current.load();
          current.playbackRate = previousPlaybackRate;
        }
      }
      return;
    }
    const cleanup = (reason) => {
      let didDoSomething = false;
      cleanupFns.forEach((fn) => {
        fn(reason);
        didDoSomething = true;
      });
      cleanupFns = [];
      setIsBuffering((previous) => {
        if (previous) {
          didDoSomething = true;
        }
        return false;
      });
      if (didDoSomething) {
        playbackLogging({
          logLevel,
          message: `Unmarking as buffering: ${current.src}. Reason: ${reason}`,
          tag: "buffer",
          mountTime
        });
      }
    };
    const blockMedia = (reason) => {
      setIsBuffering(true);
      playbackLogging({
        logLevel,
        message: `Marking as buffering: ${current.src}. Reason: ${reason}`,
        tag: "buffer",
        mountTime
      });
      const { unblock } = buffer.delayPlayback();
      const onCanPlay = () => {
        cleanup('"canplay" was fired');
        init();
      };
      const onError = () => {
        cleanup('"error" event was occurred');
        init();
      };
      current.addEventListener("canplay", onCanPlay, {
        once: true
      });
      cleanupFns.push(() => {
        current.removeEventListener("canplay", onCanPlay);
      });
      current.addEventListener("error", onError, {
        once: true
      });
      cleanupFns.push(() => {
        current.removeEventListener("error", onError);
      });
      cleanupFns.push((cleanupReason) => {
        playbackLogging({
          logLevel,
          message: `Unblocking ${current.src} from buffer. Reason: ${cleanupReason}`,
          tag: "buffer",
          mountTime
        });
        unblock();
      });
    };
    const init = () => {
      if (current.readyState < current.HAVE_FUTURE_DATA) {
        blockMedia(`readyState is ${current.readyState}, which is less than HAVE_FUTURE_DATA`);
        if (!navigator.userAgent.includes("Firefox/")) {
          playbackLogging({
            logLevel,
            message: `Calling .load() on ${src} because readyState is ${current.readyState} and it is not Firefox. ${current.playbackRate}`,
            tag: "load",
            mountTime
          });
          const previousPlaybackRate = current.playbackRate;
          current.load();
          current.playbackRate = previousPlaybackRate;
        }
      } else {
        const onWaiting = () => {
          blockMedia('"waiting" event was fired');
        };
        current.addEventListener("waiting", onWaiting);
        cleanupFns.push(() => {
          current.removeEventListener("waiting", onWaiting);
        });
      }
    };
    init();
    return () => {
      cleanup("element was unmounted or prop changed");
    };
  }, [
    buffer,
    src,
    element,
    isPremounting,
    isPostmounting,
    logLevel,
    shouldBuffer,
    mountTime
  ]);
  return isBuffering;
};

// src/use-request-video-callback-time.ts
import { useEffect as useEffect11, useRef as useRef16 } from "react";
var useRequestVideoCallbackTime = ({
  mediaRef,
  mediaType,
  lastSeek,
  onVariableFpsVideoDetected
}) => {
  const currentTime = useRef16(null);
  useEffect11(() => {
    const { current } = mediaRef;
    if (current) {
      currentTime.current = {
        time: current.currentTime,
        lastUpdate: performance.now()
      };
    } else {
      currentTime.current = null;
      return;
    }
    if (mediaType !== "video") {
      currentTime.current = null;
      return;
    }
    const videoTag = current;
    if (!videoTag.requestVideoFrameCallback) {
      return;
    }
    let cancel = () => {
      return;
    };
    const request = () => {
      if (!videoTag) {
        return;
      }
      const cb = videoTag.requestVideoFrameCallback((_, info2) => {
        if (currentTime.current !== null) {
          const difference = Math.abs(currentTime.current.time - info2.mediaTime);
          const differenceToLastSeek = Math.abs(lastSeek.current === null ? Infinity : info2.mediaTime - lastSeek.current);
          if (difference > 0.5 && differenceToLastSeek > 0.5 && info2.mediaTime > currentTime.current.time) {
            onVariableFpsVideoDetected();
          }
        }
        currentTime.current = {
          time: info2.mediaTime,
          lastUpdate: performance.now()
        };
        request();
      });
      cancel = () => {
        videoTag.cancelVideoFrameCallback(cb);
        cancel = () => {
          return;
        };
      };
    };
    request();
    return () => {
      cancel();
    };
  }, [lastSeek, mediaRef, mediaType, onVariableFpsVideoDetected]);
  return currentTime;
};

// src/interpolate.ts
function interpolateFunction(input, inputRange, outputRange, options) {
  const { extrapolateLeft, extrapolateRight, easing } = options;
  let result = input;
  const [inputMin, inputMax] = inputRange;
  const [outputMin, outputMax] = outputRange;
  if (result < inputMin) {
    if (extrapolateLeft === "identity") {
      return result;
    }
    if (extrapolateLeft === "clamp") {
      result = inputMin;
    } else if (extrapolateLeft === "wrap") {
      const range = inputMax - inputMin;
      result = ((result - inputMin) % range + range) % range + inputMin;
    } else if (extrapolateLeft === "extend") {}
  }
  if (result > inputMax) {
    if (extrapolateRight === "identity") {
      return result;
    }
    if (extrapolateRight === "clamp") {
      result = inputMax;
    } else if (extrapolateRight === "wrap") {
      const range = inputMax - inputMin;
      result = ((result - inputMin) % range + range) % range + inputMin;
    } else if (extrapolateRight === "extend") {}
  }
  if (outputMin === outputMax) {
    return outputMin;
  }
  result = (result - inputMin) / (inputMax - inputMin);
  result = easing(result);
  result = result * (outputMax - outputMin) + outputMin;
  return result;
}
function findRange(input, inputRange) {
  let i;
  for (i = 1;i < inputRange.length - 1; ++i) {
    if (inputRange[i] >= input) {
      break;
    }
  }
  return i - 1;
}
function checkValidInputRange(arr) {
  for (let i = 1;i < arr.length; ++i) {
    if (!(arr[i] > arr[i - 1])) {
      throw new Error(`inputRange must be strictly monotonically increasing but got [${arr.join(",")}]`);
    }
  }
}
function checkInfiniteRange(name, arr) {
  if (arr.length < 2) {
    throw new Error(name + " must have at least 2 elements");
  }
  for (const element of arr) {
    if (typeof element !== "number") {
      throw new Error(`${name} must contain only numbers`);
    }
    if (!Number.isFinite(element)) {
      throw new Error(`${name} must contain only finite numbers, but got [${arr.join(",")}]`);
    }
  }
}
function interpolate(input, inputRange, outputRange, options) {
  if (typeof input === "undefined") {
    throw new Error("input can not be undefined");
  }
  if (typeof inputRange === "undefined") {
    throw new Error("inputRange can not be undefined");
  }
  if (typeof outputRange === "undefined") {
    throw new Error("outputRange can not be undefined");
  }
  if (inputRange.length !== outputRange.length) {
    throw new Error("inputRange (" + inputRange.length + ") and outputRange (" + outputRange.length + ") must have the same length");
  }
  checkInfiniteRange("inputRange", inputRange);
  checkInfiniteRange("outputRange", outputRange);
  checkValidInputRange(inputRange);
  const easing = options?.easing ?? ((num) => num);
  let extrapolateLeft = "extend";
  if (options?.extrapolateLeft !== undefined) {
    extrapolateLeft = options.extrapolateLeft;
  }
  let extrapolateRight = "extend";
  if (options?.extrapolateRight !== undefined) {
    extrapolateRight = options.extrapolateRight;
  }
  if (typeof input !== "number") {
    throw new TypeError("Cannot interpolate an input which is not a number");
  }
  const range = findRange(input, inputRange);
  return interpolateFunction(input, [inputRange[range], inputRange[range + 1]], [outputRange[range], outputRange[range + 1]], {
    easing,
    extrapolateLeft,
    extrapolateRight
  });
}

// src/video/get-current-time.ts
var getExpectedMediaFrameUncorrected = ({
  frame,
  playbackRate,
  startFrom
}) => {
  return interpolate(frame, [-1, startFrom, startFrom + 1], [-1, startFrom, startFrom + playbackRate]);
};
var getMediaTime = ({
  fps,
  frame,
  playbackRate,
  startFrom
}) => {
  const expectedFrame = getExpectedMediaFrameUncorrected({
    frame,
    playbackRate,
    startFrom
  });
  const msPerFrame = 1000 / fps;
  return expectedFrame * msPerFrame / 1000;
};

// src/warn-about-non-seekable-media.ts
var alreadyWarned = {};
var warnAboutNonSeekableMedia = (ref, type) => {
  if (ref === null) {
    return;
  }
  if (ref.seekable.length === 0) {
    return;
  }
  if (ref.seekable.length > 1) {
    return;
  }
  if (alreadyWarned[ref.src]) {
    return;
  }
  const range = { start: ref.seekable.start(0), end: ref.seekable.end(0) };
  if (range.start === 0 && range.end === 0) {
    const msg = [
      `The media ${ref.src} cannot be seeked. This could be one of few reasons:`,
      "1) The media resource was replaced while the video is playing but it was not loaded yet.",
      "2) The media does not support seeking.",
      "3) The media was loaded with security headers prventing it from being included.",
      "Please see https://remotion.dev/docs/non-seekable-media for assistance."
    ].join(`
`);
    if (type === "console-error") {
      console.error(msg);
    } else if (type === "console-warning") {
      console.warn(`The media ${ref.src} does not support seeking. The video will render fine, but may not play correctly in the Remotion Studio and in the <Player>. See https://remotion.dev/docs/non-seekable-media for an explanation.`);
    } else {
      throw new Error(msg);
    }
    alreadyWarned[ref.src] = true;
  }
};

// src/use-media-playback.ts
var useMediaPlayback = ({
  mediaRef,
  src,
  mediaType,
  playbackRate: localPlaybackRate,
  onlyWarnForMediaSeekingError,
  acceptableTimeshift,
  pauseWhenBuffering,
  isPremounting,
  isPostmounting,
  onAutoPlayError
}) => {
  const { playbackRate: globalPlaybackRate } = usePlaybackRate();
  const frame = useCurrentFrame();
  const absoluteFrame = useTimelinePosition();
  const [playing] = usePlayingState();
  const buffering = useContext26(BufferingContextReact);
  const { fps } = useVideoConfig();
  const mediaStartsAt = useMediaStartsAt();
  const lastSeekDueToShift = useRef17(null);
  const lastSeek = useRef17(null);
  const logLevel = useLogLevel();
  const mountTime = useMountTime();
  if (!buffering) {
    throw new Error("useMediaPlayback must be used inside a <BufferingContext>");
  }
  const isVariableFpsVideoMap = useRef17({});
  const onVariableFpsVideoDetected = useCallback12(() => {
    if (!src) {
      return;
    }
    if (isVariableFpsVideoMap.current[src]) {
      return;
    }
    Log.verbose({ logLevel, tag: null }, `Detected ${src} as a variable FPS video. Disabling buffering while seeking.`);
    isVariableFpsVideoMap.current[src] = true;
  }, [logLevel, src]);
  const rvcCurrentTime = useRequestVideoCallbackTime({
    mediaRef,
    mediaType,
    lastSeek,
    onVariableFpsVideoDetected
  });
  const mediaTagCurrentTime = useCurrentTimeOfMediaTagWithUpdateTimeStamp(mediaRef);
  const desiredUnclampedTime = getMediaTime({
    frame,
    playbackRate: localPlaybackRate,
    startFrom: -mediaStartsAt,
    fps
  });
  const isMediaTagBuffering = useMediaBuffering({
    element: mediaRef,
    shouldBuffer: pauseWhenBuffering,
    isPremounting,
    isPostmounting,
    logLevel,
    mountTime,
    src: src ?? null
  });
  const { bufferUntilFirstFrame, isBuffering } = useBufferUntilFirstFrame({
    mediaRef,
    mediaType,
    onVariableFpsVideoDetected,
    pauseWhenBuffering,
    logLevel,
    mountTime
  });
  const playbackRate = localPlaybackRate * globalPlaybackRate;
  const acceptableTimeShiftButLessThanDuration = (() => {
    const DEFAULT_ACCEPTABLE_TIMESHIFT_WITH_NORMAL_PLAYBACK = 0.45;
    const DEFAULT_ACCEPTABLE_TIMESHIFT_WITH_AMPLIFICATION = DEFAULT_ACCEPTABLE_TIMESHIFT_WITH_NORMAL_PLAYBACK + 0.2;
    const defaultAcceptableTimeshift = DEFAULT_ACCEPTABLE_TIMESHIFT_WITH_AMPLIFICATION;
    if (mediaRef.current?.duration) {
      return Math.min(mediaRef.current.duration, acceptableTimeshift ?? defaultAcceptableTimeshift);
    }
    return acceptableTimeshift ?? defaultAcceptableTimeshift;
  })();
  const isPlayerBuffering = useIsPlayerBuffering(buffering);
  useEffect12(() => {
    if (mediaRef.current?.paused) {
      return;
    }
    if (!playing) {
      playbackLogging({
        logLevel,
        tag: "pause",
        message: `Pausing ${mediaRef.current?.src} because ${isPremounting ? "media is premounting" : isPostmounting ? "media is postmounting" : "Player is not playing"}`,
        mountTime
      });
      mediaRef.current?.pause();
      return;
    }
    const isMediaTagBufferingOrStalled = isMediaTagBuffering || isBuffering();
    const playerBufferingNotStateButLive = buffering.buffering.current;
    if (playerBufferingNotStateButLive && !isMediaTagBufferingOrStalled) {
      playbackLogging({
        logLevel,
        tag: "pause",
        message: `Pausing ${mediaRef.current?.src} because player is buffering but media tag is not`,
        mountTime
      });
      mediaRef.current?.pause();
    }
  }, [
    isBuffering,
    isMediaTagBuffering,
    buffering,
    isPlayerBuffering,
    isPremounting,
    logLevel,
    mediaRef,
    mediaType,
    mountTime,
    playing,
    isPostmounting
  ]);
  const env = useRemotionEnvironment();
  useLayoutEffect8(() => {
    const playbackRateToSet = Math.max(0, playbackRate);
    if (mediaRef.current && mediaRef.current.playbackRate !== playbackRateToSet) {
      mediaRef.current.playbackRate = playbackRateToSet;
    }
  }, [mediaRef, playbackRate]);
  useEffect12(() => {
    const tagName = mediaType === "audio" ? "<Html5Audio>" : "<Html5Video>";
    if (!mediaRef.current) {
      throw new Error(`No ${mediaType} ref found`);
    }
    if (!src) {
      throw new Error(`No 'src' attribute was passed to the ${tagName} element.`);
    }
    const { duration } = mediaRef.current;
    const shouldBeTime = !Number.isNaN(duration) && Number.isFinite(duration) ? Math.min(duration, desiredUnclampedTime) : desiredUnclampedTime;
    const mediaTagTime = mediaTagCurrentTime.current.time;
    const rvcTime = rvcCurrentTime.current?.time ?? null;
    const isVariableFpsVideo = isVariableFpsVideoMap.current[src];
    const timeShiftMediaTag = Math.abs(shouldBeTime - mediaTagTime);
    const timeShiftRvcTag = rvcTime ? Math.abs(shouldBeTime - rvcTime) : null;
    const mostRecentTimeshift = rvcCurrentTime.current?.lastUpdate && rvcCurrentTime.current.time > mediaTagCurrentTime.current.lastUpdate ? timeShiftRvcTag : timeShiftMediaTag;
    const timeShift = timeShiftRvcTag && !isVariableFpsVideo ? mostRecentTimeshift : timeShiftMediaTag;
    if (timeShift > acceptableTimeShiftButLessThanDuration && lastSeekDueToShift.current !== shouldBeTime) {
      lastSeek.current = seek({
        mediaRef: mediaRef.current,
        time: shouldBeTime,
        logLevel,
        why: `because time shift is too big. shouldBeTime = ${shouldBeTime}, isTime = ${mediaTagTime}, requestVideoCallbackTime = ${rvcTime}, timeShift = ${timeShift}${isVariableFpsVideo ? ", isVariableFpsVideo = true" : ""}, isPremounting = ${isPremounting}, isPostmounting = ${isPostmounting}, pauseWhenBuffering = ${pauseWhenBuffering}`,
        mountTime
      });
      lastSeekDueToShift.current = lastSeek.current;
      if (playing) {
        if (playbackRate > 0) {
          bufferUntilFirstFrame(shouldBeTime);
        }
        if (mediaRef.current.paused) {
          playAndHandleNotAllowedError({
            mediaRef,
            mediaType,
            onAutoPlayError,
            logLevel,
            mountTime,
            reason: "player is playing but media tag is paused, and just seeked",
            isPlayer: env.isPlayer
          });
        }
      }
      if (!onlyWarnForMediaSeekingError) {
        warnAboutNonSeekableMedia(mediaRef.current, onlyWarnForMediaSeekingError ? "console-warning" : "console-error");
      }
      return;
    }
    const seekThreshold = playing ? 0.15 : 0.01;
    const makesSenseToSeek = Math.abs(mediaRef.current.currentTime - shouldBeTime) > seekThreshold;
    const isMediaTagBufferingOrStalled = isMediaTagBuffering || isBuffering();
    const isSomethingElseBuffering = buffering.buffering.current && !isMediaTagBufferingOrStalled;
    if (!playing || isSomethingElseBuffering) {
      if (makesSenseToSeek) {
        lastSeek.current = seek({
          mediaRef: mediaRef.current,
          time: shouldBeTime,
          logLevel,
          why: `not playing or something else is buffering. time offset is over seek threshold (${seekThreshold})`,
          mountTime
        });
      }
      return;
    }
    if (!playing || buffering.buffering.current) {
      return;
    }
    const pausedCondition = mediaRef.current.paused && !mediaRef.current.ended;
    const firstFrameCondition = absoluteFrame === 0;
    if (pausedCondition || firstFrameCondition) {
      const reason = pausedCondition ? "media tag is paused" : "absolute frame is 0";
      if (makesSenseToSeek) {
        lastSeek.current = seek({
          mediaRef: mediaRef.current,
          time: shouldBeTime,
          logLevel,
          why: `is over timeshift threshold (threshold = ${seekThreshold}) and ${reason}`,
          mountTime
        });
      }
      playAndHandleNotAllowedError({
        mediaRef,
        mediaType,
        onAutoPlayError,
        logLevel,
        mountTime,
        reason: `player is playing and ${reason}`,
        isPlayer: env.isPlayer
      });
      if (!isVariableFpsVideo && playbackRate > 0) {
        bufferUntilFirstFrame(shouldBeTime);
      }
    }
  }, [
    absoluteFrame,
    acceptableTimeShiftButLessThanDuration,
    bufferUntilFirstFrame,
    buffering.buffering,
    rvcCurrentTime,
    logLevel,
    desiredUnclampedTime,
    isBuffering,
    isMediaTagBuffering,
    mediaRef,
    mediaType,
    onlyWarnForMediaSeekingError,
    playbackRate,
    playing,
    src,
    onAutoPlayError,
    isPremounting,
    isPostmounting,
    pauseWhenBuffering,
    mountTime,
    mediaTagCurrentTime,
    env.isPlayer
  ]);
};

// src/use-media-tag.ts
import { useEffect as useEffect13 } from "react";
var useMediaTag = ({
  mediaRef,
  id,
  mediaType,
  onAutoPlayError,
  isPremounting,
  isPostmounting
}) => {
  const { audioAndVideoTags, imperativePlaying } = useTimelineContext();
  const logLevel = useLogLevel();
  const mountTime = useMountTime();
  const env = useRemotionEnvironment();
  useEffect13(() => {
    const tag = {
      id,
      play: (reason) => {
        if (!imperativePlaying.current) {
          return;
        }
        if (isPremounting || isPostmounting) {
          return;
        }
        return playAndHandleNotAllowedError({
          mediaRef,
          mediaType,
          onAutoPlayError,
          logLevel,
          mountTime,
          reason,
          isPlayer: env.isPlayer
        });
      }
    };
    audioAndVideoTags.current.push(tag);
    return () => {
      audioAndVideoTags.current = audioAndVideoTags.current.filter((a) => a.id !== id);
    };
  }, [
    audioAndVideoTags,
    id,
    mediaRef,
    mediaType,
    onAutoPlayError,
    imperativePlaying,
    isPremounting,
    isPostmounting,
    logLevel,
    mountTime,
    env.isPlayer
  ]);
};

// src/volume-position-state.ts
import { createContext as createContext21, useContext as useContext27, useMemo as useMemo27 } from "react";
var MediaVolumeContext = createContext21({
  mediaMuted: false,
  mediaVolume: 1
});
var SetMediaVolumeContext = createContext21({
  setMediaMuted: () => {
    throw new Error("default");
  },
  setMediaVolume: () => {
    throw new Error("default");
  }
});
var useMediaVolumeState = () => {
  const { mediaVolume } = useContext27(MediaVolumeContext);
  const { setMediaVolume } = useContext27(SetMediaVolumeContext);
  return useMemo27(() => {
    return [mediaVolume, setMediaVolume];
  }, [mediaVolume, setMediaVolume]);
};
var useMediaMutedState = () => {
  const { mediaMuted } = useContext27(MediaVolumeContext);
  const { setMediaMuted } = useContext27(SetMediaVolumeContext);
  return useMemo27(() => {
    return [mediaMuted, setMediaMuted];
  }, [mediaMuted, setMediaMuted]);
};

// src/volume-safeguard.ts
var warnAboutTooHighVolume = (volume) => {
  if (volume >= 100) {
    throw new Error(`Volume was set to ${volume}, but regular volume is 1, not 100. Did you forget to divide by 100? Set a volume of less than 100 to dismiss this error.`);
  }
};

// src/audio/AudioForPreview.tsx
import { jsx as jsx22 } from "react/jsx-runtime";
var AudioForDevelopmentForwardRefFunction = (props, ref) => {
  const [initialShouldPreMountAudioElements] = useState16(props.shouldPreMountAudioTags);
  if (props.shouldPreMountAudioTags !== initialShouldPreMountAudioElements) {
    throw new Error("Cannot change the behavior for pre-mounting audio tags dynamically.");
  }
  const logLevel = useLogLevel();
  const {
    volume,
    muted,
    playbackRate,
    shouldPreMountAudioTags,
    src,
    onDuration,
    acceptableTimeShiftInSeconds,
    _remotionInternalNeedsDurationCalculation,
    _remotionInternalNativeLoopPassed,
    _remotionInternalStack,
    allowAmplificationDuringRender,
    name,
    pauseWhenBuffering,
    showInTimeline,
    loopVolumeCurveBehavior,
    stack,
    crossOrigin,
    delayRenderRetries,
    delayRenderTimeoutInMilliseconds,
    toneFrequency,
    useWebAudioApi,
    onError,
    onNativeError,
    audioStreamIndex,
    ...nativeProps
  } = props;
  const _propsValid = true;
  if (!_propsValid) {
    throw new Error("typecheck error");
  }
  const [mediaVolume] = useMediaVolumeState();
  const [mediaMuted] = useMediaMutedState();
  const volumePropFrame = useFrameForVolumeProp(loopVolumeCurveBehavior ?? "repeat");
  const { hidden } = useContext28(SequenceVisibilityToggleContext);
  if (!src) {
    throw new TypeError("No 'src' was passed to <Html5Audio>.");
  }
  const preloadedSrc = usePreload(src);
  const sequenceContext = useContext28(SequenceContext);
  const [timelineId] = useState16(() => String(Math.random()));
  const isSequenceHidden = hidden[timelineId] ?? false;
  const userPreferredVolume = evaluateVolume({
    frame: volumePropFrame,
    volume,
    mediaVolume
  });
  warnAboutTooHighVolume(userPreferredVolume);
  const crossOriginValue = getCrossOriginValue({
    crossOrigin,
    requestsVideoFrame: false,
    isClientSideRendering: false
  });
  const propsToPass = useMemo28(() => {
    return {
      muted: muted || mediaMuted || isSequenceHidden || userPreferredVolume <= 0,
      src: preloadedSrc,
      loop: _remotionInternalNativeLoopPassed,
      crossOrigin: crossOriginValue,
      ...nativeProps
    };
  }, [
    _remotionInternalNativeLoopPassed,
    isSequenceHidden,
    mediaMuted,
    muted,
    nativeProps,
    preloadedSrc,
    userPreferredVolume,
    crossOriginValue
  ]);
  const id = useMemo28(() => `audio-${random(src ?? "")}-${sequenceContext?.relativeFrom}-${sequenceContext?.cumulatedFrom}-${sequenceContext?.durationInFrames}-muted:${props.muted}-loop:${props.loop}`, [
    src,
    sequenceContext?.relativeFrom,
    sequenceContext?.cumulatedFrom,
    sequenceContext?.durationInFrames,
    props.muted,
    props.loop
  ]);
  const {
    el: audioRef,
    mediaElementSourceNode,
    cleanupOnMediaTagUnmount
  } = useSharedAudio({
    aud: propsToPass,
    audioId: id,
    premounting: Boolean(sequenceContext?.premounting),
    postmounting: Boolean(sequenceContext?.postmounting)
  });
  useMediaInTimeline({
    volume,
    mediaVolume,
    src,
    mediaType: "audio",
    playbackRate: playbackRate ?? 1,
    displayName: name ?? null,
    id: timelineId,
    stack: _remotionInternalStack,
    showInTimeline,
    premountDisplay: sequenceContext?.premountDisplay ?? null,
    postmountDisplay: sequenceContext?.postmountDisplay ?? null,
    loopDisplay: undefined
  });
  useMediaPlayback({
    mediaRef: audioRef,
    src,
    mediaType: "audio",
    playbackRate: playbackRate ?? 1,
    onlyWarnForMediaSeekingError: false,
    acceptableTimeshift: acceptableTimeShiftInSeconds ?? null,
    isPremounting: Boolean(sequenceContext?.premounting),
    isPostmounting: Boolean(sequenceContext?.postmounting),
    pauseWhenBuffering,
    onAutoPlayError: null
  });
  useMediaTag({
    id: timelineId,
    isPostmounting: Boolean(sequenceContext?.postmounting),
    isPremounting: Boolean(sequenceContext?.premounting),
    mediaRef: audioRef,
    mediaType: "audio",
    onAutoPlayError: null
  });
  useVolume({
    logLevel,
    mediaRef: audioRef,
    source: mediaElementSourceNode,
    volume: userPreferredVolume,
    shouldUseWebAudioApi: useWebAudioApi ?? false
  });
  const effectToUse = React23.useInsertionEffect ?? React23.useLayoutEffect;
  effectToUse(() => {
    return () => {
      requestAnimationFrame(() => {
        cleanupOnMediaTagUnmount();
      });
    };
  }, [cleanupOnMediaTagUnmount]);
  useImperativeHandle4(ref, () => {
    return audioRef.current;
  }, [audioRef]);
  const currentOnDurationCallback = useRef18(onDuration);
  currentOnDurationCallback.current = onDuration;
  useEffect14(() => {
    const { current } = audioRef;
    if (!current) {
      return;
    }
    if (current.duration) {
      currentOnDurationCallback.current?.(current.src, current.duration);
      return;
    }
    const onLoadedMetadata = () => {
      currentOnDurationCallback.current?.(current.src, current.duration);
    };
    current.addEventListener("loadedmetadata", onLoadedMetadata);
    return () => {
      current.removeEventListener("loadedmetadata", onLoadedMetadata);
    };
  }, [audioRef, src]);
  if (initialShouldPreMountAudioElements) {
    return null;
  }
  return /* @__PURE__ */ jsx22("audio", {
    ref: audioRef,
    preload: "metadata",
    crossOrigin: crossOriginValue,
    ...propsToPass
  });
};
var AudioForPreview = forwardRef6(AudioForDevelopmentForwardRefFunction);

// src/audio/AudioForRendering.tsx
import {
  forwardRef as forwardRef7,
  useContext as useContext29,
  useEffect as useEffect15,
  useImperativeHandle as useImperativeHandle5,
  useLayoutEffect as useLayoutEffect9,
  useMemo as useMemo29,
  useRef as useRef19
} from "react";
import { jsx as jsx23 } from "react/jsx-runtime";
var AudioForRenderingRefForwardingFunction = (props, ref) => {
  const audioRef = useRef19(null);
  const {
    volume: volumeProp,
    playbackRate,
    allowAmplificationDuringRender,
    onDuration,
    toneFrequency,
    _remotionInternalNeedsDurationCalculation,
    _remotionInternalNativeLoopPassed,
    acceptableTimeShiftInSeconds,
    name,
    onNativeError,
    delayRenderRetries,
    delayRenderTimeoutInMilliseconds,
    loopVolumeCurveBehavior,
    pauseWhenBuffering,
    audioStreamIndex,
    ...nativeProps
  } = props;
  const absoluteFrame = useTimelinePosition();
  const volumePropFrame = useFrameForVolumeProp(loopVolumeCurveBehavior ?? "repeat");
  const frame = useCurrentFrame();
  const sequenceContext = useContext29(SequenceContext);
  const { registerRenderAsset, unregisterRenderAsset } = useContext29(RenderAssetManager);
  const { delayRender: delayRender2, continueRender: continueRender2 } = useDelayRender();
  const id = useMemo29(() => `audio-${random(props.src ?? "")}-${sequenceContext?.relativeFrom}-${sequenceContext?.cumulatedFrom}-${sequenceContext?.durationInFrames}`, [
    props.src,
    sequenceContext?.relativeFrom,
    sequenceContext?.cumulatedFrom,
    sequenceContext?.durationInFrames
  ]);
  const volume = evaluateVolume({
    volume: volumeProp,
    frame: volumePropFrame,
    mediaVolume: 1
  });
  warnAboutTooHighVolume(volume);
  useImperativeHandle5(ref, () => {
    return audioRef.current;
  }, []);
  useEffect15(() => {
    if (!props.src) {
      throw new Error("No src passed");
    }
    if (!window.remotion_audioEnabled) {
      return;
    }
    if (props.muted) {
      return;
    }
    if (volume <= 0) {
      return;
    }
    registerRenderAsset({
      type: "audio",
      src: getAbsoluteSrc(props.src),
      id,
      frame: absoluteFrame,
      volume,
      mediaFrame: frame,
      playbackRate: props.playbackRate ?? 1,
      toneFrequency: toneFrequency ?? 1,
      audioStartFrame: Math.max(0, -(sequenceContext?.relativeFrom ?? 0)),
      audioStreamIndex: audioStreamIndex ?? 0
    });
    return () => unregisterRenderAsset(id);
  }, [
    props.muted,
    props.src,
    registerRenderAsset,
    absoluteFrame,
    id,
    unregisterRenderAsset,
    volume,
    volumePropFrame,
    frame,
    playbackRate,
    props.playbackRate,
    toneFrequency,
    sequenceContext?.relativeFrom,
    audioStreamIndex
  ]);
  const { src } = props;
  const needsToRenderAudioTag = ref || _remotionInternalNeedsDurationCalculation;
  useLayoutEffect9(() => {
    if (window.process?.env?.NODE_ENV === "test") {
      return;
    }
    if (!needsToRenderAudioTag) {
      return;
    }
    const newHandle = delayRender2("Loading <Html5Audio> duration with src=" + src, {
      retries: delayRenderRetries ?? undefined,
      timeoutInMilliseconds: delayRenderTimeoutInMilliseconds ?? undefined
    });
    const { current } = audioRef;
    const didLoad = () => {
      if (current?.duration) {
        onDuration(current.src, current.duration);
      }
      continueRender2(newHandle);
    };
    if (current?.duration) {
      onDuration(current.src, current.duration);
      continueRender2(newHandle);
    } else {
      current?.addEventListener("loadedmetadata", didLoad, { once: true });
    }
    return () => {
      current?.removeEventListener("loadedmetadata", didLoad);
      continueRender2(newHandle);
    };
  }, [
    src,
    onDuration,
    needsToRenderAudioTag,
    delayRenderRetries,
    delayRenderTimeoutInMilliseconds,
    continueRender2,
    delayRender2
  ]);
  if (!needsToRenderAudioTag) {
    return null;
  }
  return /* @__PURE__ */ jsx23("audio", {
    ref: audioRef,
    ...nativeProps,
    onError: onNativeError
  });
};
var AudioForRendering = forwardRef7(AudioForRenderingRefForwardingFunction);

// src/audio/html5-audio.tsx
import { jsx as jsx24 } from "react/jsx-runtime";
var AudioRefForwardingFunction = (props, ref) => {
  const audioTagsContext = useContext30(SharedAudioTagsContext);
  const {
    startFrom,
    endAt,
    trimBefore,
    trimAfter,
    name,
    stack,
    pauseWhenBuffering,
    showInTimeline,
    onError: onRemotionError,
    ...otherProps
  } = props;
  const { loop, ...propsOtherThanLoop } = props;
  const { fps } = useVideoConfig();
  const environment = useRemotionEnvironment();
  if (environment.isClientSideRendering) {
    throw new Error("<Html5Audio> is not supported in @remotion/web-renderer. Use <Audio> from @remotion/media instead. See https://remotion.dev/docs/client-side-rendering/limitations");
  }
  const { durations, setDurations } = useContext30(DurationsContext);
  if (typeof props.src !== "string") {
    throw new TypeError(`The \`<Html5Audio>\` tag requires a string for \`src\`, but got ${JSON.stringify(props.src)} instead.`);
  }
  const preloadedSrc = usePreload(props.src);
  const onError = useCallback13((e) => {
    console.log(e.currentTarget.error);
    const errMessage = `Could not play audio with src ${preloadedSrc}: ${e.currentTarget.error}. See https://remotion.dev/docs/media-playback-error for help.`;
    if (loop) {
      if (onRemotionError) {
        onRemotionError(new Error(errMessage));
        return;
      }
      cancelRender(new Error(errMessage));
    } else {
      onRemotionError?.(new Error(errMessage));
      console.warn(errMessage);
    }
  }, [loop, onRemotionError, preloadedSrc]);
  const onDuration = useCallback13((src, durationInSeconds) => {
    setDurations({ type: "got-duration", durationInSeconds, src });
  }, [setDurations]);
  const durationFetched = durations[getAbsoluteSrc(preloadedSrc)] ?? durations[getAbsoluteSrc(props.src)];
  validateMediaTrimProps({ startFrom, endAt, trimBefore, trimAfter });
  const { trimBeforeValue, trimAfterValue } = resolveTrimProps({
    startFrom,
    endAt,
    trimBefore,
    trimAfter
  });
  if (loop && durationFetched !== undefined) {
    if (!Number.isFinite(durationFetched)) {
      return /* @__PURE__ */ jsx24(Html5Audio, {
        ...propsOtherThanLoop,
        ref,
        _remotionInternalNativeLoopPassed: true
      });
    }
    const duration = durationFetched * fps;
    return /* @__PURE__ */ jsx24(Loop, {
      layout: "none",
      durationInFrames: calculateMediaDuration({
        trimAfter: trimAfterValue,
        mediaDurationInFrames: duration,
        playbackRate: props.playbackRate ?? 1,
        trimBefore: trimBeforeValue
      }),
      children: /* @__PURE__ */ jsx24(Html5Audio, {
        ...propsOtherThanLoop,
        ref,
        _remotionInternalNativeLoopPassed: true
      })
    });
  }
  if (typeof trimBeforeValue !== "undefined" || typeof trimAfterValue !== "undefined") {
    return /* @__PURE__ */ jsx24(Sequence, {
      layout: "none",
      from: 0 - (trimBeforeValue ?? 0),
      showInTimeline: false,
      durationInFrames: trimAfterValue,
      name,
      children: /* @__PURE__ */ jsx24(Html5Audio, {
        _remotionInternalNeedsDurationCalculation: Boolean(loop),
        pauseWhenBuffering: pauseWhenBuffering ?? false,
        ...otherProps,
        ref
      })
    });
  }
  validateMediaProps({ playbackRate: props.playbackRate, volume: props.volume }, "Html5Audio");
  if (environment.isRendering) {
    return /* @__PURE__ */ jsx24(AudioForRendering, {
      onDuration,
      ...props,
      ref,
      onNativeError: onError,
      _remotionInternalNeedsDurationCalculation: Boolean(loop)
    });
  }
  return /* @__PURE__ */ jsx24(AudioForPreview, {
    _remotionInternalNativeLoopPassed: props._remotionInternalNativeLoopPassed ?? false,
    _remotionInternalStack: stack ?? null,
    shouldPreMountAudioTags: audioTagsContext !== null && audioTagsContext.numberOfAudioTags > 0,
    ...props,
    ref,
    onNativeError: onError,
    onDuration,
    pauseWhenBuffering: pauseWhenBuffering ?? false,
    _remotionInternalNeedsDurationCalculation: Boolean(loop),
    showInTimeline: showInTimeline ?? true
  });
};
var Html5Audio = forwardRef8(AudioRefForwardingFunction);
addSequenceStackTraces(Html5Audio);
var Audio = Html5Audio;
// src/bezier.ts
var NEWTON_ITERATIONS = 4;
var NEWTON_MIN_SLOPE = 0.001;
var SUBDIVISION_PRECISION = 0.0000001;
var SUBDIVISION_MAX_ITERATIONS = 10;
var kSplineTableSize = 11;
var kSampleStepSize = 1 / (kSplineTableSize - 1);
var float32ArraySupported = typeof Float32Array === "function";
function a(aA1, aA2) {
  return 1 - 3 * aA2 + 3 * aA1;
}
function b(aA1, aA2) {
  return 3 * aA2 - 6 * aA1;
}
function c(aA1) {
  return 3 * aA1;
}
function calcBezier(aT, aA1, aA2) {
  return ((a(aA1, aA2) * aT + b(aA1, aA2)) * aT + c(aA1)) * aT;
}
function getSlope(aT, aA1, aA2) {
  return 3 * a(aA1, aA2) * aT * aT + 2 * b(aA1, aA2) * aT + c(aA1);
}
function binarySubdivide({
  aX,
  _aA,
  _aB,
  mX1,
  mX2
}) {
  let currentX;
  let currentT;
  let i = 0;
  let aA = _aA;
  let aB = _aB;
  do {
    currentT = aA + (aB - aA) / 2;
    currentX = calcBezier(currentT, mX1, mX2) - aX;
    if (currentX > 0) {
      aB = currentT;
    } else {
      aA = currentT;
    }
  } while (Math.abs(currentX) > SUBDIVISION_PRECISION && ++i < SUBDIVISION_MAX_ITERATIONS);
  return currentT;
}
function newtonRaphsonIterate(aX, _aGuessT, mX1, mX2) {
  let aGuessT = _aGuessT;
  for (let i = 0;i < NEWTON_ITERATIONS; ++i) {
    const currentSlope = getSlope(aGuessT, mX1, mX2);
    if (currentSlope === 0) {
      return aGuessT;
    }
    const currentX = calcBezier(aGuessT, mX1, mX2) - aX;
    aGuessT -= currentX / currentSlope;
  }
  return aGuessT;
}
function bezier(mX1, mY1, mX2, mY2) {
  if (!(mX1 >= 0 && mX1 <= 1 && mX2 >= 0 && mX2 <= 1)) {
    throw new Error("bezier x values must be in [0, 1] range");
  }
  const sampleValues = float32ArraySupported ? new Float32Array(kSplineTableSize) : new Array(kSplineTableSize);
  if (mX1 !== mY1 || mX2 !== mY2) {
    for (let i = 0;i < kSplineTableSize; ++i) {
      sampleValues[i] = calcBezier(i * kSampleStepSize, mX1, mX2);
    }
  }
  function getTForX(aX) {
    let intervalStart = 0;
    let currentSample = 1;
    const lastSample = kSplineTableSize - 1;
    for (;currentSample !== lastSample && sampleValues[currentSample] <= aX; ++currentSample) {
      intervalStart += kSampleStepSize;
    }
    --currentSample;
    const dist = (aX - sampleValues[currentSample]) / (sampleValues[currentSample + 1] - sampleValues[currentSample]);
    const guessForT = intervalStart + dist * kSampleStepSize;
    const initialSlope = getSlope(guessForT, mX1, mX2);
    if (initialSlope >= NEWTON_MIN_SLOPE) {
      return newtonRaphsonIterate(aX, guessForT, mX1, mX2);
    }
    if (initialSlope === 0) {
      return guessForT;
    }
    return binarySubdivide({
      aX,
      _aA: intervalStart,
      _aB: intervalStart + kSampleStepSize,
      mX1,
      mX2
    });
  }
  return function(x) {
    if (mX1 === mY1 && mX2 === mY2) {
      return x;
    }
    if (x === 0) {
      return 0;
    }
    if (x === 1) {
      return 1;
    }
    return calcBezier(getTForX(x), mY1, mY2);
  };
}

// src/easing.ts
class Easing {
  static step0(n) {
    return n > 0 ? 1 : 0;
  }
  static step1(n) {
    return n >= 1 ? 1 : 0;
  }
  static linear(t) {
    return t;
  }
  static ease(t) {
    return Easing.bezier(0.42, 0, 1, 1)(t);
  }
  static quad(t) {
    return t * t;
  }
  static cubic(t) {
    return t * t * t;
  }
  static poly(n) {
    return (t) => t ** n;
  }
  static sin(t) {
    return 1 - Math.cos(t * Math.PI / 2);
  }
  static circle(t) {
    return 1 - Math.sqrt(1 - t * t);
  }
  static exp(t) {
    return 2 ** (10 * (t - 1));
  }
  static elastic(bounciness = 1) {
    const p = bounciness * Math.PI;
    return (t) => 1 - Math.cos(t * Math.PI / 2) ** 3 * Math.cos(t * p);
  }
  static back(s = 1.70158) {
    return (t) => t * t * ((s + 1) * t - s);
  }
  static bounce(t) {
    if (t < 1 / 2.75) {
      return 7.5625 * t * t;
    }
    if (t < 2 / 2.75) {
      const t2_ = t - 1.5 / 2.75;
      return 7.5625 * t2_ * t2_ + 0.75;
    }
    if (t < 2.5 / 2.75) {
      const t2_ = t - 2.25 / 2.75;
      return 7.5625 * t2_ * t2_ + 0.9375;
    }
    const t2 = t - 2.625 / 2.75;
    return 7.5625 * t2 * t2 + 0.984375;
  }
  static bezier(x1, y1, x2, y2) {
    return bezier(x1, y1, x2, y2);
  }
  static in(easing) {
    return easing;
  }
  static out(easing) {
    return (t) => 1 - easing(1 - t);
  }
  static inOut(easing) {
    return (t) => {
      if (t < 0.5) {
        return easing(t * 2) / 2;
      }
      return 1 - easing((1 - t) * 2) / 2;
    };
  }
}
// src/get-static-files.ts
var warnedServer = false;
var warnedPlayer = false;
var warnServerOnce = () => {
  if (warnedServer) {
    return;
  }
  warnedServer = true;
  console.warn("Called getStaticFiles() on the server. The API is only available in the browser. An empty array was returned.");
};
var warnPlayerOnce = () => {
  if (warnedPlayer) {
    return;
  }
  warnedPlayer = true;
  console.warn("Called getStaticFiles() while using the Remotion Player. The API is only available while using the Remotion Studio. An empty array was returned.");
};
var getStaticFiles = () => {
  if (ENABLE_V5_BREAKING_CHANGES) {
    throw new Error("getStaticFiles() has moved into the `@remotion/studio` package. Update your imports.");
  }
  if (typeof document === "undefined") {
    warnServerOnce();
    return [];
  }
  if (window.remotion_isPlayer) {
    warnPlayerOnce();
    return [];
  }
  return window.remotion_staticFiles;
};
// src/IFrame.tsx
import { forwardRef as forwardRef9, useCallback as useCallback14, useState as useState17 } from "react";
import { jsx as jsx25 } from "react/jsx-runtime";
var IFrameRefForwarding = ({
  onLoad,
  onError,
  delayRenderRetries,
  delayRenderTimeoutInMilliseconds,
  ...props2
}, ref) => {
  const { delayRender: delayRender2, continueRender: continueRender2 } = useDelayRender();
  const [handle] = useState17(() => delayRender2(`Loading <IFrame> with source ${props2.src}`, {
    retries: delayRenderRetries ?? undefined,
    timeoutInMilliseconds: delayRenderTimeoutInMilliseconds ?? undefined
  }));
  const didLoad = useCallback14((e) => {
    continueRender2(handle);
    onLoad?.(e);
  }, [handle, onLoad, continueRender2]);
  const didGetError = useCallback14((e) => {
    continueRender2(handle);
    if (onError) {
      onError(e);
    } else {
      console.error("Error loading iframe:", e, "Handle the event using the onError() prop to make this message disappear.");
    }
  }, [handle, onError, continueRender2]);
  return /* @__PURE__ */ jsx25("iframe", {
    referrerPolicy: "strict-origin-when-cross-origin",
    ...props2,
    ref,
    onError: didGetError,
    onLoad: didLoad
  });
};
var IFrame = forwardRef9(IFrameRefForwarding);
// src/Img.tsx
import {
  useCallback as useCallback15,
  useContext as useContext31,
  useImperativeHandle as useImperativeHandle6,
  useLayoutEffect as useLayoutEffect10,
  useRef as useRef20,
  useState as useState18
} from "react";
import { jsx as jsx26 } from "react/jsx-runtime";
function exponentialBackoff(errorCount) {
  return 1000 * 2 ** (errorCount - 1);
}
function truncateSrcForLabel(src) {
  if (src.startsWith("data:") && src.length > 100) {
    return src.slice(0, 60) + "...[" + src.length + " chars total]";
  }
  return src;
}
var ImgInner = ({
  onError,
  maxRetries = 2,
  src,
  pauseWhenLoading,
  delayRenderRetries,
  delayRenderTimeoutInMilliseconds,
  onImageFrame,
  crossOrigin,
  showInTimeline,
  name,
  stack,
  ref,
  _experimentalControls: controls,
  ...props2
}) => {
  const imageRef = useRef20(null);
  const errors = useRef20({});
  const { delayPlayback } = useBufferState();
  const sequenceContext = useContext31(SequenceContext);
  const [timelineId] = useState18(() => String(Math.random()));
  if (!src) {
    throw new Error('No "src" prop was passed to <Img>.');
  }
  const _propsValid = true;
  if (!_propsValid) {
    throw new Error("typecheck error");
  }
  useImperativeHandle6(ref, () => {
    return imageRef.current;
  }, []);
  useImageInTimeline({
    src,
    displayName: name ?? null,
    id: timelineId,
    stack: stack ?? null,
    showInTimeline: showInTimeline ?? true,
    premountDisplay: sequenceContext?.premountDisplay ?? null,
    postmountDisplay: sequenceContext?.postmountDisplay ?? null,
    loopDisplay: undefined,
    controls: controls ?? null
  });
  const actualSrc = usePreload(src);
  const retryIn = useCallback15((timeout) => {
    if (!imageRef.current) {
      return;
    }
    const currentSrc = imageRef.current.src;
    setTimeout(() => {
      if (!imageRef.current) {
        return;
      }
      const newSrc = imageRef.current?.src;
      if (newSrc !== currentSrc) {
        return;
      }
      imageRef.current.removeAttribute("src");
      imageRef.current.setAttribute("src", newSrc);
    }, timeout);
  }, []);
  const { delayRender: delayRender2, continueRender: continueRender2, cancelRender: cancelRender2 } = useDelayRender();
  const didGetError = useCallback15((e) => {
    if (!errors.current) {
      return;
    }
    errors.current[imageRef.current?.src] = (errors.current[imageRef.current?.src] ?? 0) + 1;
    if (onError && (errors.current[imageRef.current?.src] ?? 0) > maxRetries) {
      onError(e);
      return;
    }
    if ((errors.current[imageRef.current?.src] ?? 0) <= maxRetries) {
      const backoff = exponentialBackoff(errors.current[imageRef.current?.src] ?? 0);
      console.warn(`Could not load image with source ${truncateSrcForLabel(imageRef.current?.src)}, retrying again in ${backoff}ms`);
      retryIn(backoff);
      return;
    }
    try {
      cancelRender2("Error loading image with src: " + truncateSrcForLabel(imageRef.current?.src));
    } catch {}
  }, [cancelRender2, maxRetries, onError, retryIn]);
  if (typeof window !== "undefined") {
    const isPremounting = Boolean(sequenceContext?.premounting);
    const isPostmounting = Boolean(sequenceContext?.postmounting);
    useLayoutEffect10(() => {
      if (window.process?.env?.NODE_ENV === "test") {
        if (imageRef.current) {
          imageRef.current.src = actualSrc;
        }
        return;
      }
      const { current } = imageRef;
      if (!current) {
        return;
      }
      const newHandle = delayRender2("Loading <Img> with src=" + truncateSrcForLabel(actualSrc), {
        retries: delayRenderRetries ?? undefined,
        timeoutInMilliseconds: delayRenderTimeoutInMilliseconds ?? undefined
      });
      const unblock = pauseWhenLoading && !isPremounting && !isPostmounting ? delayPlayback().unblock : () => {
        return;
      };
      let unmounted = false;
      const onComplete = () => {
        if (unmounted) {
          continueRender2(newHandle);
          return;
        }
        if ((errors.current[imageRef.current?.src] ?? 0) > 0) {
          delete errors.current[imageRef.current?.src];
          console.info(`Retry successful - ${truncateSrcForLabel(imageRef.current?.src)} is now loaded`);
        }
        if (current) {
          onImageFrame?.(current);
        }
        unblock();
        continueRender2(newHandle);
      };
      if (!imageRef.current) {
        onComplete();
        return;
      }
      current.src = actualSrc;
      current.decode().then(onComplete).catch((err) => {
        console.warn(err);
        if (current.complete && current.naturalWidth > 0 && current.naturalHeight > 0) {
          onComplete();
        } else {
          current.addEventListener("load", onComplete);
        }
      });
      return () => {
        unmounted = true;
        current.removeEventListener("load", onComplete);
        unblock();
        continueRender2(newHandle);
      };
    }, [
      actualSrc,
      delayPlayback,
      delayRenderRetries,
      delayRenderTimeoutInMilliseconds,
      pauseWhenLoading,
      isPremounting,
      isPostmounting,
      onImageFrame,
      continueRender2,
      delayRender2
    ]);
  }
  const { isClientSideRendering } = useRemotionEnvironment();
  const crossOriginValue = getCrossOriginValue({
    crossOrigin,
    requestsVideoFrame: false,
    isClientSideRendering
  });
  return /* @__PURE__ */ jsx26("img", {
    ...props2,
    ref: imageRef,
    crossOrigin: crossOriginValue,
    onError: didGetError,
    decoding: "sync"
  });
};
var Img = wrapInSchema(ImgInner, sequenceStyleSchema);
addSequenceStackTraces(Img);
// src/internals.ts
import { createRef as createRef3 } from "react";

// src/CompositionManager.tsx
import React28 from "react";
var compositionsRef = React28.createRef();

// src/CompositionManagerProvider.tsx
import {
  useCallback as useCallback16,
  useImperativeHandle as useImperativeHandle7,
  useMemo as useMemo30,
  useRef as useRef21,
  useState as useState19
} from "react";
import { jsx as jsx27 } from "react/jsx-runtime";
var CompositionManagerProvider = ({
  children,
  onlyRenderComposition,
  currentCompositionMetadata,
  initialCompositions,
  initialCanvasContent
}) => {
  const [folders, setFolders] = useState19([]);
  const [canvasContent, setCanvasContent] = useState19(initialCanvasContent);
  const [compositions, setCompositions] = useState19(initialCompositions);
  const currentcompositionsRef = useRef21(compositions);
  const updateCompositions = useCallback16((updateComps) => {
    setCompositions((comps) => {
      const updated = updateComps(comps);
      currentcompositionsRef.current = updated;
      return updated;
    });
  }, []);
  const registerComposition = useCallback16((comp) => {
    updateCompositions((comps) => {
      if (comps.find((c2) => c2.id === comp.id)) {
        throw new Error(`Multiple composition with id ${comp.id} are registered.`);
      }
      return [...comps, comp];
    });
  }, [updateCompositions]);
  const unregisterComposition = useCallback16((id) => {
    setCompositions((comps) => {
      return comps.filter((c2) => c2.id !== id);
    });
  }, []);
  const registerFolder = useCallback16((name, parent, nonce) => {
    setFolders((prevFolders) => {
      return [
        ...prevFolders,
        {
          name,
          parent,
          nonce
        }
      ];
    });
  }, []);
  const unregisterFolder = useCallback16((name, parent) => {
    setFolders((prevFolders) => {
      return prevFolders.filter((p) => !(p.name === name && p.parent === parent));
    });
  }, []);
  useImperativeHandle7(compositionsRef, () => {
    return {
      getCompositions: () => currentcompositionsRef.current
    };
  }, []);
  const compositionManagerSetters = useMemo30(() => {
    return {
      registerComposition,
      unregisterComposition,
      registerFolder,
      unregisterFolder,
      setCanvasContent,
      onlyRenderComposition
    };
  }, [
    registerComposition,
    registerFolder,
    unregisterComposition,
    unregisterFolder,
    onlyRenderComposition
  ]);
  const compositionManagerContextValue = useMemo30(() => {
    return {
      compositions,
      folders,
      currentCompositionMetadata,
      canvasContent
    };
  }, [compositions, folders, currentCompositionMetadata, canvasContent]);
  return /* @__PURE__ */ jsx27(CompositionManager.Provider, {
    value: compositionManagerContextValue,
    children: /* @__PURE__ */ jsx27(CompositionSetters.Provider, {
      value: compositionManagerSetters,
      children
    })
  });
};

// src/default-css.ts
var exports_default_css = {};
__export(exports_default_css, {
  makeDefaultPreviewCSS: () => makeDefaultPreviewCSS,
  injectCSS: () => injectCSS,
  OBJECTFIT_CONTAIN_CLASS_NAME: () => OBJECTFIT_CONTAIN_CLASS_NAME
});
var injected = {};
var injectCSS = (css) => {
  if (typeof document === "undefined") {
    return () => {};
  }
  if (injected[css]) {
    return () => {};
  }
  const head = document.head || document.getElementsByTagName("head")[0];
  const style = document.createElement("style");
  style.appendChild(document.createTextNode(css));
  head.prepend(style);
  injected[css] = style;
  return () => {
    const styleElement = injected[css];
    if (styleElement) {
      if (styleElement.parentNode) {
        styleElement.parentNode.removeChild(styleElement);
      }
      delete injected[css];
    }
  };
};
var OBJECTFIT_CONTAIN_CLASS_NAME = "__remotion_objectfitcontain";
var makeDefaultPreviewCSS = (scope, backgroundColor) => {
  if (!scope) {
    return `
    * {
      box-sizing: border-box;
    }
    body {
      margin: 0;
	    background-color: ${backgroundColor};
    }
    .${OBJECTFIT_CONTAIN_CLASS_NAME} {
      object-fit: contain;
    }
    `;
  }
  return `
    ${scope} * {
      box-sizing: border-box;
    }
    ${scope} *:-webkit-full-screen {
      width: 100%;
      height: 100%;
    }
    ${scope} .${OBJECTFIT_CONTAIN_CLASS_NAME} {
      object-fit: contain;
    }
  `;
};

// src/effects/define-effect.ts
var defineEffect = (definition) => definition;
var createDescriptor = (definition, params) => {
  return {
    definition,
    params,
    stack: new Error().stack
  };
};

// src/get-preview-dom-element.ts
var REMOTION_STUDIO_CONTAINER_ELEMENT = "__remotion-studio-container";
var getPreviewDomElement = () => {
  return document.getElementById(REMOTION_STUDIO_CONTAINER_ELEMENT);
};

// src/max-video-cache-size.ts
import React29 from "react";
var MaxMediaCacheSizeContext = React29.createContext(null);

// src/register-root.ts
var Root = null;
var listeners = [];
var registerRoot = (comp) => {
  if (!comp) {
    throw new Error(`You must pass a React component to registerRoot(), but ${JSON.stringify(comp)} was passed.`);
  }
  if (Root) {
    throw new Error("registerRoot() was called more than once.");
  }
  Root = comp;
  listeners.forEach((l) => {
    l(comp);
  });
};
var getRoot = () => {
  return Root;
};
var waitForRoot = (fn) => {
  if (Root) {
    fn(Root);
    return () => {
      return;
    };
  }
  listeners.push(fn);
  return () => {
    listeners = listeners.filter((l) => l !== fn);
  };
};

// src/RemotionRoot.tsx
import { useMemo as useMemo32 } from "react";

// src/use-media-enabled.tsx
import { createContext as createContext22, useContext as useContext32, useMemo as useMemo31 } from "react";
import { jsx as jsx28 } from "react/jsx-runtime";
var MediaEnabledContext = createContext22(null);
var useVideoEnabled = () => {
  const context = useContext32(MediaEnabledContext);
  if (!context) {
    return window.remotion_videoEnabled;
  }
  if (context.videoEnabled === null) {
    return window.remotion_videoEnabled;
  }
  return context.videoEnabled;
};
var useAudioEnabled = () => {
  const context = useContext32(MediaEnabledContext);
  if (!context) {
    return window.remotion_audioEnabled;
  }
  if (context.audioEnabled === null) {
    return window.remotion_audioEnabled;
  }
  return context.audioEnabled;
};
var MediaEnabledProvider = ({
  children,
  videoEnabled,
  audioEnabled
}) => {
  const value = useMemo31(() => ({ videoEnabled, audioEnabled }), [videoEnabled, audioEnabled]);
  return /* @__PURE__ */ jsx28(MediaEnabledContext.Provider, {
    value,
    children
  });
};

// src/RemotionRoot.tsx
import { jsx as jsx29 } from "react/jsx-runtime";
var RemotionRootContexts = ({
  children,
  numberOfAudioTags,
  logLevel,
  audioLatencyHint,
  videoEnabled,
  audioEnabled,
  frameState,
  visualModeEnabled
}) => {
  const nonceContext = useMemo32(() => {
    let counter = 0;
    return {
      getNonce: () => counter++
    };
  }, []);
  const logging = useMemo32(() => {
    return { logLevel, mountTime: Date.now() };
  }, [logLevel]);
  return /* @__PURE__ */ jsx29(LogLevelContext.Provider, {
    value: logging,
    children: /* @__PURE__ */ jsx29(NonceContext.Provider, {
      value: nonceContext,
      children: /* @__PURE__ */ jsx29(TimelineContextProvider, {
        frameState,
        children: /* @__PURE__ */ jsx29(MediaEnabledProvider, {
          videoEnabled,
          audioEnabled,
          children: /* @__PURE__ */ jsx29(EditorPropsProvider, {
            children: /* @__PURE__ */ jsx29(PrefetchProvider, {
              children: /* @__PURE__ */ jsx29(SequenceManagerProvider, {
                visualModeEnabled,
                children: /* @__PURE__ */ jsx29(DurationsContextProvider, {
                  children: /* @__PURE__ */ jsx29(BufferingProvider, {
                    children: /* @__PURE__ */ jsx29(SharedAudioContextProvider, {
                      audioLatencyHint,
                      audioEnabled,
                      children: /* @__PURE__ */ jsx29(SharedAudioTagsContextProvider, {
                        numberOfAudioTags,
                        children
                      })
                    })
                  })
                })
              })
            })
          })
        })
      })
    })
  });
};

// src/codec.ts
var validCodecs = [
  "h264",
  "h265",
  "vp8",
  "vp9",
  "av1",
  "mp3",
  "aac",
  "wav",
  "prores",
  "h264-mkv",
  "h264-ts",
  "gif"
];

// src/validation/validate-default-codec.ts
function validateCodec(defaultCodec, location, name) {
  if (typeof defaultCodec === "undefined") {
    return;
  }
  if (typeof defaultCodec !== "string") {
    throw new TypeError(`The "${name}" prop ${location} must be a string, but you passed a value of type ${typeof defaultCodec}.`);
  }
  if (!validCodecs.includes(defaultCodec)) {
    throw new Error(`The "${name}" prop ${location} must be one of ${validCodecs.join(", ")}, but you passed ${defaultCodec}.`);
  }
}

// src/resolve-video-config.ts
var validateCalculated = ({
  calculated,
  compositionId,
  compositionFps,
  compositionHeight,
  compositionWidth,
  compositionDurationInFrames
}) => {
  const calculateMetadataErrorLocation = `calculated by calculateMetadata() for the composition "${compositionId}"`;
  const defaultErrorLocation = `of the "<Composition />" component with the id "${compositionId}"`;
  const width = calculated?.width ?? compositionWidth ?? undefined;
  validateDimension(width, "width", calculated?.width ? calculateMetadataErrorLocation : defaultErrorLocation);
  const height = calculated?.height ?? compositionHeight ?? undefined;
  validateDimension(height, "height", calculated?.height ? calculateMetadataErrorLocation : defaultErrorLocation);
  const fps = calculated?.fps ?? compositionFps ?? null;
  validateFps(fps, calculated?.fps ? calculateMetadataErrorLocation : defaultErrorLocation, false);
  const durationInFrames = calculated?.durationInFrames ?? compositionDurationInFrames ?? null;
  validateDurationInFrames(durationInFrames, {
    allowFloats: false,
    component: `of the "<Composition />" component with the id "${compositionId}"`
  });
  const defaultCodec = calculated?.defaultCodec;
  validateCodec(defaultCodec, calculateMetadataErrorLocation, "defaultCodec");
  const defaultOutName = calculated?.defaultOutName;
  const defaultVideoImageFormat = calculated?.defaultVideoImageFormat;
  const defaultPixelFormat = calculated?.defaultPixelFormat;
  const defaultProResProfile = calculated?.defaultProResProfile;
  const defaultSampleRate = calculated?.defaultSampleRate;
  return {
    width,
    height,
    fps,
    durationInFrames,
    defaultCodec,
    defaultOutName,
    defaultVideoImageFormat,
    defaultPixelFormat,
    defaultProResProfile,
    defaultSampleRate
  };
};
var resolveVideoConfig = ({
  calculateMetadata,
  signal,
  defaultProps,
  inputProps: originalProps,
  compositionId,
  compositionDurationInFrames,
  compositionFps,
  compositionHeight,
  compositionWidth
}) => {
  const calculatedProm = calculateMetadata ? calculateMetadata({
    defaultProps,
    props: originalProps,
    abortSignal: signal,
    compositionId,
    isRendering: getRemotionEnvironment().isRendering
  }) : null;
  if (calculatedProm !== null && typeof calculatedProm === "object" && "then" in calculatedProm) {
    return calculatedProm.then((c2) => {
      const {
        height,
        width,
        durationInFrames,
        fps,
        defaultCodec,
        defaultOutName,
        defaultVideoImageFormat,
        defaultPixelFormat,
        defaultProResProfile,
        defaultSampleRate
      } = validateCalculated({
        calculated: c2,
        compositionDurationInFrames,
        compositionFps,
        compositionHeight,
        compositionWidth,
        compositionId
      });
      return {
        width,
        height,
        fps,
        durationInFrames,
        id: compositionId,
        defaultProps: serializeThenDeserializeInStudio(defaultProps),
        props: serializeThenDeserializeInStudio(c2.props ?? originalProps),
        defaultCodec: defaultCodec ?? null,
        defaultOutName: defaultOutName ?? null,
        defaultVideoImageFormat: defaultVideoImageFormat ?? null,
        defaultPixelFormat: defaultPixelFormat ?? null,
        defaultProResProfile: defaultProResProfile ?? null,
        defaultSampleRate: defaultSampleRate ?? null
      };
    });
  }
  const data = validateCalculated({
    calculated: calculatedProm,
    compositionDurationInFrames,
    compositionFps,
    compositionHeight,
    compositionWidth,
    compositionId
  });
  if (calculatedProm === null) {
    return {
      ...data,
      id: compositionId,
      defaultProps: serializeThenDeserializeInStudio(defaultProps ?? {}),
      props: serializeThenDeserializeInStudio(originalProps),
      defaultCodec: null,
      defaultOutName: null,
      defaultVideoImageFormat: null,
      defaultPixelFormat: null,
      defaultProResProfile: null,
      defaultSampleRate: null
    };
  }
  return {
    ...data,
    id: compositionId,
    defaultProps: serializeThenDeserializeInStudio(defaultProps ?? {}),
    props: serializeThenDeserializeInStudio(calculatedProm.props ?? originalProps),
    defaultCodec: calculatedProm.defaultCodec ?? null,
    defaultOutName: calculatedProm.defaultOutName ?? null,
    defaultVideoImageFormat: calculatedProm.defaultVideoImageFormat ?? null,
    defaultPixelFormat: calculatedProm.defaultPixelFormat ?? null,
    defaultProResProfile: calculatedProm.defaultProResProfile ?? null,
    defaultSampleRate: calculatedProm.defaultSampleRate ?? null
  };
};
var resolveVideoConfigOrCatch = (params) => {
  try {
    const promiseOrReturnValue = resolveVideoConfig(params);
    return {
      type: "success",
      result: promiseOrReturnValue
    };
  } catch (err) {
    return {
      type: "error",
      error: err
    };
  }
};

// src/sequence-stack-traces.ts
import React31 from "react";
var SequenceStackTracesUpdateContext = React31.createContext(() => {});

// src/setup-env-variables.ts
var getEnvVariables = () => {
  if (getRemotionEnvironment().isRendering) {
    const param = window.remotion_envVariables;
    if (!param) {
      return {};
    }
    return { ...JSON.parse(param), NODE_ENV: "production" };
  }
  if (false) {}
  return {
    NODE_ENV: "production"
  };
};
var setupEnvVariables = () => {
  const env = getEnvVariables();
  if (!window.process) {
    window.process = {};
  }
  if (!window.process.env) {
    window.process.env = {};
  }
  Object.keys(env).forEach((key) => {
    window.process.env[key] = env[key];
  });
};

// src/use-current-scale.ts
import React32, { createContext as createContext23 } from "react";
var CurrentScaleContext = React32.createContext(null);
var PreviewSizeContext = createContext23({
  setSize: () => {
    return;
  },
  size: { size: "auto", translation: { x: 0, y: 0 } }
});
var calculateScale = ({
  canvasSize,
  compositionHeight,
  compositionWidth,
  previewSize
}) => {
  const heightRatio = canvasSize.height / compositionHeight;
  const widthRatio = canvasSize.width / compositionWidth;
  const ratio = Math.min(heightRatio, widthRatio);
  if (previewSize === "auto") {
    if (ratio === 0) {
      return 1;
    }
    return ratio;
  }
  return Number(previewSize);
};
var useCurrentScale = (options) => {
  const hasContext = React32.useContext(CurrentScaleContext);
  const zoomContext = React32.useContext(PreviewSizeContext);
  const config = useUnsafeVideoConfig();
  const env = useRemotionEnvironment();
  if (hasContext === null || config === null || zoomContext === null) {
    if (options?.dontThrowIfOutsideOfRemotion) {
      return 1;
    }
    if (env.isRendering) {
      return 1;
    }
    throw new Error([
      "useCurrentScale() was called outside of a Remotion context.",
      "This hook can only be called in a component that is being rendered by Remotion.",
      "If you want to this hook to return 1 outside of Remotion, pass {dontThrowIfOutsideOfRemotion: true} as an option.",
      "If you think you called this hook in a Remotion component, make sure all versions of Remotion are aligned."
    ].join(`
`));
  }
  if (hasContext.type === "scale") {
    return hasContext.scale;
  }
  return calculateScale({
    canvasSize: hasContext.canvasSize,
    compositionHeight: config.height,
    compositionWidth: config.width,
    previewSize: zoomContext.size.size
  });
};

// src/use-sequence-control-override.ts
import { useContext as useContext33 } from "react";
var useSequenceControlOverride = (key) => {
  const seqContext = useContext33(SequenceContext);
  const { dragOverrides: overrides } = useContext33(VisualModeOverridesContext);
  if (!seqContext) {
    return;
  }
  return overrides[seqContext.id]?.[key];
};

// src/video/OffthreadVideo.tsx
import { useCallback as useCallback18 } from "react";

// src/video/OffthreadVideoForRendering.tsx
import {
  useCallback as useCallback17,
  useContext as useContext34,
  useEffect as useEffect16,
  useLayoutEffect as useLayoutEffect11,
  useMemo as useMemo33,
  useState as useState20
} from "react";

// src/video/offthread-video-source.ts
var getOffthreadVideoSource = ({
  src,
  transparent,
  currentTime,
  toneMapped
}) => {
  return `http://localhost:${window.remotion_proxyPort}/proxy?src=${encodeURIComponent(getAbsoluteSrc(src))}&time=${encodeURIComponent(Math.max(0, currentTime))}&transparent=${String(transparent)}&toneMapped=${String(toneMapped)}`;
};

// src/video/OffthreadVideoForRendering.tsx
import { jsx as jsx30 } from "react/jsx-runtime";
var OffthreadVideoForRendering = ({
  onError,
  volume: volumeProp,
  playbackRate,
  src,
  muted,
  allowAmplificationDuringRender,
  transparent,
  toneMapped,
  toneFrequency,
  name,
  loopVolumeCurveBehavior,
  delayRenderRetries,
  delayRenderTimeoutInMilliseconds,
  onVideoFrame,
  crossOrigin,
  audioStreamIndex,
  ...props2
}) => {
  const absoluteFrame = useTimelinePosition();
  const frame = useCurrentFrame();
  const volumePropsFrame = useFrameForVolumeProp(loopVolumeCurveBehavior);
  const videoConfig = useUnsafeVideoConfig();
  const sequenceContext = useContext34(SequenceContext);
  const mediaStartsAt = useMediaStartsAt();
  const { registerRenderAsset, unregisterRenderAsset } = useContext34(RenderAssetManager);
  if (!src) {
    throw new TypeError("No `src` was passed to <OffthreadVideo>.");
  }
  const id = useMemo33(() => `offthreadvideo-${random(src)}-${sequenceContext?.cumulatedFrom}-${sequenceContext?.relativeFrom}-${sequenceContext?.durationInFrames}`, [
    src,
    sequenceContext?.cumulatedFrom,
    sequenceContext?.relativeFrom,
    sequenceContext?.durationInFrames
  ]);
  if (!videoConfig) {
    throw new Error("No video config found");
  }
  const volume = evaluateVolume({
    volume: volumeProp,
    frame: volumePropsFrame,
    mediaVolume: 1
  });
  warnAboutTooHighVolume(volume);
  useEffect16(() => {
    if (!src) {
      throw new Error("No src passed");
    }
    if (!window.remotion_audioEnabled) {
      return;
    }
    if (muted) {
      return;
    }
    if (volume <= 0) {
      return;
    }
    registerRenderAsset({
      type: "video",
      src: getAbsoluteSrc(src),
      id,
      frame: absoluteFrame,
      volume,
      mediaFrame: frame,
      playbackRate,
      toneFrequency,
      audioStartFrame: Math.max(0, -(sequenceContext?.relativeFrom ?? 0)),
      audioStreamIndex
    });
    return () => unregisterRenderAsset(id);
  }, [
    muted,
    src,
    registerRenderAsset,
    id,
    unregisterRenderAsset,
    volume,
    frame,
    absoluteFrame,
    playbackRate,
    toneFrequency,
    sequenceContext?.relativeFrom,
    audioStreamIndex
  ]);
  const currentTime = useMemo33(() => {
    return getExpectedMediaFrameUncorrected({
      frame,
      playbackRate: playbackRate || 1,
      startFrom: -mediaStartsAt
    }) / videoConfig.fps;
  }, [frame, mediaStartsAt, playbackRate, videoConfig.fps]);
  const actualSrc = useMemo33(() => {
    return getOffthreadVideoSource({
      src,
      currentTime,
      transparent,
      toneMapped
    });
  }, [toneMapped, currentTime, src, transparent]);
  const [imageSrc, setImageSrc] = useState20(null);
  const { delayRender: delayRender2, continueRender: continueRender2 } = useDelayRender();
  useLayoutEffect11(() => {
    if (!window.remotion_videoEnabled) {
      return;
    }
    const cleanup = [];
    setImageSrc(null);
    const controller = new AbortController;
    const newHandle = delayRender2(`Fetching ${actualSrc} from server`, {
      retries: delayRenderRetries ?? undefined,
      timeoutInMilliseconds: delayRenderTimeoutInMilliseconds ?? undefined
    });
    const execute = async () => {
      try {
        const res = await fetch(actualSrc, {
          signal: controller.signal,
          cache: "no-store"
        });
        if (res.status !== 200) {
          if (res.status === 500) {
            const json = await res.json();
            if (json.error) {
              const cleanedUpErrorMessage = json.error.replace(/^Error: /, "");
              throw new Error(cleanedUpErrorMessage);
            }
          }
          throw new Error(`Server returned status ${res.status} while fetching ${actualSrc}`);
        }
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        cleanup.push(() => URL.revokeObjectURL(url));
        setImageSrc({
          src: url,
          handle: newHandle
        });
      } catch (err) {
        if (err.message.includes("aborted")) {
          continueRender2(newHandle);
          return;
        }
        if (controller.signal.aborted) {
          continueRender2(newHandle);
          return;
        }
        if (err.message.includes("Failed to fetch")) {
          err = new Error(`Failed to fetch ${actualSrc}. This could be caused by Chrome rejecting the request because the disk space is low. Consider increasing the disk size of your environment.`, { cause: err });
        }
        if (onError) {
          onError(err);
        } else {
          cancelRender(err);
        }
      }
    };
    execute();
    cleanup.push(() => {
      if (controller.signal.aborted) {
        return;
      }
      controller.abort();
    });
    return () => {
      cleanup.forEach((c2) => c2());
    };
  }, [
    actualSrc,
    delayRenderRetries,
    delayRenderTimeoutInMilliseconds,
    onError,
    continueRender2,
    delayRender2
  ]);
  const onErr = useCallback17(() => {
    if (onError) {
      onError?.(new Error("Failed to load image with src " + imageSrc));
    } else {
      cancelRender("Failed to load image with src " + imageSrc);
    }
  }, [imageSrc, onError]);
  const className = useMemo33(() => {
    return [OBJECTFIT_CONTAIN_CLASS_NAME, props2.className].filter(truthy).join(" ");
  }, [props2.className]);
  const onImageFrame = useCallback17((img) => {
    if (onVideoFrame) {
      onVideoFrame(img);
    }
  }, [onVideoFrame]);
  if (!imageSrc || !window.remotion_videoEnabled) {
    return null;
  }
  continueRender2(imageSrc.handle);
  return /* @__PURE__ */ jsx30(Img, {
    src: imageSrc.src,
    delayRenderRetries,
    delayRenderTimeoutInMilliseconds,
    onImageFrame,
    ...props2,
    onError: onErr,
    className
  });
};

// src/video/VideoForPreview.tsx
import React34, {
  forwardRef as forwardRef10,
  useContext as useContext35,
  useEffect as useEffect18,
  useImperativeHandle as useImperativeHandle8,
  useMemo as useMemo34,
  useRef as useRef22,
  useState as useState21
} from "react";

// src/video/emit-video-frame.ts
import { useEffect as useEffect17 } from "react";
var useEmitVideoFrame = ({
  ref,
  onVideoFrame
}) => {
  useEffect17(() => {
    const { current } = ref;
    if (!current) {
      return;
    }
    if (!onVideoFrame) {
      return;
    }
    let handle = 0;
    const callback = () => {
      if (!ref.current) {
        return;
      }
      onVideoFrame(ref.current);
      handle = ref.current.requestVideoFrameCallback(callback);
    };
    callback();
    return () => {
      current.cancelVideoFrameCallback(handle);
    };
  }, [onVideoFrame, ref]);
};

// src/video/MediaPlaybackError.ts
class MediaPlaybackError extends Error {
  src;
  constructor({ message, src }) {
    super(message);
    this.name = "MediaPlaybackError";
    this.src = src;
  }
}

// src/video/VideoForPreview.tsx
import { jsx as jsx31 } from "react/jsx-runtime";
var VideoForDevelopmentRefForwardingFunction = (props2, ref) => {
  const context = useContext35(SharedAudioContext);
  if (!context) {
    throw new Error("SharedAudioContext not found");
  }
  const videoRef = useRef22(null);
  const sharedSource = useMemo34(() => {
    if (!context.audioContext) {
      return null;
    }
    return makeSharedElementSourceNode({
      audioContext: context.audioContext,
      ref: videoRef
    });
  }, [context.audioContext]);
  const effectToUse = React34.useInsertionEffect ?? React34.useLayoutEffect;
  effectToUse(() => {
    return () => {
      requestAnimationFrame(() => {
        sharedSource?.cleanup();
      });
    };
  }, [sharedSource]);
  const {
    volume,
    muted,
    playbackRate,
    onlyWarnForMediaSeekingError,
    src,
    onDuration,
    acceptableTimeShift,
    acceptableTimeShiftInSeconds,
    toneFrequency,
    name,
    _remotionInternalNativeLoopPassed,
    _remotionInternalStack,
    style,
    pauseWhenBuffering,
    showInTimeline,
    loopVolumeCurveBehavior,
    onError,
    onAutoPlayError,
    onVideoFrame,
    crossOrigin,
    delayRenderRetries,
    delayRenderTimeoutInMilliseconds,
    allowAmplificationDuringRender,
    useWebAudioApi,
    audioStreamIndex,
    ...nativeProps
  } = props2;
  const _propsValid = true;
  if (!_propsValid) {
    throw new Error("typecheck error");
  }
  const volumePropFrame = useFrameForVolumeProp(loopVolumeCurveBehavior ?? "repeat");
  const { fps, durationInFrames } = useVideoConfig();
  const parentSequence = useContext35(SequenceContext);
  const { hidden } = useContext35(SequenceVisibilityToggleContext);
  const logLevel = useLogLevel();
  const mountTime = useMountTime();
  const [timelineId] = useState21(() => String(Math.random()));
  const isSequenceHidden = hidden[timelineId] ?? false;
  if (typeof acceptableTimeShift !== "undefined") {
    throw new Error("acceptableTimeShift has been removed. Use acceptableTimeShiftInSeconds instead.");
  }
  const [mediaVolume] = useMediaVolumeState();
  const [mediaMuted] = useMediaMutedState();
  const userPreferredVolume = evaluateVolume({
    frame: volumePropFrame,
    volume,
    mediaVolume
  });
  warnAboutTooHighVolume(userPreferredVolume);
  useMediaInTimeline({
    volume,
    mediaVolume,
    mediaType: "video",
    src,
    playbackRate: props2.playbackRate ?? 1,
    displayName: name ?? null,
    id: timelineId,
    stack: _remotionInternalStack,
    showInTimeline,
    premountDisplay: parentSequence?.premountDisplay ?? null,
    postmountDisplay: parentSequence?.postmountDisplay ?? null,
    loopDisplay: undefined
  });
  useMediaPlayback({
    mediaRef: videoRef,
    src,
    mediaType: "video",
    playbackRate: props2.playbackRate ?? 1,
    onlyWarnForMediaSeekingError,
    acceptableTimeshift: acceptableTimeShiftInSeconds ?? null,
    isPremounting: Boolean(parentSequence?.premounting),
    isPostmounting: Boolean(parentSequence?.postmounting),
    pauseWhenBuffering,
    onAutoPlayError: onAutoPlayError ?? null
  });
  useMediaTag({
    id: timelineId,
    isPostmounting: Boolean(parentSequence?.postmounting),
    isPremounting: Boolean(parentSequence?.premounting),
    mediaRef: videoRef,
    mediaType: "video",
    onAutoPlayError: onAutoPlayError ?? null
  });
  useVolume({
    logLevel,
    mediaRef: videoRef,
    volume: userPreferredVolume,
    source: sharedSource,
    shouldUseWebAudioApi: useWebAudioApi ?? false
  });
  const actualFrom = parentSequence ? parentSequence.relativeFrom : 0;
  const duration = parentSequence ? Math.min(parentSequence.durationInFrames, durationInFrames) : durationInFrames;
  const preloadedSrc = usePreload(src);
  const actualSrc = useAppendVideoFragment({
    actualSrc: preloadedSrc,
    actualFrom,
    duration,
    fps
  });
  useImperativeHandle8(ref, () => {
    return videoRef.current;
  }, []);
  useState21(() => playbackLogging({
    logLevel,
    message: `Mounting video with source = ${actualSrc}, v=${VERSION}, user agent=${typeof navigator === "undefined" ? "server" : navigator.userAgent}`,
    tag: "video",
    mountTime
  }));
  useEffect18(() => {
    const { current } = videoRef;
    if (!current) {
      return;
    }
    const errorHandler = () => {
      if (current.error) {
        console.error("Error occurred in video", current?.error);
        if (onError) {
          const err = new MediaPlaybackError({
            message: `Code ${current.error.code}: ${current.error.message}`,
            src
          });
          onError(err);
          return;
        }
        throw new MediaPlaybackError({
          message: `The browser threw an error while playing the video ${src}: Code ${current.error.code} - ${current?.error?.message}. See https://remotion.dev/docs/media-playback-error for help. Pass an onError() prop to handle the error.`,
          src
        });
      } else {
        if (onError) {
          const err = new MediaPlaybackError({
            message: `The browser threw an error while playing the video ${src}`,
            src
          });
          onError(err);
          return;
        }
        throw new MediaPlaybackError({
          message: "The browser threw an error while playing the video",
          src
        });
      }
    };
    current.addEventListener("error", errorHandler, { once: true });
    return () => {
      current.removeEventListener("error", errorHandler);
    };
  }, [onError, src]);
  const currentOnDurationCallback = useRef22(onDuration);
  currentOnDurationCallback.current = onDuration;
  useEmitVideoFrame({ ref: videoRef, onVideoFrame });
  useEffect18(() => {
    const { current } = videoRef;
    if (!current) {
      return;
    }
    if (current.duration) {
      currentOnDurationCallback.current?.(src, current.duration);
      return;
    }
    const onLoadedMetadata = () => {
      currentOnDurationCallback.current?.(src, current.duration);
    };
    current.addEventListener("loadedmetadata", onLoadedMetadata);
    return () => {
      current.removeEventListener("loadedmetadata", onLoadedMetadata);
    };
  }, [src]);
  useEffect18(() => {
    const { current } = videoRef;
    if (!current) {
      return;
    }
    if (isIosSafari()) {
      current.preload = "metadata";
    } else {
      current.preload = "auto";
    }
  }, []);
  const actualStyle = useMemo34(() => {
    return {
      ...style,
      opacity: isSequenceHidden ? 0 : style?.opacity ?? 1
    };
  }, [isSequenceHidden, style]);
  const crossOriginValue = getCrossOriginValue({
    crossOrigin,
    requestsVideoFrame: Boolean(onVideoFrame),
    isClientSideRendering: false
  });
  return /* @__PURE__ */ jsx31("video", {
    ref: videoRef,
    muted: muted || mediaMuted || isSequenceHidden || userPreferredVolume <= 0,
    playsInline: true,
    src: actualSrc,
    loop: _remotionInternalNativeLoopPassed,
    style: actualStyle,
    disableRemotePlayback: true,
    crossOrigin: crossOriginValue,
    ...nativeProps
  });
};
var VideoForPreview = forwardRef10(VideoForDevelopmentRefForwardingFunction);

// src/video/OffthreadVideo.tsx
import { jsx as jsx32 } from "react/jsx-runtime";
var InnerOffthreadVideo = (props2) => {
  const {
    startFrom,
    endAt,
    trimBefore,
    trimAfter,
    name,
    pauseWhenBuffering,
    stack,
    showInTimeline,
    ...otherProps
  } = props2;
  const environment = useRemotionEnvironment();
  if (environment.isClientSideRendering) {
    throw new Error("<OffthreadVideo> is not supported in @remotion/web-renderer. Use <Video> from @remotion/media instead. See https://remotion.dev/docs/client-side-rendering/limitations");
  }
  const onDuration = useCallback18(() => {
    return;
  }, []);
  if (typeof props2.src !== "string") {
    throw new TypeError(`The \`<OffthreadVideo>\` tag requires a string for \`src\`, but got ${JSON.stringify(props2.src)} instead.`);
  }
  validateMediaTrimProps({ startFrom, endAt, trimBefore, trimAfter });
  const { trimBeforeValue, trimAfterValue } = resolveTrimProps({
    startFrom,
    endAt,
    trimBefore,
    trimAfter
  });
  if (typeof trimBeforeValue !== "undefined" || typeof trimAfterValue !== "undefined") {
    return /* @__PURE__ */ jsx32(Sequence, {
      layout: "none",
      from: 0 - (trimBeforeValue ?? 0),
      showInTimeline: false,
      durationInFrames: trimAfterValue,
      name,
      children: /* @__PURE__ */ jsx32(InnerOffthreadVideo, {
        pauseWhenBuffering: pauseWhenBuffering ?? false,
        ...otherProps,
        trimAfter: undefined,
        name: undefined,
        showInTimeline,
        trimBefore: undefined,
        stack: undefined,
        startFrom: undefined,
        endAt: undefined
      })
    });
  }
  validateMediaProps(props2, "Video");
  if (environment.isRendering) {
    return /* @__PURE__ */ jsx32(OffthreadVideoForRendering, {
      pauseWhenBuffering: pauseWhenBuffering ?? false,
      ...otherProps,
      trimAfter: undefined,
      name: undefined,
      showInTimeline,
      trimBefore: undefined,
      stack: undefined,
      startFrom: undefined,
      endAt: undefined
    });
  }
  const {
    transparent,
    toneMapped,
    onAutoPlayError,
    onVideoFrame,
    crossOrigin,
    delayRenderRetries,
    delayRenderTimeoutInMilliseconds,
    ...propsForPreview
  } = otherProps;
  return /* @__PURE__ */ jsx32(VideoForPreview, {
    _remotionInternalStack: stack ?? null,
    onDuration,
    onlyWarnForMediaSeekingError: true,
    pauseWhenBuffering: pauseWhenBuffering ?? false,
    showInTimeline: showInTimeline ?? true,
    onAutoPlayError: onAutoPlayError ?? undefined,
    onVideoFrame: onVideoFrame ?? null,
    crossOrigin,
    ...propsForPreview,
    _remotionInternalNativeLoopPassed: false
  });
};
var OffthreadVideo = ({
  src,
  acceptableTimeShiftInSeconds,
  allowAmplificationDuringRender,
  audioStreamIndex,
  className,
  crossOrigin,
  delayRenderRetries,
  delayRenderTimeoutInMilliseconds,
  id,
  loopVolumeCurveBehavior,
  muted,
  name,
  onAutoPlayError,
  onError,
  onVideoFrame,
  pauseWhenBuffering,
  playbackRate,
  showInTimeline,
  style,
  toneFrequency,
  toneMapped,
  transparent,
  trimAfter,
  trimBefore,
  useWebAudioApi,
  volume,
  _remotionInternalNativeLoopPassed,
  endAt,
  stack,
  startFrom,
  imageFormat
}) => {
  if (imageFormat) {
    throw new TypeError(`The \`<OffthreadVideo>\` tag does no longer accept \`imageFormat\`. Use the \`transparent\` prop if you want to render a transparent video.`);
  }
  return /* @__PURE__ */ jsx32(InnerOffthreadVideo, {
    acceptableTimeShiftInSeconds,
    allowAmplificationDuringRender: allowAmplificationDuringRender ?? true,
    audioStreamIndex: audioStreamIndex ?? 0,
    className,
    crossOrigin,
    delayRenderRetries,
    delayRenderTimeoutInMilliseconds,
    id,
    loopVolumeCurveBehavior: loopVolumeCurveBehavior ?? "repeat",
    muted: muted ?? false,
    name,
    onAutoPlayError: onAutoPlayError ?? null,
    onError,
    onVideoFrame,
    pauseWhenBuffering: pauseWhenBuffering ?? true,
    playbackRate: playbackRate ?? 1,
    toneFrequency: toneFrequency ?? 1,
    showInTimeline: showInTimeline ?? true,
    src,
    stack,
    startFrom,
    _remotionInternalNativeLoopPassed: _remotionInternalNativeLoopPassed ?? false,
    endAt,
    style,
    toneMapped: toneMapped ?? true,
    transparent: transparent ?? false,
    trimAfter,
    trimBefore,
    useWebAudioApi: useWebAudioApi ?? false,
    volume
  });
};
addSequenceStackTraces(OffthreadVideo);

// src/watch-static-file.ts
var WATCH_REMOTION_STATIC_FILES = "remotion_staticFilesChanged";
var watchStaticFile = (fileName, callback) => {
  if (ENABLE_V5_BREAKING_CHANGES) {
    throw new Error("watchStaticFile() has moved into the `@remotion/studio` package. Update your imports.");
  }
  if (!getRemotionEnvironment().isStudio) {
    console.warn("The watchStaticFile() API is only available while using the Remotion Studio.");
    return { cancel: () => {
      return;
    } };
  }
  const withoutStaticBase = fileName.startsWith(window.remotion_staticBase) ? fileName.replace(window.remotion_staticBase, "") : fileName;
  const withoutLeadingSlash = withoutStaticBase.startsWith("/") ? withoutStaticBase.slice(1) : withoutStaticBase;
  let prevFileData = window.remotion_staticFiles.find((file) => file.name === withoutLeadingSlash);
  const checkFile = (event) => {
    const staticFiles = event.detail.files;
    const newFileData = staticFiles.find((file) => file.name === withoutLeadingSlash);
    if (!newFileData) {
      if (prevFileData !== undefined) {
        callback(null);
      }
      prevFileData = undefined;
      return;
    }
    if (prevFileData === undefined || prevFileData.lastModified !== newFileData.lastModified) {
      callback(newFileData);
      prevFileData = newFileData;
    }
  };
  window.addEventListener(WATCH_REMOTION_STATIC_FILES, checkFile);
  const cancel = () => {
    return window.removeEventListener(WATCH_REMOTION_STATIC_FILES, checkFile);
  };
  return { cancel };
};

// src/wrap-remotion-context.tsx
import React36, { useMemo as useMemo35 } from "react";
import { jsx as jsx33 } from "react/jsx-runtime";
function useRemotionContexts() {
  const compositionManagerCtx = React36.useContext(CompositionManager);
  const timelineContext = React36.useContext(TimelineContext);
  const setTimelineContext = React36.useContext(SetTimelineContext);
  const sequenceContext = React36.useContext(SequenceContext);
  const nonceContext = React36.useContext(NonceContext);
  const canUseRemotionHooksContext = React36.useContext(CanUseRemotionHooks);
  const preloadContext = React36.useContext(PreloadContext);
  const resolveCompositionContext = React36.useContext(ResolveCompositionContext);
  const renderAssetManagerContext = React36.useContext(RenderAssetManager);
  const sequenceManagerContext = React36.useContext(SequenceManager);
  const bufferManagerContext = React36.useContext(BufferingContextReact);
  const logLevelContext = React36.useContext(LogLevelContext);
  return useMemo35(() => ({
    compositionManagerCtx,
    timelineContext,
    setTimelineContext,
    sequenceContext,
    nonceContext,
    canUseRemotionHooksContext,
    preloadContext,
    resolveCompositionContext,
    renderAssetManagerContext,
    sequenceManagerContext,
    bufferManagerContext,
    logLevelContext
  }), [
    compositionManagerCtx,
    nonceContext,
    sequenceContext,
    setTimelineContext,
    timelineContext,
    canUseRemotionHooksContext,
    preloadContext,
    resolveCompositionContext,
    renderAssetManagerContext,
    sequenceManagerContext,
    bufferManagerContext,
    logLevelContext
  ]);
}
var RemotionContextProvider = (props2) => {
  const { children, contexts } = props2;
  return /* @__PURE__ */ jsx33(LogLevelContext.Provider, {
    value: contexts.logLevelContext,
    children: /* @__PURE__ */ jsx33(CanUseRemotionHooks.Provider, {
      value: contexts.canUseRemotionHooksContext,
      children: /* @__PURE__ */ jsx33(NonceContext.Provider, {
        value: contexts.nonceContext,
        children: /* @__PURE__ */ jsx33(PreloadContext.Provider, {
          value: contexts.preloadContext,
          children: /* @__PURE__ */ jsx33(CompositionManager.Provider, {
            value: contexts.compositionManagerCtx,
            children: /* @__PURE__ */ jsx33(SequenceManager.Provider, {
              value: contexts.sequenceManagerContext,
              children: /* @__PURE__ */ jsx33(RenderAssetManager.Provider, {
                value: contexts.renderAssetManagerContext,
                children: /* @__PURE__ */ jsx33(ResolveCompositionContext.Provider, {
                  value: contexts.resolveCompositionContext,
                  children: /* @__PURE__ */ jsx33(TimelineContext.Provider, {
                    value: contexts.timelineContext,
                    children: /* @__PURE__ */ jsx33(SetTimelineContext.Provider, {
                      value: contexts.setTimelineContext,
                      children: /* @__PURE__ */ jsx33(SequenceContext.Provider, {
                        value: contexts.sequenceContext,
                        children: /* @__PURE__ */ jsx33(BufferingContextReact.Provider, {
                          value: contexts.bufferManagerContext,
                          children
                        })
                      })
                    })
                  })
                })
              })
            })
          })
        })
      })
    })
  });
};

// src/internals.ts
var compositionSelectorRef = createRef3();
var Internals = {
  MaxMediaCacheSizeContext,
  useUnsafeVideoConfig,
  useFrameForVolumeProp,
  useTimelinePosition,
  useAbsoluteTimelinePosition,
  evaluateVolume,
  getAbsoluteSrc,
  Timeline: exports_timeline_position_state,
  validateMediaTrimProps,
  validateMediaProps,
  resolveTrimProps,
  VideoForPreview,
  CompositionManager,
  CompositionSetters,
  VisualModeOverridesContext,
  SequenceManager,
  SequenceStackTracesUpdateContext,
  SequenceVisibilityToggleContext,
  wrapInSchema,
  sequenceSchema,
  sequenceStyleSchema,
  flattenActiveSchema,
  getFlatSchemaWithAllKeys,
  useSequenceControlOverride,
  RemotionRootContexts,
  CompositionManagerProvider,
  useVideo,
  getRoot,
  useMediaVolumeState,
  useMediaMutedState,
  useMediaInTimeline,
  useLazyComponent,
  truthy,
  SequenceContext,
  PremountContext,
  useRemotionContexts,
  RemotionContextProvider,
  CSSUtils: exports_default_css,
  setupEnvVariables,
  MediaVolumeContext,
  SetMediaVolumeContext,
  getRemotionEnvironment,
  SharedAudioContext,
  SharedAudioContextProvider,
  SharedAudioTagsContext,
  SharedAudioTagsContextProvider,
  invalidCompositionErrorMessage,
  calculateMediaDuration,
  isCompositionIdValid,
  getPreviewDomElement,
  compositionsRef,
  portalNode,
  waitForRoot,
  SetTimelineContext,
  CanUseRemotionHooksProvider,
  CanUseRemotionHooks,
  PrefetchProvider,
  DurationsContextProvider,
  IsPlayerContextProvider,
  useIsPlayer,
  EditorPropsProvider,
  EditorPropsContext,
  usePreload,
  NonceContext,
  resolveVideoConfig,
  resolveVideoConfigOrCatch,
  ResolveCompositionContext,
  useResolvedVideoConfig,
  resolveCompositionsRef,
  REMOTION_STUDIO_CONTAINER_ELEMENT,
  RenderAssetManager,
  persistCurrentFrame,
  usePlaybackRate,
  useTimelineContext,
  useTimelineSetFrame,
  isIosSafari,
  WATCH_REMOTION_STATIC_FILES,
  addSequenceStackTraces,
  useMediaStartsAt,
  BufferingProvider,
  BufferingContextReact,
  getComponentsToAddStacksTo,
  CurrentScaleContext,
  PreviewSizeContext,
  calculateScale,
  validateRenderAsset,
  Log,
  LogLevelContext,
  useLogLevel,
  playbackLogging,
  timeValueRef,
  compositionSelectorRef,
  RemotionEnvironmentContext,
  warnAboutTooHighVolume,
  AudioForPreview,
  OBJECTFIT_CONTAIN_CLASS_NAME,
  InnerOffthreadVideo,
  useBasicMediaInTimeline,
  getInputPropsOverride,
  setInputPropsOverride,
  useVideoEnabled,
  useAudioEnabled,
  useIsPlayerBuffering,
  TimelinePosition: exports_timeline_position_state,
  DelayRenderContextType,
  TimelineContext,
  PlaybackRateContext,
  AbsoluteTimeContext,
  RenderAssetManagerProvider,
  getEffectiveVisualModeValue,
  CompositionRenderErrorContext,
  useEffectChainState,
  runEffectChain,
  useMemoizedEffects,
  defineEffect,
  createDescriptor,
  computeEffectiveSchemaValuesDotNotation
};
// src/interpolate-colors.ts
var NUMBER = "[-+]?\\d*\\.?\\d+";
var PERCENTAGE = NUMBER + "%";
function call(...args) {
  return "\\(\\s*(" + args.join(")\\s*,\\s*(") + ")\\s*\\)";
}
var MODERN_VALUE = "(?:none|[-+]?\\d*\\.?\\d+(?:%|deg|rad|grad|turn)?)";
function modernColorCall(name) {
  return new RegExp(name + "\\(\\s*(" + MODERN_VALUE + ")\\s+(" + MODERN_VALUE + ")\\s+(" + MODERN_VALUE + ")(?:\\s*\\/\\s*(" + MODERN_VALUE + "))?\\s*\\)");
}
function getMatchers() {
  const cachedMatchers = {
    rgb: undefined,
    rgba: undefined,
    hsl: undefined,
    hsla: undefined,
    hex3: undefined,
    hex4: undefined,
    hex5: undefined,
    hex6: undefined,
    hex8: undefined,
    oklch: undefined,
    oklab: undefined,
    lab: undefined,
    lch: undefined,
    hwb: undefined
  };
  if (cachedMatchers.rgb === undefined) {
    cachedMatchers.rgb = new RegExp("rgb" + call(NUMBER, NUMBER, NUMBER));
    cachedMatchers.rgba = new RegExp("rgba" + call(NUMBER, NUMBER, NUMBER, NUMBER));
    cachedMatchers.hsl = new RegExp("hsl" + call(NUMBER, PERCENTAGE, PERCENTAGE));
    cachedMatchers.hsla = new RegExp("hsla" + call(NUMBER, PERCENTAGE, PERCENTAGE, NUMBER));
    cachedMatchers.hex3 = /^#([0-9a-fA-F]{1})([0-9a-fA-F]{1})([0-9a-fA-F]{1})$/;
    cachedMatchers.hex4 = /^#([0-9a-fA-F]{1})([0-9a-fA-F]{1})([0-9a-fA-F]{1})([0-9a-fA-F]{1})$/;
    cachedMatchers.hex6 = /^#([0-9a-fA-F]{6})$/;
    cachedMatchers.hex8 = /^#([0-9a-fA-F]{8})$/;
    cachedMatchers.oklch = modernColorCall("oklch");
    cachedMatchers.oklab = modernColorCall("oklab");
    cachedMatchers.lab = modernColorCall("lab");
    cachedMatchers.lch = modernColorCall("lch");
    cachedMatchers.hwb = modernColorCall("hwb");
  }
  return cachedMatchers;
}
function hue2rgb(p, q, t) {
  if (t < 0) {
    t += 1;
  }
  if (t > 1) {
    t -= 1;
  }
  if (t < 1 / 6) {
    return p + (q - p) * 6 * t;
  }
  if (t < 1 / 2) {
    return q;
  }
  if (t < 2 / 3) {
    return p + (q - p) * (2 / 3 - t) * 6;
  }
  return p;
}
function hslToRgb(h, s, l) {
  const q = l < 0.5 ? l * (1 + s) : l + s - l * s;
  const p = 2 * l - q;
  const r = hue2rgb(p, q, h + 1 / 3);
  const g = hue2rgb(p, q, h);
  const b2 = hue2rgb(p, q, h - 1 / 3);
  return Math.round(r * 255) << 24 | Math.round(g * 255) << 16 | Math.round(b2 * 255) << 8;
}
function parse255(str) {
  const int = Number.parseInt(str, 10);
  if (int < 0) {
    return 0;
  }
  if (int > 255) {
    return 255;
  }
  return int;
}
function parse360(str) {
  const int = Number.parseFloat(str);
  return (int % 360 + 360) % 360 / 360;
}
function parse1(str) {
  const num = Number.parseFloat(str);
  if (num < 0) {
    return 0;
  }
  if (num > 1) {
    return 255;
  }
  return Math.round(num * 255);
}
function parsePercentage(str) {
  const int = Number.parseFloat(str);
  if (int < 0) {
    return 0;
  }
  if (int > 100) {
    return 1;
  }
  return int / 100;
}
function parseModernComponent(str, percentScale) {
  if (str === "none")
    return 0;
  if (str.endsWith("%")) {
    return Number.parseFloat(str) / 100 * percentScale;
  }
  return Number.parseFloat(str);
}
function parseHueAngle(str) {
  if (str === "none")
    return 0;
  if (str.endsWith("rad")) {
    return Number.parseFloat(str) * 180 / Math.PI;
  }
  if (str.endsWith("grad"))
    return Number.parseFloat(str) * 0.9;
  if (str.endsWith("turn"))
    return Number.parseFloat(str) * 360;
  return Number.parseFloat(str);
}
function parseModernAlpha(str) {
  if (str === undefined || str === "none")
    return 1;
  if (str.endsWith("%")) {
    return Math.max(0, Math.min(1, Number.parseFloat(str) / 100));
  }
  return Math.max(0, Math.min(1, Number.parseFloat(str)));
}
function linearToSrgb(c2) {
  if (c2 <= 0.0031308)
    return 12.92 * c2;
  return 1.055 * c2 ** (1 / 2.4) - 0.055;
}
function clamp01(v) {
  return Math.max(0, Math.min(1, v));
}
function rgbFloatToInt(r, g, b2, alpha) {
  const ri = Math.round(clamp01(r) * 255);
  const gi = Math.round(clamp01(g) * 255);
  const bi = Math.round(clamp01(b2) * 255);
  const ai = Math.round(clamp01(alpha) * 255);
  return (ri << 24 | gi << 16 | bi << 8 | ai) >>> 0;
}
function oklabToSrgb(L, a2, b2) {
  const l_ = L + 0.3963377774 * a2 + 0.2158037573 * b2;
  const m_ = L - 0.1055613458 * a2 - 0.0638541728 * b2;
  const s_ = L - 0.0894841775 * a2 - 1.291485548 * b2;
  const l = l_ * l_ * l_;
  const m = m_ * m_ * m_;
  const s = s_ * s_ * s_;
  const rLin = 4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s;
  const gLin = -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s;
  const bLin = -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s;
  return [linearToSrgb(rLin), linearToSrgb(gLin), linearToSrgb(bLin)];
}
function labToSrgb(L, a2, b2) {
  const epsilon = 216 / 24389;
  const kappa = 24389 / 27;
  const Xn = 0.95047;
  const Yn = 1;
  const Zn = 1.08883;
  const fy = (L + 16) / 116;
  const fx = a2 / 500 + fy;
  const fz = fy - b2 / 200;
  const fx3 = fx * fx * fx;
  const fz3 = fz * fz * fz;
  const xr = fx3 > epsilon ? fx3 : (116 * fx - 16) / kappa;
  const yr = L > kappa * epsilon ? ((L + 16) / 116) ** 3 : L / kappa;
  const zr = fz3 > epsilon ? fz3 : (116 * fz - 16) / kappa;
  const X = xr * Xn;
  const Y = yr * Yn;
  const Z = zr * Zn;
  const rLin = 3.2404542 * X - 1.5371385 * Y - 0.4985314 * Z;
  const gLin = -0.969266 * X + 1.8760108 * Y + 0.041556 * Z;
  const bLin = 0.0556434 * X - 0.2040259 * Y + 1.0572252 * Z;
  return [linearToSrgb(rLin), linearToSrgb(gLin), linearToSrgb(bLin)];
}
function hwbToSrgb(h, w, bk) {
  if (w + bk >= 1) {
    const gray = w / (w + bk);
    return [gray, gray, gray];
  }
  const q = 1;
  const p = 0;
  const r = hue2rgb(p, q, h + 1 / 3);
  const g = hue2rgb(p, q, h);
  const bl = hue2rgb(p, q, h - 1 / 3);
  const factor = 1 - w - bk;
  return [r * factor + w, g * factor + w, bl * factor + w];
}
var colorNames = {
  transparent: 0,
  aliceblue: 4042850303,
  antiquewhite: 4209760255,
  aqua: 16777215,
  aquamarine: 2147472639,
  azure: 4043309055,
  beige: 4126530815,
  bisque: 4293182719,
  black: 255,
  blanchedalmond: 4293643775,
  blue: 65535,
  blueviolet: 2318131967,
  brown: 2771004159,
  burlywood: 3736635391,
  burntsienna: 3934150143,
  cadetblue: 1604231423,
  chartreuse: 2147418367,
  chocolate: 3530104575,
  coral: 4286533887,
  cornflowerblue: 1687547391,
  cornsilk: 4294499583,
  crimson: 3692313855,
  cyan: 16777215,
  darkblue: 35839,
  darkcyan: 9145343,
  darkgoldenrod: 3095792639,
  darkgray: 2846468607,
  darkgreen: 6553855,
  darkgrey: 2846468607,
  darkkhaki: 3182914559,
  darkmagenta: 2332068863,
  darkolivegreen: 1433087999,
  darkorange: 4287365375,
  darkorchid: 2570243327,
  darkred: 2332033279,
  darksalmon: 3918953215,
  darkseagreen: 2411499519,
  darkslateblue: 1211993087,
  darkslategray: 793726975,
  darkslategrey: 793726975,
  darkturquoise: 13554175,
  darkviolet: 2483082239,
  deeppink: 4279538687,
  deepskyblue: 12582911,
  dimgray: 1768516095,
  dimgrey: 1768516095,
  dodgerblue: 512819199,
  firebrick: 2988581631,
  floralwhite: 4294635775,
  forestgreen: 579543807,
  fuchsia: 4278255615,
  gainsboro: 3705462015,
  ghostwhite: 4177068031,
  gold: 4292280575,
  goldenrod: 3668254975,
  gray: 2155905279,
  green: 8388863,
  greenyellow: 2919182335,
  grey: 2155905279,
  honeydew: 4043305215,
  hotpink: 4285117695,
  indianred: 3445382399,
  indigo: 1258324735,
  ivory: 4294963455,
  khaki: 4041641215,
  lavender: 3873897215,
  lavenderblush: 4293981695,
  lawngreen: 2096890111,
  lemonchiffon: 4294626815,
  lightblue: 2916673279,
  lightcoral: 4034953471,
  lightcyan: 3774873599,
  lightgoldenrodyellow: 4210742015,
  lightgray: 3553874943,
  lightgreen: 2431553791,
  lightgrey: 3553874943,
  lightpink: 4290167295,
  lightsalmon: 4288707327,
  lightseagreen: 548580095,
  lightskyblue: 2278488831,
  lightslategray: 2005441023,
  lightslategrey: 2005441023,
  lightsteelblue: 2965692159,
  lightyellow: 4294959359,
  lime: 16711935,
  limegreen: 852308735,
  linen: 4210091775,
  magenta: 4278255615,
  maroon: 2147483903,
  mediumaquamarine: 1724754687,
  mediumblue: 52735,
  mediumorchid: 3126187007,
  mediumpurple: 2473647103,
  mediumseagreen: 1018393087,
  mediumslateblue: 2070474495,
  mediumspringgreen: 16423679,
  mediumturquoise: 1221709055,
  mediumvioletred: 3340076543,
  midnightblue: 421097727,
  mintcream: 4127193855,
  mistyrose: 4293190143,
  moccasin: 4293178879,
  navajowhite: 4292783615,
  navy: 33023,
  oldlace: 4260751103,
  olive: 2155872511,
  olivedrab: 1804477439,
  orange: 4289003775,
  orangered: 4282712319,
  orchid: 3664828159,
  palegoldenrod: 4008225535,
  palegreen: 2566625535,
  paleturquoise: 2951671551,
  palevioletred: 3681588223,
  papayawhip: 4293907967,
  peachpuff: 4292524543,
  peru: 3448061951,
  pink: 4290825215,
  plum: 3718307327,
  powderblue: 2967529215,
  purple: 2147516671,
  rebeccapurple: 1714657791,
  red: 4278190335,
  rosybrown: 3163525119,
  royalblue: 1097458175,
  saddlebrown: 2336560127,
  salmon: 4202722047,
  sandybrown: 4104413439,
  seagreen: 780883967,
  seashell: 4294307583,
  sienna: 2689740287,
  silver: 3233857791,
  skyblue: 2278484991,
  slateblue: 1784335871,
  slategray: 1887473919,
  slategrey: 1887473919,
  snow: 4294638335,
  springgreen: 16744447,
  steelblue: 1182971135,
  tan: 3535047935,
  teal: 8421631,
  thistle: 3636451583,
  tomato: 4284696575,
  turquoise: 1088475391,
  violet: 4001558271,
  wheat: 4125012991,
  white: 4294967295,
  whitesmoke: 4126537215,
  yellow: 4294902015,
  yellowgreen: 2597139199
};
function normalizeColor(color) {
  const matchers = getMatchers();
  let match;
  if (matchers.hex6) {
    if (match = matchers.hex6.exec(color)) {
      return Number.parseInt(match[1] + "ff", 16) >>> 0;
    }
  }
  if (colorNames[color] !== undefined) {
    return colorNames[color];
  }
  if (matchers.rgb) {
    if (match = matchers.rgb.exec(color)) {
      return (parse255(match[1]) << 24 | parse255(match[2]) << 16 | parse255(match[3]) << 8 | 255) >>> 0;
    }
  }
  if (matchers.rgba) {
    if (match = matchers.rgba.exec(color)) {
      return (parse255(match[1]) << 24 | parse255(match[2]) << 16 | parse255(match[3]) << 8 | parse1(match[4])) >>> 0;
    }
  }
  if (matchers.hex3) {
    if (match = matchers.hex3.exec(color)) {
      return Number.parseInt(match[1] + match[1] + match[2] + match[2] + match[3] + match[3] + "ff", 16) >>> 0;
    }
  }
  if (matchers.hex8) {
    if (match = matchers.hex8.exec(color)) {
      return Number.parseInt(match[1], 16) >>> 0;
    }
  }
  if (matchers.hex4) {
    if (match = matchers.hex4.exec(color)) {
      return Number.parseInt(match[1] + match[1] + match[2] + match[2] + match[3] + match[3] + match[4] + match[4], 16) >>> 0;
    }
  }
  if (matchers.hsl) {
    if (match = matchers.hsl.exec(color)) {
      return (hslToRgb(parse360(match[1]), parsePercentage(match[2]), parsePercentage(match[3])) | 255) >>> 0;
    }
  }
  if (matchers.hsla) {
    if (match = matchers.hsla.exec(color)) {
      return (hslToRgb(parse360(match[1]), parsePercentage(match[2]), parsePercentage(match[3])) | parse1(match[4])) >>> 0;
    }
  }
  if (matchers.oklch) {
    if (match = matchers.oklch.exec(color)) {
      const L = parseModernComponent(match[1], 1);
      const C = parseModernComponent(match[2], 0.4);
      const H = parseHueAngle(match[3]);
      const alpha = parseModernAlpha(match[4]);
      const hRad = H * Math.PI / 180;
      const [r, g, b2] = oklabToSrgb(L, C * Math.cos(hRad), C * Math.sin(hRad));
      return rgbFloatToInt(r, g, b2, alpha);
    }
  }
  if (matchers.oklab) {
    if (match = matchers.oklab.exec(color)) {
      const L = parseModernComponent(match[1], 1);
      const a2 = parseModernComponent(match[2], 0.4);
      const b2 = parseModernComponent(match[3], 0.4);
      const alpha = parseModernAlpha(match[4]);
      const [r, g, bl] = oklabToSrgb(L, a2, b2);
      return rgbFloatToInt(r, g, bl, alpha);
    }
  }
  if (matchers.lab) {
    if (match = matchers.lab.exec(color)) {
      const L = parseModernComponent(match[1], 100);
      const a2 = parseModernComponent(match[2], 125);
      const b2 = parseModernComponent(match[3], 125);
      const alpha = parseModernAlpha(match[4]);
      const [r, g, bl] = labToSrgb(L, a2, b2);
      return rgbFloatToInt(r, g, bl, alpha);
    }
  }
  if (matchers.lch) {
    if (match = matchers.lch.exec(color)) {
      const L = parseModernComponent(match[1], 100);
      const C = parseModernComponent(match[2], 150);
      const H = parseHueAngle(match[3]);
      const alpha = parseModernAlpha(match[4]);
      const hRad = H * Math.PI / 180;
      const [r, g, bl] = labToSrgb(L, C * Math.cos(hRad), C * Math.sin(hRad));
      return rgbFloatToInt(r, g, bl, alpha);
    }
  }
  if (matchers.hwb) {
    if (match = matchers.hwb.exec(color)) {
      const H = parseHueAngle(match[1]);
      const W = parseModernComponent(match[2], 1);
      const B = parseModernComponent(match[3], 1);
      const alpha = parseModernAlpha(match[4]);
      const [r, g, bl] = hwbToSrgb(H / 360, W, B);
      return rgbFloatToInt(r, g, bl, alpha);
    }
  }
  throw new Error(`invalid color string ${color} provided`);
}
var opacity = (c2) => {
  return (c2 >> 24 & 255) / 255;
};
var red = (c2) => {
  return c2 >> 16 & 255;
};
var green = (c2) => {
  return c2 >> 8 & 255;
};
var blue = (c2) => {
  return c2 & 255;
};
var rgbaColor = (r, g, b2, alpha) => {
  return `rgba(${r}, ${g}, ${b2}, ${alpha})`;
};
function processColor(color) {
  const normalizedColor = normalizeColor(color);
  return (normalizedColor << 24 | normalizedColor >>> 8) >>> 0;
}
var interpolateColorsRGB = (value, inputRange, colors) => {
  const [r, g, b2, a2] = [red, green, blue, opacity].map((f) => {
    const unrounded = interpolate(value, inputRange, colors.map((c2) => f(c2)), {
      extrapolateLeft: "clamp",
      extrapolateRight: "clamp"
    });
    if (f === opacity) {
      return Number(unrounded.toFixed(3));
    }
    return Math.round(unrounded);
  });
  return rgbaColor(r, g, b2, a2);
};
var interpolateColors = (input, inputRange, outputRange) => {
  if (typeof input === "undefined") {
    throw new TypeError("input can not be undefined");
  }
  if (typeof inputRange === "undefined") {
    throw new TypeError("inputRange can not be undefined");
  }
  if (typeof outputRange === "undefined") {
    throw new TypeError("outputRange can not be undefined");
  }
  if (inputRange.length !== outputRange.length) {
    throw new TypeError("inputRange (" + inputRange.length + " values provided) and outputRange (" + outputRange.length + " values provided) must have the same length");
  }
  const processedOutputRange = outputRange.map((c2) => processColor(c2));
  return interpolateColorsRGB(input, inputRange, processedOutputRange);
};
// src/validate-frame.ts
var validateFrame = ({
  allowFloats,
  durationInFrames,
  frame
}) => {
  if (typeof frame === "undefined") {
    throw new TypeError(`Argument missing for parameter "frame"`);
  }
  if (typeof frame !== "number") {
    throw new TypeError(`Argument passed for "frame" is not a number: ${frame}`);
  }
  if (!Number.isFinite(frame)) {
    throw new RangeError(`Frame ${frame} is not finite`);
  }
  if (frame % 1 !== 0 && !allowFloats) {
    throw new RangeError(`Argument for frame must be an integer, but got ${frame}`);
  }
  if (frame < 0 && frame < -durationInFrames) {
    throw new RangeError(`Cannot use frame ${frame}: Duration of composition is ${durationInFrames}, therefore the lowest frame that can be rendered is ${-durationInFrames}`);
  }
  if (frame > durationInFrames - 1) {
    throw new RangeError(`Cannot use frame ${frame}: Duration of composition is ${durationInFrames}, therefore the highest frame that can be rendered is ${durationInFrames - 1}`);
  }
};
// src/series/index.tsx
import { Children, forwardRef as forwardRef11, useMemo as useMemo36 } from "react";

// src/series/flatten-children.tsx
import React37 from "react";
var flattenChildren = (children) => {
  const childrenArray = React37.Children.toArray(children);
  return childrenArray.reduce((flatChildren, child) => {
    if (child.type === React37.Fragment) {
      return flatChildren.concat(flattenChildren(child.props.children));
    }
    flatChildren.push(child);
    return flatChildren;
  }, []);
};

// src/series/is-inside-series.tsx
import React38, { createContext as createContext24 } from "react";
import { jsx as jsx34 } from "react/jsx-runtime";
var IsInsideSeriesContext = createContext24(false);
var IsInsideSeriesContainer = ({ children }) => {
  return /* @__PURE__ */ jsx34(IsInsideSeriesContext.Provider, {
    value: true,
    children
  });
};
var IsNotInsideSeriesProvider = ({ children }) => {
  return /* @__PURE__ */ jsx34(IsInsideSeriesContext.Provider, {
    value: false,
    children
  });
};
var useRequireToBeInsideSeries = () => {
  const isInsideSeries = React38.useContext(IsInsideSeriesContext);
  if (!isInsideSeries) {
    throw new Error("This component must be inside a <Series /> component.");
  }
};

// src/series/index.tsx
import { jsx as jsx35 } from "react/jsx-runtime";
var SeriesSequenceRefForwardingFunction = ({ children }, _ref) => {
  useRequireToBeInsideSeries();
  return /* @__PURE__ */ jsx35(IsNotInsideSeriesProvider, {
    children
  });
};
var SeriesSequence = forwardRef11(SeriesSequenceRefForwardingFunction);
var SeriesInner = (props2) => {
  const childrenValue = useMemo36(() => {
    let startFrame = 0;
    const flattenedChildren = flattenChildren(props2.children);
    return Children.map(flattenedChildren, (child, i) => {
      const castedChild = child;
      if (typeof castedChild === "string") {
        if (castedChild.trim() === "") {
          return null;
        }
        throw new TypeError(`The <Series /> component only accepts a list of <Series.Sequence /> components as its children, but you passed a string "${castedChild}"`);
      }
      if (castedChild.type !== SeriesSequence) {
        throw new TypeError(`The <Series /> component only accepts a list of <Series.Sequence /> components as its children, but got ${castedChild} instead`);
      }
      const debugInfo = `index = ${i}, duration = ${castedChild.props.durationInFrames}`;
      if (!castedChild?.props.children) {
        throw new TypeError(`A <Series.Sequence /> component (${debugInfo}) was detected to not have any children. Delete it to fix this error.`);
      }
      const durationInFramesProp = castedChild.props.durationInFrames;
      const {
        durationInFrames,
        children: _children,
        from,
        name,
        ...passedProps
      } = castedChild.props;
      if (i !== flattenedChildren.length - 1 || durationInFramesProp !== Infinity) {
        validateDurationInFrames(durationInFramesProp, {
          component: `of a <Series.Sequence /> component`,
          allowFloats: true
        });
      }
      const offset = castedChild.props.offset ?? 0;
      if (Number.isNaN(offset)) {
        throw new TypeError(`The "offset" property of a <Series.Sequence /> must not be NaN, but got NaN (${debugInfo}).`);
      }
      if (!Number.isFinite(offset)) {
        throw new TypeError(`The "offset" property of a <Series.Sequence /> must be finite, but got ${offset} (${debugInfo}).`);
      }
      if (offset % 1 !== 0) {
        throw new TypeError(`The "offset" property of a <Series.Sequence /> must be finite, but got ${offset} (${debugInfo}).`);
      }
      const currentStartFrame = startFrame + offset;
      startFrame += durationInFramesProp + offset;
      return /* @__PURE__ */ jsx35(Sequence, {
        name: name || "<Series.Sequence>",
        from: currentStartFrame,
        durationInFrames: durationInFramesProp,
        ...passedProps,
        ref: castedChild.ref,
        children: child
      });
    });
  }, [props2.children]);
  return /* @__PURE__ */ jsx35(IsInsideSeriesContainer, {
    children: /* @__PURE__ */ jsx35(Sequence, {
      layout: "none",
      name: "<Series>",
      ...props2,
      children: childrenValue
    })
  });
};
var Series = Object.assign(wrapInSchema(SeriesInner, sequenceSchemaDefaultLayoutNone), {
  Sequence: SeriesSequence
});
addSequenceStackTraces(Series);
addSequenceStackTraces(SeriesSequence);
// src/validation/validation-spring-duration.ts
var validateSpringDuration = (dur) => {
  if (typeof dur === "undefined") {
    return;
  }
  if (typeof dur !== "number") {
    throw new TypeError(`A "duration" of a spring must be a "number" but is "${typeof dur}"`);
  }
  if (Number.isNaN(dur)) {
    throw new TypeError('A "duration" of a spring is NaN, which it must not be');
  }
  if (!Number.isFinite(dur)) {
    throw new TypeError('A "duration" of a spring must be finite, but is ' + dur);
  }
  if (dur <= 0) {
    throw new TypeError('A "duration" of a spring must be positive, but is ' + dur);
  }
};

// src/spring/spring-utils.ts
var defaultSpringConfig = {
  damping: 10,
  mass: 1,
  stiffness: 100,
  overshootClamping: false
};
var advanceCache = {};
function advance({
  animation,
  now,
  config
}) {
  const { toValue, lastTimestamp, current, velocity } = animation;
  const deltaTime = Math.min(now - lastTimestamp, 64);
  if (config.damping <= 0) {
    throw new Error("Spring damping must be greater than 0, otherwise the spring() animation will never end, causing an infinite loop.");
  }
  const c2 = config.damping;
  const m = config.mass;
  const k = config.stiffness;
  const cacheKey = [
    toValue,
    lastTimestamp,
    current,
    velocity,
    c2,
    m,
    k,
    now
  ].join("-");
  if (advanceCache[cacheKey]) {
    return advanceCache[cacheKey];
  }
  const v0 = -velocity;
  const x0 = toValue - current;
  const zeta = c2 / (2 * Math.sqrt(k * m));
  const omega0 = Math.sqrt(k / m);
  const omega1 = omega0 * Math.sqrt(1 - zeta ** 2);
  const t = deltaTime / 1000;
  const sin1 = Math.sin(omega1 * t);
  const cos1 = Math.cos(omega1 * t);
  const underDampedEnvelope = Math.exp(-zeta * omega0 * t);
  const underDampedFrag1 = underDampedEnvelope * (sin1 * ((v0 + zeta * omega0 * x0) / omega1) + x0 * cos1);
  const underDampedPosition = toValue - underDampedFrag1;
  const underDampedVelocity = zeta * omega0 * underDampedFrag1 - underDampedEnvelope * (cos1 * (v0 + zeta * omega0 * x0) - omega1 * x0 * sin1);
  const criticallyDampedEnvelope = Math.exp(-omega0 * t);
  const criticallyDampedPosition = toValue - criticallyDampedEnvelope * (x0 + (v0 + omega0 * x0) * t);
  const criticallyDampedVelocity = criticallyDampedEnvelope * (v0 * (t * omega0 - 1) + t * x0 * omega0 * omega0);
  const animationNode = {
    toValue,
    prevPosition: current,
    lastTimestamp: now,
    current: zeta < 1 ? underDampedPosition : criticallyDampedPosition,
    velocity: zeta < 1 ? underDampedVelocity : criticallyDampedVelocity
  };
  advanceCache[cacheKey] = animationNode;
  return animationNode;
}
var calculationCache = {};
function springCalculation({
  frame,
  fps,
  config = {}
}) {
  const from = 0;
  const to = 1;
  const cacheKey = [
    frame,
    fps,
    config.damping,
    config.mass,
    config.overshootClamping,
    config.stiffness
  ].join("-");
  if (calculationCache[cacheKey]) {
    return calculationCache[cacheKey];
  }
  let animation = {
    lastTimestamp: 0,
    current: from,
    toValue: to,
    velocity: 0,
    prevPosition: 0
  };
  const frameClamped = Math.max(0, frame);
  const unevenRest = frameClamped % 1;
  for (let f = 0;f <= Math.floor(frameClamped); f++) {
    if (f === Math.floor(frameClamped)) {
      f += unevenRest;
    }
    const time = f / fps * 1000;
    animation = advance({
      animation,
      now: time,
      config: {
        ...defaultSpringConfig,
        ...config
      }
    });
  }
  calculationCache[cacheKey] = animation;
  return animation;
}

// src/spring/measure-spring.ts
var cache = new Map;
function measureSpring({
  fps,
  config = {},
  threshold = 0.005
}) {
  if (typeof threshold !== "number") {
    throw new TypeError(`threshold must be a number, got ${threshold} of type ${typeof threshold}`);
  }
  if (threshold === 0) {
    return Infinity;
  }
  if (threshold === 1) {
    return 0;
  }
  if (isNaN(threshold)) {
    throw new TypeError("Threshold is NaN");
  }
  if (!Number.isFinite(threshold)) {
    throw new TypeError("Threshold is not finite");
  }
  if (threshold < 0) {
    throw new TypeError("Threshold is below 0");
  }
  const cacheKey = [
    fps,
    config.damping,
    config.mass,
    config.overshootClamping,
    config.stiffness,
    threshold
  ].join("-");
  if (cache.has(cacheKey)) {
    return cache.get(cacheKey);
  }
  validateFps(fps, "to the measureSpring() function", false);
  let frame = 0;
  let finishedFrame = 0;
  const calc = () => {
    return springCalculation({
      fps,
      frame,
      config
    });
  };
  let animation = calc();
  const calcDifference = () => {
    return Math.abs(animation.current - animation.toValue);
  };
  let difference = calcDifference();
  while (difference >= threshold) {
    frame++;
    animation = calc();
    difference = calcDifference();
  }
  finishedFrame = frame;
  for (let i = 0;i < 20; i++) {
    frame++;
    animation = calc();
    difference = calcDifference();
    if (difference >= threshold) {
      i = 0;
      finishedFrame = frame + 1;
    }
  }
  cache.set(cacheKey, finishedFrame);
  return finishedFrame;
}

// src/spring/index.ts
function spring({
  frame: passedFrame,
  fps,
  config = {},
  from = 0,
  to = 1,
  durationInFrames: passedDurationInFrames,
  durationRestThreshold,
  delay = 0,
  reverse = false
}) {
  validateSpringDuration(passedDurationInFrames);
  validateFrame({
    frame: passedFrame,
    durationInFrames: Infinity,
    allowFloats: true
  });
  validateFps(fps, "to spring()", false);
  const needsToCalculateNaturalDuration = reverse || typeof passedDurationInFrames !== "undefined";
  const naturalDuration = needsToCalculateNaturalDuration ? measureSpring({
    fps,
    config,
    threshold: durationRestThreshold
  }) : undefined;
  const naturalDurationGetter = needsToCalculateNaturalDuration ? {
    get: () => naturalDuration
  } : {
    get: () => {
      throw new Error("did not calculate natural duration, this is an error with Remotion. Please report");
    }
  };
  const reverseProcessed = reverse ? (passedDurationInFrames ?? naturalDurationGetter.get()) - passedFrame : passedFrame;
  const delayProcessed = reverseProcessed + (reverse ? delay : -delay);
  const durationProcessed = passedDurationInFrames === undefined ? delayProcessed : delayProcessed / (passedDurationInFrames / naturalDurationGetter.get());
  if (passedDurationInFrames && delayProcessed > passedDurationInFrames) {
    return to;
  }
  const spr = springCalculation({
    fps,
    frame: durationProcessed,
    config
  });
  const inner = config.overshootClamping ? to >= from ? Math.min(spr.current, to) : Math.max(spr.current, to) : spr.current;
  const interpolated = from === 0 && to === 1 ? inner : interpolate(inner, [0, 1], [from, to]);
  return interpolated;
}
// src/static-file.ts
var problematicCharacters = {
  "%3A": ":",
  "%2F": "/",
  "%3F": "?",
  "%23": "#",
  "%5B": "[",
  "%5D": "]",
  "%40": "@",
  "%21": "!",
  "%24": "$",
  "%26": "&",
  "%27": "'",
  "%28": "(",
  "%29": ")",
  "%2A": "*",
  "%2B": "+",
  "%2C": ",",
  "%3B": ";"
};
var didWarn2 = {};
var warnOnce3 = (message) => {
  if (didWarn2[message]) {
    return;
  }
  console.warn(message);
  didWarn2[message] = true;
};
var includesHexOfUnsafeChar = (path) => {
  for (const key of Object.keys(problematicCharacters)) {
    if (path.includes(key)) {
      return { containsHex: true, hexCode: key };
    }
  }
  return { containsHex: false };
};
var trimLeadingSlash = (path) => {
  if (path.startsWith("/")) {
    return trimLeadingSlash(path.substring(1));
  }
  return path;
};
var inner = (path) => {
  if (typeof window !== "undefined" && window.remotion_staticBase) {
    if (path.startsWith(window.remotion_staticBase)) {
      throw new Error(`The value "${path}" is already prefixed with the static base ${window.remotion_staticBase}. You don't need to call staticFile() on it.`);
    }
    return `${window.remotion_staticBase}/${trimLeadingSlash(path)}`;
  }
  return `/${trimLeadingSlash(path)}`;
};
var encodeBySplitting = (path) => {
  const splitBySlash = path.split("/");
  const encodedArray = splitBySlash.map((element) => {
    return encodeURIComponent(element);
  });
  const merged = encodedArray.join("/");
  return merged;
};
var staticFile = (path) => {
  if (path === null) {
    throw new TypeError("null was passed to staticFile()");
  }
  if (typeof path === "undefined") {
    throw new TypeError("undefined was passed to staticFile()");
  }
  if (path.startsWith("http://") || path.startsWith("https://")) {
    throw new TypeError(`staticFile() does not support remote URLs - got "${path}". Instead, pass the URL without wrapping it in staticFile(). See: https://remotion.dev/docs/staticfile-remote-urls`);
  }
  if (path.startsWith("..") || path.startsWith("./")) {
    throw new TypeError(`staticFile() does not support relative paths - got "${path}". Instead, pass the name of a file that is inside the public/ folder. See: https://remotion.dev/docs/staticfile-relative-paths`);
  }
  if (path.startsWith("/Users") || path.startsWith("/home") || path.startsWith("/tmp") || path.startsWith("/etc") || path.startsWith("/opt") || path.startsWith("/var") || path.startsWith("C:") || path.startsWith("D:") || path.startsWith("E:")) {
    throw new TypeError(`staticFile() does not support absolute paths - got "${path}". Instead, pass the name of a file that is inside the public/ folder. See: https://remotion.dev/docs/staticfile-relative-paths`);
  }
  if (path.startsWith("public/")) {
    throw new TypeError(`Do not include the public/ prefix when using staticFile() - got "${path}". See: https://remotion.dev/docs/staticfile-relative-paths`);
  }
  const includesHex = includesHexOfUnsafeChar(path);
  if (includesHex.containsHex) {
    warnOnce3(`WARNING: You seem to pass an already encoded path (path contains ${includesHex.hexCode}). Since Remotion 4.0, the encoding is done by staticFile() itself. You may want to remove a encodeURIComponent() wrapping.`);
  }
  const preprocessed = encodeBySplitting(path);
  const preparsed = inner(preprocessed);
  if (!preparsed.startsWith("/")) {
    return `/${preparsed}`;
  }
  return preparsed;
};
// src/Still.tsx
import React40 from "react";
var Still = (props2) => {
  const newProps = {
    ...props2,
    durationInFrames: 1,
    fps: 1
  };
  return React40.createElement(Composition, newProps);
};
// src/video/html5-video.tsx
import { forwardRef as forwardRef13, useCallback as useCallback19, useContext as useContext37 } from "react";

// src/video/VideoForRendering.tsx
import {
  forwardRef as forwardRef12,
  useContext as useContext36,
  useEffect as useEffect19,
  useImperativeHandle as useImperativeHandle9,
  useLayoutEffect as useLayoutEffect12,
  useMemo as useMemo37,
  useRef as useRef23
} from "react";

// src/video/seek-until-right.ts
var roundTo6Commas = (num) => {
  return Math.round(num * 1e5) / 1e5;
};
var seekToTime = ({
  element,
  desiredTime,
  logLevel,
  mountTime
}) => {
  if (isApproximatelyTheSame(element.currentTime, desiredTime)) {
    return {
      wait: Promise.resolve(desiredTime),
      cancel: () => {}
    };
  }
  seek({
    logLevel,
    mediaRef: element,
    time: desiredTime,
    why: "Seeking during rendering",
    mountTime
  });
  let cancel;
  let cancelSeeked = null;
  const prom = new Promise((resolve) => {
    cancel = element.requestVideoFrameCallback((now, metadata) => {
      const displayIn = metadata.expectedDisplayTime - now;
      if (displayIn <= 0) {
        resolve(metadata.mediaTime);
        return;
      }
      setTimeout(() => {
        resolve(metadata.mediaTime);
      }, displayIn + 150);
    });
  });
  const waitForSeekedEvent = new Promise((resolve) => {
    const onDone = () => {
      resolve();
    };
    element.addEventListener("seeked", onDone, {
      once: true
    });
    cancelSeeked = () => {
      element.removeEventListener("seeked", onDone);
    };
  });
  return {
    wait: Promise.all([prom, waitForSeekedEvent]).then(([time]) => time),
    cancel: () => {
      cancelSeeked?.();
      element.cancelVideoFrameCallback(cancel);
    }
  };
};
var seekToTimeMultipleUntilRight = ({
  element,
  desiredTime,
  fps,
  logLevel,
  mountTime
}) => {
  const threshold = 1 / fps / 2;
  let currentCancel = () => {
    return;
  };
  if (Number.isFinite(element.duration) && element.currentTime >= element.duration && desiredTime >= element.duration) {
    return {
      prom: Promise.resolve(),
      cancel: () => {}
    };
  }
  const prom = new Promise((resolve, reject) => {
    const firstSeek = seekToTime({
      element,
      desiredTime: desiredTime + threshold,
      logLevel,
      mountTime
    });
    firstSeek.wait.then((seekedTo) => {
      const difference = Math.abs(desiredTime - seekedTo);
      if (difference <= threshold) {
        return resolve();
      }
      const sign = desiredTime > seekedTo ? 1 : -1;
      const newSeek = seekToTime({
        element,
        desiredTime: seekedTo + threshold * sign,
        logLevel,
        mountTime
      });
      currentCancel = newSeek.cancel;
      newSeek.wait.then((newTime) => {
        const newDifference = Math.abs(desiredTime - newTime);
        if (roundTo6Commas(newDifference) <= roundTo6Commas(threshold)) {
          return resolve();
        }
        const thirdSeek = seekToTime({
          element,
          desiredTime: desiredTime + threshold,
          logLevel,
          mountTime
        });
        currentCancel = thirdSeek.cancel;
        return thirdSeek.wait.then(() => {
          resolve();
        }).catch((err) => {
          reject(err);
        });
      }).catch((err) => {
        reject(err);
      });
    });
    currentCancel = firstSeek.cancel;
  });
  return {
    prom,
    cancel: () => {
      currentCancel();
    }
  };
};

// src/video/VideoForRendering.tsx
import { jsx as jsx36 } from "react/jsx-runtime";
var VideoForRenderingForwardFunction = ({
  onError,
  volume: volumeProp,
  allowAmplificationDuringRender,
  playbackRate,
  onDuration,
  toneFrequency,
  name,
  acceptableTimeShiftInSeconds,
  delayRenderRetries,
  delayRenderTimeoutInMilliseconds,
  loopVolumeCurveBehavior,
  audioStreamIndex,
  onVideoFrame,
  ...props2
}, ref) => {
  const absoluteFrame = useTimelinePosition();
  const frame = useCurrentFrame();
  const volumePropsFrame = useFrameForVolumeProp(loopVolumeCurveBehavior ?? "repeat");
  const videoConfig = useUnsafeVideoConfig();
  const videoRef = useRef23(null);
  const sequenceContext = useContext36(SequenceContext);
  const mediaStartsAt = useMediaStartsAt();
  const environment = useRemotionEnvironment();
  const logLevel = useLogLevel();
  const mountTime = useMountTime();
  const { delayRender: delayRender2, continueRender: continueRender2 } = useDelayRender();
  const { registerRenderAsset, unregisterRenderAsset } = useContext36(RenderAssetManager);
  const id = useMemo37(() => `video-${random(props2.src ?? "")}-${sequenceContext?.cumulatedFrom}-${sequenceContext?.relativeFrom}-${sequenceContext?.durationInFrames}`, [
    props2.src,
    sequenceContext?.cumulatedFrom,
    sequenceContext?.relativeFrom,
    sequenceContext?.durationInFrames
  ]);
  if (!videoConfig) {
    throw new Error("No video config found");
  }
  const volume = evaluateVolume({
    volume: volumeProp,
    frame: volumePropsFrame,
    mediaVolume: 1
  });
  warnAboutTooHighVolume(volume);
  useEffect19(() => {
    if (!props2.src) {
      throw new Error("No src passed");
    }
    if (props2.muted) {
      return;
    }
    if (volume <= 0) {
      return;
    }
    if (!window.remotion_audioEnabled) {
      return;
    }
    registerRenderAsset({
      type: "video",
      src: getAbsoluteSrc(props2.src),
      id,
      frame: absoluteFrame,
      volume,
      mediaFrame: frame,
      playbackRate: playbackRate ?? 1,
      toneFrequency: toneFrequency ?? 1,
      audioStartFrame: Math.max(0, -(sequenceContext?.relativeFrom ?? 0)),
      audioStreamIndex: audioStreamIndex ?? 0
    });
    return () => unregisterRenderAsset(id);
  }, [
    props2.muted,
    props2.src,
    registerRenderAsset,
    id,
    unregisterRenderAsset,
    volume,
    frame,
    absoluteFrame,
    playbackRate,
    toneFrequency,
    sequenceContext?.relativeFrom,
    audioStreamIndex
  ]);
  useImperativeHandle9(ref, () => {
    return videoRef.current;
  }, []);
  useEffect19(() => {
    if (!window.remotion_videoEnabled) {
      return;
    }
    const { current } = videoRef;
    if (!current) {
      return;
    }
    const currentTime = getMediaTime({
      frame,
      playbackRate: playbackRate || 1,
      startFrom: -mediaStartsAt,
      fps: videoConfig.fps
    });
    const handle = delayRender2(`Rendering <Html5Video /> with src="${props2.src}" at time ${currentTime}`, {
      retries: delayRenderRetries ?? undefined,
      timeoutInMilliseconds: delayRenderTimeoutInMilliseconds ?? undefined
    });
    if (window.process?.env?.NODE_ENV === "test") {
      continueRender2(handle);
      return;
    }
    if (isApproximatelyTheSame(current.currentTime, currentTime)) {
      if (current.readyState >= 2) {
        continueRender2(handle);
        return;
      }
      const loadedDataHandler = () => {
        continueRender2(handle);
      };
      current.addEventListener("loadeddata", loadedDataHandler, { once: true });
      return () => {
        current.removeEventListener("loadeddata", loadedDataHandler);
      };
    }
    const endedHandler = () => {
      continueRender2(handle);
    };
    const seek2 = seekToTimeMultipleUntilRight({
      element: current,
      desiredTime: currentTime,
      fps: videoConfig.fps,
      logLevel,
      mountTime
    });
    seek2.prom.then(() => {
      continueRender2(handle);
    });
    current.addEventListener("ended", endedHandler, { once: true });
    const errorHandler = () => {
      if (current?.error) {
        console.error("Error occurred in video", current?.error);
        if (onError) {
          return;
        }
        throw new MediaPlaybackError({
          message: `The browser threw an error while playing the video ${props2.src}: Code ${current.error.code} - ${current?.error?.message}. See https://remotion.dev/docs/media-playback-error for help. Pass an onError() prop to handle the error.`,
          src: props2.src
        });
      } else {
        throw new MediaPlaybackError({
          message: "The browser threw an error",
          src: props2.src
        });
      }
    };
    current.addEventListener("error", errorHandler, { once: true });
    return () => {
      seek2.cancel();
      current.removeEventListener("ended", endedHandler);
      current.removeEventListener("error", errorHandler);
      continueRender2(handle);
    };
  }, [
    volumePropsFrame,
    props2.src,
    playbackRate,
    videoConfig.fps,
    frame,
    mediaStartsAt,
    onError,
    delayRenderRetries,
    delayRenderTimeoutInMilliseconds,
    logLevel,
    mountTime,
    continueRender2,
    delayRender2
  ]);
  const { src } = props2;
  if (environment.isRendering) {
    useLayoutEffect12(() => {
      if (window.process?.env?.NODE_ENV === "test") {
        return;
      }
      const newHandle = delayRender2("Loading <Html5Video> duration with src=" + src, {
        retries: delayRenderRetries ?? undefined,
        timeoutInMilliseconds: delayRenderTimeoutInMilliseconds ?? undefined
      });
      const { current } = videoRef;
      const didLoad = () => {
        if (current?.duration) {
          onDuration(src, current.duration);
        }
        continueRender2(newHandle);
      };
      if (current?.duration) {
        onDuration(src, current.duration);
        continueRender2(newHandle);
      } else {
        current?.addEventListener("loadedmetadata", didLoad, { once: true });
      }
      return () => {
        current?.removeEventListener("loadedmetadata", didLoad);
        continueRender2(newHandle);
      };
    }, [
      src,
      onDuration,
      delayRenderRetries,
      delayRenderTimeoutInMilliseconds,
      continueRender2,
      delayRender2
    ]);
  }
  return /* @__PURE__ */ jsx36("video", {
    ref: videoRef,
    disableRemotePlayback: true,
    ...props2
  });
};
var VideoForRendering = forwardRef12(VideoForRenderingForwardFunction);

// src/video/html5-video.tsx
import { jsx as jsx37 } from "react/jsx-runtime";
var VideoForwardingFunction = (props2, ref) => {
  const {
    startFrom,
    endAt,
    trimBefore,
    trimAfter,
    name,
    pauseWhenBuffering,
    stack,
    _remotionInternalNativeLoopPassed,
    showInTimeline,
    onAutoPlayError,
    ...otherProps
  } = props2;
  const { loop, ...propsOtherThanLoop } = props2;
  const { fps } = useVideoConfig();
  const environment = useRemotionEnvironment();
  if (environment.isClientSideRendering) {
    throw new Error("<Html5Video> is not supported in @remotion/web-renderer. Use <Video> from @remotion/media instead. See https://remotion.dev/docs/client-side-rendering/limitations");
  }
  const { durations, setDurations } = useContext37(DurationsContext);
  if (typeof ref === "string") {
    throw new Error("string refs are not supported");
  }
  if (typeof props2.src !== "string") {
    throw new TypeError(`The \`<Html5Video>\` tag requires a string for \`src\`, but got ${JSON.stringify(props2.src)} instead.`);
  }
  const preloadedSrc = usePreload(props2.src);
  const onDuration = useCallback19((src, durationInSeconds) => {
    setDurations({ type: "got-duration", durationInSeconds, src });
  }, [setDurations]);
  const onVideoFrame = useCallback19(() => {}, []);
  const durationFetched = durations[getAbsoluteSrc(preloadedSrc)] ?? durations[getAbsoluteSrc(props2.src)];
  validateMediaTrimProps({ startFrom, endAt, trimBefore, trimAfter });
  const { trimBeforeValue, trimAfterValue } = resolveTrimProps({
    startFrom,
    endAt,
    trimBefore,
    trimAfter
  });
  if (loop && durationFetched !== undefined) {
    if (!Number.isFinite(durationFetched)) {
      return /* @__PURE__ */ jsx37(Html5Video, {
        ...propsOtherThanLoop,
        ref,
        stack,
        _remotionInternalNativeLoopPassed: true
      });
    }
    const mediaDuration = durationFetched * fps;
    return /* @__PURE__ */ jsx37(Loop, {
      durationInFrames: calculateMediaDuration({
        trimAfter: trimAfterValue,
        mediaDurationInFrames: mediaDuration,
        playbackRate: props2.playbackRate ?? 1,
        trimBefore: trimBeforeValue
      }),
      layout: "none",
      name,
      showInTimeline: false,
      children: /* @__PURE__ */ jsx37(Html5Video, {
        ...propsOtherThanLoop,
        ref,
        stack,
        _remotionInternalNativeLoopPassed: true
      })
    });
  }
  if (typeof trimBeforeValue !== "undefined" || typeof trimAfterValue !== "undefined") {
    return /* @__PURE__ */ jsx37(Sequence, {
      layout: "none",
      from: 0 - (trimBeforeValue ?? 0),
      showInTimeline: false,
      durationInFrames: trimAfterValue === undefined ? undefined : trimAfterValue / (props2.playbackRate ?? 1),
      name,
      children: /* @__PURE__ */ jsx37(Html5Video, {
        pauseWhenBuffering: pauseWhenBuffering ?? false,
        ...otherProps,
        ref,
        stack
      })
    });
  }
  validateMediaProps({ playbackRate: props2.playbackRate, volume: props2.volume }, "Html5Video");
  if (environment.isRendering) {
    return /* @__PURE__ */ jsx37(VideoForRendering, {
      onDuration,
      onVideoFrame: onVideoFrame ?? null,
      ...otherProps,
      ref
    });
  }
  return /* @__PURE__ */ jsx37(VideoForPreview, {
    onlyWarnForMediaSeekingError: false,
    ...otherProps,
    ref,
    onVideoFrame: null,
    pauseWhenBuffering: pauseWhenBuffering ?? false,
    onDuration,
    _remotionInternalStack: stack ?? null,
    _remotionInternalNativeLoopPassed: _remotionInternalNativeLoopPassed ?? false,
    showInTimeline: showInTimeline ?? true,
    onAutoPlayError: onAutoPlayError ?? undefined
  });
};
var Html5Video = forwardRef13(VideoForwardingFunction);
addSequenceStackTraces(Html5Video);
var Video = Html5Video;
// src/index.ts
checkMultipleRemotionVersions();
var Experimental = {
  Clipper,
  Null,
  Solid,
  useIsPlayer
};
var proxyObj = {};
var Config = new Proxy(proxyObj, {
  get(_, prop) {
    if (prop === "Bundling" || prop === "Rendering" || prop === "Log" || prop === "Puppeteer" || prop === "Output") {
      return Config;
    }
    return () => {
      console.warn("⚠️  The CLI configuration has been extracted from Remotion Core.");
      console.warn("Update the import from the config file:");
      console.warn();
      console.warn("- Delete:");
      console.warn('import {Config} from "remotion";');
      console.warn("+ Replace:");
      console.warn('import {Config} from "@remotion/cli/config";');
      console.warn();
      console.warn("For more information, see https://www.remotion.dev/docs/4-0-migration.");
      process.exit(1);
    };
  }
});
Sequence.displayName = "Sequence";
addSequenceStackTraces(Sequence);
addSequenceStackTraces(Composition);
export {
  watchStaticFile,
  useVideoConfig,
  useRemotionEnvironment,
  useDelayRender,
  useCurrentScale,
  useCurrentFrame,
  useBufferState,
  staticFile,
  spring,
  registerRoot,
  random,
  prefetch,
  measureSpring,
  isHtmlInCanvasSupported,
  interpolateColors,
  interpolate,
  getStaticFiles,
  getRemotionEnvironment,
  getInputProps,
  delayRender,
  continueRender,
  cancelRender,
  Video,
  VERSION,
  Still,
  Series,
  Sequence,
  OffthreadVideo,
  MediaPlaybackError,
  Loop,
  Internals,
  Img,
  IFrame,
  HtmlInCanvas,
  Html5Video,
  Html5Audio,
  Freeze,
  FolderContext,
  Folder,
  Experimental,
  Easing,
  Config,
  Composition,
  Audio,
  Artifact,
  AnimatedImage,
  AbsoluteFill
};
