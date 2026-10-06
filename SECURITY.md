# Política de segurança

## Como reportar

**Não abra uma issue pública** com detalhes de uma falha.

Use o relato privado do GitHub: em **Security → Report a vulnerability**, escolha este
repositório e descreva a falha. Se o repositório ainda não tiver o relato privado
habilitado, envie por mensagem direta ao mantenedor com um título
`[SEGURANÇA] descrição curta` e **não** publique a reprodução.

Include, se possível:

- passos para reproduzir;
- versão do PHP e do servidor web (Apache/nginx/IIS);
- impacto (o que um atacante consegue);
- correção sugerida, se já tiver.

Resposta esperada: confirmação em até 7 dias, correção ou plano de correção antes de
divulgar publicamente.

## Versões suportadas

| Versão | Suporte |
|---|---|
| `main` | ✅ correções ativas |
| Releases anteriores | ✅ melhor esforço, se a falha for crítica |

## O que é considerado falha

- Ler, alterar ou apagar arquivo/pasta/permissão de quem não devia;
- Executar código a partir de um arquivo enviado;
- Acesso ao banco SQLite ou ao `.chave` de fora do sistema;
- Bypass de login, sessão ou CSRF;
- Vazar senha, hash ou dado de outro usuário.

## O que **não** é falha

- Baixar arquivo que você já tem permissão para baixar;
- Configuração de servidor (nginx sem `files/` fechado, por exemplo) — mas avise, é
  bom ter isso documentado;
- Senha provisória do instalador, desde que seguido do checklist de instalação
  (troque as senhas e apague o instalador);
- Força bruta acima do limite definido em `TENTATIVAS_LOGIN`, é ajustável.

## Boas práticas esperadas de quem hospeda

- HTTPS;
- senhas fortes (`TAMANHO_MIN_SENHA >= 12`);
- `S1DISCO/instalar_usuarios.php` e `S1DISCO/testes/` apagados;
- `S1DISCO/.chave` e `S1DISCO/files/.htaccess` presentes;
- pasta do banco fora da área pública;
- backup de `.s1disco_<hash>/` + `S1DISCO/files/`.

Detalhes técnicos: [`docs/SEGURANCA.md`](docs/SEGURANCA.md).
