<?php
/*
 * Teste completo das correcoes de seguranca (29 checagens).
 *
 * PRIMEIRO: leia o README desta pasta e apague testes/ depois
 * da instalacao.
 *
 * Uso (com o servidor local rodando):
 *     php S1DISCO/testes/teste_completo.php
 *
 * Precisa de ext-curl e de uma instalacao local com os usuarios
 * criados pelo instalar_usuarios.php (senha 123456).
 * Apaga os arquivos e pastas que ele mesmo cria.
 */

$base = 'http://127.0.0.1:8080/S1DISCO';
$falhas = 0;

function req($url, $post = null, $jar = null, $files = null)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
    ));

    if ($files !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $files);
    } elseif ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $out = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    /* o PHP guarda em cache o resultado de is_dir/file_exists */
    clearstatcache(true);

    return array($code, $out);
}

function entrar($usuario, $senha = '123456')
{
    global $base;

    $jar = tempnam(sys_get_temp_dir(), 't');

    req($base . '/login.php', array('usuario' => $usuario, 'senha' => $senha), $jar);

    return $jar;
}

function checar($rotulo, $ok, $detalhe = '')
{
    global $falhas;

    if (!$ok) {
        $falhas++;
    }

    echo ($ok ? '  OK   ' : '  FALHA') . ' | ' . $rotulo . ($detalhe !== '' ? ' -> ' . $detalhe : '') . PHP_EOL;
}

/* ---------- 1. login ---------- */
echo "1. LOGIN E SESSAO" . PHP_EOL;

$jar = entrar('helio');

list($c, $t) = req($base . '/app.php', null, $jar);
checar('admin entra no app', $c === 200, "http $c");

$csrf = null;
if (preg_match('/name="csrf" value="([^"]+)"/', (string)$t, $m)) {
    $csrf = $m[1];
}
checar('formulario traz token CSRF', $csrf !== null && strlen($csrf) === 64);

/* ---------- 2. CSRF ---------- */
echo PHP_EOL . "2. CSRF" . PHP_EOL;

list($c) = req($base . '/app.php', array('acao' => 'nova_pasta', 'dir' => 'general', 'nome' => 'semtoken'), $jar);
checar('POST sem token e recusado', $c === 403, "http $c");

list($c) = req($base . '/app.php', array('csrf' => 'invalido', 'acao' => 'nova_pasta', 'dir' => 'general', 'nome' => 'tokenruim'), $jar);
checar('POST com token errado e recusado', $c === 403, "http $c");

list($c) = req($base . '/app.php', array('csrf' => $csrf, 'acao' => 'nova_pasta', 'dir' => 'general', 'nome' => 'Pasta Teste CSRF'), $jar);
$pasta = __DIR__ . '/../files/general/Pasta Teste CSRF';
checar('POST com token valido cria a pasta', $c === 302 && is_dir($pasta));

/* ---------- 3. validacao de nome ---------- */
echo PHP_EOL . "3. VALIDACAO DE NOME" . PHP_EOL;

$nomes = array('../fora', '..', 'a/b', str_repeat('x', 150));
foreach ($nomes as $ruim) {
    req($base . '/app.php', array('csrf' => $csrf, 'acao' => 'nova_pasta', 'dir' => 'general', 'nome' => $ruim), $jar);
}
checar('nome com .. nao cria fora da pasta', !is_dir(__DIR__ . '/../files/fora'));
checar('nome gigante nao cria', !is_dir(__DIR__ . '/../files/general/' . str_repeat('x', 150)));

/* ---------- 4. renomear, mover, excluir ---------- */
echo PHP_EOL . "4. RENOMEAR / EXCLUIR" . PHP_EOL;

req($base . '/app.php', array('csrf' => $csrf, 'acao' => 'renomear', 'dir' => 'general', 'item' => 'general/Pasta Teste CSRF', 'nome' => 'Pasta Renomeada'), $jar);
checar('renomear funciona', is_dir(__DIR__ . '/../files/general/Pasta Renomeada'));

req($base . '/app.php', array('csrf' => $csrf, 'acao' => 'excluir', 'dir' => 'general', 'sel' => array('general/Pasta Renomeada')), $jar);
checar('excluir funciona', !is_dir(__DIR__ . '/../files/general/Pasta Renomeada'));

/* ---------- 5. upload ---------- */
echo PHP_EOL . "5. UPLOAD" . PHP_EOL;

$tmp = tempnam(sys_get_temp_dir(), 'up') . '.txt';
file_put_contents($tmp, 'conteudo');

$fields = array('csrf' => $csrf, 'acao' => 'enviar', 'dir' => 'general', 'arquivos[]' => new CURLFile($tmp, 'text/plain', 'auditoria.txt'));
list($c) = req($base . '/app.php', null, $jar, $fields);
checar('upload de txt cria o arquivo', is_file(__DIR__ . '/../files/general/auditoria.txt'), "http $c");

$phar = tempnam(sys_get_temp_dir(), 'ph') . '.phar';
file_put_contents($phar, '<?php echo 1;');
$fields = array('csrf' => $csrf, 'acao' => 'enviar', 'dir' => 'general', 'arquivos[]' => new CURLFile($phar, 'text/plain', 'mal.phar'));
req($base . '/app.php', null, $jar, $fields);
checar('upload de .phar e aceito (gestor de disco)', is_file(__DIR__ . '/../files/general/mal.phar'));

