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
        'horario'      => '', // p. ej. 'Lunes a viernes, ...' (pedirlo a la Alcaldía)
        'enlaces'      => [
            'Estatuto de Rentas (Acuerdo 019 de 2022)' => 'https://www.paipa-boyaca.gov.co/Transparencia/Normatividad/Acuerdo%20019%20de%202022.pdf',
            'Régimen sancionatorio (Acuerdo 018 de 2024)' => 'https://www.paipa-boyaca.gov.co/Transparencia/Normatividad/Acuerdo%20018%20de%202024.pdf',
        ],
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

    // Pago en línea con AvalPay (PlacetoPay), el mismo comercio de ICA. Las
    // credenciales van SOLO en config.local.php del servidor. Sin url, login y
    // secret el botón "Pagar en línea" no aparece. En AvalPay se registra como
    // URL de notificación https://<dominio>/avalpay-aviso.php, y en Plesk una
    // tarea cada 15 minutos: herramientas/verificar-pagos.php.
    // 'avalpay' => [
    //     'url'             => 'https://checkout.avalpaycenter.com/api',
    //     'login'           => '',
    //     'secret'          => '',
    //     'activo'          => true,
    //     'usuarios_prueba' => [],   // documentos; vacío = todos ven el botón
    //     'proveedor'       => 5,    // generacion_pagos.proveedor de este portal
    //     'id_banco'        => 0,    // bancos.id del PSE; 0 = el de asobancaria 999
    //     'politica_datos'  => '',   // URL de la política de tratamiento de datos
    // ],

    // MISMA CUENTA QUE ICA (lo pidió el cliente): ruta del config.municipio.php
    // del módulo de ICA en el servidor. Las credenciales se leen de ahí (de la
    // tabla conf_parametros de ICA o de sus constantes PLACETOPAY_*), sin
    // copiarlas. Lo que se ponga en 'avalpay' arriba tiene prioridad; 'activo'
    // y 'usuarios_prueba' siguen saliendo de 'avalpay'. Con la cuenta de ICA los
    // avisos de AvalPay van a la URL de ICA: aquí el pago se confirma al volver
    // del banco y con la tarea herramientas/verificar-pagos.php.
    // 'avalpay_ica' => '',

    // Paz y salvo (formato del sistema oficial de Paipa, pazPDF_pai).
    'paz_salvo' => [
        'dependencia'    => 'Tesorería Municipal',
        'cargo'          => 'SECRETARIO(A) DE HACIENDA MUNICIPAL',
        'firma'          => '',   // ruta de la firma (JPEG, fuera de public/ y del repositorio)
        'elaboro'        => 'Portal de pago en línea',
        'correo'         => 'tesoreria@paipa-boyaca.gov.co',
        'direccion'      => 'Carrera 22 # 25 - 14',
        'valor'          => 0,    // costo del certificado; 0 = no se imprime
        'nota'           => 'Actualmente el municipio de Paipa no tiene adoptada la contribución de valorización para predios ubicados dentro de su jurisdicción.',
        'url_validacion' => '',   // vacío = validar.php de este portal (+ token)
    ],

    // Zona horaria de las fechas que ve el contribuyente.
    'zona_horaria' => 'America/Bogota',

    // true solo en el equipo de desarrollo: muestra los errores en pantalla.
    'depurar' => false,
];
