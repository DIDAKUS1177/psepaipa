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
  factura.php  PDF de la liquidación oficial (hoja A4 de la Alcaldía) con código de barras GS1-128
  estado-cuenta.php  PDF informativo de las vigencias elegidas (no guarda nada)
  paz-y-salvo.php    PDF del certificado de paz y salvo (consecutivo oficial + QR)
  validar.php  verificación pública del QR del paz y salvo
src/           Db, Cuentas, Predios, Factura, FacturaPdf, PdfPredial, Vista, AvalPay, PagoLinea, arranque
herramientas/  verificar.php (comprobación en el servidor), verificar-pagos.php (tarea),
               probar-sp.php <id> (compara el cálculo oficial sin guardar)
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
- **Todo se calcula con la fecha de hoy** (pedido del cliente, 2026-10-13). Probado contra
  el procedimiento: con `@ANO_FIN` (rango de varios años) recalcula todas las vigencias
  pendientes anteriores pero NO la del año en curso, que se quedaba con la cifra del último
  día en que alguien la calculó (los "$424" que antes creímos un descuento por rango eran eso;
  hoy vale $481). Con `@ANO = año en curso, @ANO_FIN = 0` (la "vista previa" del oficial) sí.
  `Predios::calcularHoy` corre primero esa y luego el rango; la usan la cotización y la
  factura. Al abrir el predio se recalculan TODOS los bloques (`cotizar(..., true)`) y al
  cambiar la selección se vuelve a cotizar (`cotizar.php`). En el listado de predios NO se
  muestra el valor (sale "$ ******"): el único que vale es el de hoy, dentro del predio.
  Para comparar en el servidor: `herramientas/probar-sp.php <id>` (no guarda nada).
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
pestañas Estado de cuenta / Recibos emitidos / Histórico de pagos. En celular la tabla
de vigencias se vuelve tarjetas (sin deslizar a la derecha). Diego pidió NO poner
"paso a paso" ni preguntas frecuentes: solo lo que pidió el cliente.

## Cuentas

Las de `usuarios_pse`, las mismas del PSE anterior, para que nadie se reinscriba. La
contraseña es MD5 sin sal en hexadecimal MAYÚSCULA, igual que el sistema anterior: débil,
pero cambiarla dejaría sin entrar a quien siga usando el viejo mientras conviven.
Pendiente: migrar a `password_hash` cuando se retire el viejo. Bloqueo de 5 minutos tras
5 intentos fallidos (en la sesión). En pantalla y en el estado de cuenta el nombre y el
documento del dueño van ocultos; el paz y salvo los lleva completos, como el oficial
(lo pidió la Tesorería el 2026-10-14), y la factura también desde el formato de la Alcaldía
(2026-11, ver «Factura en el formato de la Alcaldía»).

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

## Cambios del cliente (2026-10-13)

- **Colores**: el mismo verde de ICA (`#1fa49d`, oscuro `#17756f`), sin el tono casi negro.
- **Paz y salvo** (`paz-y-salvo.php`, `src/PazYSalvo.php`): formato de `pazPDF_pai`, número
  del consecutivo oficial `anios.consecutivo_paz`, QR en `predios_validaciones_qr` (tabla del
  oficial: 5 consultas, válido al 31/12) verificable en `validar.php`. Solo sin deudas, con el
  año en curso pagado y sin pago en curso; el mismo usuario y predio el mismo día repite el
  certificado. Botón en «Valor a pagar» y en «Sin saldos pendientes».
- **Paz y salvo igual al oficial** (muestra de la Tesorería, 2026-10-14): A4, encabezado con
  escudo y QR, "LA TESORERÍA MUNICIPAL DE PAIPA CERTIFICA", predio, propietarios completos
  (hasta 5), "Válido hasta / Valor 0.00 / Elaboró" y la **firma** del Secretario de Hacienda.
  La firma es un JPEG que NO va en el repositorio (público): vive en el servidor fuera de
  `public/` y de las versiones, y `paz_salvo.firma` apunta a ella. `PazYSalvo::pdf()` dibuja;
  `pruebas/datos/probar_paz_pdf.php` lo prueba con datos inventados (1, 5 y 9 propietarios).
  `verificar.php pago` dice en pocas líneas el ambiente de AvalPay y si la firma está.
