# Tiknix Pipelines — plan (streamlined from myctobot)

Deterministic, agent-buildable workflows that **ship as part of an instance's code**,
run on the instance, expose themselves as MCP tools + REST APIs, and use that
instance's own connections. Distilled from myctobot's pipelines/dapps/pipeline-MCP
(~19,000 lines / 12 files / 10 tables, 4 empty everywhere) down to an irreducible
core (~2,000 lines). Recon: Fable, against the live `myctobot_demo` DB (28 pipelines,
124 steps, 1,315 runs).

---

## 1. Thesis — same power, a tenth the code, none of the cognitive tax

myctobot's power is real and worth keeping. Its cruft is enormous and quantified:
- **`services/PipelineExecutor.php` is 6,813 lines**; the actual value is a ~130-line
  variable-substitution core + a step dispatch + a grid walker. Target executor: ~800.
- **22 step types**, most connector-specific (shopify/stitch/linkedin) or dead
  (`script` 0 uses, `webhook_out` 0). Real need: ~9 generic types.
- **Retry machinery: 0 uses** across 4,923 step-runs. **Condition evaluator is a
  stub** (`// TODO: Implement proper expression evaluation`, returns truthy).
  **58% of step-runs are orphaned "pending"** rows from pre-creating them up front.
- **Dapps** (`controls/Dapp.php` 2,623 lines) are ~85% a hosted chatbot runtime, not
  packaging; the tables meant to embed pipelines (`detachableappembeddedpipelines`,
  `…releases`) have **0 rows everywhere**. Detachability never shipped.
- **Three parallel sources of step-type truth** (executor switch, a hand-written
  schema service, a docblock-scraping registry) that have drifted apart.

We copy the **concepts**, not the code: the grid, the variable language, the
MCP-builds-pipelines loop, pipeline-as-a-tool. We drop the executor daemons, tmux
resident sessions, the dapp chatbot, the wizard, and the 4 empty tables.

---

## 2. Locked decisions (from the owner)

| Decision | Choice |
|---|---|
| Where definitions live | **Versioned files in the instance repo** (`pipelines/<slug>.json`). Deploy with the code, diff in git. Run history in the instance DB. |
| Editor | **A sidecar** (`pipelines.tiknix`, on the Sidecar Kit) that reads/writes pipeline files in the instances you own. Bootstrap UI. |
| Execution model | **Sequential + opt-in parallel** — steps run in order; a step declares `parallel` to fan out. (Drops "everything is a grid".) |
| Run surfaces (all) | MCP tool · the instance's own code/UI · cron/webhook trigger · manual run from the editor. |
| Connections | A pipeline uses that instance's own connections **scoped to the instance it runs for** (via the existing broker). |
| APIs | A pipeline can be exposed as a **REST API**, authed by **per-user API keys in the instance's own DB**. |

---

## 3. Architecture — three homes

```
CORE (lib/Pipeline/*) — baked into every instance clone, like the connector system
   Loader (glob pipelines/*.json)   Executor (~800 ln)   Vars   StepRegistry
   Run/StepRun beans                PipelineTool (MCP)   RestExpose   Schedules
        │  ships via git pull from core, same as Introspector/Connectors
        ▼
INSTANCE (<slug>.tiknix) — the pipelines are PART OF ITS CODE
   pipelines/<slug>.json            ← versioned definitions (the source of truth)
   <instance DB>: piperun, pipesteprun, pipeapikey   ← runs + per-user REST keys
   /mcp   → auto-registers expose_as_tool pipelines as tools
   /pipeline/api/<slug>  → REST endpoint (per-user apikey) when expose_as_api
   its own controllers → Pipeline::run($slug, $context) for in-app features
        ▲
EDITOR SIDECAR (pipelines.tiknix) — on lib/Sidecar/*
   SSO'd, owner-scoped; lists/edits pipeline FILES in owned instances;
   run + live-watch; Bootstrap UI. Writes the same JSON the MCP builder writes.
```

**Why files, not a DB, for definitions:** they travel with "build here, deploy to
yours," show in git diffs, need no migration, and are discovered by a `glob` exactly
like `ConnectorRegistry` (`services/connectors/ConnectorRegistry.php:19`). The file
IS the export format — `export_pipeline`/`import_pipeline` become trivial. Run
history (which mutates constantly) stays in the instance's DB.

---

## 4. The definition file (one pipeline = one JSON)

`pipelines/lead-triage.json` in the instance repo:
```jsonc
{
  "slug": "lead-triage",
  "name": "Lead triage",
  "description": "Score + route an inbound lead.",
  "context_schema": { "email": {"type":"string","required":true} },   // REST/MCP input
  "expose_as_tool": true,          // → registered on the instance's /mcp
  "expose_as_api": true,           // → /pipeline/api/lead-triage (per-user apikey)
  "trigger": { "cron": "*/15 * * * *" },   // optional scheduled run
  "steps": [
    { "name": "fetch", "type": "db_query",  "config": {...}, "on_fail": "exit" },
    { "name": "score", "type": "agent",     "config": {"prompt":"Rate {fetch.output.rows}"} },
    { "name": "notify","type": "notify",    "config": {"to":"{context.email}"}, "parallel": true },
    { "name": "crm",   "type": "connection","config": {"connector":"hubspot","tool":"upsert"}, "parallel": true }
  ]
}
```
- **Sequential + opt-in parallel:** steps run top-to-bottom; consecutive steps flagged
  `parallel` form one concurrent group (replaces the always-a-grid row/col model, keeps
  the fan-out). Flow: each step's `on_success`/`on_fail` ∈ `next | goto:<name> | exit`.
