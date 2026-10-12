<?php
/**
 * Configuración de ejemplo. NO lleva claves: el repositorio es público.
 *
 * En el servidor se copia como `config.local.php` en la RAÍZ del despliegue
 * (un nivel arriba de public/, fuera de la web) y se llenan los datos reales.
 * `config.local.php` está en .gitignore.
 */
return [
    // Identidad visual (la misma del Portal Tributario).
    'municipio' => [
        'ciudad'       => 'Paipa',
        'departamento' => 'Boyacá',
        'secretaria'   => 'Secretaría de Hacienda',
        'dependencia'  => 'Dirección de Impuestos, Rentas y Jurisdicción Coactiva',
        'telefono'     => '318 530 9285',
        'correo'       => 'impuestos@paipa-boyaca.gov.co',
        'direccion'    => 'Carrera 22 # 25-14, primer piso',
        'color'        => '#1fa49d',
        'colorOscuro'  => '#17756f',
        'portal'       => 'https://sistema.erpsoftsas.com/predial/dist/dashboard.php',
    ],

    // Base de datos del PREDIAL del municipio (la misma del sistema Laravel).
    'db' => [
        'servidor' => 'localhost',
        'base'     => 'erpsofts_paipa',
        'usuario'  => '',
        'clave'    => '',
    ],

    // Puntos de pago que se imprimen en la factura (los mismos de la
    // liquidación oficial de Paipa, facturaPDF_pai).
    'puntos_pago' => [
        'AGRARIO'      => '1511-007245-4',
        'BANCOLOMBIA'  => '038-851044-27',
        'BBVA'         => '001380660100000027',
        'BOGOTÁ'       => '676001340',
        'CONFIAR COOP' => '450006242',
        'DAVIVIENDA'   => '41600000803-5',
        'POPULAR'      => '261-72007-2',
    ],

    // usuarios.id (tabla del sistema de predial) con que se registra el
    // recálculo que hace SP_CALCULO_PREDIAL desde el portal.
    'usuario_sistema' => 1,

    // Zona horaria de las fechas que ve el contribuyente.
    'zona_horaria' => 'America/Bogota',

    // true solo en el equipo de desarrollo: muestra los errores en pantalla.
    'depurar' => false,
];
