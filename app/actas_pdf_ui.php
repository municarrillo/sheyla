<?php
/**
 * Botones «PDF» y «PDF con …» en los registros (actas y formularios), y el selector del registro que acompaña.
 * Pareja configurada: Acta de Entrega  ⇄  Preparación de equipo (formulario del constructor).
 * Se incluye ANTES del script principal de la página; luego se usa botonesPdf(a) y elegirPareja(a, i).
 */
declare(strict_types=1);
require_once __DIR__ . '/formularios.php';

const PDF_FORM_PREPARACION = 'preparacion-de-equipo';

/** Registros que se pueden unir en un mismo PDF con $este, según lo que existe y el acceso del usuario. */
function actas_pdf_parejas(string $este, ?array $reg): array {
    $prep = 'f:' . PDF_FORM_PREPARACION;
    $out = [];
    if ($este === 'acta_entrega' && ($def = form_cargar(PDF_FORM_PREPARACION)) && !empty($def['publicado']) && actas_puede($reg, $prep))
        $out[] = ['clave' => $prep, 'etiqueta' => mb_minus_seguro((string)$def['titulo'])];
    if ($este === $prep && actas_puede($reg, 'acta_entrega') && form_fijo_activo('acta_entrega'))
        $out[] = ['clave' => 'acta_entrega', 'etiqueta' => 'acta de entrega'];
    return $out;
}
function mb_minus_seguro(string $s): string { return strtr(strtolower($s), ['Á' => 'á', 'É' => 'é', 'Í' => 'í', 'Ó' => 'ó', 'Ú' => 'ú', 'Ñ' => 'ñ']); }

