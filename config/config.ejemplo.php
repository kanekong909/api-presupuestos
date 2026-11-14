<?php
// Config PRIVADA (no se sube a git ni se enseña). Copia de config.ejemplo.php con tus valores.
return array (
  'bd' => 
  array (
    'host' => '127.0.0.1',
    'puerto' => 3306,
    'nombre' => 'presupuestos_dev',
    'usuario' => 'root',
    'clave' => 'PON_AQUI',
  ),
  // OPCIONAL — solo si la tabla `productos` (el catálogo) está en OTRA base de datos, con su propio usuario de MySQL. Entonces «bd» es la base de los
  // presupuestos (presupuestos, usuarios, reglas, notas…) y «catalogo» la de los productos. Si no se pone, todo está en la misma base (la de «bd»).
  // Ver herramientas/separar_bd.php para pasar de una a dos bases.
  // 'catalogo' => array (
  //   'nombre' => 'NOMBRE_DE_LA_BASE_DE_PRODUCTOS',
  //   'usuario' => 'USUARIO_DE_ESA_BASE',
  //   'clave' => 'PON_AQUI',
  // ),
  'api_clave' => 'PON_AQUI',
  'zona_horaria' => 'Europe/Madrid',
  'depurar' => true,
  'ia' => 
  array (
    'clave' => 'PON_AQUI',
    'tiempo_maximo' => 120,
    'reintentos' => 2,
    'usos' => 
    array (
      'interprete' => 
      array (
        'modelo' => 'claude-sonnet-5-5',
        'max_tokens' => 10000,
      ),
      'emparejar' => 
      array (
        'modelo' => 'claude-sonnet-5-5',
        'max_tokens' => 12000,
      ),
    ),
  ),
  'productos' => 
  array (
    'fuente' => 'local_y_novelec',
    'proveedores' => 
    array (
    ),
  ),
  // Base de clientes del sistema «Servicios Clim» (solo lectura). Opcional: sin esto, la búsqueda de clientes no aparece.
  // En Hostinger, 'host' es 'localhost' si esa base está en la misma cuenta; usa un usuario de MySQL de SOLO LECTURA.
  'clientes' => 
  array (
    'host' => 'localhost',
    'base' => 'PON_AQUI_el_nombre_de_la_base_de_Servicios_Clim',
    'usuario' => 'PON_AQUI',
    'clave' => 'PON_AQUI',
    'tabla' => 'clientes',
    // OPCIONAL: la dirección web desde la que Servicios Clim sirve las fotos de las evidencias (para verlas al buscar un servicio). Sin ella solo se cuenta cuántas hay.
    // 'archivos_url' => 'https://TU_DOMINIO_DE_SERVICIOS_CLIM/api/',
  ),
  'novelec' => 
  array (
    'url_login' => 'https://www.gruponovelec.com/login',
    'usuario' => 'tu_usuario',
    'clave' => 'PON_AQUI',
  ),
);
