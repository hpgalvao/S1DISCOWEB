# S1 Disco

Gerenciador de arquivos web com usuários, permissões e compartilhamento.

Feito em **PHP puro** — sem framework, sem Composer, sem banco de dados externo e sem
dependência de JavaScript. O banco é um único arquivo SQLite gerado pelo próprio
sistema.

---

## Recursos

**Arquivos**

- Explorador em duas visões: grade (com miniatura) e lista, com busca dentro da pasta.
- Enviar vários arquivos de uma vez (até 60), arrastando para a janela.
- Criar, renomear, mover e apagar pastas e arquivos.
- Baixar um arquivo, baixar a seleção em ZIP ou baixar uma pasta inteira em ZIP.
- Aceita qualquer tipo de arquivo (`.php`, `.exe`, `.sql`...) — a única exceção são os
  arquivos de configuração do servidor web, que mudam a proteção da pasta.
- Cota de disco configurável, com a barra de uso sempre visível na lateral.

**Pessoas e permissões**

- Vários usuários com login, perfis `admin` e `usuario`, senha com hash e troca de senha.
- Cada usuário vê a pasta compartilhada (`general`) e a própria pasta; o admin vê tudo.
- Permissão concedida por pasta **ou por arquivo**, com nível `leitura` (ver e baixar) ou
  `escrita` (pode alterar).
- Link público com senha, validade e limite de acessos.
- Atalhos **Compartilhados comigo** e **Meus compartilhamentos**, com descompartilhamento
  item a item ou de uma vez (`Descompartilhar tudo`).
- Auditoria de ações sensíveis (exclusão, renomeação, envio, senha, permissão).

**Segurança**

- Token CSRF em todo POST, cabeçalhos HTTP rígidos (CSP, `X-Frame-Options`, HSTS).
- Sessão com expiração, cookie `httponly`/`samesite`, regeneração de ID no login.
- Tentativas de login limitadas por IP, com espera crescente.
- Caminho sempre validado contra a pasta raiz (`realpath` + prefixo), símbolos de link
  bloqueados, pastas de trabalho sem execução nem acesso direto pelo navegador.

Detalhes em [`docs/SEGURANCA.md`](docs/SEGURANCA.md).

---

## Requisitos

| Item | Mínimo | Testado |
|---|---|---|
| PHP | 7.4 | 8.2 |
| Extensão `pdo_sqlite` | obrigatória | — |
| Extensão `mbstring` | obrigatória | — |
| Extensão `gd` | obrigatória (miniaturas) | — |
| Extensão `fileinfo` | obrigatória (tipo do arquivo) | — |
| Extensão `zip` | obrigatória (download em ZIP) | — |
| Extensão `curl` | só para os testes | — |
| Servidor web | Apache (usa `.htaccess`) | PHP embutido |

Sem Composer, sem Node, sem MySQL/PostgreSQL.

> **nginx:** o `files/.htaccess` não é lido pelo nginx. Feche a pasta `S1DISCO/files/`
> na configuração do site (não sirva nada de lá). Mesma coisa vale para `web.config` no
> IIS — o sistema gera esse arquivo sozinho.

---

## Instalação em 1 minuto (local)

```bash
git clone https://github.com/hpgalvao/S1DISCOWEB
cd SEU-REPO
iniciar.cmd        # Windows — abre http://127.0.0.1:8080/S1DISCO/
```

Linux/macOS:

```bash
php -S 127.0.0.1:8080 -t . roteador.php
```

Na primeira visita o próprio sistema cria o banco de dados e mostra o cadastro do
administrador (login `admin`, nome `Administrador`, senha `123456` — tudo editável
antes de salvar). Crie a conta, entre e pronto.

Instalação passo a passo, incluindo hospedagem: [`docs/INSTALACAO.md`](docs/INSTALACAO.md).

---

## Primeiro acesso

Ao abrir o sistema sem nenhum usuário cadastrado, você vê o formulário de criação do
administrador, já preenchido:

| Campo | Valor inicial |
|---|---|
| Login | `admin` |
| Nome exibido | `Administrador` |
| Senha | `123456` (provisória) |

