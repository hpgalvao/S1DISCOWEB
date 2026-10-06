<?php

/* ============================================================
   CONTROLE DE USUARIOS EM ARQUIVO (SQLite)
   Nao usa banco de dados externo. Basta ter o PHP com pdo_sqlite.
   ============================================================ */

require_once __DIR__ . '/config.php';

/*
 * Troque o SAL por um texto qualquer seu, para dificultar adivinhacao.
 * Depois de trocar, os usuarios ja cadastrados continuam valendo,
 * porque o arquivo e sempre o mesmo (ele naso e recriado).
 *
 * O SAL e as pastas ficam em config.php.
 */

/* Erros nunca vao para a tela: ficam so no log do servidor. */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/* Cabecalhos de seguranca das paginas HTML. */
function cabecalhosSeguranca()
{
    if (headers_sent()) {
        return;
    }

    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Permitted-Cross-Domain-Policies: none');

    /*
     * A pagina ainda usa script e style dentro do proprio HTML
     * (os botoes usam onclick), entao o 'unsafe-inline' e necessario.
     * Ainda assim a politica barra carregamento de scripts de fora.
     */
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "img-src 'self' data:; "
        . "script-src 'self' 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "object-src 'none'; "
        . "base-uri 'none'; "
        . "form-action 'self'; "
        . "frame-ancestors 'none'"
    );

    $https = false;

    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $https = true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        $https = true;
    }

    if ($https) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}


/* ============================================================
   PROTEÇÃO CONTRE CSRF
   Token criado na sessão e conferido em toda acao que
   altera alguma coisa (POST).
   ============================================================ */

function tokenCsrf()
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function campoCsrf()
{
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(tokenCsrf(), ENT_QUOTES, 'UTF-8') . '">';
}

function conferirCsrf()
{
    $enviado = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    $guardado = isset($_SESSION['csrf']) ? (string)$_SESSION['csrf'] : '';

    if ($enviado === '' || $guardado === '' || !hash_equals($guardado, $enviado)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit("403 - Requisicao invalida. Atualize a pagina e tente de novo.");
    }
}

/* ============================================================
   ONDE O ARQUIVO FICA
   O banco fica sempre na pasta PAI do sistema (../), em uma
   pasta oculta cujo nome sai de uma chave gravada dentro
   do proprio projeto (.chave). Assim o nome nao muda quando
   o projeto e movido de lugar nem quando muda de servidor.
   ============================================================ */

/* chave do sistema: gerada uma vez e guardada no projeto */
function chaveUsuarios()
{
    $arquivo = __DIR__ . '/.chave';

    if (is_file($arquivo)) {
        $conteudo = trim((string)@file_get_contents($arquivo));
        if (strlen($conteudo) >= 32) {
            return $conteudo;
        }
    }

    $conteudo = bin2hex(random_bytes(24));
    @file_put_contents($arquivo, $conteudo);

    return $conteudo;
}

/*
 * Nome da pasta oculta e do arquivo do banco.
 * Ex.: .s1disco_<hash> / s1disco_<hash>.db.sqlite
 * O prefixo vem de NOME_SISTEMA (config.php), para cada instalacao
 * poder usar o proprio nome.
 */
function baseUsuariosNome()
{
    $nome = defined('NOME_SISTEMA') ? trim((string)NOME_SISTEMA) : '';
    $nome = preg_replace('/[^a-zA-Z0-9_-]/', '', $nome);

    if ($nome === '') {
        $nome = 's1disco';
    }

    $h = substr(hash('sha256', chaveUsuarios() . '|' . USUARIOS_SAL), 0, 16);

    return strtolower($nome) . '_' . $h;
}

