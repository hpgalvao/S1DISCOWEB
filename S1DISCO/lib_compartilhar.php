<?php

/* ============================================================
   FUNCOES USADAS PELO LINK PUBLICO
   Ficam separadas porque link.php nao pode exigir login.
   A pasta de arquivos vem do config.php.
   ============================================================ */

define('FILES_DIR', PASTA_ARQUIVOS !== '' ? rtrim(str_replace('\\', '/', PASTA_ARQUIVOS), '/') : __DIR__ . DIRECTORY_SEPARATOR . 'files');

/* limpa "..", barras e espacos do caminho recebido */
function limpar($relativo)
{
    $partes = array();

    foreach (explode('/', str_replace('\\', '/', (string)$relativo)) as $parte) {
        $parte = trim($parte);
        if ($parte === '' || $parte === '.' || $parte === '..') {
            continue;
        }
        $partes[] = $parte;
    }

    return implode('/', $partes);
}

function pastaRaiz()
{
    $real = realpath(FILES_DIR);

    if ($real === false) {
        return rtrim(str_replace('\\', '/', FILES_DIR), '/');
    }

    return rtrim(str_replace('\\', '/', $real), '/');
}

/* caminho seguro: nunca sai da pasta de arquivos */
function caminhoSeguro($relativo)
{
    $raiz = pastaRaiz();
    $relativo = limpar($relativo);

    if ($relativo === '') {
        return $raiz;
    }

    if (strpos($relativo, '/') === 0 || preg_match('/^[A-Za-z]:/', $relativo)) {
        return false;
    }

    if (in_array('..', explode('/', $relativo), true)) {
        return false;
    }

    $caminho = realpath($raiz . '/' . $relativo);

    if ($caminho === false) {
        return false;
    }

    $caminho = rtrim(str_replace('\\', '/', $caminho), '/');

    if ($caminho !== $raiz && strpos($caminho, $raiz . '/') !== 0) {
        return false;
    }

    return $caminho;
}

/*
 * O ZIP do link publico leva qualquer tipo de arquivo.
 * Saem de fora apenas arquivos de configuracao do servidor web:
 * dentro da pasta de arquivos eles poderiam desligar a protecao
 * que impede a execucao de codigo.
 */
function arquivoBloqueado($nome)
{
    $nomes = array(
        '.htaccess', '.htpasswd', '.user.ini', 'web.config',
    );

    $nomeLower = strtolower($nome);

    foreach ($nomes as $bloqueado) {
        if ($nomeLower === strtolower($bloqueado)) {
            return true;
        }
    }

    return false;
}

function mime_content_type_com_seguranca($caminho)
{
    if (!function_exists('finfo_open')) {
        $mime = @mime_content_type($caminho);
        return is_string($mime) ? $mime : '';
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);

    if ($finfo === false) {
        return '';
    }

    $mime = finfo_file($finfo, $caminho);
    finfo_close($finfo);

    return is_string($mime) ? $mime : '';
}

function tamanhoLegivel($bytes)
{
    $bytes = (int)$bytes;

    if ($bytes <= 0) {
        return '0 B';
    }

    $unidades = array('B', 'KB', 'MB', 'GB', 'TB');
    $i = (int)floor(log($bytes, 1024));
    $i = max(0, min($i, count($unidades) - 1));

    return round($bytes / pow(1024, $i), $i > 0 ? 1 : 0) . ' ' . $unidades[$i];
}

/* extensoes com preview de texto na tela de visualizacao */
function ehTextoPublicavel($nome)
{
    $texto = array(
        'txt', 'sql', 'html', 'htm', 'js', 'json', 'css', 'xml', 'csv', 'md',
        'ini', 'cfg', 'conf', 'log', 'yml', 'yaml', 'bat', 'cmd', 'sh', 'php',
    );

    $ext = strtolower((string)pathinfo($nome, PATHINFO_EXTENSION));

    return $ext !== '' && in_array($ext, $texto, true);
}

/* nome seguro dentro do ZIP */
function nomeNoZipPublico($nome)
{
    $nome = str_replace('\\', '/', (string)$nome);
    $nome = preg_replace('#/+#', '/', $nome);

    while (strpos($nome, '../') !== false) {
        $nome = str_replace('../', '', $nome);
    }

    $nome = ltrim($nome, '/');
    $nome = str_replace("\0", '', $nome);

    return $nome === '' ? 'arquivo' : $nome;
}

