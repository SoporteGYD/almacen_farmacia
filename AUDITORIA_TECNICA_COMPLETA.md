# Auditoría técnica integral — ERP Farmacias G&D
**Corte del código recibido:** 20/10/2026 · **Estado:** fase de estabilización (NO aprobado para producción)

## 0. Alcance y método
Se examinó el archivo `almacen-farmacia2(2).zip` (estructura completa: directorios `app`, `public`, `sql`, `vendor`, `tools`, pruebas, respaldos e historial). Se analizaron rutas funcionales, control de inventario, autenticación, importadores, respaldos y flujo OC → recepción → factura → verificación → aplicación, con pruebas estáticas y de lógica sin MySQL. No se conectó a la instancia MySQL que utiliza la empresa; el respaldo SQL del paquete es anterior y **no representa el estado actual de su base `prueba`**. Por tanto, no afirmamos haber corregido todas las anomalías ni probado el ERP bajo carga, concurrencia, ventas reales y sucursales múltiples.

**Criterio de severidad:** Crítico = posible intrusión o daño financiero/inventario; Alto = inconsistencia operativa frecuente; Medio = mantenibilidad/control.

## 1. Dictamen ejecutivo
**La dirección del proyecto es correcta**: catálogo único, inventario por sede/ubicación, POS, compras y OC, recepción, verificación, resurtidos, devoluciones, Kardex y finanzas. Pero el ERP está compuesto de desarrollos incrementales con reglas dispersas y varias formas paralelas de registrar el mismo evento. La funcionalidad y la exactitud de inventario se deben certificar antes de ser sistema de registro principal.

### Hallazgos corroborados en código (con evidencia)
| ID | Riesgo | Severidad | Evidencia del proyecto | Medida |
|---|---|---|---|---|
| H-01 | Ruta web para **resetear la contraseña de administrador a una clave fija** | CRÍTICO | `public/create_admin.php` original; acceso de administrador permitía acción GET destructiva | **Corregido en este paquete:** endpoint deshabilitado HTTP 410. Gestionar contraseñas por administración segura |
| H-02 | Cookies de sesión persistente **10 años** | CRÍTICO | `app/helpers/auth.php` (`$tiempoSesion`) | **Mitigado:** 30 días máximo deslizantes; pendiente inactividad, MFA, revocación de credenciales y política por rol |
| H-03 | Importación Excel de stock accesible a **cualquier usuario con login**, sin CSRF, sobrescribe cantidades y cambia sucursal/ubicación del maestro | CRÍTICO | `public/importar_productos.php` | **Mitigado:** solo Dirección/Administración, CSRF, confirmación, validación, bloqueo por defecto por bandera, sin sobrescribir sucursal/ubicación global ni reactivar productos |
| H-04 | El ZIP contiene credenciales locales, clave de correo, historial `.git` y respaldo SQL con datos históricos | CRÍTICO | `app/config/mail.local.php`, `app/config/erp_mail.key`, `.git/`, `backups/inventario/` | **Mitigado en copia depurada:** excluidos de distribución. Revisar exposición previa y rotar claves de correo si se compartieron |
| H-05 | Configuración de respaldos apunta a base `almacen_farmacia` mientras conexión principal usa `prueba` | ALTO | `public/respaldos.php` vs `app/config/database.php` | **Corregido por defecto en desarrollo:** `GD_DB_NAME`, predeterminado `prueba`; verificar cada servidor y restauración de respaldo |
| H-06 | Falta política consistente de exposición web de directorios privados | ALTO | App desplegada bajo `htdocs`; `.gitignore.txt` no actúa como `.gitignore`; no había `.htaccess` protector | **Mitigado:** `.htaccess` de raíz y public, `.gitignore`; preferir DocumentRoot directamente en `public/` |
| H-07 | Factura verificada/aplicada aunque una OC tuvo recepción parcial; ya se observó diferencia 100/80 | CRÍTICO para datos | `erp_facturas_verificacion.php`, `erp_facturas_aplicar.php`, `erp_oc_seguimiento.php`; caso OC8 analizado | **No reescribir retrospectivamente**: OC8 se concilió y se añadió límite neto, pero requieren pruebas concurrentes y reconciliación general SQL |
| H-08 | Productos se validan contra duplicados mediante recorrido de tabla y SQL opcional, sin confirmación de restricciones efectivamente instaladas | ALTO | `app/helpers/erp_productos_unicos.php` y `sql/02_refuerzo_unicidad_OPCIONAL_TRAS_LIMPIAR.sql` | Diagnóstico SQL; imponer índices únicos después de depurar duplicados y normalizar catálogo |
| H-09 | Existen al menos dos reservas/almacenes lógicos de stock y flujos previos de movimientos | ALTO | `producto_existencias`, `pos_existencias_farmacia`, `movimientos`, `erp_facturas_stock_aplicaciones` y entradas clásicas | Definir servicio único de contabilización y matriz de transacciones por sede; impedir doble contabilización |
| H-10 | Prueba lógica de conciliación estaba rota por campos simulados antiguos | MEDIO | `tests/test_conciliacion_sin_mysql.php` | **Corregido el mock de prueba** y ejecutadas de nuevo todas las pruebas del paquete |
| H-11 | Hay muchos scripts SQL independientes, respaldos PHP y parches instalables, sin una única tabla/orden de migraciones verificadas | ALTO | carpeta `sql/`, instaladores de `tools/`, múltiples `*.backup_*` | Proponer migraciones versionadas y snapshots de esquema por entorno; no ejecutar SQL en bloque |
| H-12 | Pantallas de `Recepción por OC`, `Entradas clásicas` y `Entradas por factura` pueden representar un mismo evento | ALTO | rutas de `public/`, avisos en pantalla | Elegir un camino de recepción por sede y generar idempotencia por documento/OC/partida/ubicación |
| H-13 | Integraciones con WinCaja/Pharmacy Lite no son transaccionalmente verificables en este código; CFDI requiere certificación PAC | ALTO | módulos de POS/ERP existentes y ausencia de ETL/PAC completo probado | No tratar el ERP propio como sustituto de sistemas externos hasta finalizar conciliación, ETL y timbrado |
| H-14 | El importador considera cantidades mal formadas como cero, incluyendo decimales/valores no numéricos | ALTO | `public/importar_productos.php` original | **Corregido:** rechaza explícitamente valores inválidos en vez de borrar stock accidentalmente |

