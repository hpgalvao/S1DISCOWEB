# Segurança — S1 Disco

**Escopo:** `app.php`, `usuarios.php`, `config.php`, `login.php`, `logout.php`,
`index.php`, `perfil.php`, `senha.php`, `gerenciar.php`, `instalar_usuarios.php`,
`link.php`, `lib_compartilhar.php`, `roteador.php`

**Tipo:** revisão estática de código com verificação por requisição HTTP real
(`S1DISCO/testes/teste_completo.php`).

**Situação:** nenhum item em aberto. As três rodadas de correção (críticos → altos →
médios e baixos) foram concluídas e validadas pelos testes automatizados.

---

## 1. Modelo de ameaça

| Quem | O que pode fazer | O que o sistema impede |
|---|---|---|
| Visitante anônimo | Entrar sem senha, ver pasta de outro, executar código | Login protegido, pasta fechada, `files/` sem execução e sem acesso direto |
| Usuário comum | Ler pasta alheia, apagar o que não pode, forçar ação em nome de outro | `podeVer()` / `podeEditar()` em toda operação, token CSRF, sem symlink |
| Quem tem um link público | Ir além do item compartilhado | Link é por caminho exato, com senha/validade/limite de acessos |
| Quem tem acesso ao servidor | Ler o banco | Banco fora da área pública, pasta `0700`, `.htaccess` + `web.config` |

---

## 2. Achados críticos — corrigidos

### 2.1 CSRF ausente — corrigido (CWE-352)

`tokenCsrf()` / `campoCsrf()` / `conferirCsrf()` em `usuarios.php`, aplicado no topo de
todo POST de `app.php`, `perfil.php`, `senha.php`, `gerenciar.php`.

```
POST sem token ....... 403  (nada acontece)
POST token errado .... 403
POST token válido .... 302  (executa)
```

### 2.2 Código executável na pasta de arquivos — corrigido (CWE-434)

O sistema **aceita qualquer tipo de arquivo**: é um gestor de disco, então `.php`,
`.exe`, `.msi`, `.sql` etc. são guardados, listados e baixados como qualquer outro.

A proteção não está no tipo do arquivo, e sim no acesso a ele:

- `files/.htaccess` — nega todo acesso HTTP e desliga os handlers do PHP
  (`Require all denied` + `RemoveHandler` + `php_flag engine off` + `-Indexes`);
- `files/web.config` — mesmo resultado no IIS, gerado pelo próprio sistema;
- `roteador.php` — no servidor local, devolve 403 para tudo sob `/files/`;
- os arquivos só saem pelo `app.php`, sempre como anexo
  (`Content-Disposition: attachment` + `X-Content-Type-Options: nosniff`).

Quem usa **nginx** precisa fechar `files/` na configuração do site: nginx não lê
`.htaccess`.

Única exceção à regra de "aceita tudo": `.htaccess`, `.htpasswd`, `.user.ini` e
`web.config`. São nomes que mudam a configuração do servidor dentro da pasta e poderiam
desligar a proteção acima.

```
upload .phar / .exe / .php   -> gravado   (sem execução possível)
URL direta para o arquivo    -> 403
app.php?download=arquivo     -> 200, como anexo
```

### 2.3 Modo instalação aberto por falha de banco — corrigido (CWE-306)

`totalUsuarios()` lança exceção em vez de devolver `0`; `gerenciar.php` responde 500 em
erro de leitura; o modo instalação exige `?chave=`.

**Primeiro acesso:** sem nenhum usuário, o `login.php` mostra o cadastro do
administrador (preenchido com `admin`/`Administrador`/`123456`, editável), protegido por
token CSRF, com criação da pasta `files/users/<login>` e registro em `auditoria`.
A exposição existe entre publicar e criar a conta — o manual manda configurar
imediatamente (ou usar `instalar_usuarios.php` pela linha de comando).

---

## 3. Achados altos — corrigidos

| # | Achado | Correção |
|---|---|---|
| 3.1 | XSS no modal de detalhes (CWE-79) | Todos os campos passam por `escapar()`/`htmlspecialchars(ENT_QUOTES)` |
| 3.2 | `onclick` montado por concatenação (CWE-79/116) | `data-*` + ouvinte delegado; zero `onclick` com conteúdo dinâmico |
| 3.3 | IDOR no endpoint de miniaturas (CWE-862) | `podeVer()` conferido, limite de 12 MB, `imagecreatefrom<formato>` |
| 3.4 | Banco SQLite exposto (CWE-552) | Pasta fora da área pública, `0700`, `.htaccess` + `web.config` + `index.php`, roteador local barra `/.` |
| 3.5 | Senha fraca e força bruta (CWE-521/307) | Mínimo de 8 caracteres (12 recomendado), 8 tentativas por IP em 15 min com espera crescente, `password_needs_rehash()` |
| 3.6 | Permissão de exclusão por `podeVer()` (CWE-284) | Exigido `podeEditar()` no item: quem só tem leitura não apaga |

