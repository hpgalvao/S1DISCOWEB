# Testes — apague esta pasta depois da instalar

> ⚠️ **`testes/` é para desenvolvimento. Nunca deixe esta pasta no servidor em
> produção.** Os scripts criam pastas, arquivos, links e permissões de verdade. Se
> ficarem na web, qualquer visitante pode chamá-los e o sistema executará os testes sozinho.

Há um `.htaccess` aqui negando o acesso pelo navegador (Apache), mas isso não é
substituto de apagar. Depois que o sistema estiver instalado e conferido, apague a
pasta inteira:

```
S1DISCO/testes/
```

---

## O que tem aqui

| Arquivo | O que verifica |
|---|---|
| `teste_completo.php` | 29 checagens: login, CSRF, validação de nome, permissão, envio de arquivo, proteção de `files/`, ZIP, avatar, auditoria |
| `teste_compartilhar.php` | Link público com senha, validade, revogação, permissão entre usuários (leitura e escrita) |
| `SEGURANCA.md` | A revisão de segurança que fundamentou esses testes |

## Como rodar

Com o servidor local rodando (`iniciar.cmd` na raiz do repositório):

```bash
php S1DISCO/testes/teste_completo.php
php S1DISCO/testes/teste_compartilhar.php
```

Pré-requisitos:

- PHP com `ext-curl`;
- servidor em `http://127.0.0.1:8080/S1DISCO/`;
- usuários já criados pelo `instalar_usuarios.php` (senhas provisórias `123456`);
- pasta `files/general/` existente.

Cada script imprime `OK` ou `FALHA` por checagem e um resumo no fim. Saída 0 significa
tudo certo.

## O que os testes mexem

- criam e apagam `files/general/Pasta Teste CSRF`, `Pasta Renomeada`, `auditoria.txt`;
- fazem upload de arquivos de teste e os apagam;
- criam link público e revogam;
- concedem e revogam acesso de `miguel` na pasta `users/helio`;
- **não** apagam as contas de usuário;
- gravam linhas na tabela `auditoria`.

Ou seja: rode apenas em instalação de desenvolvimento.

## Se quiser rodar fora da instalação

Os scripts usam esta constante no topo de cada um:

```php
$base = 'http://127.0.0.1:8080/S1DISCO';
```

Ajuste se o seu servidor local usar outra porta ou outro caminho.
