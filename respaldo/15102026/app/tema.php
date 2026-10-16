<?php
/**
 * Tema visual común de Actas TIC.
 *  - tema_estilos(): capa de estilo compartida; se imprime al final del <head> para que
 *    prevalezca sobre los estilos propios de cada página (solo en pantalla: la impresión no cambia).
 *  - tema_barra(): barra institucional superior (escudo, nombre del sistema, volver, usuario).
 */
if (!defined('ESCUDO_MINI')) require_once __DIR__ . '/plantilla.php';

function tema_barra(?string $volverHref, string $volverTxt, string $usuario, string $ancho = '1100px', array $extra = []): void {
    $h = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?>
<header class="appbar" style="--appw:<?= $h($ancho) ?>"><div class="appbar-in">
  <a class="marca" href="index.php" title="Ir al menú principal"><img src="<?= ESCUDO_MINI ?>" alt=""><span><small>Municipalidad de Carrillo</small>Actas TIC</span></a>
<?php if ($volverHref !== null): ?>  <a class="volver" href="<?= $h($volverHref) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg><?= $h($volverTxt) ?></a>
<?php endif; ?>  <span class="sp"></span>
<?php foreach ($extra as [$hr, $tx]): ?>  <a class="enl" href="<?= $h($hr) ?>"><?= $h($tx) ?></a>
<?php endforeach; ?>  <span class="quien" title="Sesión iniciada"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8.5" r="3.6"/><path d="M5 20c.8-3.8 3.6-6 7-6s6.2 2.2 7 6"/></svg><?= $h($usuario) ?></span>
  <a class="salir" href="logout.php">Cerrar sesión</a>
</div></header>
<?php }

function tema_estilos(): void { ?>
<style id="tema-actas">
/* ===== Tokens ===== */
:root{
  --navy:#123456;--navy-2:#1a4570;--navy-ink:#123456;--accent:#1f4e79;--gold:#c08a1e;--ok:#1d7a4a;--err:#b3261e;
  --bg:#edf1f5;--paper:#fff;--ink:#17212e;--mute:#566273;--line:#d3dbe5;--band:#e9eff6;--zebra:#f5f7fa;--field:#fff;
  --r:8px;--shadow:0 1px 2px rgba(18,52,86,.06),0 10px 30px -12px rgba(18,52,86,.22);
  --serif:"Iowan Old Style","Palatino Linotype",Palatino,"Book Antiqua",Georgia,serif;
  --sans:"Segoe UI Variable Text","Segoe UI",system-ui,-apple-system,Roboto,"Helvetica Neue",Arial,sans-serif;
}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){
  --navy:#0e2236;--navy-2:#16324f;--navy-ink:#9cc3ea;--accent:#7fb2e0;--gold:#e0b04f;--ok:#5fc48c;--err:#f2b8b5;
  --bg:#10151b;--paper:#19212a;--ink:#e8edf3;--mute:#9aa6b5;--line:#344050;--band:#22303f;--zebra:#1d2630;--field:#141a21;
  --shadow:0 1px 2px rgba(0,0,0,.4),0 12px 30px -10px rgba(0,0,0,.6)}}

