<?php

/* ============================================================
   ENTRADA DO SITE - vai para o login ou para o disco
   ============================================================ */

require_once __DIR__ . '/usuarios.php';

iniciarSessaoUsuarios();

if (usuarioLogado()) {
    header('Location: app.php');
} else {
    header('Location: login.php');
}

exit;