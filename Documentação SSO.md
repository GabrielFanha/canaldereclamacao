# SSO — Guia de Integração

> **Gestão ProvitaSST** · Autenticação centralizada para sistemas externos

---

## 1. Endpoint e Chave

| Campo | Valor |
|---|---|
| **URL** | `https://www.provitasst.com.br/sso.php` |
| **Método** | `POST` |
| **Content-Type** | `application/json` |
| **Autenticação** | Header `Authorization: Bearer {TOKEN_FIXO}` |

O SSO usa um **token fixo de autenticação**. Ele deve ser enviado em **todas** as requisições — sem ele, a API recusa o acesso **antes** de processar qualquer `action`.

> **⚠️ Guarde em segredo:** o token não deve aparecer em JavaScript, HTML, logs ou repositórios. Mantenha-o somente em arquivos PHP server-side, fora do alcance do cliente.

> **🔒 Não é público.** Nem o login nem a listagem de usuários podem ser chamados pelo navegador do usuário final. Apenas o **backend** do sistema integrado, com o Bearer token, pode consumir o SSO.

---

## 2. Actions disponíveis

| `action` | Finalidade | Exige usuário/senha do Gestão? |
|---|---|---|
| `login` | Autenticar um usuário (usuário/e-mail + senha) | **Sim** |
| `listar_usuarios` | Consultar usuários ativos (para vínculo / seleção) | **Não** (só o Bearer token) |

Se `action` for omitida, o padrão é `login`.

---

## 3. Action: `login`

Envie os campos abaixo no corpo da requisição como JSON:

| Campo | Tipo | | Descrição |
|---|---|---|---|
| `action` | `string` | **obrigatório** | `"login"` |
| `usuario` | `string` | **obrigatório** | Usuário ou e-mail cadastrado no Gestão |
| `senha` | `string` | **obrigatório** | Senha do usuário no Gestão |

> **ℹ️** O campo `usuario` aceita tanto o **nome de usuário** quanto o **e-mail** — o sistema identifica automaticamente o formato enviado.

### Exemplo PHP — requisição

```php
$ch = curl_init('https://www.provitasst.com.br/sso.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
        'action'  => 'login',
        'usuario' => $usuario,
        'senha'   => $senha,
    ]),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . SEU_TOKEN,
    ],
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$raw = curl_exec($ch);
if ($raw === false) {
    // Falha de conexão — API inacessível ou timeout
    error_log('SSO erro: ' . curl_error($ch));
    curl_close($ch);
    // Tratar: exibir mensagem de serviço indisponível
}
curl_close($ch);

$result = json_decode($raw, true);
```

### Resposta de sucesso (HTTP 200)

```json
{
  "success": true,
  "data": {
    "id":       42,
    "nome":     "João da Silva",
    "email":    "joao@empresa.com.br",
    "telefone": "(11) 99999-0000",
    "situacao": "Ativo",
    "foto":     "https://www.provitasst.com.br/gestao/storage/images/2025/09/foto-perfil-xxx.png"
  }
}
```

> `foto` retorna `null` quando o usuário não possui foto cadastrada.

### Resposta de falha (credenciais)

```json
{ "success": false, "message": "Usuário ou senha inválidos." }
```

### Campos do objeto `data` (login)

| Campo | Tipo | Descrição |
|---|---|---|
| `id` | `int` | Identificador único do usuário no Gestão. Use como chave de referência local. |
| `nome` | `string` | Nome completo |
| `email` | `string` | E-mail cadastrado |
| `telefone` | `string` | Celular de contato |
| `situacao` | `string` | `"Ativo"` = acesso permitido · `"Inativo"` = bloquear · **inicial sempre maiúscula** |
| `foto` | `string\|null` | URL completa da foto de perfil. `null` quando o usuário não possui foto. |

### ⚠️ Atenção — Comparação Case-Sensitive no campo `situacao`

O campo `situacao` é retornado com a **inicial maiúscula**: `"Ativo"` e `"Inativo"`.

Sistemas que comparam diretamente contra `"ativo"` (minúsculo) interpretam o usuário como **inativo e bloqueiam o acesso indevidamente**. Isso é uma causa comum de usuários ativos que não conseguem fazer login.

**Sempre normalize a comparação** usando `strtolower()` ou `strcasecmp()`:

```php
// ✅ Correto — normaliza antes de comparar
if (strtolower(trim($result['data']['situacao'])) !== 'ativo') {
    // Usuário inativo
}

// ❌ Errado — comparação case-sensitive quebra
if ($result['data']['situacao'] !== 'ativo') {
    // "Ativo" !== "ativo" → bloqueia usuário ativo!
}
```

### Exemplo PHP — verificação completa após login

```php
if (!$result || !$result['success']) {
    // Credenciais inválidas
    $erro = $result['message'] ?? 'Erro ao conectar ao SSO';

} elseif (strtolower(trim($result['data']['situacao'])) !== 'ativo') {
    // Usuário existe mas está inativo no Gestão
    $erro = 'Usuário inativo. Contate o administrador.';

} else {
    // Login autorizado — criar sessão
    $usuario = $result['data']; // id, nome, email, telefone, situacao, foto
    $_SESSION['sso_user'] = $usuario;
    header('Location: dashboard.php'); exit;
}
```

---

## 4. Action: `listar_usuarios`

Consulta de usuários do Gestão para seleção / vínculo em sistemas externos (ex.: atrelar conta SSO a um período no Portal do RH).

### Segurança

| Regra | Detalhe |
|---|---|
| Bearer token | **Obrigatório** — mesma chave do login |
| Público? | **Não** — só backend com o token |
| Senha | **Nunca** retornada |
| Escopo | Retorna `id`, `nome`, `usuario` (login), `email` e `telefone` (celular) |

