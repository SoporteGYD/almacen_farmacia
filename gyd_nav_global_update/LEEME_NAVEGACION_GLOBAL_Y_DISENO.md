# Navegación global y mejora visual de módulos

Este paquete agrega de forma global:
- Botón **Inicio ERP**
- Botón **Regresar** al módulo anterior
- Botón **Regresar al POS** en páginas relacionadas con POS, retiros, cortes y tickets
- Mejora visual ligera en páginas simples como el comprobante de retiro

## Instalación
1. Respaldar el ERP.
2. Copiar la carpeta `tools` y la carpeta `public/assets` del paquete al proyecto.
3. Ejecutar:

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\almacen-farmacia2\tools\instalar_navegacion_global.php" --check
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\almacen-farmacia2\tools\instalar_navegacion_global.php" --apply
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\almacen-farmacia2\tools\instalar_navegacion_global.php" --verify
```

Luego reiniciar Apache y actualizar con **Ctrl + F5**.
