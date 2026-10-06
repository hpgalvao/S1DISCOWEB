<?php

/* ============================================================
   LOGIN
   Os usuarios ficam em um arquivo no proprio servidor.

   Primeira visita: o sistema cria o banco sozinho e mostra o
   cadastro do administrador (ja pre-preenchido, editavel).
   Depois, volta a ser a tela de entrada normal.
   ============================================================ */

require_once __DIR__ . '/usuarios.php';

iniciarSessaoUsuarios();
cabecalhosSeguranca();

$erro = '';
$primeiroAcesso = false;

try {
    $primeiroAcesso = (totalUsuarios() === 0);
} catch (Exception $e) {
    $primeiroAcesso = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($primeiroAcesso) {

        /* ---------- primeiro acesso: cadastro do admin ---------- */

        conferirCsrf();

        $novoUsuario = trim(isset($_POST['usuario']) ? $_POST['usuario'] : '');
        $novoNome = trim(isset($_POST['nome']) ? $_POST['nome'] : '');
        $novaSenha = isset($_POST['senha']) ? $_POST['senha'] : '';

        if (!preg_match('/^[A-Za-z0-9._\-]{3,' . (int)TAMANHO_MAX_USUARIO . '}$/', $novoUsuario)) {
            $erro = 'Login invalido: use letras, numeros, ponto, hifen ou sublinhado'
                . ' (de 3 a ' . (int)TAMANHO_MAX_USUARIO . ' caracteres).';
        } elseif ($novoNome === '') {
            $erro = 'Escreva o nome que aparecera para os outros usuarios.';
        } elseif ($novaSenha === '') {
            $erro = 'Escreva a senha do administrador.';
        } elseif (usuarioPorNome($novoUsuario)) {
            $erro = 'Este login ja existe.';
        } elseif (criarUsuarioProvisorio($novoUsuario, $novoNome, $novaSenha, 'admin')) {

            /* a pasta particular do novo admin */
            $baseArquivos = PASTA_ARQUIVOS !== '' ? rtrim(str_replace('\\', '/', PASTA_ARQUIVOS), '/') : __DIR__ . DIRECTORY_SEPARATOR . 'files';
            $pastaUsuarios = PASTA_USUARIOS !== '' ? rtrim(str_replace('\\', '/', PASTA_USUARIOS), '/') : $baseArquivos . '/users';
            $destino = $pastaUsuarios . '/' . $novoUsuario;

            if (!is_dir($destino)) {
                @mkdir($destino, 0775, true);
            }

            registrarAuditoria('primeiro_acesso', $novoUsuario, 'admin criado');

            /* entra direto */
            session_regenerate_id(true);
            iniciarSessaoValida();

            $_SESSION['usuario'] = $novoUsuario;
            $_SESSION['nome'] = $novoNome;
            $_SESSION['perfil'] = 'admin';

            header('Location: app.php');
            exit;
        } else {
            $erro = 'Nao foi possivel criar o administrador. Confira os campos e tente de novo.';
        }

    } else {

        /* ---------- login normal ---------- */

        $usuario = trim(isset($_POST['usuario']) ? $_POST['usuario'] : '');
        $senha = isset($_POST['senha']) ? $_POST['senha'] : '';

        if ($usuario !== '' && $senha !== '') {

            if (!podeTentarLogin()) {
                $erro = 'Muitas tentativas. Aguarde alguns minutos e tente de novo.';
            } elseif (conferirSenha($usuario, $senha)) {

                $conta = usuarioPorNome($usuario);

                session_regenerate_id(true);
                limparTentativas();

                iniciarSessaoValida();

                $_SESSION['usuario'] = $conta['usuario'];
                $_SESSION['nome'] = $conta['nome'];
                $_SESSION['perfil'] = $conta['perfil'];

                // renova o hash se o algoritmo do PHP mudou
                if (password_needs_rehash($conta['senha_hash'], PASSWORD_DEFAULT)) {
                    atualizarSenha($conta['usuario'], $senha);
                }

                header('Location: app.php');
                exit;

            } else {
                registrarTentativa();
                $erro = 'Usuario ou senha invalidos';
            }

        } else {
            $erro = 'Preencha os campos';
        }
    }
}

