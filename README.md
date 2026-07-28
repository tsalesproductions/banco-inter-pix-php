# ⚡ Integração Inteligente Pix Banco Inter + Loja Integrada (PHP)

Solução backend em **PHP puro** (sem frameworks pesados) para geração inteligente de Cobranças Pix com Vencimento (**Pix Cobv 24h**) integrando a **API v2 do Banco Inter** com a **API v1 da Loja Integrada**.

Contém mecanismo de **Cache Inteligente de 24h**, **mTLS OAuth 2.0**, **Listener e Gestor de Webhooks com Auditoria**, **Tratamento Automático de Pagamentos e Estornos**, e **Script JS de Injeção Visual no Checkout** (`qrpix-store.js`).

---

## 🚀 Destaques da Aplicação

1. **Reaproveitamento Inteligente de Pix (Cache Local 24h)**:
   - Se o cliente recarregar a página de checkout ou abrir novamente o mesmo pedido, a aplicação **não gera cobranças duplicadas no Banco Inter**.
   - Valida no cache local (`storage/data/pix_cache.json`) com trava de arquivo (`flock`) se existe um Pix ativo (< 24h) e devolve o mesmo QR Code e Pix Copia e Cola instantaneamente.

2. **Integração Completa com a Loja Integrada**:
   - Busca o pedido via `GET https://api.awsli.com.br/v1/pedido/{numero}`.
   - Extrai automaticamente o valor total, dados do cliente (nome, e-mail, CPF/CNPJ).
   - **Pagamentos**: Atualiza a situação para `pedido_pago` quando o Webhook do Banco Inter notifica a liquidação.
   - **Estornos / Devoluções**: Identifica automáticas de devolução e altera a situação para `pedido_cancelado`.

3. **Segurança Reforçada**:
   - Autenticação bancária mTLS (Certificado `.crt` e chave privada `.key`).
   - Bloqueio multi-camadas via `.htaccess` e regras no roteador para proteger arquivos sensíveis (`.env`, `.key`, `.crt`, `storage/`, `config/`).

4. **Auditoria e Logs em Tempo Real**:
   - Grava 100% dos disparos de Webhook recebidos no arquivo `storage/logs/webhooks.log` com IP de origem, User-Agent, dados mTLS e payload JSON bruto.

5. **Script de Injeção Visual no Checkout (`qrpix-store.js`)**:
   - Script em JavaScript/jQuery para injetar o QR Code do Banco Inter no checkout da Loja Integrada com cópia em 1 clique e blindagem visual contra *flicker*.

---

## 🛠️ Requisitos e Estrutura do Projeto

- **PHP 8.0+** (extensão `curl` e `json` habilitadas).
- **Servidor Web**: Apache (com `mod_rewrite`) ou Nginx.
- **Certificados Banco Inter**: Arquivos `.crt` e `.key` fornecidos no Portal do Desenvolvedor Inter.

```
inter-pix-php/
├── config/
│   └── app.php                  # Configurações e fusão de variáveis de ambiente
├── src/
│   ├── Controllers/
│   │   ├── PixController.php    # Endpoint de Geração Inteligente e Consulta Pix
│   │   └── WebhookController.php # Listener de notificações, auditoria e CRUD de Webhooks
│   ├── Services/
│   │   ├── EnvLoader.php        # Parser nativo de arquivos .env
│   │   ├── InterPixService.php   # Cliente OAuth v2 mTLS, Cobv e Webhooks Banco Inter
│   │   ├── LojaIntegradaService.php # Cliente API Loja Integrada (Consulta e Atualização)
│   │   └── PixCacheRepository.php # Repositório JSON com trava de arquivo (flock)
│   └── Router.php               # Micro Rroteador HTTP e guardião de segurança CORS/403
├── public/
│   ├── index.php                # Entrypoint principal & Painel de Controle Web
│   └── assets/
├── storage/
│   ├── certs/                   # Certificados mTLS (inter_cert.crt e inter_cert.key)
│   ├── data/                    # Cache local (pix_cache.json e inter_token.json)
│   └── logs/                    # Arquivo de log de auditoria (webhooks.log)
├── .env.example                 # Exemplo de variáveis de ambiente
├── .gitignore                   # Regras de exclusão do Git
├── cli.php                      # Utilitário CLI para testes no terminal
├── qrpix-store.js               # Script de injeção JS na Loja Integrada
└── README.md                    # Documentação do projeto
```

