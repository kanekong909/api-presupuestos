<?php
// Aplica sql/02 a 11 a la base de config.php (se puede repetir sin problema).   php herramientas/migrar.php
require dirname(__DIR__) . '/src/arranque.php';
$sql = (string) file_get_contents(RAIZ . '/sql/02_extensiones.sql') . "
" . (string) file_get_contents(RAIZ . '/sql/03_usuarios.sql') . "
" . (string) file_get_contents(RAIZ . '/sql/04_sinonimos.sql') . "\n" . (string) file_get_contents(RAIZ . '/sql/05_sinonimos_sugeridos.sql') . "\n" . (string) file_get_contents(RAIZ . '/sql/06_fijados.sql') . "
" . (string) file_get_contents(RAIZ . '/sql/07_correcciones_ignorar.sql') . "\n" . (string) file_get_contents(RAIZ . '/sql/08_linea_manual.sql') . "\n" . (string) file_get_contents(RAIZ . '/sql/09_grupos.sql') . "\n" . (string) file_get_contents(RAIZ . '/sql/10_trabajos_tipo.sql') . "\n" . (string) file_get_contents(RAIZ . '/sql/11_imagenes.sql');
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
foreach (array_filter(array_map('trim', explode(';', $sql))) as $orden) {
    Db::pdo()->exec($orden);
    echo 'OK: ' . substr(preg_replace('/\s+/', ' ', $orden), 0, 80) . "…\n";
}
