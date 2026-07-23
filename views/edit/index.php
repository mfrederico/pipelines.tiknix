<?php
/**
 * Pipeline Editor — spreadsheet step builder + step-trace debugger, in the tiknix
 * design system (fonts + --ui-* tokens pulled from core). A dense row-per-step grid
 * (drag to reorder; name/type/flow editable inline); click a row to reveal its full
 * config card inline. No hand-authored JSON — it's the on-disk format only, behind an
 * "Advanced" toggle.
 *
 * @var array  $instances  accessible instances [{slug,name,owned,app}]
 * @var string $email      signed-in member email
 * @var array  $components  type => {summary, fields[], config}
 */
$h = fn($s) => htmlspecialchars((string) $s);
$coreUrl  = rtrim((string) (\Flight::get('sidecar.core_url') ?? ''), '/');
$coreRoot = rtrim((string) (\Flight::get('sidecar.core_root') ?? ''), '/');
$dsFile   = $coreRoot . '/views/components/design-system.php';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pipeline Editor · tiknix</title>
<script>(function(){try{var t=localStorage.getItem('ui-theme');if(t)document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}})();</script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php if ($coreUrl): ?><link href="<?= $h($coreUrl) ?>/css/app.css" rel="stylesheet"><?php endif; ?>
<?php if (is_file($dsFile)) include $dsFile;   // tiknix fonts + --ui-* tokens + ui-* component classes ?>
<style>
  .pe-topbar .brand-word{font-family:'Playfair Display',Georgia,serif;font-weight:600;font-size:1.35rem;letter-spacing:.005em;}
  .pe-list .item{cursor:pointer;border:1px solid var(--bs-border-color);background:var(--ui-surface);border-radius:.75rem;padding:.6rem .75rem;margin-bottom:.5rem;transition:.12s;}
  .pe-list .item:hover{border-color:var(--ui-primary);}
  .pe-list .item.active{border-color:var(--ui-primary);box-shadow:0 0 0 1px var(--ui-primary) inset;}
  .pe-list .item .nm{font-weight:600;font-family:var(--ui-ff-display);}

  /* spreadsheet step grid */
  .ss{border:1px solid var(--bs-border-color);border-radius:.9rem;overflow:hidden;background:var(--ui-surface);box-shadow:var(--ui-shadow);}
  .ss-cols{grid-template-columns:20px 26px minmax(6rem,1fr) 7.5rem 8.5rem 8.5rem 28px;}
  .ss-head,.ss-row{display:grid;gap:.4rem;align-items:center;padding:.3rem .55rem;}
  .ss-head{background:var(--ui-surface-soft);border-bottom:1px solid var(--bs-border-color);font-family:var(--ui-ff-mono);font-size:.62rem;letter-spacing:.14em;text-transform:uppercase;color:var(--bs-tertiary-color);}
  .ss-row-wrap{border-bottom:1px solid var(--bs-border-color);transition:background .1s;}
  .ss-row-wrap:last-child{border-bottom:none;}
  .ss-row-wrap:hover{background:var(--ui-surface-soft);}
  .ss-row-wrap.open{background:var(--ui-surface-soft);}
  .ss-row-wrap.dbg-cur{box-shadow:inset 3px 0 0 var(--ui-accent-report);}
  .ss-ghost{opacity:.35;}
  .ss-row .drag{cursor:grab;color:var(--bs-tertiary-color);text-align:center;}
  .ss-row .drag:active{cursor:grabbing;}
  .ss-row .idx{width:22px;height:22px;border-radius:50%;background:var(--ui-primary);color:#fff;display:grid;place-items:center;font-family:var(--ui-ff-mono);font-size:.72rem;font-weight:600;}
  .ss-summary{font-family:var(--ui-ff-mono);font-size:11px;color:var(--bs-tertiary-color);padding:0 .55rem .3rem 3rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .ss-card{padding:.65rem .8rem .85rem;border-top:1px dashed var(--bs-border-color);}
  .fld-help{font-size:.76rem;color:var(--bs-tertiary-color);}
  .fld-label{font-size:.78rem;font-weight:600;color:var(--bs-secondary-color);margin-bottom:.15rem;}
  .fld-label .req,.req{color:var(--ui-accent-report);}
  .mono,#jsonBox,.bag-view{font-family:var(--ui-ff-mono);font-size:12.5px;}
  .st-completed{color:#3bbf7a}.st-failed{color:#e0559b}.st-running,.st-awaiting{color:#e0a23b}.st-pending,.st-paused{color:var(--bs-tertiary-color)}
  .trace-step{border:1px solid var(--bs-border-color);border-radius:.6rem;padding:.45rem .6rem;margin-bottom:.4rem;font-size:.85rem;}
  .trace-step.cur{border-color:var(--ui-primary);box-shadow:0 0 0 1px var(--ui-primary) inset;}
  pre.io{background:var(--ui-surface-inset);border-radius:.5rem;padding:.5rem;margin:.35rem 0 0;font-size:11.5px;max-height:26vh;overflow:auto;white-space:pre-wrap;word-break:break-word;}
  .type-menu{max-height:60vh;overflow:auto;}
  .type-menu .t{font-family:var(--ui-ff-mono);}
  .comp code{color:var(--bs-link-color);}
  textarea.code{font-family:var(--ui-ff-mono);font-size:12.5px;white-space:pre;tab-size:2;}
</style>
</head>
<body>

<header class="ui-topbar pe-topbar">
  <a href="/edit" class="text-decoration-none d-flex align-items-center gap-2" style="color:inherit">
    <span class="brand-word">tiknix</span>
    <span class="ui-eyebrow" style="letter-spacing:.16em">Pipelines</span>
  </a>
  <div class="ms-auto d-flex align-items-center gap-2">
    <?php if ($instances): ?>
    <select id="inst" class="form-select form-select-sm" style="width:auto">
      <?php foreach ($instances as $i): ?>
        <option value="<?= $h($i['slug']) ?>"><?= $h($i['name']) ?> (<?= $h($i['slug']) ?>)<?= $i['owned'] ? '' : ' · team' ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <button class="ui-btn-icon" id="themeToggle" type="button" title="Toggle theme"><i class="bi bi-moon-stars"></i></button>
    <span class="d-none d-md-inline" style="font-size:.85rem;color:var(--bs-secondary-color)"><?= $h($email) ?></span>
    <a class="btn btn-sm btn-outline-secondary" href="/sso/logout">Sign out</a>
  </div>
</header>

<div class="ui-content">
<?php if (!$instances): ?>
  <div class="ui-panel"><div class="ui-panel-body text-center" style="color:var(--bs-secondary-color)">
    You have no instances yet. Create one in the AI Builder, then build pipelines here.
  </div></div>
<?php else: ?>
  <div class="row g-3">

    <!-- ============ pipeline list ============ -->
    <div class="col-lg-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="ui-eyebrow">Pipelines</span>
        <button class="btn btn-sm btn-primary" onclick="newPipeline()"><i class="bi bi-plus-lg"></i> New</button>
      </div>
      <div id="plist" class="pe-list"></div>
    </div>

    <!-- ============ builder ============ -->
    <div class="col-lg-6">
      <div class="d-flex gap-2 mb-3 flex-wrap">
        <button class="btn btn-sm btn-outline-info" onclick="validateDef()"><i class="bi bi-check2-circle"></i> Validate</button>
        <button class="btn btn-sm btn-primary" onclick="saveDef()"><i class="bi bi-save"></i> Save</button>
        <button class="btn btn-sm btn-success" onclick="runDef()"><i class="bi bi-play-fill"></i> Run</button>
        <button class="btn btn-sm btn-warning" onclick="debugStart()"><i class="bi bi-bug"></i> Debug</button>
        <button class="btn btn-sm btn-outline-secondary ms-auto" onclick="toggleJson()"><i class="bi bi-code-slash"></i> JSON</button>
        <button class="btn btn-sm btn-outline-danger" onclick="deleteDef()"><i class="bi bi-trash"></i></button>
      </div>
      <div id="msg" class="small mb-2"></div>

      <div id="builder">
        <div class="ui-panel"><div class="ui-panel-body" style="color:var(--bs-secondary-color)">Select a pipeline, or click <b>New</b>.</div></div>
      </div>

      <!-- advanced JSON -->
      <div id="jsonWrap" class="ui-panel mt-3" style="display:none">
        <div class="ui-panel-header"><span class="ui-eyebrow">Advanced · raw JSON</span>
          <button class="btn btn-sm btn-outline-primary" onclick="applyJson()">Apply JSON → builder</button></div>
        <div class="ui-panel-body"><textarea id="jsonBox" class="form-control code" rows="16" spellcheck="false"></textarea></div>
      </div>
    </div>

    <!-- ============ run / debug + reference ============ -->
    <div class="col-lg-3">
      <div class="ui-panel mb-3">
        <div class="ui-panel-header"><span class="ui-eyebrow">Run / Debug</span></div>
        <div class="ui-panel-body">
          <label class="fld-label">Test context (JSON)</label>
          <textarea id="ctx" class="form-control form-control-sm code mb-2" rows="3" spellcheck="false">{}</textarea>
          <div id="runbox" class="small"></div>
        </div>
      </div>

      <div class="ui-panel">
        <div class="ui-panel-header"><span class="ui-eyebrow">Step types</span></div>
        <div class="ui-panel-body comp small">
          <?php foreach ($components as $type => $c): ?>
            <div class="mb-2"><code><?= $h($type) ?></code> — <?= $h($c['summary'] ?? '') ?></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  </div>
<?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
"use strict";
const COMPONENTS = <?= json_encode($components, JSON_UNESCAPED_SLASHES) ?> || {};
const TYPES = Object.keys(COMPONENTS);
const $ = s => document.querySelector(s);
const inst = () => $('#inst') ? $('#inst').value : '';
let DEF = null, CURRENT = null, watchTimer = null, DEBUG = null, OPEN = null, sortable = null, UIDSEQ = 1, schedForceCustom = false;

// ---- theme toggle (shares the tiknix 'ui-theme' key) ----
$('#themeToggle') && ($('#themeToggle').onclick = () => {
  const cur = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-bs-theme', cur);
  try { localStorage.setItem('ui-theme', cur); } catch(e){}
});

// ---- helpers ----
function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
function msg(t,cls){ $('#msg').innerHTML = t ? `<span class="text-${cls}">${esc(t)}</span>` : ''; }
function trunc(v){ const s = typeof v==='string' ? v : JSON.stringify(v); return s && s.length>52 ? s.slice(0,52)+'…' : (s||''); }
async function jget(u){ const r=await fetch(u,{headers:{Accept:'application/json'}}); const d=await r.json().catch(()=>({})); if(!r.ok) throw new Error(d.message||('HTTP '+r.status)); return d; }
async function jpost(u,b){ const r=await fetch(u,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(b)}); const d=await r.json().catch(()=>({})); if(!r.ok) throw new Error(d.message||('HTTP '+r.status)); return d; }
function uid(s){ Object.defineProperty(s,'__uid',{value:UIDSEQ++,enumerable:false,writable:true,configurable:true}); return s; }

const TEMPLATE = () => ({
  slug:"my-pipeline", name:"My pipeline", description:"",
  expose_as_tool:false, expose_as_api:false, trigger:{cron:""},
  context_schema:{ name:{ type:"string", required:true } },
  steps:[ { name:"greet", type:"transform", config:{ mode:"template", input:"Hello {context.name}" }, on_success:"exit", on_fail:"exit" } ]
});

// ---- pipeline list ----
async function loadList(){
  try{
    const d = await jget('/edit/pipelines?inst='+encodeURIComponent(inst()));
    const el=$('#plist'); el.innerHTML='';
    if(!d.pipelines.length){ el.innerHTML='<div class="small" style="color:var(--bs-tertiary-color)">No pipelines yet — click New.</div>'; return; }
    d.pipelines.forEach(p=>{
      const badges=(p.expose_as_tool?'<span class="ui-chip" style="padding:.05rem .45rem;font-size:.7rem">tool</span> ':'')
        +(p.expose_as_api?'<span class="ui-chip" style="padding:.05rem .45rem;font-size:.7rem">api</span> ':'')
        +(p.cron?'<span class="ui-chip" style="padding:.05rem .45rem;font-size:.7rem" title="'+esc(p.cron)+'"><i class="bi bi-clock"></i></span>':'');
      const div=document.createElement('div'); div.className='item'+(CURRENT===p.slug?' active':'');
      div.innerHTML=`<div class="d-flex justify-content-between align-items-start gap-2"><div><div class="nm">${esc(p.name)}</div><div class="small" style="color:var(--bs-tertiary-color)">${esc(p.slug)} · ${p.steps} steps</div></div><div class="text-end">${badges}</div></div>`;
      div.onclick=()=>openPipeline(p.slug);
      el.appendChild(div);
    });
  }catch(e){ msg(e.message,'danger'); }
}
async function openPipeline(slug){
  try{ const d=await jget('/edit/get?inst='+encodeURIComponent(inst())+'&slug='+encodeURIComponent(slug));
    DEF=normalize(d.def); CURRENT=slug; DEBUG=null; OPEN=null; schedForceCustom=false; $('#runbox').innerHTML=''; msg('',''); renderBuilder(); loadList();
  }catch(e){ msg(e.message,'danger'); }
}
function newPipeline(){ DEF=normalize(TEMPLATE()); CURRENT=null; DEBUG=null; OPEN=null; schedForceCustom=false; $('#runbox').innerHTML=''; msg('New pipeline — edit + Save.','info'); renderBuilder(); loadList(); }

function normalize(def){
  def=def||{}; def.steps=Array.isArray(def.steps)?def.steps:[];
  def.steps.forEach(s=>{ s.config=s.config||{}; s.on_success=s.on_success||'next'; s.on_fail=s.on_fail||'exit'; if(s.__uid===undefined) uid(s); });
  def.context_schema=def.context_schema||{}; def.trigger=def.trigger||{};
  return def;
}

// ---- builder render ----
function renderBuilder(){
  if(!DEF) return;
  const settings = `
    <div class="ui-panel mb-3"><div class="ui-panel-body">
      <div class="row g-2">
        <div class="col-6"><div class="fld-label">Slug <span class="req">*</span></div>
          <input class="form-control form-control-sm" data-meta="slug" value="${esc(DEF.slug||'')}"></div>
        <div class="col-6"><div class="fld-label">Name</div>
          <input class="form-control form-control-sm" data-meta="name" value="${esc(DEF.name||'')}"></div>
        <div class="col-12"><div class="fld-label">Description</div>
          <input class="form-control form-control-sm" data-meta="description" value="${esc(DEF.description||'')}"></div>
        <div class="col-6 d-flex align-items-center gap-2 pt-2">
          <div class="form-check form-switch"><input class="form-check-input" type="checkbox" data-meta="expose_as_tool" ${DEF.expose_as_tool?'checked':''}><label class="form-check-label small">Expose as MCP tool</label></div>
        </div>
        <div class="col-6 d-flex align-items-center gap-2 pt-2">
          <div class="form-check form-switch"><input class="form-check-input" type="checkbox" data-meta="expose_as_api" ${DEF.expose_as_api?'checked':''}><label class="form-check-label small">Expose as REST API</label></div>
        </div>
        <div class="col-12"><div class="fld-label">Schedule</div><div id="sched"></div></div>
        <div class="col-12"><div class="fld-label">Context variables</div><div id="ctxRows"></div>
          <button class="btn btn-sm btn-outline-secondary mt-1" onclick="addCtxVar()"><i class="bi bi-plus"></i> variable</button></div>
      </div>
    </div></div>`;

  const rows = DEF.steps.map((s,i)=>renderRow(s,i)).join('') ||
    '<div class="p-3 small" style="color:var(--bs-tertiary-color)">No steps yet — add one below.</div>';
  const grid = `
    <div class="ss">
      <div class="ss-head ss-cols"><span></span><span>#</span><span>Name</span><span>Type</span><span>→ ok</span><span>→ fail</span><span></span></div>
      <div id="steps">${rows}</div>
    </div>`;

  const addMenu = `
    <div class="dropdown mt-2">
      <button class="btn btn-outline-primary w-100" data-bs-toggle="dropdown"><i class="bi bi-plus-lg"></i> Add step</button>
      <ul class="dropdown-menu type-menu w-100">
        ${TYPES.map(t=>`<li><a class="dropdown-item" href="#" onclick="addStep('${t}');return false"><span class="t">${esc(t)}</span><div class="small" style="color:var(--bs-tertiary-color)">${esc(COMPONENTS[t].summary||'')}</div></a></li>`).join('')}
      </ul>
    </div>`;

  $('#builder').innerHTML = settings + grid + addMenu;
  initSortable(); renderCtxRows(); renderSchedule(); syncJson();
}

// ---- schedule (cron) builder — friendly UI over the stored 5-field cron ----
const DOW=['Su','Mo','Tu','We','Th','Fr','Sa'];
const SCHED_DEFAULT={off:'',minutes:'*/15 * * * *',hours:'0 */1 * * *',daily:'0 9 * * *',weekly:'0 9 * * 1',monthly:'0 9 1 * *'};
function pad2(n){ return String(n).padStart(2,'0'); }
function hmToTime(h,m){ return pad2(h||0)+':'+pad2(m||0); }
function parseCron(expr){
  expr=(expr||'').trim(); if(!expr) return {mode:'off'};
  const f=expr.split(/\s+/); if(f.length!==5) return {mode:'custom', raw:expr};
  const [mi,ho,dom,mon,dow]=f, num=s=>/^\d+$/.test(s), star=(...a)=>a.every(x=>x==='*'); let m;
  if((m=mi.match(/^\*\/(\d+)$/)) && star(ho,dom,mon,dow)) return {mode:'minutes', n:+m[1]};
  if(mi==='0' && (m=ho.match(/^\*\/(\d+)$/)) && star(dom,mon,dow)) return {mode:'hours', n:+m[1]};
  if(num(mi)&&num(ho)&&star(dom,mon,dow)) return {mode:'daily', h:+ho, m:+mi};
  if(num(mi)&&num(ho)&&dom==='*'&&mon==='*'&&/^[0-6](,[0-6])*$/.test(dow)) return {mode:'weekly', h:+ho, m:+mi, days:dow.split(',').map(Number)};
  if(num(mi)&&num(ho)&&num(dom)&&mon==='*'&&dow==='*') return {mode:'monthly', h:+ho, m:+mi, dom:+dom};
  return {mode:'custom', raw:expr};
}
function buildCron(s){
  switch(s.mode){
    case 'minutes': return `*/${s.n||15} * * * *`;
    case 'hours':   return `0 */${s.n||1} * * *`;
    case 'daily':   return `${s.m||0} ${s.h||0} * * *`;
    case 'weekly':  { const d=(s.days&&s.days.length?s.days:[1]).slice().sort((a,b)=>a-b); return `${s.m||0} ${s.h||0} * * ${d.join(',')}`; }
    case 'monthly': return `${s.m||0} ${s.h||0} ${s.dom||1} * *`;
    case 'custom':  return (s.raw||'').trim();
    default:        return '';
  }
}
function cronEnglish(s){
  switch(s.mode){
    case 'off':     return 'no schedule — runs on manual / API / trigger only';
    case 'minutes': return `every ${s.n} minute${s.n==1?'':'s'}`;
    case 'hours':   return `every ${s.n} hour${s.n==1?'':'s'}`;
    case 'daily':   return `daily at ${hmToTime(s.h,s.m)}`;
    case 'weekly':  { const d=(s.days||[]).slice().sort((a,b)=>a-b).map(x=>DOW[x]); return `weekly on ${d.join(', ')||'—'} at ${hmToTime(s.h,s.m)}`; }
    case 'monthly': return `monthly on day ${s.dom} at ${hmToTime(s.h,s.m)}`;
    default:        return 'custom expression';
  }
}
function validCronClient(expr){ const f=(expr||'').trim().split(/\s+/); if(f.length!==5) return false; const R=[[0,59],[0,23],[1,31],[1,12],[0,7]];
  return f.every((spec,i)=>spec.split(',').every(part=>{ if(part==='') return false; let p=part; if(p.includes('/')){ const [pp,st]=p.split('/'); if(!/^\d+$/.test(st)||+st<1) return false; p=pp; } if(p==='*') return true; if(p.includes('-')){ const [a,b]=p.split('-'); return /^\d+$/.test(a)&&/^\d+$/.test(b)&&+a>=R[i][0]&&+b<=R[i][1]&&+a<=+b; } return /^\d+$/.test(p)&&+p>=R[i][0]&&+p<=R[i][1]; })); }
function renderSchedule(){
  const host=$('#sched'); if(!host||!DEF) return;
  const cur=(DEF.trigger&&DEF.trigger.cron)||'';
  const s = schedForceCustom ? {mode:'custom', raw:cur} : parseCron(cur);
  const modes=[['off','Off — manual / API only'],['minutes','Every N minutes'],['hours','Every N hours'],['daily','Daily at…'],['weekly','Weekly on…'],['monthly','Monthly on…'],['custom','Custom cron']];
  const modeSel=`<select id="schedMode" class="form-select form-select-sm" style="max-width:13rem">${modes.map(([v,l])=>`<option value="${v}" ${s.mode===v?'selected':''}>${l}</option>`).join('')}</select>`;
  let ctl='';
  if(s.mode==='minutes'||s.mode==='hours'){
    const base=(s.mode==='minutes'?[1,2,5,10,15,20,30]:[1,2,3,4,6,8,12]); if(s.n&&!base.includes(s.n)) base.push(s.n);
    ctl=`Every <select id="schedN" class="form-select form-select-sm d-inline-block" style="width:auto">${base.sort((a,b)=>a-b).map(o=>`<option ${o===s.n?'selected':''}>${o}</option>`).join('')}</select> ${s.mode}`;
  } else if(s.mode==='daily'){
    ctl=`at <input type="time" id="schedTime" class="form-control form-control-sm d-inline-block" style="width:auto" value="${hmToTime(s.h,s.m)}">`;
  } else if(s.mode==='weekly'){
    ctl=`<div class="btn-group btn-group-sm mb-2" role="group">${DOW.map((d,i)=>`<button type="button" class="btn btn-outline-secondary sched-day ${(s.days||[]).includes(i)?'active':''}" data-day="${i}">${d}</button>`).join('')}</div><div>at <input type="time" id="schedTime" class="form-control form-control-sm d-inline-block" style="width:auto" value="${hmToTime(s.h,s.m)}"></div>`;
  } else if(s.mode==='monthly'){
    ctl=`on day <input type="number" id="schedDom" class="form-control form-control-sm d-inline-block" style="width:5rem" min="1" max="31" value="${s.dom||1}"> at <input type="time" id="schedTime" class="form-control form-control-sm d-inline-block" style="width:auto" value="${hmToTime(s.h,s.m)}">`;
  } else if(s.mode==='custom'){
    ctl=`<input id="schedRaw" class="form-control form-control-sm mono" placeholder="*/15 * * * *" value="${esc(s.raw||(DEF.trigger&&DEF.trigger.cron)||'')}">`;
  }
  host.innerHTML=`${modeSel}<div id="schedCtl" class="mt-2">${ctl}</div><div class="fld-help mt-1" id="schedOut"></div>`;
  $('#schedMode').onchange=e=>{ DEF.trigger=DEF.trigger||{}; const mode=e.target.value;
    if(mode==='custom'){ schedForceCustom=true; if(!(DEF.trigger.cron||'').trim()) DEF.trigger.cron='*/15 * * * *'; }
    else { schedForceCustom=false; DEF.trigger.cron=SCHED_DEFAULT[mode]; }
    renderSchedule(); };
  host.querySelectorAll('#schedN,#schedTime,#schedDom,#schedRaw').forEach(el=>el.addEventListener('input',syncSchedule));
  host.querySelectorAll('.sched-day').forEach(b=>b.addEventListener('click',()=>{ b.classList.toggle('active'); syncSchedule(); }));
  syncSchedule();
}
function readSchedState(){
  const mode=$('#schedMode')?$('#schedMode').value:'off', s={mode};
  if(mode==='minutes'||mode==='hours'){ s.n=parseInt($('#schedN').value,10)||1; }
  else if(mode==='daily'||mode==='weekly'||mode==='monthly'){ const t=($('#schedTime')?$('#schedTime').value:'00:00').split(':'); s.h=parseInt(t[0],10)||0; s.m=parseInt(t[1],10)||0;
    if(mode==='weekly') s.days=[...document.querySelectorAll('.sched-day.active')].map(b=>+b.dataset.day);
    if(mode==='monthly') s.dom=parseInt($('#schedDom').value,10)||1; }
  else if(mode==='custom'){ s.raw=$('#schedRaw')?$('#schedRaw').value:''; }
  return s;
}
function syncSchedule(){
  const s=readSchedState(), cron=buildCron(s);
  DEF.trigger=DEF.trigger||{}; if(cron==='') delete DEF.trigger.cron; else DEF.trigger.cron=cron;
  const out=$('#schedOut');
  if(out){
    if(s.mode==='off') out.innerHTML=cronEnglish(s);
    else if(s.mode==='custom') out.innerHTML = validCronClient(cron) ? `<span class="mono">${esc(cron)}</span> · custom` : `<span class="text-danger">invalid cron — need 5 fields (min hour day month weekday)</span>`;
    else out.innerHTML=`<span class="mono">${esc(cron)}</span> · ${esc(cronEnglish(s))}`;
  }
  syncJson();
}

function flowOptions(step,i,which){
  const others=DEF.steps.filter((_,k)=>k!==i).map(s=>s.name);
  const val=step[which]|| (which==='on_success'?'next':'exit');
  const opts=['next','exit',...others.map(n=>'goto:'+n)];
  if(String(val).startsWith('goto:') && !others.includes(String(val).slice(5))) opts.push(val);
  return `<select class="form-select form-select-sm no-toggle" data-si="${i}" data-flow="${which}">
    ${opts.map(o=>`<option value="${esc(o)}" ${o===val?'selected':''}>${esc(o)}</option>`).join('')}</select>`;
}

function summarize(step){
  const c=step.config||{}, t=step.type;
  if(t==='branch') return `${c.left||''} ${c.op||''} ${c.right??''}`.trim();
  if(t==='connection') return `${c.connector||'?'}.${c.tool||'?'}`;
  if(t==='http') return `${c.method||'GET'} ${c.url||''}`.trim();
  if(t==='transform') return `${c.mode||''}${c.input!=null?': '+trunc(c.input):''}`;
  const comp=COMPONENTS[t]||{fields:[]};
  for(const f of (comp.fields||[])){ if(c[f.name]!=null && c[f.name]!=='') return `${f.name}: ${trunc(c[f.name])}`; }
  return '';
}

function renderRow(step,i){
  const comp=COMPONENTS[step.type]||{fields:[]};
  const open=step.__uid===OPEN;
  const fields=(comp.fields||[]).map(f=>renderField(f,step.config[f.name],i)).join('');
  const typeOpts=TYPES.map(t=>`<option value="${t}" ${t===step.type?'selected':''}>${t}</option>`).join('');
  const sum=summarize(step);
  return `<div class="ss-row-wrap ${open?'open':''}" data-uid="${step.__uid}">
    <div class="ss-row ss-cols">
      <span class="drag" title="drag to reorder"><i class="bi bi-grip-vertical"></i></span>
      <span class="idx">${i+1}</span>
      <input class="form-control form-control-sm no-toggle" data-si="${i}" data-meta="name" value="${esc(step.name||'')}">
      <select class="form-select form-select-sm no-toggle" data-si="${i}" data-meta="type">${typeOpts}</select>
      ${flowOptions(step,i,'on_success')}
      ${flowOptions(step,i,'on_fail')}
      <button class="ui-btn-icon no-toggle" style="width:26px;height:26px" title="Delete step" onclick="removeStep(${i})"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="ss-summary">${sum?esc(sum):'<span style="opacity:.55">— click to configure —</span>'}</div>
    <div class="ss-card" ${open?'':'hidden'}><div class="row g-2">${fields}</div></div>
  </div>`;
}

function renderField(f,val,i){
  const req=f.required?' <span class="req">*</span>':'';
  const lab=`<div class="fld-label">${esc(f.label||f.name)}${req}</div>`;
  const help=f.help?`<div class="fld-help">${esc(f.help)}</div>`:'';
  const da=`data-si="${i}" data-field="${f.name}" data-ftype="${f.type}"`;
  const col = (f.type==='textarea'||f.type==='keyval'||f.type==='list') ? 'col-12' : 'col-6';
  let input='';
  if(f.type==='textarea'){ input=`<textarea class="form-control form-control-sm code" rows="2" ${da}>${esc(val||'')}</textarea>`; }
  else if(f.type==='number'){ input=`<input type="number" class="form-control form-control-sm" ${da} value="${val==null?'':esc(val)}">`; }
  else if(f.type==='bool'){ return `<div class="col-6"><div class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" ${da} ${val?'checked':''}><label class="form-check-label fld-label">${esc(f.label||f.name)}</label></div>${help}</div>`; }
  else if(f.type==='select'){ input=`<select class="form-select form-select-sm" ${da}><option value="">—</option>${(f.options||[]).map(o=>`<option value="${esc(o)}" ${o===val?'selected':''}>${esc(o)}</option>`).join('')}</select>`; }
  else if(f.type==='keyval'){ input=`<textarea class="form-control form-control-sm code" rows="2" placeholder='{"key":"value"}' ${da}>${esc(val&&Object.keys(val).length?JSON.stringify(val):'')}</textarea>`; }
  else if(f.type==='list'){ input=`<textarea class="form-control form-control-sm code" rows="2" placeholder="one value per line" ${da}>${esc(Array.isArray(val)?val.join('\n'):'')}</textarea>`; }
  else { input=`<input class="form-control form-control-sm" ${da} value="${esc(val==null?'':val)}">`; }
  return `<div class="${col}">${lab}${input}${help}</div>`;
}

// ---- drag reorder ----
function initSortable(){
  const el=document.getElementById('steps');
  if(!el || typeof Sortable==='undefined') return;
  if(sortable){ try{ sortable.destroy(); }catch(e){} }
  sortable=Sortable.create(el,{ handle:'.drag', animation:150, ghostClass:'ss-ghost', draggable:'.ss-row-wrap',
    onEnd:()=>{
      const order=[...el.querySelectorAll('.ss-row-wrap')].map(w=>w.dataset.uid);
      DEF.steps.sort((a,b)=>order.indexOf(String(a.__uid))-order.indexOf(String(b.__uid)));
      renderBuilder();
    } });
}

// ---- expand / collapse (accordion) ----
function toggleRow(u){ OPEN = (OPEN===u)?null:u;
  document.querySelectorAll('#steps .ss-row-wrap').forEach(w=>{
    const on = String(OPEN)===w.dataset.uid;
    w.classList.toggle('open',on);
    const card=w.querySelector('.ss-card'); if(card) card.hidden=!on;
  });
}

// ---- builder events (delegated) ----
$('#builder') && $('#builder').addEventListener('input', onBuilderChange);
$('#builder') && $('#builder').addEventListener('change', onBuilderChange);
$('#builder') && $('#builder').addEventListener('click', e=>{
  if(e.target.closest('.ss-card')) return;                                          // clicks inside an open card
  if(e.target.closest('input,select,textarea,button,a,label,.no-toggle')) return;   // interactive controls
  const wrap=e.target.closest('.ss-row-wrap'); if(!wrap) return;
  toggleRow(parseInt(wrap.dataset.uid,10));
});
function onBuilderChange(e){
  const t=e.target; if(!DEF) return;
  const meta=t.getAttribute('data-meta'), si=t.getAttribute('data-si'), field=t.getAttribute('data-field'), flow=t.getAttribute('data-flow');
  if(meta && si===null){   // top-level meta
    if(meta==='expose_as_tool'||meta==='expose_as_api'){ DEF[meta]=t.checked; }
    else if(meta==='cron'){ DEF.trigger=DEF.trigger||{}; DEF.trigger.cron=t.value; }
    else { DEF[meta]=t.value; }
    syncJson(); return;
  }
  const i=parseInt(si,10); if(isNaN(i)||!DEF.steps[i]) return;
  if(meta==='name'){ DEF.steps[i].name=t.value; syncJson(); if(e.type==='change') rerenderFlows(); return; }
  if(meta==='type'){ DEF.steps[i].type=t.value; DEF.steps[i].config={}; OPEN=DEF.steps[i].__uid; renderBuilder(); return; }
  if(flow){ DEF.steps[i][flow]=t.value; syncJson(); return; }
  if(field){ setStepField(i,field,t); refreshSummary(i); syncJson(); return; }
}
function setStepField(i,name,el){
  const ftype=el.getAttribute('data-ftype'); const cfg=DEF.steps[i].config;
  if(ftype==='bool'){ cfg[name]=el.checked; return; }
  if(ftype==='number'){ if(el.value==='') delete cfg[name]; else cfg[name]=Number(el.value); return; }
  if(ftype==='keyval'){ const v=el.value.trim(); if(!v){ delete cfg[name]; el.classList.remove('is-invalid'); return; } try{ cfg[name]=JSON.parse(v); el.classList.remove('is-invalid'); }catch(_){ el.classList.add('is-invalid'); } return; }
  if(ftype==='list'){ const arr=el.value.split('\n').map(s=>s.trim()).filter(Boolean); if(arr.length) cfg[name]=arr; else delete cfg[name]; return; }
  if(el.value==='') delete cfg[name]; else cfg[name]=el.value;
}
function refreshSummary(i){ const w=document.querySelector(`#steps .ss-row-wrap[data-uid="${DEF.steps[i].__uid}"] .ss-summary`);
  if(w){ const s=summarize(DEF.steps[i]); w.innerHTML = s?esc(s):'<span style="opacity:.55">— click to configure —</span>'; } }
function rerenderFlows(){ DEF.steps.forEach((s,i)=>['on_success','on_fail'].forEach(w=>{ const sel=document.querySelector(`#steps select[data-si="${i}"][data-flow="${w}"]`); if(sel) sel.outerHTML=flowOptions(s,i,w); })); }

function addStep(type){ const n=DEF.steps.length+1; const s=uid({name:type+n, type:type, config:{}, on_success:'next', on_fail:'exit'}); DEF.steps.push(s); OPEN=s.__uid; renderBuilder(); }
function removeStep(i){ DEF.steps.splice(i,1); renderBuilder(); }

// ---- context schema rows ----
function renderCtxRows(){
  const host=$('#ctxRows'); if(!host) return; host.innerHTML='';
  Object.entries(DEF.context_schema||{}).forEach(([k,spec])=>{
    spec=spec||{}; const row=document.createElement('div'); row.className='d-flex gap-1 mb-1';
    row.innerHTML=`<input class="form-control form-control-sm" value="${esc(k)}" data-ck="name" placeholder="name">
      <select class="form-select form-select-sm" data-ck="type" style="max-width:6.5rem">
        ${['string','number','boolean','object'].map(t=>`<option ${((spec.type||'string')===t)?'selected':''}>${t}</option>`).join('')}</select>
      <div class="form-check form-switch d-flex align-items-center" title="required"><input class="form-check-input" type="checkbox" data-ck="required" ${spec.required?'checked':''}></div>
      <button class="ui-btn-icon" style="width:30px;height:30px" onclick="delCtxVar('${esc(k)}')"><i class="bi bi-x"></i></button>`;
    host.appendChild(row);
  });
  host.querySelectorAll('[data-ck]').forEach(el=> el.addEventListener('change', syncCtxVars));
}
function syncCtxVars(){
  const rows=$('#ctxRows').querySelectorAll('.d-flex'); const out={};
  rows.forEach(r=>{ const name=r.querySelector('[data-ck="name"]').value.trim(); if(!name) return;
    out[name]={ type:r.querySelector('[data-ck="type"]').value, required:r.querySelector('[data-ck="required"]').checked }; });
  DEF.context_schema=out; syncJson();
}
function addCtxVar(){ DEF.context_schema=DEF.context_schema||{}; let n='var',i=1; while(DEF.context_schema[n]) n='var'+(++i); DEF.context_schema[n]={type:'string',required:false}; renderCtxRows(); syncJson(); }
function delCtxVar(k){ delete DEF.context_schema[k]; renderCtxRows(); syncJson(); }

// ---- JSON advanced ----
function syncJson(){ const b=$('#jsonBox'); if(b) b.value=JSON.stringify(DEF,null,2); }
function toggleJson(){ const w=$('#jsonWrap'); w.style.display = w.style.display==='none' ? 'block':'none'; }
function applyJson(){ try{ DEF=normalize(JSON.parse($('#jsonBox').value)); OPEN=null; schedForceCustom=false; renderBuilder(); msg('Applied JSON ✓','success'); }catch(e){ msg('Invalid JSON: '+e.message,'danger'); } }

// ---- validate / save / delete ----
async function validateDef(){ if(!DEF) return;
  try{ const d=await jpost('/edit/validate',{inst:inst(),def:JSON.stringify(DEF)});
    d.valid? msg('Valid ✓','success') : msg('Invalid: '+d.errors.join('; '),'danger'); }catch(e){ msg(e.message,'danger'); } }
async function saveDef(){ if(!DEF) return;
  try{ const d=await jpost('/edit/save',{inst:inst(),def:JSON.stringify(DEF)});
    if(d.ok){ CURRENT=DEF.slug; msg('Saved '+d.file+' ✓','success'); loadList(); } else msg('Not saved: '+(d.errors||[]).join('; '),'danger');
  }catch(e){ msg(e.message,'danger'); } }
async function deleteDef(){ if(!CURRENT||!confirm('Delete '+CURRENT+'?')) return;
  try{ await jpost('/edit/delete',{inst:inst(),slug:CURRENT}); CURRENT=null; DEF=null; $('#builder').innerHTML=''; loadList(); msg('Deleted.','secondary'); }catch(e){ msg(e.message,'danger'); } }

// ---- normal run + watch ----
async function runDef(){ if(!DEF||!DEF.slug){ msg('Save first.','warning'); return; }
  try{ await saveDef(); const d=await jpost('/edit/run',{inst:inst(),slug:DEF.slug,context:$('#ctx').value||'{}'}); watchRun(d.run_id); }catch(e){ msg(e.message,'danger'); } }
function watchRun(runId){
  if(watchTimer) clearInterval(watchTimer); DEBUG=null;
  const render=r=>{ let h=`<div class="mb-1"><b>Run #${runId}</b> <span class="st-${r.status}">${esc(r.status)}</span> <span style="color:var(--bs-tertiary-color)">${r.steps_done}/${r.steps_total}</span></div>`;
    (r.steps||[]).forEach(s=>{ h+=`<div class="st-${s.status}">${s.status==='completed'?'✓':s.status==='failed'?'✗':'…'} ${esc(s.step)} <span style="color:var(--bs-tertiary-color)">[${esc(s.type)}] ${s.duration_ms||0}ms</span></div>`; });
    if(r.await_prompt) h+=`<div class="st-awaiting mt-1">⏸ awaiting: ${esc(r.await_prompt)}</div>`;
    if(r.output!=null) h+=`<div class="small mt-2" style="color:var(--bs-tertiary-color)">output</div><pre class="io">${esc(JSON.stringify(r.output,null,2))}</pre>`;
    $('#runbox').innerHTML=h; };
  const poll=async()=>{ try{ const r=await jget('/edit/runstatus?inst='+encodeURIComponent(inst())+'&run_id='+runId); render(r);
    if(['completed','failed','paused'].includes(r.status)){ clearInterval(watchTimer); watchTimer=null; } }catch(e){} };
  $('#runbox').innerHTML='<div class="small" style="color:var(--bs-tertiary-color)">Starting…</div>'; poll(); watchTimer=setInterval(poll,1000);
}

// ---- debugger ----
async function debugStart(){ if(!DEF||!DEF.slug){ msg('Save first.','warning'); return; }
  try{ await saveDef(); $('#runbox').innerHTML='<div class="small" style="color:var(--bs-tertiary-color)">Starting debug…</div>';
    const bp=await jpost('/edit/debug',{inst:inst(),slug:DEF.slug,context:$('#ctx').value||'{}'}); renderDebug(bp);
  }catch(e){ msg(e.message,'danger'); $('#runbox').innerHTML=''; } }
async function debugAct(action){
  const patch=$('#patchBox')?$('#patchBox').value.trim():'';
  let parsed={}; if(patch){ try{ parsed=JSON.parse(patch); }catch(e){ msg('Inject data is not valid JSON.','danger'); return; } }
  try{ const bp=await jpost('/edit/debugstep',{inst:inst(),run_id:DEBUG.run_id,action:action,patch:JSON.stringify(parsed)}); renderDebug(bp); }
  catch(e){ msg(e.message,'danger'); }
}
function highlightDebugRow(stepName){
  document.querySelectorAll('#steps .ss-row-wrap').forEach(w=>{ const nm=w.querySelector('[data-meta="name"]'); w.classList.toggle('dbg-cur', !!stepName && nm && nm.value===stepName); });
}
function renderDebug(bp){
  DEBUG=bp;
  const paused=bp.debug && bp.status==='paused';
  highlightDebugRow(paused?bp.last_step:null);
  let h=`<div class="mb-2"><b>Debug #${bp.run_id}</b> <span class="st-${bp.status}">${esc(bp.status)}</span> <span style="color:var(--bs-tertiary-color)">${bp.steps_done}/${bp.steps_total}</span></div>`;
  (bp.steps||[]).forEach(s=>{
    const cur=paused && s.step===bp.last_step;
    h+=`<div class="trace-step ${cur?'cur':''}"><div><span class="st-${s.status}">${s.status==='completed'?'✓':s.status==='failed'?'✗':'…'}</span> <b>${esc(s.step)}</b> <span style="color:var(--bs-tertiary-color)">[${esc(s.type)}] ${s.duration_ms||0}ms</span></div>`;
    if(cur){
      if(s.input!=null) h+=`<div class="small mt-1" style="color:var(--bs-tertiary-color)">resolved input</div><pre class="io">${esc(JSON.stringify(s.input,null,2))}</pre>`;
      if(s.output!=null) h+=`<div class="small" style="color:var(--bs-tertiary-color)">output</div><pre class="io">${esc(JSON.stringify(s.output,null,2))}</pre>`;
      if(s.stderr) h+=`<pre class="io st-failed">${esc(s.stderr)}</pre>`;
    }
    h+=`</div>`;
  });
  if(paused){
    const nextType=(DEF.steps.find(x=>x.name===bp.next_step)||{}).type;
    const hint=(COMPONENTS[nextType]&&COMPONENTS[nextType].fields||[]).map(f=>`<code>${esc(f.name)}</code>`).join(' · ');
    h+=`<div class="mt-2 p-2" style="background:var(--ui-surface-soft);border-radius:.6rem">
      <div class="fld-label">Next: <b>${esc(bp.next_step||'—')}</b>${nextType?` <span style="color:var(--bs-tertiary-color)">[${esc(nextType)}]</span>`:''}</div>
      ${hint?`<div class="fld-help mb-1">consumes: ${hint}</div>`:''}
      <label class="fld-label">Inject data (merged into the bag)</label>
      <textarea id="patchBox" class="form-control form-control-sm code" rows="3" placeholder='{"context":{"key":"value"}}'></textarea>
      <details class="mt-1"><summary class="small" style="color:var(--bs-tertiary-color)">current data (bag)</summary><pre class="io bag-view">${esc(JSON.stringify(bp.bag,null,2))}</pre></details>
      <div class="d-flex gap-1 mt-2">
        <button class="btn btn-sm btn-warning" onclick="debugAct('step')"><i class="bi bi-skip-end"></i> Step</button>
        <button class="btn btn-sm btn-success" onclick="debugAct('end')"><i class="bi bi-play-fill"></i> Continue</button>
        <button class="btn btn-sm btn-outline-danger ms-auto" onclick="debugAct('abort')"><i class="bi bi-x-lg"></i> Abort</button>
      </div></div>`;
  } else if(bp.output!=null){
    h+=`<div class="small mt-2" style="color:var(--bs-tertiary-color)">final output</div><pre class="io">${esc(JSON.stringify(bp.output,null,2))}</pre>`;
  }
  if(bp.error) h+=`<div class="st-failed small mt-1">${esc(bp.error)}</div>`;
  $('#runbox').innerHTML=h;
}

// ---- boot ----
$('#inst') && ($('#inst').onchange=()=>{ CURRENT=null; DEF=null; OPEN=null; $('#builder').innerHTML=''; $('#runbox').innerHTML=''; loadList(); });
<?php if ($instances): ?>loadList();<?php endif; ?>
</script>
</body>
</html>