- **Variables (ported ~verbatim, ~130 ln):** `{context.x}`, `{<step>.output.path}`,
  `{<step>.stdout}`, `{prev.x}`, `{time.*}`, dot-path nesting. This is the language;
  it's the one part we copy nearly line-for-line.

---

## 5. Step-type registry — ONE map, ~9 types (was 22)

`lib/Pipeline/steps/*.php`, each `{schema(), run($cfg,$vars): array}`. One registry
(no drift), auto-discovered by glob.

| Type | From myctobot | Note |
|---|---|---|
| `shell` | direct_exec (17) | jailed command; stdout/exit captured |
| `agent` | ai_agent (20) + llm_call (3) **merged** | a **jailed runner** — reuses `lib/EngineRegistry` + the AI Builder's jailed agent path (NOT a second agent runtime); one step, `mode` selects one-shot vs session |
| `http` | webhook_out **+ generic** | REST call; subsumes connector-specific HTTP |
| `mcp_call` | mcp_call (3) | call any MCP tool — composability (pipelines calling tools) |
| `connection` | shopify_graphql/stitch/linkedin… **collapsed** | **the owner's requirement**: call THIS instance's own connection via the broker (§7), instance-scoped |
| `transform` | parser (9) | jq + regex + template; **drop the `php` eval branch** |
| `branch` | condition (5) + switch (5) **merged** | a REAL evaluator (operators at PipelineExecutor.php:3699 are fine; replace the stubbed truthy-return) |
| `wait` | wait (6) | `delay` + `await_input` (human/agent gate + resume via `continue`) |
| `notify` | mailgun/email_out (19) | via `lib/Mailer` |
| `db_query` | datastore_query (10) | RedBean query on the instance DB |

Dropped entirely: `script`, `harvest`/`reaper` + tmux resident sessions,
`file_write` (run-dir writes are implicit), `shopify_theme/liquid_eval/crawler`,
`schedule_task` (→ a `trigger` field, not a step). Everything connector-specific is
reachable through `connection`/`mcp_call`/`http`.

---

## 6. The MCP builder surface — the crown jewel, preserved (writes FILES)

The essential agent loop from myctobot, retargeted to write instance files:
`get_pipeline_components` → `set_pipeline(name, steps[], dry_run)` → `run_pipeline`
→ `get_run` → iterate → flip `expose_as_tool`/`expose_as_api`. ~11 tools:

- **Build:** `get_pipeline_components` (the ~9 step schemas + **this instance's
  connections** so the agent knows what it can wire), `set_pipeline` (create/update the
  whole pipeline incl. `steps[]` in ONE call, with `dry_run` validation → writes
  `pipelines/<slug>.json`), `get_pipeline`, `list_pipelines`, `delete_pipeline`,
  `set_step`/`delete_step` (convenience edits).
- **Run:** `run_pipeline`, `get_run`, `continue_pipeline` (resume an `await_input`),
  `cancel_run`.
- **Pipeline-as-tool:** any `expose_as_tool` pipeline is dynamically registered on the
  instance's own `/mcp` endpoint with its `context_schema` (mirrors
  `Mcppipelines.php:143`), executed async, returns a run id.

Export/import are trivial (the file is the format). Schedule/CRUD collapse into the
`trigger` field. The 6 tmux "inter-agent" tools (`send_to_step`, `list_run_sessions`)
are dropped.

---

## 7. Connections — instance-scoped, via the existing broker

The owner's requirement — "dapps have access to added connections, but only on the
instance it runs for" — is already solved by tiknix's custody model. The `connection`
step calls the instance's **broker token** (`brk_…` in the instance's `conf/broker.ini`,
minted by `lib/BrokerService`) → core `Mcp::brokerToolCall` (`controls/Mcp.php:1121`),
which decrypts that connection server-side (`EncryptionService`) and runs the tool,
scoped by the token's own `instance_id`. So a pipeline can never reach another
instance's connection — the broker token is the boundary. Zero new custody code; the
step just wraps the broker call the shop sidecar already proved.

---

## 8. Run surfaces

1. **MCP tool** — `expose_as_tool` → the instance's `/mcp` (agents run it).
2. **Instance code/UI** — `\app\Pipeline::run('lead-triage', $ctx)` from any controller,
   or a button. This is the "part of the code" use — pipelines power real features.
3. **REST API** — `expose_as_api` → `POST /pipeline/api/<slug>` on the instance, authed
   by a **per-member API key in the instance's own DB** (`pipeapikey` bean, `member_id`
   + `key_class` pattern from `apikey`/`BrokerService.php:22`). Keys are **minted by
   admin/root only** (a gated in-instance UI, `Flight::hasLevel(LEVELS['ADMIN'])`), each
   bound to a member; the call runs as that member. Returns the run result (sync) or a
   run id (async).