- La pestaña «Estado financiero» se llama **«Estado de cuenta»**.
- **PSE con la cuenta de ICA**: `avalpay_ica` = ruta del `config.municipio.php` de ICA (la del
  servidor está solo en su `config.local.php`); se lee
  sin ejecutarlo (constantes DB_PROD_* y PLACETOPAY_*, y `conf_parametros` PASARELA_*).
  Con esa cuenta los avisos de AvalPay llegan a la URL de ICA: aquí confirman el retorno y la
  tarea. Los puntos de pago de la factura ya coinciden con la liquidación oficial de Paipa.

## Revisión antes de subir (2026-10-13, tres revisores)

- **Política de seguridad**: `form-action` permite `checkout(.test).avalpaycenter.com`; sin eso
  Chrome bloqueaba la redirección a AvalPay en IIS (en local, Apache no lee `web.config`).
- **Fechas a `datetime`** siempre con "T" (`fecha_pago` de `predios_pagos` con login en español:
  del 13 al 31 fallaba y del 1 al 12 invertía día y mes). `probar_fechas.php` prueba en español.
- **Pago aprobado que no se pudo registrar**: queda `APPROVED` con `Activo = 1`, bloquea el
  predio sin límite de días y la tarea lo reintenta. `REVISAR` reciente: aviso en el predio y
  sin pago en línea. En el ambiente de pruebas de AvalPay un aprobado queda `PRUEBAS` (no se
  registra). `crearIntento` toma el candado de la factura y comprueba que siga igual.
- **Acuerdos de pago**: el oficial no marca `predios_pagos.acuerdo`; el acuerdo activo está en
  `predios_acuerdos_pago` (`Predios::EN_ACUERDO`). Esas vigencias no se pagan ni se facturan aquí.
- **Paz y salvo**: se decide con las marcas (no con `total_calculo`, que puede estar en 0 sin
  calcular); topes por usuario, IP y hora; salta números ya usados; reutiliza solo para el
  mismo usuario; `validar.php` cuenta una consulta por IP al día.
- Menú en celular con nombres cortos hasta 640 px; contraste del pie.

## Contraseña: olvidé y cambiar (2026-10-27)

Igual que en Industria y Comercio (Diego: "que se mantenga igual"). `Cuentas::recuperar`: la persona escribe su
documento y le llega al correo de su cuenta una contraseña temporal de 8 caracteres que REEMPLAZA la anterior
(`public/index.php`, bloque «¿Olvidaste tu contraseña?»; sus campos pertenecen a un segundo formulario con el
atributo `form=`). `Cuentas::cambiarClave` + `public/cambiar-clave.php`: con la sesión abierta, pide la actual.
- **Correo**: `src/Correo.php`, cliente SMTP mínimo (no se trajo PHPMailer: el de ICA es 6.0.7). Los datos salen de
  `config.local.php 'correo'` o, por defecto, del `appsettings.json` de la API anterior (FromEmail, FromPass, Host,
  Port) por la ruta de `db_appsettings` (`ConexionNet::correo`): no hay claves en el repositorio.
  `verificar.php pago` abre la conexión y se autentica sin mandar nada.
- **Lo agregado a lo de ICA**: si el correo no sale, se devuelve la anterior (condicional: solo si sigue puesta la
  temporal; también si el proceso se corta); si el mensaje salió completo y el servidor no contestó, NO se devuelve;
  una solicitud a la vez por documento y no dos en 15 s (doble clic: la repetida no cambia nada); cambia solo la cuenta
  que recibe el correo; topes de 3 por hora por documento y por correo y 10 por IP (`Limites::reservar`, atómico;
  sin carpeta de límites escribible, "no disponible"); tras enviarla se levanta el bloqueo de intentos del documento.
- **Aceptado a propósito (igual que ICA)**: cualquiera con un documento puede cambiar esa contraseña (3 veces por hora),
  "El documento no se encuentra registrado" revela qué documentos existen, y las sesiones ya abiertas siguen valiendo
  hasta los 30 minutos de inactividad. MD5 sin sal sigue igual.
- Pruebas (scratchpad): `recuperar_clave.py` (27) y `recuperar_clave2.py` (12) con un SMTP de mentira en el puerto 2525/2526.

## Factura en el formato de la Alcaldía (2026-11)

