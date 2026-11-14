<?php
// ARRANQUE — carga las clases (una por archivo, mismo nombre) y deja la zona horaria puesta.

define('RAIZ', dirname(__DIR__));

spl_autoload_register(function (string $clase): void {
    $ruta = __DIR__ . '/' . $clase . '.php';
    if (is_file($ruta)) require $ruta;
});

mb_internal_encoding('UTF-8');
date_default_timezone_set((string) Config::get('zona_horaria', 'Europe/Madrid'));