/* locais candidatos, do mais seguro para o mais simples */
function locaisUsuarios()
{
    $nome = baseUsuariosNome();
    $aqui = rtrim(str_replace('\\', '/', __DIR__), '/');
    $raiz = dirname($aqui);

    $locais = array();

    /* caminho definido manualmente no config.php */
    if (defined('PASTA_DADOS') && PASTA_DADOS !== '') {
        $manual = rtrim(str_replace('\\', '/', PASTA_DADOS), '/');
        $locais[] = array($manual, 'definido em config.php');
        return $locais;
    }

    if ($raiz !== '/' && $raiz !== '.' && $raiz !== '') {
        $locais[] = array($raiz . '/.' . $nome, 'pasta pai (../)');
    }

    $locais[] = array($aqui . '/.' . $nome, 'dentro do projeto');

    return $locais;
}

/* caminho escolhido e gravado em "caminho" dentro da pasta */
function pastaUsuarios()
{
    static $pasta = null;

    if ($pasta !== null) {
        return $pasta === '' ? null : $pasta;
    }

    $nome = baseUsuariosNome();

    // caminho ja gravado em uma execucao anterior?
    $marcador = '';
    foreach (locaisUsuarios() as $local) {
        $arqMarcador = $local[0] . '/caminho';
        if (is_file($arqMarcador)) {
            $marcador = trim((string)@file_get_contents($arqMarcador));
            if ($marcador !== '') {
                break;
            }
        }
    }

    $encontrada = '';

    foreach (locaisUsuarios() as $local) {

        $candidata = $local[0];

        // se ja existe um caminho gravado, so aceitamos aquele
        if ($marcador !== '' && $candidata !== $marcador) {
            continue;
        }

        if (!is_dir($candidata)) {
            @mkdir($candidata, 0700, true);
        }

        if (is_dir($candidata) && is_writable($candidata)) {
            @chmod($candidata, 0700);
            $encontrada = $candidata;
            break;
        }
    }

    $pasta = $encontrada;

    return $pasta === '' ? null : $pasta;
}

function arquivoUsuarios()
{
    $pasta = pastaUsuarios();

    if ($pasta === null) {
        return null;
    }

    return $pasta . '/' . baseUsuariosNome() . '.db.sqlite';
}

/* grava o caminho escolhido, para as proximas execucoes */
function gravarCaminhoUsuarios($pasta)
{
    @file_put_contents($pasta . '/caminho', $pasta);
}

/* protegido contra acesso pelo navegador */
function protegerPastaUsuarios()
{
    $pasta = pastaUsuarios();

    if ($pasta === null) {
        return;
    }

    gravarCaminhoUsuarios($pasta);

    /* Apache */
    $htaccess = $pasta . '/.htaccess';

    if (!is_file($htaccess)) {
        @file_put_contents(
            $htaccess,
            "Require all denied\n"
            . "Deny from all\n"
            . "<FilesMatch \".*\">\n    Require all denied\n</FilesMatch>\n"
        );
    }

    /* IIS */
    $webconfig = $pasta . '/web.config';

    if (!is_file($webconfig)) {
        @file_put_contents(
            $webconfig,
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . "<configuration><system.webServer><authorization>"
            . "<deny users=\"*\" />"
            . "</authorization></system.webServer></configuration>\n"
        );
    }

    /* qualquer outro servidor: index que bloqueia */
    $indice = $pasta . '/index.php';

    if (!is_file($indice)) {
        @file_put_contents($indice, "<?php http_response_code(403); exit('403');\n");
    }

    $vazio = $pasta . '/index.html';

    if (!is_file($vazio)) {
        @file_put_contents($vazio, '');
    }
}

/*
 * Situacao do armazenamento, mostrada na tela de gerenciamento.
 */