## 2. Operación objetivo: una sola fuente de verdad
```
Catálogo maestro compartido (producto_id único; claves de barras/equivalencias normalizadas)
  ├── Compras: OC EMITIDA (autorización de Dirección OPCIONAL, actualmente inactiva)
  │      └── Factura real del proveedor (documento completo o parcial, no stock)
  │               └── Recepción física contra OC (0..N entregas)
  │                       └── Verificación por lote/caducidad/calidad
  │                              └── MOVIMIENTO contable de INVENTARIO, idempotente
  ├── Ciudad Hidalgo/Tuxtla: ALMACÉN → resurtido/transferencia → FARMACIA (movimiento doble compensado)
  ├── Tapachula/Toscana: FARMACIA con inventario propio
  ├── Punto de venta: ticket → reserva/transacción → venta → Kardex/saldo final
  └── Devoluciones, mermas, vencimientos y ajustes: SIEMPRE movimiento auditable
```

**Invariantes obligatorias:** (i) crear OC/factura/recepción documental no suma existencia; (ii) verificación solo acepta mercancía realmente registrada y comprobada; (iii) ingreso a stock una vez por línea verificada, con clave idempotente; (iv) lo cancelado se reversa auditablemente; (v) transferencia de bodega a farmacia = una salida y una entrada ligadas, sin stock fantasma; (vi) importar un listado/OC no altera existencias; (vii) costo histórico se preserva, precios solo por autorización; (viii) claves únicas por catálogo; (ix) catálogo no contiene una única ubicación mutable para todas las sedes.

## 3. Revisión por módulo
| Área | Situación observada | Próximo control obligatorio |
|---|---|---|
| Roles/login | Autenticación por contraseña, sesión reconstruible y permisos dispersos en endpoints | Política de roles única, renovación tokens, expiración por inactividad, MFA para Dirección y admins |
| Catálogo | Modelo `productos`, validador de duplicados, categorías y proveedores | Índices únicos y equivalencias; ficha única; alta sin afectar stock; control de unidades pieza/caja |
| OC/compras | Pantallas OC con importación masiva y PDFs con/sin costos, política sin autorización | Contrato de proveedor, serie/folio idempotente, costos historizados, destinatarios/estados |
| Facturas compras | Captura documental separada de stock y verificación; conciliaciones posteriores | UUID/proveedor/número únicos según regla fiscal, facturas sin OC autorizadas solo Dirección, créditos/nc |
| Recepción | Captura parcial con lotes opcionales | Control de caducidades y FEFO por medicamento, rechazo/sobrantes/daños/retiros, cantidad neta verificada |
| Inventario bodega | Varias ubicaciones y Kardex, riesgo de duplicación entre flujos antiguos | Servicio único de movimientos por lote/ubicación, reposición y reconteo con eventos |
| POS | Cajeros, cobro, reservas, tickets y controles de turno | Pruebas de concurrencia, cierres, devolución/reversa, precios por sucursal, stock no negativo |
| Resurtidos | Flujo farmacia ↔ bodega y tickets | Prueba extremo a extremo de salida y entrada en farmacia con trazabilidad e idempotencia |
| Caja/finanzas | CXP/tesorería/control bancario y reportes implementados parcialmente | Reconciliar documentos y pagos; evitar dobles registros; conciliaciones bancarias |
| ERP e integración | Módulos cada vez más numerosos sin capa de dominio única | Contratos de API/ETL, réplica WinCaja controlada, catálogo único, migraciones versionadas |
| Auditoría | Hay tablas y mecanismos de auditoría, pero varios flujos compensan fuera de operaciones originales | Registro inmutable de reversas, referencias cruzadas, razón y usuario, control SO-D |
| Reportes | Análisis y exportaciones existentes, algunas dependientes de modelos diferentes | KPI consistentes y conciliados: inventario, margen, vencimientos, diferencias, reposiciones |

