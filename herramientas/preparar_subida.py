# PREPARA LA SUBIDA a Hostinger: crea para_subir/1_privado_api-presupuestos.zip y para_subir/2_publico_api.zip
#   python herramientas/preparar_subida.py
# El config.php del servidor sale del tuyo, con la base en «localhost» (así se conecta Hostinger a su propia MySQL) y sin mensajes de depuración.
import os, subprocess, zipfile, shutil, sys

R = os.path.dirname(os.path.dirname(os.path.abspath(__file__))).replace(os.sep, '/')
OUT = R + '/para_subir'
shutil.rmtree(OUT, ignore_errors=True)
os.makedirs(OUT)

PHP = shutil.which('php') or 'C:/xampp2/php/php.exe'
php = r'''
$c = require "%s/config/config.php";
$c["bd"]["host"] = "localhost";
$c["depurar"] = false;
unset($c["bd_nueva"]);   // el bloque temporal de la copia entre bases no se sube
if (isset($c["catalogo"]["host"])) $c["catalogo"]["host"] = "localhost";
if (isset($c["clientes"]["host"])) $c["clientes"]["host"] = "localhost";
echo "<?php\n// Config del SERVIDOR (privada). Generada por herramientas/preparar_subida.py\nreturn " . var_export($c, true) . ";\n";
''' % R
servidor = subprocess.run([PHP, '-r', php], capture_output=True, check=True).stdout.decode('utf-8')
assert servidor.startswith('<?php') and "'bd'" in servidor, 'No se ha podido generar el config del servidor'

def anadir(z, carpeta, destino):
    for raiz, dirs, archivos in os.walk(R + '/' + carpeta):
        dirs[:] = [d for d in dirs if d not in ('para_subir',)]
        for a in archivos:
            if a.startswith('novelec_') and a.endswith('.html'): continue   # lo que guarda la herramienta de pruebas
            ruta = os.path.join(raiz, a).replace(os.sep, '/')
            z.write(ruta, destino + '/' + os.path.relpath(ruta, R).replace(os.sep, '/'))

with zipfile.ZipFile(OUT + '/1_privado_api-presupuestos.zip', 'w', zipfile.ZIP_DEFLATED) as z:
    for d in ('src', 'prompts', 'sql', 'herramientas'):
        anadir(z, d, 'api-presupuestos')
    z.write(R + '/API.md', 'api-presupuestos/API.md')
    z.write(R + '/config/.htaccess', 'api-presupuestos/config/.htaccess')
    z.write(R + '/config/config.ejemplo.php', 'api-presupuestos/config/config.ejemplo.php')
    z.writestr('api-presupuestos/config/config.php', servidor.encode('utf-8'))
    z.writestr('api-presupuestos/.htaccess', '# Carpeta privada: nadie puede abrirla desde el navegador.\nRequire all denied\n')

with zipfile.ZipFile(OUT + '/2_publico_api.zip', 'w', zipfile.ZIP_DEFLATED) as z:
    z.write(R + '/public/index.php', 'index.php')
    z.write(R + '/public/.htaccess', '.htaccess')

for f in sorted(os.listdir(OUT)):
    with zipfile.ZipFile(OUT + '/' + f) as z:
        print(f, os.path.getsize(OUT + '/' + f), 'bytes ·', len(z.namelist()), 'archivos')
print('\nListo. Sube cada zip como indica API.md › Despliegue (el 1 a domains/<tu dominio>/ y el 2 a public_html/api-presupuesto/).')
