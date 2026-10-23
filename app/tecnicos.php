<?php
/**
 * Técnicos del Departamento de TI (tabla «tecnicos» de MySQL).
 * Se usan en las listas desplegables de los formularios donde se anota el técnico o funcionario de TI:
 * Actas de Entrega y Devolución, Bitácora de Simulacro, Préstamo de Activos y formularios del Constructor
 * (campos cuya etiqueta dice «técnico» o «funcionario de TI»).
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

function tec_listar(bool $soloActivos = false): array {
    return db_todas('SELECT id, nombre, cedula, puesto, correo, usuario, activo, orden FROM tecnicos' . ($soloActivos ? ' WHERE activo = 1' : '') . ' ORDER BY orden, nombre');
}
function tec_leer(int $id): ?array { return db_fila('SELECT * FROM tecnicos WHERE id = ?', [$id]); }

/** Crea ($id = 0) o actualiza un técnico. Devuelve '' si todo bien, o el motivo del error. */
function tec_guardar(int $id, array $d): string {
    $nombre = trim(preg_replace('/\s+/u', ' ', (string)($d['nombre'] ?? '')));
    if (actas_largo($nombre) < 5) return 'Escriba el nombre completo del técnico.';
    if (actas_largo($nombre) > 120) return 'El nombre es demasiado largo.';
    $ced = trim((string)($d['cedula'] ?? ''));
    if ($ced !== '' && !preg_match('/^\d{9,12}$/', preg_replace('/[\s-]/', '', $ced))) return 'Cédula inválida: use de 9 a 12 dígitos (ej. 5-0123-0456).';
    $correo = strtolower(trim((string)($d['correo'] ?? '')));
    if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) return 'Correo inválido.';
    $otro = db_valor('SELECT id FROM tecnicos WHERE nombre = ? AND id <> ?', [$nombre, $id]);
    if ($otro !== null) return 'Ya existe un técnico con ese nombre.';
    $v = [$nombre, $ced, db_corta($d['puesto'] ?? '', 120), $correo, db_corta($d['usuario'] ?? '', 64), empty($d['activo']) ? 0 : 1];
    if ($id > 0) {
        if (!tec_leer($id)) return 'Ese técnico ya no existe.';
        db_q('UPDATE tecnicos SET nombre = ?, cedula = ?, puesto = ?, correo = ?, usuario = ?, activo = ? WHERE id = ?', [...$v, $id]);
    } else {
        $orden = (int)db_valor('SELECT COALESCE(MAX(orden), 0) + 1 FROM tecnicos');
        db_q('INSERT INTO tecnicos (nombre, cedula, puesto, correo, usuario, activo, orden) VALUES (?, ?, ?, ?, ?, ?, ?)', [...$v, $orden]);
    }
    return '';
}
function tec_eliminar(int $id): bool { return db_q('DELETE FROM tecnicos WHERE id = ?', [$id])->rowCount() > 0; }
/** Sube (-1) o baja (+1) un técnico en el orden de la lista. */
function tec_mover(int $id, int $dir): void {
    db_tx(function () use ($id, $dir) {
        $l = array_column(db_todas('SELECT id FROM tecnicos ORDER BY orden, nombre FOR UPDATE'), 'id');
        $i = array_search($id, array_map('intval', $l), true);
        $j = $i === false ? false : $i + $dir;
        if ($j === false || $j < 0 || $j >= count($l)) return;
        [$l[$i], $l[$j]] = [$l[$j], $l[$i]];
        foreach ($l as $k => $tid) db_q('UPDATE tecnicos SET orden = ? WHERE id = ?', [$k + 1, (int)$tid]);
    });
}

/**
 * Convierte en lista desplegable los campos de técnico de la página. Se incluye al final de la página.
 * $yo: nombre del usuario que inició sesión (si es un técnico de la lista, se elige solo en los campos vacíos).
 */
