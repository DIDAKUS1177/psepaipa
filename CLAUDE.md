# psepaipa: pago en línea del Impuesto Predial de Paipa

Reemplaza la aplicación anterior de psepaipa.erpsoftsas.com (Angular 15 + API .NET
`serviciospsepaipa`, de la que no hay código fuente). PHP 8 + SQL Server, sin
framework, mismo estilo que el módulo de ICA. **El repositorio es PÚBLICO: nada de
claves aquí.** La configuración real va en `config.local.php` (gitignored), en la raíz
del despliegue, fuera de `public/`.

## Estructura

```
public/        raíz web (en Plesk el docroot debe apuntar aquí)
  pagar.php    resumen del pago en línea y salida a AvalPay
  retorno.php  resultado del pago (vuelta de AvalPay)
  avalpay-aviso.php  URL de notificación de AvalPay
  index.php    iniciar sesión / inscribirse
  inicio.php   mis predios + buscar otro predio por referencia catastral
  predio.php   información, estado financiero por vigencia, valor a pagar
  cotizar.php  JSON: valor exacto de un rango de vigencias (sin guardar)
  factura.php  PDF de la liquidación oficial con código de barras GS1-128
  estado-cuenta.php  PDF informativo de las vigencias elegidas (no guarda nada)
src/           Db, Cuentas, Predios, Factura, PdfPredial, Vista, AvalPay, PagoLinea, arranque
herramientas/  verificar.php (comprobación en el servidor), verificar-pagos.php (tarea)
lib/tcpdf      TCPDF recortado (las fuentes básicas)
config/        config.example.php (sin claves)
pruebas/datos  (gitignored) scripts y cuenta de prueba local
```

## La base de datos es la del sistema oficial de predial

Es la del Laravel de predial del municipio (en Paipa, la que usa `serviciospsepaipa`).
Este portal **no recalcula fórmulas**: llama al procedimiento oficial
`SP_CALCULO_PREDIAL @USUARIO_ID, @ANO, @ANO_FIN, @PREDIO_ID, @FECHA_PAGO`, que escribe
cada vigencia en `predios_pagos`, y lee lo que deja. Los procedimientos vienen cifrados
(`WITH ENCRYPTION`): se llaman, no se leen.

- **Ver un predio no escribe nada**: `Predios::cotizar` corre el procedimiento dentro de
  una transacción, lee y hace ROLLBACK. Probado: el procedimiento acepta ir anidado.
- **El valor depende del rango elegido**: el descuento cambia según los años que se
  pagan juntos (en la prueba, 2026 sola valía $481 y dentro de 2017–2026, $424). Por eso
  la pantalla vuelve a cotizar cada vez que cambia la selección (`cotizar.php`).
- **La factura sí guarda**: corre el procedimiento, toma el consecutivo de
  `anios.numero_factura_actual` del año en curso (número = AAAA + 5 cifras, regla del
  `PrediosController` oficial) y escribe `factura_pago` y `fecha_emision` en cada
  vigencia. Diferencias a propósito con el oficial: el consecutivo se toma con candado
  (`sp_getapplock`), y si una vigencia del rango ya estaba en OTRA factura se numera de
  nuevo todo el rango (el oficial la excluía en silencio y la factura salía por menos años).
- **Cupones**: suma de `total_calculo` hasta `primer_fecha`; con una sola vigencia se
  agregan los de segunda y tercera fecha si siguen vigentes (`total_dos` / `total_tres`).
- **Banderas** de `predios_pagos`: -1 es verdadero (pagado, anulada, prescrito,
  exencion, acuerdo). Se debe = `pagado = 0`, no anulada, no prescrita. Las vigencias en
  acuerdo de pago se muestran pero no se pagan aquí.
- **Columnas**: c1 impuesto, c2 intereses, c3 sobretasa ambiental, c4 intereses de la
  sobretasa, c13 y c15 descuentos; "Otros" es lo que queda del total oficial, para que
  cada fila sume exactamente `total_calculo`.
