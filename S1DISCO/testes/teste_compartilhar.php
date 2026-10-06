<?php
/*
 * Teste do compartilhamento: link publico, senha, permissao entre usuarios.
 *
 * PRIMEIRO: leia o README desta pasta e apague testes/ depois
 * da instalacao.
 *
 * Uso (com o servidor local rodando):
 *     php S1DISCO/testes/teste_compartilhar.php
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
    clearstatcache(true);

    return array($code, $out);
}

function entrar($usuario)
{
    global $base;
    $jar = tempnam(sys_get_temp_dir(), 'sc');
    req($base . '/login.php', array('usuario' => $usuario, 'senha' => '123456'), $jar);
    return $jar;
}

function csrf($jar, $dir = 'general')
{
    global $base;
    list($c, $t) = req($base . '/app.php?dir=' . urlencode($dir) . '&ver=grade', null, $jar);
    preg_match('/name="csrf" value="([^"]+)"/', (string)$t, $m);
    return isset($m[1]) ? $m[1] : '';
}

function checar($rotulo, $ok, $detalhe = '')
{
    global $falhas;
    if (!$ok) {
        $falhas++;
    }
    echo ($ok ? '  OK   ' : '  FALHA') . ' | ' . $rotulo . ($detalhe !== '' ? ' -> ' . $detalhe : '') . PHP_EOL;
}

echo "1. LINK PUBLICO" . PHP_EOL;

$admin = entrar('helio');
$token = csrf($admin);

$item = 'general/teste.txt';

/* fixture: o teste compartilha este arquivo */
$pastaGeneral = __DIR__ . '/../files/general';

if (!is_dir($pastaGeneral)) {
    @mkdir($pastaGeneral, 0755, true);
}

$criouFixture = !is_file($pastaGeneral . '/teste.txt');

if ($criouFixture) {
    /* conteudo fixo: o teste confere o download por este texto */
    file_put_contents($pastaGeneral . '/teste.txt', "conteudo\r\n");
}

list($c) = req($base . '/app.php', array(
    'csrf' => $token,
    'acao' => 'criar_link',
    'dir' => 'general',
    'ver' => 'grade',
    'item' => $item,
    'usar_senha' => '1',
    'senha_link' => 'segredo123',
    'validade' => '60',
    'titulo' => '',
), $admin);
checar('cria link com senha', $c === 302, "http $c");

list($c, $t) = req($base . '/app.php?action=compartilhar&caminho=' . urlencode($item), null, $admin);
$json = json_decode((string)$t, true);
checar('consulta devolve o link', $json && !empty($json['links']), count($json['links'] ?? array()) . ' link(s)');

$codigo = $json['links'][0]['codigo'];

/* acesso anonimo */
$anon = null;
list($c, $t) = req($base . '/link.php?c=' . $codigo, null, $anon);
checar('link abre sem login', $c === 200 && strpos((string)$t, 'Senha necessaria') !== false, "http $c");

/* senha errada */
$jar2 = tempnam(sys_get_temp_dir(), 's');
list($c, $t) = req($base . '/link.php?c=' . $codigo, array('senha' => 'errada'), $jar2);
checar('senha errada mostra o formulario', strpos((string)$t, 'Senha incorreta') !== false);

/* senha certa */
list($c) = req($base . '/link.php?c=' . $codigo, array('senha' => 'segredo123'), $jar2);
checar('senha certa libera', $c === 302, "http $c");

list($c, $t) = req($base . '/link.php?c=' . $codigo . '&baixar=1', null, $jar2);
checar('link baixa o arquivo', $c === 200 && strpos((string)$t, 'conteudo') !== false);

/* sem cookie volta a pedir senha */
list($c, $t) = req($base . '/link.php?c=' . $codigo);
checar('sem liberacao pede senha de novo', strpos((string)$t, 'Senha necessaria') !== false);

/* codigo invalido */
list($c) = req($base . '/link.php?c=naoexistemesmoassim');
checar('codigo inexistente da 404', $c === 404, "http $c");

echo PHP_EOL . "2. LINK DE PASTA (RECURSIVO)" . PHP_EOL;

$tokenAdmin = csrf($admin);
list($c) = req($base . '/app.php', array(
    'csrf' => $tokenAdmin,
    'acao' => 'criar_link',
    'dir' => '',
    'ver' => 'grade',
    'item' => 'users/helio',
    'validade' => '60',
), $admin);
checar('cria link de pasta', $c === 302, "http $c");

list($c, $t) = req($base . '/app.php?action=compartilhar&caminho=' . urlencode('users/helio'), null, $admin);
$json2 = json_decode((string)$t, true);
$codigoPasta = null;
foreach ($json2['links'] as $l) {
    if ($l['caminho'] === 'users/helio') {
        $codigoPasta = $l['codigo'];
    }
}

