# Instalação

Passo a passo do zero até o sistema rodando. Em português, sem supor nada.

---

## 1. Verifique o servidor

```bash
php -v          # 7.4 ou superior
php -m          # precisa ter: pdo_sqlite, mbstring, gd, fileinfo, zip
```

Faltando uma extensão no cPanel, marque a opção em **PHP Selector → Extensions**.
A extensão `curl` só é exigida pelos testes.

---

## 2. Coloque os arquivos no lugar

Só a pasta `S1DISCO/` vai para a área pública do servidor:

```
public_html/
└── S1DISCO/          <- esta pasta
    ├── app.php
    ├── config.php
    ├── ...
    └── files/
```

Não suba a pasta raiz do repositório (que contém `docs/`, `exemplos/` e `testes/`
de teste no nível superior) para a raiz pública.

### Ponto importante dos arquivos ocultos

O sistema usa dois arquivos ocultos e o FTP costuma escondê-los:

| Arquivo | Para que serve |
|---|---|
| `S1DISCO/.chave` | Nome do banco de dados. Se sumir, o sistema acha que é uma instalação nova. |
| `S1DISCO/files/.htaccess` | Proíbe acesso direto e execução de scripts em `files/`. |

Se o seu FTP não mostra arquivos que começam com ponto, **ative "mostrar ocultos"**
antes de subir tudo. Um `.htaccess` faltando em `files/` é a diferença entre guardar
arquivos com segurança e entregar código executável.

---

## 3. Crie os usuários

### Opção A — primeiro acesso (recomendada)

Com o sistema no lugar, abra a URL no navegador. Sem nenhum usuário cadastrado, o
`login.php` mostra o cadastro do **administrador**, já preenchido:

| Campo | Valor inicial |
|---|---|
| Login | `admin` |
| Nome exibido | `Administrador` |
| Senha | `123456` (provisória) |

Altere o que quiser, salve, e o sistema cria a conta (perfil admin) + a pasta
`files/users/<login>` e já entra. A tabela `auditoria` registra `primeiro_acesso`.

> ⚠️ **Na produção, faça esse primeiro acesso imediatamente após publicar.** Enquanto
> não existir admin, quem acessar a URL primeiro cria a conta. O formulário é protegido
> por token CSRF, mas a janela de exposição existe — configure antes de divulgar o
> endereço.

### Opção B — usuários de demonstração (para os testes)

Os testes automáticos de `S1DISCO/testes/` usam 4 contas fixas. Se você for rodá-los,
crie essas contas (só aceita da própria máquina: terminal/SSH ou `127.0.0.1`):

```bash
cd caminho/para/S1DISCO
php instalar_usuarios.php
```

```
S1 Disco - instalacao dos usuarios
----------------------------------------
Usuarios criados:

  helio      perfil: ADMIN    senha: 123456   <- primeiro acesso
  jana       perfil: usuario  senha: 123456
  miguel     perfil: usuario  senha: 123456
  sandro     perfil: usuario  senha: 123456

ATENCAO: as senhas sao 123456. Troque todas (gerenciar.php)
         antes de publicar, e DEPOIS APAGUE instalar_usuarios.php.
```

Rodar de novo não duplica nada: se já houver usuários, ele não mexe em nada. Em uso
normal esse arquivo é opcional — os usuários são criados pelo admin no painel.

### Opção C — outros usuários (depois da instalação)

Entre como admin e use o menu **Usuários** (`Usuarios > Novo usuario`): login, nome,
senha e perfil. Cada usuário ganha a pasta `files/users/<login>` na hora. É assim que
se criam contas no dia a dia — o instalador é só para o início.

### Opção D — sem terminal, só para o admin inicial (emergência)

Se ninguém conseguiu abrir a URL e não há terminal, o modo instalação do
`gerenciar.php` abre com um código calculado a partir do `S1DISCO/.chave`:

```bash
php -r "echo hash('sha256', trim(file_get_contents('S1DISCO/.chave')) . 'instalacao');"
```

Abra `gerenciar.php?chave=<o que o comando acima imprimiu>`. O modo instalação some
assim que existir um usuário.

---

## 4. Checklist depois da instalação

- [ ] Entre como admin e troque a senha de **todos** os usuários (`Usuários`).
      O próprio painel marca as senhas fracas.
- [ ] Apague `S1DISCO/instalar_usuarios.php`.
- [ ] Apague `S1DISCO/testes/` (nunca deixe teste em produção).
- [ ] Edite `S1DISCO/config.php`: limite de disco, validade de sessão, tamanho de senha.
- [ ] Confirme que `S1DISCO/files/.htaccess` está no servidor.
- [ ] Confirme que `S1DISCO/.chave` está no servidor.
- [ ] Use HTTPS (o sistema já emite HSTS quando detecta HTTPS).
- [ ] Acesse `S1DISCO/files/` pelo navegador: precisa devolver **403**.

---

## 5. Onde ficam os dados

Dois lugares, fora do código:

```
<raiz_do_repositorio>/
├── .s1disco_<hash>/                     banco SQLite
│   ├── s1disco_<hash>.db.sqlite         usuários, permissões, links, auditoria
│   └── caminho                          registro do local escolhido
└── S1DISCO/files/                       os arquivos enviados
```

