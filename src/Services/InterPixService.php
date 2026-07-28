<?php

namespace App\Services;

class InterPixService
{
    private string $clientId;
    private string $clientSecret;
    private string $pixKey;
    private string $baseUrl;
    private string $tokenFilePath;
    private ?string $certPath;
    private ?string $keyPath;
    private ?string $certPassphrase;

    public function __construct(
        string $clientId,
        string $clientSecret,
        string $pixKey,
        string $baseUrl = 'https://cdpj.partners.bancointer.com.br',
        string $tokenFilePath = __DIR__ . '/../../storage/data/inter_token.json',
        ?string $certPath = null,
        ?string $keyPath = null,
        ?string $certPassphrase = null
    ) {
        $this->clientId = trim($clientId);
        $this->clientSecret = trim($clientSecret);
        $this->pixKey = trim($pixKey);
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->tokenFilePath = $tokenFilePath;
        $this->certPath = $certPath ? trim($certPath) : null;
        $this->keyPath = $keyPath ? trim($keyPath) : null;
        $this->certPassphrase = $certPassphrase ? trim($certPassphrase) : null;
    }

    /**
     * Aplica os certificados mTLS na conexão cURL se estiverem configurados e existirem
     */
    private function applyCertificates($ch): void
    {
        if ($this->certPath) {
            $cert = (str_starts_with($this->certPath, '/') || str_contains($this->certPath, ':'))
                ? $this->certPath
                : __DIR__ . '/../../' . ltrim($this->certPath, '/\\');

            if (file_exists($cert)) {
                curl_setopt($ch, CURLOPT_SSLCERT, $cert);
            }
        }

        if ($this->keyPath) {
            $key = (str_starts_with($this->keyPath, '/') || str_contains($this->keyPath, ':'))
                ? $this->keyPath
                : __DIR__ . '/../../' . ltrim($this->keyPath, '/\\');

            if (file_exists($key)) {
                curl_setopt($ch, CURLOPT_SSLKEY, $key);
            }
        }

        if ($this->certPassphrase) {
            curl_setopt($ch, CURLOPT_KEYPASSWD, $this->certPassphrase);
        }
    }

