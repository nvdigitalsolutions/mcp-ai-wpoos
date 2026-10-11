"use strict";
(() => {
  // src/page-agent-bridge.js
  (function(config) {
    "use strict";
    var agent = null;
    var active = false;
    function init() {
      if (!config || !config.enabled) {
        return;
      }
      if (!config.model || !config.baseURL) {
        console.warn("[NV oOS Page Agent] Missing required configuration (model or baseURL).");
        return;
      }
      if (typeof window.PageAgent === "undefined") {
        console.warn("[NV oOS Page Agent] PageAgent global not found \u2014 is page-agent.bundle.js loaded?");
        return;
      }
      try {
        agent = new window.PageAgent({
          model: config.model,
          baseURL: config.baseURL,
          apiKey: config.apiKey,
          language: config.language || "en-US",
          maxSteps: config.maxSteps || 50,
          systemPrompt: "You are a helpful WordPress page assistant. Interact with the current web page based on natural language instructions. Be precise and efficient."
        });
        if (window.wpMcpAiJobBus) {
          window.wpMcpAiJobBus.on("page-agent:execute", handleExecute);
          window.wpMcpAiJobBus.on("page-agent:abort", handleAbort);
        }
        window.wpMcpAiPageAgentInstance = agent;
        active = true;
        if (window.wpMcpAiJobBus) {
          window.wpMcpAiJobBus.emit("page-agent:ready", {
            model: config.model,
            language: config.language
          });
        }
      } catch (error) {
        console.error("[NV oOS Page Agent] Initialization failed:", error);
      }
    }
    async function handleExecute(payload) {
      var instruction = payload.instruction;
      var requestId = payload.requestId;
      var waitForResult = payload.waitForResult !== false;
      var maxSteps = payload.maxSteps || 0;
      if (!agent || !active) {
        emitError(requestId, "Page Agent is not initialized.");
        return;
      }
      if (!instruction) {
        emitError(requestId, "No instruction provided.");
        return;
      }
      try {
        var executeOptions = {};
        if (maxSteps > 0) {
          executeOptions.maxSteps = maxSteps;
        }
        var result = await agent.execute(instruction, executeOptions);
        if (waitForResult && window.wpMcpAiJobBus) {
          window.wpMcpAiJobBus.emit("page-agent:result", {
            requestId,
            success: true,
            result,
            instruction
          });
        }
      } catch (error) {
        if (waitForResult && window.wpMcpAiJobBus) {
          window.wpMcpAiJobBus.emit("page-agent:result", {
            requestId,
            success: false,
            error: error.message || "Unknown error",
            instruction
          });
        }
      }
    }
    function handleAbort() {
      if (agent && typeof agent.stop === "function") {
        try {
          agent.stop();
        } catch (error) {
        }
      }
    }
    function emitError(requestId, message) {
      if (window.wpMcpAiJobBus) {
        window.wpMcpAiJobBus.emit("page-agent:result", {
          requestId,
          success: false,
          error: message
        });
      }
    }
    function destroy() {
      if (agent && typeof agent.destroy === "function") {
        try {
          agent.destroy();
        } catch (error) {
        }
      }
      if (window.wpMcpAiJobBus) {
        window.wpMcpAiJobBus.off("page-agent:execute", handleExecute);
        window.wpMcpAiJobBus.off("page-agent:abort", handleAbort);
      }
      agent = null;
      active = false;
    }
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", init);
    } else {
      init();
    }
    window.addEventListener("beforeunload", destroy);
  })(window.wpMcpAiPageAgent || {});
})();
