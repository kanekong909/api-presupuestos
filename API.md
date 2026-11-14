# API de presupuestos (CLIM Eléctricos)

PHP 8 sin librerías, MySQL/MariaDB. Todas las respuestas son JSON: `{ "ok": true, … }` o `{ "ok": false, "error": "…" }`.
## Acceso

Sesión con token: `POST /auth/login` devuelve un `token`; en el resto de peticiones se manda `Authorization: Bearer <token>`.
El token dura `sesion_dias` días (30 por defecto) y en la base solo se guarda su hash.

- **Primer uso**: con la base vacía, `POST /auth/primer-admin` crea el administrador inicial (`/salud` dice `necesita_primer_admin`). Después se cierra para siempre.
- **Roles**: `admin` (todo: usuarios, ajustes, registro de actividad) y `tecnico` (presupuestos).
- **Clave de la API** (`X-Api-Clave`, config › `api_clave`): solo sirve para `POST /presupuestos` (pedidos que llegan de WhatsApp, web…), no para ver nada.
- Tras 5 intentos de acceso fallidos en 15 minutos se bloquea un rato (429).

## Flujo (la arquitectura)

```
POST /presupuestos            M1 normalizar → M2 interpretar (IA) → BORRADOR con resumen y preguntas
POST /presupuestos/{id}/editar   «Seguir editando»: la IA reinterpreta solo ese cambio
POST /presupuestos/{id}/confirmar   M3 clasificar → buscar (Novelec/productos + Base de servicios)
                                    → la IA elige candidatos → M4 motor económico → líneas, IVA y total
PATCH /presupuestos/{id}/lineas/{n}  el técnico corrige → queda en `correcciones` (aprendizaje)
```

La IA solo **interpreta y elige ids**. Los precios, márgenes, IVA y totales salen siempre del motor leyendo la base.

## Rutas

| Método y ruta | Cuerpo | Qué hace |
|---|---|---|
| `GET /salud` | – | Comprueba que la API está viva (sin sesión). |
| `POST /auth/login` | `{usuario, clave}` | Usuario o email. Devuelve `token` y `usuario`. |
| `POST /auth/primer-admin` | `{usuario, nombre, clave, email?}` | Solo si no hay ningún usuario. |
| `GET /auth/yo` · `POST /auth/logout` | – | Mi usuario · cerrar sesión. |
| `POST /auth/clave` | `{actual, nueva}` | Cambia mi contraseña y cierra mis otras sesiones. |
| `GET /usuarios` · `POST /usuarios` | `{usuario, nombre, clave, rol?, email?}` | **Admin.** Lista · crea (rol `tecnico` por defecto). |
| `PATCH /usuarios/{id}` | `{nombre?, email?, rol?, activo?, clave?}` | **Admin.** Desactivar o cambiar la clave cierra sus sesiones. Siempre queda un admin activo. |
| `GET /eventos?usuario=&accion=&entidad=&entidad_id=&limite=` | – | **Admin.** Quién hizo qué: logins (y fallidos), altas, presupuestos, ajustes. |
| `POST /presupuestos` | `{texto, cliente?, telefono?, email?, canal?}` | Crea el borrador. Devuelve `resumen_interpretado`, `datos_pendientes` e `interpretacion.datos`. |
| `GET /presupuestos?estado=&limite=&pagina=` | – | Lista. |
| `GET /presupuestos/{id}` | – | Presupuesto completo con `interpretacion` y `lineas`. |
| `POST /presupuestos/{id}/editar` | `{cambio}` | Reinterpreta solo el cambio. No vale tras confirmar (409). |
| `POST /presupuestos/{id}/confirmar` | – | Cierra la interpretación y calcula. Estado: `REVISION` o `CALCULADO`. |
| `POST /presupuestos/{id}/recalcular` | – | Rehace búsqueda y precios (p. ej. tras cambiar el margen). |
| `PATCH /presupuestos/{id}/lineas/{n}` | `{cantidad?, precio_unitario?, estado?, elegir?: {fuente, id}, comentario?}` | Corrige una línea. `estado`: `ACEPTADO`, `REVISION`, `DESCARTADO`. `elegir` cambia el producto o servicio por otro del catálogo. |
| `POST /presupuestos/{id}/estado` | `{estado}` | `BORRADOR, REVISION, CALCULADO, ENVIADO, ACEPTADO, RECHAZADO`. |
| `GET /servicios?q=` · `GET /productos?q=[&novelec=1]` | – | Busca en el catálogo (para elegir a mano); con `novelec=1` también en Novelec. |
| `GET /configuracion` · `PUT /configuracion/{clave}` | `{valor}` | Margen, IVA, umbrales de confianza. Cambiarlos: **admin**. |
| `GET/POST /base/servicios` · `GET/PATCH/DELETE /base/servicios/{id}` | `{codigo, linea, nombre, precio_unitario, unidad, modo_precio, …}` | **Admin.** Listar (`?q=&linea=&estado=&pagina=`), añadir, editar y borrar servicios. Uno usado en presupuestos no se borra: se desactiva. |
| `GET/POST /sinonimos` · `PATCH/DELETE /sinonimos/{id}` | `{termino, concepto_canonico, grupo?}` | **Admin.** Sinónimos activos. |
| `POST /sinonimos/sugerir` · `POST /sinonimos/sugeridos/{id}/aprobar\|rechazar` | – | **Admin.** La IA propone sinónimos a partir de las correcciones; nada se activa sin aprobarlo. |
| `GET /proveedores` | – | Proveedores con productos en la base (para elegir los preferidos). |
| `GET /correcciones` | – | **Admin.** Lo que han corregido los técnicos (interpretación, cantidades, precios, productos cambiados). |
| `DELETE /presupuestos/{id}` · `DELETE /presupuestos?estado=BORRADOR` | – | Borra uno (uno enviado o aceptado, solo admin) · borra todos los borradores. |
| `PATCH /presupuestos/{id}` | `{cliente?, telefono?, email?}` | Datos del cliente (salen en los PDF). |

