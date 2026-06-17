# /clear · /reset · /resume

> **Added in:** v2.1.0 · **Capability:** `read`

## Synopsis

```
/clear
/reset
/resume
```

Session lifecycle commands. These return action signals to the chat client — no server-side transcript is modified.

## Commands

### /clear

Sends a `clear_chat` action to the front end. The visible chat window is cleared.

```
/clear
```

**Response:**
```json
{ "success": true, "message": "Chat cleared.", "action": "clear_chat" }
```

### /reset

Fires the `wp_mcp_ai_session_reset` WordPress action (allowing other code to hook in) and sends a `reset_session` signal to the client.

```
/reset
```

**Response:**
```json
{ "success": true, "action": "reset_session", "message": "Session reset." }
```

### /resume

Tells the client to load the most recent saved transcript.

```
/resume
```

**Response:**
```json
{ "success": true, "action": "resume_session", "message": "Resuming last session..." }
```

## Required Capability

`read` (authenticated users only — guests are blocked)

## Notes

- All three commands are pure front-end signals; they do not write to the database.
- `/reset` fires the `wp_mcp_ai_session_reset( $user_id, $assistant_id )` action hook.
