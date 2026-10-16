<?php
/**
 * Buscador para autollenar las Actas de Entrega y Devolución con la base de datos de colaboradores
 * (web/inventario.php). Se incluye después del script principal del acta: usa eq, rows() y renum().
 */
declare(strict_types=1);

function inv_autollenado_js(): void { ?>
<style>
.buscador{position:relative;margin:14px 22px 0;padding:12px 14px;border:1px solid var(--line,#d3dbe5);border-left:4px solid #c08a1e;border-radius:10px;background:var(--zebra,#f5f7fa)}
.buscador label{display:block;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--mute,#566273);margin:0 0 6px}
.buscador .fila{display:flex;gap:8px;align-items:center}
.buscador input{flex:1;font:inherit;padding:9px 12px 9px 34px;border:1px solid var(--line,#d3dbe5);border-radius:8px;background:var(--field,#fff) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23566273' stroke-width='2'%3E%3Ccircle cx='11' cy='11' r='6.5'/%3E%3Cpath d='M16 16l4.5 4.5'/%3E%3C/svg%3E") no-repeat 10px center/16px!important;color:var(--ink,#17212e)}
.buscador .ayuda{font-size:12.5px;color:var(--mute,#566273);margin:6px 0 0}
.buscador .ayuda.ok{color:#1d7a4a;font-weight:600}
.bs-lista{position:absolute;left:14px;right:14px;top:calc(100% - 6px);z-index:30;max-height:380px;overflow:auto;background:var(--paper,#fff);border:1px solid var(--line,#d3dbe5);border-radius:10px;box-shadow:0 14px 34px -12px rgba(18,52,86,.35)}
.bs-lista[hidden]{display:none}
.bs-it{display:block;width:100%;text-align:left;font:inherit;padding:9px 12px;border:0;border-top:1px solid var(--line,#d3dbe5);background:none;color:var(--ink,#17212e);cursor:pointer}
.bs-it:first-child{border-top:0}
.bs-it:hover,.bs-it.on{background:color-mix(in srgb,#c08a1e 13%,transparent)}
.bs-it strong{display:block;font-size:14px}
.bs-it small{display:block;font-size:12.5px;color:var(--mute,#566273)}
.bs-it .chips{display:flex;flex-wrap:wrap;gap:4px;margin-top:4px}
.bs-it .chips span,.bs-pc button{font-size:11.5px;padding:2px 8px;border-radius:99px;background:var(--band,#e9eff6);color:var(--ink,#17212e)}
.bs-vacio{padding:12px;font-size:13px;color:var(--mute,#566273)}
.bs-pc{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:8px;font-size:13px}
.bs-pc[hidden]{display:none}
.bs-pc button{font:inherit;font-size:12.5px;padding:5px 10px;border:1px solid var(--line,#d3dbe5);cursor:pointer}
.bs-pc button:hover{border-color:#c08a1e}
@media print{.buscador{display:none!important}}
</style>
<script>
(()=>{
  const banda=[...document.querySelectorAll('.band')].find(b=>/DATOS DEL COLABORADOR/i.test(b.textContent));if(!banda)return;
  const box=document.createElement('div');box.className='buscador';
  box.innerHTML='<label for="bsq">Llenar desde la base de datos de colaboradores</label>'
    +'<div class="fila"><input type="search" id="bsq" autocomplete="off" placeholder="Escriba nombre, cédula, usuario, código, nombre del equipo o serie" role="combobox" aria-expanded="false" aria-controls="bslista" aria-autocomplete="list"></div>'
    +'<div class="bs-lista" id="bslista" role="listbox" hidden></div>'
    +'<div class="bs-pc" id="bspc" hidden></div>'
    +'<p class="ayuda" id="bsayuda">Al elegir a la persona se llenan nombre, cargo, dependencia, usuario, correo, cédula y los equipos que tiene asignados. Revise los datos antes de firmar.</p>';
  banda.after(box);
  const q=box.querySelector('#bsq'),lista=box.querySelector('#bslista'),pc=box.querySelector('#bspc'),ayuda=box.querySelector('#bsayuda');
  let res=[],sel=-1,t=null,ultimo='',pedido=0;
  const el=(tag,cls,txt)=>{const x=document.createElement(tag);if(cls)x.className=cls;if(txt!=null)x.textContent=txt;return x};
  const cerrar=()=>{lista.hidden=true;q.setAttribute('aria-expanded','false');sel=-1};
  const marcar=i=>{sel=i;[...lista.querySelectorAll('.bs-it')].forEach((b,k)=>{b.classList.toggle('on',k===i);if(k===i)b.scrollIntoView({block:'nearest'})})};
  function pintar(cargado){lista.innerHTML='';sel=-1;
    if(!cargado){lista.append(el('div','bs-vacio','Todavía no se ha cargado la base de datos de colaboradores. Un administrador la carga desde el menú, en «Base de datos de colaboradores». Mientras tanto, llene los datos a mano.'))}
    else if(!res.length){lista.append(el('div','bs-vacio','No se encontró a nadie con «'+q.value.trim()+'». Pruebe con otra parte del nombre, la cédula o la serie del equipo.'))}
    res.forEach((r,i)=>{const e=r.empleado,b=el('button','bs-it');b.type='button';b.setAttribute('role','option');
      b.append(el('strong','',e.nombre||'(sin nombre)'),el('small','',[e.cedula&&'Céd. '+e.cedula,e.puesto,e.departamento].filter(Boolean).join(' · ')||'Equipo sin colaborador en la hoja de empleados'));
      if(r.equipos.length){const c=el('div','chips');r.equipos.forEach(x=>c.append(el('span','',[(x.hostname||'').toUpperCase(),x.tipo||x.modelo].filter(Boolean).join(' · '))));b.append(c)}
      else b.append(el('small','','Sin equipo asignado en el inventario'));
      b.onmousedown=ev=>ev.preventDefault();b.onclick=()=>elegir(i);lista.append(b)});
    lista.hidden=false;q.setAttribute('aria-expanded','true')}
  async function buscar(){const v=q.value.trim();if(v.length<2){res=[];cerrar();return}if(v===ultimo&&!lista.hidden)return;ultimo=v;const n=++pedido;
    try{const r=await fetch('inventario.php?q='+encodeURIComponent(v),{credentials:'same-origin'});const j=await r.json();if(n!==pedido)return;
      if(!r.ok||!j.ok)throw new Error(j.error||'Error '+r.status);res=j.resultados;pintar(j.cargado)}
    catch(e){if(n!==pedido)return;lista.innerHTML='';lista.append(el('div','bs-vacio','No se pudo consultar la base de datos: '+e.message));lista.hidden=false}}
  q.addEventListener('input',()=>{clearTimeout(t);t=setTimeout(buscar,220)});
  q.addEventListener('focus',()=>{if(res.length||q.value.trim().length>=2){ultimo='';buscar()}});
  q.addEventListener('blur',()=>setTimeout(cerrar,150));
  q.addEventListener('keydown',e=>{const n=lista.querySelectorAll('.bs-it').length;
    if(e.key==='ArrowDown'&&n){e.preventDefault();if(lista.hidden)lista.hidden=false;marcar((sel+1)%n)}
    else if(e.key==='ArrowUp'&&n){e.preventDefault();marcar((sel-1+n)%n)}
    else if(e.key==='Enter'){e.preventDefault();if(sel>=0)elegir(sel);else if(n===1)elegir(0)}
    else if(e.key==='Escape'){cerrar()}});

  const poner=(id,v)=>{const x=document.getElementById(id);if(!x)return;x.value=v||'';x.style.borderColor='';x.dispatchEvent(new Event('input',{bubbles:true}))};
  function llenarEquipo(x){
    const hay=[...eq.querySelectorAll('input')].some(i=>i.value.trim());
    if(hay&&!confirm('La tabla de equipos ya tiene datos.\n¿Reemplazarlos por los equipos de '+(x.hostname||'').toUpperCase()+'?'))return false;
    eq.innerHTML='';x.filas.slice(0,10).forEach(f=>{rows(eq,1,5,true);[...eq.rows[eq.rows.length-1].querySelectorAll('input')].forEach((i,k)=>i.value=f[k]||'')});
    if(!eq.rows.length)rows(eq,1,5,true);renum(eq);
    const r=x.tipo&&document.querySelector('[name=tipo][value="'+x.tipo+'"]');if(r)r.checked=true;
    if(x.usuario&&!document.getElementById('usr').value)poner('usr',x.usuario);
    return true}
  function elegir(i){const r=res[i];if(!r)return;const e=r.empleado;cerrar();
    poner('nombre',e.nombre);poner('cargo',e.puesto);poner('dep',e.departamento);poner('correo',e.correo);
    poner('usr',e.usuario||(r.equipos[0]&&r.equipos[0].usuario)||'');poner('ced',e.cedula);
    const nc=document.getElementById('ncol');if(nc&&nc.value!==e.nombre){nc.value=e.nombre||''}
    pc.innerHTML='';pc.hidden=true;
    let txt='Se llenaron los datos de '+(e.nombre||'el colaborador')+'.';
    if(r.equipos.length===1){if(llenarEquipo(r.equipos[0]))txt+=' Equipo: '+(r.equipos[0].hostname||'').toUpperCase()+' ('+r.equipos[0].filas.length+' ítem(s)).'}
    else if(r.equipos.length>1){txt+=' Tiene '+r.equipos.length+' computadoras: elija cuál va en el acta.';
      pc.append(el('span','','Computadora:'));
      r.equipos.forEach(x=>{const b=el('button','',[(x.hostname||'').toUpperCase(),x.modelo,x.serie].filter(Boolean).join(' · '));b.type='button';
        b.onclick=()=>{if(llenarEquipo(x)){pc.hidden=true;ayuda.textContent='Se llenaron los datos de '+(e.nombre||'el colaborador')+' y el equipo '+(x.hostname||'').toUpperCase()+'.'}};pc.append(b)});
      const no=el('button','','Ninguna');no.type='button';no.onclick=()=>{pc.hidden=true};pc.append(no);pc.hidden=false}
    else txt+=' No tiene equipos en el inventario; complete la tabla a mano.';
    if(!e.cedula)txt+=' Falta la cédula.';if(!e.correo)txt+=' Falta el correo.';
    ayuda.textContent=txt;ayuda.className='ayuda ok';q.value='';res=[];ultimo=''}
  document.getElementById('f').addEventListener('reset',()=>{pc.hidden=true;q.value='';ayuda.className='ayuda';ayuda.textContent='Al elegir a la persona se llenan nombre, cargo, dependencia, usuario, correo, cédula y los equipos que tiene asignados. Revise los datos antes de firmar.'});
})();
</script>
<?php }
