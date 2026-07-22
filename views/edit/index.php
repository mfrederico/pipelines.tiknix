<?php /** @var array $instances @var string $email @var array $components */
$h = fn($s) => htmlspecialchars((string) $s); ?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pipeline Editor</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  body { background:#0b1530; }
  .navbar { border-bottom:1px solid rgba(255,255,255,.1); }
  .brand b { color:#3b76f0; }
  .side { max-height:calc(100vh - 120px); overflow:auto; }
  .plist .list-group-item { cursor:pointer; background:transparent; }
  .plist .list-group-item.active { background:#243056; border-color:#3b76f0; }
  #editor { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:13px; min-height:52vh; white-space:pre; tab-size:2; }
  .step-row { font-family:ui-monospace,monospace; font-size:12.5px; }
  .st-completed{color:#3bbf7a}.st-failed{color:#e0559b}.st-running{color:#e0a23b}.st-awaiting{color:#8ab4ff}
  .comp code { color:#8ab4ff; }
  .comp .cfg { color:#9ba4bd; font-size:12px; }
  .muted { color:#9ba4bd; }
  pre.out { background:#060d20; border:1px solid rgba(255,255,255,.1); border-radius:8px; padding:.6rem; font-size:12px; max-height:30vh; overflow:auto; }
</style>
</head>
<body>
<nav class="navbar px-3">
  <span class="navbar-brand mb-0">Pipeline <b>Editor</b></span>
  <div class="d-flex align-items-center gap-2">
    <select id="inst" class="form-select form-select-sm" style="width:auto">
      <?php foreach ($instances as $i): ?>
        <option value="<?= $h($i['slug']) ?>"><?= $h($i['name']) ?> (<?= $h($i['slug']) ?>)<?= $i['owned'] ? '' : ' · team' ?></option>
      <?php endforeach; ?>
    </select>
    <span class="muted small"><?= $h($email) ?></span>
    <a class="btn btn-sm btn-outline-secondary" href="/sso/logout">Sign out</a>
  </div>
</nav>

<?php if (!$instances): ?>
  <div class="container py-5 text-center muted">You have no instances yet. Create one in the AI Builder, then build pipelines here.</div>
<?php else: ?>
<div class="container-fluid py-3">
  <div class="row g-3">
    <!-- pipeline list -->
    <div class="col-lg-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="text-uppercase muted mb-0">Pipelines</h6>
        <button class="btn btn-sm btn-primary" onclick="newPipeline()"><i class="bi bi-plus-lg"></i> New</button>
      </div>
      <div id="plist" class="list-group plist side"></div>
    </div>

    <!-- editor -->
    <div class="col-lg-6">
      <div class="d-flex gap-2 mb-2">
        <button class="btn btn-sm btn-outline-info" onclick="validateDef()"><i class="bi bi-check2-circle"></i> Validate</button>
        <button class="btn btn-sm btn-success" onclick="saveDef()"><i class="bi bi-save"></i> Save</button>
        <button class="btn btn-sm btn-outline-warning" onclick="runDef()"><i class="bi bi-play-fill"></i> Run</button>
        <button class="btn btn-sm btn-outline-danger ms-auto" onclick="deleteDef()"><i class="bi bi-trash"></i></button>
      </div>
      <textarea id="editor" class="form-control" spellcheck="false"></textarea>
      <div id="msg" class="small mt-2"></div>
    </div>

    <!-- run watch + components -->
    <div class="col-lg-3 side">
      <h6 class="text-uppercase muted">Run</h6>
      <label class="small muted">Test context (JSON)</label>
      <textarea id="ctx" class="form-control form-control-sm mb-2" rows="2" spellcheck="false">{}</textarea>
      <div id="runbox" class="mb-3"></div>

      <h6 class="text-uppercase muted mt-2">Step types</h6>
      <div class="comp small">
        <?php foreach ($components as $type => $c): ?>
          <div class="mb-2">
            <code><?= $h($type) ?></code> — <?= $h($c['summary'] ?? '') ?>
            <?php if (!empty($c['config'])): ?>
              <div class="cfg"><?php foreach ($c['config'] as $k => $desc): ?>&nbsp;&nbsp;<b><?= $h($k) ?></b>: <?= $h($desc) ?><br><?php endforeach; ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
"use strict";
const $ = s => document.querySelector(s);
const inst = () => $('#inst').value;
let CURRENT = null, watchTimer = null;

const TEMPLATE = {
  slug: "my-pipeline", name: "My pipeline", description: "",
  expose_as_tool: false, expose_as_api: false,
  context_schema: { name: { type: "string", required: true } },
  steps: [ { name: "greet", type: "transform", config: { mode: "template", input: "Hello {context.name}" }, on_success: "exit" } ]
};

async function jget(u){ const r=await fetch(u,{headers:{Accept:'application/json'}}); const d=await r.json().catch(()=>({})); if(!r.ok) throw new Error(d.message||('HTTP '+r.status)); return d; }
async function jpost(u,body){ const r=await fetch(u,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(body)}); const d=await r.json().catch(()=>({})); if(!r.ok) throw new Error(d.message||('HTTP '+r.status)); return d; }
function msg(text,cls){ $('#msg').innerHTML = text ? `<span class="text-${cls}">${esc(text)}</span>` : ''; }
function esc(s){ return String(s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

async function loadList(){
  try {
    const d = await jget('/edit/pipelines?inst='+encodeURIComponent(inst()));
    const el = $('#plist'); el.innerHTML='';
    d.pipelines.forEach(p=>{
      const badges = (p.expose_as_tool?'<span class="badge text-bg-primary ms-1">tool</span>':'')
        + (p.expose_as_api?'<span class="badge text-bg-info ms-1">api</span>':'')
        + (p.cron?'<span class="badge text-bg-secondary ms-1" title="'+esc(p.cron)+'"><i class="bi bi-clock"></i></span>':'');
      const a=document.createElement('div'); a.className='list-group-item'+(CURRENT===p.slug?' active':'');
      a.innerHTML=`<div class="d-flex justify-content-between"><span><b>${esc(p.name)}</b><br><span class="muted small">${esc(p.slug)} · ${p.steps} steps</span></span><span>${badges}</span></div>`;
      a.onclick=()=>openPipeline(p.slug);
      el.appendChild(a);
    });
    if(!d.pipelines.length) el.innerHTML='<div class="muted small p-2">No pipelines yet — click New.</div>';
  } catch(e){ msg(e.message,'danger'); }
}
async function openPipeline(slug){
  try { const d=await jget('/edit/get?inst='+encodeURIComponent(inst())+'&slug='+encodeURIComponent(slug));
    CURRENT=slug; $('#editor').value=JSON.stringify(d.def,null,2); msg('',''); $('#runbox').innerHTML=''; loadList();
  } catch(e){ msg(e.message,'danger'); }
}
function newPipeline(){ CURRENT=null; $('#editor').value=JSON.stringify(TEMPLATE,null,2); msg('New pipeline — edit the slug + steps, then Save.','info'); loadList(); }
function parseDef(){ try { return JSON.parse($('#editor').value); } catch(e){ msg('Invalid JSON: '+e.message,'danger'); return null; } }

async function validateDef(){ const def=parseDef(); if(!def) return;
  try { const d=await jpost('/edit/validate',{inst:inst(),def:JSON.stringify(def)});
    d.valid ? msg('Valid ✓','success') : msg('Invalid: '+d.errors.join('; '),'danger');
  } catch(e){ msg(e.message,'danger'); } }
async function saveDef(){ const def=parseDef(); if(!def) return;
  try { const d=await jpost('/edit/save',{inst:inst(),def:JSON.stringify(def)});
    if(d.ok){ CURRENT=def.slug; msg('Saved '+d.file+' ✓','success'); loadList(); }
    else msg('Not saved: '+(d.errors||[]).join('; '),'danger');
  } catch(e){ msg(e.message,'danger'); } }
async function deleteDef(){ if(!CURRENT||!confirm('Delete '+CURRENT+'?')) return;
  try { await jpost('/edit/delete',{inst:inst(),slug:CURRENT}); CURRENT=null; $('#editor').value=''; loadList(); msg('Deleted.','muted'); }
  catch(e){ msg(e.message,'danger'); } }

async function runDef(){ const def=parseDef(); if(!def||!def.slug) { msg('Save the pipeline first.','warning'); return; }
  try {
    const d=await jpost('/edit/run',{inst:inst(),slug:def.slug,context:$('#ctx').value||'{}'});
    watchRun(d.run_id);
  } catch(e){ msg(e.message,'danger'); }
}
function watchRun(runId){
  if(watchTimer) clearInterval(watchTimer);
  const render = (r)=>{
    let html=`<div class="mb-1"><b>Run #${runId}</b> <span class="st-${r.status}">${esc(r.status)}</span> <span class="muted">${r.steps_done}/${r.steps_total}</span></div>`;
    (r.steps||[]).forEach(s=>{ html+=`<div class="step-row st-${s.status}">${s.status==='completed'?'✓':s.status==='failed'?'✗':'…'} ${esc(s.step)} <span class="muted">[${esc(s.type)}] ${s.duration_ms}ms</span>${s.stderr?'<br><span class="text-danger">'+esc(s.stderr)+'</span>':''}</div>`; });
    if(r.await_prompt) html+=`<div class="text-info small mt-1">⏸ awaiting: ${esc(r.await_prompt)}</div>`;
    if(r.output!=null) html+=`<div class="muted small mt-2">output:</div><pre class="out">${esc(JSON.stringify(r.output,null,2))}</pre>`;
    $('#runbox').innerHTML=html;
  };
  const poll=async()=>{
    try{ const r=await jget('/edit/runstatus?inst='+encodeURIComponent(inst())+'&run_id='+runId); render(r);
      if(['completed','failed','paused'].includes(r.status)){ clearInterval(watchTimer); watchTimer=null; }
    }catch(e){ /* run may not be written yet — keep polling briefly */ }
  };
  $('#runbox').innerHTML='<div class="muted small">Starting run…</div>';
  poll(); watchTimer=setInterval(poll,1000);
}

$('#inst') && ($('#inst').onchange=()=>{ CURRENT=null; $('#editor').value=''; loadList(); });
if (<?= $instances ? 'true':'false' ?>) loadList();
</script>
</body>
</html>
