#!/bin/bash
# Instalador de Actas TIC con inicio de sesión y recuperación de contraseña por correo
# Uso (como root):  bash instalar.sh
# Se puede ejecutar de nuevo para actualizar: respalda lo anterior y conserva usuarios y configuración.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

ORIGEN="$(cd "$(dirname "$0")" && pwd)"
WEB=/var/www/html
APP=/var/www/actas-app
CONF=/etc/actas
DATOS=/var/lib/actas
RESPALDO=/root/respaldo-actas-$(date +%Y%m%d-%H%M%S)

[ "$(id -u)" -eq 0 ] || { echo "Ejecute como root:  sudo su  y luego  bash $0"; exit 1; }
[ -f "$ORIGEN/web/login.php" ] && [ -f "$ORIGEN/app/auth.php" ] || { echo "No encuentro las carpetas web/ y app/ junto a este script."; exit 1; }

echo "== 1. Verificando PHP y el envío de correo"
PAQ=""
command -v php >/dev/null 2>&1 && apache2ctl -M 2>/dev/null | grep -qE 'php[0-9._]*_module|proxy_fcgi_module' || PAQ="$PAQ php libapache2-mod-php"
command -v msmtp >/dev/null 2>&1 || PAQ="$PAQ msmtp msmtp-mta ca-certificates"
if [ -n "$PAQ" ]; then
  echo "   Instalando:$PAQ"
  apt-get update -qq
  apt-get install -y $PAQ
fi
php -v | head -1

echo "== 2. Respaldando archivos anteriores en $RESPALDO"
mkdir -p "$RESPALDO"
for f in index.html acta_entrega_equipos_web.html acta_devolucion_equipos_web.html \
         index.php login.php logout.php olvido.php restablecer.php cambiar_clave.php usuarios_admin.php prestamos.php registros.php inventario.php colaboradores.php bitacora_mantenimiento.php checklist_equipos.php formulario.php formularios_admin.php papelera.php \
         acta_entrega_equipos_web.php acta_devolucion_equipos_web.php; do
  if [ -e "$WEB/$f" ]; then cp -a "$WEB/$f" "$RESPALDO/"; fi
done
if [ -d "$APP" ]; then cp -a "$APP" "$RESPALDO/actas-app"; fi
if [ -d "$CONF" ]; then cp -a "$CONF" "$RESPALDO/etc-actas"; fi
if [ -d "$DATOS/formularios" ]; then cp -a "$DATOS/formularios" "$RESPALDO/formularios"; fi
if [ -d "$DATOS/prestamos" ]; then cp -a "$DATOS/prestamos" "$RESPALDO/prestamos"; fi
if [ -d "$DATOS/registros" ]; then cp -a "$DATOS/registros" "$RESPALDO/registros"; fi
if [ -d "$DATOS/datos" ]; then cp -a "$DATOS/datos" "$RESPALDO/datos"; fi
# Los .html viejos NO pueden quedar en la carpeta web: se podrían abrir sin iniciar sesión.
rm -f "$WEB/index.html" "$WEB/acta_entrega_equipos_web.html" "$WEB/acta_devolucion_equipos_web.html"
# Formularios retirados
rm -f "$WEB/checklist_equipos.php"

