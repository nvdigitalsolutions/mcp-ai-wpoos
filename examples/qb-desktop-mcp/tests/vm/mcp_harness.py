"""
Minimal MCP test client: starts the QBBridge server over stdio exactly as
Claude Desktop does, calls tools, and records every call and result in a
transcript. Generic -- no company data in here; test scripts
with real data live outside the repository.

Usage (inside the Windows VM, 32-bit Python, QuickBooks open):
    from mcp_harness import run
    async def scenario(h):
        status = await h.call("qb_status")
        booked = await h.book("qb_create_customer", {"name": "Test"})
    run(scenario, transcript_path, server_cmd=[r"C:\\py32\\python.exe", "-m", "qbbridge_mcp.server"])
"""

import asyncio
import json
import time
import traceback

from mcp import ClientSession, StdioServerParameters
from mcp.client.stdio import stdio_client, get_default_environment


class ToolFailed(Exception):
    def __init__(self, tool, message):
        super().__init__(tool + ": " + message)
        self.tool = tool
        self.message = message


class Harness:
    def __init__(self, session, transcript):
        self.s = session
        self.t = transcript
        self.tools = []

    @staticmethod
    def _decode(res):
        sc = getattr(res, "structuredContent", None)
        if sc is not None:
            return sc["result"] if isinstance(sc, dict) and set(sc) == {"result"} else sc
        texts = [c.text for c in res.content if getattr(c, "type", "") == "text"]
        parsed = []
        for t in texts:
            try:
                parsed.append(json.loads(t))
            except ValueError:
                parsed.append(t)
        return parsed[0] if len(parsed) == 1 else parsed

    async def call(self, tool, args=None, expect_error=False, label=None):
        args = args or {}
        t0 = time.time()
        res = await self.s.call_tool(tool, args)
        data = self._decode(res)
        entry = {"label": label, "tool": tool, "args": args, "is_error": bool(res.isError),
                 "seconds": round(time.time() - t0, 2), "result": data}
        self.t.append(entry)
        if res.isError and not expect_error:
            raise ToolFailed(tool, json.dumps(data)[:800])
        if expect_error and not res.isError:
            raise ToolFailed(tool, "expected an error but the call succeeded")
        return data

    async def book(self, tool, args, label=None):
        """Two-step booking: preview (must not book), then confirm with the code."""
        prev = await self.call(tool, args, label=(label or tool) + " [preview]")
        assert prev["status"] == "preview" and prev["booked"] is False, prev
        done = await self.call(tool, dict(args, confirm_code=prev["confirm_code"]), label=(label or tool) + " [confirm]")
        assert done["status"] == "booked", done
        return done


def run(scenario, transcript_path, server_cmd, env=None):
    transcript = []
    summary = {"ok": False}

    async def main():
        e = get_default_environment()
        e.update(env or {})
        params = StdioServerParameters(command=server_cmd[0], args=server_cmd[1:], env=e)
        async with stdio_client(params) as (r, w):
            async with ClientSession(r, w) as session:
                await session.initialize()
                h = Harness(session, transcript)
                h.tools = [t.name for t in (await session.list_tools()).tools]
                transcript.append({"label": "list_tools", "result": h.tools})
                summary.update(await scenario(h) or {})
        summary["ok"] = True

    try:
        asyncio.run(main())
    except BaseException:
        summary["error"] = traceback.format_exc()
    with open(transcript_path, "w", encoding="utf-8") as f:
        json.dump({"summary": summary, "transcript": transcript}, f, indent=1, default=str, ensure_ascii=False)
    return summary