function tecnicos_js(string $yo = ''): void {
    $l = array_map(fn($t) => ['n' => $t['nombre'], 'c' => $t['cedula'], 'p' => $t['puesto']], tec_listar(true)); ?>
<style>
.tecsel{width:100%;font:inherit}
.tec-oculto{display:none!important}
.tec-otro{margin-top:6px}
.tec-hint{display:block;font-size:12px;color:var(--mute,#566273);margin-top:3px}
@media print{.tecsel{appearance:none;-webkit-appearance:none;background:none!important;border:0!important;padding:0!important}.tec-hint{display:none}}
</style>
<script>
(()=>{
  const TEC=<?= json_encode($l, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,YO=<?= json_encode($yo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  if(!TEC.length)return;
  const norm=s=>String(s||'').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'').replace(/\s+/g,' ').trim();
  const buscar=v=>TEC.find(t=>norm(t.n)===norm(v));
  const desc=Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value');
  /** Lista desplegable para un campo de texto; opcional: input de cédula que se llena con la del técnico. */
  window.selectTecnico=function(inp,ced){
    if(!inp||inp.dataset.tec)return;inp.dataset.tec='1';
    const s=document.createElement('select');s.className='tecsel';s.setAttribute('aria-label',(inp.labels&&inp.labels[0]?inp.labels[0].textContent.replace('*','').trim():'Técnico'));
    if(inp.id){const lb=document.querySelector('label[for="'+inp.id+'"]');if(lb){lb.htmlFor='';s.id='sel_'+inp.id;lb.htmlFor=s.id}}
    s.append(new Option('— Seleccione el técnico —',''));
    TEC.forEach(t=>{const o=new Option(t.n,t.n);o.title=t.p||'';s.append(o)});
    s.append(new Option('Otro (escribir el nombre)…','__otro'));
    inp.before(s);inp.classList.add('tec-otro');
    let autoCed='';
    const verCed=t=>{if(!ced)return;const libre=!ced.value.trim()||ced.value===autoCed;if(!libre)return;const nv=t&&t.c?t.c:'';if(ced.value!==nv){ced.value=nv;ced.dispatchEvent(new Event('input',{bubbles:true}))}autoCed=nv};
    const sync=()=>{const v=desc.get.call(inp),t=buscar(v);
      if(t){s.value=t.n;inp.classList.add('tec-oculto')}
      else if(!v.trim()){if(s.value!=='__otro'){s.value='';inp.classList.add('tec-oculto')}}
      else{s.value='__otro';inp.classList.remove('tec-oculto')}};
    Object.defineProperty(inp,'value',{configurable:true,get(){return desc.get.call(this)},set(v){desc.set.call(this,v);sync()}});
    s.addEventListener('change',()=>{
      if(s.value==='__otro'){desc.set.call(inp,'');inp.classList.remove('tec-oculto');verCed(null);inp.focus()}
      else{desc.set.call(inp,s.value);inp.classList.add('tec-oculto');verCed(buscar(s.value))}
      inp.style.borderColor='';s.style.borderColor='';
      inp.dispatchEvent(new Event('input',{bubbles:true}));inp.dispatchEvent(new Event('change',{bubbles:true}))});
    // las validaciones marcan el campo en rojo: se refleja en la lista
    new MutationObserver(()=>{s.style.borderColor=inp.style.borderColor}).observe(inp,{attributes:true,attributeFilter:['style']});
    const f=inp.form;if(f)f.addEventListener('reset',()=>setTimeout(()=>{s.value='';sync();autoCed='';elegirYo()},0));
    const elegirYo=()=>{const t=buscar(YO);if(t&&!desc.get.call(inp).trim()){desc.set.call(inp,t.n);verCed(t);inp.dispatchEvent(new Event('input',{bubbles:true}))}sync()};
    elegirYo();
  };
  const ini=()=>{
    const $=q=>document.querySelector(q);
    // Actas de Entrega y Devolución: funcionario de TIC y su cédula
    selectTecnico($('#ent'),$('#cedtic'));
    // Bitácora de Simulacro: técnico y quien firma
    if($('#tec')&&$('#firmante')){selectTecnico($('#tec'));selectTecnico($('#firmante'))}
    // Préstamo de activos: firma del funcionario de TI
    document.querySelectorAll('input[name=fti_nombre]').forEach(i=>selectTecnico(i,i.form&&i.form.querySelector('input[name=fti_cedula]')));
    // Formularios del Constructor: campos cuya etiqueta habla del técnico o funcionario de TI
    if(typeof campos!=='undefined'&&typeof nodo==='function'){
      const RE=/tecnico|funcionario de tic?\b|responsable de tic?\b|soporte de ti/;
      campos.forEach(c=>{const et=norm(c.etiqueta),w=nodo(c);if(!w||!RE.test(et))return;
        if(c.tipo==='texto')selectTecnico(w.querySelector('input'),null);
        else if(c.tipo==='firma')selectTecnico(w.querySelector('[data-f="nombre"]'),w.querySelector('[data-f="cedula"]'))})}
  };
  document.readyState==='loading'?document.addEventListener('DOMContentLoaded',ini):ini();
})();
</script>
<?php }
