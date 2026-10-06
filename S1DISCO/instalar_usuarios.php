<?php

/* ============================================================
   USUARIOS DE DEMONSTRACAO (opcional)
   Cria os 4 usuarios padrao usados pelos testes automaticos
   (helio/jana/miguel/sandro, senha 123456).

   Na primeira visita o proprio login.php ja cria o admin.
   Este arquivo so e necessario se voce quiser rodar os testes
   de S1DISCO/testes — e opcional para usar o sistema.

   Depois de usar, APAGUE ESTE ARQUIVO do servidor.
   Se ja existir algum usuario, ele nao faz nada.
   ============================================================ */

require_once __DIR__ . '/usuarios.php';

iniciarSessaoUsuarios();
cabecalhosSeguranca();

/*
 * Este arquivo cria usuarios com senha padrao.
 * So roda para quem tem acesso ao codigo do servidor:
 *   - pela web, apenas de 127.0.0.1 (localhost);
 *   - ou pela linha de comando (recomendado em hospedagem):
 *         php instalar_usuarios.php
 * Depois de usar, APAGUE este arquivo.
 */
$bloqueado = false;
$ehCli = (php_sapi_name() === 'cli');

if (!$ehCli && (!isset($_SERVER['REMOTE_ADDR']) || $_SERVER['REMOTE_ADDR'] !== '127.0.0.1')) {
    $bloqueado = true;
}

$lista = array(
    array('admin', 'Administrador', '123456', 'admin'),
    array('suporte', 'Suporte', '123456', 'usuario'),
);

$jaExiste = false;
$executado = false;
$resultados = array();
$erro = '';

if (!$bloqueado) {

    try {
        $jaExiste = totalUsuarios() > 0;
    } catch (Exception $e) {
        $erro = 'Nao foi possivel ler o banco de usuarios. Nada foi alterado.';
    }

    if ($erro === '' && !$jaExiste) {

        foreach ($lista as $u) {

            /* conta inicial com senha provisoria; use criarUsuarioProvisorio */
            $ok = criarUsuarioProvisorio($u[0], $u[1], $u[2], $u[3]);

            if ($ok) {
                $baseArquivos = PASTA_ARQUIVOS !== '' ? rtrim(str_replace('\\', '/', PASTA_ARQUIVOS), '/') : __DIR__ . DIRECTORY_SEPARATOR . 'files';
                $pastaUsuarios = PASTA_USUARIOS !== '' ? rtrim(str_replace('\\', '/', PASTA_USUARIOS), '/') : $baseArquivos . '/users';
                $destino = $pastaUsuarios . '/' . $u[0];
                if (!is_dir($destino)) {
                    @mkdir($destino, 0755, true);
                }
            }

            $resultados[] = array($u[0], $u[1], $u[3], $ok);
        }

        $executado = true;
    }
}

/* ---------- saida de linha de comando ---------- */

if ($ehCli) {

    echo "S1 Disco - instalacao dos usuarios\n";
    echo str_repeat('-', 40) . "\n";

    if ($bloqueado) {
        echo "Bloqueado.\n";
    } elseif ($erro !== '') {
        echo $erro . "\n";
        exit(1);
    } elseif ($jaExiste && !$executado) {
        echo "Ja existe(m) usuario(s) cadastrado(s). Nada foi alterado.\n";
    } elseif ($executado) {
        echo "Usuarios criados:\n\n";
        foreach ($resultados as $r) {
            printf("  %-10s perfil: %-8s senha: 123456   %s\n", $r[0], $r[3] === 'admin' ? 'ADMIN' : 'usuario', $r[3] === 'admin' ? '<- primeiro acesso' : '');
        }
        echo "\nATENCAO: as senhas sao 123456. Troque todas (gerenciar.php)\n";
        echo "         antes de publicar, e DEPOIS APAGUE instalar_usuarios.php.\n";
    }

    exit(($erro === '' && !$bloqueado) ? 0 : 1);
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalacao - S1 Disco</title>
<style>
body { margin:0; padding:24px; background:#f5f6f7; font-family:Arial,Helvetica,sans-serif; color:#14181c; }
.caixa { max-width:620px; margin:0 auto; background:#fff; border:1px solid #dfe2e5; border-radius:12px; padding:24px 26px; }
h1 { font-size:19px; margin:0 0 14px; }
table { width:100%; border-collapse:collapse; font-size:14px; }
th, td { padding:8px 6px; border-bottom:1px solid #eceef0; text-align:left; }
th { font-size:12px; color:#5f6771; text-transform:uppercase; }
.bom { color:#137333; font-weight:bold; }
.ruim { color:#c5221f; font-weight:bold; }
.aviso { background:#fff1e6; color:#a83c00; padding:11px 13px; border-radius:8px; font-size:13px; margin:14px 0; }
.aviso-fim { background:#e6f4ea; color:#137333; padding:11px 13px; border-radius:8px; font-size:13px; margin:14px 0; }
pre { background:#f5f6f7; border:1px solid #dfe2e5; border-radius:8px; padding:12px; font-size:12.5px; white-space:pre-wrap; }
a { color:#ff5c00; }
</style>
</head>
<body>

<div class="caixa">

    <h1>Instalacao dos usuarios</h1>

<?php if ($bloqueado): ?>

    <div class="aviso">
        Este instalador so funciona quando acessado de dentro do proprio servidor.
        Use o Terminal/SSH do cPanel para criar o primeiro usuario, ou apague este arquivo
        depois que os usuarios ja existirem.
    </div>

<?php elseif ($erro !== ''): ?>

    <div class="aviso">
        <?php echo htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?>
    </div>

<?php elseif ($jaExiste && !$executado): ?>

    <div class="aviso">
        Ja existe(m) usuario(s) cadastrado(s). Este instalador nao altera nada.
        Entre em <a href="gerenciar.php">gerenciar usuarios</a> para ver a lista.
    </div>

<?php elseif ($executado): ?>

    <div class="aviso-fim">
        Usuarios criados. Agora apague o arquivo <strong>instalar_usuarios.php</strong> do servidor.
    </div>

    <table>
        <tr><th>Usuario</th><th>Nome</th><th>Permissao</th><th>Senha</th><th>Situacao</th></tr>
<?php foreach ($resultados as $r): ?>
        <tr>
            <td><?php echo htmlspecialchars($r[0], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($r[1], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($r[2], ENT_QUOTES, 'UTF-8'); ?></td>
            <td>123456</td>
            <td class="<?php echo $r[3] ? 'bom' : 'ruim'; ?>"><?php echo $r[3] ? 'criado' : 'falhou'; ?></td>
        </tr>
<?php endforeach; ?>
    </table>

    <p><a href="login.php">Ir para o login</a></p>

<?php endif; ?>

    <h2>Observacoes</h2>
    <pre>- O "admin" ve todas as pastas.
- Os outros usuarios veem apenas a pasta general e a propria pasta.
- As pastas em files/users/<usuario> ja foram criadas.
- Troque a senha depois do primeiro acesso.</pre>

</div>

</body>
</html>