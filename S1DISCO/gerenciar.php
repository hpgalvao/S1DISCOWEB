<?php

/* ============================================================
   GERENCIAR USUARIOS
   Cria, altera e remove usuarios do arquivo do sistema.
   ============================================================ */

require_once __DIR__ . '/usuarios.php';

iniciarSessaoUsuarios();
cabecalhosSeguranca();

/*
 * Falha no banco NAO pode abrir o modo de instalacao.
 * Se der erro aqui, o sistema para com aviso claro.
 */
try {
    $total = totalUsuarios();
} catch (Exception $e) {
    http_response_code(500);
    exit('Nao foi possivel ler os usuarios do banco. Verifique as permissões da pasta de dados.');
}

$instalando = ($total === 0);

/*
 * Modo instalacao protegido por um segredo que fica no codigo.
 * Sem ele, ninguem cria conta pela web.
 */
if ($instalando) {

    $segredo = hash('sha256', chaveUsuarios() . 'instalacao');

    if (!isset($_GET['chave']) || !hash_equals($segredo, (string)$_GET['chave'])) {
        http_response_code(403);
        exit("403 - Instalacao bloqueada.\nO primeiro usuario deve ser criado pelo arquivo instalar_usuarios.php.");
    }
} else {

    if (!usuarioLogado()) {
        header('Location: login.php');
        exit;
    }

    if (!ehAdmin()) {
        header('Location: app.php');
        exit;
    }
}

