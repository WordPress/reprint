# Reverse transport: push as a remote-driven pull

**Status: design sketch. No code yet.** This describes how to run the *ordinary*
pull commands (`files-index`, `files-pull`, `db-pull`, `db-apply`) in the push
direction — local site → remote target — **without the local site being
reachable inbound and without a new transfer subsystem.** It is deliberately
scoped to the existing commands; the staged-transfer/push machinery is out of
scope here.

## The idea in one paragraph

The **remote** (the public site, the destination) runs the ordinary importer, so
it owns all state: cursors, index, staged apply, final writes. The **local** site
(the source, behind NAT) never listens — it only makes **outbound** requests. It
runs a small `relay-source` worker whose every request does two things at once:
it **delivers the result of the previous export command** and **asks for the
next one**. The importer's HTTP transport is swapped for a *relay transport*
that, instead of dialing the source over the network, hands each export request
to a per-session rendezvous on the remote and waits for the worker to bring the
answer back. Above that one seam nothing changes — the importer can't tell a
relayed pull from a direct one, so every regular command rides it for free.

## Actors

| | Role | Network | Runs |
|---|---|---|---|
| **Remote** (destination) | the importer *brain* + the rendezvous | reachable (it's the live site) | `files-pull` (etc.) with `--transport=relay`, plus the rendezvous endpoints in its plugin |
| **Local** (source) | a stateless source worker | outbound-only | `relay-source`: exchange with the remote, execute each export command in-process against the local export engine |

The direction of *control* is remote→protocol; the direction of *connection* is
local→remote. That split is the whole trick.

## The inversion, next to a normal pull

```
Normal pull (importer wants files, dials the source):
    [importer] --HTTP GET file_fetch?cursor=C--> [export.php on source]
    [importer] <------- multipart bytes, X-Cursor: C' -------

Reverse transport (source can't be dialed; it dials out instead):
    [importer on REMOTE] --RelayTransport--> [rendezvous on remote]   (enqueues "command N")
                                                    ^
                                                    | (2) POST result of N-1, take command N   (outbound)
                                                    |
    [relay-source worker on LOCAL] --runs export.php in-process on command N--
                                                    |
                                                    v  (3) POST result of N on its next exchange
    [importer on REMOTE] <-- RelayTransport returns result N --  (feeds the normal parser)
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

## The rendezvous (endpoints on the remote plugin)

The remote hosts a tiny per-session store holding **one in-flight command and
its result**, committed by atomic rename — the same discipline the staged store
uses. One endpoint carries the whole exchange (the worker's single outbound
request):

- **`relay_exchange`** (local → remote, HMAC-authenticated):
  request body = `{ session, last_command_id, result? }` where `result` is the
  previous export response (`{httpCode, headers incl. X-Cursor, body}`).
  The handler:
  1. records `result` for `last_command_id`, unblocking the importer's
     RelayTransport that is waiting on it;
  2. returns the next pending command
     `{ command_id, method, endpoint, params, X-Cursor, requestBody? }`, or
     `{ status: "waiting" }` (retry after a short backoff — the importer hasn't
     produced the next request yet), or `{ status: "done" }`.

Optional, or folded into the first exchange:

- **`relay_open` / `relay_close`** — create/tear down a session (id + secret
  binding), or let the first `relay_exchange` create it lazily and `db-apply`
  completion close it.

Only one endpoint is load-bearing. There is **no long-poll held open** in the
minimal form: `relay_exchange` returns promptly with either the next command or
`waiting`, and the worker retries. (A held long-poll is a later optimization, not
required.)

## Who blocks on whom (and why there's no held socket)

Two processes on the **remote** share the rendezvous store, exactly the way the
uploader and applier share the staged store:

- the **importer process** runs `files-pull`; its RelayTransport writes the
  export request as "pending command N" and then **polls the store** for
  "result N" (bounded sleeps, not a held connection);
- the **`relay_exchange` web handler** is hit by the local worker; it writes
  "result N-1" into the store and reads back "pending command N" to return.

So the importer advances **exactly one export request per local exchange**,
paced by the worker's outbound cadence. Every hop is a bounded request; nothing
holds a socket across the internet. This is the same reentrant, shared-store
decoupling the codebase already relies on, which is why it suits shared hosting.

## One `file_fetch`, end to end

1. Remote `files-pull` needs the next file chunk → RelayTransport enqueues
   `{endpoint: file_fetch, X-Cursor: C, chunk params}` as command N, waits.
2. The local worker's next `relay_exchange` returns command N. The worker runs
   the export engine **in-process** — `Site_Export_HTTP_Server::handle_request()`
   already accepts a synthetic `{get, post, body, server}` request array, so the
   worker drives it with N's params and **captures** the output instead of
   echoing it — producing the multipart body + `X-Cursor` response.
3. The worker POSTs `{ last_command_id: N, result: {...} }` on its next
   exchange. **The bytes travel local → remote here.**
4. The remote records result N; RelayTransport returns it to `files-pull`, which
   feeds it to `MultipartStreamParser` + (optionally) the staged apply window
   exactly as for a direct pull. Cursor advances to `C'`; command N+1 is
   enqueued.

The remote sets the chunk-size params, so each command's response is bounded to
one comfortable exchange body — no unbounded buffering on either side.

## Why this is the resilience shape you wanted

- **The remote owns cursors, the index, staged apply, and the final writes** —
  one machine mutating its own tree with its own state. There is no client-side
  *belief* about the target's state that can drift from reality (the fragile
  piece in the local-drives model).