Pode aceitar como está ou trocar antes de salvar. A conta é criada com perfil admin e a
pasta `files/users/<login>` é criada junto. A senha provisória aparece como "fraca" no
painel Usuários até você trocá-la.

> **Usuários de demonstração (`helio`, `jana`, `miguel`, `sandro`)** — só para rodar os
> testes automáticos de `S1DISCO/testes/`. Crie com
> `php S1DISCO/instalar_usuarios.php` e depois apague o arquivo. Em uso normal, não é
> preciso: os usuários são criados pelo admin em **Usuários** no próprio sistema.

---

## Configuração

Tudo fica em `S1DISCO/config.php`, sem mexer no código:

| Constante | O que controla |
|---|---|
| `NOME_SISTEMA` | Prefixo da pasta oculta e do banco (ex.: `.s1disco_<hash>`). Troque numa instalação nova |
| `PASTA_DADOS` | Onde fica o banco SQLite (vazio = pasta oculta `../.s1disco_<hash>`) |
| `PASTA_ARQUIVOS` | Onde ficam os arquivos enviados (vazio = `S1DISCO/files`) |
| `LIMITE_DISCO` | Espaço total: `'20GB'`, `'500MB'`, `''` = sem limite |
| `MAX_ARQUIVOS_ENVIO` | Arquivos por envio |
| `TAMANHO_MIN_SENHA` | Tamanho mínimo da senha |
| `TENTATIVAS_LOGIN` | Tentativas antes de desacelerar o login |
| `MINUTOS_SESSAO` / `MINUTOS_OCIOSO` | Expiração da sessão |

O arquivo é comentado, em português.

---

## Estrutura do projeto

```
.
├── iniciar.cmd            servidor local (porta 8080)
├── roteador.php           regras do servidor local (bloqueia /files/ e pastas ocultas)
├── docs/                  documentação
├── S1DISCO/               O SISTEMA (é esta pasta que você publica)
│   ├── config.php         todos os ajustes
│   ├── app.php            explorador de arquivos
│   ├── login.php  logout.php  index.php
│   ├── usuarios.php       banco, sessão, CSRF, permissões, links
│   ├── gerenciar.php      gestão de usuários (admin)
│   ├── perfil.php  senha.php
│   ├── link.php           página pública do link compartilhado
│   ├── instalar_usuarios.php   usuários de demonstração p/ testes (opcional, APAGAR)
│   ├── testes/            APAGAR após instalar
│   └── files/             arquivos dos usuários
│       ├── .htaccess      proíbe acesso e execução
│       ├── general/       pasta compartilhada por todos
│       ├── users/<login>/ pasta particular de cada um
│       └── .perfis/       fotos de perfil
└── .s1disco_<hash>/       banco SQLite (gerado, fora da área pública)
```

---

## Testes

Com o servidor local rodando:

```bash
php S1DISCO/testes/teste_completo.php       # 29 verificações de segurança e funcionamento
php S1DISCO/testes/teste_compartilhar.php   # links públicos, senha e permissões
```

Os testes criam e apagam arquivos de verdade na instalação local — **não rode em
produção**.

> ⚠️ Após instalar, apague `S1DISCO/testes/` e `S1DISCO/instalar_usuarios.php`.
> Os testes usam contas com senha provisória.

---

## Limitações conhecidas

- Um servidor sem `.htaccess`/`web.config` precisa ser configurado à mão para não
  entregar arquivos de `files/` (nginx, `AllowOverride None`).
- O cálculo de espaço em uso varre a árvore a cada página; com dezenas de milhares de
  arquivos, vale um cache (ainda não implementado).
- Não tem compartilhamento externo com data de expiração automática de pasta — a
  expiração vale para o link público.

---

## Contribuindo

Leia [`CONTRIBUTING.md`](CONTRIBUTING.md). Em resumo: nada de framework, nada de
Composer, tudo ajustável pelo `config.php`, e rode os testes antes do pull request.

## Segurança

Encontrou uma falha? Veja [`SECURITY.md`](SECURITY.md). Não abra issue pública.

## Licença

MIT — veja [`LICENSE`](LICENSE).