function situacaoUsuarios()
{
    $info = array(
        'gravavel' => false,
        'caminho' => '',
        'local' => '',
        'arquivo' => false,
        'mensagem' => '',
    );

    $pasta = pastaUsuarios();

    if ($pasta === null) {
        $tentativas = array();
        foreach (locaisUsuarios() as $local) {
            $tentativas[] = $local[0];
        }

        $info['mensagem'] = 'Nao foi possivel criar a pasta de usuarios. Locais tentados: ' . implode(' | ', $tentativas);

        return $info;
    }

    $info['caminho'] = $pasta;
    $info['gravavel'] = is_writable($pasta);
    $info['arquivo'] = is_file($pasta . '/' . baseUsuariosNome() . '.db.sqlite');

    foreach (locaisUsuarios() as $local) {
        if ($local[0] === $pasta) {
            $info['local'] = $local[1];
        }
    }

    if (!$info['gravavel']) {
        $info['mensagem'] = 'A pasta existe, mas o PHP nao tem permissao de escrita.';
    }

    return $info;
}
/* ============================================================
   CONEXAO
   ============================================================ */

function bdUsuarios()
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $arquivo = arquivoUsuarios();

    if ($arquivo === null) {
        throw new Exception('Sem permissao para gravar os usuarios.');
    }

    protegerPastaUsuarios();

    $pdo = new PDO('sqlite:' . $arquivo);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS usuarios (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            usuario TEXT NOT NULL UNIQUE,
            nome TEXT NOT NULL,
            senha_hash TEXT NOT NULL,
            perfil TEXT NOT NULL DEFAULT \'usuario\',
            criado_em TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )'
    );

    // registro das acoes importantes (exclusao, renomear, senha, permissao)
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS auditoria (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            data_hora TEXT NOT NULL DEFAULT (datetime(\'now\', \'localtime\')),
            usuario TEXT NOT NULL,
            acao TEXT NOT NULL,
            alvo TEXT,
            detalhe TEXT,
            ip TEXT
        )'
    );

    // link publico de compartilhamento
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            codigo TEXT NOT NULL UNIQUE,
            tipo TEXT NOT NULL,
            caminho TEXT NOT NULL,
            titulo TEXT,
            criado_por TEXT NOT NULL,
            criado_em TEXT NOT NULL DEFAULT (datetime(\'now\', \'localtime\')),
            expira_em TEXT,
            senha_hash TEXT,
            acessos INTEGER NOT NULL DEFAULT 0,
            max_acessos INTEGER,
            ativo INTEGER NOT NULL DEFAULT 1
        )'
    );

    // permissao de acesso para um usuario do sistema
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS permissoes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            caminho TEXT NOT NULL,
            usuario TEXT NOT NULL,
            nivel TEXT NOT NULL DEFAULT \'leitura\',
            criado_por TEXT NOT NULL,
            criado_em TEXT NOT NULL DEFAULT (datetime(\'now\', \'localtime\')),
            UNIQUE(caminho, usuario)
        )'
    );

    return $pdo;
}

/*
 * Grava uma acao no historico.
 * Nunca interrompe a operacao do usuario se falhar.
 */
function registrarAuditoria($acao, $alvo = '', $detalhe = '')
{
    try {

        $sql = bdUsuarios()->prepare(
            'INSERT INTO auditoria (usuario, acao, alvo, detalhe, ip) VALUES (:u, :a, :al, :d, :ip)'
        );

        $sql->execute(array(
            'u' => isset($_SESSION['usuario']) ? (string)$_SESSION['usuario'] : 'sistema',
            'a' => (string)$acao,
            'al' => mb_substr((string)$alvo, 0, 300),
            'd' => mb_substr((string)$detalhe, 0, 500),
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '',
        ));
    } catch (Exception $e) {
        /* auditoria nunca pode derrubar o sistema */
    }
}


/* ============================================================
   OPERACOES
   Nota: nenhuma mensagem deste arquivo revela caminho nem nome do arquivo.
   ============================================================ */

function usuarioPorNome($usuario)
{
    try {
        $sql = bdUsuarios()->prepare('SELECT * FROM usuarios WHERE usuario = :u');
        $sql->execute(array('u' => (string)$usuario));
        $conta = $sql->fetch();

        return $conta ? $conta : false;
    } catch (Exception $e) {
        return false;
    }
}

