<?php

/* ============================================================
   S1 DISCO - gerenciador de arquivos
   O controle de usuarios fica em um arquivo no proprio servidor.
   ============================================================ */

/* ---------- CONEXAO ---------- */

require_once __DIR__ . '/usuarios.php';

/* ---------- LOGIN ---------- */

iniciarSessaoUsuarios();
cabecalhosSeguranca();

if (!usuarioLogado()) {
    header('Location: login.php');
    exit;
}

/* ---------- CONFIGURACAO ---------- */

// Pasta "files" (raiz listada). Pode ser mudada em config.php.
define('FILES_DIR', PASTA_ARQUIVOS !== '' ? rtrim(str_replace('\\', '/', PASTA_ARQUIVOS), '/') : __DIR__ . DIRECTORY_SEPARATOR . 'files');

// Pasta raiz listada: a pasta "files" que fica ao lado deste arquivo
$raiz = realpath(FILES_DIR);

if ($raiz === false) {
    die('Pasta de arquivos nao encontrada.');
}

/*
 * A pasta de arquivos nunca pode ser servida direto pelo servidor web,
 * nem executar codigo. O .htaccess cuida no Apache; o web.config cuida
 * no IIS. Nao ha equivalente em arquivo para o nginx: quem usa nginx
 * precisa fechar a pasta na configuracao do site.
 */
protegerPastaArquivos($raiz);

/*
 * O sistema e um gestor de disco: aceita qualquer tipo de arquivo,
 * inclusive .php, .exe, .sql, .msi e afins. Os arquivos so chegam
 * ate o usuario pelo app.php, que sempre envia como anexo.
 *
 * A unica excecao sao estes nomes: sao arquivos de configuracao do
 * servidor web. Se um usuario colocasse um deles dentro da pasta de
 * arquivos, poderia desligar a protecao que impede a execucao de
 * codigo naquela pasta. Nao e restricao de "tipo de arquivo", e
 * protecao do servidor.
 */
$arquivosBloqueados = [
    '.htaccess',
    '.htpasswd',
    '.user.ini',
    'web.config',
];

/* Garante que nada da pasta de arquivos seja servido ou executado direto. */
function protegerPastaArquivos($pasta)
{
    if (!is_dir($pasta) || !is_writable($pasta)) {
        return;
    }

    $webconfig = $pasta . '/web.config';

    if (is_file($webconfig)) {
        return;
    }

    @file_put_contents(
        $webconfig,
        '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<configuration>' . "\n"
        . '  <system.webServer>' . "\n"
        . '    <authorization><deny users="*" /></authorization>' . "\n"
        . '    <handlers>' . "\n"
        . '      <clear />' . "\n"
        . '      <add name="StaticFile" path="*" verb="*" modules="StaticFileModule"'
        . ' resourceType="File" requireAccess="Read" />' . "\n"
        . '    </handlers>' . "\n"
        . '  </system.webServer>' . "\n"
        . '</configuration>' . "\n"
    );
}

/* ============================================================
   FUNCOES DE SEGURANCA
   ============================================================ */

