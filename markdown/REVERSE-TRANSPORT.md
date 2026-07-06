# Reverse transport: push as a remote-driven pull

**Status: wired into the real importer; an ordinary `files-pull` runs end to
end over the reverse channel in a test.** This describes how to run the
*ordinary* pull commands (`files-index`, `files-pull`, `db-pull`, `db-apply`) in
the push direction — local site → remote target — **without the local site being
reachable inbound and without a new transfer subsystem.** It is deliberately
scoped to the existing commands; the staged-transfer/push machinery is out of
scope here.

The relay mechanism exists as small classes, and the importer's transport is
now pluggable so the real pull rides them:

- `packages/reprint-importer/src/lib/relay/class-relay-transport.php` — `RelayTransport`
- `packages/reprint-importer/src/lib/relay/class-transport-yield.php` — `TransportYield`
- `packages/reprint-importer/src/lib/relay/class-relay-exchange.php` — `RelayExchange`
- `packages/reprint-importer/src/lib/relay/class-relay-source-worker.php` — `RelaySourceWorker`
- `packages/reprint-importer/src/lib/relay/class-relay-export-source.php` — `RelayExportSource` (runs the source's real `export.php` and gunzips the response)
- `packages/reprint-importer/src/lib/relay/class-relay-import-driver.php` — `RelayImportDriver` (re-enters the real importer per exchange)
- `ImportClient` gains a transport seam: `set_relay_transport()` plus a relay
  branch at the two curl choke points (`fetch_json`, `fetch_streaming`), inert
  in direct mode.

Tests:

- `tests/Relay/ReverseTransportDemoTest.php` — moves real file bytes source →
  destination over the reversed channel with a stand-in source/driver (byte
  identity + crash-resume).
- `tests/Relay/ReverseTransportPullTest.php` — runs the **real** `files-pull`
  (real `ImportClient`) against the **real** `export.php` entirely over the
  reverse channel and mirrors a source tree to the fs-root byte-for-byte, in two
  exchanges (`file_index` then `file_fetch`).

## The idea in one paragraph

The **remote** (the public site, the destination) runs the ordinary importer, so
it owns all state: cursors, index, staged apply, final writes. The **local** site
(the source, behind NAT) never listens — it only makes **outbound** requests. It
runs a small `relay-source` worker whose every request does two things at once:
it **delivers the result of the previous export command** and **asks for the
next one**. The importer's HTTP transport is swapped for a *relay transport*
that, instead of dialing the source over the network, hands each export request
back to the local worker to execute. Above that one seam nothing changes — the
importer can't tell a relayed pull from a direct one, so every regular command
rides it for free.

## Actors

| | Role | Network | Runs |
|---|---|---|---|
| **Remote** (destination) | the importer *brain* | reachable (it's the live site) | the `relay_exchange` endpoint, which *is* `files-pull` (etc.) driven one step per call |
| **Local** (source) | a stateless source worker | outbound-only | `relay-source`: exchange with the remote, execute each export command in-process against the local export engine |

The direction of *control* is remote→protocol; the direction of *connection* is
local→remote. That split is the whole trick.

## The inversion, next to a normal pull

```
Normal pull (importer wants files, dials the source):
    [importer] --HTTP GET file_fetch?cursor=C--> [export.php on source]
    [importer] <------- multipart bytes, X-Cursor: C' -------

Reverse transport (source can't be dialed; it dials out instead):
    [importer on REMOTE, inside relay_exchange] --RelayTransport.request(cmd N)-->
                                                    | (no result in hand → TransportYield)
                                                    v  returns "command N" as the HTTP response
    [relay-source worker on LOCAL] --runs export.php in-process on command N--
                                                    |
                                                    v  next outbound POST: { last_command_id: N, result }
    [importer on REMOTE, inside the NEXT relay_exchange] -- resumes from cursor,
        RelayTransport.request(cmd N) returns the delivered result -- feeds the normal parser
```

The export **request envelope** the importer builds is identical in both worlds
(`{method, endpoint, params, X-Cursor}`); only *who carries it* differs. The
file bytes travel **local → remote inside the worker's next request body** —
exactly the flow "the next request from local transports the result."

## The seam: how `files-pull` gets pointed "through" the local connection

Today the importer issues export requests at ~2 curl choke points:

- `fetch_json($url)` — buffered GET, returns a body string (used by
  `file_index`, `db_index`, and other lightweight JSON calls).
- the streaming `file_fetch` path — a curl write-callback feeding
  `MultipartStreamParser` incrementally (large multipart bodies).

Neither is pluggable on the pull side today (the push client already
parameterizes its `transport`; the pull side does not). Step one is therefore a
**transport abstraction** both choke points route through — a single callable:

```
transport(method, endpoint, params, headers, requestBody, onChunk) -> { httpCode, headers, body|streamed }
```

- Default binding: **direct HTTP** (today's curl). No behavior change; this is a
  pure refactor that all existing pull tests must still pass through.
- `--transport=relay` binding: **RelayTransport** (below).

Because the seam sits *below* cursor handling, multipart parsing, the diff, and
staged apply, **every regular command works over the relay unchanged** — that is
the payoff and the reason this is small.

## The single endpoint: the endpoint *is* the importer

There is **no separate importer process on the remote, no held socket, and no
shared command/result store polled by two processes.** The whole exchange is one
bounded HTTP request handled by one endpoint:

- **`relay_exchange`** (local → remote, HMAC-authenticated):
  request body = `{ session, last_command_id, result? }`, where `result` is the
  previous export response (`{httpCode, headers incl. X-Cursor, body}`).

The handler (`RelayExchange::handle`) runs the importer **inline**:

1. It builds a `RelayTransport` primed with the one delivered `result`.
2. It calls the importer (the injected *driver*). The driver **resumes from its
   own persisted cursor**, and its next `transport->request(...)` — the command
   the delivered result answers — returns that result, so the importer advances
   one step (writes/stages the bytes, moves the cursor, persists it).
3. The importer then issues its *next* export request. There is no result for it
   yet, so `RelayTransport` throws **`TransportYield`**, which unwinds cleanly
   back to the handler. The handler catches it and returns
   `{ status: "command", command_id, command }` — the next thing for the worker
   to fetch. When the driver returns instead of requesting, the handler returns
   `{ status: "done" }`.

`RelayTransport` is deliberately tiny: it holds exactly one delivered result and
hands it back to the single matching request (matched by a fingerprint of the
request envelope, so a re-issued request after re-entry lines up with its
result); every other request yields. That one rule is what makes each exchange
advance the importer by **exactly one** export request.

## Why there's no liveness problem (the old open question, now solved)

Earlier sketches kept a long-lived importer process on the remote and asked how
to trigger/keep it alive. The single-endpoint model deletes that question: **the
local worker's outbound request is itself the trigger.** Each POST runs one
bounded importer step and returns; between exchanges nothing runs on the remote.
This is the same reentrant, resume-from-cursor discipline the codebase already
uses for exit-code-2 resumption — here applied at *per-exchange* granularity.

The one real requirement this places on a command: it must **persist its cursor
at the yield boundary**, exactly as it already checkpoints on an exit-2 stop, so
the next exchange resumes precisely after the last consumed result. Re-entry
replays only the importer's cheap in-memory reconstruction from that cursor — not
any prior transfer. The prototype's mirror driver shows the shape concretely: it
saves its state to disk immediately before issuing the request that yields, and
the crash-resume test kills the worker mid-transfer and finishes on a fresh one
without re-asking for the file the remote already holds.

## One `file_fetch`, end to end

1. A `relay_exchange` arrives carrying the result of the previous chunk (or none,
   on the first exchange). The handler runs `files-pull` inline; it resumes from
   cursor `C`, and `RelayTransport.request({endpoint: file_fetch, X-Cursor: C})`
   returns the delivered chunk. The importer feeds it to `MultipartStreamParser`
   + (optionally) the staged apply window exactly as for a direct pull, advances
   the cursor to `C'`, and **persists it**.
2. `files-pull` needs the next chunk → `RelayTransport.request({... X-Cursor: C'})`
   has no delivered result → **`TransportYield`** → the handler returns that
   command. `relay_exchange` responds; nothing runs on the remote until the next
   POST.
3. The local worker runs the export engine **in-process** on the returned
   command — `Site_Export_HTTP_Server::handle_request()` already accepts a
   synthetic `{get, post, body, server}` request array and returns without
   exiting, so the worker drives it with the command's params and **captures**
   the multipart body + `X-Cursor` response.
4. The worker POSTs `{ last_command_id, result }` on its next exchange. **The
   bytes travel local → remote here.** Back to step 1 for the next chunk.

The remote sets the chunk-size params, so each command's response is bounded to
one comfortable exchange body — no unbounded buffering on either side.

## Why this is the resilience shape you wanted

- **The remote owns cursors, the index, staged apply, and the final writes** —
  one machine mutating its own tree with its own state. There is no client-side
  *belief* about the target's state that can drift from reality (the fragile
  piece in the local-drives model).
- **The local worker is stateless.** It executes idempotent, read-only export
  commands. Kill it and rerun — it re-exchanges and re-executes; the export side
  is cursor-driven, so re-execution is safe. (The demo's second test does exactly
  this: crash mid-transfer, resume clean.)
- **It reuses the entire tested pull pipeline** (resume, delta, `--staged-apply`
  atomic apply). The only new code is the transport seam + the four small relay
  classes + the `relay-source` loop.

## Security to design carefully (flagged, not solved here)

- The worker executes export commands the remote sends, so it **must run the
  export engine with the source's own root/directory constraints** — a
  compromised or hostile remote can then only ask for paths the source already
  exposes over `export.php`, no more. The remote is handed the read-only export
  API in reverse, never a shell. (The demo enforces a containment check in its
  stand-in source to make this concrete.)
- **HMAC** authenticates the worker↔remote channel (the worker holds the secret,
  as today). **TLS** for confidentiality: the file bytes traverse the wire inside
  the exchange bodies.
- **Idempotency/replay:** `command_id` makes the exchange idempotent — a lost
  exchange re-fetches the same command; a lost result is re-posted; a replayed
  command re-runs a read-only export safely.

## First iteration — "play with it in context of regular commands"

Done:
- The relay mechanism (`RelayTransport`, `TransportYield`, `RelayExchange`,
  `RelaySourceWorker`) and a demo that moves real files over the reversed
  single-endpoint channel, including crash-resume.
- The importer transport seam: `fetch_json`/`fetch_streaming` route through
  `RelayTransport` when it is set (a request's URL, cache-buster-free so it
  fingerprints stably, is the command identity); direct mode is untouched and
  every existing pull test stays green (504/74 PHPCS on `import.php`, unchanged).
- `RelayExportSource` (the source's real `export.php`, gunzipped) and
  `RelayImportDriver` (re-enter the real importer per exchange until it yields or
  completes).
- A real reversed `files-pull` — real importer, real exporter — mirroring a
  source tree byte-for-byte in `tests/Relay/ReverseTransportPullTest.php`.

Notes from wiring the real path:
- `TransportYield` extends `Error`, not `Exception`, so it slips past the
  importer's `catch (Exception)` command wrapper without persisting an error
  status.
- No explicit "persist cursor at the yield boundary" code was needed: the
  importer already reads its resumable position from persisted state at the
  start of each request and only issues one export request per invocation, so a
  yield lands exactly on the existing exit-2 checkpoint.

Next, in scope:
1. `db-pull` (same seam; it uses `sql_chunk`/`db_index` through the same choke
   points).
2. Ship the `relay_exchange` endpoint (HMAC-authenticated) and the
   `relay-source` CLI as thin wrappers over `RelayImportDriver` /
   `RelaySourceWorker`, with the source worker making a real loopback request to
   its own `export.php` in place of the test's subprocess.

Out of scope for now: multi-session concurrency and any of the push-side staging
store (unused in this model).

## Open questions

- **Chunk sizing across the exchange.** The remote sets it, so it's bounded;
  confirm one `file_fetch` response fits one exchange body comfortably under the
  host's `post_max_size`.
- **Backpressure / timeouts.** Exchange timeout, retry policy, and how a stalled
  worker surfaces to the operator.
- **`db-apply` writes on the remote.** `db-pull` fetches the dump via the relay;
  `db-apply` then runs locally on the remote against its own DB — no relay needed
  for the write side, which is another point in favor of "remote owns the final
  writes."