- **Código de barras**: GS1-128 `FNC1 415 EAN 8020 factura(24) FNC1 3900 valor(14) FNC1 96
  AAAAMMDD`, con `write1DBarcode` (vectorial). FNC1 = chr(241). Leído con zxing: sale
  `]C1` (GS1-128) con los cuatro campos. El EAN sale de `parametros.ean`.

## Pantallas (estructura del portal de Duitama, identidad de Paipa)

Encabezado con "Registrarse / Iniciar sesión" (sin la barra azul GOV.CO: a Diego no le
gustó, 2026-10-12), menú horizontal y franja
del trámite con "Regresar". En el predio: "Información del predio" con datos clave,
"Valor a pagar" (fecha límite, total, "¿Qué estoy pagando?", menús Imprimir y Pagar) y
pestañas Estado financiero / Recibos emitidos / Histórico de pagos. En celular la tabla
de vigencias se vuelve tarjetas (sin deslizar a la derecha). Diego pidió NO poner
"paso a paso" ni preguntas frecuentes: solo lo que pidió el cliente.

## Cuentas

Las de `usuarios_pse`, las mismas del PSE anterior, para que nadie se reinscriba. La
contraseña es MD5 sin sal en hexadecimal MAYÚSCULA, igual que el sistema anterior: débil,
pero cambiarla dejaría sin entrar a quien siga usando el viejo mientras conviven.
Pendiente: migrar a `password_hash` cuando se retire el viejo. Bloqueo de 5 minutos tras
5 intentos fallidos (en la sesión). A quien no es propietario se le ocultan el nombre y
el documento del dueño (pantalla y PDF), pero puede pagar.

## Desarrollo local

Contenedor `psepaipa_local` (imagen `erpsoftsas-web` de ICA, PHP 8.1 + sqlsrv) en
http://localhost:8082, con el docroot cambiado a `public/`. Base: copia de la de predial
de **Guateque** restaurada como `predial_guateque` en el SQL Server de Docker (misma
estructura; Paipa no tenía copia local). `config/config.local.php` apunta ahí.

## Pago en línea (AvalPay, 2026-10-13)

Misma integración de ICA (`src/AvalPay.php`, de `business/class.placetopay.php`): sesión con
`paymentMethod = pse`, consulta autenticada, firma del aviso SHA-1 o `sha256:`.

- **Flujo**: predio → Pagar → «Pagar en línea (PSE)» (`pagar.php`): resumen con el valor de hoy y
  aceptación del tratamiento de datos (certificación) → genera/reutiliza la factura, anota el
  intento y manda a AvalPay. La referencia en AvalPay es el número de factura. Vuelve a
  `retorno.php?i=&t=` (enlace firmado, no exige la sesión del portal).
- **Intentos**: `generacion_pagos` (la tabla del PSE viejo), `proveedor = 5` (config
  `avalpay.proveedor`), `referencia = id del predio`, `Impuesto = "Predial AAAA[-AAAA]"`.
  Mientras haya uno abierto con sesión, el predio no abre otro pago ni genera facturas
  (`PagoLinea::enTramite`; `refrescar()` consulta los vencidos).
- **Registro** (`PagoLinea::registrar`, como `UploadController`): INSERT `pagos` (origen 'A',
  banco = asobancaria 999), `predios_pagos.pagado = -1` con `valor_pago = total_calculo`, y
  `predios.ultimo_anio_pago`. Con candado por factura e idempotente. Si la factura ya estaba en
  `pagos` → DOBLE; si cambiaron sus vigencias o entró menos de lo que vale → REVISAR (no
  registra; se concilia a mano).
- **Respaldo**: `avalpay-aviso.php` (URL de notificación en AvalPay) y la tarea
  `herramientas/verificar-pagos.php` cada 15 minutos.
- **Pruebas**: simulador `pruebas/datos/stub_avalpay.php` en el contenedor `avalpay_stub`
  (puerto 9090); `config/config.local.php` apunta a `host.docker.internal:9090/api`.
  `pago.py` del scratchpad: 21/21 (aprobado, rechazado, pendiente + tarea, aviso, valor menor).

## Pendiente

