<?php

/* ============================================================
   LINK PUBLICO DE COMPARTILHAMENTO
   Pagina sem login: quem tem o link consegue ver e baixar.
   ============================================================ */

require_once __DIR__ . '/usuarios.php';

/*
 * A sessao e iniciada normalmente (cookie com o caminho certo),
 * mas NAO exige login: quem nao tem sessao so precisa do link.
 */
iniciarSessaoUsuarios();
cabecalhosSeguranca();

/*
 * Esta pagina precisa das funcoes de seguranca de caminho do app.php,
 * mas nao pode exigir login nem abrir a sessao do usuario.
 */
require_once __DIR__ . '/lib_compartilhar.php';

$codigo = isset($_GET['c']) ? trim((string)$_GET['c']) : '';

if ($codigo === '' || !preg_match('/^[A-Za-z0-9_-]{20,80}$/', $codigo)) {
    paginaErro('Link invalido', 'O endereco acessado nao e um link valido.');
}

$link = buscarLink($codigo);

if (!$link) {
    paginaErro('Link nao encontrado', 'Este link foi removido ou nunca existiu.');
}

/* caminho guardado no link tem que existir de verdade */
$caminho = limpar($link['caminho']);
$arquivo = caminhoSeguro($caminho);

if ($arquivo === false || !file_exists($arquivo)) {
    paginaErro('Item nao encontrado', 'O arquivo ou pasta deste link foi removido.');
}

if (linkExpirado($link)) {
    paginaErro('Link expirado', 'O prazo de validade deste link terminou.');
}

if (linkAcabouOsAcessos($link)) {
    paginaErro('Link esgotado', 'Este link atingiu o limite de acessos.');
}

/* ---------- senha ---------- */

$temSenha = !empty($link['senha_hash']);
$liberado = false;

if (!$temSenha) {
    $liberado = true;
} else {
    $chaveSessao = 'link_' . $codigo;
    $liberado = !empty($_SESSION[$chaveSessao]);
}

$erroSenha = '';

if ($temSenha && !$liberado) {

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $digitada = isset($_POST['senha']) ? (string)$_POST['senha'] : '';

        if (password_verify($digitada, $link['senha_hash'])) {

            $_SESSION[$chaveSessao] = true;

            registrarAuditoria('acesso_link', $caminho, 'senha conferida');

            header('Location: link.php?c=' . urlencode($codigo));
            exit;

        } else {

            $erroSenha = 'Senha incorreta.';

            $tentativas = (isset($_SESSION['tent_link']) ? (int)$_SESSION['tent_link'] : 0) + 1;
            $_SESSION['tent_link'] = $tentativas;

            if ($tentativas >= 5) {
                usleep(2000000);
            }
        }
    }

    paginaSenha($link, $erroSenha);
}

/* ---------- liberacao: conteudo ou download ---------- */

if (empty($_SESSION['acesso_link_' . $codigo])) {
    $_SESSION['acesso_link_' . $codigo] = true;
    contarAcessoLink($codigo);
    registrarAuditoria('acesso_link', $caminho, 'tipo: ' . $link['tipo']);
}

$ehPasta = is_dir($arquivo);
$titulo = $link['titulo'] !== null && $link['titulo'] !== '' ? $link['titulo'] : basename($caminho);

$ehImagem = !$ehPasta && strpos(mime_content_type_com_seguranca($arquivo), 'image/') === 0;
$ehTexto = !$ehPasta && ehTextoPublicavel(basename($caminho));

/* conteudo de arquivo texto */
if (isset($_GET['texto'])) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo @file_get_contents($arquivo, false, null, 0, 100 * 1024);
    exit;
}

/* pre-visualizacao de imagem */
if (isset($_GET['imagem'])) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $info = @getimagesize($arquivo);
    if ($info === false) {
        http_response_code(415);
        exit;
    }
    $origem = @imagecreatefromstring((string)file_get_contents($arquivo));
    if ($origem === false) {
        http_response_code(415);
        exit;
    }
    $escala = min(900 / $info[0], 900 / $info[1], 1);
    $w = max(1, (int)round($info[0] * $escala));
    $h = max(1, (int)round($info[1] * $escala));
    $mini = imagecreatetruecolor($w, $h);
    imagealphablending($mini, false);
    imagesavealpha($mini, true);
    imagecopyresampled($mini, $origem, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
    header('Content-Type: image/png');
    imagepng($mini);
    exit;
}

/* download do arquivo */
if (isset($_GET['baixar'])) {

    if ($ehPasta) {
        zipPastaPublica($arquivo, basename($caminho));
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $mime = mime_content_type_com_seguranca($arquivo);
    $mime = ($mime && strpos($mime, 'php') === false) ? $mime : 'application/octet-stream';

    $ascii = preg_replace('/[^\x20-\x7E]/', '_', basename($arquivo));
    $ascii = str_replace('"', '', $ascii);

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode(basename($arquivo)));
    header('Content-Length: ' . filesize($arquivo));
    header('X-Content-Type-Options: nosniff');
    readfile($arquivo);
    exit;
}

