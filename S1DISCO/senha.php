<?php

/* ============================================================
   TROCAR SENHA
   ============================================================ */

require_once __DIR__ . '/usuarios.php';

iniciarSessaoUsuarios();
cabecalhosSeguranca();
exigirUsuarioLogado();

$usuarioAtual = $_SESSION['usuario'];
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    conferirCsrf();

    $atual = isset($_POST['atual']) ? $_POST['atual'] : '';
    $nova = isset($_POST['nova']) ? $_POST['nova'] : '';
    $confirma = isset($_POST['confirma']) ? $_POST['confirma'] : '';

    if (!conferirSenha($usuarioAtual, $atual)) {
        $erro = 'Senha atual incorreta.';
    } elseif (strlen((string)$nova) < 4) {
        $erro = 'A nova senha deve ter ao menos 4 caracteres.';
    } elseif ($nova !== $confirma) {
        $erro = 'A confirmacao nao bate com a nova senha.';
    } else {
        atualizarSenha($usuarioAtual, $nova);
        registrarAuditoria('trocar_senha', $usuarioAtual, '');
        header('Location: app.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Trocar senha - S1 Disco</title>
<style>
body {
    margin: 0;
    background: #f5f6f7;
    font-family: Arial, Helvetica, sans-serif;
    color: #14181c;
}
.topo {
    background: #2b3038;
    color: #fff;
    padding: 10px 16px;
}
.topo a { color: #c8ced6; text-decoration: none; font-size: 14px; }
.caixa {
    max-width: 420px;
    margin: 26px auto;
    background: #fff;
    border: 1px solid #dfe2e5;
    border-radius: 12px;
    padding: 24px 26px;
}
h1 { font-size: 19px; margin: 0 0 4px; }
.sub { color: #8a929c; font-size: 13px; margin: 0 0 20px; }
label { display: block; font-size: 13px; color: #5f6771; margin: 12px 0 5px; }
input {
    width: 100%;
    padding: 9px 11px;
    border: 1px solid #c9ced3;
    border-radius: 8px;
    font-size: 15px;
    box-sizing: border-box;
}
button {
    margin-top: 18px;
    width: 100%;
    padding: 10px;
    border: 0;
    border-radius: 8px;
    background: #ff5c00;
    color: #fff;
    font-size: 15px;
    cursor: pointer;
}
button:hover { background: #ff7a2f; }
.erro {
    background: #fdecea;
    color: #c5221f;
    padding: 9px 11px;
    border-radius: 7px;
    font-size: 13px;
    margin-bottom: 14px;
}
</style>
</head>
<body>

<div class="topo">
    <a href="app.php">&#8592; Voltar ao disco</a>
</div>

<div class="caixa">

    <h1>Trocar senha</h1>
    <p class="sub">Usuario: <?php echo htmlspecialchars($usuarioAtual, ENT_QUOTES, 'UTF-8'); ?></p>

<?php if ($erro !== ''): ?>
    <div class="erro"><?php echo htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

    <form method="post" action="senha.php">
        <?php echo campoCsrf(); ?>

        <label>Senha atual</label>
        <input type="password" name="atual">

        <label>Nova senha</label>
        <input type="password" name="nova">

        <label>Confirmar nova senha</label>
        <input type="password" name="confirma">

        <button type="submit">Alterar senha</button>
    </form>

</div>

</body>
</html>