    /**
     * Obtém o Token OAuth v2 do Banco Inter com suporte a cache local
     */
    public function getAccessToken(): string
    {
        // 1. Tenta carregar o token do cache local se ainda for válido
        if (file_exists($this->tokenFilePath)) {
            $data = json_decode(file_get_contents($this->tokenFilePath), true);
            if (is_array($data) && isset($data['access_token'], $data['expires_at'])) {
                // Adiciona margem de segurança de 60 segundos
                if ($data['expires_at'] > (time() + 60)) {
                    return $data['access_token'];
                }
            }
        }

        // 2. Solicita novo token via POST
        $tokenUrl = "{$this->baseUrl}/oauth/v2/token";
        $payload = http_build_query([
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type'    => 'client_credentials',
            'scope'         => 'cobv.write cobv.read webhook.read webhook.write'
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $tokenUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $this->applyCertificates($ch);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($result === false) {
            if ($curlErrno === CURLE_OPERATION_TIMEDOUT || $curlErrno === 28) {
                throw new \Exception("Timeout ao conectar ao Banco Inter (20s). O firewall do Banco Inter exige o envio dos arquivos de certificado digital (.crt / .key) na requisição cURL para realizar o handshake mTLS e liberar a conexão. Verifique se os arquivos INTER_CERT_PATH e INTER_KEY_PATH foram configurados no .env.");
            }
            throw new \Exception("Erro cURL ao solicitar token do Banco Inter: {$curlError}");
        }

        $json = json_decode($result, true);

        if ($httpCode !== 200 || !isset($json['access_token'])) {
            $errorMsg = !empty($result) ? $result : 'O Banco Inter recusou a autenticação (HTTP 400). Na API do Banco Inter, a geração do token exige obrigatoriamente o envio do Certificado Digital (.crt e .key) gerado no painel da aplicação no Internet Banking PJ. Baixe os certificados no Internet Banking e configure INTER_CERT_PATH e INTER_KEY_PATH no seu .env.';
            throw new \Exception("Erro de autenticação Banco Inter (HTTP {$httpCode}): {$errorMsg}");
        }

        $expiresIn = (int)($json['expires_in'] ?? 3600);
        $json['expires_at'] = time() + $expiresIn;

        // Salva token em disco
        $dir = dirname($this->tokenFilePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->tokenFilePath, json_encode($json, JSON_PRETTY_PRINT));

        return $json['access_token'];
    }

    /**
     * Cria ou Atualiza uma Cobrança Pix com Vencimento (PUT /pix/v2/cobv/{txid})
     */
    public function createCobv(string $txid, array $orderData, int $validadeHoras = 24): array
    {
        $url = "{$this->baseUrl}/pix/v2/cobv/{$txid}";

        // Extrai e formata dados do cliente
        $cliente = $orderData['cliente'] ?? [];
        $endereco = $orderData['endereco_entrega'] ?? [];

        $nome = trim($cliente['nome'] ?? $endereco['nome'] ?? 'Cliente Loja Integrada');
        $cpf = preg_replace('/\D/', '', $cliente['cpf'] ?? $endereco['cpf'] ?? '');
        $cnpj = preg_replace('/\D/', '', $cliente['cnpj'] ?? $endereco['cnpj'] ?? '');

        // Formata data de vencimento (YYYY-MM-DD)
        $dataVencimento = date('Y-m-d', strtotime("+{$validadeHoras} hours"));

        // Formata valor com duas casas decimais
        $valorTotal = number_format((float)($orderData['valor_total'] ?? 0.00), 2, '.', '');

        $devedor = [
            'nome' => substr($nome, 0, 140)
        ];

        if (!empty($cpf)) {
            $devedor['cpf'] = $cpf;
        } elseif (!empty($cnpj)) {
            $devedor['cnpj'] = $cnpj;
        } else {
            // Fallback genérico caso o cliente não tenha informado CPF na LI
            $devedor['cpf'] = '00000000000';
        }

        $numeroPedido = $orderData['numero'] ?? '';

        $body = [
            'calendario' => [
                'dataDeVencimento' => $dataVencimento,
                'validadeAposVencimento' => 0
            ],
            'devedor' => $devedor,
            'valor' => [
                'original' => $valorTotal
            ],
            'chave' => $this->pixKey,
            'solicitacaoPagador' => "Pagamento do Pedido #{$numeroPedido}",
            'infoAdicionais' => [
                [
                    'nome' => 'Número do Pedido',
                    'valor' => (string) $numeroPedido
                ]
            ]
        ];

        $response = $this->authenticatedRequest('PUT', $url, $body);

        if (isset($response['error'])) {
            throw new \Exception("Erro ao criar Cobv no Banco Inter: " . $response['error']);
        }

        return $response;
    }

    /**
     * Consulta uma Cobrança com Vencimento pelo TXID (GET /pix/v2/cobv/{txid})
     */
    public function getCobv(string $txid): array
    {
        $url = "{$this->baseUrl}/pix/v2/cobv/{$txid}";
        return $this->authenticatedRequest('GET', $url);
    }

    /**
     * Cadastra ou altera o Webhook do Pix no Banco Inter (PUT /pix/v2/webhook/{chave})
     */
    public function registerWebhook(string $webhookUrl, ?string $chave = null): array
    {
        $chavePix = $chave ?? $this->pixKey;
        $url = "{$this->baseUrl}/pix/v2/webhook/{$chavePix}";
        $body = [
            'webhookUrl' => $webhookUrl
        ];

        return $this->authenticatedRequest('PUT', $url, $body);
    }

    /**
     * Obtém informações do Webhook cadastrado para uma chave Pix (GET /pix/v2/webhook/{chave})
     */
    public function getWebhook(?string $chave = null): array
    {
        $chavePix = $chave ?? $this->pixKey;
        $url = "{$this->baseUrl}/pix/v2/webhook/{$chavePix}";
        $res = $this->authenticatedRequest('GET', $url);

        // Trata a resposta 404 nativa do Banco Inter quando não existe webhook cadastrado
        if (isset($res['status']) && (int)$res['status'] === 404) {
            return [
                'registered' => false,
                'message'    => "Nenhum webhook cadastrado no Banco Inter para a chave Pix '{$chavePix}'.",
                'chave'      => $chavePix
            ];
        }

        return $res;
    }

    /**
     * Lista webhooks cadastrados (GET /pix/v2/webhook)
     */
    public function listWebhooks(int $itensPorPagina = 10, int $paginaAtual = 0): array
    {
        $url = "{$this->baseUrl}/pix/v2/webhook?itensPorPagina={$itensPorPagina}&paginaAtual={$paginaAtual}";
        return $this->authenticatedRequest('GET', $url);
    }

    /**
     * Cancela/Deleta o Webhook da chave Pix no Banco Inter (DELETE /pix/v2/webhook/{chave})
     */
    public function deleteWebhook(?string $chave = null): array
    {
        $chavePix = $chave ?? $this->pixKey;
        $url = "{$this->baseUrl}/pix/v2/webhook/{$chavePix}";
        return $this->authenticatedRequest('DELETE', $url);
    }

    /**
     * Gera um TXID único de 26 a 35 caracteres alfanuméricos válidos para o Pix
     */
    public static function generateTxid(int|string $orderNumber): string
    {
        $prefix = "ORDER" . sprintf("%06d", $orderNumber);
        $random = bin2hex(random_bytes(10)); // 20 hex chars
        $txid = strtoupper(substr($prefix . $random, 0, 32));
        return $txid;
    }

    /**
     * Executa requisição HTTP autenticada via Bearer Token com cURL
     */
    private function authenticatedRequest(string $method, string $url, ?array $body = null): array
    {
        $token = $this->getAccessToken();
        $ch = curl_init();

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            "Authorization: Bearer {$token}"
        ];

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $this->applyCertificates($ch);

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
        } elseif (strtoupper($method) === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
        } elseif (strtoupper($method) === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            return ['error' => 'cURL Error: ' . $curlError];
        }

        if (empty($result) && ($httpCode === 200 || $httpCode === 204)) {
            return ['success' => true, 'status' => $httpCode];
        }

        $decoded = json_decode($result, true);
        if ($httpCode >= 400) {
            $msg = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE) : $result;
            if (empty(trim($msg))) {
                $msg = $result;
            }
            return ['error' => "HTTP {$httpCode}: {$msg}", 'status' => $httpCode, 'raw' => $result];
        }

        return is_array($decoded) ? $decoded : ['response' => $result];
    }
}