O nome da pasta do banco é derivado de `NOME_SISTEMA` + `.chave` + `USUARIOS_SAL`
do `config.php`:

```
s1disco_<16 chars de sha256(.chave + USUARIOS_SAL)>
```

Trocando `NOME_SISTEMA`, o conteúdo de `.chave` ou `USUARIOS_SAL` muda o nome —
**não troque sem antes saber onde o banco está** (veja abaixo).

**Backup:** copie a pasta `.s1disco_<hash>/` e a pasta `S1DISCO/files/`. São essas duas
coisas.

A pasta da web (`S1DISCO/`) pode ter qualquer nome: o código usa caminhos relativos
ao próprio arquivo. O `NOME_SISTEMA` serve para o nome do banco — pode ser diferente
do nome da pasta da web.

Em hospedagem, se quiser que o banco fique fora da área pública para sempre, defina em
`config.php`:

```php
define('PASTA_DADOS', '/home2/seu_usuario/dados_s1disco');
```

### Renomear ou mover a pasta do banco

O nome `.s1disco_<hash>` não é escolhido à mão: sai de
`NOME_SISTEMA` + `sha256(S1DISCO/.chave | USUARIOS_SAL)`. **Renomear a pasta ou trocar o
conteúdo de `.chave` sem avisar o sistema não muda o caminho que ele procura** — ele passa
a criar outra pasta vazia e o sistema vira uma instalação nova (0 usuários). Seus dados
não somem, ficam órfãos.

**Procedimento correto (testado):**

1. Pare o servidor.
2. Mova/renomeie a pasta para onde quiser.
3. Edite o arquivo `caminho` que fica dentro dela: no lugar do caminho antigo, escreva o
   caminho absoluto novo (sempre com `/`, inclusive no Windows).
4. Aponte o `config.php`:

   ```php
   define('PASTA_DADOS', '/novo/lugar/dados_s1disco');
   ```

5. Suba o servidor e confira o login.

Sem o passo 3 o sistema não acha o banco (login falha com erro 500). Sem o passo 4, se o
nome novo não for o derivado da chave, ele ignora a pasta.

**Resumo do que faz o quê:**

| O que você mexe | O que acontece |
|---|---|
| Só o nome da pasta `.s1disco_...` | Sistema ignora, cria outra vazia — perde acesso aos dados |
| O conteúdo de `S1DISCO/.chave` | Mesma coisa: nome derivado muda, dados ficam órfãos |
| `USUARIOS_SAL` no `config.php` | Mesma coisa |
| `NOME_SISTEMA` no `config.php` | Mesma coisa |
| Nome do arquivo do `.db.sqlite` | O sistema passa a criar outro banco — use o nome original |
| `PASTA_DADOS` + `caminho` (passos acima) | ✅ forma suportada de mover/renomear |

> `.chave` não criptografa nada: é só a semente do nome da pasta. Quem tem o arquivo do
> banco lê o conteúdo de qualquer jeito — por isso ele fica **fora** da área pública e com
> permissão `0700`. O `.chave` e a pasta do banco estão no `.gitignore`, não vão para o
> GitHub.

---

## 6. Configuração rápida

Abra `S1DISCO/config.php` — é o único arquivo que precisa editar.

```php
define('LIMITE_DISCO', '20GB');   // soma de todos os arquivos; '' = sem limite
define('TAMANHO_MIN_SENHA', 12);
define('MINUTOS_SESSAO', 600);    // minutos no total
define('MINUTOS_OCIOSO', 120);    // minutos sem mexer
define('MAX_ARQUIVOS_ENVIO', 60);
```

Cada constante tem comentário explicando o que acontece se mudar.

---

## 7. Atualizar

1. Faça backup (pasta do banco + `files/`).
2. Suba os arquivos `.php` sobre os atuais, **preservando** `config.php`,
   `.chave`, `files/.htaccess` e `files/`.
3. Não existe migração de banco: o SQLite é criado com `CREATE TABLE IF NOT EXISTS`,
   então novas tabelas aparecem sozinhas.
4. Abra o site; se aparecer erro de tabela, o log do servidor diz qual.

---

## 8. Problemas comuns

| Sintoma | Causa provável |
|---|---|
| "Pasta de arquivos nao encontrada" | `PASTA_ARQUIVOS` no `config.php` apontando para um caminho que não existe |
| 403 em tudo dentro de `files/` | Comportamento esperado — o sistema entrega os arquivos |
| Login sempre falha | Banco não gravável, ou `.chave` apagado (virou instalação nova) |
| "Erro no banco de usuários" | Pasta do banco sem permissão de escrita (precisa ser `0700`) |
| Imagens não ganham miniatura | Extensão `gd` desligada |
| ZIP vazio | Extensão `zip` desligada |
| Tela branca | `display_errors` fica desligado por segurança; olhe o log do PHP |

---

Próximo passo: [`SEGURANCA.md`](SEGURANCA.md) para entender o que o sistema já
protege sozinho.