- **The local worker is stateless.** It executes idempotent, read-only export
  commands. Kill it and rerun — it re-exchanges and re-executes; the export side
  is cursor-driven, so re-execution is safe.
- **It reuses the entire tested pull pipeline** (resume, delta, `--staged-apply`
  atomic apply). The only new code is the transport seam + `RelayTransport` +
  `relay_exchange` + the `relay-source` loop.

## Security to design carefully (flagged, not solved here)

- The worker executes export commands the remote sends, so it **must run the
  export engine with the source's own root/directory constraints** — a
  compromised or hostile remote can then only ask for paths the source already
  exposes over `export.php`, no more. The remote is handed the read-only export
  API in reverse, never a shell.
- **HMAC** authenticates the worker↔remote channel (the worker holds the secret,
  as today). **TLS** for confidentiality: the file bytes traverse the wire inside
  the exchange bodies.
- **Idempotency/replay:** `command_id`/`result_id` make the exchange idempotent —
  a lost exchange re-fetches the same command; a lost result is re-posted; a
  replayed command re-runs a read-only export safely.

## First iteration — "play with it in context of regular commands"

In scope:
1. Introduce the transport seam; default binding = direct HTTP (**no behavior
   change**, existing pull tests green).
2. Add `RelayTransport`, the `relay_exchange` endpoint, and a `relay-source` CLI
   worker.
3. Prove it: run an ordinary `files-pull` (and `db-pull`) **entirely over the
   relay** with source and destination on one host in a test — a real reversed
   pull, no staged-push subsystem involved.

Out of scope for now: production deployment (how the remote importer is
triggered/kept alive), multi-session concurrency, and any of the push-side
staging store (unused in this model).

## Open questions

- **Remote importer liveness/trigger.** CLI vs cron vs a "kick" endpoint that
  runs one bounded importer step per call. The bounded-step-per-call variant
  would remove the held remote process entirely, but needs the importer to
  checkpoint at the transport boundary (it currently resumes at command
  granularity, not mid-transport-call) — worth exploring, possibly the cleanest
  end state.
- **Chunk sizing across the exchange.** The remote sets it, so it's bounded;
  confirm one `file_fetch` response fits one exchange body comfortably under the
  host's `post_max_size`.
- **Backpressure / timeouts.** `waiting` backoff, exchange timeout, and how a
  stalled worker surfaces to the operator.
- **`db-apply` writes on the remote.** `db-pull` fetches the dump via the relay;
  `db-apply` then runs locally on the remote against its own DB — no relay
  needed for the write side, which is another point in favor of "remote owns the
  final writes."
