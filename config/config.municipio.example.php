<?php
/**
 * Ejemplo de `config.local.php` para un municipio que NO es Paipa (Guateque, Sutatenza,
 * La Capilla, Santa Rosa de Viterbo). Se copia como `config.local.php` en la RAÍZ del
 * despliegue (un nivel arriba de public/) y se ajusta. NO lleva claves: el repositorio
 * es público y `config.local.php` está en .gitignore.
 */
$c = require __DIR__ . '/config/perfiles/guateque.php';   // guateque | sutatenza | lacapilla | santarosa

// Conexión: la misma del PSE anterior, leída de su appsettings.json sin copiar la clave
// (carpeta de la API vieja del municipio en el servidor).
$c['db_appsettings'] = dirname(__DIR__) . '/serviciospseguateque.erpsoftsas.com/appsettings.json';

// Datos de contacto y cuentas, cuando la Alcaldía los entregue:
// $c['municipio']['telefono'] = '';
// $c['municipio']['correo']   = '';
// $c['municipio']['horario']  = '';
// $c['puntos_pago'] = ['BANCO' => 'número de cuenta'];

// Pago en línea con el mismo convenio del PSE anterior (1cero1pay): usuario, clave y entidad
// se leen de conf_medios_pagos de la base. "verificar.php pago" dice qué encontró.
// $c['unocerouno'] = ['url' => 'https://www.1cero1pay.com/ApiPayment/api', 'activo' => true, 'proveedor' => 6];

return $c;