/* ---------- tela de visualizacao ---------- */

$tamanhoTexto = $ehPasta ? 0 : (int)filesize($arquivo);
$data = date('d/m/Y \xe0\s H:i', (int)filemtime($arquivo));

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8'); ?> - S1 Disco</title>
<style>
body {
    margin: 0;
    min-height: 100vh;
    background: #f5f6f7;
    font-family: Arial, Helvetica, sans-serif;
    color: #14181c;
}
.topo {
    background: #2b3038;
    color: #fff;
    padding: 10px 16px;
    font-size: 14px;
}
.caixa {
    max-width: 860px;
    margin: 22px auto;
    background: #fff;
    border: 1px solid #dfe2e5;
    border-radius: 12px;
    padding: 22px 26px;
}
h1 { font-size: 18px; margin: 0 0 14px; word-break: break-word; }
.info {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 6px 14px;
    font-size: 13.5px;
    color: #5f6771;
    border-bottom: 1px solid #eceef0;
    padding-bottom: 14px;
    margin-bottom: 14px;
}
.info b { color: #14181c; font-weight: normal; word-break: break-all; }
.acoes { display: flex; gap: 8px; flex-wrap: wrap; }
.btn {
    display: inline-block;
    padding: 9px 16px;
    border-radius: 8px;
    background: #ff5c00;
    color: #fff;
    text-decoration: none;
    font-size: 14px;
    cursor: pointer;
    border: 0;
}
.btn:hover { background: #ff7a2f; }
.btn.sec { background: #eceef0; color: #14181c; }
.btn.sec:hover { background: #dfe2e5; }
.previa { max-width: 100%; border-radius: 8px; border: 1px solid #dfe2e5; margin-bottom: 14px; }
.texto {
    background: #f5f6f7;
    border: 1px solid #dfe2e5;
    border-radius: 8px;
    padding: 12px 14px;
    font-size: 12.5px;
    max-height: 420px;
    overflow: auto;
    white-space: pre-wrap;
    word-break: break-word;
    margin-bottom: 14px;
}
.aviso {
    background: #fff1e6;
    color: #a83c00;
    padding: 9px 11px;
    border-radius: 7px;
    font-size: 13px;
    margin-bottom: 14px;
}
.rodape { text-align: center; color: #8a929c; font-size: 12px; margin: 18px 0 30px; }
</style>
</head>
<body>

<div class="topo">Conteudo compartilhado</div>

<div class="caixa">

    <h1><?php echo htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8'); ?></h1>

    <div class="info">
        <span>Tipo</span><b><?php echo $ehPasta ? 'Pasta' : 'Arquivo'; ?></b>
        <?php if (!$ehPasta): ?>
        <span>Tamanho</span><b><?php echo htmlspecialchars(tamanhoLegivel($tamanhoTexto), ENT_QUOTES, 'UTF-8'); ?></b>
        <?php endif; ?>
        <span>Modificado</span><b><?php echo htmlspecialchars($data, ENT_QUOTES, 'UTF-8'); ?></b>
        <?php if (!empty($link['expira_em'])): ?>
        <span>Validade</span><b><?php echo htmlspecialchars(date('d/m/Y \xe0\s H:i', strtotime($link['expira_em'])), ENT_QUOTES, 'UTF-8'); ?></b>
        <?php endif; ?>
    </div>

    <?php if ($ehPasta): ?>
        <div class="aviso">
            Este link aponta para uma pasta. O download gera um arquivo ZIP com o conteudo
            atual, incluindo as subpastas criadas depois.
        </div>
    <?php endif; ?>

    <?php if ($ehImagem): ?>
        <img class="previa" src="link.php?c=<?php echo urlencode($codigo); ?>&imagem=1" alt="">
    <?php endif; ?>

    <?php if ($ehTexto && $tamanhoTexto < 200 * 1024): ?>
        <pre class="texto" id="conteudo"><?php echo htmlspecialchars((string)@file_get_contents($arquivo, false, null, 0, 100 * 1024), ENT_QUOTES, 'UTF-8'); ?></pre>
    <?php endif; ?>

    <div class="acoes">
        <a class="btn" href="link.php?c=<?php echo urlencode($codigo); ?>&baixar=1">
            &#11015; <?php echo $ehPasta ? 'Baixar tudo (ZIP)' : 'Baixar'; ?>
        </a>
        <a class="btn sec" href="link.php?c=<?php echo urlencode($codigo); ?>">Atualizar</a>
    </div>

</div>

<div class="rodape">Compartilhado pelo S1 Disco</div>

</body>
</html>