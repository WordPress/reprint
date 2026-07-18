# AGENTS.md

Working rules for agents in this repository. Every rule below is a
correction that was actually issued during code review here, or a defect
class that survived several review rounds before being caught. Follow them
the first time. Project structure and commands live in CLAUDE.md; this file
is about how to work.

## Push language contract

Read `markdown/PUSH-TERMINOLOGY.md` before proposing or changing push work.
Use its terms verbatim in identifiers, comments, tests, documentation, plans,
review replies, commit messages, and pull-request descriptions. Do not replace
them with near-synonyms.

`tests/Import/PushTerminologyTest.php` keeps retired terms out of push code and
requires `PushFilesSender` names to remain in the glossary. Update the glossary
and this test together when the language contract changes.

## Streaming is the point — never buffer

- Never accumulate a request body, a frame plan, or any "send later"
  structure. A stepping API (`send_chunk()`, `next_chunk()`) must perform
  its real I/O before returning: when
  `MultipartPushStreamClient::send_chunk()` returns true, the frame has left
  for the network.
- The one permissible in-memory unit is a single bounded chunk (a few MiB,
  the `chunk_bytes` option). Read it, send it, drop it. PHP string
  assignment is copy-on-write — hand strings over instead of concatenating
  them (see the outbound frame fields in the push client).
- Read first, declare after: compute frame headers from the bytes actually
  read (`strlen($payload)`); never promise a byte count and then try to
  fulfill it. Short reads then produce smaller frames instead of a poisoned
  stream.
- Minimize requests: many chunks of many files travel in one request,
  resumable by cursor. One-request-per-file or one-request-per-chunk
  designs are rejected on sight.

## Names carry the correct dimension

- The chunk (in-memory unit) and the request body (what hosts cap) are
  different dimensions. Never name one after the other. This repo renamed
  `PushFrameSizer` → `PushRequestSizer`, `chunk_size_exhausted` →
  `request_size_exhausted`, and the endpoint option `max_request_bytes` →
  `max_frame_bytes` because each name lied about its dimension.
- Full descriptive names everywhere; no abbreviations
  (`$current_base64_path`, never `$cur_b64`). If a name needs "remote" or
  "local" to be unambiguous, it must contain it.

## Budgets and limits

- Whatever budget you account for must be the budget the remote stack
  actually enforces. Request-size limits (`post_max_size`,
  `client_max_body_size`) measure the decoded entity body: frame header
  lines count toward it, transfer framing and HTTP headers do not.
- Numbers come from the remote's real configuration — preflight-reported
  php.ini values seed the ceiling — plus learning from rejections (413s cap
  it permanently). Web-server limits PHP cannot introspect are learned,
  never assumed.
- Adaptive learning needs a size-bearing success: an accepted empty request
  proves nothing about a size being safe to grow from, so it must not record
  a success.

## Timeouts

- Never a total-transfer timeout: it kills healthy-but-slow bulk transfers.
  Time out per phase, on lack of progress: connect, stall (zero bytes
  moving for N seconds), response wait. A slow connection that keeps moving
  bytes may take as long as it needs.

## Errors, validation, and recoverability

- Every error names the exact violated condition and the observed value, in
  a human sentence. `throw new InvalidArgumentException('fields')` is the
  canonical counterexample.
- Options that are absent get defaults; options that are present but
  invalid throw. Silently substituting a default hides the caller's
  mistake. Cast validated numerics — `argv` and state files deliver
  numeric strings.