@media screen{
body{font-family:var(--sans);background:var(--bg)!important;color:var(--ink)}

/* ===== Barra institucional ===== */
.appbar{position:sticky;top:0;z-index:40;margin:0 -12px 0;background:var(--navy);color:#fff;border-bottom:3px solid var(--gold);box-shadow:0 2px 10px rgba(10,30,50,.18)}
.appbar-in{max-width:var(--appw,1100px);margin:0 auto;padding:9px 16px;display:flex;align-items:center;gap:14px;min-height:58px}
.appbar a{color:#fff;text-decoration:none}
.appbar .marca{display:flex;align-items:center;gap:10px;padding-right:16px}
.appbar .marca:has(+ .volver){border-right:1px solid rgba(255,255,255,.18)}
.appbar .marca img{height:38px;width:auto;filter:drop-shadow(0 1px 1px rgba(0,0,0,.35))}
.appbar .marca span{display:flex;flex-direction:column;font-family:var(--serif);font-size:18px;font-weight:600;line-height:1.1;letter-spacing:.01em}
.appbar .marca small{font-family:var(--sans);font-size:11.5px;font-weight:500;letter-spacing:.02em;color:#c9d6e4}
.appbar .volver{display:inline-flex;align-items:center;gap:6px;font-size:14px;font-weight:600;padding:7px 14px 7px 9px;border-radius:999px;color:#e6edf5;transition:background .15s}
.appbar .volver svg,.appbar .quien svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.appbar .volver:hover{background:rgba(255,255,255,.12);color:#fff}
.appbar .sp{flex:1}
.appbar .enl{font-size:13.5px;font-weight:600;color:#d7e2ee;padding:7px 6px}.appbar .enl:hover{color:#fff;text-decoration:underline;text-underline-offset:3px}
.appbar .quien{display:inline-flex;align-items:center;gap:6px;font-size:14px;font-weight:600;color:#fff}
.appbar .quien svg{color:var(--gold)}
.appbar .salir{font-size:13.5px;font-weight:600;padding:7px 14px;border:1px solid rgba(255,255,255,.35);border-radius:999px;color:#fff;transition:background .15s,border-color .15s}
.appbar .salir:hover{background:#fff;color:#123456;border-color:#fff}
.appbar a:focus-visible{outline:2px solid var(--gold);outline-offset:2px}
@media(max-width:640px){.appbar{margin:0 -6px}.appbar-in{gap:8px;padding:8px 10px;min-height:52px}.appbar .marca{padding-right:8px;border:0}.appbar .marca img{height:32px}.appbar .marca small{display:none}.appbar .marca span{font-size:16px}
  .appbar .volver{font-size:0;padding:7px}.appbar .volver svg{width:20px;height:20px}.appbar .quien,.appbar .enl{display:none}.appbar .salir{font-size:12.5px;padding:6px 11px}}

/* ===== Pantallas de acceso (inicio de sesión, contraseña) ===== */
body:has(> main.card){background:linear-gradient(180deg,var(--navy) 0 38vh,var(--bg) 38vh)!important}
body:has(> main.card)::before{content:"";position:fixed;left:0;right:0;top:38vh;height:3px;background:var(--gold);z-index:0}
main.card{position:relative;z-index:1;border-radius:14px!important;border:0!important;box-shadow:0 2px 4px rgba(10,30,50,.08),0 24px 60px -20px rgba(10,30,50,.45)!important}
main.card::before{display:none!important}
main.card label{text-transform:none!important;letter-spacing:0!important;font-size:13.5px!important;color:var(--ink)!important}
main.card h1{color:var(--navy-ink)}
main.card button[type=submit],main.card .btn,main.card button.p{background:var(--navy)!important;border-color:var(--navy)!important;color:#fff!important;border-radius:var(--r)!important;box-shadow:0 6px 16px -8px var(--navy)!important}
main.card button[type=submit]:hover{background:var(--navy-2)!important}

/* ===== Hoja / tarjetas principales ===== */
.sheet,main.sheet{border:1px solid var(--line)!important;border-radius:12px!important;box-shadow:var(--shadow)!important;overflow:hidden}
.sheet::before{height:4px!important;background:linear-gradient(90deg,var(--navy) 0 76%,var(--gold) 76% 100%)!important}
.top h1,.hd h1{font-family:var(--serif);color:var(--navy-ink);letter-spacing:.005em}
.eyebrow{letter-spacing:.12em!important;color:var(--mute)!important}

/* Encabezados de sección del formulario */
#f .band{background:var(--band)!important;color:var(--navy-ink);border-top:1px solid var(--line);border-bottom:1px solid var(--line);font-size:13.5px;letter-spacing:.05em}
#f .band::before{background:var(--navy)!important;color:#fff!important;box-shadow:0 0 0 3px color-mix(in srgb,var(--gold) 45%,transparent)!important}

/* ===== Campos ===== */
#f label:not(.ack):not(:has(input)),main.sheet label:not(:has(input)),.grid label:not(:has(input)){text-transform:none!important;letter-spacing:0!important;font-size:13.5px!important;font-weight:600;color:var(--ink)!important}
input[type=text],input[type=email],input[type=date],input[type=time],input[type=number],input[type=password],input[type=search],select,textarea{
  background:var(--field)!important;border:1px solid var(--line)!important;border-radius:var(--r)!important;color:var(--ink)}
td :is(input[type=text],input[type=email],input[type=date],input[type=time],input[type=number],select,textarea){border:0!important;border-radius:0!important;background:transparent!important;box-shadow:none!important}
.num :is(input[type=text]){border:0!important;border-bottom:2px solid var(--navy)!important;border-radius:0!important;background:transparent!important;color:var(--navy-ink)}
.num :is(input[type=text]):focus{box-shadow:none!important;background:var(--band)!important}
input[type=text]:hover,input[type=email]:hover,input[type=date]:hover,input[type=time]:hover,input[type=number]:hover,input[type=password]:hover,select:hover,textarea:hover{border-color:#9fb0c4!important}
input:focus,select:focus,textarea:focus{outline:0!important;border-color:var(--accent)!important;box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 22%,transparent)!important}
td :is(input[type=text],input[type=email],input[type=date],input[type=time],input[type=number],select,textarea):focus{box-shadow:inset 0 0 0 2px var(--accent)!important;background:var(--field)!important}
input[type=radio],input[type=checkbox]{accent-color:var(--navy-ink)}
::placeholder{color:#8a96a6}
textarea.txt{border-color:transparent!important;background:transparent!important;border-radius:6px!important;min-height:0!important}
textarea.txt:hover{border-color:var(--line)!important}
textarea.txt:focus{background:var(--field)!important;border-color:var(--accent)!important}

/* ===== Tablas ===== */
.tw table,table.lista,.tabla table{border-radius:var(--r)}
th{background:var(--navy)!important;color:#fff!important;letter-spacing:.06em}
tbody tr:hover td{background:color-mix(in srgb,var(--band) 55%,transparent)}

/* ===== Botones ===== */
#f .bar{background:var(--zebra)!important;border-top:1px solid var(--line)}
.bar button,.barra button,.b,.btn{border-radius:var(--r)!important}
.bar button.p,.barra button.p,button.p,.b.p,.btn.p{background:var(--navy)!important;border-color:var(--navy)!important;color:#fff!important;box-shadow:0 1px 0 rgba(255,255,255,.12) inset,0 4px 12px -4px color-mix(in srgb,var(--navy) 70%,transparent)!important;filter:none}
.bar button.p:hover,.barra button.p:hover,button.p:hover,.b.p:hover,.btn.p:hover{background:var(--navy-2)!important}
#f .bar button:not(.p):hover{background:var(--band)!important}
button:focus-visible,a:focus-visible{outline:3px solid color-mix(in srgb,var(--gold) 70%,transparent)!important;outline-offset:2px}
.add,button.add{border-radius:999px!important}

/* ===== Pantallas de administración ===== */
main.sheet .hd{background:linear-gradient(180deg,var(--zebra),var(--paper))!important;border-bottom:1px solid var(--line)}
main.sheet .bar h2,main.sheet h2{color:var(--navy-ink)}
.panel{border-radius:10px!important}
}

@media print{.appbar{display:none!important}}
/* Asterisco de campo obligatorio en rojo */
.ast,.req{color:#c62828!important;font-weight:700}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]) .ast,:root:not([data-theme="light"]) .req{color:#ff8a80!important}}
@media print{.ast,.req{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
@media (prefers-reduced-motion:reduce){*{transition:none!important;animation:none!important}}
</style>
<script>
/* Pone en rojo el asterisco (*) que marca los campos obligatorios, también en lo que se dibuja después con JavaScript */
(()=>{const NO=/^(SCRIPT|STYLE|TEXTAREA|OPTION|SELECT|INPUT|BUTTON)$/,RE=/^([\s\S]*?)(\s+)\*(\s*)$/;
  function marcar(raiz){const w=document.createTreeWalker(raiz,NodeFilter.SHOW_TEXT,{acceptNode:n=>{
      if(!n.nodeValue||n.nodeValue.indexOf('*')<0||!RE.test(n.nodeValue))return NodeFilter.FILTER_REJECT;
      for(let p=n.parentNode;p&&p!==document.body;p=p.parentNode){if(p.nodeType===1&&(NO.test(p.nodeName)||p.isContentEditable||p.classList.contains('ast')||p.classList.contains('req')||p.classList.contains('prt')))return NodeFilter.FILTER_REJECT}
      return NodeFilter.FILTER_ACCEPT}});
    const l=[];while(w.nextNode())l.push(w.currentNode);
    l.forEach(n=>{const m=n.nodeValue.match(RE);const s=document.createElement('span');s.className='ast';s.textContent='*';s.setAttribute('aria-hidden','true');
      n.nodeValue=m[1]+m[2];n.after(s);if(m[3])s.after(document.createTextNode(m[3]))})}
  const ini=()=>{marcar(document.body);new MutationObserver(ms=>ms.forEach(m=>m.addedNodes.forEach(n=>{if(n.nodeType===1&&!n.classList.contains('ast'))marcar(n);else if(n.nodeType===3&&n.parentNode)marcar(n.parentNode)}))).observe(document.body,{childList:true,subtree:true})};
  document.readyState==='loading'?document.addEventListener('DOMContentLoaded',ini):ini()})();
</script>
<?php }
