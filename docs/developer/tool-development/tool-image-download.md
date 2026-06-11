# Downloading Images from Tool Outputs

When a tool invocation generates an image, the retrieval flow depends on the SDK.

## OpenAI Assistants/Responses API

Tools return images as `image_file` blocks that reference `file_id` values. Download them with the Files API.

### Python

```python
from openai import OpenAI
client = OpenAI()

response = client.responses.retrieve("RESPONSE_ID")

for item in response.output:
    if item.type == "message":
        for block in item.content:
            if block["type"] == "image_file":
                fid = block["image_file"]["file_id"]
                file_stream = client.files.content(fid)
                with open("gemini_image.png", "wb") as f:
                    f.write(file_stream.read())
                print("Saved gemini_image.png")
```

### Node.js

```js
import OpenAI from "openai";
const openai = new OpenAI();

const response = await openai.responses.retrieve("RESPONSE_ID");

for (const item of response.output ?? []) {
  if (item.type === "message") {
    for (const block of item.content ?? []) {
      if (block.type === "image_file") {
        const fid = block.image_file.file_id;
        const fileResp = await openai.files.content(fid);
        const buffer = Buffer.from(await fileResp.arrayBuffer());
        await import("fs").then(fs => fs.writeFileSync("gemini_image.png", buffer));
        console.log("Saved gemini_image.png");
      }
    }
  }
}
```

Use the response or run ID that contains your tool invocation. If you are working with Assistants threads, list the latest messages and inspect their `content` blocks for `image_file` entries, then download the referenced files as above.

## Google Gemini SDK

Some Gemini tool results include inline base64 image data.

### Python

```python
import base64

b64 = resp.candidates[0].content.parts[0].inline_data.data
with open("gemini_image.png", "wb") as f:
    f.write(base64.b64decode(b64))
print("Saved gemini_image.png")
```

### Node.js

```js
import fs from "fs";

const b64 = resp.candidates[0].content.parts[0].inlineData.data;
fs.writeFileSync("gemini_image.png", Buffer.from(b64, "base64"));
console.log("Saved gemini_image.png");
```

## Generic Attachment URLs

If the tool returns a direct attachment URL, fetch it and stream the response to disk.

```python
import requests

url = tool_output["attachments"][0]["url"]
r = requests.get(url, stream=True)
with open("gemini_image.png", "wb") as f:
    for chunk in r.iter_content(8192):
        f.write(chunk)
```

## Troubleshooting Empty Arguments

Seeing `{}` for the tool "Arguments" is expected when the tool accepts no input parameters. The generated image data is attached to the tool output (as a file reference, inline data, or URL) rather than being embedded in the arguments payload.
