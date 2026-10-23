<?php
/**
 * Buscador para autollenar las Actas de Entrega y Devolución con la base de datos de colaboradores
 * (web/inventario.php). Se incluye después del script principal del acta: usa eq, rows() y renum().
 */
declare(strict_types=1);

function inv_autollenado_estilos_core(): void { ?>
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
/* Buscador genérico. cfg = {ancla:Element, form:Element, ayuda:'texto', param:'&f=..', persona(r):string, equipo(x,e):bool|undefined, sinEquipo:'texto', elegirEquipo:'texto'} */
function buscadorColaboradores(cfg){
  if(!cfg.ancla)return;
  const box=document.createElement('div');box.className='buscador';
  box.innerHTML='<label for="bsq">Llenar desde la base de datos de colaboradores</label>'
    +'<div class="fila"><input type="search" id="bsq" autocomplete="off" placeholder="Escriba nombre, cédula, usuario, código, nombre del equipo o serie" role="combobox" aria-expanded="false" aria-controls="bslista" aria-autocomplete="list"></div>'
    +'<div class="bs-lista" id="bslista" role="listbox" hidden></div>'
    +'<div class="bs-pc" id="bspc" hidden></div>'
    +'<p class="ayuda" id="bsayuda"></p>';
  cfg.ancla.after(box);
  const q=box.querySelector('#bsq'),lista=box.querySelector('#bslista'),pc=box.querySelector('#bspc'),ayuda=box.querySelector('#bsayuda');
  ayuda.textContent=cfg.ayuda;
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
    try{const r=await fetch('inventario.php?q='+encodeURIComponent(v)+(cfg.param||''),{credentials:'same-origin'});const j=await r.json();if(n!==pedido)return;
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
  function elegir(i){const r=res[i];if(!r)return;const e=r.empleado;cerrar();
    cfg.persona(r);
    pc.innerHTML='';pc.hidden=true;
    let txt='Se llenaron los datos de '+(e.nombre||'el colaborador')+'.';
    if(r.equipos.length===1){if(cfg.equipo(r.equipos[0],e)!==false)txt+=' Equipo: '+(r.equipos[0].hostname||'').toUpperCase()+'.'}
    else if(r.equipos.length>1){txt+=' Tiene '+r.equipos.length+' computadoras: '+cfg.elegirEquipo;
      pc.append(el('span','','Computadora:'));
      r.equipos.forEach(x=>{const b=el('button','',[(x.hostname||'').toUpperCase(),x.modelo,x.serie].filter(Boolean).join(' · '));b.type='button';
        b.onclick=()=>{if(cfg.equipo(x,e)!==false){pc.hidden=true;ayuda.textContent='Se llenaron los datos de '+(e.nombre||'el colaborador')+' y el equipo '+(x.hostname||'').toUpperCase()+'.'}};pc.append(b)});
      const no=el('button','','Ninguna');no.type='button';no.onclick=()=>{pc.hidden=true};pc.append(no);pc.hidden=false}
    else txt+=' '+cfg.sinEquipo;
    if(cfg.avisaFaltantes){if(!e.cedula)txt+=' Falta la cédula.';if(!e.correo)txt+=' Falta el correo.'}
    ayuda.textContent=txt;ayuda.className='ayuda ok';q.value='';res=[];ultimo=''}
  if(cfg.form)cfg.form.addEventListener('reset',()=>{pc.hidden=true;q.value='';ayuda.className='ayuda';ayuda.textContent=cfg.ayuda});
}
</script>
<?php }

/** Actas de Entrega y Devolución: usa eq, rows() y renum() del acta. */
function inv_autollenado_js(): void { inv_autollenado_estilos_core(); ?>
<script>
(()=>{
  const AYUDA='Al elegir a la persona se llenan nombre, cargo, dependencia, usuario, correo, cédula y los equipos que tiene asignados. Revise los datos antes de firmar.';
  const poner=(id,v)=>{const x=document.getElementById(id);if(!x)return;x.value=v||'';x.style.borderColor='';x.dispatchEvent(new Event('input',{bubbles:true}))};
  buscadorColaboradores({
    ancla:[...document.querySelectorAll('.band')].find(b=>/DATOS DEL COLABORADOR/i.test(b.textContent)),
    form:document.getElementById('f'),ayuda:AYUDA,avisaFaltantes:true,
    elegirEquipo:'elija cuál va en el acta.',sinEquipo:'No tiene equipos en el inventario; complete la tabla a mano.',
    persona(r){const e=r.empleado;
      poner('nombre',e.nombre);poner('cargo',e.puesto);poner('dep',e.departamento);poner('correo',e.correo);
      poner('usr',e.usuario||(r.equipos[0]&&r.equipos[0].usuario)||'');poner('ced',e.cedula);
      const nc=document.getElementById('ncol');if(nc&&nc.value!==e.nombre){nc.value=e.nombre||''}},
    equipo(x){
      const hay=[...eq.querySelectorAll('input')].some(i=>i.value.trim());
      if(hay&&!confirm('La tabla de equipos ya tiene datos.\n¿Reemplazarlos por los equipos de '+(x.hostname||'').toUpperCase()+'?'))return false;
      eq.innerHTML='';x.filas.slice(0,10).forEach(f=>{rows(eq,1,5,true);[...eq.rows[eq.rows.length-1].querySelectorAll('input')].forEach((i,k)=>i.value=f[k]||'')});
      if(!eq.rows.length)rows(eq,1,5,true);renum(eq);
      const r=x.tipo&&document.querySelector('[name=tipo][value="'+x.tipo+'"]');if(r)r.checked=true;
      if(x.usuario&&!document.getElementById('usr').value)poner('usr',x.usuario);
      return true}});
})();
</script>
<?php }

/**
 * Formularios del constructor (p. ej. Preparación de Equipo): reconoce los campos por su etiqueta
 * (nombre / usuario final, cédula, correo, departamento, puesto, usuario de red, hostname, serie, modelo, marca, tipo de equipo)
 * y la firma del usuario final. Se incluye después del script principal de formulario.php (usa campos y nodo()).
 */
function inv_autollenado_form_js(string $formId): void { inv_autollenado_estilos_core(); ?>
<script>
(()=>{
  const norm=s=>String(s||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'');
  const REGLAS=[['cedula',/cedula/],['correo',/correo|e-?mail/],['usuario',/usuario de red|usuario de dominio|\blogin\b/],['hostname',/hostname|nombre del equipo|computer name/],
    ['serie',/\bserie\b|serial|service tag/],['modelo',/\bmodelo\b/],['marca',/\bmarca\b/],['tipo',/tipo de equipo/],['departamento',/departamento|dependencia|unidad|\barea\b/],
    ['puesto',/puesto|cargo/],['codigo',/codigo de empleado/],['nombre',/usuario final|colaborador|funcionario|nombre/]];
  const NO_PERSONA=/tecnico|responsable|\bti\b|tic\b|encargad|autoriz|aprob/;
  const mapa={},firmas=[];
  campos.forEach(c=>{const et=norm(c.etiqueta);
    if(c.tipo==='firma'){if(/usuario|colaborador|funcionario|recibe/.test(et)&&!NO_PERSONA.test(et))firmas.push(c);return}
    if(!['texto','correo','lista','unica'].includes(c.tipo))return;
    for(const[k,re]of REGLAS){if(re.test(et)&&!mapa[k]&&!(k==='nombre'&&NO_PERSONA.test(et))&&!(k==='nombre'&&/equipo/.test(et))){mapa[k]=c;break}}});
  const n=Object.keys(mapa).length;
  if(n<2||!(mapa.nombre||mapa.cedula))return;   // el formulario no tiene datos de una persona
  const primero=Object.values(mapa).map(c=>nodo(c)).filter(Boolean).sort((a,b)=>a.compareDocumentPosition(b)&Node.DOCUMENT_POSITION_FOLLOWING?-1:1)[0];
  const sec=primero&&primero.closest('section');const ancla=sec?sec.querySelector('.band'):null;
  const poner=(c,v)=>{if(!c||v==null)return;const w=nodo(c);if(!w)return;v=String(v);
    if(c.tipo==='unica'){const r=[...w.querySelectorAll('input[type=radio]')].find(i=>norm(i.value)===norm(v));if(r){r.checked=true;r.dispatchEvent(new Event('change',{bubbles:true}))}return}
    if(c.tipo==='lista'){const s=w.querySelector('select');const o=[...s.options].find(o=>norm(o.value)===norm(v));if(o){s.value=o.value;s.dispatchEvent(new Event('change',{bubbles:true}))}return}
    const i=w.querySelector('input,textarea');if(!i)return;i.value=v;w.classList.remove('mal');const er=w.querySelector('.ferr');if(er)er.textContent='';i.dispatchEvent(new Event('input',{bubbles:true}))};
  const campo=k=>mapa[k];
  const lista=Object.keys(mapa).map(k=>({nombre:'nombre',cedula:'cédula',correo:'correo',departamento:'departamento',puesto:'puesto',usuario:'usuario',hostname:'hostname',serie:'serie',modelo:'modelo',marca:'marca',tipo:'tipo de equipo',codigo:'código'}[k]));
  const AYUDA='Al elegir a la persona se llenan: '+lista.join(', ')+(firmas.length?' y el nombre en la firma':'')+'. Revise los datos antes de finalizar.';
  buscadorColaboradores({ancla,form:document.getElementById('f'),ayuda:AYUDA,param:'&f='+encodeURIComponent(<?= json_encode($formId) ?>),
    elegirEquipo:'elija cuál se está preparando.',sinEquipo:(campo('hostname')||campo('serie'))?'No tiene equipos en el inventario; complete los datos del equipo a mano.':'',
    persona(r){const e=r.empleado;
      poner(campo('nombre'),e.nombre);poner(campo('cedula'),e.cedula);poner(campo('correo'),e.correo);poner(campo('departamento'),e.departamento);
      poner(campo('puesto'),e.puesto);poner(campo('codigo'),e.codigo);poner(campo('usuario'),e.usuario||(r.equipos[0]&&r.equipos[0].usuario)||'');
      firmas.forEach(f=>{const w=nodo(f);if(!w)return;[['nombre',e.nombre],['cedula',e.cedula]].forEach(([k,v])=>{const i=w.querySelector('[data-f="'+k+'"]');if(i&&v)i.value=v})});
      if(typeof cambio==='function')cambio()},
    equipo(x){
      if(!(campo('hostname')||campo('serie')||campo('modelo')||campo('tipo')))return;
      poner(campo('hostname'),(x.hostname||'').toUpperCase());poner(campo('serie'),x.serie);poner(campo('modelo'),x.modelo);
      poner(campo('marca'),x.filas&&x.filas[0]?x.filas[0][1]:'');if(x.tipo)poner(campo('tipo'),x.tipo);
      if(x.usuario&&campo('usuario')&&!nodo(campo('usuario')).querySelector('input').value)poner(campo('usuario'),x.usuario);
      if(typeof cambio==='function')cambio();return true}});
})();
</script>
<?php }