function actas_pdf_ui_js(string $este, ?array $reg): void {
    $parejas = actas_pdf_parejas($este, $reg); ?>
<style>
.pdfsel{border:0;padding:0;border-radius:12px;max-width:640px;width:calc(100% - 24px);background:var(--paper,#fff);color:var(--ink,#17212e);box-shadow:0 24px 60px -18px rgba(18,52,86,.5)}
.pdfsel::backdrop{background:rgba(12,24,38,.45)}
.pdfsel .cab{padding:16px 20px 10px;border-top:4px solid #c08a1e}
.pdfsel h3{margin:0 0 4px;font-size:18px;color:var(--navy-ink,#123456)}
.pdfsel p{margin:0;font-size:13.5px;color:var(--mute,#566273)}
.pdfsel input[type=search]{display:block;width:calc(100% - 40px);margin:10px 20px;font:inherit;padding:8px 11px;border:1px solid var(--line,#d3dbe5);border-radius:8px;background:var(--field,#fff);color:var(--ink,#17212e)}
.pdfsel .lista{max-height:52vh;overflow:auto;margin:0 20px;border:1px solid var(--line,#d3dbe5);border-radius:10px}
.pdfsel .op{display:flex;gap:10px;align-items:center;width:100%;text-align:left;font:inherit;padding:10px 12px;border:0;border-top:1px solid var(--line,#d3dbe5);background:none;color:inherit;cursor:pointer}
.pdfsel .op:first-child{border-top:0}
.pdfsel .op:hover{background:color-mix(in srgb,#c08a1e 12%,transparent)}
.pdfsel .op strong{white-space:nowrap}
.pdfsel .op small{color:var(--mute,#566273)}
.pdfsel .op .tag{margin-left:auto;font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px;background:#1d7a4a;color:#fff;white-space:nowrap}
.pdfsel .vacio{padding:16px;font-size:13.5px;color:var(--mute,#566273)}
.pdfsel .pie{display:flex;justify-content:flex-end;gap:8px;padding:14px 20px 18px}
.pdfsel .pie button{font:inherit;font-weight:600;font-size:13.5px;padding:7px 14px;border-radius:8px;border:1px solid var(--line,#d3dbe5);background:var(--paper,#fff);color:var(--ink,#17212e);cursor:pointer}
</style>
<script>
const PDF_ESTE=<?= json_encode($este) ?>,PDF_PAREJAS=<?= json_encode($parejas, JSON_UNESCAPED_UNICODE) ?>;
const pdfNorm=s=>String(s||'').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'').replace(/\s+/g,' ').trim();
const pdfCed=s=>String(s||'').replace(/\D/g,'');
/** Datos comparables de un registro (acta o formulario). */
function pdfInfo(clave,r){
  if(clave==='acta_entrega'||clave==='acta_devolucion'){const eq=(r.eq||[]).join(' ');
    return {nombre:r.nombre,ced:r.ced,dep:r.dep,fecha:r.fecha,equipo:eq,serie:(r.eq||[]).map(x=>String(x).split('|')[3]||'').filter(Boolean)}}
  const v=r.v||{};
  if(clave==='f:<?= PDF_FORM_PREPARACION ?>')return {nombre:v.cusu,ced:'',dep:v.cdep,fecha:v.cfecha||String(r.at||'').slice(0,10),host:v.chost,serie:v.cserie?[v.cserie]:[],equipo:[v.chost,v.cserie].join(' ')};
  const t=Object.values(v).filter(x=>typeof x==='string'&&x.trim());return {nombre:t[0]||'',dep:t[1]||'',fecha:String(r.at||'').slice(0,10)}}
function pdfMismo(a,b){
  if(a.ced&&b.ced&&pdfCed(a.ced)===pdfCed(b.ced))return true;
  if(a.nombre&&b.nombre&&pdfNorm(a.nombre)===pdfNorm(b.nombre))return true;
  const tiene=(x,host)=>host&&String(host).length>2&&pdfNorm(x.equipo).includes(pdfNorm(host));
  if(tiene(a,b.host)||tiene(b,a.host))return true;
  return (a.serie||[]).some(s=>s&&(b.serie||[]).some(t=>pdfNorm(t)===pdfNorm(s)))}
function urlPdf(sel){const q=new URLSearchParams();
  for(const[k,id]of Object.entries(sel)){if(!id)continue;if(k==='acta_entrega')q.set('e',id);else if(k==='acta_devolucion')q.set('d',id);else if(k.startsWith('f:')){q.set('f',k.slice(2));q.set('r',id)}}
  return 'actas_pdf.php?'+q.toString()}
function descargarPdf(id,otraClave,otroId){const s={[PDF_ESTE]:id};if(otraClave)s[otraClave]=otroId;location.href=urlPdf(s)}
async function elegirPareja(a,i){const par=PDF_PAREJAS[i||0];if(!par)return;
  let dlg=document.getElementById('pdfsel');
  if(!dlg){dlg=document.createElement('dialog');dlg.id='pdfsel';dlg.className='pdfsel';
    dlg.innerHTML='<div class="cab"><h3></h3><p></p></div><input type="search" aria-label="Buscar"><div class="lista" role="list"></div><div class="pie"><button type="button" data-x>Cancelar</button></div>';
    document.body.append(dlg);dlg.querySelector('[data-x]').onclick=()=>dlg.close()}
  const yo=pdfInfo(PDF_ESTE,a),lista=dlg.querySelector('.lista'),q=dlg.querySelector('input');
  dlg.querySelector('h3').textContent='Registro '+a.id+' junto con '+par.etiqueta;
  dlg.querySelector('p').textContent='Elija el registro de '+par.etiqueta+' que va en el mismo PDF'+(yo.nombre?' (primero aparecen los de '+yo.nombre+')':'')+'.';
  q.value='';lista.innerHTML='<div class="vacio">Cargando registros…</div>';dlg.showModal();
  let regs=[];
  try{regs=(await Registro(par.clave).lista()).map(r=>{const d={...r.datos,id:r.id};return {id:r.id,i:pdfInfo(par.clave,d)}})}
  catch(e){lista.innerHTML='';const v=document.createElement('div');v.className='vacio';v.textContent='No se pudieron cargar los registros: '+e.message;lista.append(v);return}
  regs.forEach(r=>r.m=pdfMismo(yo,r.i));
  regs.sort((x,y)=>(y.m-x.m)||String(y.i.fecha||'').localeCompare(String(x.i.fecha||'')));
  const pintar=()=>{const t=pdfNorm(q.value);lista.innerHTML='';
    const l=regs.filter(r=>!t||pdfNorm([r.id,r.i.nombre,r.i.ced,r.i.dep,r.i.equipo].join(' ')).includes(t));
    if(!l.length){const v=document.createElement('div');v.className='vacio';v.textContent=regs.length?'Ninguno coincide con la búsqueda.':'No hay registros de '+par.etiqueta+'.';lista.append(v);return}
    l.forEach(r=>{const b=document.createElement('button');b.type='button';b.className='op';b.setAttribute('role','listitem');
      const s=document.createElement('strong');s.textContent=r.id;const sm=document.createElement('small');
      sm.textContent=[r.i.nombre,r.i.host?String(r.i.host).toUpperCase():'',r.i.dep,String(r.i.fecha||'').split('-').reverse().join('/')].filter(Boolean).join(' · ');b.append(s,sm);
      if(r.m){const g=document.createElement('span');g.className='tag';g.textContent='Mismo colaborador o equipo';b.append(g)}
      b.onclick=()=>{dlg.close();descargarPdf(a.id,par.clave,r.id)};lista.append(b)})};
  q.oninput=pintar;pintar();q.focus()}
function botonesPdf(a){
  const b1=document.createElement('button');b1.type='button';b1.textContent='PDF';b1.title='Descargar en PDF';b1.onclick=()=>descargarPdf(a.id);
  return [b1,...PDF_PAREJAS.map((p,i)=>{const b=document.createElement('button');b.type='button';b.textContent='PDF con '+p.etiqueta;
    b.title='Descargar junto con '+p.etiqueta+' en un mismo PDF';b.onclick=()=>elegirPareja(a,i);return b})]}
/** Botones para la ventana de «guardado» (avisoExito). */
function botonesAvisoPdf(a){return [{t:'Descargar PDF',p:true,f:()=>descargarPdf(a.id)},...PDF_PAREJAS.map((p,i)=>({t:'PDF con '+p.etiqueta,f:()=>elegirPareja(a,i)}))]}
</script>
<?php }