if ($codigoPasta) {
    list($c, $t) = req($base . '/link.php?c=' . $codigoPasta . '&baixar=1', null, null);
    $zip = (string)$t;
    checar('link de pasta gera ZIP', $c === 200 && strpos($zip, 'PK') === 0, "http $c");
    checar('ZIP tem as subpastas', strpos($zip, 'Documentos') !== false);
}

echo PHP_EOL . "3. REVOGAR LINK" . PHP_EOL;

list($c) = req($base . '/app.php', array(
    'csrf' => $tokenAdmin,
    'acao' => 'revogar_link',
    'dir' => 'general',
    'codigo' => $codigoPasta,
), $admin);
checar('revoga o link', $c === 302, "http $c");

list($c) = req($base . '/link.php?c=' . $codigoPasta);
checar('link revogado da 404', $c === 404, "http $c");

echo PHP_EOL . "4. PERMISSAO ENTRE USUARIOS" . PHP_EOL;

list($c) = req($base . '/app.php', array(
    'csrf' => $tokenAdmin,
    'acao' => 'conceder',
    'dir' => 'users/helio',
    'ver' => 'grade',
    'item' => 'users/helio',
    'usuario' => 'miguel',
    'nivel' => 'leitura',
), $admin);
checar('admin concede acesso ao miguel', $c === 302, "http $c");

$miguel = entrar('miguel');

list($c) = req($base . '/app.php?dir=' . urlencode('users/helio'), null, $miguel);
checar('miguel entra na pasta compartilhada', $c === 200, "http $c");

list($c, $t) = req($base . '/app.php?atalho=comigo', null, $miguel);
checar('miguel ve em "Compartilhados comigo"', strpos((string)$t, 'users/helio') !== false);

/* pasta irmã continua escondida */
list($c) = req($base . '/app.php?dir=' . urlencode('users/jana'), null, $miguel);
checar('miguel nao entra na pasta irma', $c === 302, "http $c");

/* leitura nao permite escrever */
$tokenMig = csrf($miguel, 'users/helio');
list($c) = req($base . '/app.php', array(
    'csrf' => $tokenMig,
    'acao' => 'nova_pasta',
    'dir' => 'users/helio',
    'nome' => 'NaoDeveria',
), $miguel);
checar('leitura nao permite criar pasta', !is_dir(__DIR__ . '/../files/users/helio/NaoDeveria'));

/* escrita permite */
list($c) = req($base . '/app.php', array(
    'csrf' => $tokenAdmin,
    'acao' => 'conceder',
    'dir' => 'users/helio',
    'ver' => 'grade',
    'item' => 'users/helio',
    'usuario' => 'miguel',
    'nivel' => 'escrita',
), $admin);

$tokenMig = csrf($miguel, 'users/helio');
list($c) = req($base . '/app.php', array(
    'csrf' => $tokenMig,
    'acao' => 'nova_pasta',
    'dir' => 'users/helio',
    'nome' => 'PastaDoMiguel',
), $miguel);
checar('escrita permite criar pasta', is_dir(__DIR__ . '/../files/users/helio/PastaDoMiguel'), "http $c");

/* revogar */
list($c) = req($base . '/app.php', array(
    'csrf' => $tokenAdmin,
    'acao' => 'revogar_acesso',
    'dir' => 'users/helio',
    'item' => 'users/helio',
    'usuario' => 'miguel',
), $admin);

list($c) = req($base . '/app.php?dir=' . urlencode('users/helio'), null, $miguel);
checar('apos revogar, perde o acesso', $c === 302, "http $c");

/* limpeza */
@rmdir(__DIR__ . '/../files/users/helio/PastaDoMiguel');

if (!empty($criouFixture)) {
    @unlink(__DIR__ . '/../files/general/teste.txt');
}

/* revoga tudo que este teste criou no banco */
foreach (array($item, 'users/helio') as $caminho) {
    list(, $json) = req($base . '/app.php?action=compartilhar&caminho=' . urlencode($caminho), null, $admin);
    $resto = json_decode((string)$json, true);

    if (!is_array($resto) || empty($resto['links'])) {
        continue;
    }

    foreach ($resto['links'] as $l) {
        req($base . '/app.php', array(
            'csrf' => $tokenAdmin,
            'acao' => 'revogar_link',
            'dir' => 'general',
            'codigo' => $l['codigo'],
        ), $admin);
    }
}

@unlink($admin);
@unlink($miguel);
@unlink($jar2);

echo PHP_EOL . "=========================================" . PHP_EOL;
echo $falhas === 0 ? 'TODOS OS TESTES PASSARAM' : $falhas . ' TESTE(S) FALHARAM';
echo PHP_EOL;