function arquivoBloqueado($nome)
{
    global $arquivosBloqueados;

    $nomeLower = strtolower($nome);

    foreach ($arquivosBloqueados as $bloqueado) {
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

/*
 * Converte um caminho relativo em caminho fisico seguro.
 * Nunca permite sair de $raiz.
 */
function caminhoSeguro($relativo)
{
    global $raiz;

    $relativo = str_replace('\\', '/', (string)$relativo);

    if ($relativo === '' || $relativo === '.') {
        return $raiz;
    }

    // Bloqueia caminhos absolutos
    if (strpos($relativo, '/') === 0 || preg_match('/^[A-Za-z]:/', $relativo)) {
        return false;
    }

    // Bloqueia ../
    if (in_array('..', explode('/', $relativo), true)) {
        return false;
    }

    $caminho = realpath($raiz . DIRECTORY_SEPARATOR . $relativo);

    if ($caminho === false) {
        return false;
    }

    $caminho = rtrim(str_replace('\\', '/', $caminho), '/');

    if ($caminho !== rtrim(str_replace('\\', '/', $raiz), '/') && strpos($caminho, rtrim(str_replace('\\', '/', $raiz), '/') . '/') !== 0) {
        return false;
    }

    return $caminho;
}

// Remove . / .. e barras
function limpar($relativo)
{
    $partes = [];

    foreach (explode('/', str_replace('\\', '/', (string)$relativo)) as $parte) {
        $parte = trim($parte);
        if ($parte === '' || $parte === '.' || $parte === '..') {
            continue;
        }
        $partes[] = $parte;
    }

    return implode('/', $partes);
}

// admin ve tudo. Outros veem "general", a propria pasta e o que foi compartilhado com eles.
function podeVer($relativo)
{
    $relativo = limpar($relativo);

    if ($relativo === '' || ehAdmin()) {
        return true;
    }

    if ($relativo === 'general' || strpos($relativo, 'general/') === 0) {
        return true;
    }

    $minha = minhaPasta();

    if ($relativo === $minha || strpos($relativo, $minha . '/') === 0) {
        return true;
    }

    /* alguem compartilhou esta pasta, ou uma pasta acima, com o usuario */
    if (nivelConcedido($_SESSION['usuario'] ?? '', $relativo) !== null) {
        return true;
    }

    return false;
}

/*
 * Pode alterar o conteudo?
 *   admin      -> tudo
 *   general e a propria pasta -> como sempre
 *   fora disso -> somente com concessao de "escrita"
 */
function podeEditar($relativo)
{
    $relativo = limpar($relativo);

    if ($relativo === '' || ehAdmin()) {
        return true;
    }

    if ($relativo === 'general' || strpos($relativo, 'general/') === 0) {
        return true;
    }

    $minha = minhaPasta();

    if ($relativo === $minha || strpos($relativo, $minha . '/') === 0) {
        return true;
    }

    return nivelConcedido($_SESSION['usuario'] ?? '', $relativo) === 'escrita';
}

/*
 * Quem pode conceder acesso e quem pode retirar acesso de um caminho.
 * O admin sempre pode. Os outros so quando o compartilhamento daquele
 * caminho foi registrado por eles: ninguem tira o acesso que o outro
 * concedeu. Isso impede que duas pessoas que enxergam a mesma pasta de
 * trabalho fiquem brigando pela lista de quem tem acesso.
 */
function podeGerenciarCompartilhamento($relativo)
{
    $relativo = limpar($relativo);

    if ($relativo === '') {
        return false;
    }

    if (ehAdmin()) {
        return true;
    }

    try {

        $sql = bdUsuarios()->prepare('SELECT criado_por FROM permissoes WHERE caminho = :c');
        $sql->execute(array('c' => $relativo));

        foreach ($sql->fetchAll() as $p) {

            if ((string)$p['criado_por'] !== (string)($_SESSION['usuario'] ?? '')) {
                return false;
            }
        }

        return true;
    } catch (Exception $e) {
        return false;
    }
}

/*
 * Pasta que guarda os arquivos de cada usuario.
 * O nome real no disco continua "users".
 */
function pastaDeUsuarios()
{
    return PASTA_USUARIOS !== '' ? rtrim(str_replace('\\', '/', PASTA_USUARIOS), '/') : 'users';
}

/* Pasta do usuario conectado (ex.: users/miguel) */
function minhaPasta()
{
    return pastaDeUsuarios() . '/' . (isset($_SESSION['usuario']) ? $_SESSION['usuario'] : '');
}

/*
 * Como a pasta aparece para o usuario.
 * Para o dono, vira "Minha pasta". Para o admin, aparece o nome real.
 */
function nomeDaPasta($rel)
{
    if (!ehAdmin() && limpar($rel) === minhaPasta()) {
        return 'Minha pasta';
    }

    return basename(limpar($rel));
}

/*
 * Pastas que aparecem dentro de $rel para o usuario atual.
 * Fora do admin, a pasta "users" some e da lugar a propria pasta.
 */
function pastasVisiveis($rel)
{
    $rel = limpar($rel);
    $dir = caminhoSeguro($rel);

    if ($dir === false || !is_dir($dir)) {
        return array();
    }

    $filhas = array();

    foreach (scandir($dir) as $item) {

        if ($item === '.' || $item === '..' || strpos($item, '.') === 0) {
            continue;
        }

        $caminho = $dir . '/' . $item;

        if (!is_dir($caminho) || is_link($caminho)) {
            continue;
        }

        $novoRel = ($rel !== '' ? $rel . '/' : '') . $item;

        if (!podeVer($novoRel)) {
            continue;
        }

        $filhas[$novoRel] = true;
    }

    /* fora do admin, mostra a propria pasta no lugar de "users" */
    if ($rel === '' && !ehAdmin()) {

        unset($filhas[pastaDeUsuarios()]);

        $minha = minhaPasta();

        if (caminhoSeguro($minha) !== false) {
            $filhas[$minha] = true;
        }
    }

    return array_keys($filhas);
}

/*
 * Confere um nome digitado pelo usuario.
 * Devolve o nome pronto, ou false se estiver invalido.
 */
function nomeValido($nome)
{
    $nome = (string)$nome;

    // remove espacos no inicio e no fim e reduz espacos duplos
    $nome = preg_replace('/\s+/u', ' ', trim($nome));

    if ($nome === '') {
        return false;
    }

    // barra e barra invertida nunca podem entrar no nome
    if (strpos($nome, '/') !== false || strpos($nome, '\\') !== false) {
        return false;
    }

    // ponto e dois pontos sozinhos mudam de pasta
    if ($nome === '.' || $nome === '..') {
        return false;
    }

    // remove o que o Windows nao aceita em nome de arquivo
    $nome = str_replace(array(':', '*', '?', '"', '<', '>', '|'), '', $nome);
    $nome = trim($nome);

    if ($nome === '') {
        return false;
    }

    if (mb_strlen($nome, 'UTF-8') > TAMANHO_MAX_NOME) {
        return false;
    }

    if (!preg_match('/^' . CARACTERES_NOME . '+$/u', $nome)) {
        return false;
    }

    return $nome;
}

/*
 * Confete um caminho relativo de arquivo enviado.
 * Mantem a extensao e devolve um nome seguro.
 */
function nomeDeUpload($nome)
{
    $nome = (string)$nome;

    $ext = strtolower((string)pathinfo($nome, PATHINFO_EXTENSION));
    $semExt = pathinfo($nome, PATHINFO_FILENAME);

    $semExt = str_replace(array('/', '\\', ':', '*', '?', '"', '<', '>', '|'), '_', $semExt);
    $semExt = trim(preg_replace('/\s+/u', ' ', $semExt));

    if ($semExt === '') {
        $semExt = 'arquivo';
    }

    if (mb_strlen($semExt, 'UTF-8') > TAMANHO_MAX_NOME) {
        $semExt = mb_substr($semExt, 0, TAMANHO_MAX_NOME, 'UTF-8');
    }

    return $semExt . ($ext !== '' ? '.' . $ext : '');
}

function tamanhoLegivel($bytes)
{
    $bytes = (int)$bytes;

    if ($bytes <= 0) {
        return '0 B';
    }

    $unidades = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = (int)floor(log($bytes, 1024));
    $i = max(0, min($i, count($unidades) - 1));

    return round($bytes / pow(1024, $i), $i > 0 ? 1 : 0) . ' ' . $unidades[$i];
}

/* ============================================================
   ESPACO DO DISCO
   O limite vem do config.php (LIMITE_DISCO): '20GB', '500MB'...
   Vazio significa "sem limite".
   ============================================================ */

/* Limite total em bytes. 0 = sem limite. */
function limiteDiscoBytes()
{
    if (!defined('LIMITE_DISCO')) {
        return 0;
    }

    $texto = trim((string)LIMITE_DISCO);

    if ($texto === '' || !preg_match('/^([0-9]+(?:[.,][0-9]+)?)\s*([KMGT]?)B?$/i', $texto, $achado)) {
        return 0;
    }

    $numero = (float)str_replace(',', '.', $achado[1]);

    $multiplicadores = array(
        '' => 1,
        'K' => 1024,
        'M' => 1024 * 1024,
        'G' => 1024 * 1024 * 1024,
        'T' => 1024 * 1024 * 1024 * 1024,
    );

    $unidade = strtoupper($achado[2]);

    return (int)round($numero * $multiplicadores[$unidade]);
}

/* Soma o tamanho de todos os arquivos da pasta de arquivos. */
function espacoUsado($pasta = null, $nivel = 0)
{
    if ($nivel > 60) {
        return 0;
    }

    if ($pasta === null) {
        $pasta = realpath(FILES_DIR);
        $pasta = ($pasta === false) ? '' : $pasta;
    }

    if ($pasta === '' || !is_dir($pasta)) {
        return 0;
    }

    $itens = @scandir($pasta);

    if ($itens === false) {
        return 0;
    }

    $total = 0;

    foreach ($itens as $item) {

        if ($item === '.' || $item === '..') {
            continue;
        }

        $caminho = $pasta . '/' . $item;

        if (is_link($caminho)) {
            continue;
        }

        if (is_dir($caminho)) {
            $total += espacoUsado($caminho, $nivel + 1);
            continue;
        }

        $tamanho = @filesize($caminho);

        if ($tamanho !== false) {
            $total += $tamanho;
        }
    }

    return $total;
}

/* Quanto ainda cabe no disco. Sem limite devolve null. */
function espacoLivre()
{
    $limite = limiteDiscoBytes();

    if ($limite <= 0) {
        return null;
    }

    return max(0, $limite - espacoUsado());
}

function iconeArquivo($caminho, $nome)
{
    $ext = strtolower((string)pathinfo($nome, PATHINFO_EXTENSION));
    $mime = function_exists('mime_content_type') ? @mime_content_type($caminho) : '';

    if (is_string($mime) && strpos($mime, 'image/') === 0) {
        return "\u{1F5BC}";
    }
    if (is_string($mime) && strpos($mime, 'video/') === 0) {
        return "\u{1F3AC}";
    }
    if (is_string($mime) && strpos($mime, 'audio/') === 0) {
        return "\u{1F3B5}";
    }
    if (is_string($mime) && strpos($mime, 'pdf') !== false) {
        return "\u{1F4C4}";
    }
    if (in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz'], true)) {
        return "\u{1F5C4}";
    }
    if (in_array($ext, ['xls', 'xlsx', 'csv'], true)) {
        return "\u{1F4CA}";
    }
    if (in_array($ext, ['doc', 'docx', 'odt'], true)) {
        return "\u{1F4DD}";
    }
    if (in_array($ext, ['txt', 'md', 'sql', 'json', 'xml', 'csv'], true)) {
        return "\u{1F4C3}";
    }

    return "\u{1F4E6}";
}

function iconePasta($rel)
{
    // general  = pasta de grupo (pessoas)
    // users    = pasta de usuario
    // resto    = pasta comum
    if ($rel === 'general') {
        return "\u{1F465}";
    }

    if ($rel === 'users') {
        return "\u{1F464}";
    }

    return "\u{1F4C1}";
}

/*
 * Extensoes treatedas arquivo de texto.
 * Esses arquivos mostram o conteudo na janela de informacoes.
 */
function ehTexto($nome, $mime = '')
{
    $texto = [
        'txt', 'sql', 'html', 'htm', 'js', 'json', 'css', 'sh', 'cmd', 'bat',
        'ini', 'log', 'cfg', 'conf', 'md', 'csv', 'xml', 'yml', 'yaml', 'php',
        'ps1', 'py', 'rb', 'java', 'c', 'h', 'cpp', 'hpp', 'cs', 'go', 'rs',
        'ts', 'tsx', 'jsx', 'vue', 'svelte', 'gitignore', 'env', 'rtf', 'srt',
    ];

    $ext = strtolower((string)pathinfo($nome, PATHINFO_EXTENSION));

    if ($ext !== '' && in_array($ext, $texto, true)) {
        return true;
    }

    // sem extensao, confia no mime
    if ($ext === '' && is_string($mime) && strpos($mime, 'text/') === 0) {
        return true;
    }

    return false;
}

/*
 * Nome seguro dentro do ZIP.
 * Sem ".." e sem barra no comeco, para quem extrair o arquivo
 * nao correr risco de escrever fora da pasta.
 */
function nomeNoZip($nome)
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

// Apaga uma pasta e tudo que esta dentro
function apagarPasta($pasta, $nivel = 0)
{
    /* limite de profundidade, para nao estourar a pilha */
    if ($nivel > 60) {
        return;
    }

    $items = scandir($pasta);

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $caminho = $pasta . DIRECTORY_SEPARATOR . $item;

        if (is_dir($caminho) && !is_link($caminho)) {
            apagarPasta($caminho, $nivel + 1);
        } else {
            @unlink($caminho);
        }
    }

    @rmdir($pasta);
}

/* ============================================================
   CONSULTA DE COMPARTILHAMENTO (usada pelo modal)
   ============================================================ */

if (isset($_GET['action']) && $_GET['action'] === 'compartilhar') {

    header('Content-Type: application/json; charset=utf-8');

    $item = limpar($_GET['caminho'] ?? '');

    if ($item === '' || !podeVer($item) || !podeGerenciarCompartilhamento($item)) {
        echo json_encode(array('ok' => false, 'erro' => 'Sem permissao'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dados = array('ok' => true, 'links' => array(), 'permissoes' => array());

    foreach (linksDe($item) as $l) {

        /* texto pronto da validade, para a tela mostrar se ainda vale */
        if (empty($l['expira_em'])) {
            $validade = 'sem validade';
        } elseif (linkExpirado($l)) {
            $validade = 'expirado em ' . date('d/m/Y H:i', strtotime($l['expira_em']));
        } else {
            $validade = 'validade ate ' . date('d/m/Y H:i', strtotime($l['expira_em']));
        }

        $dados['links'][] = array(
            'codigo' => $l['codigo'],
            'caminho' => $l['caminho'],
            'titulo' => $l['titulo'],
            'validade' => $validade,
            'expirado' => linkExpirado($l),
            'acessos' => (int)$l['acessos'],
            'tem_senha' => !empty($l['senha_hash']),
        );
    }

    foreach (permissoesDe($item) as $p) {
        $conta = usuarioPorNome($p['usuario']);
        $dados['permissoes'][] = array(
            'usuario' => $p['usuario'],
            'nome' => $conta ? $conta['nome'] : $p['usuario'],
            'nivel' => $p['nivel'],
        );
    }

    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
   DOWNLOAD DE ARQUIVO INDIVIDUAL
   ============================================================ */

if (isset($_GET['download'])) {

    $relativoDownload = $_GET['download'];

    if (is_array($relativoDownload)) {
        http_response_code(400);
        exit('Download invalido.');
    }

    if (!podeVer($relativoDownload)) {
        http_response_code(403);
        exit('Sem permissao.');
    }

    $arquivo = caminhoSeguro($relativoDownload);

    if ($arquivo === false || !is_file($arquivo) || is_link($arquivo)) {
        http_response_code(404);
        exit('Arquivo nao encontrado.');
    }

    $nomeArquivo = basename($arquivo);

    if (arquivoBloqueado($nomeArquivo)) {
        http_response_code(403);
        exit('Este arquivo nao pode ser baixado.');
    }

    if (!is_readable($arquivo)) {
        http_response_code(403);
        exit('Arquivo nao pode ser lido.');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $mime = function_exists('mime_content_type') ? @mime_content_type($arquivo) : '';
    $mime = ($mime && strpos($mime, 'php') === false) ? $mime : 'application/octet-stream';

header('Content-Type: ' . $mime);

/* nome com acento ou emoji: RFC 5987 (o padrao antigo quebrava) */
$soAscii = preg_replace('/[^\x20-\x7E]/', '_', $nomeArquivo);
$soAscii = str_replace('"', '', $soAscii);

header(
    'Content-Disposition: attachment;'
    . ' filename="' . $soAscii . '";'
    . " filename*=UTF-8''" . rawurlencode($nomeArquivo)
);

header('Content-Length: ' . filesize($arquivo));
header('X-Content-Type-Options: nosniff');

    readfile($arquivo);
    exit;
}

/* ============================================================
   FOTO DO USUARIO
   ============================================================ */

if (isset($_GET['avatar'])) {

    $nomeFoto = basename((string)$_GET['avatar']);
    $semExtensao = pathinfo($nomeFoto, PATHINFO_FILENAME);

    /* cada um so ve a propria foto; o admin ve todas */
    if ($semExtensao !== ($_SESSION['usuario'] ?? '') && !ehAdmin()) {
        http_response_code(403);
        exit('Sem permissao.');
    }

    $foto = caminhoSeguro('.perfis/' . $nomeFoto);

    if ($foto === false || !is_file($foto)) {
        http_response_code(404);
        exit;
    }

    $mime = function_exists('mime_content_type') ? @mime_content_type($foto) : '';

    if (!is_string($mime) || strpos($mime, 'image/') !== 0) {
        http_response_code(415);
        exit;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($foto));
    header('Cache-Control: private, max-age=600');
    readfile($foto);
    exit;
}

/* ============================================================
   CONTEUDO DE ARQUIVO DE TEXTO
   ============================================================ */

if (isset($_GET['conteudo'])) {

    $rel = (string)$_GET['conteudo'];

    if (!podeVer($rel)) {
        http_response_code(403);
        exit('Sem permissao.');
    }

    $arquivo = caminhoSeguro($rel);

    if ($arquivo === false || !is_file($arquivo) || is_link($arquivo)) {
        http_response_code(404);
        exit('Arquivo nao encontrado.');
    }

    $nome = basename($arquivo);

    if (arquivoBloqueado($nome)) {
        http_response_code(403);
        exit('Arquivo bloqueado.');
    }

    $mime = function_exists('mime_content_type') ? @mime_content_type($arquivo) : '';

    if (!ehTexto($nome, $mime)) {
        http_response_code(415);
        exit('Este arquivo nao e um arquivo de texto.');
    }

    $limite = 200 * 1024;
    $conteudo = @file_get_contents($arquivo, false, null, 0, $limite);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/plain; charset=utf-8');

    echo $conteudo;
    exit;
}

/* ============================================================
   MINiatura de imagem
   ============================================================ */

if (isset($_GET['miniatura'])) {

    $rel = (string)$_GET['miniatura'];

    if (!podeVer($rel)) {
        http_response_code(403);
        exit('Sem permissao.');
    }

    $arquivo = caminhoSeguro($rel);

    if ($arquivo === false || !is_file($arquivo)) {
        http_response_code(404);
        exit;
    }

    // nao le arquivos enormes na memoria
    if (filesize($arquivo) > 12 * 1024 * 1024) {
        http_response_code(415);
        exit;
    }

    $info = @getimagesize($arquivo);

    if ($info === false) {
        http_response_code(415);
        exit;
    }

    $mime = mime_content_type_com_seguranca($arquivo);

    // cria a imagem conforme o tipo, sem aceitar qualquer formato
    $origem = false;

    if ($mime === 'image/jpeg') {
        $origem = @imagecreatefromjpeg($arquivo);
    } elseif ($mime === 'image/png') {
        $origem = @imagecreatefrompng($arquivo);
    } elseif ($mime === 'image/gif') {
        $origem = @imagecreatefromgif($arquivo);
    } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $origem = @imagecreatefromwebp($arquivo);
    }

    if ($origem === false) {
        http_response_code(415);
        exit;
    }

    $largura = $info[0];
    $altura = $info[1];
    $escala = min(200 / $largura, 200 / $altura, 1);
    $novoW = max(1, (int)round($largura * $escala));
    $novoH = max(1, (int)round($altura * $escala));

    $mini = imagecreatetruecolor($novoW, $novoH);
    imagealphablending($mini, false);
    imagesavealpha($mini, true);
    imagecopyresampled($mini, $origem, 0, 0, 0, 0, $novoW, $novoH, $largura, $altura);

    header('Content-Type: image/png');
    imagepng($mini);

    exit;
}

/* ============================================================
   ACOES (POST)
   ============================================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* toda acao que altera algo precisa de token valido */
    conferirCsrf();

$acao = isset($_POST['acao']) ? $_POST['acao'] : '';
$dir = isset($_POST['dir']) ? limpar($_POST['dir']) : '';
$ver = isset($_POST['ver']) ? $_POST['ver'] : 'grade';
$ver = ($ver === 'lista') ? 'lista' : 'grade';

/* atalho que estava aberto, para o "voltar" cair na mesma lista */
$atalho = isset($_POST['atalho']) && !is_array($_POST['atalho']) ? (string)$_POST['atalho'] : '';
$atalho = in_array($atalho, array('comigo', 'meus'), true) ? $atalho : '';
$voltarAtalho = $atalho !== '' ? '&atalho=' . $atalho : '';

    if (!podeVer($dir)) {
        header('Location: app.php');
        exit;
    }

    /*
     * Acoes que mudam conteudo exigem permissao de escrita.
     * Ler (baixar, ver informacoes) continua bastando podeVer.
     */
    $acoesEscrita = array('enviar', 'nova_pasta', 'renomear', 'mover', 'excluir');

    if (in_array($acao, $acoesEscrita, true) && !podeEditar($dir)) {
        registrarAuditoria('tentativa_sem_permissao', $acao . ' em ' . $dir, '');
        header('Location: app.php?erro=1&dir=' . rawurlencode($dir));
        exit;
    }

    /*
     * Nos atalhos de compartilhamento nao existe pasta aberta: enviar
     * arquivo e criar pasta ficariam sem destino. Quem quiser, abre
     * a pasta do item e faz la dentro.
     */
    if ($atalho !== '' && ($acao === 'enviar' || $acao === 'nova_pasta')) {
        registrarAuditoria('tentativa_sem_permissao', $acao . ' no atalho ' . $atalho, '');
        header('Location: app.php?ver=' . $ver . $voltarAtalho);
        exit;
    }

    /* ---------- compartilhar ---------- */

    /*
     * Chamadas pelo modal (fetch) recebem resposta em JSON e nao
     * recarregam a pagina: assim a tela mostra na hora se deu certo.
     */
    $acaoAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    $avisoOk = true;
    $avisoDados = array();

    if ($acao === 'criar_link') {

        $item = limpar(isset($_POST['item']) ? $_POST['item'] : '');

        $usarSenha = isset($_POST['usar_senha']) && $_POST['usar_senha'] !== '';
        $senha = trim(isset($_POST['senha_link']) ? $_POST['senha_link'] : '');
        $validade = (int)(isset($_POST['validade']) ? $_POST['validade'] : 60);
        $titulo = trim(isset($_POST['titulo']) ? $_POST['titulo'] : '');
        $maxAcessos = (int)(isset($_POST['max_acessos']) ? $_POST['max_acessos'] : 0);

        if ($item === '' || !podeEditar($item) || caminhoSeguro($item) === false) {
            $avisoOk = false;
            $avisoCompartilhar = 'Sem permissao para compartilhar este item.';
        } elseif ($usarSenha && $senha === '') {
            /* marcar "usar senha" sem digitar senha nao faz sentido */
            $avisoOk = false;
            $avisoCompartilhar = 'Escreva a senha do link ou desmarque "Usar senha".';
        } else {

            if (!$usarSenha) {
                $senha = '';
            }

            $tipo = is_dir(caminhoSeguro($item)) ? 'pasta' : 'arquivo';

            $codigo = criarLink($tipo, $item, $titulo, $senha, $validade, $maxAcessos);

            if ($codigo === false) {
                $avisoOk = false;
                $avisoCompartilhar = 'Nao foi possivel criar o link.';
            } else {

                $avisoCompartilhar = 'Link criado.';

                $avisoDados['codigo'] = $codigo;
                $avisoDados['url'] = urlDoSistema() . '/link.php?c=' . $codigo;

                if ($validade > 0) {
                    $avisoDados['expira'] = date('d/m/Y H:i', time() + ($validade * 60));
                }
            }
        }
    }

    if ($acao === 'revogar_link') {

        $codigo = isset($_POST['codigo']) ? (string)$_POST['codigo'] : '';

        foreach (linksDe() as $meu) {
            if ($meu['codigo'] === $codigo) {
                revogarLink($codigo);
                $avisoCompartilhar = 'Link revogado.';
                break;
            }
        }

        if (!isset($avisoCompartilhar)) {
            $avisoCompartilhar = 'Link nao encontrado.';
        }
    }

    if ($acao === 'conceder') {

        $item = limpar(isset($_POST['item']) ? $_POST['item'] : '');
        $alvo = trim(isset($_POST['usuario']) ? $_POST['usuario'] : '');
        $nivel = (isset($_POST['nivel']) && $_POST['nivel'] === 'escrita') ? 'escrita' : 'leitura';

        if ($item === '' || $alvo === '' || $alvo === $_SESSION['usuario']
            || !podeEditar($item) || !podeGerenciarCompartilhamento($item)) {
            $avisoOk = false;
            $avisoCompartilhar = 'Sem permissao para liberar este item.';
        } else {

            $conta = usuarioPorNome($alvo);

            if (!$conta) {
                $avisoOk = false;
                $avisoCompartilhar = 'Usuario nao encontrado.';
            } else {
                concederPermissao($item, $alvo, $nivel);
                $avisoCompartilhar = 'Acesso liberado para ' . $conta['nome'] . '.';
            }
        }
    }

    if ($acao === 'revogar_acesso') {

        $item = limpar(isset($_POST['item']) ? $_POST['item'] : '');
        $alvo = trim(isset($_POST['usuario']) ? $_POST['usuario'] : '');

        if ($item === '' || $alvo === '' || !podeEditar($item) || !podeGerenciarCompartilhamento($item)) {
            $avisoOk = false;
            $avisoCompartilhar = 'Sem permissao para remover este acesso.';
        } else {
            removerPermissao($item, $alvo);
            $avisoCompartilhar = 'Acesso de ' . $alvo . ' removido.';
        }
    }

    /* tira tudo: links e pessoas deste caminho */
    if ($acao === 'revogar_tudo') {

        $item = limpar(isset($_POST['item']) ? $_POST['item'] : '');

        if ($item === '' || !podeEditar($item) || !podeGerenciarCompartilhamento($item)) {
            $avisoOk = false;
            $avisoCompartilhar = 'Sem permissao para descompartilhar este item.';
        } else {

            $quantos = 0;

            foreach (linksDe($item) as $l) {
                revogarLink($l['codigo']);
                $quantos++;
            }

            foreach (permissoesDe($item) as $p) {
                removerPermissao($item, $p['usuario']);
                $quantos++;
            }

            registrarAuditoria('descompartilhar', $item, $quantos . ' item(ns)');

            $avisoCompartilhar = 'Compartilhamento desfeito.'
                . ($quantos > 0 ? '' : ' Nao havia nada compartilhado aqui.');
        }
    }

    if (isset($avisoCompartilhar) && $acao !== 'enviar') {

        if ($acaoAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array_merge(
                array('ok' => $avisoOk, 'aviso' => $avisoCompartilhar),
                $avisoDados
            ), JSON_UNESCAPED_UNICODE);
            exit;
        }

        $destino = $dir !== '' ? $dir : '';
        $voltar = 'app.php' . ($destino !== '' ? '?dir=' . rawurlencode($destino) . '&ver=' . $ver : '?ver=' . $ver) . $voltarAtalho;

        header('Location: ' . $voltar . '&aviso=' . rawurlencode($avisoCompartilhar));
        exit;
    }

    $destino = caminhoSeguro($dir);

    if ($destino === false || !is_dir($destino)) {
        header('Location: app.php?ver=' . $ver . $voltarAtalho);
        exit;
    }

    $voltar = 'app.php' . ($dir !== '' ? '?dir=' . rawurlencode($dir) . '&ver=' . $ver : '?ver=' . $ver) . $voltarAtalho;

    /* ---------- enviar arquivos ---------- */

    if ($acao === 'enviar') {

        // o PHP descarta o POST inteiro quando passa de post_max_size
        if (empty($_POST) && !empty($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
            header('Location: ' . $voltar);
            exit;
        }
    }

    if ($acao === 'enviar' && !empty($_FILES['arquivos'])) {

        $quantos = count($_FILES['arquivos']['name']);

        if ($quantos > MAX_ARQUIVOS_ENVIO) {
            $quantos = MAX_ARQUIVOS_ENVIO;
        }

        /*
         * Limite do disco: se o que esta chegando nao couber no que
         * ainda resta, o envio inteiro e recusado antes de gravar
         * qualquer coisa.
         */
        $limite = limiteDiscoBytes();

        if ($limite > 0) {

            $totalEnviado = 0;

            for ($i = 0; $i < $quantos; $i++) {

                if ($_FILES['arquivos']['error'][$i] === UPLOAD_ERR_OK) {
                    $totalEnviado += (int)$_FILES['arquivos']['size'][$i];
                }
            }

            $usado = espacoUsado();
            $livre = $limite - $usado;

            if ($totalEnviado > $livre) {

                registrarAuditoria('enviar_barrado', $dir, tamanhoLegivel($totalEnviado) . ' sem espaco');

                header('Location: ' . $voltar . '&erro=espaco&faltam=' . (int)max(0, $totalEnviado - $livre));
                exit;
            }
        }

        $salvos = 0;
        $ignorados = 0;

        for ($i = 0; $i < $quantos; $i++) {

            if ($_FILES['arquivos']['error'][$i] !== UPLOAD_ERR_OK) {
                $ignorados++;
                continue;
            }

            $nome = nomeDeUpload($_FILES['arquivos']['name'][$i]);
            $temp = $_FILES['arquivos']['tmp_name'][$i];

            if ($nome === '' || !is_uploaded_file($temp)) {
                $ignorados++;
                continue;
            }

            /* so arquivos de configuracao do servidor web ficam de fora */
            if (arquivoBloqueado($nome)) {
                $ignorados++;
                continue;
            }

            $final = $destino . DIRECTORY_SEPARATOR . $nome;
            $contador = 1;
            $semExtensao = pathinfo($nome, PATHINFO_FILENAME);
            $extensao = pathinfo($nome, PATHINFO_EXTENSION);

            while (file_exists($final)) {
                $final = $destino . DIRECTORY_SEPARATOR . $semExtensao . ' (' . $contador . ')' . ($extensao !== '' ? '.' . $extensao : '');
                $contador++;
            }

            move_uploaded_file($temp, $final);
            @chmod($final, 0644);

            $salvos++;

            registrarAuditoria('enviar', ($dir !== '' ? $dir . '/' : '') . basename($final), basename($nome));
        }

        if ($salvos > 0 && $ignorados > 0) {
            header('Location: ' . $voltar . '&aviso=' . rawurlencode(
                $salvos . ' arquivo(s) enviado(s). ' . $ignorados . ' ignorado(s).'
            ));
            exit;
        }

        if ($salvos > 0) {
            header('Location: ' . $voltar . '&aviso=' . rawurlencode(
                $salvos . ($salvos === 1 ? ' arquivo enviado.' : ' arquivos enviados.')
            ));
            exit;
        }

        if ($ignorados > 0) {
            header('Location: ' . $voltar . '&erro=ignorado');
            exit;
        }

        header('Location: ' . $voltar);
        exit;
    }

    /* ---------- nova pasta ---------- */

    if ($acao === 'nova_pasta') {

        $nome = nomeValido(isset($_POST['nome']) ? $_POST['nome'] : '');

        if ($nome !== false && !file_exists($destino . DIRECTORY_SEPARATOR . $nome)) {
            @mkdir($destino . DIRECTORY_SEPARATOR . $nome, 0775, true);
            registrarAuditoria('nova_pasta', ($dir !== '' ? $dir . '/' : '') . $nome, '');
        }

        header('Location: ' . $voltar);
        exit;
    }

    /* ---------- renomear ---------- */

    if ($acao === 'renomear') {

        $item = limpar(isset($_POST['item']) ? $_POST['item'] : '');
        $novo = nomeValido(isset($_POST['nome']) ? $_POST['nome'] : '');

        if ($item !== '' && $novo !== false && podeVer($item)) {

            $atual = caminhoSeguro($item);

            if ($atual !== false && $atual !== caminhoSeguro('')) {
                $alvo = dirname($atual) . DIRECTORY_SEPARATOR . $novo;
                if (!file_exists($alvo)) {
                    @rename($atual, $alvo);
                    registrarAuditoria('renomear', $item, 'novo nome: ' . $novo);
                }
            }
        }

        header('Location: ' . $voltar);
        exit;
    }

    /* ---------- mover ---------- */

    if ($acao === 'mover') {

        $destinoRel = limpar(isset($_POST['destino']) ? $_POST['destino'] : '');
        $selecionados = isset($_POST['sel']) && is_array($_POST['sel']) ? $_POST['sel'] : [];

        if ($destinoRel !== '' && podeVer($destinoRel)) {

            $pastaDestino = caminhoSeguro($destinoRel);

            if ($pastaDestino !== false && is_dir($pastaDestino)) {

                foreach ($selecionados as $item) {

                    $item = limpar($item);

                    if ($item === '' || !podeVer($item)) {
                        continue;
                    }

                    $origem = caminhoSeguro($item);

                    if ($origem === false) {
                        continue;
                    }

                    $alvo = $pastaDestino . DIRECTORY_SEPARATOR . basename($origem);

                    if ($alvo !== $origem && !file_exists($alvo)) {
                        @rename($origem, $alvo);
                        registrarAuditoria('mover', $item, 'destino: ' . $destinoRel);
                    }
                }
            }
        }

        header('Location: app.php?dir=' . rawurlencode($destinoRel) . '&ver=' . $ver);
        exit;
    }

    /* ---------- baixar selecionados (zip) ---------- */

    if ($acao === 'baixar_selecionados') {

        if (!class_exists('ZipArchive')) {
            header('HTTP/1.1 500 Internal Server Error');
            exit('A extensao ZIP do PHP nao esta instalada no servidor.');
        }

        $selecionados = isset($_POST['sel']) && is_array($_POST['sel']) ? $_POST['sel'] : array();

        if (count($selecionados) === 0) {
            header('HTTP/1.1 400 Bad Request');
            exit('Nenhum item foi selecionado.');
        }

        $zip = new ZipArchive();
        $temporario = tempnam(sys_get_temp_dir(), 'disco_');

        if ($zip->open($temporario, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($temporario);
            header('HTTP/1.1 500 Internal Server Error');
            exit('Nao foi possivel criar o arquivo ZIP.');
        }

        $adicionados = array();

        foreach ($selecionados as $selecionado) {

            $selecionado = limpar($selecionado);

            if ($selecionado === '' || !podeVer($selecionado)) {
                continue;
            }

            $caminho = caminhoSeguro($selecionado);

            if ($caminho === false || is_link($caminho)) {
                continue;
            }

            /* arquivo */
            if (is_file($caminho)) {

                if (arquivoBloqueado(basename($caminho)) || !is_readable($caminho)) {
                    continue;
                }

                $nomeNoZip = nomeNoZip($selecionado);

                if (!isset($adicionados[$nomeNoZip])) {
                    $zip->addFile($caminho, $nomeNoZip);
                    $adicionados[$nomeNoZip] = true;
                }

                continue;
            }

            /* pasta (com tudo dentro) */
            if (is_dir($caminho)) {

                $base = trim(str_replace('\\', '/', $selecionado), '/');

                $arquivos = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($caminho, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($arquivos as $arquivo) {

                    /* nao deixa o ZIP crescer sem limite */
                    if (count($adicionados) >= 20000) {
                        break 2;
                    }

                    $interno = $arquivo->getPathname();

                    if ($arquivo->isLink()) {
                        continue;
                    }

                    $parte = str_replace(
                        '\\',
                        '/',
                        substr($interno, strlen($caminho) + 1)
                    );

                    $nomeNoZip = nomeNoZip($base . '/' . $parte);

                    if ($arquivo->isDir()) {
                        if (!isset($adicionados[$nomeNoZip])) {
                            $zip->addEmptyDir($nomeNoZip);
                            $adicionados[$nomeNoZip] = true;
                        }
                        continue;
                    }

                    if (!$arquivo->isFile()) {
                        continue;
                    }

                    if (arquivoBloqueado($arquivo->getFilename()) || !is_readable($interno)) {
                        continue;
                    }

                    if (!isset($adicionados[$nomeNoZip])) {
                        $zip->addFile($interno, $nomeNoZip);
                        $adicionados[$nomeNoZip] = true;
                    }
                }
            }
        }

        $zip->close();

        if (count($adicionados) === 0) {
            @unlink($temporario);
            header('HTTP/1.1 400 Bad Request');
            exit('Nenhum arquivo disponivel para download.');
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="selecionados.zip"');
        header('Content-Length: ' . filesize($temporario));
        header('X-Content-Type-Options: nosniff');

        readfile($temporario);
        @unlink($temporario);
        exit;
    }

    /* ---------- excluir ---------- */

    if ($acao === 'excluir') {

        $selecionados = isset($_POST['sel']) && is_array($_POST['sel']) ? $_POST['sel'] : [];

        foreach ($selecionados as $item) {

            $item = limpar($item);

            /* acesso de leitura nao apaga: precisa de permissao de escrita */
            if ($item === '' || !podeEditar($item)) {
                continue;
            }

            $caminho = caminhoSeguro($item);

            if ($caminho === false || $caminho === caminhoSeguro('')) {
                continue;
            }

            if (is_dir($caminho)) {
                apagarPasta($caminho);
                registrarAuditoria('excluir_pasta', $item, 'pasta inteira');
            } else {
                @unlink($caminho);
                registrarAuditoria('excluir_arquivo', $item, '');
            }
        }

        registrarAuditoria('excluir', $dir, count($selecionados) . ' item(ns)');

        header('Location: ' . $voltar);
        exit;
    }

    header('Location: ' . $voltar);
    exit;
}

/* ============================================================
   MENU LATERAL (arvore de pastas)
   ============================================================ */

function menuPastas($relativo, $atual, $nivel = 0)
{
    if ($nivel > 6) {
        return;
    }

    $dir = caminhoSeguro($relativo);

    if ($dir === false || !is_dir($dir)) {
        return;
    }

    $filhas = subpastasVisiveis($relativo);
    $temFilhas = count($filhas) > 0;

    $id = 'galeria-' . preg_replace('/[^a-zA-Z0-9]/', '-', $relativo);
    $aberta = ($relativo === '' || $relativo === $atual || estaDentroDe($atual, $relativo));

    echo '<div class="ramo">';

    echo '<div class="linha-menu">';

    if ($temFilhas) {
        echo '<button class="dobrador" type="button" title="Expandir ou recolher" onclick="alternarRamo(\'' . $id . '\')">';
        echo $aberta ? '&#8722;' : '+';
        echo '</button>';
    } else {
        // espaco vazio para alinhar com as linhas que tem dobredor
        echo '<span class="dobrador vazio"></span>';
    }

    echo '<a class="item-menu' . ($relativo === $atual ? ' ativo' : '') . '" href="' . linkPara($relativo) . '"';
    echo ' style="padding-left:' . (8 + $nivel * 14) . 'px" title="' . htmlspecialchars($relativo, ENT_QUOTES, 'UTF-8') . '">';
    echo iconePasta($relativo) . ' <span>' . ($relativo === '' ? 'Disco' : htmlspecialchars(nomeDaPasta($relativo), ENT_QUOTES, 'UTF-8')) . '</span>';
    echo '</a>';

    echo '</div>';

    if ($temFilhas) {

        echo '<div class="sub" id="' . $id . '"' . ($aberta ? '' : ' style="display:none"') . '>';

        foreach ($filhas as $novaRel) {

            menuPastas($novaRel, $atual, $nivel + 1);
        }

        echo '</div>';
    }

    echo '</div>';
}

// Pastas filhas que o usuario pode ver
// Fora do admin, a pasta "users" e trocada pela propria pasta.
function subpastasVisiveis($relativo)
{
    return pastasVisiveis($relativo);
}

// true quando $atual esta dentro de $relativo
function estaDentroDe($atual, $relativo)
{
    if ($relativo === '') {
        return true;
    }

    return strpos($atual, $relativo . '/') === 0;
}

/* ============================================================
   NAVEGACAO NORMAL
   ============================================================ */

$relativo = isset($_GET['dir']) ? $_GET['dir'] : '';

if (is_array($relativo)) {
    http_response_code(400);
    exit('Diretorio invalido.');
}

$relativo = limpar($relativo);

if (!podeVer($relativo)) {
    header('Location: app.php');
    exit;
}

$dir = caminhoSeguro($relativo);

if ($dir === false || !is_dir($dir)) {
    $relativo = '';
    $dir = caminhoSeguro('');
}

$usuario = isset($_SESSION['usuario']) ? $_SESSION['usuario'] : '';
$nomeUsuario = isset($_SESSION['nome']) ? $_SESSION['nome'] : '';

/* modo de visualizacao: grade ou lista */
$ver = isset($_GET['ver']) && !is_array($_GET['ver']) ? $_GET['ver'] : 'grade';
$ver = ($ver === 'lista') ? 'lista' : 'grade';

$verLista = ($ver === 'lista');

/*
 * Atalhos do menu lateral. Nao sao modos de visualizacao: sao listas
 * montadas com o que esta no banco de dados. Podem aparecer em grade
 * ou em lista, do mesmo jeito que uma pasta comum.
 * '' = o proprio disco
 */
$atalho = isset($_GET['atalho']) && !is_array($_GET['atalho']) ? (string)$_GET['atalho'] : '';
$atalho = in_array($atalho, array('comigo', 'meus'), true) ? $atalho : '';

$vistaCompartilhados = ($atalho !== '');

/* no atalho nao existe pasta atual: a listagem vem do banco */
if ($vistaCompartilhados) {
    $relativo = '';
    $dir = caminhoSeguro('');
}


/* monta um link preservando o modo de visualizacao */
function linkPara($destino)
{
    global $ver;

    $url = 'app.php';

    if ($destino !== '') {
        $url .= '?dir=' . rawurlencode($destino) . '&amp;ver=' . $ver;
    } else {
        $url .= '?ver=' . $ver;
    }

    return $url;
}

/* link de um atalho do menu, preservando grade ou lista */
function linkAtalho($qual)
{
    global $ver;

    return 'app.php?atalho=' . rawurlencode($qual) . '&amp;ver=' . $ver;
}

/* link que so troca o modo de visualizacao */
function linkVista($novaVer)
{
    global $ver, $atalho, $relativo;

    if ($atalho !== '') {
        return 'app.php?atalho=' . rawurlencode($atalho) . '&amp;ver=' . $novaVer;
    }

return 'app.php?dir=' . rawurlencode($relativo) . '&amp;ver=' . $novaVer;
}

/*
 * Botoes de compartilhar e descompartilhar que aparecem nos itens das
 * listas de compartilhamento. So para quem cuida do compartilhamento
 * daquele caminho (admin ou quem registrou).
 */
function botoesCompartilhamento($item, $visivel)
{
    if (!$visivel || empty($item['gerenciar'])) {
        return '';
    }

    $caminho = htmlspecialchars($item['rel'], ENT_QUOTES, 'UTF-8');

    return '<a class="mini acao-compartilhar" title="Compartilhar: criar link ou liberar acesso"'
        . ' href="javascript:void(0)" data-compartilhar="' . $caminho . '">&#128279;</a>'
        . '<a class="mini perigo acao-descompartilhar" title="Descompartilhar: tirar todos os links e acessos"'
        . ' href="javascript:void(0)" data-compartilhar="' . $caminho . '">&#10060;</a>';
}

/* caminho pai */
$pai = '';
if ($relativo !== '') {
    $partes = explode('/', $relativo);
    array_pop($partes);
    $pai = implode('/', $partes);
}

/* caminho do avo (nivel acima) */
$avo = '';
if ($pai !== '') {
    $partes = explode('/', $pai);
    array_pop($partes);
    $avo = implode('/', $partes);
}

/* foto do usuario */
$avatarUrl = '';

foreach (['png', 'jpg', 'jpeg', 'gif', 'webp'] as $extFoto) {

    $possivel = realpath(FILES_DIR . DIRECTORY_SEPARATOR . '.perfis' . DIRECTORY_SEPARATOR . $usuario . '.' . $extFoto);

    if ($possivel !== false && is_file($possivel)) {
        $avatarUrl = 'app.php?avatar=' . rawurlencode($usuario . '.' . $extFoto);
        break;
    }
}

?>

<!DOCTYPE html>
<html lang="pt-br">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>S1 Disco</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 15px;
    color: #14181c;
    background: #ffffff;
}

a {
    text-decoration: none;
    color: inherit;
}

/* ---------- TOPO ---------- */

.topo {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: #2b3038;
    color: #ffffff;
}

.topo .grupo {
    display: flex;
    align-items: center;
    gap: 4px;
}

.topo .direita {
    margin-left: auto;
}

.trilha {
    font-size: 14px;
    color: #c8ced6;
    overflow-x: auto;
    white-space: nowrap;
}

.trilha a {
    color: #c8ced6;
    padding: 3px 6px;
    border-radius: 5px;
}

.trilha a:hover {
    background: rgba(255, 255, 255, 0.15);
    color: #ffffff;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 30px;
    height: 30px;
    padding: 0 9px;
    border: 1px solid #c9ced3;
    border-radius: 7px;
    background: #ffffff;
    color: #14181c;
    font-size: 15px;
    cursor: pointer;
}

.btn:hover {
    background: #f5f6f7;
}

.btn:disabled {
    opacity: 0.35;
    cursor: default;
}

.btn.laranja {
    background: #ff5c00;
    border-color: #ff5c00;
    color: #ffffff;
}

.btn.laranja:hover {
    background: #ff7a2f;
}

.btn.perigo:hover {
    background: #d93025;
    border-color: #d93025;
    color: #ffffff;
}

.topo .btn {
    background: transparent;
    color: #e7eaee;
    border-color: transparent;
}

.topo .btn:hover {
    background: rgba(255, 255, 255, 0.15);
}

.usuario {
    background: rgba(255, 255, 255, 0.15);
    padding: 3px 10px;
    border-radius: 99px;
    font-size: 12px;
}

/* ---------- BARRA ---------- */

.barra {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 6px 12px;
    background: #f5f6f7;
    border-bottom: 1px solid #dfe2e5;
    flex-wrap: wrap;
}

.barra form {
    display: inline-flex;
    gap: 4px;
}

.sep {
    width: 1px;
    height: 20px;
    background: #c9ced3;
    margin: 0 4px;
}

.busca {
    width: 220px;
    padding: 6px 10px;
    border: 1px solid #c9ced3;
    border-radius: 7px;
    font-size: 14px;
}

.contagem {
    font-size: 12px;
    color: #8a929c;
}

/* ---------- CONTEUDO ---------- */

.conteudo {
    display: flex;
    height: calc(100vh - 92px);
}

.menu {
    width: 240px;
    background: #ffffff;
    border-right: 1px solid #dfe2e5;
    overflow: auto;
    padding: 8px 4px;
    flex-shrink: 0;
}

.menu-titulo {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #8a929c;
    padding: 6px 12px;
}

.item-menu {
    display: block;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 14px;
    color: #5f6771;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.item-menu:hover {
    background: #f5f6f7;
}

.item-menu.ativo {
    background: #fff1e6;
    color: #2b3038;
    font-weight: bold;
}

/* ---------- ESPACO DO DISCO (fim da barra lateral) ---------- */

.disco-uso {
    margin: 14px 12px 12px;
    padding: 10px 10px 9px;
    border: 1px solid #dfe2e5;
    border-radius: 8px;
    background: #fbfbfc;
}

.disco-uso .rotulo-uso {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #8a929c;
}

.disco-uso .total-uso {
    font-size: 13px;
    color: #2b3038;
    margin: 2px 0 7px;
    word-break: break-word;
}

.disco-uso .barra-uso {
    height: 6px;
    border-radius: 4px;
    background: #e8eaec;
    overflow: hidden;
}

.disco-uso .barra-uso > span {
    display: block;
    height: 100%;
    background: #ff5c00;
    border-radius: 4px;
}

.disco-uso .barra-uso > span.cheio {
    background: #c5221f;
}

.disco-uso .detalhe-uso {
    font-size: 11.5px;
    color: #8a929c;
    margin-top: 6px;
}

/* ---------- aviso da pagina ---------- */

.pagina-aviso {
    display: flex;
    align-items: center;
    gap: 8px;
    background: #e6f4ea;
    color: #137333;
    border: 1px solid #b7dfc4;
    padding: 10px 12px;
    border-radius: 8px;
    font-size: 13.5px;
    margin-bottom: 12px;
}

.pagina-aviso.ruim {
    background: #fdecea;
    color: #c5221f;
    border-color: #f5c2c0;
}

.pagina-aviso button {
    margin-left: auto;
    border: 0;
    background: transparent;
    color: inherit;
    font-size: 17px;
    line-height: 1;
    cursor: pointer;
    padding: 0 2px;
}

.principal {
    flex: 1;
    padding: 14px;
    overflow: auto;
    position: relative;
}

/* ---------- GRADE ---------- */

.grade {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 12px;
}

.cartao {
    position: relative;
    border: 1px solid #dfe2e5;
    border-radius: 10px;
    background: #ffffff;
    min-height: 140px;
    padding: 14px 8px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.cartao:hover {
    border-color: #ff5c00;
}

.cartao.ativo {
    background: #fff1e6;
    border-color: #ff5c00;
}

.cartao-link {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    text-align: center;
}

.icone {
    font-size: 44px;
    line-height: 1;
}

.miniatura {
    max-width: 100%;
    max-height: 84px;
    border-radius: 6px;
    border: 1px solid #dfe2e5;
}

.nome {
    font-size: 13px;
    word-break: break-word;
    max-width: 100%;
}

.info {
    font-size: 11px;
    color: #8a929c;
}

/* a caixa de selecao fica sobre o card */
.cartao .marca {
    position: absolute;
    top: 6px;
    left: 6px;
    width: 16px;
    height: 16px;
    cursor: pointer;
}

/* na tabela a caixa fica no fluxo normal, dentro da celula */
.tabela .marca {
    position: static;
    width: 16px;
    height: 16px;
    cursor: pointer;
    margin: 0;
    vertical-align: middle;
}

/* ---------- TABELA ---------- */

.tabela {
    width: 100%;
    border-collapse: collapse;
}

.tabela th {
    text-align: left;
    font-size: 12px;
    color: #5f6771;
    background: #f5f6f7;
    padding: 8px;
    border-bottom: 1px solid #dfe2e5;
}

.tabela td {
    padding: 7px 8px;
    border-bottom: 1px solid #eceef0;
    font-size: 14px;
}

.tabela tr:hover td {
    background: #f5f6f7;
}

/* ---------- SO UM MODO POR VEZ ---------- */

.ver-grade .tabela {
    display: none;
}

.ver-lista .grade {
    display: none;
}

.ver-lista .cartao {
    display: none;
}

.mini {
    display: inline-block;
    padding: 3px 8px;
    border: 1px solid #c9ced3;
    border-radius: 5px;
    font-size: 12px;
}

.mini:hover {
    background: #eceef0;
}

/* ---------- VAZIO / SOLTAR ---------- */

.vazio {
    text-align: center;
    color: #8a929c;
    padding: 70px 20px;
}

.vazio .icone {
    font-size: 54px;
}

.soltar {
    position: absolute;
    inset: 10px;
    border: 2px dashed #ff5c00;
    border-radius: 14px;
    background: rgba(255, 92, 0, 0.07);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 5;
}

.soltar.visivel {
    display: flex;
}

.soltar .icone {
    font-size: 46px;
    color: #ff5c00;
}

/* ---------- JANELA ---------- */

.fundo {
    position: fixed;
    inset: 0;
    background: rgba(20, 24, 28, 0.5);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    z-index: 50;
}

.fundo.aberto {
    display: flex;
}

.janela {
    background: #ffffff;
    border-radius: 12px;
    width: min(500px, 100%);
    overflow: hidden;
}

.janela-topo {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 14px;
    border-bottom: 1px solid #dfe2e5;
    font-weight: bold;
}

.janela-corpo {
    padding: 16px;
}

.janela-base {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding: 12px 14px;
    border-top: 1px solid #dfe2e5;
    background: #f5f6f7;
}

.previa {
    width: 100%;
    max-height: 240px;
    object-fit: contain;
    background: #f5f6f7;
    border-radius: 8px;
    margin-bottom: 14px;
}

.info-lista {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 8px 16px;
    font-size: 14px;
}

.info-lista dt {
    color: #8a929c;
}

.info-lista dd {
    word-break: break-all;
}

/* ---------- MENU DO USUARIO ---------- */

.marca-sistema {
    font-size: 15px;
    letter-spacing: 0.5px;
}

.conta {
    position: relative;
}

.usuario {
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(255, 255, 255, 0.15);
    border: 0;
    color: #ffffff;
    padding: 4px 10px;
    border-radius: 99px;
    font-size: 13px;
    cursor: pointer;
}

.usuario:hover {
    background: rgba(255, 255, 255, 0.25);
}

.foto {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    object-fit: cover;
    background: #ffffff;
}

.foto-vazia {
    font-size: 15px;
}

.seta {
    font-size: 10px;
    opacity: 0.8;
}

.menu-conta {
    display: none;
    position: absolute;
    right: 0;
    top: 38px;
    min-width: 180px;
    background: #ffffff;
    border: 1px solid #dfe2e5;
    border-radius: 10px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
    padding: 4px;
    z-index: 60;
}

.menu-conta.aberto {
    display: block;
}

.menu-conta a {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 8px 10px;
    border-radius: 6px;
    font-size: 14px;
    color: #14181c;
}

.menu-conta a:hover {
    background: #f5f6f7;
}

.menu-conta a.sair {
    color: #d93025;
}

.menu-conta hr {
    border: 0;
    border-top: 1px solid #eceef0;
    margin: 4px 2px;
}

/* ---------- ARVORE COM + E - ---------- */

.ramo {
    margin: 1px 0;
}

.linha-menu {
    display: flex;
    align-items: center;
}

.dobrador {
    width: 18px;
    height: 18px;
    margin-right: 2px;
    border: 1px solid #c9ced3;
    background: #ffffff;
    border-radius: 4px;
    color: #5f6771;
    font-size: 12px;
    line-height: 1;
    cursor: pointer;
    flex-shrink: 0;
    padding: 0;
}

.dobrador:hover {
    background: #eceef0;
}

.item-menu {
    flex: 1;
    display: block;
    padding: 6px 8px;
    border-radius: 6px;
    font-size: 14px;
    color: #5f6771;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.item-menu:hover {
    background: #f5f6f7;
}

.item-menu.ativo {
    background: #fff1e6;
    color: #2b3038;
    font-weight: bold;
}

.sub {
    margin-left: 9px;
    border-left: 1px solid #eceef0;
    padding-left: 2px;
}

/* ---------- BARRA DO CAMINHO ---------- */

.barra-caminho {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    background: #eef0f2;
    border: 1px solid #dfe2e5;
    border-radius: 10px;
    padding: 6px 10px;
    margin-bottom: 14px;
}

.atalhos {
    display: flex;
    gap: 4px;
    flex-shrink: 0;
}

.trilha {
    flex: 1;
    min-width: 120px;
    font-size: 14px;
    color: #5f6771;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.trilha a {
    color: #2b3038;
    padding: 2px 4px;
    border-radius: 4px;
}

.trilha a:hover {
    background: #dfe2e5;
}

.barrinha {
    color: #a8aeb5;
    padding: 0 2px;
}

/* ---------- BARRA DE SELECAO ---------- */

.barra-selecao {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    background: #fff1e6;
    border: 1px solid #ff5c00;
    border-radius: 10px;
    padding: 8px 12px;
    margin-bottom: 14px;
}

.barra-selecao label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: #2b3038;
    cursor: pointer;
}

.barra-selecao .opcoes {
    display: flex;
    gap: 6px;
    margin-left: auto;
}

.barra-selecao .contagem-selecionados {
    font-size: 13px;
    color: #5f6771;
}

/* ---------- BOTOES DE TABELA E CARTAO ---------- */

.acoes-linha {
    white-space: nowrap;
    text-align: right;
}

.acoes-linha .mini {
    margin-left: 4px;
}

.acoes-cartao {
    position: absolute;
    top: 6px;
    right: 6px;
    display: none;
    gap: 4px;
}

.cartao:hover .acoes-cartao {
    display: flex;
}

.cartao.selecionado {
    background: #fff1e6;
    border-color: #ff5c00;
}

.cartao:has(.marca:checked) {
    background: #fff1e6;
    border-color: #ff5c00;
}

.dobrador.vazio {
    visibility: hidden;
}

.acoes-cartao .mini {
    margin: 0;
}

/* ---------- CONTEUDO DE TEXTO ---------- */

.titulo-texto {
    font-size: 13px;
    color: #5f6771;
    margin: 16px 0 6px;
}

.texto {
    background: #f5f6f7;
    border: 1px solid #dfe2e5;
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 12.5px;
    line-height: 1.5;
    max-height: 300px;
    overflow: auto;
    white-space: pre-wrap;
    word-break: break-word;
    color: #14181c;
}

.carregando {
    color: #8a929c;
    font-size: 13px;
    margin-top: 12px;
}

/* ---------- compartilhar ---------- */

/* usado para esconder um dos paineis das abas */
.escondido {
    display: none !important;
}

.abas {
    display: flex;
    gap: 4px;
    border-bottom: 1px solid #dfe2e5;
    margin-bottom: 14px;
}

.aba {
    border: 0;
    background: #f5f6f7;
    color: #5f6771;
    padding: 8px 14px;
    cursor: pointer;
    font-size: 13.5px;
    font-weight: normal;
    border-bottom: 3px solid transparent;
    margin-bottom: -1px;
    border-radius: 6px 6px 0 0;
}

.aba:hover {
    background: #eceef0;
    color: #14181c;
}

.aba.ativa {
    background: #fff;
    color: #ff5c00;
    border-bottom-color: #ff5c00;
    font-weight: bold;
}

.compartilhar-aviso {
    display: none;
    background: #e6f4ea;
    color: #137333;
    padding: 9px 11px;
    border-radius: 7px;
    font-size: 13px;
    margin-bottom: 12px;
}

.compartilhar-aviso.erro {
    background: #fdecea;
    color: #c5221f;
}

.rotulo {
    display: block;
    font-size: 12px;
    color: #5f6771;
    margin: 12px 0 4px;
}

#fundoCompartilhar input[type="text"],
#fundoCompartilhar select {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid var(--borda-2);
    border-radius: 7px;
    font-size: 13.5px;
    background: #fff;
    color: #14181c;
    box-sizing: border-box;
}

.campo {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13.5px;
    color: #14181c;
    margin-bottom: 6px;
}

.ajuda {
    font-size: 12.5px;
    color: #5f6771;
    background: #f5f6f7;
    border-radius: 7px;
    padding: 8px 10px;
    margin: 0 0 12px;
}

.ajuda strong {
    color: #14181c;
}

.sub-titulo {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--texto-3);
    margin: 20px 0 8px;
}

.lista-links {
    border: 1px solid #dfe2e5;
    border-radius: 8px;
    padding: 4px;
    font-size: 13px;
    color: #5f6771;
}

.item-link-share {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 7px 8px;
    border-bottom: 1px solid #eceef0;
}

.item-link-share:last-child {
    border-bottom: 0;
}

.item-link-share.expirado .cresce {
    opacity: 0.55;
    text-decoration: line-through;
}

/* caixa do link recem criado, com o botao de copiar */
.link-novo {
    display: flex;
    align-items: center;
    gap: 8px;
    background: #e6f4ea;
    border: 1px solid #b7dfc4;
    border-radius: 8px;
    padding: 9px 10px;
    margin-bottom: 12px;
    font-size: 13px;
    color: #137333;
}

.link-novo .cresce {
    flex: 1;
    min-width: 0;
}

.link-novo .link-curto {
    color: #137333;
}

.item-link-share .cresce {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.link-curto {
    font-family: ui-monospace, Consolas, monospace;
    font-size: 11.5px;
    color: var(--texto-3);
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* ---------- LOGIN ---------- */

body.login {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100vh;
    background: #f5f6f7;
}

.caixa {
    background: #ffffff;
    border: 1px solid #dfe2e5;
    border-radius: 12px;
    padding: 28px 30px;
    width: 320px;
}

.caixa h1 {
    font-size: 20px;
    margin-bottom: 18px;
}

.caixa label {
    display: block;
    font-size: 13px;
    color: #5f6771;
    margin-bottom: 5px;
}

.caixa input {
    width: 100%;
    padding: 9px 11px;
    border: 1px solid #c9ced3;
    border-radius: 8px;
    margin-bottom: 12px;
    font-size: 15px;
}

.caixa button {
    width: 100%;
    padding: 10px;
    border: 0;
    border-radius: 8px;
    background: #ff5c00;
    color: #ffffff;
    font-size: 15px;
    cursor: pointer;
}

.caixa button:hover {
    background: #ff7a2f;
}

.erro {
    background: #fdecea;
    color: #c5221f;
    padding: 9px 11px;
    border-radius: 7px;
    font-size: 13px;
    margin-bottom: 12px;
}

</style>

</head>

<body class="<?php echo $verLista ? 'ver-lista' : 'ver-grade'; ?>">

<div class="topo">

    <strong class="marca-sistema">S1 Disco</strong>

    <div class="grupo direita">

        <div class="conta">
            <button class="usuario" type="button" onclick="alternarConta()">
<?php if ($avatarUrl !== ''): ?>
                <img class="foto" src="<?php echo $avatarUrl; ?>" alt="">
<?php else: ?>
                <span class="foto-vazia">&#128100;</span>
<?php endif; ?>
                <span class="nome-usuario"><?php echo htmlspecialchars($nomeUsuario, ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="seta">&#9662;</span>
            </button>

            <div class="menu-conta" id="menuConta">
                <a href="perfil.php"><span>&#128100;</span> Perfil</a>
                <a href="senha.php"><span>&#128273;</span> Trocar senha</a>
<?php if (ehAdmin()): ?>
                <a href="gerenciar.php"><span>&#128101;</span> Usuarios</a>
<?php endif; ?>
                <hr>
                <a href="logout.php" class="sair"><span>&#9099;</span> Deslogar</a>
            </div>
        </div>

    </div>

</div>

<div class="barra">

    <div class="grupo">
        <form method="post" action="app.php" enctype="multipart/form-data" id="formEnvio"
              class="<?php echo $vistaCompartilhados ? 'escondido' : ''; ?>">
            <input type="hidden" name="acao" value="enviar">
            <input type="hidden" name="dir" value="<?php echo htmlspecialchars($relativo, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="ver" value="<?php echo $ver; ?>">
            <?php echo campoCsrf(); ?>
            <button class="btn laranja" type="button" title="Enviar arquivos" onclick="document.getElementById('seletor').click()">&#8682;</button>
        </form>

        <form method="post" action="app.php" id="formPasta"
              class="<?php echo $vistaCompartilhados ? 'escondido' : ''; ?>">
            <input type="hidden" name="acao" value="nova_pasta">
            <input type="hidden" name="dir" value="<?php echo htmlspecialchars($relativo, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="ver" value="<?php echo $ver; ?>">
            <?php echo campoCsrf(); ?>
            <input type="hidden" name="nome" id="nomePasta">
            <button class="btn" type="submit" title="Nova pasta">&#128193;&#65291;</button>
        </form>

        <span class="sep"></span>

        <form method="post" action="app.php" id="formRenomear">
            <input type="hidden" name="acao" value="renomear">
            <input type="hidden" name="dir" value="<?php echo htmlspecialchars($relativo, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="ver" value="<?php echo $ver; ?>">
            <?php echo campoCsrf(); ?>
            <input type="hidden" name="item" id="itemRenomear">
            <input type="hidden" name="nome" id="novoNome">
            <button class="btn" type="submit" title="Renomear selecionado">&#9998;</button>
        </form>

<span class="sep"></span>

        <a class="btn" href="<?php echo linkVista($ver === 'grade' ? 'lista' : 'grade'); ?>"
           title="<?php echo $ver === 'grade' ? 'Ver em lista' : 'Ver em miniaturas'; ?>">
            <?php echo $ver === 'grade' ? '&#9636;' : '&#9635;'; ?>
        </a>
    </div>

    <div class="grupo">
        <input type="search" class="busca" id="busca" placeholder="<?php echo $vistaCompartilhados ? 'Buscar nesta lista...' : 'Buscar nesta pasta...'; ?>">
        <span class="contagem" id="contagem"></span>
    </div>

</div>

<div class="conteudo">

    <nav class="menu">
        <div class="menu-titulo">Pastas</div>
<?php menuPastas('', $relativo); ?>

        <div class="menu-titulo">Compartilhamentos</div>

        <a class="item-menu<?php echo $atalho === 'comigo' ? ' ativo' : ''; ?>" href="<?php echo linkAtalho('comigo'); ?>" title="Itens que outras pessoas compartilharam com voce">
            &#128101; <span>Compartilhados comigo</span>
        </a>

        <a class="item-menu<?php echo $atalho === 'meus' ? ' ativo' : ''; ?>" href="<?php echo linkAtalho('meus'); ?>" title="Itens que voce compartilhou">
            &#128101; <span>Meus compartilhamentos</span>
        </a>

<?php
/*
 * Espaco em uso, sempre no fim da barra lateral.
 * O limite vem do config.php (LIMITE_DISCO).
 */
$limiteDisco = limiteDiscoBytes();
$usadoDisco = espacoUsado();
$porcentagem = ($limiteDisco > 0) ? min(100, ($usadoDisco / $limiteDisco) * 100) : 0;
?>
        <div class="disco-uso" title="<?php echo $limiteDisco > 0
            ? 'Limite definido em config.php (LIMITE_DISCO)'
            : 'Sem limite: defina LIMITE_DISCO no config.php'; ?>">

            <div class="rotulo-uso">Espaco em uso</div>

            <div class="total-uso">
<?php if ($limiteDisco > 0): ?>
                <?php echo tamanhoLegivel($usadoDisco) . ' de ' . tamanhoLegivel($limiteDisco); ?>
<?php else: ?>
                <?php echo tamanhoLegivel($usadoDisco); ?>
<?php endif; ?>
            </div>

            <div class="barra-uso">
                <span class="<?php echo $porcentagem >= 90 ? 'cheio' : ''; ?>" style="width: <?php echo $porcentagem > 0 ? max(1.5, $porcentagem) : 0; ?>%"></span>
            </div>

            <div class="detalhe-uso">
<?php if ($limiteDisco > 0): ?>
                <?php echo round($porcentagem, 1) . '% · ' . tamanhoLegivel(max(0, $limiteDisco - $usadoDisco)) . ' livres'; ?>
<?php else: ?>
                sem limite definido
<?php endif; ?>
            </div>
        </div>
    </nav>

    <main class="principal">

<?php
/* recados das acoes: upload, espaco, exclusao, etc. */
$paginaAviso = '';
$paginaAvisoRuim = false;

if (isset($_GET['aviso']) && is_string($_GET['aviso']) && $_GET['aviso'] !== '') {
    $paginaAviso = $_GET['aviso'];
}

if (isset($_GET['erro']) && is_string($_GET['erro'])) {

    if ($_GET['erro'] === 'espaco') {

        $faltam = isset($_GET['faltam']) ? (int)$_GET['faltam'] : 0;

        $paginaAvisoRuim = true;
        $paginaAviso = 'Espaco insuficiente: o envio foi barrado.'
            . ($faltam > 0 ? ' Faltavam ' . tamanhoLegivel($faltam) . ' no disco.' : '')
            . ' Apague algo ou aumente o limite em config.php.';

    } elseif ($_GET['erro'] === 'ignorado') {

        $paginaAvisoRuim = true;
        $paginaAviso = 'Nenhum arquivo foi gravado.';

    } elseif ($_GET['erro'] === '1') {

        $paginaAvisoRuim = true;
        $paginaAviso = 'Sem permissao para fazer essa operacao aqui.';
    }
}
?>
<?php if ($paginaAviso !== ''): ?>
        <div class="pagina-aviso<?php echo $paginaAvisoRuim ? ' ruim' : ''; ?>">
            <span><?php echo htmlspecialchars($paginaAviso, ENT_QUOTES, 'UTF-8'); ?></span>
            <button type="button" title="Fechar" onclick="this.parentNode.remove()">&times;</button>
        </div>
<?php endif; ?>

        <div class="barra-caminho">
            <div class="atalhos">
                <a class="btn" href="<?php echo linkPara(''); ?>" title="Ir para a raiz do disco">&#127968; Raiz</a>
                <a class="btn<?php echo $relativo === '' ? ' desativado' : ''; ?>" href="<?php echo linkPara($pai); ?>" title="Voltar para a pasta anterior">&#8592;</a>
                <a class="btn<?php echo $relativo === '' ? ' desativado' : ''; ?>" href="<?php echo linkPara($avo); ?>" title="Subir um nivel">&#8593;</a>
            </div>

<nav class="trilha">
<?php if ($vistaCompartilhados): ?>
                <span><?php echo $atalho === 'comigo' ? 'Compartilhados comigo' : 'Meus compartilhamentos'; ?></span>
<?php else: ?>
                <a href="<?php echo linkPara(''); ?>">Disco</a>
<?php
$acc = '';

/*
 * Fora do admin, a propria pasta aparece como "Minha pasta",
 * sem mostrar o nivel intermediario "users".
 */
if (!ehAdmin() && strpos($relativo, minhaPasta()) === 0) {

    echo ' <span class="barrinha">/</span> <a href="' . linkPara(minhaPasta()) . '">'
       . htmlspecialchars(nomeDaPasta(minhaPasta()), ENT_QUOTES, 'UTF-8') . '</a>';

    if ($relativo !== minhaPasta()) {

        $resto = substr($relativo, strlen(minhaPasta()));

        $acc = minhaPasta();

        foreach (explode('/', ltrim($resto, '/')) as $parte) {
            if ($parte === '') {
                continue;
            }
            $acc .= '/' . $parte;
            echo ' <span class="barrinha">/</span> <a href="' . linkPara($acc) . '">'
               . htmlspecialchars($parte, ENT_QUOTES, 'UTF-8') . '</a>';
        }
    }

} else {

    foreach (explode('/', $relativo) as $parte) {

        if ($parte === '') {
            continue;
        }

$acc = $acc === '' ? $parte : $acc . '/' . $parte;

        echo ' <span class="barrinha">/</span> <a href="' . linkPara($acc) . '">'
           . htmlspecialchars($parte, ENT_QUOTES, 'UTF-8') . '</a>';
    }
}
endif;
?>
            </nav>

            <a class="btn" href="<?php echo $vistaCompartilhados ? linkAtalho($atalho) : linkPara($relativo); ?>" title="Atualizar listagem">&#8635;</a>
        </div>

        <form method="post" action="app.php" class="barra-selecao" id="barraSelecao">
            <input type="hidden" name="dir" value="<?php echo htmlspecialchars($relativo, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="ver" value="<?php echo $ver; ?>">
            <input type="hidden" name="atalho" value="<?php echo $atalho; ?>">
            <?php echo campoCsrf(); ?>

            <label>
                <input type="checkbox" id="selecionarTodos" onchange="alternarTodas(this)">
                Selecionar todos
            </label>

            <span class="contagem-selecionados" id="contagemSelecionados">0 selecionado(s)</span>

            <div class="opcoes" id="opcoesSelecao" style="display:none">
                <button class="btn" type="button" title="Compartilhar os selecionados" onclick="abrirCompartilhar()">&#128279; Compartilhar</button>
                <button class="btn" type="submit" name="acao" value="baixar_selecionados" title="Baixar os marcados em um ZIP">&#11015; Baixar selecionados</button>
                <button class="btn perigo" type="submit" name="acao" value="excluir" onclick="return confirmarExclusao()">&#128465; Excluir selecionados</button>
            </div>
        </form>

<?php

$items = scandir($dir);
$total = 0;
$visiveis = [];

/*
 * Atalhos de compartilhamento: a lista vem do banco, nao do disco.
 * Cada linha traz o caminho real do item. Ao clicar, o acesso normal
 * confere a permissao da pessoa, como em qualquer outra pasta.
 */
if ($vistaCompartilhados) {

    $linhas = ($atalho === 'comigo') ? compartilhadosComigo() : meusCompartilhamentos();

    $nomes = array();

    foreach ($linhas as $linha) {

        $caminho = limpar(isset($linha['caminho']) ? $linha['caminho'] : '');

        if ($caminho === '' || !podeVer($caminho)) {
            continue;
        }

        $caminhoReal = caminhoSeguro($caminho);

        if ($caminhoReal === false) {
            continue;
        }

        $ehPasta = is_dir($caminhoReal);

        /* nome de quem aparece no texto (vem do banco, nao do disco) */
        $nomeDe = function ($usuario) use (&$nomes) {

            $usuario = (string)$usuario;

            if ($usuario === '') {
                return '';
            }

            if (!isset($nomes[$usuario])) {
                $conta = usuarioPorNome($usuario);
                $nomes[$usuario] = $conta ? $conta['nome'] : $usuario;
            }

            return $nomes[$usuario];
        };

        /* texto que mostra o que ainda esta ligado a este item */
        $observacao = '';

        if ($atalho === 'comigo') {

            $observacao = 'de ' . ($nomeDe(isset($linha['quem']) ? $linha['quem'] : '') ?: 'alguem')
                . ' (' . (isset($linha['nivel']) && $linha['nivel'] === 'escrita' ? 'ver e alterar' : 'somente ver') . ')';

        } else {

            $partes = array();

            if (!empty($linha['pessoas'])) {

                $lista = array();

                foreach ($linha['pessoas'] as $outro) {
                    $lista[] = $nomeDe($outro);
                }

                if (count($lista) > 2) {
                    $partes[] = 'com ' . implode(', ', array_slice($lista, 0, 2))
                        . ' e mais ' . (count($lista) - 2);
                } else {
                    $partes[] = 'com ' . implode(' e ', $lista);
                }
            }

            if (!empty($linha['links'])) {
                $quantos = (int)$linha['links'];
                $partes[] = $quantos . ($quantos > 1 ? ' links publicos' : ' link publico');
            }

            if (!empty($linha['links_expirados'])) {
                $quantos = (int)$linha['links_expirados'];
                $partes[] = $quantos . ($quantos > 1 ? ' links expirados' : ' link expirado');
            }

            $observacao = implode(' · ', $partes);
        }

        $visiveis[] = array(
            'nome' => basename($caminho),
            'rel' => $caminho,
            'caminho' => $caminhoReal,
            'pasta' => $ehPasta,
            'tamanho' => $ehPasta ? 0 : (int)filesize($caminhoReal),
            'data' => date('d/m/Y H:i', isset($linha['data']) ? strtotime($linha['data']) : filemtime($caminhoReal)),
            'nivel' => isset($linha['nivel']) ? $linha['nivel'] : '',
            'tipo' => isset($linha['tipo']) ? $linha['tipo'] : '',
            'info' => $observacao,

            /* so quem alterou o item e cuida da lista de acesso dele */
            'gerenciar' => (podeEditar($caminho) && podeGerenciarCompartilhamento($caminho)),
        );

        $total++;
    }

    $items = array();
}

/*
 * Fora do admin, a pasta "users" nao aparece: no lugar dela
 * entra a propria pasta do usuario.
 */
$trocarUsers = ($relativo === '' && !ehAdmin() && !$vistaCompartilhados);

foreach ($items as $item) {

    if ($item === '.' || $item === '..' || strpos($item, '.') === 0) {
        continue;
    }

    /* fora do admin a pasta "users" e substituida pela propria */
    if ($trocarUsers && $item === basename(pastaDeUsuarios())) {
        continue;
    }

    $path = $dir . DIRECTORY_SEPARATOR . $item;

    if (is_link($path)) {
        continue;
    }

    $isDir = is_dir($path);

    if (!$isDir && !is_file($path)) {
        continue;
    }

    if (!$isDir && arquivoBloqueado($item)) {
        continue;
    }

    $novoRelativo = ($relativo !== '' ? $relativo . '/' : '') . $item;

    if (!podeVer($novoRelativo)) {
        continue;
    }

    $visiveis[] = array(
        'nome' => $item,
        'rel' => $novoRelativo,
        'caminho' => $path,
        'pasta' => $isDir,
        'tamanho' => $isDir ? 0 : filesize($path),
        'data' => date('d/m/Y H:i', filemtime($path)),
    );

    $total++;
}

/* inclui a propria pasta do usuario */
if ($trocarUsers) {

    $minha = minhaPasta();
    $caminhoMinha = caminhoSeguro($minha);

    if ($caminhoMinha !== false) {

        $visiveis[] = array(
            'nome' => basename($minha),
            'rel' => $minha,
            'caminho' => $caminhoMinha,
            'pasta' => true,
            'tamanho' => 0,
            'data' => date('d/m/Y H:i', filemtime($caminhoMinha)),
        );

        $total++;
    }
}

usort($visiveis, function ($a, $b) {
    if ($a['pasta'] !== $b['pasta']) {
        return $a['pasta'] ? -1 : 1;
    }
    return strcasecmp($a['nome'], $b['nome']);
});

if ($total === 0) {

    if ($atalho === 'comigo') {
        echo '<div class="vazio"><div class="icone">&#128101;</div><p>Nada foi compartilhado com voce</p>'
           . '<small>Quando alguem liberar uma pasta para voce, ela aparece aqui</small></div>';
    } elseif ($atalho === 'meus') {
        echo '<div class="vazio"><div class="icone">&#128279;</div><p>Voce ainda nao compartilhou nada</p>'
           . '<small>Marque um item e use o botao Compartilhar</small></div>';
    } else {
        echo '<div class="vazio"><div class="icone">&#128193;</div><p>Esta pasta esta vazia</p>'
           . '<small>Arraste arquivos aqui para enviar</small></div>';
    }
}

?>

        <div class="grade">
<?php

foreach ($visiveis as $item) {

    $rotulo = $item['pasta'] ? nomeDaPasta($item['rel']) : $item['nome'];
    $nome = htmlspecialchars($rotulo, ENT_QUOTES, 'UTF-8');
    $caminho = $item['caminho'];
    $ext = strtolower((string)pathinfo($item['nome'], PATHINFO_EXTENSION));
    $mime = function_exists('mime_content_type') ? @mime_content_type($caminho) : '';
    $ehImagem = !$item['pasta'] && is_string($mime) && strpos($mime, 'image/') === 0;

    $ehTexto = !$item['pasta'] && ehTexto($item['nome'], $mime);

    /* texto mostrado abaixo do nome: o padrao, ou a observacao do atalho */
    $detalhe = isset($item['info']) && $item['info'] !== ''
        ? $item['info']
        : ($item['pasta'] ? 'Pasta' : tamanhoLegivel($item['tamanho']));

    $detalhe = htmlspecialchars($detalhe, ENT_QUOTES, 'UTF-8');

    echo '<div class="cartao" data-nome="' . $nome . '"'
       . ' data-rel="' . htmlspecialchars($item['rel'], ENT_QUOTES, 'UTF-8') . '"'
       . ' data-pasta="' . ($item['pasta'] ? '1' : '0') . '"'
       . ' data-origem="' . linkPara($item['rel']) . '"'
       . ' data-nome-arquivo="' . htmlspecialchars($item['nome'], ENT_QUOTES, 'UTF-8') . '"'
       . ' data-tamanho="' . htmlspecialchars(tamanhoLegivel($item['tamanho']), ENT_QUOTES, 'UTF-8') . '"'
       . ' data-mime="' . htmlspecialchars($mime, ENT_QUOTES, 'UTF-8') . '"'
       . ' data-data="' . htmlspecialchars($item['data'], ENT_QUOTES, 'UTF-8') . '"'
       . ' data-texto="' . ($ehTexto ? '1' : '0') . '">';

    echo '<input type="checkbox" class="marca" name="sel[]" value="'
       . htmlspecialchars($item['rel'], ENT_QUOTES, 'UTF-8') . '" form="barraSelecao">';

    /*
     * Nos atalhos de compartilhamento aparece o botao que abre a tela
     * de compartilhamento: la sim sai link e se tira pessoa da lista.
     */
    $botaoCompartilhar = botoesCompartilhamento($item, $vistaCompartilhados);

    if ($item['pasta']) {

        echo '<div class="cartao-link">';
        echo '<div class="icone">' . iconePasta($item['rel']) . '</div>';
        echo '<div class="nome">' . $nome . '</div>';
        echo '<div class="info">' . $detalhe . '</div>';
        echo '</div>';

        echo '<div class="acoes-cartao">';
        echo '<a class="mini" title="Abrir pasta" href="' . linkPara($item['rel']) . '">&#128194;</a>';
        echo $botaoCompartilhar;
        echo '</div>';

    } else {

        echo '<div class="cartao-link">';

        if ($ehImagem) {
            echo '<img class="miniatura" src="app.php?miniatura=' . rawurlencode($item['rel']) . '" alt="">';
        } else {
            echo '<div class="icone">' . iconeArquivo($caminho, $item['nome']) . '</div>';
        }

        echo '<div class="nome">' . $nome . '</div>';
        echo '<div class="info">' . $detalhe . '</div>';
        echo '</div>';

        echo '<div class="acoes-cartao">';
        echo '<a class="mini" title="Ver informacoes" href="javascript:void(0)" onclick="abrirCartao(this.parentNode.parentNode);return false">&#128065;</a>';
        echo '<a class="mini" title="Baixar" href="app.php?download=' . rawurlencode($item['rel']) . '">&#11015;</a>';
        echo $botaoCompartilhar;
        echo '</div>';

    }

    echo '</div>';
}

?>
        </div>

        <table class="tabela">
            <thead>
                <tr>
                    <th style="width:30px"></th>
                    <th>Nome</th>
                    <th style="width:120px">Tipo</th>
                    <th style="width:110px">Tamanho</th>
                    <th style="width:150px">Modificado</th>
                    <th style="width:90px"></th>
                </tr>
            </thead>
            <tbody>
<?php

foreach ($visiveis as $item) {

    $rotulo = $item['pasta'] ? nomeDaPasta($item['rel']) : $item['nome'];
    $nome = htmlspecialchars($rotulo, ENT_QUOTES, 'UTF-8');
    $ext = strtolower((string)pathinfo($item['nome'], PATHINFO_EXTENSION));
    $mime = function_exists('mime_content_type') ? @mime_content_type($item['caminho']) : '';

    /* coluna tipo: o padrao, ou a observacao do atalho */
    $rotuloTipo = isset($item['info']) && $item['info'] !== ''
        ? $item['info']
        : ($item['pasta'] ? 'Pasta' : strtoupper($ext !== '' ? $ext : 'arquivo'));

    $rotuloTipo = htmlspecialchars($rotuloTipo, ENT_QUOTES, 'UTF-8');

    /* botoes de compartilhar/descompartilhar (so nas listas de compartilhamento) */
    $botaoCompartilhar = botoesCompartilhamento($item, $vistaCompartilhados);

    echo '<tr>';

    echo '<td><input type="checkbox" class="marca" name="sel[]" value="'
       . htmlspecialchars($item['rel'], ENT_QUOTES, 'UTF-8') . '" form="barraSelecao"></td>';

    if ($item['pasta']) {

        echo '<td><a href="' . linkPara($item['rel']) . '">'
           . iconePasta($item['rel']) . ' ' . $nome . '</a></td>';
        echo '<td>' . $rotuloTipo . '</td>';
        echo '<td>-</td>';
        echo '<td>' . htmlspecialchars($item['data'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td class="acoes-linha">';
        echo '<a class="mini" title="Abrir pasta" href="' . linkPara($item['rel']) . '">&#128194;</a>';
        echo $botaoCompartilhar;
        echo '</td>';

    } else {

        $ehTexto = ehTexto($item['nome'], $mime);

        // dados no atributo (escapados), sem JavaScript dentro do HTML
        $detalhes = ' class="mini ver-info" title="Ver informacoes" href="javascript:void(0)"'
            . ' data-rel="' . htmlspecialchars($item['rel'], ENT_QUOTES, 'UTF-8') . '"'
            . ' data-nome="' . $nome . '"'
            . ' data-tamanho="' . htmlspecialchars(tamanhoLegivel($item['tamanho']), ENT_QUOTES, 'UTF-8') . '"'
            . ' data-mime="' . htmlspecialchars($mime, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-data="' . htmlspecialchars($item['data'], ENT_QUOTES, 'UTF-8') . '"'
            . ' data-texto="' . ($ehTexto ? '1' : '0') . '"';

        echo '<td>' . iconeArquivo($item['caminho'], $item['nome']) . ' ' . $nome . '</td>';
        echo '<td>' . $rotuloTipo . '</td>';
        echo '<td>' . htmlspecialchars(tamanhoLegivel($item['tamanho']), ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($item['data'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td class="acoes-linha">';
        echo '<a' . $detalhes . '>&#128065;</a>';
        echo '<a class="mini" title="Baixar" href="app.php?download=' . rawurlencode($item['rel']) . '">&#11015;</a>';
        echo $botaoCompartilhar;
        echo '</td>';

    }

    echo '</tr>';
}

?>
            </tbody>
        </table>

        <div class="soltar<?php echo $vistaCompartilhados ? ' escondido' : ''; ?>" id="soltar">
            <div style="text-align:center">
                <div class="icone">&#8682;</div>
                <strong>Solte aqui para enviar</strong>
            </div>
        </div>

    </main>

</div>

<input type="file" id="seletor" multiple style="display:none">

<div class="fundo" id="fundo">
    <div class="janela">
        <div class="janela-topo">
            <span id="janelaTitulo"></span>
            <button class="btn" onclick="fecharJanela()">&times;</button>
        </div>
        <div class="janela-corpo" id="janelaCorpo"></div>
        <div class="janela-base">
            <a class="btn" id="janelaBaixar" href="#">&#11015; Baixar</a>
            <button class="btn laranja" onclick="fecharJanela()">Fechar</button>
        </div>
    </div>
</div>

<div class="fundo" id="fundoCompartilhar">
    <div class="janela" style="width:min(640px,100%)">
        <div class="janela-topo">
            <span id="janelaCompartilharTitulo">Compartilhar</span>
            <button class="btn" onclick="fecharCompartilhar()">&times;</button>
        </div>

        <div class="janela-corpo">
            <p class="compartilhar-aviso" id="avisoCompartilhar"></p>

            <div class="link-novo escondido" id="linkCriado">
                <div class="cresce">
                    <strong>Link criado</strong>
                    <span class="link-curto" id="linkCriadoUrl"></span>
                    <small id="linkCriadoValidade"></small>
                </div>
                <button class="btn" type="button"
                        onclick="copiarTexto(document.getElementById('linkCriadoUrl').textContent)">Copiar</button>
            </div>

            <p class="ajuda">
                Para <strong>descompartilhar</strong>: <strong>Revogar</strong> tira um link,
                <strong>Remover</strong> tira o acesso de uma pessoa, e o botao
                <strong>Descompartilhar tudo</strong> no fim da tela tira links e acessos de uma vez.
            </p>

            <div class="abas">
                <button class="aba ativa" id="abaLink" onclick="trocarAba('link')">Link publico</button>
                <button class="aba" id="abaPessoas" onclick="trocarAba('pessoas')">Pessoas</button>
            </div>

            <div id="painelLink">
                <form method="post" action="app.php" id="formLink">
                    <input type="hidden" name="acao" value="criar_link">
                    <input type="hidden" name="item" id="itemLink">
                    <input type="hidden" name="dir" value="<?php echo htmlspecialchars($relativo, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="ver" value="<?php echo $ver; ?>">
                    <?php echo campoCsrf(); ?>

                    <label class="campo">
                        <input type="checkbox" name="usar_senha" id="usarSenha" checked onchange="alternarUsoSenha()">
                        Usar senha
                    </label>

                    <label class="rotulo" for="senhaLink">Senha do link</label>
                    <input type="text" id="senhaLink" name="senha_link" maxlength="<?php echo (int)TAMANHO_MAX_SENHA; ?>"
                           autocomplete="off" placeholder="Digite a senha (obrigatoria)">

                    <label class="rotulo" for="validade">Validade</label>
                    <select id="validade" name="validade">
                        <option value="60" selected>1 hora</option>
                        <option value="1440">24 horas</option>
                        <option value="10080">7 dias</option>
                        <option value="43200">30 dias</option>
                        <option value="0">Sem validade</option>
                    </select>

                    <label class="rotulo" for="tituloLink">Titulo (opcional)</label>
                    <input type="text" id="tituloLink" name="titulo" maxlength="120" placeholder="Como o pessoa vera o link">

                    <button class="btn laranja" type="submit" style="margin-top:14px">Gerar link</button>
                </form>

                <div class="sub-titulo">Links ja gerados</div>
                <div id="listaLinks" class="lista-links">Nenhum link ainda.</div>
            </div>

            <div id="painelPessoas" class="escondido">
                <form method="post" action="app.php" id="formConceder">
                    <input type="hidden" name="acao" value="conceder">
                    <input type="hidden" name="item" id="itemPessoa">
                    <input type="hidden" name="dir" value="<?php echo htmlspecialchars($relativo, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="ver" value="<?php echo $ver; ?>">
                    <?php echo campoCsrf(); ?>

                    <label class="rotulo" for="usuarioAlvo">Pessoa</label>
                    <select id="usuarioAlvo" name="usuario">
<?php foreach (listarUsuarios() as $u): ?>
<?php if ($u['usuario'] === $_SESSION['usuario']) continue; ?>
                        <option value="<?php echo htmlspecialchars($u['usuario'], ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($u['nome'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($u['usuario'], ENT_QUOTES, 'UTF-8'); ?>)
                        </option>
<?php endforeach; ?>
                    </select>

                    <label class="rotulo" for="nivelAcesso">Permissao</label>
                    <select id="nivelAcesso" name="nivel">
                        <option value="leitura" selected>Somente ver e baixar</option>
                        <option value="escrita">Ver, baixar e alterar</option>
                    </select>

                    <button class="btn laranja" type="submit" style="margin-top:14px">Liberar acesso</button>
                </form>

                <div class="sub-titulo">Quem ja tem acesso</div>
                <div id="listaPermissoes" class="lista-links">Ninguém ainda.</div>
            </div>
        </div>

        <div class="janela-base">
            <button class="btn perigo" type="button" id="botaoDescompartilharTudo" style="display:none;margin-right:auto"
                    onclick="descompartilhar(document.getElementById('itemLink').value)">Descompartilhar tudo</button>
            <button class="btn" onclick="fecharCompartilhar()">Fechar</button>
        </div>
    </div>
</div>

<script>

/* ---------- selecao ---------- */

// marca ou desmarca todas as caixas visiveis
function alternarTodas(caixa) {
    var caixas = document.querySelectorAll('input.marca');

    for (var i = 0; i < caixas.length; i++) {
        caixas[i].checked = caixa.checked;
        atualizarCartao(caixas[i]);
    }

    atualizarSelecao();
}

// pinta o card conforme a caixa
function atualizarCartao(caixa) {
    var cartao = caixa.closest ? caixa.closest('.cartao') : null;

    if (cartao) {
        cartao.classList.toggle('selecionado', caixa.checked);
    }
}

// mostra os botoes de download/excluir quando ha selecao
function atualizarSelecao() {
    var marcadas = document.querySelectorAll('input.marca:checked').length;
    var texto = marcadas + (marcadas === 1 ? ' selecionado' : ' selecionados');

    var contagem = document.getElementById('contagemSelecionados');
    if (contagem) {
        contagem.textContent = texto;
    }

    var opcoes = document.getElementById('opcoesSelecao');
    if (opcoes) {
        opcoes.style.display = marcadas > 0 ? 'flex' : 'none';
    }

    var todos = document.getElementById('selecionarTodos');
    if (todos) {
        var caixas = document.querySelectorAll('input.marca');
        todos.checked = marcadas > 0 && marcadas === caixas.length;
    }
}

function contar() {
    var visiveis = document.querySelectorAll('.cartao:not([style*="display: none"])').length;
    document.getElementById('contagem').textContent = visiveis + ' item(ns)';
    atualizarSelecao();
}

/* ---------- cartas: 1 clique marca, 2 cliques abre ---------- */

function cartaoDe(alvo) {
    while (alvo && alvo !== document) {
        if (alvo.classList && alvo.classList.contains('cartao')) {
            return alvo;
        }
        alvo = alvo.parentNode;
    }
    return null;
}

function marcarCartao(cartao) {
    var caixa = cartao.querySelector('input.marca');

    if (caixa) {
        caixa.checked = !caixa.checked;
    }

    cartao.classList.toggle('selecionado', cartao.querySelector('input.marca').checked);
}

function abrirCartao(cartao) {
    if (cartao.getAttribute('data-pasta') === '1') {
        location.href = cartao.getAttribute('data-origem');
        return;
    }

    mostrarDetalhes(
        cartao.getAttribute('data-rel'),
        cartao.getAttribute('data-nome-arquivo'),
        cartao.getAttribute('data-tamanho'),
        cartao.getAttribute('data-mime'),
        cartao.getAttribute('data-data'),
        cartao.getAttribute('data-texto') === '1'
    );
}

document.addEventListener('click', function (e) {

    if (e.target.closest && e.target.closest('.acoes-cartao')) {
        return;
    }

    // a caixa tem o proprio comportamento, nao alterna de novo
    if (e.target.classList && e.target.classList.contains('marca')) {
        return;
    }

    var cartao = cartaoDe(e.target);

    if (cartao) {
        marcarCartao(cartao);
        contar();
    }
});

document.addEventListener('dblclick', function (e) {

    if (e.target.closest && e.target.closest('.acoes-cartao')) {
        return;
    }

    var cartao = cartaoDe(e.target);

    if (cartao) {
        abrirCartao(cartao);
    }
});

/* busca */
document.getElementById('busca').addEventListener('input', function () {
    var termo = this.value.toLowerCase();
    var cartoes = document.querySelectorAll('.cartao');

    for (var i = 0; i < cartoes.length; i++) {
        var nome = (cartoes[i].getAttribute('data-nome') || '').toLowerCase();
        cartoes[i].style.display = (termo === '' || nome.indexOf(termo) !== -1) ? '' : 'none';
    }

    contar();
});

/* ---------- nomes: limites e caracteres ---------- */

var TAMANHO_MAX_NOME = <?php echo (int)TAMANHO_MAX_NOME; ?>;
var PERMITIDOS = <?php echo json_encode(CARACTERES_NOME); ?>;

/* mesma regra do servidor, aplicada antes de enviar */
var RE_NOME = null;

try {
    RE_NOME = new RegExp('^(?:' + PERMITIDOS + ')+$', 'u');
} catch (e) {
    RE_NOME = null;
}

function conferirNome(nome) {

    if (!nome || nome === null) {
        return false;
    }

    nome = nome.replace(/\s+/g, ' ').trim();

    if (nome === '' || nome === '.' || nome === '..') {
        return false;
    }

    if (nome.indexOf('/') !== -1 || nome.indexOf('\\') !== -1) {
        return false;
    }

    nome = nome.replace(/[:*?"<>|]/g, '').trim();

    if (nome === '' || nome.length > TAMANHO_MAX_NOME) {
        return false;
    }

    /* se o navegador nao entendeu a regra, deixa o servidor decidir */
    if (!RE_NOME) {
        return true;
    }

    return RE_NOME.test(nome);
}

function avisarNomeInvalido() {
    alert(
        'Nome invalido.\n\n' +
        'Use ate ' + TAMANHO_MAX_NOME + ' caracteres.\n' +
        'Nao use: / \\ : * ? " < > | nem apenas pontos.'
    );
}

/* nova pasta */
document.getElementById('formPasta').addEventListener('submit', function (e) {
    var nome = prompt('Nome da nova pasta:');

    if (!nome) {
        e.preventDefault();
        return;
    }

    if (!conferirNome(nome)) {
        avisarNomeInvalido();
        e.preventDefault();
        return;
    }

    document.getElementById('nomePasta').value = nome.trim();
});

/* renomear */
document.getElementById('formRenomear').addEventListener('submit', function (e) {
    var marcadas = document.querySelectorAll('input.marca:checked');

    if (marcadas.length !== 1) {
        alert('Marque exatamente 1 item para renomear.');
        e.preventDefault();
        return;
    }

    var caminho = marcadas[0].value;
    var atual = caminho.split('/').pop();
    var novo = prompt('Novo nome:', atual);

    if (!novo || novo === atual) {
        e.preventDefault();
        return;
    }

    if (!conferirNome(novo)) {
        avisarNomeInvalido();
        e.preventDefault();
        return;
    }

    document.getElementById('itemRenomear').value = caminho;
    document.getElementById('novoNome').value = novo.trim();
});

/* botao de ver informacoes nas linhas da tabela */
document.addEventListener('click', function (e) {

    var botao = e.target.closest ? e.target.closest('.ver-info') : null;

    if (botao) {
        e.preventDefault();
        mostrarDetalhes(
            botao.getAttribute('data-rel'),
            botao.getAttribute('data-nome'),
            botao.getAttribute('data-tamanho'),
            botao.getAttribute('data-mime'),
            botao.getAttribute('data-data'),
            botao.getAttribute('data-texto') === '1'
        );
        return;
    }

    if (e.target.classList && e.target.classList.contains('marca')) {
        atualizarCartao(e.target);
        atualizarSelecao();
    }
});

/* ---------- compartilhar ---------- */

var csrfCompartilhar = <?php echo json_encode(tokenCsrf()); ?>;
var dirCompartilhar = <?php echo json_encode($relativo); ?>;
var verCompartilhar = <?php echo json_encode($ver); ?>;
var atalhoCompartilhar = <?php echo json_encode($atalho); ?>;
var baseSistema = <?php echo json_encode(urlDoSistema()); ?>;

/* aviso verde (deu certo) ou vermelho (nao deu) dentro do modal */
function mostrarAviso(texto, erro) {
    var aviso = document.getElementById('avisoCompartilhar');
    aviso.textContent = texto;
    aviso.classList.toggle('erro', !!erro);
    aviso.style.display = texto ? 'block' : 'none';
}

function trocarAba(qual) {
    var link = (qual === 'link');
    document.getElementById('painelLink').classList.toggle('escondido', !link);
    document.getElementById('painelPessoas').classList.toggle('escondido', link);
    document.getElementById('abaLink').classList.toggle('ativa', link);
    document.getElementById('abaPessoas').classList.toggle('ativa', !link);
}

function alternarUsoSenha() {
    var usar = document.getElementById('usarSenha').checked;
    var campo = document.getElementById('senhaLink');

    campo.disabled = !usar;
    campo.placeholder = usar ? 'Digite a senha (obrigatoria)' : '';
}

function fecharCompartilhar() {
    document.getElementById('fundoCompartilhar').classList.remove('aberto');
}

/* caminho em branco: usa o item que esta marcado na listagem */
function abrirCompartilhar(caminho) {

    if (!caminho) {

        var marcadas = Array.prototype.slice.call(document.querySelectorAll('.marca:checked'));

        if (marcadas.length === 0) {
            alert('Marque um item para compartilhar.');
            return;
        }

        if (marcadas.length > 1) {
            alert('Compartilhe um item por vez.');
            return;
        }

        caminho = marcadas[0].value;
    }

    document.getElementById('itemLink').value = caminho;
    document.getElementById('itemPessoa').value = caminho;

    var partes = caminho.split('/');
    document.getElementById('janelaCompartilharTitulo').textContent = 'Compartilhar: ' + partes[partes.length - 1];

    mostrarAviso('', false);
    document.getElementById('linkCriado').classList.add('escondido');
    document.getElementById('senhaLink').value = '';
    document.getElementById('tituloLink').value = '';

    document.getElementById('listaLinks').textContent = 'Carregando...';
    document.getElementById('listaPermissoes').textContent = 'Carregando...';

    document.getElementById('fundoCompartilhar').classList.add('aberto');
    trocarAba('link');
    alternarUsoSenha();

    buscarCompartilhamento(caminho);
}

/* botoes de compartilhar e descompartilhar nas listas */
document.addEventListener('click', function (e) {

    var botao = e.target.closest ? e.target.closest('.acao-compartilhar') : null;

    if (botao) {
        e.preventDefault();
        abrirCompartilhar(botao.getAttribute('data-compartilhar'));
        return;
    }

    var tirar = e.target.closest ? e.target.closest('.acao-descompartilhar') : null;

    if (tirar) {
        e.preventDefault();
        descompartilhar(tirar.getAttribute('data-compartilhar'));
        return;
    }
});

function mostrarLinkCriado(j) {

    document.getElementById('linkCriadoUrl').textContent = j.url;
    document.getElementById('linkCriadoValidade').textContent = j.expira
        ? ('vale ate ' + j.expira)
        : 'sem validade';

    document.getElementById('linkCriado').classList.remove('escondido');
}

/*
 * Manda uma acao de compartilhamento e mostra o resultado na propria tela.
 * recarregar = true recarrega a pagina (usado quando algo sai da lista).
 */
function acaoCompartilhar(campos, caminho, recarregar) {

    var dados = new URLSearchParams();

    dados.append('csrf', csrfCompartilhar);
    dados.append('dir', dirCompartilhar);
    dados.append('ver', verCompartilhar);
    dados.append('atalho', atalhoCompartilhar);

    Object.keys(campos).forEach(function (k) { dados.append(k, campos[k]); });

    return fetch('app.php', {
        method: 'POST',
        body: dados,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(function (r) {

            if (r.status === 403) {
                throw new Error('Sua sessao expirou. Atualize a pagina.');
            }

            return r.json();
        })
        .then(function (j) {

            if (recarregar) {
                location.reload();
                return;
            }

            mostrarAviso(j.aviso || (j.ok ? 'Feito.' : 'Nao foi possivel.'), !j.ok);

            if (j.ok && j.url) {
                mostrarLinkCriado(j);
            }

            buscarCompartilhamento(caminho);
        })
        .catch(function (e) {
            mostrarAviso(e.message || 'Nao foi possivel completar a acao.', true);
        });
}

function descompartilhar(caminho) {

    var nome = caminho.split('/').pop();

    if (!confirm('Descompartilhar "' + nome + '"?\n\n'
        + 'Sao tirados todos os links publicos e todos os acessos liberados para este item.\n'
        + 'O item continua no disco.')) {
        return;
    }

    acaoCompartilhar({ acao: 'revogar_tudo', item: caminho }, caminho, true);
}

function buscarCompartilhamento(caminho) {

    fetch('app.php?action=compartilhar&caminho=' + encodeURIComponent(caminho), {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(function (r) { return r.json(); })
        .then(function (j) {

            /* links */
            var alvo = document.getElementById('listaLinks');
            alvo.innerHTML = '';

            if (!j.ok) {
                alvo.textContent = 'Sem permissao para ver este compartilhamento.';
                document.getElementById('listaPermissoes').textContent = 'Sem permissao.';
                return;
            }

            if (!j.links || !j.links.length) {
                alvo.textContent = 'Nenhum link ainda.';
            } else {
                j.links.forEach(function (l) {

                    var urlCompleta = baseSistema + '/link.php?c=' + l.codigo;

                    var linha = document.createElement('div');
                    linha.className = 'item-link-share' + (l.expirado ? ' expirado' : '');

                    var info = document.createElement('div');
                    info.className = 'cresce';

                    var titulo = document.createElement('span');
                    titulo.textContent = l.titulo || l.caminho;

                    var url = document.createElement('span');
                    url.className = 'link-curto';
                    url.textContent = urlCompleta;

                    var validade = document.createElement('span');
                    validade.className = 'link-curto';
                    validade.textContent = l.validade
                        + ' · ' + (l.tem_senha ? 'com senha' : 'sem senha')
                        + ' · ' + l.acessos + ' acesso(s)';

                    info.appendChild(titulo);
                    info.appendChild(url);
                    info.appendChild(validade);

                    var abrir = document.createElement('a');
                    abrir.className = 'btn';
                    abrir.textContent = 'Abrir';
                    abrir.href = urlCompleta;
                    abrir.target = '_blank';
                    abrir.rel = 'noopener';

                    var copiar = document.createElement('button');
                    copiar.type = 'button';
                    copiar.className = 'btn';
                    copiar.textContent = 'Copiar';
                    copiar.title = 'Copiar o endereco do link';
                    copiar.onclick = function () { copiarTexto(urlCompleta); };

                    var revogar = document.createElement('button');
                    revogar.type = 'button';
                    revogar.className = 'btn perigo';
                    revogar.textContent = 'Revogar';
                    revogar.onclick = function () { revogarLink(caminho, l.codigo); };

                    linha.appendChild(info);
                    linha.appendChild(abrir);
                    linha.appendChild(copiar);
                    linha.appendChild(revogar);
                    alvo.appendChild(linha);
                });
            }

            /* permissoes */
            var alvo2 = document.getElementById('listaPermissoes');
            alvo2.innerHTML = '';

            if (!j.permissoes || !j.permissoes.length) {
                alvo2.textContent = 'Ninguém ainda.';
            } else {
                j.permissoes.forEach(function (p) {

                    var linha = document.createElement('div');
                    linha.className = 'item-link-share';

                    var info = document.createElement('div');
                    info.className = 'cresce';
                    info.textContent = p.nome + ' (' + p.usuario + ') - ' + (p.nivel === 'escrita' ? 'ver e alterar' : 'somente ver');

                    var revogar = document.createElement('button');
                    revogar.type = 'button';
                    revogar.className = 'btn perigo';
                    revogar.textContent = 'Remover';
                    revogar.onclick = function () { revogarAcesso(caminho, p.usuario); };

                    linha.appendChild(info);
                    linha.appendChild(revogar);
                    alvo2.appendChild(linha);
                });
            }

            /* so mostra o botao de desfazer tudo quando ha algo ligado */
            var btnTudo = document.getElementById('botaoDescompartilharTudo');

            if (btnTudo) {
                var temAlgo = (j.links && j.links.length) || (j.permissoes && j.permissoes.length);
                btnTudo.style.display = temAlgo ? 'inline-block' : 'none';
            }
        })
        .catch(function () {
            document.getElementById('listaLinks').textContent = 'Nao foi possivel carregar.';
        });
}

function copiarTexto(texto) {

    if (navigator.clipboard) {
        navigator.clipboard.writeText(texto);
        mostrarAviso('Link copiado.', false);
        return;
    }

    var campo = document.createElement('textarea');
    campo.value = texto;
    document.body.appendChild(campo);
    campo.select();
    try { document.execCommand('copy'); } catch (e) {}
    campo.remove();
    mostrarAviso('Link copiado.', false);
}

function revogarLink(caminho, codigo) {
    if (!confirm('Revogar este link? Quem tiver o endereco nao acessa mais.')) return;
    acaoCompartilhar({ acao: 'revogar_link', codigo: codigo }, caminho, true);
}

function revogarAcesso(caminho, usuario) {
    if (!confirm('Tirar o acesso de ' + usuario + ' deste item?')) return;
    acaoCompartilhar({ acao: 'revogar_acesso', item: caminho, usuario: usuario }, caminho, true);
}

/* os dois formularios do modal respondem na tela, sem recarregar a pagina */
document.getElementById('formLink').addEventListener('submit', function (e) {

    e.preventDefault();

    var caminho = document.getElementById('itemLink').value;

    if (!caminho) {
        mostrarAviso('Escolha um item para compartilhar.', true);
        return;
    }

    var usarSenha = document.getElementById('usarSenha').checked;
    var senha = document.getElementById('senhaLink').value.trim();

    if (usarSenha && senha === '') {
        mostrarAviso('Escreva a senha do link ou desmarque "Usar senha".', true);
        document.getElementById('senhaLink').focus();
        return;
    }

    acaoCompartilhar({
        acao: 'criar_link',
        item: caminho,
        usar_senha: usarSenha ? '1' : '',
        senha_link: usarSenha ? senha : '',
        validade: document.getElementById('validade').value,
        titulo: document.getElementById('tituloLink').value,
        max_acessos: '0'
    }, caminho, false);

    document.getElementById('senhaLink').value = '';
    document.getElementById('tituloLink').value = '';
});

document.getElementById('formConceder').addEventListener('submit', function (e) {

    e.preventDefault();

    var caminho = document.getElementById('itemPessoa').value;

    if (!caminho) {
        mostrarAviso('Escolha um item para compartilhar.', true);
        return;
    }

    acaoCompartilhar({
        acao: 'conceder',
        item: caminho,
        usuario: document.getElementById('usuarioAlvo').value,
        nivel: document.getElementById('nivelAcesso').value
    }, caminho, false);
});

/* ---------- confirmar exclusao ---------- */

function confirmarExclusao() {
    var marcadas = document.querySelectorAll('input.marca:checked').length;

    if (marcadas === 0) {
        alert('Marque um ou mais itens para excluir.');
        return false;
    }

    return confirm('Excluir ' + marcadas + (marcadas === 1 ? ' item?' : ' itens?'));
}

function mostrarDetalhes(rel, nome, tamanho, mime, data, ehArquivoTexto) {
    var imagem = '';

    if (mime && mime.indexOf('image/') === 0) {
        imagem = '<img class="previa" src="app.php?miniatura=' + encodeURIComponent(rel) + '&g=1" alt="">';
    }

    document.getElementById('janelaTitulo').textContent = nome;
    document.getElementById('janelaCorpo').innerHTML = imagem +
        '<dl class="info-lista">' +
        '<dt>Tipo</dt><dd>' + escapar(mime) + '</dd>' +
        '<dt>Tamanho</dt><dd>' + escapar(tamanho) + '</dd>' +
        '<dt>Modificado</dt><dd>' + escapar(data) + '</dd>' +
        '<dt>Local</dt><dd>files/' + escapar(rel) + '</dd>' +
        '</dl>' +
        '<div id="areaTexto"></div>';

    document.getElementById('janelaBaixar').href = 'app.php?download=' + encodeURIComponent(rel);
    document.getElementById('fundo').classList.add('aberto');

    if (ehArquivoTexto) {
        var area = document.getElementById('areaTexto');
        area.innerHTML = '<p class="carregando">Carregando conteudo...</p>';

        fetch('app.php?conteudo=' + encodeURIComponent(rel))
            .then(function (r) {
                if (!r.ok) {
                    throw new Error('erro');
                }
                return r.text();
            })
            .then(function (texto) {
                area.innerHTML = '<h4 class="titulo-texto">Conteudo do arquivo</h4><pre class="texto">' + escapar(texto) + '</pre>';
            })
            .catch(function () {
                area.innerHTML = '<p class="carregando">Nao foi possivel carregar o conteudo.</p>';
            });
    }
}

function escapar(texto) {
    return String(texto === null || texto === undefined ? '' : texto)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/* menu do usuario */
function alternarConta() {
    var menu = document.getElementById('menuConta');
    menu.classList.toggle('aberto');
}

/* expandir / recolher a arvore de pastas */
function alternarRamo(id) {
    var alvo = document.getElementById(id);

    if (!alvo) {
        return;
    }

    var botao = alvo.previousElementSibling.querySelector('.dobrador');
    var aberto = alvo.style.display !== 'none';

    if (aberto) {
        alvo.style.display = 'none';
        botao.innerHTML = '+';
    } else {
        alvo.style.display = '';
        botao.innerHTML = '&#8722;';
    }
}

document.addEventListener('click', function (e) {
    if (!e.target.closest('.conta')) {
        document.getElementById('menuConta').classList.remove('aberto');
    }
});

function fecharJanela() {
    document.getElementById('fundo').classList.remove('aberto');
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        fecharJanela();
    }
});

/* upload por botao e por arrastar */
function enviar(arquivos) {
    if (!arquivos || arquivos.length === 0) {
        return;
    }

    var dados = new FormData();

    for (var i = 0; i < arquivos.length; i++) {
        dados.append('arquivos[]', arquivos[i]);
    }

    dados.append('acao', 'enviar');
    dados.append('dir', <?php echo json_encode($relativo); ?>);
    dados.append('csrf', <?php echo json_encode(tokenCsrf()); ?>);

    fetch('app.php', { method: 'POST', body: dados, credentials: 'same-origin' })
        .then(function (r) {
            if (r.status === 403) {
                alert('Sessao expirada. Atualize a pagina e tente de novo.');
                return;
            }
            location.reload();
        });
}

document.getElementById('seletor').addEventListener('change', function () {
    enviar(this.files);
    this.value = '';
});

var arrastando = 0;

function temArquivo(e) {
    return e.dataTransfer && e.dataTransfer.types && e.dataTransfer.types.indexOf('Files') !== -1;
}

window.addEventListener('dragenter', function (e) {
    if (!temArquivo(e)) return;
    e.preventDefault();
    arrastando++;
    document.getElementById('soltar').classList.add('visivel');
});

window.addEventListener('dragover', function (e) {
    if (!temArquivo(e)) return;
    e.preventDefault();
});

window.addEventListener('dragleave', function () {
    arrastando--;
    if (arrastando <= 0) {
        arrastando = 0;
        document.getElementById('soltar').classList.remove('visivel');
    }
});

window.addEventListener('drop', function (e) {
    if (!temArquivo(e)) return;
    e.preventDefault();
    arrastando = 0;
    document.getElementById('soltar').classList.remove('visivel');
    enviar(e.dataTransfer.files);
});

contar();

</script>

</body>
</html>