La Alcaldía pidió su hoja `Factura_Predial_Paipa_A4` (A4, una página) y es **la misma que imprime el
back-office de predial** (vista `facturaPDF_pai.blade.php` del Laravel): los dos PDF deben verse igual y
decir lo mismo. Si se cambia uno, se cambia el otro. `src/FacturaPdf.php` la dibuja con TCPDF a partir de datos
que le pasan (`FacturaPdf::dibujar`), sin tocar la base; `public/factura.php` los arma. El estado de cuenta y
el paz y salvo no cambian.

- **Un solo total y un solo «pague hasta»** (respuesta de Juan, de la Alcaldía): los del primer descuento
  que aún vale hoy; si ninguno, el valor de hoy con la fecha de hoy (si pasa la fecha se imprime otra
  factura). `Factura::cuponHoja` elige sobre `Factura::cupones` (ahora marca `vigente`); en el back-office es
  `FacturaPaipa::cuponVigente`. El resto de la pantalla y el pago en línea siguen usando `cupones()[0]`.
- **Tabla**: Vigencia, Avalúo, Tarifa ‰, Impuesto, Intereses, Sobretasa, Int. sobretasa, **Alumbrado**
  (`valor_concepto18`), Descuentos (c13 + c15) y Total; «Otros» solo aparece si alguna fila tiene un resto
  (bomberil, saldos). Si el descuento que aplica es el de la 2.ª o 3.ª fecha, la última vigencia lo
  absorbe en Descuentos para que tabla y total coincidan. Con más de 5 vigencias, las más viejas van sumadas
  en una fila «Antes de AAAA» (como el oficial).
- **Propietario**: solo el primero, con nombre y documento COMPLETOS (lo confirmó Juan). Es la única hoja donde
  salen completos además del paz y salvo; en pantalla y en el estado de cuenta siguen tapados. Ojo: cualquiera
  que se inscriba y busque un predio por su referencia catastral puede imprimir esa hoja y ver nombre y
  documento del dueño.
- **Textos**: competencia, notificación, recurso y mérito ejecutivo son el texto que validó el abogado de la
  Alcaldía: no se cambian sin su visto bueno (constantes en `FacturaPdf`). La tasa de mora sale de
  `tasas_interes` (`tasa_mensual` × 12 = % E.A.; `Factura::tasaMoraAnual`); sin dato dice «vigente». El valor
  se escribe en letras (`FacturaPdf::totalEnLetras`). «Publicada» = fecha de impresión.
- **Firma**: la de `paz_salvo.firma` (JPEG fuera del repositorio), recortada a la parte de arriba como en la
  hoja; firmante y cargo en `config 'factura'` (William Niño Echeverría, Secretario de Hacienda).
- **Código de barras**: igual que antes (GS1-128, `parametros.ean`). La hoja trae el código 7709998086715; falta
  confirmar con Javier que es el `ean` de Paipa. Las cuentas de los bancos ya coinciden con las de `puntos_pago`.
- **Pruebas**: `pruebas/datos/probar_factura_pdf.php [carpeta]` (gitignored) dibuja 8 casos con datos inventados
  (5 y 10 vigencias, una vigencia con otro descuento, «Otros», sin EAN, textos largos, sin dueño) y comprueba una sola
  hoja; corre con cualquier PHP 8 (no necesita la base). Las cifras reales se prueban con `probar_factura.php`.

## Pendiente

- Recuperar la contraseña por correo: hecho el 2026-10-27 (falta desplegar y probar el envío real con el correo de la API anterior).
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
  que el PSE viejo), así que nombre y documento del propietario van ocultos (salvo en el paz
  y salvo, que la Tesorería pidió igual al oficial, y en la factura de la Alcaldía). Un
  predio ajeno solo se abre después de buscarlo (`Predios::permitido`). Pendiente si el
  cliente lo quiere: verificar la propiedad (p. ej. con el número de la última factura).
- **Huecos**: predios con años no seguidos se pagan por bloques (`data-bloque`); al abrir se
  cotiza el primer bloque (`Predios::rangoInicial`).
- **Fechas** a SQL Server en ISO (`Y-m-d\TH:i:s`): con un login en español, `Y-m-d` se lee
  día-mes. Errores de la base nunca llegan al usuario (`AvisoUsuario`).
- Resuelto con el formato nuevo (2026-11): la hoja imprime un solo cupón, el del primer descuento
  vigente (antes el sistema oficial imprimía solo los últimos y el portal todos los vigentes).