function conferirSenha($usuario, $senha)
{
    $conta = usuarioPorNome($usuario);

    if (!$conta) {
        /*
         * Mesmo que o usuario nao exista, gastamos o mesmo tempo
         * do bcrypt. Sem isso, da para descobrir quais logins
         * existem so medindo a resposta.
         */
        password_verify((string)$senha, '$2y$10$e0NRwPlQiJfZ0d0O3eP1keYHVkhR0mVn0eM4z1yQ0mG1xkq1oLzK');

        return false;
    }

    return password_verify((string)$senha, $conta['senha_hash']);
}

/*
 * Importante: falha de armazenamento NAO pode virar "zero usuarios",
 * senao o sistema reabre o modo de instalacao para qualquer visitante.
 */
function totalUsuarios()
{
    try {
        return (int)bdUsuarios()->query('SELECT count(*) FROM usuarios')->fetchColumn();
    } catch (Exception $e) {
        throw new Exception('Nao foi possivel ler os usuarios do banco.');
    }
}

/* mesmo principio para a listagem */
function listarUsuarios()
{
    try {
        return bdUsuarios()->query('SELECT usuario, nome, perfil, criado_em FROM usuarios ORDER BY nome')->fetchAll();
    } catch (Exception $e) {
        throw new Exception('Nao foi possivel ler os usuarios do banco.');
    }
}


/* ============================================================
   COMPARTILHAMENTO: LINKS PUBLICOS
   ============================================================ */

function urlDoSistema()
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

    $esquema = $https ? 'https' : 'http';

    $base = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $raiz = isset($_SERVER['SCRIPT_NAME']) ? rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') : '';

    return $esquema . '://' . $base . $raiz;
}

/* codigo aleatorio, impossivel de adivinhar */
function gerarCodigoLink()
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function criarLink($tipo, $caminho, $titulo, $senha, $validade, $maxAcessos)
{
    try {

        $codigo = gerarCodigoLink();

        $sql = bdUsuarios()->prepare('SELECT count(*) FROM links WHERE codigo = :c');
        $sql->execute(array('c' => $codigo));

        if ((int)$sql->fetchColumn() > 0) {
            return false;
        }

        $expira = null;

        if ($validade > 0) {
            $expira = date('Y-m-d H:i:s', time() + ($validade * 60));
        }

        $sql = bdUsuarios()->prepare(
            'INSERT INTO links (codigo, tipo, caminho, titulo, criado_por, expira_em, senha_hash, max_acessos, ativo)
             VALUES (:c, :t, :p, :ti, :u, :e, :s, :m, 1)'
        );

        $sql->execute(array(
            'c' => $codigo,
            't' => $tipo,
            'p' => $caminho,
            'ti' => ($titulo !== '' ? $titulo : null),
            'u' => $_SESSION['usuario'],
            'e' => $expira,
            's' => ($senha !== '' ? password_hash($senha, PASSWORD_DEFAULT) : null),
            'm' => ($maxAcessos > 0 ? $maxAcessos : null),
        ));

        registrarAuditoria('criar_link', $caminho, $tipo . ' | validade: ' . ($validade > 0 ? $validade . ' min' : 'sem validade'));

        return $codigo;
    } catch (Exception $e) {
        return false;
    }
}

function buscarLink($codigo)
{
    try {
        $sql = bdUsuarios()->prepare('SELECT * FROM links WHERE codigo = :c AND ativo = 1');
        $sql->execute(array('c' => (string)$codigo));
        $link = $sql->fetch();

        return $link ? $link : false;
    } catch (Exception $e) {
        return false;
    }
}

function linkExpirado($link)
{
    if (empty($link['expira_em'])) {
        return false;
    }

    return strtotime($link['expira_em']) < time();
}

function linkAcabouOsAcessos($link)
{
    if (empty($link['max_acessos'])) {
        return false;
    }

    return (int)$link['acessos'] >= (int)$link['max_acessos'];
}

function contarAcessoLink($codigo)
{
    try {
        $sql = bdUsuarios()->prepare('UPDATE links SET acessos = acessos + 1 WHERE codigo = :c');
        $sql->execute(array('c' => $codigo));
    } catch (Exception $e) {
    }
}