/* gera ZIP da pasta e envia para o navegador */
function zipPastaPublica($pasta, $nomeBase)
{
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        exit('O servidor nao tem a extensao ZIP do PHP.');
    }

    $zip = new ZipArchive();
    $temporario = tempnam(sys_get_temp_dir(), 'pub_');

    if ($zip->open($temporario, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($temporario);
        http_response_code(500);
        exit('Nao foi possivel criar o ZIP.');
    }

    $base = nomeNoZipPublico($nomeBase);
    $contados = 0;

    $arquivos = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pasta, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($arquivos as $arquivo) {

        if ($contados >= 20000) {
            break;
        }

        if ($arquivo->isLink()) {
            continue;
        }

        $interno = $arquivo->getPathname();
        $parte = str_replace('\\', '/', substr($interno, strlen($pasta) + 1));
        $nomeNoZip = nomeNoZipPublico($base . '/' . $parte);

        if ($arquivo->isDir()) {
            $zip->addEmptyDir($nomeNoZip);
            continue;
        }

        if (!$arquivo->isFile()) {
            continue;
        }

        if (arquivoBloqueado($arquivo->getFilename())) {
            continue;
        }

        $zip->addFile($interno, $nomeNoZip);
        $contados++;
    }

    $zip->close();

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $nomeBase);

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $ascii . '.zip"');
    header('Content-Length: ' . filesize($temporario));
    header('X-Content-Type-Options: nosniff');

    readfile($temporario);
    @unlink($temporario);
    exit;
}

/* ---------- telas de erro e de senha ---------- */

function paginaErro($titulo, $mensagem)
{
    http_response_code(404);
    cabecalhosPaginaPublica();
    ?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8'); ?></title>
<style>
body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
    background:#f5f6f7; font-family:Arial,Helvetica,sans-serif; color:#14181c; }
.caixa { background:#fff; border:1px solid #dfe2e5; border-radius:12px; padding:28px 32px;
    max-width:380px; text-align:center; }
h1 { font-size:18px; margin:0 0 8px; }
p { color:#5f6771; font-size:13.5px; margin:0; }
</style>
</head>
<body>
<div class="caixa">
    <h1><?php echo htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8'); ?></h1>
    <p><?php echo htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8'); ?></p>
</div>
</body>
</html>
<?php
    exit;
}

function paginaSenha($link, $erro)
{
    cabecalhosPaginaPublica();
    ?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Senha necessaria</title>
<style>
body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
    background:#f5f6f7; font-family:Arial,Helvetica,sans-serif; color:#14181c; }
.caixa { background:#fff; border:1px solid #dfe2e5; border-radius:12px; padding:28px 32px;
    max-width:360px; width:100%; }
h1 { font-size:18px; margin:0 0 4px; }
.sub { color:#8a929c; font-size:13px; margin:0 0 18px; }
label { display:block; font-size:13px; color:#5f6771; margin-bottom:5px; }
input { width:100%; padding:9px 11px; border:1px solid #c9ced3; border-radius:8px; font-size:15px;
    box-sizing:border-box; margin-bottom:12px; }
button { width:100%; padding:10px; border:0; border-radius:8px; background:#ff5c00; color:#fff;
    font-size:15px; cursor:pointer; }
button:hover { background:#ff7a2f; }
.erro { background:#fdecea; color:#c5221f; padding:9px 11px; border-radius:7px; font-size:13px;
    margin-bottom:12px; }
</style>
</head>
<body>
<div class="caixa">
    <h1>Conteudo protegido</h1>
    <p class="sub"><?php echo htmlspecialchars(basename((string)$link['caminho']), ENT_QUOTES, 'UTF-8'); ?></p>
    <?php if ($erro !== ''): ?>
        <div class="erro"><?php echo htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <form method="post">
        <label for="s">Senha</label>
        <input type="password" id="s" name="senha" autofocus autocomplete="off">
        <button type="submit">Abrir</button>
    </form>
</div>
</body>
</html>
<?php
    exit;
}

function cabecalhosPaginaPublica()
{
    if (headers_sent()) {
        return;
    }

    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
}