- AvalPay: hecho y probado contra un simulador local (ver «Pago en línea»); falta poner las
  credenciales en el `config.local.php` del servidor, registrar la URL de aviso y la tarea.
- Recuperar la contraseña por correo (hoy remite a la Dirección de Impuestos).
- Paz y salvo.
- Probar contra una copia de la base de Paipa antes de publicar (las cifras de Guateque
  traen intereses en 0).

## Producción (desde el 2026-10-12)

- **Publicado** en https://psepaipa.erpsoftsas.com. La raíz del dominio en Plesk es
  `psepaipa-cafc64f/public`; antes era `psepaipa.erpsoftsas.com` (el Angular viejo, que
  sigue intacto). Para volver atrás: Hosting > Raíz del documento > `psepaipa.erpsoftsas.com`.
- **Conexión**: `config.local.php` (en la carpeta de la versión, fuera de `public/`) usa
  `db_appsettings` = `dirname(__DIR__)/serviciospsepaipa.erpsoftsas.com/appsettings.json`
  (cadena "SQLDeveloper", base `erpsofts_paipa`). Nadie copió la clave.
- **Despliegue**: el "Pull ahora" del Git de Plesk (dominio psepaipa, a `\psepaipa-portal`)
  se trabó en 27a5a4a, igual que pasó con ICA. Lo que funcionó: Archivos > + > Importar
  archivo mediante URL `https://codeload.github.com/DIDAKUS1177/psepaipa/zip/<commit>`,
  renombrar a `.zip`, Archivo > Extraer (crea `psepaipa-<commit>/`), copiar
  `config.local.php` y cambiar la raíz del documento. Cambios de un archivo: importar
  `https://raw.githubusercontent.com/DIDAKUS1177/psepaipa/<commit>/<ruta>` en su carpeta.
- **Verificación**: Tareas programadas > Ejecutar un script PHP (8.3) >
  `<carpeta>/herramientas/verificar.php` > "Ejecutar ahora" (sin guardar la tarea). Plesk
  corta la salida larga; "se completó correctamente" = todas las comprobaciones en OK.
- **Límites de uso**: en IIS el proceso web NO puede escribir en la carpeta de la
  aplicación; `Limites` usa entonces la carpeta de sesiones de PHP. Comprobado en vivo:
  el sexto intento fallido de un documento queda bloqueado aunque cambie la sesión.

## Revisión antes de producción (2026-10-12, tres revisores)

- **Factura**: el cálculo oficial guardaba ANTES de validar (reescribía vigencias pagadas
  y creaba años). Ahora `Predios::validarRango` va primero y el cálculo corre dentro de la
  transacción de la numeración. Números: no se reutiliza uno de otro predio, pagado o ya en
  `pagos` (el recaudo por archivo busca solo por número); se saltan los usados; al
  renumerar se libera el viejo. Límites: 10 facturas nuevas por usuario y 4 por predio al
  día, y freno global de 300 por hora (consulta a la base).
- **Identidad**: la inscripción no prueba que el documento sea de quien se inscribe (igual
  que el PSE viejo), así que nombre y documento del propietario van ocultos SIEMPRE. Un
  predio ajeno solo se abre después de buscarlo (`Predios::permitido`). Pendiente si el
  cliente lo quiere: verificar la propiedad (p. ej. con el número de la última factura).
- **Huecos**: predios con años no seguidos se pagan por bloques (`data-bloque`); al abrir se
  cotiza el primer bloque (`Predios::rangoInicial`).
- **Fechas** a SQL Server en ISO (`Y-m-d\TH:i:s`): con un login en español, `Y-m-d` se lee
  día-mes. Errores de la base nunca llegan al usuario (`AvisoUsuario`).
- Pendiente de confirmar con Paipa: el sistema oficial imprime en Paipa solo el código de
  barras del tercer cupón cuando hay varios; el portal imprime todos los vigentes.
- Pruebas locales: `pruebas/datos/probar_factura.php` (12), `probar_cuentas.php` (16),
  y en el scratchpad `funcional.py` (18) y `otros.py` (14).