## Estados de línea

`ACEPTADO` (confianza ≥ `confianza_automatica`) · `REVISION` (entre `confianza_revision` y la anterior) · `DATO_PENDIENTE` (falta un dato, R012) ·
`SERVICIO_NO_ENCONTRADO` / `PRODUCTO_NO_ENCONTRADO` (R009 / R010) · `DESCARTADO`. Solo suman `ACEPTADO` y `REVISION`.

## Cálculo

- **Servicio**: `precio_unitario × cantidad`, con `importe_minimo` si lo hay. Si el modo es `DESDE` o `BASE…`, la línea lleva un `aviso`.
- **Producto**: coste del proveedor (sin IVA si venía con él) × (1 + `margen_producto`).
- **IVA**: `iva_general`. Todo en la tabla `configuracion`.

## Pendiente

- **Enviar el presupuesto** (email / WhatsApp con el PDF): hoy el PDF se saca desde el navegador (botón «PDF cliente» / «Interno»).
- **Voz e imágenes** (M1): el normalizador ya acepta `extras` con texto; falta el transcriptor / lector de imágenes.
- **Entradas WhatsApp / chat**: llegarán a `POST /presupuestos` con `canal`.
- **Huecos de la base de servicios** que salieron en el uso real: instalación de split simple (solo hay MultiSplit), instalación de aerotermia (solo diagnóstico), suelo radiante, certificado de eficiencia energética, canalización de PVC.

## Despliegue en Hostinger

`python herramientas/preparar_subida.py` crea `para_subir/` con dos zips (el config del servidor sale del tuyo, con la base en `localhost`):

| Zip | Se descomprime en | Resultado |
|---|---|---|
| `1_privado_api-presupuestos.zip` | `domains/climelectricos.com/` | carpeta `api-presupuestos/` (src, config, prompts…), fuera de `public_html` |
| `2_publico_api.zip` | `public_html/api-presupuesto/` | `index.php` y `.htaccess` |

Antes, vacía `public_html/api-presupuesto/` (los archivos de la API vieja) y borra `domains/climelectricos.com/api-cotizador/`.
Las tablas ya están en la base de Hostinger. Si algún día cambias de base: `php herramientas/migrar.php`.
Comprobación: abrir `https://api-presupuesto.climelectricos.com/salud`.
No hace falta ningún cron.

## Novelec

Para cada material se busca primero en gruponovelec.com con el usuario de `config › novelec` (con sesión iniciada salen los precios
de profesional; el PVP sin sesión no se usa) y el resultado se guarda en `productos` (proveedor `NOVELEC`).
`productos.fuente`: `local_y_novelec` (por defecto) o `local` (nunca entra en Novelec). El catálogo local (OBRAMAT, Solar Fácil) queda de respaldo, y los ajustes «proveedor preferido» (general y fotovoltaica) deciden de quién elige la IA.

## Sinónimos

La tabla `sinonimos` (cómo lo dice el cliente → cómo se llama en la base; `sql/04_sinonimos.sql` trae los primeros). La IA los usa para entender el pedido y el buscador del catálogo, para encontrar lo que se pide con otras palabras.
Probar el acceso y ver qué precios lee: `php herramientas/probar_novelec.php "magnetotérmico 16A"`.
