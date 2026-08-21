<!-- /autoplan restore point: /home/gstack/.gstack/projects/garrytan-gstack/feat-browser-batch-multitab-autoplan-restore-20260406-061451.md -->
# Plan: Batch Command Endpoint + Multi-Tab Parallel Execution

## Problem

GStack Browser commands are sequential HTTP round-trips. An AI agent controlling the browser from a remote server (e.g., Render → ngrok → laptop) pays ~2-5s latency per command. A 143-page crawl at 4 commands per page = ~600 commands = ~30-45 minutes.

## Insight

The browser can already handle multiple tabs. But the agent can only send one command per HTTP request. Two changes turn this from serial to massively parallel:

1. **Batch endpoint**: Send N commands in one HTTP request, get N results back
2. **Multi-tab parallelism**: Open 10-20 tabs, execute commands across all of them simultaneously

Combined: 20 companies per batch round-trip instead of 1. A 143-company crawl drops from ~45 minutes to ~5 minutes.

## Architecture

### Batch Endpoint

```
POST /batch
Authorization: Bearer <token>

{
  "commands": [
    {"command": "text", "tabId": 1},
    {"command": "text", "tabId": 2},
    {"command": "snapshot", "args": ["-i"], "tabId": 3},
    {"command": "click", "args": ["@e5"], "tabId": 4}
  ],
  "parallel": true,     // default true — execute all commands concurrently
  "timeout": 30000      // overall batch timeout in ms
}
```

Response:
```json
{
  "results": [
    {"index": 0, "tabId": 1, "ok": true, "result": "...page text..."},
    {"index": 1, "tabId": 2, "ok": true, "result": "...page text..."},
    {"index": 2, "tabId": 3, "ok": true, "result": "...snapshot..."},
    {"index": 3, "tabId": 4, "ok": false, "error": "Element not found"}
  ],
  "timing": {
    "total_ms": 2340,
    "per_command_ms": [1200, 890, 2340, 150]
  }
}
```

### Key Design Decisions

1. **Parallel by default**: Commands targeting different tabs run concurrently (Promise.all). Commands targeting the SAME tab run sequentially within that tab (to avoid race conditions like clicking while a snapshot is in progress).

2. **Per-command error isolation**: One command failing doesn't abort the batch. Each result has its own ok/error status.

3. **Tab ownership enforcement**: Same as today — each command must target a tab owned by the requesting agent. Unauthorized tab access returns 403 per-command.

4. **Ref scoping**: Snapshot refs (@e1, @e2...) are already per-tab. No changes needed — each tab has its own ref namespace.

5. **Rate limiting**: The existing per-agent rate limit (10 req/s) should apply to the batch as a whole (1 batch = 1 request), not per-command-within-batch. This is the whole point — batch reduces HTTP overhead.

6. **Max batch size**: Cap at 50 commands per batch to prevent abuse. This is generous — typical use is 10-20.

## Implementation

### Phase 1: Batch endpoint (server.ts)

**File: `browse/src/server.ts`**

Add `POST /batch` route handler:
- Parse array of commands from request body
- Group commands by tabId
- For each tab group, execute commands sequentially
- Across tab groups, execute in parallel (Promise.all)
- Collect results, return as array matching input order
- Apply per-command timeout (default 10s each) and batch-level timeout

### Phase 2: Multi-tab newtab batching

**File: `browse/src/server.ts`** or **`browse/src/write-commands.ts`**

Add `POST /batch-newtab` convenience endpoint:
```json
{
  "urls": [
    "https://example.com/page1",
    "https://example.com/page2",
    "https://example.com/page3"
  ],
  "wait": "domcontentloaded"  // or "networkidle"
}
```

Response:
```json
{
  "tabs": [
    {"tabId": 5, "url": "...", "ok": true},
    {"tabId": 6, "url": "...", "ok": true},
    {"tabId": 7, "url": "...", "ok": false, "error": "timeout"}
  ]
}
```

Opens N tabs in parallel. Returns all tab IDs. Agent can then use `/batch` to read all of them at once.

### Phase 3: Bulk close

Add `POST /batch-close`:
```json
{
  "tabIds": [5, 6, 7, 8, 9]
}
```

Clean up tabs after a batch crawl.

## Usage Pattern (Agent Workflow)

```bash
# Step 1: Open 20 company pages at once
POST /batch-newtab
{"urls": ["https://internal.ycinside.com/companies/1435", ...20 more]}
# → returns tabIds [5, 6, 7, ..., 24]

# Step 2: Read all 20 pages at once  
POST /batch
{"commands": [
  {"command": "text", "tabId": 5},
  {"command": "text", "tabId": 6},
  ...
]}
# → returns all 20 page contents in ~2-3 seconds total

# Step 3: Click "Application" tab on all 20
POST /batch
{"commands": [
  {"command": "click", "args": ["text=Application"], "tabId": 5},
  {"command": "click", "args": ["text=Application"], "tabId": 6},
  ...
]}

# Step 4: Read all 20 applications
POST /batch
{"commands": [
  {"command": "text", "tabId": 5},
  ...
]}

# Step 5: Clean up
POST /batch-close
{"tabIds": [5, 6, ..., 24]}

# Result: 20 companies fully ingested in 5 HTTP round-trips instead of 160
```

## Testing

### New test file: `browse/test/batch.test.ts`

- Batch with commands targeting different tabs (parallel execution)
- Batch with commands targeting same tab (sequential within tab)
- Per-command error isolation (one fails, others succeed)
- Tab ownership enforcement (can't batch commands on other agent's tabs)
- Max batch size enforcement (>50 returns 400)
- Batch timeout (overall timeout kills remaining commands)
- batch-newtab: opens N tabs in parallel
- batch-newtab: partial failure (some URLs fail, others succeed)
- batch-close: closes multiple tabs
- batch-close: can't close other agent's tabs

### Existing tests unaffected
- Single-command /command endpoint unchanged
- All existing snapshot, click, fill tests work identically

## Security

- Same auth model — Bearer token required on /batch, /batch-newtab, /batch-close
- Tab ownership checked per-command within the batch
- No new capabilities — batch just reduces round-trips for operations already available via /command
- Rate limit: 1 batch = 1 request against the per-agent limit

## Performance Considerations

- 20 concurrent page loads on the same browser WILL spike CPU/memory on the host machine
- Recommend: agent should self-limit to 10-15 concurrent tabs for reliability
- If the browser process gets stressed, commands will just take longer (graceful degradation)
- The `/batch-newtab` endpoint could optionally support a `concurrency` param to control how many tabs open simultaneously

## Files to Create/Modify

1. `browse/src/server.ts` — add /batch, /batch-newtab, /batch-close routes
2. `browse/test/batch.test.ts` — new test file
3. `browse/test/fixtures/` — may need a multi-page fixture set
4. `browse/SKILL.md` — document batch commands in the COMMAND REFERENCE
5. `CHANGELOG.md` — new feature entry
