<?php

/* ============================================================
   CONFIGURACAO DO SISTEMA
   Altere somente este arquivo.
   ============================================================ */

/*
 * ---------------------------------------------------------
 * 1) NOME DO SISTEMA (escolha da instalacao)
 * ---------------------------------------------------------
 * Este nome entra no nome da pasta oculta e do arquivo do banco
 * de dados (ex.: .s1disco_<hash> / s1disco_<hash>.db.sqlite).
 * Numa instalacao nova, troque por um nome seu — e mais um nome
 * que o visitante nao tem como adivinhar.
 *
 * Somente letras, numeros, "_" e "-". Depois de definir, NAO
 * mude mais: o banco para de ser encontrado. Para renomear
 * junto, siga docs/INSTALACAO.md, secao 5.1.
 */
define('NOME_SISTEMA', 'S1DISCO');

/*
 * ---------------------------------------------------------
 * 2) PASTA DO BANCO DE DADOS
 * ---------------------------------------------------------
 * Deixe VAZIO ('') para o sistema escolher sozinho:
 *   ele cria uma pasta oculta ao lado da pasta do sistema (../)
 *   com o nome .s1disco_<hash>.
 *
 * Se voce mover o banco, escreva aqui o caminho INTEIRO da pasta.
 * Exemplos:
 *     '/home2/select99/.s1disco_a0c0034fd29d7803'
 *     '/home2/select99/dados_s1disco'
 *     'C:/dados/s1disco'
 *
 * A pasta precisa ter permissao de escrita para o PHP.
 * Use "/" como separador, tambem no Windows.
 */
define('PASTA_DADOS', '');

/*
 * ---------------------------------------------------------
 * 3) SENHA QUE GERA O NOME DO BANCO
 * ---------------------------------------------------------
 * Quando PASTA_DADOS esta vazio, esta senha entra no calculo do
 * nome da pasta. Troque por um texto seu para dificultar adivinhacao.
 * Se voce ja preencheu o PASTA_DADOS acima, esta linha nao tem
 * efeito nenhum.
 */
define('USUARIOS_SAL', 'S1Disco-2026-SelectOne');

/*
 * ---------------------------------------------------------
 * 4) PASTA COM OS ARQUIVOS DO USUARIO
 * ---------------------------------------------------------
 * Deixe vazio ('') para usar a pasta "files" que fica dentro
 * do sistema. Preencha apenas se os arquivos estiverem em outro lugar
 * (por exemplo FORA da area publica do servidor).
 *
 * ATENCAO: se voce apontar para fora da area publica, o Apache
 * deixa de entregar os arquivos por link direto, o que e mais seguro.
 *
 * Exemplos:
 *     '/home2/select99/arquivos_s1disco'
 */
define('PASTA_ARQUIVOS', '');

/*
 * ---------------------------------------------------------
 * 5) SUBDOINDOES DE PASTA_ARQUIVOS
 * ---------------------------------------------------------
 * Preencha apenas se quiser mudar esses nomes.
 * Vazio = usar o padrao, que e:
 *     usuarios  ->  files/users/<usuario>
 *     perfis    ->  files/.perfis
 *     geral     ->  files/general
 */
define('PASTA_USUARIOS', '');
define('PASTA_PERFIS', '');
define('PASTA_GERAL', '');

/*
 * ---------------------------------------------------------
 * 5) LIMITES
 * ---------------------------------------------------------
 */
define('MAX_ARQUIVOS_ENVIO', 60);      // arquivos por envio
define('TAMANHO_MIN_SENHA', 8);        // tamanho minimo de senha (NIST pede 12+)
define('TENTATIVAS_LOGIN', 8);         // tentativas antes de desacelerar
define('MINUTOS_BLOQUEIO_LOGIN', 15);

/*
 * Duracao da sessao apos entrar.
 *   MINUTOS_SESSAO  = tempo maximo, mesmo usando o sistema
 *   MINUTOS_OCIOSO  = tempo sem mexer em nada
 * Acima disso o usuario entra de novo.
 */
define('MINUTOS_SESSAO', 600);         // 10 horas
define('MINUTOS_OCIOSO', 120);         // 2 horas sem clicar em nada

/*
 * Limites de caracteres dos campos de texto.
 * Altere aqui se quiser afrouxar ou apertar.
 */
define('TAMANHO_MAX_USUARIO', 40);     // campo "usuario" no login e no cadastro
define('TAMANHO_MAX_SENHA', 100);      // campo de senha
define('TAMANHO_MAX_NOME', 100);       // nome de pasta ou arquivo ao criar/renomear

/*
 * Caracteres permitidos em nome de pasta ou arquivo.
 * Guarde APENAS a classe entre colchetes, sem / no começo e no fim.
 * Sem estes, um nome poderia apontar para outro lugar do disco.
 */
define('CARACTERES_NOME', '[\p{L}\p{N} .,_()\[\]\-+&#@!]');

/*
 * ---------------------------------------------------------
 * 6) TAMANHO TOTAL DO DISCO
 * ---------------------------------------------------------
 * Limite de espaco para a soma de TODOS os arquivos do sistema.
 * Serve para simular a capacidade de um disco: se o total passar
 * do limite, o envio de arquivo e barrado.
 *
 * Exemplos:
 *     '20GB'
 *     '500MB'
 *     '1.5 GB'
 *     '2TB'
 *     ''        (sem limite)
 *
 * O espaco em uso aparece no fim da barra lateral.
 */
define('LIMITE_DISCO', '');