---

## ⚙️ Instalação e Configuração

### 1. Clonar o Repositório
```bash
git clone https://github.com/seu-usuario/inter-pix-php.git
cd inter-pix-php
```

### 2. Configurar o Arquivo `.env`
Copie o arquivo de exemplo `.env.example` para `.env`:

```bash
cp .env.example .env
```

Edite o arquivo `.env` com suas credenciais:

```env
# ==========================================
# Configurações do Banco Inter
# ==========================================
INTER_CLIENT_ID=seu_client_id_inter
INTER_CLIENT_SECRET=seu_client_secret_inter
INTER_PIX_KEY=sua_chave_pix_cnj_ou_cpf
INTER_ENV=production # production ou sandbox

# Caminho dos certificados mTLS na pasta storage/certs/
INTER_CERT_PATH=storage/certs/inter_cert.crt
INTER_KEY_PATH=storage/certs/inter_cert.key
INTER_CERT_PASSPHRASE=

# ==========================================
# Configurações da Loja Integrada
# ==========================================
LOJA_INTEGRADA_CHAVE_API=sua_chave_api_20_caracteres
LOJA_INTEGRADA_CHAVE_APLICACAO=sua_chave_aplicacao_uuid

# ==========================================
# Configurações da Aplicação
# ==========================================
APP_ENV=development
APP_URL=http://localhost/inter-pix-php
```

### 3. Adicionar Certificados mTLS
Coloque o certificado público (`inter_cert.crt`) e a chave privada (`inter_cert.key`) na pasta `storage/certs/`.

---

## 📡 Endpoints da API

| Método | Endpoint | Descrição |
| :--- | :--- | :--- |
| `POST` / `GET` | `/api/pix/generate?numero={id}` | Gera ou recupera Pix inteligente de um pedido. |
| `POST` | `/api/webhook/inter` | Webhook do Banco Inter (Recebe avisos de pagamento e estornos). |
| `GET` | `/api/webhook/logs` | Retorna o log de auditoria dos Webhooks em JSON. |
| `GET` | `/api/webhooks` | Consulta a URL do Webhook cadastrado no Banco Inter. |
| `POST` | `/api/webhooks` | Cadastra ou altera a URL do Webhook no Banco Inter. |
| `DELETE` | `/api/webhooks` | Remove a URL do Webhook cadastrado no Banco Inter. |

---

## 💻 Utilitário de Linha de Comando (`cli.php`)

Você pode gerenciar e simular a aplicação diretamente no terminal:

```bash
# Validar arquivo .env e ambiente
php cli.php test-env

# Testar autenticação OAuth v2 mTLS com Banco Inter
php cli.php test-inter

# Consultar pedido na Loja Integrada
php cli.php test-li 28491

# Gerar/Recuperar Pix de um pedido no terminal
php cli.php generate-pix 28491

# Consultar Webhook cadastrado no Banco Inter
php cli.php list-webhooks

# Cadastrar Webhook no Banco Inter
php cli.php register-webhook https://seu-dominio.com/api/webhook/inter

# Deletar Webhook no Banco Inter
php cli.php delete-webhook

# Simular pagamento de um pedido (Webhook local)
php cli.php simulate-payment 28491

# Simular estorno/devolução de um pedido (Webhook local)
php cli.php simulate-refund 28491
```

---

## 🔒 Segurança em Primeiro Lugar

- O arquivo `.gitignore` foi configurado para **NUNCA** enviar suas chaves de API, credenciais do `.env`, tokens salvos ou certificados `.crt`/`.key` para repositórios públicos.
- Todos os endpoints de armazenamento interno (`storage/`, `config/`, `src/`) possuem arquivos `.htaccess` dedicados com `Require all denied` para prevenir qualquer acesso externo via HTTP.

---

## 📄 Licença

Este projeto está licenciado sob a licença MIT. Sinta-se livre para usar e modificar na sua loja!