4. **Trigger** — every scheduled/triggered run is just an HTTP hit on the pipeline's
   **trigger endpoint** (`POST /pipeline/trigger/<slug>`, signed with a cron/HMAC
   secret), which validates and calls the background dispatcher (§9). So the
   **fake-cron only TRIGGERS, it never executes**: ONE system tick (a single cron
   entry / capricorn daemon, every minute) globs `…/*/pipelines/*.json`, finds
   `trigger.cron` pipelines that are due (cron-expr + last-run tracking), and simply
   `curl`s each due pipeline's trigger endpoint. It needs no jail/DB access — just
   HTTP. Decouples scheduling (HTTP) from execution (jailed dispatch). The
   **cron/schedule editor** is a view in the `pipelines.tiknix` editor — cron lives in
   each file's `trigger.cron`, so editing a schedule = editing the file; the view
   surfaces every scheduled pipeline + next/last-run across an instance.
5. **Editor** — manual run + live watch while building.

All five hit the same `Executor::run($def, $context, $source)`.

---

## 9. The executor (lib/Pipeline/Executor.php, ~800 ln)

- Load def from the file (Loader globs `pipelines/*.json`, validates against the
  registry). Create ONE `piperun` (status, context snapshot, source). **Create
  `pipesteprun` rows lazily** as each step starts (fixes the 58%-orphan bug).
- Walk steps in order; a run of consecutive `parallel` steps dispatches concurrently
  (background processes; `PipelineDispatcher`'s sync→async fallback is the one engine
  ceremony worth keeping, `services/PipelineDispatcher.php:*`) then joins.
- Resolve `{vars}` per step (the ported substitution core). Run the step type. Persist
  output/stdout/stderr/exit/duration. Apply `on_success`/`on_fail` (`next|goto|exit`).
- `await_input` pauses the run with a resume token; `continue_pipeline` resumes.
- Jailed exactly like the AI Builder agents when the workspace is a capricorn instance.

---

## 10. Phased delivery (each independently landable)

- **Phase 1 — core runtime (no editor, no MCP).** `lib/Pipeline/{Loader,Executor,Vars,
  StepRegistry}` + Run/StepRun beans + the `pipelines/` file convention + 5 step types
  (`shell,http,transform,branch,notify`) + `Pipeline::run()`. Prove a file-defined
  pipeline runs on an instance with run history. Ships in CORE so instances inherit it.
- **Phase 2 — the MCP builder.** `set_pipeline` (writes files, `dry_run`), `run/get_run`,
  and `expose_as_tool` registration on the instance's `/mcp`. Agents build + run.
- **Phase 3 — the rich step types.** `agent` (EngineRegistry), `mcp_call`, `connection`
  (broker, §7), `wait/await_input`, `db_query`.
- **Phase 4 — exposure.** REST API + per-user `pipeapikey` (+ a tiny mint-key UI);
  `trigger.cron`/`webhook`.
- **Phase 5 — the editor sidecar** (`pipelines.tiknix` on `lib/Sidecar/*`): list/edit
  pipeline files in owned instances, run + live-watch, Bootstrap UI. Reuses the whole
  kit (SSO, ownership scoping, allowlist) — it's the third plugin after explorer/shop.

---

## 11. Deliberately NOT copied

Retry machinery (0 uses) · the stubbed condition evaluator (we build a real one) ·
up-front step-run pre-creation · tmux resident sessions + reaper/harvest · the dapp
chatbot runtime + all dapp packaging (aspirational, 0 rows — files+git are our
"release") · the Studio wizard/templates (agents build via MCP) · per-workspace
generated engine daemons (`PipelineEngineClient/Builder`) · the 3 drifted step-type
schema sources · Shopify/Stitch/LinkedIn hardcoded steps · the 56-route Pipelines
controller.

---

## 12. Decisions locked + remaining risks

- **RESOLVED — Agent step = jailed runner.** The `agent` step reuses `lib/EngineRegistry`
  + the AI Builder's jailed agent path; no second agent runtime. (Watch fan-out quotas —
  risk below.)
- **RESOLVED — REST keys are per-member, admin/root-minted.** `pipeapikey` rows in the
  instance DB carry `member_id`; only ADMIN/ROOT can mint them (gated in-instance UI); a
  call authenticates as its bound member.

Remaining risks:
1. **Concurrency without a daemon.** Opt-in parallel via background processes is
   simplest; if an instance runs many heavy parallel pipelines (esp. fan-out `agent`
   steps, each a jailed runner), revisit a small worker + a concurrency cap. The
   `PipelineDispatcher` sync→async fallback covers v1.
2. **File vs runtime drift.** Editing a pipeline file by hand vs via the editor/MCP must
   converge — the file is canonical; the editor/MCP always rewrite the whole file
   (`set_pipeline` semantics), never a partial DB row. No sync layer needed.
5. **Webhook triggers are public.** Sign them (HMAC) or require an apikey, same as the
   REST surface — don't leave a naked public run endpoint.
