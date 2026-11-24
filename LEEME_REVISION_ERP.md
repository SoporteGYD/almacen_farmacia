# LEER ANTES DE INSTALAR — Auditoría ERP G&D

Esta carpeta es **una COPIA DEPURADA PARA PRUEBAS**, NO reemplaza de forma automática la instalación en producción.

Cambios de seguridad aplicados al código, SIN modificar datos MySQL. Ejecuta `php tests/test_revision_seguridad_estatica.php` y las 10 pruebas PHP incluidas.

ATENCIÓN:
- Del ZIP original se excluyeron `.git`, claves privadas de correo, `mail.local.php`, los backups SQL y archivos PHP de respaldo. **Conserva tus configuraciones de correo reales en un lugar seguro y NO publiques el ZIP original.**
- Se deshabilitó deliberadamente `public/create_admin.php` (peligroso restablecimiento con contraseña fija). Para recuperación de acceso utiliza procesos internos autenticados.
- `importar_productos.php` para reescribir saldos Excel está DESACTIVADO por defecto (`GD_ALLOW_DIRECT_STOCK_IMPORT` distinto de `1`). Importar compras/OC es independiente; utiliza Conteos y Ajustes Autorizados hasta diseñar un proceso de conciliación.
- Se redujo el tiempo de sesión de 10 años a un máximo deslizante de 30 días. Esta medida no es un sistema integral de MFA/expiración por inactividad.
- `respaldos.php` ahora apunta por defecto a la BD de desarrollo `prueba`; en servidores distintos establecer `GD_DB_NAME` y verificar el dump físicamente en una restauración independiente.
- `.htaccess` requiere Apache con `AllowOverride`. Es preferible que `DocumentRoot` apunte SOLO a `public/`. Si el host usa Nginx, instalar reglas equivalentes.

Instalación recomendada:
1. Respalda archivos existentes, BD de MySQL y credenciales locales (fuera del directorio público).
2. Usa **el ZIP de parches** sobre copia del proyecto; compara primero con diff. No sustituyas en bloque la instalación de producción con este snapshot.
3. Ejecuta pruebas CLI, pruebas de XAMPP, roles, POS, inventarios y reportes con UNA BASE CLONADA.
4. Usa `sql/AUDITORIA_ERP_SOLO_LECTURA.sql` para revisar inconsistencias. NO ejecutar ajustes SQL directos.
5. Aprobación formal de Compras, Almacén, Caja, Dirección y Sistemas antes de promover.

No se han ejecutado migraciones ni realizado movimientos de inventario a partir de este ZIP.
