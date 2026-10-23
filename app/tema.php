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
<?php tema_interactivo(); }

/**
 * Capa interactiva: realces al pasar el mouse, efecto «onda» al hacer clic, tarjetas con inclinación 3D,
 * aparición suave de las secciones y fondo animado en <canvas> (red de puntos) en el inicio y el login.
 * Respeta «reducir movimiento» del sistema operativo y no afecta la impresión.
 */
function tema_interactivo(): void { ?>
<style id="tema-interactivo">
@media screen{
/* ---- Botones: transición, realce y sombra al pasar el mouse ---- */
button,.b,.btn,a.b,.add,.appbar .salir,.appbar .volver,.tool,.opt,.tipo label,.chk .it,.bs-it,.pdfsel .op{
  transition:transform .2s cubic-bezier(.2,.8,.2,1),box-shadow .2s ease,background-color .2s ease,border-color .2s ease,color .2s ease,filter .2s ease}
button:not(:disabled):not(.rm):not([role=tab]):hover,.b:hover,.btn:hover,a.b:hover{transform:translateY(-2px);box-shadow:0 10px 20px -10px rgba(18,52,86,.55)}
button:not(:disabled):active,.b:active,.btn:active,a.b:active{transform:translateY(0) scale(.96);box-shadow:0 2px 6px -2px rgba(18,52,86,.45);transition-duration:.06s}
button:disabled{cursor:not-allowed;filter:saturate(.4);opacity:.65}
/* Brillo que recorre los botones principales */
button.p,.b.p,.btn.p,main.card button[type=submit]{position:relative;overflow:hidden;isolation:isolate}
button.p::after,.b.p::after,.btn.p::after,main.card button[type=submit]::after{content:"";position:absolute;inset:0;z-index:-1;pointer-events:none;
  background:linear-gradient(115deg,transparent 30%,rgba(255,255,255,.38) 48%,rgba(255,255,255,.08) 56%,transparent 70%);transform:translateX(-130%);transition:transform .7s ease}
button.p:hover::after,.b.p:hover::after,.btn.p:hover::after,main.card button[type=submit]:hover::after{transform:translateX(130%)}
button.p:hover,.b.p:hover,.btn.p:hover{box-shadow:0 12px 24px -10px color-mix(in srgb,var(--navy) 85%,transparent),0 0 0 3px color-mix(in srgb,var(--gold) 35%,transparent)!important}
/* Botones de eliminar: realce rojo */
button.del:hover,.b.danger:hover,.tool.peligro:hover{box-shadow:0 10px 20px -10px rgba(179,38,30,.6)!important}
/* Onda al hacer clic (la crea el script) */
.rpl{position:relative;overflow:hidden}
.onda{position:absolute;border-radius:50%;pointer-events:none;background:currentColor;opacity:.28;transform:scale(0);animation:onda .6s ease-out forwards}
@keyframes onda{to{transform:scale(1);opacity:0}}

/* ---- Tarjetas del menú: inclinación 3D, luz que sigue al mouse e ícono animado ---- */
.opt{transform-style:preserve-3d;will-change:transform;background-image:radial-gradient(260px circle at var(--mx,50%) var(--my,50%),color-mix(in srgb,var(--c,var(--gold)) var(--g,0%),transparent),transparent 60%)!important}
.opt::after{content:"";position:absolute;left:14px;right:14px;top:0;height:3px;border-radius:0 0 3px 3px;background:linear-gradient(90deg,var(--c,var(--navy-ink)),var(--gold));transform:scaleX(0);transform-origin:left;transition:transform .35s ease}
.opt:hover{--g:16%;transform:perspective(800px) rotateX(var(--rx,0deg)) rotateY(var(--ry,0deg)) translateY(-5px);box-shadow:0 22px 40px -20px color-mix(in srgb,var(--c,#123456) 75%,transparent),0 0 0 1px var(--c,var(--gold))!important;border-color:var(--c,var(--gold))!important}
.opt:hover::after{transform:scaleX(1)}
.opt .ico,.opt .ico svg{transition:transform .35s cubic-bezier(.3,1.6,.5,1),background-color .25s,color .25s}
.opt:hover .ico{transform:translateZ(24px) scale(1.08)}
.opt:hover .ico svg{transform:rotate(-6deg) scale(1.1)}
.opt em svg{transition:transform .25s ease}
.opt:hover em svg{transform:translateX(5px)}
.tool:hover{transform:translateX(4px);box-shadow:-4px 0 0 var(--gold),0 10px 22px -14px rgba(18,52,86,.55);border-color:color-mix(in srgb,var(--gold) 55%,var(--line))!important;background:var(--paper)!important}
.tool svg{transition:transform .3s cubic-bezier(.3,1.6,.5,1)}
.tool:hover svg{transform:scale(1.15) rotate(-5deg)}

/* ---- Barra superior ---- */
.appbar{border-bottom:0!important}
.appbar::after{content:"";position:absolute;left:0;right:0;bottom:-3px;height:3px;background:linear-gradient(90deg,var(--gold),#f3d48a,var(--gold),#a8751a,var(--gold));background-size:300% 100%;animation:brillo 9s linear infinite}
@keyframes brillo{to{background-position:300% 0}}
.appbar .marca img{transition:transform .5s cubic-bezier(.3,1.5,.5,1)}
.appbar .marca:hover img{transform:rotate(-8deg) scale(1.08)}
.appbar .salir:hover{transform:translateY(-1px);box-shadow:0 6px 14px -6px rgba(0,0,0,.5)}

/* ---- Campos: la etiqueta se ilumina cuando se escribe en su campo ---- */
input,select,textarea{transition:border-color .2s,box-shadow .25s,background-color .2s}
:is(.grid,.mini,.campo,.dl,form)>div:focus-within>label,.campo:focus-within>label{color:var(--accent)!important}
input:focus,select:focus,textarea:focus{box-shadow:0 0 0 4px color-mix(in srgb,var(--accent) 20%,transparent),0 6px 16px -10px color-mix(in srgb,var(--accent) 70%,transparent)!important}
.tipo label:hover,.chk .it:hover{transform:translateY(-1px);border-color:var(--accent)!important}
.tipo label:has(input:checked){box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 18%,transparent)}

/* ---- Tablas y listas: fila resaltada con barra dorada ---- */
tbody tr{transition:background-color .15s,box-shadow .15s}
tbody tr:hover{box-shadow:inset 3px 0 0 var(--gold)}
.reg .it,.it{transition:background-color .15s,box-shadow .2s,transform .2s}
.reg .it:hover{box-shadow:inset 4px 0 0 var(--gold);transform:translateX(2px)}

/* ---- Encabezados de sección: punto dorado que late al pasar el mouse ---- */
#f .band{transition:background-color .25s,letter-spacing .25s}
#f .band:hover{letter-spacing:.07em}

/* ---- Firmas: el lienzo se resalta al acercar el lápiz ---- */
canvas:not(.fondo-red){transition:box-shadow .2s,border-color .2s}
.sig canvas:hover,.firma canvas:hover,[data-campo] canvas:hover{box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 22%,transparent);cursor:crosshair}

/* ---- Aparición suave al desplazarse ---- */
.rv{opacity:0;transform:translateY(18px)}
.rv.rv-on{opacity:1;transform:none;transition:opacity .6s ease,transform .6s cubic-bezier(.2,.8,.2,1)}

/* ---- Fondo animado (canvas) ---- */
.fondo-red{position:absolute;inset:0;width:100%;height:100%;z-index:0;pointer-events:none;opacity:.9}
.hero>.hero-in{position:relative;z-index:1}
.escudo-wrap{transition:transform .6s cubic-bezier(.3,1.5,.5,1),box-shadow .4s}
.escudo-wrap:hover{transform:scale(1.05) rotate(-3deg);box-shadow:0 0 0 4px var(--navy),0 0 0 6px var(--gold),0 0 40px 6px color-mix(in srgb,var(--gold) 45%,transparent),0 12px 30px rgba(0,0,0,.35)}
.fondo-login{position:fixed;left:0;top:0;width:100%;height:38vh;z-index:0;pointer-events:none}
}
@media (prefers-reduced-motion:reduce){.rv{opacity:1!important;transform:none!important}.appbar::after{animation:none}}
@media print{.fondo-red,.fondo-login,.onda{display:none!important}.rv{opacity:1!important;transform:none!important}}
</style>
<script>
(()=>{
  const quieto=matchMedia('(prefers-reduced-motion: reduce)').matches;
  const ini=()=>{
    /* Onda al hacer clic */
    document.addEventListener('pointerdown',e=>{if(quieto||e.button!==0)return;
      const b=e.target.closest('button,.b,.btn,a.b,.opt,.tool,.appbar .salir');if(!b||b.disabled)return;
      const r=b.getBoundingClientRect(),d=Math.max(r.width,r.height)*2.2;
      if(getComputedStyle(b).position==='static')b.classList.add('rpl');else b.style.overflow='hidden';
      const o=document.createElement('span');o.className='onda';o.style.cssText='width:'+d+'px;height:'+d+'px;left:'+(e.clientX-r.left-d/2)+'px;top:'+(e.clientY-r.top-d/2)+'px';
      b.append(o);setTimeout(()=>o.remove(),650)},{passive:true});
    /* Tarjetas: inclinación 3D y luz que sigue al mouse */
    document.querySelectorAll('.opt').forEach(c=>{
      c.addEventListener('pointermove',e=>{const r=c.getBoundingClientRect(),x=(e.clientX-r.left)/r.width,y=(e.clientY-r.top)/r.height;
        c.style.setProperty('--mx',(x*100)+'%');c.style.setProperty('--my',(y*100)+'%');
        if(!quieto){c.style.setProperty('--ry',((x-.5)*10).toFixed(2)+'deg');c.style.setProperty('--rx',((.5-y)*8).toFixed(2)+'deg')}});
      c.addEventListener('pointerleave',()=>{c.style.setProperty('--rx','0deg');c.style.setProperty('--ry','0deg')})});
    /* Aparición suave de las secciones al desplazarse */
    if(!quieto&&'IntersectionObserver' in window){
      const io=new IntersectionObserver(es=>es.forEach(x=>{if(x.isIntersecting){const n=x.target;n.classList.add('rv-on');io.unobserve(n);
        setTimeout(()=>n.classList.remove('rv','rv-on'),700)}}),{rootMargin:'0px 0px -40px 0px'});
      document.querySelectorAll('.panel-menu,.reg,.panel,.res .card,.opts>.opt,.tools>.tool').forEach((n,i)=>{
        if(n.getBoundingClientRect().top<innerHeight*.9&&!n.matches('.opt,.tool'))return;   // lo que ya se ve no se anima
        n.classList.add('rv');if(n.matches('.opt,.tool'))n.style.transitionDelay=(i%6)*60+'ms';io.observe(n)})}
    /* Fondo animado: red de puntos que reacciona al mouse */
    const hero=document.querySelector('.hero');
    if(hero)red(hero,'fondo-red',false);
    else if(document.querySelector('body > main.card'))red(document.body,'fondo-login',true);
  };
  function red(cont,clase,fijo){
    const c=document.createElement('canvas');c.className=clase;c.setAttribute('aria-hidden','true');cont.prepend(c);
    const x=c.getContext('2d');let W=0,H=0,pts=[],raton=null,vivo=true;
    const tam=()=>{const r=c.getBoundingClientRect(),k=Math.min(devicePixelRatio||1,2);W=r.width;H=r.height;c.width=W*k;c.height=H*k;x.setTransform(k,0,0,k,0,0);
      const n=Math.min(80,Math.round(W*H/11000));while(pts.length<n)pts.push({x:Math.random()*W,y:Math.random()*H,vx:(Math.random()-.5)*.35,vy:(Math.random()-.5)*.35,r:Math.random()*1.6+.8,o:Math.random()<.18});pts.length=n};
    tam();addEventListener('resize',tam);
    (fijo?window:cont).addEventListener('pointermove',e=>{const r=c.getBoundingClientRect();raton={x:e.clientX-r.left,y:e.clientY-r.top}},{passive:true});
    (fijo?document:cont).addEventListener('pointerleave',()=>raton=null);
    document.addEventListener('visibilitychange',()=>{vivo=!document.hidden;if(vivo)requestAnimationFrame(paso)});
    const D=130;
    function paso(){if(!vivo)return;x.clearRect(0,0,W,H);
      for(const p of pts){p.x+=p.vx;p.y+=p.vy;if(p.x<0||p.x>W)p.vx*=-1;if(p.y<0||p.y>H)p.vy*=-1;
        if(raton){const dx=raton.x-p.x,dy=raton.y-p.y,d=Math.hypot(dx,dy);if(d<160){p.x-=dx/d*.6;p.y-=dy/d*.6}}}
      for(let i=0;i<pts.length;i++){const a=pts[i];
        for(let j=i+1;j<pts.length;j++){const b=pts[j],d=Math.hypot(a.x-b.x,a.y-b.y);
          if(d<D){x.strokeStyle='rgba(201,214,228,'+(.22*(1-d/D)).toFixed(3)+')';x.lineWidth=1;x.beginPath();x.moveTo(a.x,a.y);x.lineTo(b.x,b.y);x.stroke()}}
        if(raton){const d=Math.hypot(a.x-raton.x,a.y-raton.y);if(d<170){x.strokeStyle='rgba(224,176,79,'+(.5*(1-d/170)).toFixed(3)+')';x.beginPath();x.moveTo(a.x,a.y);x.lineTo(raton.x,raton.y);x.stroke()}}
        x.fillStyle=a.o?'rgba(224,176,79,.9)':'rgba(255,255,255,.7)';x.beginPath();x.arc(a.x,a.y,a.r,0,Math.PI*2);x.fill()}
      if(!quieto)requestAnimationFrame(paso)}
    paso()}
  /* Confeti dorado y azul cuando aparece el aviso de «guardado» */
  function confeti(){if(quieto)return;const c=document.createElement('canvas');c.style.cssText='position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:2147483647';
    document.body.append(c);const x=c.getContext('2d'),k=Math.min(devicePixelRatio||1,2),W=innerWidth,H=innerHeight;c.width=W*k;c.height=H*k;x.scale(k,k);
    const col=['#c08a1e','#e0b04f','#123456','#1f4e79','#1d7a4a','#ffffff'],ps=[];const cx=W/2,cy=H*.38;
    for(let i=0;i<110;i++){const a=Math.random()*Math.PI*2,v=4+Math.random()*7;ps.push({x:cx,y:cy,vx:Math.cos(a)*v,vy:Math.sin(a)*v-4,w:5+Math.random()*6,h:3+Math.random()*4,r:Math.random()*6,vr:(Math.random()-.5)*.3,c:col[i%col.length]})}
    const t0=performance.now();(function f(t){const e=t-t0;x.clearRect(0,0,W,H);
      for(const p of ps){p.vy+=.22;p.vx*=.985;p.x+=p.vx;p.y+=p.vy;p.r+=p.vr;x.save();x.globalAlpha=Math.max(0,1-e/1600);x.translate(p.x,p.y);x.rotate(p.r);x.fillStyle=p.c;x.fillRect(-p.w/2,-p.h/2,p.w,p.h);x.restore()}
      if(e<1600)requestAnimationFrame(f);else c.remove()})(t0)}
  new MutationObserver(ms=>{for(const m of ms)for(const n of m.addedNodes)if(n.nodeType===1&&n.classList&&n.classList.contains('aviso-fondo')&&n.querySelector('.aviso-ico'))confeti()})
    .observe(document.documentElement,{childList:true,subtree:true});
  document.readyState==='loading'?document.addEventListener('DOMContentLoaded',ini):ini();
})();
</script>
<?php }