- Pruebas locales: `pruebas/datos/probar_factura.php` (12), `probar_cuentas.php` (16),
  y en el scratchpad `funcional.py` (18) y `otros.py` (14).

## Otros municipios: Guateque, Sutatenza, La Capilla y Santa Rosa de Viterbo (2026-11-20, LOCAL, sin commit)

Pedido urgente de Diego: llevar el diseño nuevo a `pseguateque`, `psesutatenza`, `pselacapilla` y `psesantarosa`
(`.erpsoftsas.com`), que siguen con el Angular viejo («PSE::WEB»). El mismo código sirve a todos; lo propio de cada
uno está en su perfil.

- **Perfiles** en `config/perfiles/<slug>.php` (guateque, sutatenza, lacapilla, santarosa), sin claves. El
  `config.local.php` del servidor hace `require` del perfil y le agrega la conexión: ver
  `config/config.municipio.example.php`. Paipa NO usa perfil: sin las claves nuevas todo vale como antes.
- **Imágenes** (`imagenMunicipio()` / `archivoImagenMunicipio()` en `arranque.php`): `municipio.imagenes.logo|escudo|
  escudo_pdf|fondo`. Sin `logo` (imagen con el nombre escrito) el encabezado muestra escudo + «Alcaldía de X»; sin
  `fondo` la entrada queda con el color. Escudos en `public/assets/img/<slug>/` (salieron del back-office de predial;
  el de Sutatenza es de 181 px: pedir uno mejor).
- **Dirección**: si el perfil no la trae, sale de `parametros.direccion` (`parametroPredial()`); el NIT y el EAN ya
  salían de `parametros`. Teléfono, correo, horario, enlaces y Portal Tributario vacíos = no se imprimen.
- **Factura**: `factura.formato = 'general'` → `src/FacturaGeneral.php` (la hoja que había antes de la A4 de Paipa:
  un cupón por fecha vigente). El fundamento legal va en `factura.fundamento`; vacío no cita ninguna norma. Las
  cuentas de ventanilla van en `puntos_pago` (vacío = sin ellas). La A4 (`FacturaPdf`) es solo de Paipa.
- **Paz y salvo**: `paz_salvo.activo = false` en los cuatro perfiles (cada Alcaldía tiene formato y firma propios);
  el predio dice que se solicita en la Tesorería. Para prenderlo: formato, firmante y firma de esa Alcaldía.
- **Topes de uso**: `limites_carpeta` por municipio (comparten servidor y carpeta de sesiones).
- **Pago en línea**: Guateque trae `conf_medios_pagos` con su convenio de 1cero1pay (el del PSE viejo), así que el
  botón sale con `unocerouno` + `desde_bd`; en los otros tres hay que mirarlo con `verificar.php pago` en el servidor.
- **Probar en local como otro municipio**: escribir el slug en `var/perfil.txt` (lo lee `config/config.local.php`,
  que no va al repositorio); sin el archivo es Paipa. `verificar.php` dice municipio, formato de factura, escudo y
  dirección, y sigue fallando a propósito si la base no es la del municipio configurado.
- **Probado**: Paipa sin cambios (factura 12/12, cuentas 16/16, hoja A4 19/19, paz y salvo 4/4); Guateque con su base
  real en local (entrada, mis predios, predio, factura general de 1 y 6 vigencias, verificar 34/34). Sutatenza,
  La Capilla y Santa Rosa: solo la entrada, porque no hay copia de sus bases.
- **Falta**: datos de cada Alcaldía (contacto, cuentas, fundamento legal, foto), probar contra las bases de los otros
  tres en el servidor (`verificar.php`), revisión completa, commit y despliegue por municipio (carpeta nueva +
  `config.local.php` + tarea `verificar-pagos.php` cada 15 min + raíz del documento; volver = raíz anterior).

### Convenio de AvalPay leído del portal anterior (2026-11-21)

`'avalpay_pse_anterior'` = ruta del `assets/config.json` del portal viejo (Angular) del municipio. Si en `'avalpay'`
faltan login o llave, `AvalPay::dePseAnterior` los toma de `configuraciones[0].Plataformas[Nombre ~ Placetopay]
.configuracion.login/secretKey` (solo si esa plataforma estaba activa), igual que la conexión sale del appsettings:
nadie copia la llave. La URL NO se toma de ahí: va en `avalpay.url`. `verificar.php pago` dice la fuente
(`AvalPay::fuente()`) y le pregunta a AvalPay por una sesión inexistente (`probarCredenciales`: no crea ni cobra nada)
para saber si acepta las credenciales; en un municipio con el paz y salvo apagado ya no exige la firma.
Prueba: `pruebas/datos/probar_pse_anterior.php` (8).