echo "== 3. Copiando la aplicación"
install -d -o root -g www-data -m 750 "$APP"
install -o root -g www-data -m 640 "$ORIGEN"/app/*.php "$APP/"
install -o root -g www-data -m 644 "$ORIGEN"/web/*.php "$WEB/"
# www-data necesita escribir usuarios.json cuando alguien restablece su contraseña
install -d -o root -g www-data -m 770 "$CONF"
if [ -f "$CONF/usuarios.json" ]; then chown root:www-data "$CONF/usuarios.json"; chmod 660 "$CONF/usuarios.json"; fi
install -d -o www-data -g www-data -m 700 "$DATOS" "$DATOS/intentos" "$DATOS/reset" "$DATOS/formularios" "$DATOS/formularios/papelera" "$DATOS/prestamos" "$DATOS/prestamos/pdf" "$DATOS/registros" "$DATOS/datos" "$DATOS/datos/historial"
# El formulario «Préstamo de Activos» del constructor se reemplazó por el módulo de préstamos (prestamos.php)
VIEJO="$DATOS/formularios/prestamo-de-activos.json"
if [ -f "$VIEJO" ] && [ "$(php -r 'echo json_decode(file_get_contents($argv[1]),true)["modificado_por"]??"";' "$VIEJO")" = "instalador" ]; then
  rm -f "$VIEJO"; echo "   Se retiró el formulario «Préstamo de Activos» anterior: ahora es el módulo de préstamos."
fi
# Formularios que vienen con el paquete: se instalan solo si no existen (no se pisan los que ya se editaron)
for f in "$ORIGEN"/formularios/*.json; do
  [ -e "$f" ] || continue
  destino="$DATOS/formularios/$(basename "$f")"
  if compgen -G "$DATOS/formularios/papelera/$(basename "$f" .json)__*.json" >/dev/null; then
    echo "   Se omite «$(basename "$f" .json)»: está en la Papelera (restáurelo desde ahí si lo necesita)."
  elif [ ! -e "$destino" ]; then
    install -o www-data -g www-data -m 660 "$f" "$DATOS/formularios/"; echo "   Formulario agregado: $(basename "$f" .json)"
  elif [ "$(php -r 'echo json_decode(file_get_contents($argv[1]),true)["modificado_por"]??"";' "$destino")" = "instalador" ]; then
    # Nunca fue editado desde el constructor: se actualiza a la versión del paquete
    install -o www-data -g www-data -m 660 "$f" "$DATOS/formularios/"; echo "   Formulario actualizado: $(basename "$f" .json)"
  else
    echo "   Se conserva «$(basename "$f" .json)» porque fue editado desde el constructor."
  fi
done

echo "== 4. Configuración de recuperación de contraseña ($CONF/config.json)"
if [ ! -f "$CONF/config.json" ]; then
  IP=$(hostname -I | awk '{print $1}')
  read -rp "   Dirección con la que los usuarios abren el sistema [http://$IP]: " URL;  URL=${URL:-http://$IP}
  read -rp "   Correo remitente de los avisos [actas-tic@municarrillo.go.cr]: " REM;     REM=${REM:-actas-tic@municarrillo.go.cr}
  read -rp "   Dominio permitido para correos de usuarios [municarrillo.go.cr]: " DOM;  DOM=${DOM:-municarrillo.go.cr}
  URL="$URL" REM="$REM" DOM="$DOM" php -r 'file_put_contents("/etc/actas/config.json", json_encode(["url_base"=>rtrim(getenv("URL"),"/"),"remitente"=>getenv("REM"),"dominio"=>getenv("DOM")], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);'
  chown root:www-data "$CONF/config.json"; chmod 640 "$CONF/config.json"
else
  echo "   Ya existe; se conserva:"; sed 's/^/     /' "$CONF/config.json"
fi

echo "== 5. Cuenta SMTP para enviar correos (/etc/msmtprc)"
install -o root -g root -m 644 "$ORIGEN/msmtprc.ejemplo" /etc/msmtprc.ejemplo
if [ ! -f /etc/msmtprc ]; then
  install -o root -g www-data -m 640 "$ORIGEN/msmtprc.ejemplo" /etc/msmtprc
  SMTP_PENDIENTE=1
  echo "   Se creó /etc/msmtprc de ejemplo. DEBE editarlo con los datos de su servidor de correo."
else
  chgrp www-data /etc/msmtprc; chmod 640 /etc/msmtprc
  grep -q "CAMBIAR_ESTA_CLAVE" /etc/msmtprc && SMTP_PENDIENTE=1 || SMTP_PENDIENTE=0
fi

echo "== 6. Recargando Apache"
apache2ctl configtest
systemctl reload apache2

echo "== 7. Comprobando"
CUERPO=$(curl -s http://localhost/login.php || true)
if echo "$CUERPO" | grep -q "<?php"; then
  echo "   ERROR: Apache está mostrando el código PHP en vez de ejecutarlo. Revise que el módulo PHP esté activo."; exit 1
elif echo "$CUERPO" | grep -q "Acceso exclusivo personal de TI"; then
  echo "   OK: la página de inicio de sesión responde."
else
  echo "   AVISO: no pude comprobar http://localhost/login.php (revise apache2ctl -S)."
fi
if su -s /bin/sh www-data -c "test -w '$DATOS/registros'"; then echo "   OK: Apache puede guardar las actas y registros."; else echo "   ERROR: Apache no puede escribir en $DATOS/registros"; fi
if su -s /bin/sh www-data -c "test -w '$DATOS/prestamos'"; then echo "   OK: Apache puede guardar reservas de préstamos."; else echo "   ERROR: Apache no puede escribir en $DATOS/prestamos"; fi
if su -s /bin/sh www-data -c "test -w '$DATOS/datos'"; then echo "   OK: Apache puede guardar la base de datos de colaboradores."; else echo "   ERROR: Apache no puede escribir en $DATOS/datos"; fi
if su -s /bin/sh www-data -c "test -w '$DATOS/formularios'"; then echo "   OK: Apache puede guardar formularios del constructor."; else echo "   ERROR: Apache no puede escribir en $DATOS/formularios"; fi
CODIGO=$(curl -s -o /dev/null -w '%{http_code}' http://localhost/acta_entrega_equipos_web.php || true)
if [ "$CODIGO" = "302" ]; then echo "   OK: los formularios redirigen al login si no hay sesión."; else echo "   AVISO: el formulario respondió $CODIGO (se esperaba 302)."; fi

echo "== 8. Usuarios de TI"
if [ "$(php "$APP/usuarios.php" listar)" = "(no hay usuarios)" ]; then
  read -rp "   No hay usuarios. Nombre del primer usuario administrador (ej. dereck): " U
  php "$APP/usuarios.php" agregar "$U" admin
elif ! php "$APP/usuarios.php" listar | awk '$2=="admin" && $3=="activo"' | grep -q .; then
  echo "   No hay ningún administrador (necesario para el módulo de usuarios)."
  php "$APP/usuarios.php" listar | sed 's/^/     /'
  read -rp "   ¿Qué usuario será administrador? " U
  php "$APP/usuarios.php" rol "$U" admin
fi
echo "   Usuarios actuales:"; php "$APP/usuarios.php" listar | sed 's/^/     /'
if php "$APP/usuarios.php" listar | grep -q "sin correo"; then
  echo "   ATENCIÓN: los usuarios 'sin correo' no podrán recuperar su contraseña. Asígnelo con:"
  echo "     php $APP/usuarios.php correo <usuario>"
fi

echo
echo "Listo. Abra $(php -r 'echo json_decode(file_get_contents("/etc/actas/config.json"),true)["url_base"] ?? "";')/ desde cualquier PC de la red."
echo "Respaldo de lo anterior: $RESPALDO"
echo "Para agregar usuarios: ingrese como administrador y use «Mantenimiento de usuarios» en el menú."
if [ "${SMTP_PENDIENTE:-0}" = "1" ]; then
  echo
  echo "PENDIENTE: configure el correo para que funcione '¿Olvidó su contraseña?':"
  echo "  1) nano /etc/msmtprc        (servidor, usuario y contraseña SMTP)"
  echo "  2) php $APP/usuarios.php probar-correo <usuario>"
fi