/* endereco de acesso, para a tela do primeiro acesso */
$endereco = urlDoSistema();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>S1 Disco - <?php echo $primeiroAcesso ? 'Primeiro acesso' : 'Entrar'; ?></title>
<style>
body {
    margin: 0;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f5f6f7;
    font-family: Arial, Helvetica, sans-serif;
    padding: 24px 12px;
    box-sizing: border-box;
}
.caixa {
    background: #ffffff;
    border: 1px solid #dfe2e5;
    border-radius: 12px;
    padding: 28px 30px;
    width: 380px;
}
.caixa h1 { font-size: 20px; margin: 0 0 4px; }
.caixa p { color: #8a929c; font-size: 13px; margin: 0 0 18px; }
label { display: block; font-size: 13px; color: #5f6771; margin-bottom: 5px; }
input, select {
    width: 100%;
    padding: 9px 11px;
    border: 1px solid #c9ced3;
    border-radius: 8px;
    margin-bottom: 12px;
    font-size: 15px;
    box-sizing: border-box;
}
button {
    width: 100%;
    padding: 10px;
    border: 0;
    border-radius: 8px;
    background: #ff5c00;
    color: #ffffff;
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
    margin-bottom: 12px;
}
.dica {
    font-size: 12px;
    color: #8a929c;
    background: #f5f6f7;
    border-radius: 7px;
    padding: 9px 11px;
    margin: 0 0 14px;
}
.primeiro-info {
    border-top: 1px solid #dfe2e5;
    margin-top: 20px;
    padding-top: 14px;
}
.primeiro-info h2 {
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #8a929c;
    margin: 0 0 8px;
}
.primeiro-info p {
    font-size: 12.5px;
    line-height: 1.5;
    margin: 0 0 8px;
}
.primeiro-info code {
    background: #f5f6f7;
    border-radius: 4px;
    padding: 1px 5px;
    font-size: 12px;
}
</style>
</head>
<body>

<div class="caixa">

<?php if ($primeiroAcesso): ?>

    <h1>S1 Disco</h1>
    <p>Primeiro acesso — crie o administrador</p>

    <?php if ($erro !== ''): ?>
        <div class="erro"><?php echo htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="dica">
        O banco de dados foi criado e ainda nao existe usuario.
        A primeira conta e o administrador: pode aceitar os valores
        ou trocar antes de salvar. A senha <code>123456</code> e
        provisoria — troque depois em <strong>Usuarios</strong>.
    </div>

    <form method="post" action="login.php" autocomplete="off">
        <?php echo campoCsrf(); ?>

        <label for="usuario">Login do administrador</label>
        <input type="text" id="usuario" name="usuario" value="admin" autofocus
               maxlength="<?php echo TAMANHO_MAX_USUARIO; ?>"
               autocomplete="off" autocapitalize="none" autocorrect="off"
               spellcheck="false" pattern="[A-Za-z0-9._\-]{3,<?php echo TAMANHO_MAX_USUARIO; ?>}"
               title="De 3 a <?php echo TAMANHO_MAX_USUARIO; ?> caracteres. Use letras, numeros, ponto, hifen ou sublinhado.">

        <label for="nome">Nome exibido</label>
        <input type="text" id="nome" name="nome" value="Administrador"
               maxlength="<?php echo TAMANHO_MAX_NOME; ?>"
               autocomplete="off" autocorrect="off" spellcheck="false">

        <label for="senha">Senha</label>
        <input type="password" id="senha" name="senha" value="123456"
               maxlength="<?php echo TAMANHO_MAX_SENHA; ?>"
               autocomplete="off" autocapitalize="none" autocorrect="off"
               spellcheck="false"
               title="Ate <?php echo TAMANHO_MAX_SENHA; ?> caracteres.">

        <button type="submit">Criar administrador e entrar</button>
    </form>

    <div class="primeiro-info">
        <h2>Por dentro da instalacao</h2>
        <p>
            Voce esta acessando em <code><?php echo htmlspecialchars($endereco, ENT_QUOTES, 'UTF-8'); ?>/</code>.
            <strong>O nome da pasta e o endereco de acesso:</strong> a pasta
            <code>S1DISCO</code> vira <code>.../S1DISCO/</code>.
        </p>
        <p>
            Para usar outro endereco (ex.: <code>.../disco/</code>), renomeie a pasta
            <strong>antes</strong> de publicar — o sistema usa caminhos relativos e
            acompanha o nome escolhido. O banco fica fora da area publica e tem nome
            proprio (constante <code>NOME_SISTEMA</code> no <code>config.php</code>).
        </p>
    </div>

<?php else: ?>

    <h1>S1 Disco</h1>
    <p>Gerenciador de arquivos</p>

    <?php if ($erro !== ''): ?>
        <div class="erro"><?php echo htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <form method="post" action="login.php" autocomplete="off">
        <label for="usuario">Usuario</label>
        <input type="text" id="usuario" name="usuario" autofocus
               maxlength="<?php echo TAMANHO_MAX_USUARIO; ?>"
               autocomplete="off" autocapitalize="none" autocorrect="off"
               spellcheck="false" pattern="[A-Za-z0-9._\-]{1,<?php echo TAMANHO_MAX_USUARIO; ?>}"
               title="De 3 a <?php echo TAMANHO_MAX_USUARIO; ?> caracteres. Use letras, numeros, ponto, hifen ou sublinhado.">

        <label for="senha">Senha</label>
        <input type="password" id="senha" name="senha"
               maxlength="<?php echo TAMANHO_MAX_SENHA; ?>"
               autocomplete="off" autocapitalize="none" autocorrect="off"
               spellcheck="false"
               title="Ate <?php echo TAMANHO_MAX_SENHA; ?> caracteres.">

        <button type="submit">Entrar</button>
    </form>

<?php endif; ?>

</div>

</body>
</html>