$loginAtual = usuarioLogado() ? $_SESSION['usuario'] : '';
$aviso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    conferirCsrf();

    $acao = isset($_POST['acao']) ? $_POST['acao'] : '';

    if ($acao === 'criar') {

        $novoUsuario = trim(isset($_POST['usuario']) ? $_POST['usuario'] : '');
        $novoNome = trim(isset($_POST['nome']) ? $_POST['nome'] : '');
        $novaSenha = isset($_POST['senha']) ? $_POST['senha'] : '';
        $perfil = isset($_POST['perfil']) ? $_POST['perfil'] : 'usuario';

        if (criarUsuario($novoUsuario, $novoNome, $novaSenha, $perfil)) {

// cria a pasta particular do usuario
            $baseArquivos = PASTA_ARQUIVOS !== '' ? rtrim(str_replace('\\', '/', PASTA_ARQUIVOS), '/') : __DIR__ . DIRECTORY_SEPARATOR . 'files';
            $pastaUsuarios = PASTA_USUARIOS !== '' ? rtrim(str_replace('\\', '/', PASTA_USUARIOS), '/') : $baseArquivos . '/users';
            $destino = $pastaUsuarios . '/' . $novoUsuario;
            if (!is_dir($destino)) {
                @mkdir($destino, 0755, true);
            }

            registrarAuditoria('criar_usuario', $novoUsuario, 'perfil: ' . $perfil);
            $aviso = 'Usuario criado com sucesso.';
        } else {
            $aviso = 'Nao foi possivel criar (usuario repetido ou dados invalidos).';
        }
    }

    if ($acao === 'senha') {

        $alvo = trim(isset($_POST['alvo']) ? $_POST['alvo'] : '');
        $novaSenha = isset($_POST['nova_senha']) ? $_POST['nova_senha'] : '';

        if ($alvo !== '' && atualizarSenha($alvo, $novaSenha)) {
            registrarAuditoria('definir_senha', $alvo, 'pelo admin');
            $aviso = 'Senha alterada.';
        } else {
            $aviso = 'Nao foi possivel alterar a senha.';
        }
    }

    if ($acao === 'perfil') {

        $alvo = trim(isset($_POST['alvo']) ? $_POST['alvo'] : '');
        $novoPerfil = isset($_POST['novo_perfil']) ? $_POST['novo_perfil'] : 'usuario';

        if ($alvo === $loginAtual && $novoPerfil !== 'admin') {
            $aviso = 'Voce nao pode remover a propria permissao de admin.';
        } elseif ($alvo !== '' && atualizarPerfil($alvo, $novoPerfil)) {
            registrarAuditoria('mudar_permissao', $alvo, 'perfil: ' . $novoPerfil);
            $aviso = 'Permissao atualizada.';
        } else {
            $aviso = 'Nao foi possivel atualizar.';
        }
    }

    if ($acao === 'remover') {

        $alvo = trim(isset($_POST['alvo']) ? $_POST['alvo'] : '');

        if ($alvo === $loginAtual) {
            $aviso = 'Voce nao pode remover o proprio usuario.';
        } elseif ($totalUsuarios() <= 1) {
            $aviso = 'E preciso manter pelo menos um usuario.';
        } elseif (removerUsuario($alvo)) {
            registrarAuditoria('remover_usuario', $alvo, '');
            $aviso = 'Usuario removido.';
        } else {
            $aviso = 'Nao foi possivel remover.';
        }
    }

    header('Location: usuarios.php');
    exit;
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Usuarios - S1 Disco</title>
<style>
body { margin:0; background:#f5f6f7; font-family:Arial,Helvetica,sans-serif; color:#14181c; }
.topo { background:#2b3038; color:#fff; padding:10px 16px; display:flex; align-items:center; gap:14px; }
.topo a { color:#c8ced6; text-decoration:none; font-size:14px; }
.topo .dir { margin-left:auto; }
.caixa { max-width:760px; margin:24px auto; background:#fff; border:1px solid #dfe2e5; border-radius:12px; padding:24px 26px; }
h1 { font-size:19px; margin:0 0 16px; }
h2 { font-size:15px; margin:22px 0 8px; }
label { display:block; font-size:13px; color:#5f6771; margin:10px 0 4px; }
input, select {
    width:100%; padding:8px 10px; border:1px solid #c9ced3; border-radius:8px;
    font-size:14px; box-sizing:border-box;
}
.linha { display:flex; gap:10px; flex-wrap:wrap; }
.linha > div { flex:1; min-width:180px; }
button {
    margin-top:14px; padding:9px 15px; border:0; border-radius:8px;
    background:#ff5c00; color:#fff; font-size:14px; cursor:pointer;
}
button:hover { background:#ff7a2f; }
table { width:100%; border-collapse:collapse; font-size:14px; margin-top:8px; }
th, td { padding:8px 6px; border-bottom:1px solid #eceef0; text-align:left; vertical-align:middle; }
th { font-size:12px; color:#5f6771; text-transform:uppercase; }
.aviso { background:#e6f4ea; color:#137333; padding:9px 11px; border-radius:7px; font-size:13px; margin-bottom:14px; }
.perigo { background:#d93025; }
.perigo:hover { background:#b3271e; }
.pequeno { padding:5px 9px; margin:0; font-size:12px; background:#eceef0; color:#14181c; }
.pequeno:hover { background:#dfe2e5; }
.tag { background:#fff1e6; color:#a83c00; padding:2px 7px; border-radius:99px; font-size:11px; }
.aviso-alerta { background:#fff1e6; color:#a83c00; padding:9px 11px; border-radius:7px; font-size:13px; margin-bottom:14px; }
</style>
</head>
<body>

<div class="topo">
    <a href="app.php">&#8592; Voltar ao disco</a>
    <?php if (!$instalando): ?>
        <span class="dir"><?php echo htmlspecialchars($_SESSION['nome'], ENT_QUOTES, 'UTF-8'); ?></span>
    <?php endif; ?>
</div>

<div class="caixa">

    <h1>Usuarios</h1>

<?php
$situacao = situacaoUsuarios();
$verCaminho = usuarioLogado() && ehAdmin();
?>
    <table>
        <tr><td>Onde os usuarios sao gravados</td><td><?php
            if ($verCaminho) {
                echo htmlspecialchars($situacao['caminho'] !== '' ? $situacao['caminho'] : 'nenhuma pasta utilizavel');
            } else {
                echo 'oculto por seguranca';
            }
        ?></td></tr>
        <?php if ($verCaminho): ?>
        <tr><td>Tipo de local</td><td><?php echo htmlspecialchars($situacao['local'] !== '' ? $situacao['local'] : '-'); ?></td></tr>
        <?php endif; ?>
        <tr><td>Pasta com permissao de escrita</td><td class="<?php echo $situacao['gravavel'] ? 'bom' : 'ruim'; ?>"><?php echo $situacao['gravavel'] ? 'SIM' : 'NAO'; ?></td></tr>
        <tr><td>Arquivo de usuarios</td><td class="<?php echo $situacao['arquivo'] ? 'bom' : 'ruim'; ?>"><?php echo $situacao['arquivo'] ? 'criado' : 'ainda nao criado'; ?></td></tr>
        <tr><td>Usuarios cadastrados</td><td><?php echo totalUsuarios(); ?></td></tr>
    </table>

<?php if (!$situacao['gravavel']): ?>
    <div class="aviso-alerta"><?php
        echo htmlspecialchars(
            $verCaminho ? $situacao['mensagem'] : 'O sistema nao conseguiu gravar os usuarios. Procure o administrador.',
            ENT_QUOTES,
            'UTF-8'
        );
    ?></div>
<?php endif; ?>

<?php if ($aviso !== ''): ?>
    <div class="aviso"><?php echo htmlspecialchars($aviso, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<?php
/* alerta de senha fraca (item 3.5 do relatorio) */
$fracas = array();
foreach (listarUsuarios() as $u) {
    if (temSenhaFraca($u['usuario'])) {
        $fracas[] = $u['usuario'];
    }
}
if ($fracas && $verCaminho):
?>
    <div class="aviso-alerta">
        Senha muito fraca nestas contas: <strong><?php echo htmlspecialchars(implode(', ', $fracas), ENT_QUOTES, 'UTF-8'); ?></strong>.
        Troque a senha delas agora (campo "nova senha" na tabela abaixo ou em "Trocar senha").
    </div>
<?php endif; ?>

<?php if ($instalando): ?>

    <div class="aviso-alerta">
        Nenhum usuario cadastrado. Crie o primeiro usuario para comecar.
        Escolha o perfil <strong>admin</strong> para ver todas as pastas.
    </div>

    <form method="post" action="gerenciar.php">
        <input type="hidden" name="acao" value="criar">
        <?php echo campoCsrf(); ?>

        <label>Usuario (para entrar)</label>
        <input type="text" name="usuario" autofocus>

        <label>Nome de exibicao</label>
        <input type="text" name="nome">

        <label>Senha</label>
        <input type="text" name="senha">

        <label>Permissao</label>
        <select name="perfil">
            <option value="admin">admin (vê todas as pastas)</option>
            <option value="usuario">usuario (vê general e a propria pasta)</option>
        </select>

        <button type="submit">Criar usuario</button>
    </form>

<?php else: ?>

    <h2>Adicionar</h2>

    <form method="post" action="gerenciar.php">
        <input type="hidden" name="acao" value="criar">
        <?php echo campoCsrf(); ?>

        <div class="linha">
            <div>
                <label>Usuario</label>
                <input type="text" name="usuario">
            </div>
            <div>
                <label>Nome de exibicao</label>
                <input type="text" name="nome">
            </div>
        </div>

        <div class="linha">
            <div>
                <label>Senha</label>
                <input type="text" name="senha">
            </div>
            <div>
                <label>Permissao</label>
                <select name="perfil">
                    <option value="usuario">usuario</option>
                    <option value="admin">admin</option>
                </select>
            </div>
        </div>

        <button type="submit">Criar usuario</button>
    </form>

    <h2>Usuarios cadastrados</h2>

    <table>
        <thead>
            <tr>
                <th>Usuario</th>
                <th>Nome</th>
                <th>Permissao</th>
                <th style="width:220px">Acoes</th>
            </tr>
        </thead>
        <tbody>
<?php foreach (listarUsuarios() as $u): ?>
            <tr>
                <td><?php echo htmlspecialchars($u['usuario'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($u['nome'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td>
                    <?php if ($u['perfil'] === 'admin'): ?>
                        <span class="tag">admin</span>
                    <?php else: ?>
                        usuario
                    <?php endif; ?>
                </td>
                <td>
                    <form method="post" action="gerenciar.php" style="display:inline">
                        <input type="hidden" name="acao" value="perfil">
                        <?php echo campoCsrf(); ?>
                        <input type="hidden" name="alvo" value="<?php echo htmlspecialchars($u['usuario'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="novo_perfil" value="<?php echo $u['perfil'] === 'admin' ? 'usuario' : 'admin'; ?>">
                        <button class="pequeno" type="submit"><?php echo $u['perfil'] === 'admin' ? 'Rebaixar' : 'Tornar admin'; ?></button>
                    </form>

                    <form method="post" action="gerenciar.php" style="display:inline">
                        <input type="hidden" name="acao" value="senha">
                        <?php echo campoCsrf(); ?>
                        <input type="hidden" name="alvo" value="<?php echo htmlspecialchars($u['usuario'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="text" name="nova_senha" placeholder="nova senha" style="width:110px;padding:5px 7px;font-size:12px">
                        <button class="pequeno" type="submit">Salvar</button>
                    </form>

                    <form method="post" action="gerenciar.php" style="display:inline">
                        <input type="hidden" name="acao" value="remover">
                        <?php echo campoCsrf(); ?>
                        <input type="hidden" name="alvo" value="<?php echo htmlspecialchars($u['usuario'], ENT_QUOTES, 'UTF-8'); ?>">
                        <button class="pequeno perigo" type="submit"
                                data-alvo="<?php echo htmlspecialchars($u['usuario'], ENT_QUOTES, 'UTF-8'); ?>">Remover</button>
                    </form>
                </td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>

<?php endif; ?>

</div>

<script>
/* confirmacao sem JavaScript dentro do HTML */
document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('button[data-alvo]') : null;
    if (b && !confirm('Remover o usuario ' + b.getAttribute('data-alvo') + '?')) {
        e.preventDefault();
    }
});
</script>

</body>
</html>