---

## 4. Achados médios — corrigidos

| # | Achado | Correção |
|---|---|---|
| 4.1 | Cabeçalhos HTTP fracos | `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, CSP com `frame-ancestors 'none'`, HSTS em HTTPS |
| 4.2 | XSS em `confirm()` | `onclick` removido; atributos `data-*` escapados |
| 4.3 | Upload sem limite | Máximo de 60 arquivos por envio e detecção de `post_max_size` excedido |
| 4.4 | Cookies sem endurecimento | `use_strict_mode`, `httponly`, `samesite=Lax`, regeneração de ID no login |
| 4.5 | Sessão que não expira | 10 h totais (`MINUTOS_SESSAO`) + 2 h ocioso (`MINUTOS_OCIOSO`) |
| 4.6 | Enumeração de usuários por tempo | `password_verify` executado mesmo quando a conta não existe |
| 4.7 | Vazamento de caminho do banco | Caminho do banco só aparece para admin logado |
| 4.8 | Sem auditoria | Tabela `auditoria` + `registrarAuditoria()` em 22 pontos do sistema |
| 4.9 | Compartilhamento sem dono | `podeGerenciarCompartilhamento()`: só quem concedeu revoga; admin sempre pode |

---

## 5. Achados baixos — corrigidos

| # | Achado | Correção |
|---|---|---|
| 5.1 | Erro de PHP na tela | `display_errors=0`, `log_errors=1` |
| 5.2 | `&` sem escape em links | `&amp;` em `linkPara()` e nos demais links |
| 5.3 | Avatar de outro usuário | Só o dono vê a própria foto (admin vê todas) |
| 5.4 | Nome de arquivo com acento no download | RFC 5987 (`filename*=UTF-8''`) com fallback ASCII |
| 5.5 | Zip Slip | `nomeNoZip()` remove `../` e barra inicial |
| 5.6 | Recursão infinita | Profundidade máxima 60; ZIP limitado a 20.000 itens |
| 5.7 | Espaço em disco sem limite | Cota configurável (`LIMITE_DISCO`) com recusa do envio antes de gravar |

---

## 6. Práticas presentes no código

| Prática | Onde |
|---|---|
| *Prepared statements* em todas as consultas | `usuarios.php` |
| `password_hash` / `password_verify` + rehash | `usuarios.php` |
| `session_regenerate_id(true)` no login | `login.php` |
| Cookie `httponly`, `samesite`, expiração | `usuarios.php` |
| Anti-traversal com `realpath` + verificação de prefixo | `app.php` |
| Permissão conferida em download, conteúdo, miniatura, avatar, mover, apagar, renomear | `app.php` |
| Bloqueio de símbolos de link em todas as operações | `app.php` |
| Validação de nome no servidor **e** no navegador | `nomeValido()` / `conferirNome()` |
| `files/` sem execução e sem acesso direto | `files/.htaccess`, `files/web.config`, `roteador.php` |
| Auditoria das ações destrutivas | `registrarAuditoria()` |
| Tudo ajustável sem mexer no código | `config.php` |

---

## 7. Como manter seguro

1. **Atualize** `config.php` para senhas fortes: `TAMANHO_MIN_SENHA >= 12`,
   `TENTATIVAS_LOGIN <= 8`.
2. **Apague** `instalar_usuarios.php` e `testes/` depois da instalação.
3. **Troque** as senhas provisórias (`gerenciar.php` marca as fracas).
4. **HTTPS** sempre — o sistema só emite HSTS quando detecta HTTPS.
5. **Verifique** antes de publicar:
   - `S1DISCO/.chave` está no servidor?
   - `S1DISCO/files/.htaccess` está no servidor?
   - `https://SEUDOMINIO/S1DISCO/files/` devolve **403**?
6. **Rode** os testes no seu servidor local: `php S1DISCO/testes/teste_completo.php`
   (29 checagens).
7. **Backup**: pasta `.s1disco_<hash>/` + `S1DISCO/files/`.

---

## 8. Referências

**OWASP Top 10 (2021):** A01 Broken Access Control · A02 Cryptographic Failures ·
A03 Injection · A05 Security Misconfiguration · A07 Identification and Authentication
Failures · A08 Software and Data Integrity Failures

**Técnicas:** OWASP PHP Security Cheat Sheet · PHP Manual (`password_hash`, `finfo`,
`hash_equals`) · RFC 6265 (cookies) · RFC 5987 (Content-Disposition) ·
NIST SP 800-63B (senhas)
