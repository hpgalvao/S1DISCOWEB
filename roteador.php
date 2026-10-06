<?php

/* ============================================================
   ROTEADOR DO SERVIDOR LOCAL
   Serve a pasta-pai em http://127.0.0.1:8080/S1DISCO/

   Bloqueia o acesso direto a arquivos e pastas ocultos,
   para o banco de dados (que fica em ../) nao ser baixado
   pelo navegador durante os testes.

   Este arquivo e usado apenas pelo iniciar.cmd.
   ============================================================ */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = urldecode((string)$uri);

/* qualquer caminho com /. no meio e bloqueado (.s1disco_hash, .htaccess...) */
if (preg_match('#(^|/)\.#', $uri)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("403 - acesso negado");
}

/*
 * Nada dentro de files/ e entregue direto pelo navegador.
 * O servidor embutido do PHP ignora o .htaccess que existe la
 * dentro, entao um .php enviado seria executado. Quem precisa
 * dos arquivos passa pelo app.php, que envia como anexo.
 */
if (preg_match('#/files(/|$)#', $uri)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("403 - acesso negado");
}

/*
 * Os testes rodam pela linha de comando (php S1DISCO/testes/...).
 * Aqui eles ficam bloqueados, igual ao que o .htaccess deles faz
 * em hospedagem.
 */
if (preg_match('#/testes(/|$)#', $uri)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("403 - acesso negado");
}

$completo = __DIR__ . $uri;

/* arquivo comum: deixa o servidor interno entregar */
if ($uri !== '/' && is_file($completo)) {
    return false;
}

/* pasta: executa o index.php que estiver dentro dela */
if (is_dir($completo)) {

    $indice = rtrim($completo, '/') . '/index.php';

    if (is_file($indice)) {
        $_SERVER['SCRIPT_NAME'] = rtrim($uri, '/') . '/index.php';
        $_SERVER['SCRIPT_FILENAME'] = $indice;
        chdir(dirname($indice));
        require $indice;
        return true;
    }
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
exit('404 - nao encontrado');