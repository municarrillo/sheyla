<?php
/**
 * Registros llenados (actas, bitácoras y formularios del constructor), guardados en el servidor:
 *   /var/lib/actas/registros/<formulario>/<id en hex>.json
 *   /var/lib/actas/registros/<formulario>/_eliminados/   (lo eliminado se conserva aquí)
 * Así se consultan desde cualquier computadora.
 */
declare(strict_types=1);

define('REG_DIR', getenv('ACTAS_REG_DIR') ?: '/var/lib/actas/registros');

/** Clave del formulario → carpeta. Claves válidas: acta_entrega, acta_devolucion, bitacora, f:<id>. */
function reg_carpeta(string $form): ?string {
    if (in_array($form, ['acta_entrega', 'acta_devolucion', 'bitacora'], true)) return REG_DIR . '/' . $form;
    if (preg_match('/^f:([a-z0-9-]{1,80})$/', $form, $m)) return REG_DIR . '/f-' . $m[1];
    return null;
}
function reg_id_valido(string $id): bool { return $id !== '' && strlen($id) <= 80 && !preg_match('/[\x00-\x1F\x7F\/\\\\]/', $id); }
function reg_archivo(string $dir, string $id): string { return $dir . '/' . bin2hex($id) . '.json'; }

function reg_leer(string $dir, string $id): ?array {
    $d = json_decode((string)@file_get_contents(reg_archivo($dir, $id)), true);
    return is_array($d) ? $d : null;
}
function reg_listar(string $dir): array {
    $out = [];
    foreach (glob($dir . '/*.json') ?: [] as $f) {
        $d = json_decode((string)@file_get_contents($f), true);
        if (is_array($d) && isset($d['id'])) $out[] = $d;
    }
    return $out;
}
/** Quita las imágenes (firmas) para que la lista viaje liviana; deja una marca para saber que existían. */
function reg_sin_imagenes($v) {
    if (is_string($v)) return str_starts_with($v, 'data:image/') ? 'imagen' : $v;
    if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = reg_sin_imagenes($x); }
    return $v;
}
function reg_bloquear(string $dir) {
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    $lk = @fopen($dir . '/.bloqueo', 'c');
    if (!$lk || !flock($lk, LOCK_EX)) throw new RuntimeException('No se pudo bloquear el registro.');
    return $lk;
}
function reg_escribir(string $ruta, array $d): bool {
    $tmp = $ruta . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) return false;
    if (!@rename($tmp, $ruta)) { @unlink($tmp); return false; }
    return true;
}

