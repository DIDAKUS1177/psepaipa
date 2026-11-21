<?php
/**
 * Perfil de Sutatenza para el portal de pago en línea del predial. NO lleva claves (el
 * repositorio es público). En el servidor, `config.local.php` (en la raíz del despliegue,
 * fuera de public/) lo carga y le agrega la conexión; ver config/config.municipio.example.php.
 *
 * Solo van datos confirmados: teléfono, correo, horario, enlaces, cuentas de los bancos y
 * fundamento legal de la factura quedan vacíos hasta que la Alcaldía los entregue (lo que
 * está vacío no se imprime). La dirección, el NIT y el EAN salen de la tabla `parametros`
 * de la base de predial del municipio.
 */
return [
    'municipio' => [
        'ciudad'       => 'Sutatenza',
        'departamento' => 'Boyacá',
        'secretaria'   => 'Secretaría de Hacienda',
        'dependencia'  => '',
        'telefono'     => '',
        'correo'       => '',
        'direccion'    => '',   // vacío = la de `parametros`
        'color'        => '#1fa49d',
        'colorOscuro'  => '#17756f',
        'portal'       => '',   // dirección del Portal Tributario del municipio, si tiene
        'horario'      => '',
        'enlaces'      => [],
        'imagenes'     => [
            'logo'       => '',   // imagen del encabezado que ya trae el nombre escrito (no hay)
            'escudo'     => 'assets/img/sutatenza/escudo.png',
            'escudo_pdf' => 'assets/img/sutatenza/escudo-pdf.png',
            'fondo'      => '',   // foto de la entrada (no hay: queda el color)
        ],
    ],

    // La conexión la pone config.local.php (db_appsettings o usuario y clave).
    'db' => ['servidor' => 'localhost', 'base' => '', 'usuario' => '', 'clave' => ''],

    // Cuentas para pagar en ventanilla que se imprimen en la factura: 'BANCO' => 'número'.
    'puntos_pago' => [],

    'usuario_sistema' => 1,

    // Topes de uso: una carpeta por municipio (varios portales comparten servidor).
    'limites_carpeta' => 'psesutatenza-limites',

    // Factura en el formato general del portal (la hoja A4 de Paipa es solo de Paipa).
    'factura' => [
        'formato'    => 'general',
        'fundamento' => '',   // p. ej. 'según el Acuerdo 000 de 2020' (lo da la Alcaldía)
    ],

    // Paz y salvo: apagado hasta acordar con la Alcaldía el formato, quién firma y la firma.
    'paz_salvo' => [
        'activo'         => false,
        'dependencia'    => 'Tesorería Municipal',
        'cargo'          => 'SECRETARIO(A) DE HACIENDA MUNICIPAL',
        'firma'          => '',
        'elaboro'        => 'Portal de pago en línea',
        'correo'         => '',
        'direccion'      => '',
        'valor'          => 0,
        'nota'           => '',
        'url_validacion' => '',
    ],

    'zona_horaria' => 'America/Bogota',
    'depurar'      => false,
];