### Wompi, tercer botón de pago (2026-11-21, LOCAL, sin commit)

`src/Wompi.php` + `PagoLinea` (medios `'avalpay' | '101' | 'wompi'`, tabla `PagoLinea::MEDIOS`; el campo
`pasarela` de `pagar.php` lleva ese valor). Sin `'wompi'` ni `'wompi_pse_anterior'` en la configuración no
existe: ni botón, ni proveedor 7 en las consultas (Paipa queda igual).

- **Cobro**: Web Checkout por redirección a `https://checkout.wompi.co/p/` con `public-key`, `currency=COP`,
  `amount-in-cents`, `reference`, `redirect-url` (el enlace firmado de `retorno.php`), `expiration-time`
  (30 min) y `signature:integrity` = sha256(referencia + centavos + COP + vencimiento + llave de integridad).
  No se crea sesión: el intento se anota con la referencia ANTES de salir.
- **Referencia** única por intento: `PRED<factura>-<8 hex>`, en `generacion_pagos.IDTransaccion`; el id de la
  transacción de Wompi queda en `CUS` (`PagoLinea::porWompi` encuentra el intento por cualquiera de los dos).
- **Confirmación** (igual que en ICA, `class.pagoWompi.php`): SIEMPRE por `GET {api}/transactions?reference=`
  con la llave privada, que solo trae transacciones de este comercio. El `id` que Wompi agrega al retorno NO
  se aplica (podría ser de otro comercio con la misma referencia y valor): solo elige cuál mostrar. Un 404,
  401 o 500 es "no se pudo consultar" (el intento sigue abierto), nunca "no hay pago".
- **Reglas**: las de siempre (`PagoLinea::registrar`), más una: aprobado por un valor que no es EXACTAMENTE el
  del intento, o en otra moneda → `REVISAR` sin registrar. De varias transacciones manda la aprobada, luego
  la pendiente. `DECLINED`/`ERROR`/`VOIDED` cierran el intento; como en la página de Wompi se puede
  reintentar con la misma referencia, la tarea vuelve a mirar 26 h los cerrados sin pago
  (`PagoLinea::reintentosWompi`) y si aparece un aprobado lo registra (o queda `DOBLE`).
- **Ambiente** por el prefijo de las llaves (`pub_test_`/`pub_prod_`...; las tres del mismo, si no, no hay
  botón). API `sandbox.wompi.co/v1` o `production.wompi.co/v1`; `wompi.api` solo vale con llaves de pruebas
  (simulador). Con llaves de pruebas el botón lo ven solo los `usuarios_prueba` y un aprobado queda `PRUEBAS`.
- **Llaves**: `'wompi'` en `config.local.php`; lo que falte, de `'wompi_pse_anterior'` (config.json del
  portal viejo, plataforma "WOMPI" activa: `publickey`/`Llavepublica`, `Llaveprivada`, `integritykey`).
  `verificar.php pago` tiene una línea de Wompi (ambiente, fuente, para quién, y si Wompi acepta la llave
  privada buscando una referencia inexistente); nunca imprime llaves.
- `web.config`: `form-action` permite `https://checkout.wompi.co` (misma razón que AvalPay).
- **No hay URL de eventos** de Wompi en este portal: confirman el retorno y la tarea cada 15 minutos.
- **Prueba**: `pruebas/datos/probar_wompi.php` (128) con `stub_wompi.php`, que la misma prueba arranca en
  127.0.0.1:9191; agrega `'wompi'` a `config.local.php` mientras corre y lo quita; deja la base igual.
  `probar_dos_pasarelas.php` (18) repite AvalPay y 1cero1pay con `stub_avalpay.php` dentro del contenedor.
- **Sin verificar contra el Wompi real** (no hay llaves de pruebas): que la búsqueda por referencia responda
  así con la llave privada, que Wompi agregue `&id=` a un retorno que ya trae parámetros, y que tras el
  vencimiento no se pueda pagar. Antes de abrirlo a todos: un pago de punta a punta en el sandbox.
