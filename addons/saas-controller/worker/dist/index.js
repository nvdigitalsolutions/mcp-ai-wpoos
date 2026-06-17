// worker/src/index.ts
var index_default = {
  async fetch(request, _env, _ctx) {
    const url = new URL(request.url);
    if (url.pathname === "/healthz") {
      return new Response(JSON.stringify({ ok: true }), {
        headers: { "content-type": "application/json" }
      });
    }
    return new Response("NV oOS Cloud Worker \u2014 scaffolding", { status: 200 });
  }
};
export {
  index_default as default
};