### Quem entra na lista

- Usuários com situação **Ativo**
- Com **login** (`user_name`) preenchido  
- Limitado a **500** registros por chamada (ordenados por nome)

Inativos e cadastros sem login **não** aparecem.

### Campos da requisição

| Campo | Tipo | | Descrição |
|---|---|---|---|
| `action` | `string` | **obrigatório** | `"listar_usuarios"` |
| `q` | `string` | opcional | Filtro por nome, login ou ID exato |

### Exemplo PHP — listagem

```php
$ch = curl_init('https://www.provitasst.com.br/sso.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
        'action' => 'listar_usuarios',
        'q'      => $buscaOpcional, // ou omitir / string vazia
    ]),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . SEU_TOKEN,
    ],
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$raw    = curl_exec($ch);
$result = json_decode($raw, true);
curl_close($ch);

if (!empty($result['success'])) {
    $usuarios = $result['data']; // [ ['id'=>…, 'nome'=>…, 'usuario'=>…, 'email'=>…, 'telefone'=>…], … ]
}
```

### Resposta de sucesso (HTTP 200)

```json
{
  "success": true,
  "data": [
    {
      "id": 42,
      "nome": "João da Silva",
      "usuario": "joao.silva",
      "email": "joao@empresa.com.br",
      "telefone": "(11) 99999-0000"
    },
    {
      "id": 51,
      "nome": "Maria Souza",
      "usuario": "maria.souza",
      "email": "maria@empresa.com.br",
      "telefone": "(11) 98888-0000"
    }
  ]
}
```

### Campos de cada item em `data`

| Campo | Tipo | Descrição |
|---|---|---|
| `id` | `int` | Identificador único no Gestão (`USUA_SEQ`) — use para vínculo local |
| `nome` | `string` | Nome completo |
| `usuario` | `string` | Login no Gestão (`user_name`) |
| `email` | `string` | E-mail cadastrado. String vazia quando não houver e-mail. |
| `telefone` | `string` | Celular de contato (`USUA_CELULAR`). String vazia quando não houver celular. |

> **Não** são retornados: situação, foto, senha ou qualquer outro dado. O nome do campo do celular é `telefone`, o mesmo do `login`.

### Uso recomendado

1. Backend do sistema externo chama `listar_usuarios` com o Bearer token.
2. (Opcional) Aplica filtro local adicional — ex.: nome aproximado à pessoa do cadastro.
3. Exibe para o operador `nome`, `usuario`, `email`, `telefone` e `id`.
4. Ao confirmar o vínculo, grava o `id` (sso_id) na entidade local.

---

## 5. Códigos HTTP

| HTTP | Situação | Causa |
|---|---|---|
| `200` | OK | Requisição processada — verifique `success` no corpo |
| `401` | Unauthorized | Token Bearer ausente/incorreto **ou** usuário/senha inválidos (login) |
| `400` | Bad Request | `action` desconhecida |
| `405` | Method Not Allowed | Requisição não foi POST |
| `422` | Unprocessable | Login sem usuário ou senha |
| `500` | Server Error | Erro interno no servidor Gestão |

---

## 6. O que gravar — e o que nunca gravar

> **🚫 Nunca grave usuário ou senha no sistema externo.**
> A senha é verificada em tempo real no Gestão a cada login. O sistema externo não precisa armazená-la — e não deve.

A cada login bem-sucedido, grave ou atualize localmente apenas:

| Dado | Ação local | Motivo |
|---|---|---|
| `id` (sso_id) | gravar uma vez | Chave para identificar o usuário localmente |
| `nome` | atualizar sempre | Pode mudar no Gestão |
| `email` | atualizar sempre | Pode mudar no Gestão |
| `telefone` | atualizar sempre | Pode mudar no Gestão |
| `situacao` | atualizar sempre | Garante revogação de acesso imediata |
| `foto` | atualizar sempre | URL pode mudar no Gestão. Salvar evita chamada extra. |
| usuário / senha | **🚫 nunca** | Validados sempre na hora pelo SSO |

Para vínculo via `listar_usuarios`, em geral basta persistir o `id` (e, se útil na UI, o `nome` / `usuario` / `email` / `telefone` como espelho).

> **ℹ️** Atualizar esses dados a cada login garante que, se o administrador revogar o acesso no Gestão (`situacao: "Inativo"`), o bloqueio entra em vigor no próximo login — sem nenhuma ação manual no sistema externo.

---

## 7. Fluxos resumidos

### Login

```
1. Usuário digita usuário + senha no sistema externo
2. Backend chama a API SSO (POST JSON + Bearer token, action: login)
3. success: false                 →  exibe erro, não deixa entrar
4. strtolower(situacao) ≠ "ativo" →  conta inativa, bloqueia
5. strtolower(situacao) = "ativo"
   → Atualiza localmente: nome, email, telefone, situacao, foto
   → Cria sessão (id, nome, email...)
   → Redireciona ao sistema
```

### Listagem de usuários

```
1. Operador autenticado no sistema externo solicita vínculo / seleção
2. Backend chama SSO (POST JSON + Bearer token, action: listar_usuarios)
3. Token inválido → 401, não lista nada
4. Sucesso → recebe id, nome, usuario, email, telefone (ativos com login)
5. Sistema externo filtra/exibe e grava o id escolhido
```

> **✅** A senha **nunca sai do Gestão** e **nunca é armazenada** no sistema externo. A cada login ela é verificada em tempo real diretamente na API. A listagem também **não** expõe senha.

---

*Manual SSO — Gestão ProvitaSST · Uso interno*