function revogarLink($codigo)
{
    try {
        $sql = bdUsuarios()->prepare('UPDATE links SET ativo = 0 WHERE codigo = :c');
        $sql->execute(array('c' => $codigo));
        registrarAuditoria('revogar_link', $codigo, '');
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function linksDe($caminho = null)
{
    try {

        $sql = 'SELECT * FROM links WHERE criado_por = :u AND ativo = 1';

        $params = array('u' => isset($_SESSION['usuario']) ? $_SESSION['usuario'] : '');

        if ($caminho !== null) {
            $sql .= ' AND caminho = :c';
            $params['c'] = $caminho;
        }

        $sql .= ' ORDER BY id DESC';

        $q = bdUsuarios()->prepare($sql);
        $q->execute($params);

        return $q->fetchAll();
    } catch (Exception $e) {
        return array();
    }
}


/* ============================================================
   COMPARTILHAMENTO: PERMISSOES ENTRE USUARIOS
   ============================================================ */

/*
 * Nivel que o usuario tem sobre um caminho.
 * Vale a concessao da propria pasta ou de qualquer pasta acima,
 * para nao vazar as pastas irmas.
 */
function nivelConcedido($usuario, $caminho)
{
    if ($usuario === '' || $caminho === '') {
        return null;
    }

    try {

        $caminho = trim((string)$caminho, '/');
        $partes = explode('/', $caminho);
        $nivel = null;

        for ($i = count($partes); $i > 0; $i--) {

            $trecho = implode('/', array_slice($partes, 0, $i));

            $sql = bdUsuarios()->prepare('SELECT nivel FROM permissoes WHERE caminho = :c AND usuario = :u');
            $sql->execute(array('c' => $trecho, 'u' => $usuario));

            $achado = $sql->fetchColumn();

            if ($achado === 'escrita') {
                return 'escrita';
            }

            if ($achado === 'leitura') {
                $nivel = 'leitura';
            }
        }

        return $nivel;
    } catch (Exception $e) {
        return null;
    }
}

function concederPermissao($caminho, $usuario, $nivel)
{
    try {

        $sql = bdUsuarios()->prepare(
            'INSERT INTO permissoes (caminho, usuario, nivel, criado_por) VALUES (:c, :u, :n, :g)
             ON CONFLICT(caminho, usuario) DO UPDATE SET nivel = excluded.nivel'
        );

        $sql->execute(array(
            'c' => trim((string)$caminho, '/'),
            'u' => $usuario,
            'n' => $nivel === 'escrita' ? 'escrita' : 'leitura',
            'g' => $_SESSION['usuario'],
        ));

        registrarAuditoria('conceder_acesso', $caminho, $usuario . ' (' . $nivel . ')');

        return true;
    } catch (Exception $e) {
        return false;
    }
}

function removerPermissao($caminho, $usuario)
{
    try {

        $sql = bdUsuarios()->prepare('DELETE FROM permissoes WHERE caminho = :c AND usuario = :u');
        $sql->execute(array('c' => trim((string)$caminho, '/'), 'u' => $usuario));

        registrarAuditoria('remover_acesso', $caminho, $usuario);

        return true;
    } catch (Exception $e) {
        return false;
    }
}

function permissoesDe($caminho)
{
    try {

        $sql = bdUsuarios()->prepare('SELECT usuario, nivel, criado_em FROM permissoes WHERE caminho = :c ORDER BY usuario');
        $sql->execute(array('c' => trim((string)$caminho, '/')));

        return $sql->fetchAll();
    } catch (Exception $e) {
        return array();
    }
}

/* itens que outro usuario compartilhou com esta pessoa */
function compartilhadosComigo()
{
    try {

        $sql = bdUsuarios()->prepare(
            'SELECT caminho, nivel, criado_por, criado_em FROM permissoes WHERE usuario = :u ORDER BY caminho, id'
        );
        $sql->execute(array('u' => isset($_SESSION['usuario']) ? $_SESSION['usuario'] : ''));

        /*
         * O mesmo caminho pode aparecer mais de uma vez (pastas irmas
         * concedidas em conjunto). Fica so uma linha, com o nivel
         * mais alto entre as concessoes.
         */
        $itens = array();

        foreach ($sql->fetchAll() as $p) {

            $caminho = trim((string)$p['caminho'], '/');

            if ($caminho === '') {
                continue;
            }

            if (isset($itens[$caminho])) {

                if ($p['nivel'] === 'escrita') {
                    $itens[$caminho]['nivel'] = 'escrita';
                }

                continue;
            }

            $itens[$caminho] = array(
                'caminho' => $caminho,
                'nivel' => $p['nivel'],
                'quem' => $p['criado_por'],
                'data' => $p['criado_em'],
            );
        }

        return array_values($itens);
    } catch (Exception $e) {
        return array();
    }
}

/*
 * Itens que eu compartilhei com outras pessoas, um por caminho.
 *
 * O caminho so aparece se ainda existir algo ligado a ele: se a
 * ultima pessoa foi removida e nao sobrou link nenhum, ele some da
 * lista. Junto vem o resumo (quem tem acesso, quantos links) para
 * dar para ver o que ainda esta compartilhado.
 */
function meusCompartilhamentos()
{
    try {

        $eu = isset($_SESSION['usuario']) ? $_SESSION['usuario'] : '';

        $itens = array();

        $sql = bdUsuarios()->prepare('SELECT caminho, usuario, criado_em FROM permissoes WHERE criado_por = :c');
        $sql->execute(array('c' => $eu));

        foreach ($sql->fetchAll() as $p) {

            $caminho = trim((string)$p['caminho'], '/');

            if ($caminho === '') {
                continue;
            }

            if (!isset($itens[$caminho])) {
                $itens[$caminho] =(array(
                    'caminho' => $caminho,
                    'pessoas' => array(),
                    'links' => 0,
                    'links_expirados' => 0,
                    'data' => $p['criado_em'],
                ));
            }

            $itens[$caminho]['pessoas'][(string)$p['usuario']] = (string)$p['usuario'];

            if (strtotime($p['criado_em']) > strtotime($itens[$caminho]['data'])) {
                $itens[$caminho]['data'] = $p['criado_em'];
            }
        }

        $sql = bdUsuarios()->prepare('SELECT caminho, criado_em, expira_em FROM links WHERE criado_por = :c AND ativo = 1');
        $sql->execute(array('c' => $eu));

        foreach ($sql->fetchAll() as $l) {

            $caminho = trim((string)$l['caminho'], '/');

            if ($caminho === '') {
                continue;
            }

            if (!isset($itens[$caminho])) {
                $itens[$caminho] = array(
                    'caminho' => $caminho,
                    'pessoas' => array(),
                    'links' => 0,
                    'links_expirados' => 0,
                    'data' => $l['criado_em'],
                );
            }

            if (!empty($l['expira_em']) && strtotime($l['expira_em']) < time()) {
                $itens[$caminho]['links_expirados']++;
            } else {
                $itens[$caminho]['links']++;
            }

            if (strtotime($l['criado_em']) > strtotime($itens[$caminho]['data'])) {
                $itens[$caminho]['data'] = $l['criado_em'];
            }
        }

        /*
         * Sem ninguem e sem link nenhum: ja foi descompartilhado, o item
         * sai da lista. Link expirado continua aparecendo (marcado como
         * expirado) para o dono poder revogar.
         */
        $saida = array();

        foreach ($itens as $item) {

            if (count($item['pessoas']) === 0 && $item['links'] === 0 && $item['links_expirados'] === 0) {
                continue;
            }

            $saida[] = $item;
        }

        return $saida;
    } catch (Exception $e) {
        return array();
    }
}


/* ============================================================
   LIMITE DE TENTATIVAS DE LOGIN
   ============================================================ */

function arquivoTentativas()
{
    $pasta = @sys_get_temp_dir();

    if ($pasta === '' || !is_writable($pasta)) {
        return null;
    }

    $nome = defined('NOME_SISTEMA') ? trim((string)NOME_SISTEMA) : '';
    $nome = preg_replace('/[^a-zA-Z0-9_-]/', '', $nome);

    if ($nome === '') {
        $nome = 's1disco';
    }

    return $pasta . '/' . strtolower($nome) . '_tent_' . sha1(USUARIOS_SAL);
}

function ipVisitante()
{
    return isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : 'desconhecido';
}

function tentativasRecentes()
{
    $arquivo = arquivoTentativas();

    if ($arquivo === null) {
        return 0;
    }

    $linhas = @file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $agora = time();
    $conta = 0;

    if (is_array($linhas)) {
        foreach ($linhas as $linha) {
            $partes = explode('|', $linha);
            if (isset($partes[0], $partes[1]) && $partes[0] === ipVisitante() && (int)$partes[1] > $agora - (MINUTOS_BLOQUEIO_LOGIN * 60)) {
                $conta++;
            }
        }
    }

    return $conta;
}

function registrarTentativa()
{
    $arquivo = arquivoTentativas();

    if ($arquivo === null) {
        return;
    }

    @file_put_contents($arquivo, ipVisitante() . '|' . time() . "\n", FILE_APPEND | LOCK_EX);
}

function limparTentativas()
{
    $arquivo = arquivoTentativas();

    if ($arquivo !== null) {
        @unlink($arquivo);
    }
}

/* true quando ainda pode tentar entrar */
function podeTentarLogin()
{
    if (tentativasRecentes() < TENTATIVAS_LOGIN) {
        return true;
    }

    // desacelera o ataque: espera crescente
    $excedente = tentativasRecentes() - TENTATIVAS_LOGIN;
    usleep(min(3000000, 250000 * $excedente));

    return false;
}

function criarUsuario($usuario, $nome, $senha, $perfil)
{
    $usuario = trim((string)$usuario);
    $nome = trim((string)$nome);

    if ($usuario === '' || $nome === '' || strlen((string)$senha) < TAMANHO_MIN_SENHA) {
        return false;
    }

    try {
        $sql = bdUsuarios()->prepare(
            'INSERT INTO usuarios (usuario, nome, senha_hash, perfil) VALUES (:u, :n, :s, :p)'
        );
        $sql->execute(array(
            'u' => substr($usuario, 0, TAMANHO_MAX_USUARIO),
            'n' => substr($nome, 0, TAMANHO_MAX_NOME),
            's' => password_hash((string)$senha, PASSWORD_DEFAULT),
            'p' => $perfil === 'admin' ? 'admin' : 'usuario',
        ));

        return true;
    } catch (Exception $e) {
        return false;
    }
}

/*
 * Cria usuario aceitando senha curta.
 * Uso exclusivo do instalador (que so roda de 127.0.0.1),
 * para gerar as contas iniciais. Depois delas, o usuario
 * precisa trocar a senha.
 */
function criarUsuarioProvisorio($usuario, $nome, $senha, $perfil)
{
    try {

        $sql = bdUsuarios()->prepare(
            'INSERT INTO usuarios (usuario, nome, senha_hash, perfil) VALUES (:u, :n, :s, :p)'
        );

        $sql->execute(array(
            'u' => substr(trim((string)$usuario), 0, TAMANHO_MAX_USUARIO),
            'n' => substr(trim((string)$nome), 0, TAMANHO_MAX_NOME),
            's' => password_hash((string)$senha, PASSWORD_DEFAULT),
            'p' => $perfil === 'admin' ? 'admin' : 'usuario',
        ));

        return true;
    } catch (Exception $e) {
        return false;
    }
}

/*
 * Detecta senha muito fraca testando as mais comuns.
 * Serve para o admin ver quem ainda esta com senha padrao.
 */
function temSenhaFraca($usuario)
{
    $conta = usuarioPorNome($usuario);

    if (!$conta) {
        return false;
    }

    $comuns = array('123456', '12345678', 'admin', 'admin123', 'password', '123123', '111111', '000000', 'abc123', '1234');

    foreach ($comuns as $chave) {
        if (password_verify($chave, $conta['senha_hash'])) {
            return true;
        }
    }

    return false;
}

function atualizarNome($usuario, $nome)
{
    try {
        $sql = bdUsuarios()->prepare('UPDATE usuarios SET nome = :n WHERE usuario = :u');
        $sql->execute(array('n' => substr(trim((string)$nome), 0, 120), 'u' => $usuario));

        return true;
    } catch (Exception $e) {
        return false;
    }
}

function atualizarSenha($usuario, $senha)
{
    if (strlen((string)$senha) < TAMANHO_MIN_SENHA) {
        return false;
    }

    try {
        $sql = bdUsuarios()->prepare('UPDATE usuarios SET senha_hash = :s WHERE usuario = :u');
        $sql->execute(array('s' => password_hash((string)$senha, PASSWORD_DEFAULT), 'u' => $usuario));

        return true;
    } catch (Exception $e) {
        return false;
    }
}

function atualizarPerfil($usuario, $perfil)
{
    try {
        $sql = bdUsuarios()->prepare('UPDATE usuarios SET perfil = :p WHERE usuario = :u');
        $sql->execute(array('p' => $perfil === 'admin' ? 'admin' : 'usuario', 'u' => $usuario));

        return true;
    } catch (Exception $e) {
        return false;
    }
}

function removerUsuario($usuario)
{
    try {
        $sql = bdUsuarios()->prepare('DELETE FROM usuarios WHERE usuario = :u');
        $sql->execute(array('u' => $usuario));

        return true;
    } catch (Exception $e) {
        return false;
    }
}

/* ============================================================
   SESSAO
   ============================================================ */

function iniciarSessaoUsuarios()
{
    if (session_status() === PHP_SESSION_NONE) {

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');

        $caminho = '/';

        if (isset($_SERVER['SCRIPT_NAME'])) {
            $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
            if ($dir !== '/' && $dir !== '.' && $dir !== '') {
                $caminho = $dir;
            }
        }

        $https = false;

        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $https = true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            $https = true;
        }

        session_set_cookie_params(0, $caminho, '', $https, true);
        session_start();
    }
}

function usuarioLogado()
{
    iniciarSessaoUsuarios();

    if (empty($_SESSION['logado'])) {
        return false;
    }

    /*
     * Expira a sessao: 10 horas no total, ou 2 horas sem
     * clicar em nada. O usuario so e desconectado, nada e apagado.
     */
    $agora = time();
    $inicio = isset($_SESSION['inicio']) ? (int)$_SESSION['inicio'] : $agora;
    $ultimo = isset($_SESSION['ultimo']) ? (int)$_SESSION['ultimo'] : $agora;

    if (($agora - $inicio) > (MINUTOS_SESSAO * 60) || ($agora - $ultimo) > (MINUTOS_OCIOSO * 60)) {
        sessaoExpirada();
        return false;
    }

    $_SESSION['ultimo'] = $agora;

    return true;
}

/* avisa e volta para o login */
function sessaoExpirada()
{
    $_SESSION = array();

    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_regenerate_id(true);
    }
}

/* marca o inicio da sessao quando o login da certo */
function iniciarSessaoValida()
{
    session_regenerate_id(true);

    $_SESSION['logado'] = true;
    $_SESSION['inicio'] = time();
    $_SESSION['ultimo'] = time();
}

function exigirUsuarioLogado()
{
    if (!usuarioLogado()) {
        header('Location: login.php');
        exit;
    }
}

function ehAdmin()
{
    if (isset($_SESSION['perfil']) && strtolower((string)$_SESSION['perfil']) === 'admin') {
        return true;
    }

    return isset($_SESSION['usuario']) && strtolower((string)$_SESSION['usuario']) === 'admin';
}