- Classify remote rejections by the protocol's own design: `busy` and
  `offset_gap` are recoverable (retry with the returned cursor); auth
  failures and redirects fail permanently with pointed messages ("The target
  redirected to X. Use that address as the push base_url."). "Retry" must
  never be a final status — exhausted retries become `failed`.
- Cursors and status responses report only what the store has confirmed —
  never echo a sender's claimed offset back as truth.

## Resume and drift — the hard part

- Any resumable transfer must assume the local path changed between sessions.
  A resumed push must never append new-version bytes behind an old-version
  prefix — that builds file contents which never existed locally, and size
  checks alone will happily verify them.
- The mechanisms here: the sender persists the local path's type, size, and
  ctime alongside every cursor and restarts a changed file at offset 0. These
  are the same fields the journal diff compares; mtime can be backdated by
  touch(), ctime cannot. The receiver treats an offset-0 frame for anything it
  cannot vouch for as a restart (remove, then upload fresh), while verified
  work files replayed at their verified size are skipped.
- Document the honest gaps where the mechanism is documented: a same-size
  edit within one timestamp second leaves the change fields unchanged; the
  diff layer is the deeper net.

## Testing

- Test cross-machine code against real local endpoints (`php -S` running
  the real router); injected fake transports are rejected. Do more work in
  the tests rather than adding seams to production code.
- For any stateful or resumable feature, enumerate the failure taxonomy
  FIRST (grew / shrank / same-size edit / replay-after-partial-commit /
  cursor-beyond-EOF / cursor's file deleted from the journal), write the
  tests, watch them fail for the meaningful assertion, then implement.
- When asked whether tests would have caught a bug, prove it at the commit
  boundary: check out the pre-fix implementation files, refresh the vendor
  mirror, and show the new tests failing against them.
- Wire-level claims get wire-level proof: a raw TCP listener observing when
  bytes arrive is how "send_chunk() transmits before returning" and
  "back-to-back requests reuse the connection" are tested.

## Claims must be verified before they are written

- Never state a guarantee in a docblock, PR description, or review reply
  without pointing at the line that enforces it. Real defects here were
  sentences written from memory: "a 409 catches resume-after-change" (true
  only for verified work files), "the saved fields match the fields the diff
  compares" (the diff keys on ctime; the saved fields used mtime).
- Probe platform behavior empirically instead of assuming, and record the
  result: PHP's curl binding honors CURL_READFUNC_PAUSE only from 8.1 —
  on 7.4/8.0 the upload silently truncates (issue #327 has the full
  write-up); `php -S` never answers 100-continue, so an Expect header
  stalls every request by the full timeout; libcurl reuses connections
  across requests only when the curl_multi handle outlives them.

## Failure provenance — do not invent bugs

- Before adding defensive production code, recovery logic, or a PR
  justification, prove that the failure is reachable from a valid production
  state. Write the causal sequence. Every transition must name its actor and
  basis: the production code path which performs it, a documented concurrent
  or external actor, or the exact syscall/API failure allowed by the system
  contract.
- A test helper, operator, or hypothetical process which unlinks, rewrites,
  chmods, replaces, or symlinks private state is not a production actor. Unless
  the threat model explicitly includes that actor, classify the test as
  deliberate state corruption. Do not call it an interruption, race, or
  reproduction.
- Fault injection must match the claimed failure at the same abstraction layer.
  If the claim is that `unlink()` fails, make `unlink()` fail and exercise that
  error path. Replacing the file with a directory tests type corruption, not an
  `unlink()` failure.
- A test failing against the pre-change implementation proves only regression
  coverage. It does not prove production reachability. Require both a
  production-reachable causal sequence and a pre-change test failure at the
  assertion representing the claimed defect.
- If no production actor exists for any transition, stop. Do not add production
  logic or invent a hypothetical restore, cleanup, attacker, or helper process.
  Ask whether arbitrary corruption or tampering is an explicit requirement.
- Tests which alter private state out of band must say `corrupt` or `tamper` in
  their names. They may verify fail-closed validation, but they do not justify
  new persistent flags, schema versions, or recovery machinery.

## Abstractions

- Inline single-use helpers; no wrapper classes that only rename a concept.
  A "transport" callable, a plan-then-send request object, and a processor
  that materialized a file list were all deleted from this repo. A helper
  earns its name at two or more callers.
- No speculative options, hooks, or nullable dependencies "for tests" —
  `on_before_request` and the optional `hmac_client` were both removed;
  tests sign requests like every other caller.
- Mirror how the pipeline already solves a problem before inventing a new
  scheme: paths travel base64 on every wire and in the journal (JSON is
  UTF-8-only; file names are arbitrary bytes); change detection keys on
  ctime + size. Grep first.

## Repo mechanics that will bite you

- Composer's classmap autoloads exporter classes from
  `vendor/wp-php-toolkit/reprint-exporter/` — a stale mirror silently runs
  old code under the tests. After editing anything in
  `packages/reprint-exporter/src/`, copy the file to BOTH
  `vendor/wp-php-toolkit/reprint-exporter/src/` and
  `reprint-exporter-wp/vendor/wp-php-toolkit/reprint-exporter/src/`.
- `packages/reprint-importer/src/lib/upload/` must stay PHP 7.4-PARSEABLE:
  import.php loads it for pull users on 7.4, and the push client's 8.1
  requirement is a runtime check in its constructor.
  PushClientPhpCompatibilityTest enforces this; do not "clean up" the
  untyped `$max_request_seconds` property into an 8.0 union type.
- PHPCS: per-file violation counts must not increase. Match each file's
  existing idiom (including its pre-existing warnings' style) instead of
  importing your own.
- Run tests from `tests/` with `../vendor/bin/phpunit <files>`; run
  `composer analyze -- --memory-limit=1G` and `git diff --check` before
  committing.

## Writing

- Never use the noun spelled by joining `evid` and `ence` anywhere in code,
  comments, documentation, tests, plans, commit messages, pull requests, or
  replies. Name the concrete proof, signal, record, state, observation, or
  confirmation instead.
- Comments state the mundane real reason, tightly, and must match actual
  behavior — a docblock that prescribes a courtesy no caller performs is a
  defect. Update docs in the same commit as the behavior they describe,
  including the delivery plan (markdown/PUSH-SYNC.md) when a decision
  changes it.
- Keep PHPDoc array shapes readable. Prefer WordPress hash notation for
  human-facing docs: put the summary on the `@param array $args {` or
  `@return array {` line, then document each key with aligned `@type` entries.
  Do not force reviewers to parse a dense inline union before they understand
  what the method accepts or returns.
- Array arguments must document their keys in PHPDoc. Prefer
  `@param array $work { ... @type string $path_b64 ... }` over a bare `array`.
  Use named `@phpstan-type` aliases only when static analysis needs a reusable
  type that WordPress hash notation cannot express clearly.
- For structured array returns, use the same WordPress hash notation:
  `@return array { ... @type string $path_b64 Description. ... }`. Each key
  gets its own description, including when it is present only for one variant
  of the returned structure.
- PR descriptions: the first sentence states the observable effect and
  ideally why; describe concrete triggers and results, never vague causal
  phrases ("could be found again", "restore more predictably"); the
  implementation section is optional and high-level; avoid bullet points.
- When a hard requirement lands on users (push requires PHP 8.1+), write it
  up thoroughly for an unfamiliar audience (issue #327), link it from the
  error message and the code, and make the error say exactly what to do.
