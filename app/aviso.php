<?php
/**
 * Ventana de confirmación en pantalla, compartida por todos los formularios.
 * Uso en JavaScript:
 *   avisoExito({titulo:'…', texto:'…', botones:[{t:'Imprimir', f:()=>print(), p:true}, {t:'Cerrar'}]})
 */
?>
<style>
.aviso-fondo{position:fixed;inset:0;z-index:1000;display:grid;place-items:center;padding:16px;background:rgba(15,25,40,.45);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);animation:avisoF .18s ease-out}
.aviso-caja{width:100%;max-width:440px;background:var(--paper,#fff);color:var(--ink,#1b2430);border-radius:14px;border-top:6px solid #1d7a4a;box-shadow:0 20px 50px rgba(0,0,0,.3);padding:26px 26px 20px;text-align:center;animation:avisoC .22s cubic-bezier(.2,.9,.3,1.2)}
.aviso-ico{width:64px;height:64px;margin:0 auto 12px;border-radius:50%;display:grid;place-items:center;background:color-mix(in srgb,#1d7a4a 14%,transparent)}
.aviso-ico svg{width:36px;height:36px;fill:none;stroke:#1d7a4a;stroke-width:3;stroke-linecap:round;stroke-linejoin:round}
.aviso-ico path{stroke-dasharray:40;stroke-dashoffset:40;animation:avisoV .45s .12s ease-out forwards}
.aviso-caja h2{margin:0 0 6px;font-family:"Iowan Old Style","Palatino Linotype",Palatino,Georgia,serif;font-size:23px;color:#1d7a4a;line-height:1.2}
.aviso-caja p{margin:0 0 18px;font-size:14.5px;line-height:1.5;color:var(--mute,#5b6675);white-space:pre-line}
.aviso-caja p b{color:var(--ink,#1b2430)}
.aviso-bts{display:flex;flex-wrap:wrap;gap:8px;justify-content:center}
.aviso-bts button{font:inherit;font-weight:600;font-size:14px;padding:9px 16px;border-radius:8px;border:1px solid var(--accent,#1f4e79);background:var(--paper,#fff);color:var(--accent,#1f4e79);cursor:pointer}
.aviso-bts button:hover{background:var(--band,#e7edf4)}
.aviso-bts button.p{background:var(--accent,#1f4e79);color:#fff}
.aviso-bts button.p:hover{filter:brightness(1.1);background:var(--accent,#1f4e79)}
.aviso-bts button:focus-visible{outline:3px solid color-mix(in srgb,var(--accent,#1f4e79) 45%,transparent);outline-offset:2px}
@media (prefers-color-scheme:dark){.aviso-caja{background:#1b222b;color:#e8edf3}.aviso-caja h2{color:#5fc48c}.aviso-ico svg{stroke:#5fc48c}.aviso-bts button.p{color:#0d1116}}
@keyframes avisoF{from{opacity:0}}@keyframes avisoC{from{opacity:0;transform:translateY(12px) scale(.96)}}@keyframes avisoV{to{stroke-dashoffset:0}}
@media print{.aviso-fondo{display:none!important}}
</style>
<script>
function avisoExito(o){
  document.querySelectorAll('.aviso-fondo').forEach(n=>n.remove());
  const previo=document.activeElement;
  const fondo=document.createElement('div');fondo.className='aviso-fondo';
  const caja=document.createElement('div');caja.className='aviso-caja';caja.setAttribute('role','dialog');caja.setAttribute('aria-modal','true');caja.setAttribute('aria-labelledby','aviso-t');
  caja.innerHTML='<div class="aviso-ico"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></div><h2 id="aviso-t"></h2><p></p><div class="aviso-bts"></div>';
  caja.querySelector('h2').textContent=o.titulo||'Listo';
  caja.querySelector('p').textContent=o.texto||'';
  const cerrar=()=>{fondo.remove();document.removeEventListener('keydown',tecla);if(previo&&previo.focus)previo.focus()};
  const tecla=e=>{if(e.key==='Escape')cerrar();if(e.key==='Tab'){const b=[...caja.querySelectorAll('button')];if(!b.length)return;const i=b.indexOf(document.activeElement);
    if(e.shiftKey&&i<=0){e.preventDefault();b[b.length-1].focus()}else if(!e.shiftKey&&i===b.length-1){e.preventDefault();b[0].focus()}}};
  (o.botones&&o.botones.length?o.botones:[{t:'Aceptar',p:true}]).forEach(b=>{const n=document.createElement('button');n.type='button';n.textContent=b.t;if(b.p)n.className='p';
    n.onclick=()=>{cerrar();if(b.f)setTimeout(b.f,30)};caja.querySelector('.aviso-bts').append(n)});
  fondo.append(caja);fondo.addEventListener('click',e=>{if(e.target===fondo)cerrar()});
  document.body.append(fondo);document.addEventListener('keydown',tecla);
  (caja.querySelector('button.p')||caja.querySelector('button')).focus();
}
</script>
