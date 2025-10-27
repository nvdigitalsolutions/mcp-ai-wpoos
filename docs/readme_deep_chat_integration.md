# Deep Chat Integration

This repository uses **[Deep Chat](https://deepchat.dev/)** — a framework‑agnostic chat UI component for AI apps. Deep Chat can connect to any HTTP/WebSocket API, stream responses, send/receive files, add speech‑to‑text / text‑to‑speech, and works with React, Next.js, Vue/Nuxt, Solid, or vanilla JS.

> Replace **Project Name** and any placeholder URLs/keys below to fit your app.

---

## Table of contents
- [Features](#features)
- [Install](#install)
- [Quick start](#quick-start)
  - [Vanilla JS (CDN)](#vanilla-js-cdn)
  - [React](#react)
  - [Next.js](#nextjs)
- [Connect to your backend](#connect-to-your-backend)
  - [Request & response format](#request--response-format)
  - [Streaming (SSE)](#streaming-sse)
  - [WebSocket](#websocket)
  - [Request body limits](#request-body-limits)
- [Direct connection (prototype only)](#direct-connection-prototype-only)
- [Messages & roles](#messages--roles)
- [Events & methods](#events--methods)
- [Styling the chat](#styling-the-chat)
- [Speech & files](#speech--files)
- [Run models in the browser (optional)](#run-models-in-the-browser-optional)
- [Security notes](#security-notes)
- [Troubleshooting](#troubleshooting)

---

## Features
- Drop‑in chat UI component
- Connect via **HTTP**, **SSE streaming**, or **WebSocket**
- **Send/receive files**, images, audio, etc
- **Speech‑to‑Text** input and **Text‑to‑Speech** for responses
- Works with **any framework** (React/Next/Nuxt/Solid/Vanilla)
- Optional **direct connection** to popular AI APIs for prototyping
- Highly **customizable** styling, avatars, names, modes (focus mode), intro panel

---

## Install

**Package:**
```bash
# vanilla or any framework
npm install deep-chat

# React wrapper
npm install deep-chat-react
```

**CDN (vanilla demo or quick test):**
```html
<script src="https://unpkg.com/deep-chat@2.3.0/dist/deepChat.bundle.js"></script>
```

> Prefer npm packages for real projects. CDN is handy for sandboxes or static demos.

---

## Quick start

### Vanilla JS (CDN)
```html
<!-- 1) Include the bundle -->
<script src="https://unpkg.com/deep-chat@2.3.0/dist/deepChat.bundle.js"></script>

<!-- 2) Drop the element anywhere in your page -->
<deep-chat
  id="chat"
  style="height: 560px; border-radius: 12px"
  connect='{"url": "/api/chat", "method": "POST", "stream": true}'
></deep-chat>
```

### React
```tsx
// App.tsx / App.jsx
import { DeepChat } from "deep-chat-react";

export default function App() {
  return (
    <div style={{ maxWidth: 720, margin: "0 auto" }}>
      <DeepChat
        style={{ height: 560, borderRadius: 12 }}
        connect={{ url: "/api/chat", method: "POST", stream: true }}
      />
    </div>
  );
}
```

### Next.js
> With App Router, mark the page as a client component and lazy‑load if desired.
```tsx
// app/chat/page.tsx
"use client";
import dynamic from "next/dynamic";
const DeepChat = dynamic(() => import("deep-chat-react").then(m => m.DeepChat), { ssr: false });

export default function ChatPage() {
  return (
    <DeepChat style={{ height: 560, borderRadius: 12 }} connect={{ url: "/api/chat", stream: true }} />
  );
}
```

---

## Connect to your backend
Set the `connect` property on the component. At minimum, provide your endpoint `url`. You can also pass `method`, custom `headers`, `credentials`, `stream`, `websocket`, or a custom `handler` function.

```html
<deep-chat connect='{"url": "/api/chat", "method": "POST"}'></deep-chat>
```

### Request & response format
- **Outgoing (text only):** `{ messages: MessageContent[] }`
- **Outgoing (with files):** a `FormData` payload where files are in a `files` array and each text message is stored under keys like `message1`, `message2`, etc.
- **Expected response:** a **Response** object such as `{ text: "..." }`, optionally including `files`, `html`, `error`, or `overwrite`. You may also return an **array** of Response objects for multi‑part non‑streamed replies.

**Minimal Express example (non‑stream):**
```ts
import express from "express";

const app = express();
app.use(express.json());

app.post("/api/chat", async (req, res) => {
  const { messages } = req.body; // latest user msg is last
  const userText = messages?.[messages.length - 1]?.text || "";
  // TODO: call your model/provider here
  const reply = { text: `You said: ${userText}` };
  res.json(reply);
});

app.listen(3000);
```

### Streaming (SSE)
Enable `connect.stream: true` to consume **Server‑Sent Events**. Your server should send `Content-Type: text/event-stream` and lines like:
```
data: {"text":"partial chunk 1"}

```
Finish with a final message or close the stream. (You can also simulate streaming with `stream: { simulation: 6 }` to gradually reveal text without SSE.)

### WebSocket
Set `connect.websocket` to `true` (and a `ws://` or `wss://` URL). Messages exchanged must be **stringified JSON** using the same request/response formats as above.

```html
<deep-chat connect='{"url": "wss://example.com/ws", "websocket": true}'></deep-chat>
```

### Request body limits
To control what goes into the request body, use `requestBodyLimits`:
```html
<deep-chat requestBodyLimits='{"maxMessages": 2, "totalMessagesMaxCharLength": 2000}'></deep-chat>
```

---

## Direct connection (prototype only)
For quick local testing you can connect directly to providers (OpenAI, Anthropic Claude, Gemini, Mistral, Groq, Together, etc.) via `directConnection`. **Do not use this in production** — keys are exposed in the browser. Instead proxy through your server using `connect`.

```html
<deep-chat
  directConnection='{"openAI": {"key": "sk-...", "chat": {"system_prompt": "Assist me"}}}'
></deep-chat>
```

---

## Messages & roles
Each message is a `MessageContent` with optional fields: `role` ("user" | "ai" | custom), `text`, `files`, `html`, `custom`. If `role` is omitted it’s treated as `"ai"`.

Examples of customizing the UI around messages:
```html
<deep-chat
  avatars="true"
  names='{"ai": {"text": "Assistant"}, "user": {"text": "You"}}'
></deep-chat>
```

---

## Events & methods
**Listen to user/AI traffic:**
```html
<deep-chat id="chat"></deep-chat>
<script>
  const el = document.getElementById("chat");
  // Fires when user sends a message AND when a response arrives
  el.onMessage = ({ message, isHistory }) => {
    console.log("onMessage", message, { isHistory });
  };
</script>
```

**Programmatic APIs (selected):**
```js
el.getMessages();       // MessageContent[]
el.clearMessages();     // clear chat (optionally reset)
el.submitUserMessage({ text: "Hello" });
```

---

## Styling the chat
Deep Chat exposes granular style props (input area, bubbles, dropups, avatars/names, focus mode, intro panel, etc.). Example:
```html
<deep-chat
  style="height:560px; border-radius:12px"
  inputAreaStyle='{"backgroundColor":"#f7fbff"}'
  focusMode="true"
></deep-chat>
```

> Code blocks render in a dark panel by default; for syntax highlight, wire up highlight.js in your app.

---

## Speech & files
Enable **speech‑to‑text** and **text‑to‑speech**, and configure file uploads:
```html
<deep-chat
  speechToText='{"webSpeech": true, "button": {"position": "outside-left"}}'
  textToSpeech='{"volume": 0.9}'
  files='{"maxNumberOfFiles": 4, "acceptedFormats": ".png,.jpg,.pdf"}'
></deep-chat>
```

---

## Run models in the browser (optional)
Deep Chat can orchestrate **web models** (e.g., via WebGPU/WebAssembly). You can load a model `onInit` when the component mounts or `onMessage` when the user first sends a message.

```html
<deep-chat webModel='{"onInit": true, "clearCache": false}'></deep-chat>
```

---

## Security notes
- Don’t ship provider API keys in the browser. Use `connect` to call your server; keep credentials server‑side.
- If you need cookies for auth, set `connect.credentials` accordingly (e.g., `"include"`) and handle CORS.
- Sanitize any custom HTML you render via `html` in responses.

---

## Troubleshooting
- **Nothing happens on send** → check your `/api/chat` returns a valid **Response** object (`{ text: "..." }`) and correct status code.
- **Streaming doesn’t render** → confirm `connect.stream: true` and the endpoint sends `text/event-stream` with `data: {"text":"..."}` lines.
- **CORS / cookies** → align `connect.credentials` with your server CORS settings.
- **Mic not working** → browser support for Web Speech API varies; ensure HTTPS and permissions.

---

## Notes for maintainers
- Keep Deep Chat packages up to date (see `deep-chat` / `deep-chat-react` on npm).
- If you add a new provider/model, prefer server‑side integration behind `/api/chat` and leave the UI unchanged.

---

## License
Apache‑2.0 (or your project’s l