/* o arquivo fica guardado, mas o servidor nao o entrega nem executa */
list($c) = req($base . '/files/general/mal.phar', null, null);
checar('servidor nao entrega o que esta dentro de files/', $c === 403, "http $c");

@unlink(__DIR__ . '/../files/general/mal.phar');
@unlink($tmp);
@unlink($phar);

/* ---------- 6. zip ---------- */
echo PHP_EOL . "6. ZIP" . PHP_EOL;

$fields = array(
    'csrf' => $csrf,
    'acao' => 'baixar_selecionados',
    'dir' => 'general',
    'sel[0]' => 'general/auditoria.txt',
    'sel[1]' => 'general/teste.txt',
);
list($c, $zip) = req($base . '/app.php', null, $jar, $fields);
$zipOk = ($c === 200 && strncmp((string)$zip, "PK", 2) === 0);
checar('gera zip com os selecionados', $zipOk, "http $c");

if ($zipOk) {
    $arqZip = tempnam(sys_get_temp_dir(), 'z') . '.zip';
    file_put_contents($arqZip, $zip);
    $za = new ZipArchive();
    $za->open($arqZip);
    $nomes = array();
    for ($i = 0; $i < $za->numFiles; $i++) {
        $nomes[] = $za->getNameIndex($i);
    }
    $za->close();
    @unlink($arqZip);
    $temFora = false;
    foreach ($nomes as $n) {
        if (strpos($n, '..') !== false || strpos($n, '/') === 0) {
            $temFora = true;
        }
    }
    checar('entradas do zip sem ".."', !$temFora, implode(', ', $nomes));
}

/* ---------- 7. permissoes ---------- */
echo PHP_EOL . "7. PERMISSOES" . PHP_EOL;

$jarM = entrar('miguel');

list($c) = req($base . '/app.php?dir=users%2Fhelio', null, $jarM);
checar('miguel nao entra na pasta do admin', $c === 302, "http $c");

list($c) = req($base . '/app.php?miniatura=users%2Fhelio%2FDocumentos', null, $jarM);
checar('miguel nao ve miniatura alheia', $c === 403 || $c === 302, "http $c");

list($c) = req($base . '/app.php?avatar=helio.jpg', null, $jarM);
checar('miguel nao ve a foto do admin', $c === 403, "http $c");

list($c) = req($base . '/app.php?dir=users%2Fmiguel', null, $jarM);
checar('miguel entra na propria pasta', $c === 200, "http $c");

list($c, $t) = req($base . '/app.php', null, $jarM);
checar('miguel ve "Minha pasta"', strpos((string)$t, 'Minha pasta') !== false);

list($c, $t) = req($base . '/app.php', null, $jarM);
checar('miguel nao ve a pasta users', strpos((string)$t, '>users<') === false);

/* ---------- 8. gerenciar ---------- */
echo PHP_EOL . "8. GERENCIAR USUARIOS" . PHP_EOL;

list($c) = req($base . '/gerenciar.php', null, $jarM);
checar('miguel nao abre gerenciar', $c === 302, "http $c");

list($c, $t) = req($base . '/gerenciar.php', null, $jar);
checar('admin abre gerenciar', $c === 200, "http $c");
checar('admin ve o caminho do banco', strpos((string)$t, '.s1disco_') !== false);
checar('avisa senha fraca', strpos((string)$t, 'Senha muito fraca') !== false);

/* ---------- 9. auditoria ---------- */
echo PHP_EOL . "9. AUDITORIA" . PHP_EOL;

/* o banco pode ter mudado de lugar (PASTA_DADOS); usa o proprio sistema */
require_once __DIR__ . '/../usuarios.php';
iniciarSessaoUsuarios();
$arquivoBanco = arquivoUsuarios();

$achou = false;
$pdo = new PDO('sqlite:' . $arquivoBanco);
$total = $pdo->query('SELECT count(*) FROM auditoria')->fetchColumn();
foreach ($pdo->query('SELECT acao FROM auditoria ORDER BY id DESC LIMIT 8') as $linha) {
    if (in_array($linha['acao'], array('nova_pasta', 'renomear', 'excluir', 'excluir_pasta', 'enviar'))) {
        $achou = true;
    }
}
checar('tabela auditoria foi criada', $total > 0, (int)$total . ' registro(s)');
checar('acoes destrutivas registradas', $achou);

/* ---------- 10. cabecalhos ---------- */
echo PHP_EOL . "10. CABECALHOS" . PHP_EOL;

$ch = curl_init($base . '/app.php');
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $jar,
    CURLOPT_HEADER => true,
    CURLOPT_NOBODY => false,
));
$cabec = curl_exec($ch);
curl_close($ch);
$cabec = (string)$cabec;

checar('X-Frame-Options', strpos($cabec, 'X-Frame-Options: DENY') !== false);
checar('Content-Security-Policy', strpos($cabec, 'Content-Security-Policy') !== false);
checar('X-Content-Type-Options', strpos($cabec, 'X-Content-Type-Options: nosniff') !== false);

/* ---------- resultado ---------- */
echo PHP_EOL . "=========================================" . PHP_EOL;
echo $falhas === 0 ? 'TODOS OS TESTES PASSARAM' : $falhas . ' TESTE(S) FALHARAM';
echo PHP_EOL;

@unlink(__DIR__ . '/../files/general/auditoria.txt');
@unlink($jar);
@unlink($jarM);