<?php

declare(strict_types=1);
require __DIR__ . '/../src/arranque.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfValido($_POST['csrf'] ?? null)) {
    Cuentas::salir();
    aviso('Cerraste la sesión.', 'exito');
}
redirigir('./');
