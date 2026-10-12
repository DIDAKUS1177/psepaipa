# psepaipa: pago en línea del Impuesto Predial de Paipa

Reemplaza la aplicación anterior de psepaipa.erpsoftsas.com (Angular 15 + API .NET
`serviciospsepaipa`, de la que no hay código fuente). PHP 8 + SQL Server, sin
framework, mismo estilo que el módulo de ICA. **El repositorio es PÚBLICO: nada de
claves aquí.** La configuración real va en `config.local.php` (gitignored), en la raíz
del despliegue, fuera de `public/`.

## Estructura

```
public/        raíz web (en Plesk el docroot debe apuntar aquí)
  index.php    iniciar sesión / inscribirse
  inicio.php   mis predios + buscar otro predio por referencia catastral
  predio.php   información, estado financiero por vigencia, valor a pagar
  cotizar.php  JSON: valor exacto de un rango de vigencias (sin guardar)
  factura.php  PDF de la liquidación oficial con código de barras GS1-128
  estado-cuenta.php  PDF informativo de las vigencias elegidas (no guarda nada)
src/           Db, Cuentas, Predios, Factura, PdfPredial, Vista, arranque
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

Barra GOV.CO con accesibilidad (A−/A+ con `zoom` y alto contraste, guardados en
localStorage), encabezado con "Registrarse / Iniciar sesión", menú horizontal y franja
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

## Pendiente

- Pago en línea con AvalPay (como ICA): falta el sitio/credenciales de predial y decidir
  cómo se registra el pago en la base oficial (`pagos` + `predios_pagos.pagado = -1` +
  `predios.ultimo_anio_pago`, como el recaudo por archivo del sistema oficial).
- Recuperar la contraseña por correo (hoy remite a la Dirección de Impuestos).
- Paz y salvo.
- Probar contra una copia de la base de Paipa antes de publicar (las cifras de Guateque
  traen intereses en 0).
