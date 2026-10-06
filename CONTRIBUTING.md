# Contribuindo

Obrigado por querer melhorar o S1 Disco.

## Ambiente de desenvolvimento

```bash
git clone https://github.com/hpgalvao/S1DISCOWEB
cd SEU-REPO
iniciar.cmd                    # Windows
# Linux/macOS:
php -S 127.0.0.1:8080 -t . roteador.php
```

Abra `http://127.0.0.1:8080/S1DISCO/instalar_usuarios.php` uma vez para criar os
usuários de teste.

Com o servidor rodando:

```bash
php S1DISCO/testes/teste_completo.php       # 29 checagens
php S1DISCO/testes/teste_compartilhar.php   # compartilhamento e permissões
```

**Os dois precisam passar antes do pull request.**

## Regras do projeto

Estas são as razões de o projeto ser pequeno e fácil de hospedar — ajude a manter:

1. **PHP puro.** Nada de framework, bibliotecas ou Composer. O alvo é PHP 7.4+ em
   hospedagem compartilhada.
2. **Sem build.** Nada de Node, Webpack, Sass ou transpilação. HTML, CSS e JavaScript
   já são arquivos finais, dentro de `S1DISCO/app.php`.
3. **Configuração no `config.php`.** Se mudar um limite, um caminho ou um comportamento
   opcional, crie uma constante em `config.php` e comente. O usuário não deve mexer
   em código.
4. **Comentários e mensagens em português**, no mesmo tom do resto do código
   (frase completa, sem abreviação, sem jargão inglês).
5. **Sem dependência de servidor específico.** Se algo depender de `.htaccess`,
   `web.config` ou de configuração de nginx, deixe isso documentado em
   `docs/INSTALACAO.md`.

## Segurança

O código tem uma revisão própria documentada em `docs/SEGURANCA.md`. Ao mexer em uma
área sensível, mantenha:

- *prepared statement* em toda consulta ao SQLite;
- `podeVer()` para ler e `podeEditar()` para alterar — **nos dois lados**;
- `htmlspecialchars($texto, ENT_QUOTES, 'UTF-8')` em todo texto que vira HTML;
- token CSRF (`campoCsrf()` / `conferirCsrf()`) em todo `POST`;
- caminho sempre passado por `limpar()` e `caminhoSeguro()`;
- `registrarAuditoria()` em toda ação destrutiva.

Se a mudança mexer com arquivo enviado, sessão, compartilhamento ou permissão, adicione
uma checagem em `S1DISCO/testes/`.

## Formato das mudanças

1. Crie um *branch* com nome curto em português: `corrigir-miniatura`,
   `adicionar-ordenacao`.
2. Faça commits com mensagem no imperativo e explicando o porquê: `Corrige miniatura
   de imagem corrompida`.
3. Uma ideia por commit; misture correção e refactor em *commits* separados.
4. Abra pull request descrevendo: o problema, o que mudou e como foi testado.

## O que não subir para o repositório

O `.gitignore` já cobre. Não force a inclusão de:

- `.chave`, `.s1disco_*/` (banco de dados);
- arquivos em `S1DISCO/files/` (dados de quem usa);
- `backup_*/`.

## Bug report

Abra uma issue com:

- o que você fez;
- o que esperava;
- o que aconteceu;
- PHP + servidor web (`php -v` e se é Apache, nginx ou IIS);
- trecho relevante do log de erro do PHP.

Se for falha de segurança, veja [`SECURITY.md`](SECURITY.md) e não abra issue pública.
