<?php

/* ============================================================
   PERFIL DO USUARIO
   Troca o nome de exibicao e a foto.
   ============================================================ */

require_once __DIR__ . '/usuarios.php';

iniciarSessaoUsuarios();
cabecalhosSeguranca();
exigirUsuarioLogado();

$login = $_SESSION['usuario'];

$baseArquivos = PASTA_ARQUIVOS !== '' ? rtrim(str_replace('\\', '/', PASTA_ARQUIVOS), '/') : __DIR__ . DIRECTORY_SEPARATOR . 'files';

$pastaPerfis = PASTA_PERFIS !== '' ? rtrim(str_replace('\\', '/', PASTA_PERFIS), '/') : $baseArquivos . '/.perfis';

$aviso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    conferirCsrf();

    $novoNome = trim(isset($_POST['nome']) ? $_POST['nome'] : '');

    if ($novoNome !== '') {
        atualizarNome($login, $novoNome);
        $_SESSION['nome'] = $novoNome;
        registrarAuditoria('alterar_nome', $login, 'novo nome: ' . $novoNome);
        $aviso = 'Nome atualizado com sucesso.';
    } else {
        $aviso = 'Informe um nome.';
    }

    if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {

        $fotos = array('png', 'jpg', 'jpeg', 'gif', 'webp');
        $ext = strtolower((string)pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));

        if (in_array($ext, $fotos, true) && @getimagesize($_FILES['foto']['tmp_name']) !== false) {

            if (!is_dir($pastaPerfis)) {
                @mkdir($pastaPerfis, 0775, true);
            }

            foreach ($fotos as $antiga) {
                $velha = $pastaPerfis . DIRECTORY_SEPARATOR . $login . '.' . $antiga;
                if (is_file($velha)) {
                    @unlink($velha);
                }
            }

            @move_uploaded_file($_FILES['foto']['tmp_name'], $pastaPerfis . DIRECTORY_SEPARATOR . $login . '.' . $ext);
            registrarAuditoria('alterar_foto', $login, '');
            $aviso = ($aviso === '' ? '' : $aviso . ' ') . 'Foto atualizada.';
        } else {
            $aviso = ($aviso === '' ? '' : $aviso . ' ') . 'Foto invalida (use png, jpg, gif ou webp).';
        }
    }

    header('Location: perfil.php');
    exit;
}

$foto = '';
foreach (array('png', 'jpg', 'jpeg', 'gif', 'webp') as $ext) {
    $possivel = $pastaPerfis . DIRECTORY_SEPARATOR . $login . '.' . $ext;
    if (is_file($possivel)) {
        $foto = 'app.php?avatar=' . rawurlencode($login . '.' . $ext);
        break;
    }
}

$conta = usuarioPorNome($login);
$nomeAtual = $conta ? $conta['nome'] : $login;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Meu perfil - S1 Disco</title>
<style>
body { margin:0; background:#f5f6f7; font-family:Arial,Helvetica,sans-serif; color:#14181c; }
.topo { background:#2b3038; color:#fff; padding:10px 16px; display:flex; align-items:center; gap:12px; }
.topo a { color:#c8ced6; text-decoration:none; font-size:14px; }
.caixa { max-width:420px; margin:26px auto; background:#fff; border:1px solid #dfe2e5; border-radius:12px; padding:24px 26px; }
h1 { font-size:19px; margin:0 0 4px; }
.sub { color:#8a929c; font-size:13px; margin:0 0 20px; }
.foto-grande { width:84px; height:84px; border-radius:50%; object-fit:cover; background:#eceef0; display:block; margin-bottom:16px; }
.foto-vazia { width:84px; height:84px; border-radius:50%; background:#eceef0; display:flex; align-items:center; justify-content:center; font-size:38px; margin-bottom:16px; }
label { display:block; font-size:13px; color:#5f6771; margin:12px 0 5px; }
input[type="text"] { width:100%; padding:9px 11px; border:1px solid #c9ced3; border-radius:8px; font-size:15px; box-sizing:border-box; }
input[type="file"] { font-size:13px; }
button { margin-top:18px; width:100%; padding:10px; border:0; border-radius:8px; background:#ff5c00; color:#fff; font-size:15px; cursor:pointer; }
button:hover { background:#ff7a2f; }
.aviso { background:#e6f4ea; color:#137333; padding:9px 11px; border-radius:7px; font-size:13px; margin-bottom:14px; }
.login { font-size:13px; color:#8a929c; margin-top:18px; }
</style>
</head>
<body>

<div class="topo">
    <a href="app.php">&#8592; Voltar ao disco</a>
</div>

<div class="caixa">

    <h1>Meu perfil</h1>
    <p class="sub">Usuario: <?php echo htmlspecialchars($login, ENT_QUOTES, 'UTF-8'); ?></p>

<?php if ($aviso !== ''): ?>
    <div class="aviso"><?php echo htmlspecialchars($aviso, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<?php if ($foto !== ''): ?>
    <img class="foto-grande" src="<?php echo $foto; ?>" alt="">
<?php else: ?>
    <div class="foto-vazia">&#128100;</div>
<?php endif; ?>

    <form method="post" action="perfil.php" enctype="multipart/form-data">
        <?php echo campoCsrf(); ?>

        <label>Nome de exibicao</label>
        <input type="text" name="nome" value="<?php echo htmlspecialchars($nomeAtual, ENT_QUOTES, 'UTF-8'); ?>">

        <label>Foto (png, jpg, gif ou webp)</label>
        <input type="file" name="foto" accept="image/*">

        <button type="submit">Salvar</button>
    </form>

    <p class="login">
        Usuario: <strong><?php echo htmlspecialchars($login, ENT_QUOTES, 'UTF-8'); ?></strong>
        (nao pode ser alterado)
    </p>

</div>

</body>
</html>