/** Cliente JavaScript común: Registro('acta_entrega').lista() / .ver(id) / .guardar(id, datos, {reemplazar, original}) / .eliminar(id) / .migrar(claveLocal) */
function reg_cliente_js(string $csrf): void { ?>
<style>
.editando{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin:0;padding:10px 16px;background:color-mix(in srgb,#c08a1e 14%,var(--paper,#fff));border-bottom:1px solid #c08a1e;color:var(--ink,#17212e);font-size:14px}
.editando[hidden]{display:none}
.editando button{font:inherit;font-weight:600;font-size:13px;padding:5px 12px;border-radius:8px;border:1px solid var(--line,#d3dbe5);background:var(--paper,#fff);color:var(--ink,#17212e);cursor:pointer}
.reg-tools{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:10px 16px;border-top:1px solid var(--line,#d3dbe5)}
.reg-tools input{flex:1 1 220px;max-width:340px;font:inherit;padding:7px 10px;border:1px solid var(--line,#d3dbe5);border-radius:8px;background:var(--field,#fff);color:var(--ink,#17212e)}
.reg-tools small{color:var(--mute,#566273)}
.reg .it .acc{display:flex;gap:6px;flex:none}
.reg .it small{color:var(--mute,#566273)}
.reg .it button.del{border-color:color-mix(in srgb,#b3261e 45%,transparent)!important;color:#b3261e!important}
.reg .it button.del:hover{background:#b3261e!important;color:#fff!important}
.reg .it.abierto{background:color-mix(in srgb,#c08a1e 10%,transparent)}
@media print{.editando,.reg-tools{display:none!important}}
</style>
<script>
const Registro=(form)=>{
  const CSRF=<?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const url=q=>'registros.php?f='+encodeURIComponent(form)+(q||'');
  const leer=async r=>{let j=null;try{j=await r.json()}catch(e){}if(!r.ok&&!j)throw new Error('El servidor respondió '+r.status+'.');if(j&&j.ok===false&&!j.existe)throw new Error(j.error||'No se pudo completar.');return j};
  const post=b=>fetch('registros.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify(Object.assign({csrf:CSRF,f:form},b))}).then(leer);
  const api={
    async lista(){const j=await fetch(url('&accion=lista'),{credentials:'same-origin'}).then(leer);return j.registros},
    async ver(id){const j=await fetch(url('&accion=ver&id='+encodeURIComponent(id)),{credentials:'same-origin'}).then(leer);return j.registro},
    guardar(id,datos,o){o=o||{};return post({accion:'guardar',id,datos,reemplazar:!!o.reemplazar,original:o.original||''})},
    eliminar(id){return post({accion:'eliminar',id})},
    /** Sube al servidor lo que quedó guardado en este navegador con la versión anterior y lo retira del navegador. */
    async migrar(clave,aId){let loc={};try{loc=JSON.parse(localStorage.getItem(clave)||'{}')}catch(e){}
      const ids=Object.keys(loc);if(!ids.length)return 0;let n=0,fallos=0;
      for(const k of ids){try{const j=await post({accion:'guardar',id:aId?aId(k,loc[k]):k,datos:loc[k],reemplazar:false,migrado:true});if(j.ok||j.existe)n++;else fallos++}catch(e){fallos++}}
      if(!fallos){try{localStorage.setItem(clave+'_subido_'+Date.now(),localStorage.getItem(clave));localStorage.removeItem(clave)}catch(e){}}
      return n}
  };
  return api};
/** Lista de registros con búsqueda, Abrir/Editar y Eliminar. o = {REG, lista:()=>[], etiqueta, titulo:a=>'', detalle:a=>'', abrir:id=>Promise, despues:()=>{}, editando:()=>id} */
function panelRegistros(o){
  const box=document.getElementById('lst'),sec=document.getElementById('reg');
  let tools=sec.querySelector('.reg-tools');
  if(!tools){tools=document.createElement('div');tools.className='reg-tools';
    const q=document.createElement('input');q.type='search';q.placeholder='Buscar '+o.etiqueta+'s por número, nombre, técnico…';q.setAttribute('aria-label','Buscar');
    const c=document.createElement('small');tools.append(q,c);box.before(tools);q.addEventListener('input',()=>panelRegistros(o))}
  const q=tools.querySelector('input').value.trim().toLowerCase(),todos=o.lista();
  const l=todos.filter(a=>!q||(o.titulo(a)+' '+o.detalle(a)).toLowerCase().includes(q));
  tools.querySelector('small').textContent=todos.length?(l.length===todos.length?todos.length+' registrado(s) en el servidor':l.length+' de '+todos.length):'';
  sec.hidden=false;box.innerHTML='';
  if(!l.length){const h=document.createElement('div');h.className='hint';h.textContent=todos.length?'Ninguno coincide con la búsqueda.':'Aún no hay '+o.etiqueta+'s registradas.';box.append(h);return}
  l.forEach(a=>{const d=document.createElement('div');d.className='it'+(o.editando()===a.id?' abierto':'');const t=document.createElement('div');
    const b=document.createElement('strong');b.textContent=o.titulo(a);const sm=document.createElement('small');sm.textContent=' · '+o.detalle(a);t.append(b,sm);
    const acc=document.createElement('div');acc.className='acc';
    const ab=document.createElement('button');ab.type='button';ab.textContent='Abrir / editar';
    ab.onclick=async()=>{ab.disabled=true;try{await o.abrir(a.id)}catch(e){alert(e.message)}ab.disabled=false;panelRegistros(o)};acc.append(ab);
    if(a._r.puede_eliminar){const del=document.createElement('button');del.type='button';del.className='del';del.textContent='Eliminar';
      del.onclick=async()=>{if(!confirm('¿Eliminar '+o.etiqueta+' '+o.titulo(a)+'?\n\nDejará de aparecer en el registro para todos. Si ya la imprimió, el PDF sigue siendo válido.'))return;
        del.disabled=true;try{await o.REG.eliminar(a.id);await o.despues(a.id)}catch(e){alert(e.message);del.disabled=false}};acc.append(del)}
    d.append(t,acc);box.append(d)})}
/** Franja «Editando…» encima del formulario. */
function franjaEdicion(form,texto,cancelar){let f=document.getElementById('edicion');
  if(!f){f=document.createElement('div');f.id='edicion';f.className='editando';f.setAttribute('role','status');const s=document.createElement('span');const b=document.createElement('button');b.type='button';b.textContent='Cancelar edición y empezar uno nuevo';b.onclick=cancelar;f.append(s,b);form.prepend(f)}
  f.hidden=!texto;f.querySelector('span').textContent=texto||''}
</script>
<?php }