## 4. Cambios entregados ahora
**Archivos modificados (comparados con ZIP original):**
- `public/create_admin.php` — deshabilita restablecimiento web con contraseña predecible.
- `app/helpers/auth.php` — reduce persistencia 10 años → 30 días; NO soluciona todo el control de sesión.
- `public/importar_productos.php` — restringe rol, CSRF, confirmación, límite de archivo, validación de cantidades; la sobrescritura directa queda **desactivada por defecto**, con bandera de excepción `GD_ALLOW_DIRECT_STOCK_IMPORT=1` solo en entorno supervisado; no altera sucursal/ubicación global del producto.
- `public/respaldos.php` — selecciona nombre de BD mediante `GD_DB_NAME`, valor predeterminado `prueba` para este entorno de desarrollo.
- `tests/test_conciliacion_sin_mysql.php` — sincroniza simulación de resultados SQL con las consultas vigentes.
- `.htaccess` y `public/.htaccess` — bloquean exposición HTTP de archivos privados en Apache con `AllowOverride` habilitado; `.gitignore` auténtico.
- `sql/AUDITORIA_ERP_SOLO_LECTURA.sql` — auditoría diagnóstica sin escrituras.

**No se modificaron**: facturas históricas, movimientos, tablas de existencias, datos del usuario, integraciones SMTP reales, certificados y producción.

## 5. Validación de entrega
- Sintaxis PHP: todos los archivos PHP propios incluidos fuera de `vendor` validados con PHP CLI 8.4 (sin errores).
- Pruebas existentes: 11 archivos de pruebas pasan tras reparar una simulación desactualizada.
- Verificación seguridad estática: no hay endpoint público activo de reseteo fijo; existen reglas Apache y barreras en importador.
- Integración real **NO PROBADA**: no hubo base de datos actualizada ni entorno web funcional con XAMPP de Windows. El proveedor de bibliotecas `vendor` se copia íntegro sin modificarlo.

## 6. Despliegue seguro y reversibilidad
1. **No desplegar todo el proyecto inmediatamente**. Exportar `prueba` en phpMyAdmin y comprobar que el archivo existe. Resguardar ZIP actual, `.git` y archivos locales de correo **fuera de la carpeta pública**.
2. Aplicar primero el ZIP de parches en una copia local, usando diff y sin sobrescribir las configuraciones privadas de correo/BD.
3. Probar inicio/cierre de sesión, importador deshabilitado, OC → factura → recepción parcial → verificación → Kardex; POS, caja, resurtido y ventas concurrentes, devoluciones y reportes.
4. Ejecutar consultas de `AUDITORIA_ERP_SOLO_LECTURA.sql`; resolver las diferencias **con ajustes auditados, no UPDATE manual sobre stock**.
5. Verificar el backup concreto: al usar `GD_DB_NAME`, confirmar que el dump generado contiene el esquema correcto y que se restaura en una base de pruebas separada.
6. Antes de exponer Ubuntu/HTTP: TLS, firewall/Tailscale, DocumentRoot `public/`, secretos fuera de `htdocs`, permisos mínimos de MySQL, expiración de sesiones por inactividad y rotación de credenciales.
7. Rollback: restaurar los archivos afectados desde copia anterior; no ejecutar restores de base salvo procedimiento supervisado. Estas correcciones no tienen migración SQL de escritura.

## 7. Siguiente fase antes de certificar producción
**P0**: consolidar entradas/ajustes en un servicio único con claves idempotentes y rollback por transacción; impedir rutas antiguas de doble stock; pruebas SQL reales para OC8 y otros 20 casos, lotes y caducidades. **P1**: migración verificada y constraints de catálogo/documentos; importación Excel en modo propuesta y posterior ajuste autorizado (sin sobrescribir saldos); rol y sesiones. **P2**: integración WinCaja SQL Server/Pharmacy Lite, conciliación CXP/POS, indicadores comerciales y requisitos fiscales/CFDI con PAC. **P3**: observabilidad, auditoría de concurrencia, pruebas de restauración, respaldos fuera de servidor y capacitación de usuarios.

## 8. Limitaciones precisas
- Las pruebas automatizadas actuales son unitarias/simuladas y no garantizan que la instalación viva tenga todas las tablas/índices necesarios.
- No se ejecutaron importaciones reales, operaciones POS, timbrados CFDI ni manipulación de inventario de la empresa.
- El contenido de Git y credenciales excluidos del nuevo ZIP no se pueden recuperar desde él: mantenlos únicamente en almacenamiento privado.
- Si alguna clave de correo incluida en el ZIP original fue compartida con terceros, rota la contraseña/token antes de desplegar.

**Conclusión:** el proyecto es recuperable como ERP, pero **NO está acreditado todavía para operación integral con inventario confiable y conexiones externas**. Esta entrega resuelve debilidades confirmadas y proporciona la línea base para estabilización por etapas.
