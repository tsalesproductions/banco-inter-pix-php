<?php

namespace App\Services;

class LojaIntegradaService
{
    private string $chaveApi;
    private string $chaveAplicacao;
    private string $baseUrl;

    public function __construct(string $chaveApi, string $chaveAplicacao, string $baseUrl = 'https://api.awsli.com.br/v1')
    {
        $this->chaveApi = trim($chaveApi);
        $this->chaveAplicacao = trim($chaveAplicacao);
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Busca dados completos do pedido na Loja Integrada pelo Número do Pedido (ex: 165)
     */
    public function getOrderByNumber(int|string $numero): array
    {
        $url = "{$this->baseUrl}/pedido/{$numero}";
        $response = $this->request('GET', $url);

        if (isset($response['error'])) {
            throw new \Exception("Erro ao buscar pedido #{$numero} na Loja Integrada: " . $response['error']);
        }

        return $response;
    }

    /**
     * Atualiza a situação do pedido na Loja Integrada (ex: marca como Pago / pedido_pago)
     */
    public function updateOrderStatus(int|string $numero, string $situacaoCodigo = 'pedido_pago'): array
    {
        $url = "{$this->baseUrl}/situacao/pedido/{$numero}";
        $payload = [
            'codigo' => $situacaoCodigo
        ];

        $response = $this->request('PUT', $url, $payload);
        return $response;
    }

    /**
     * Executa requisição HTTP cURL para a API da Loja Integrada
     */
    private function request(string $method, string $url, ?array $body = null): array
    {
        $ch = curl_init();

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            "Authorization: chave_api {$this->chaveApi} aplicacao {$this->chaveAplicacao}"
        ];

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Permitir ssl local se necessário

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
        }

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            return ['error' => 'cURL Error: ' . $curlError];
        }

        $decoded = json_decode($result, true);
        if ($httpCode >= 400) {
            $msg = is_array($decoded) ? json_encode($decoded) : $result;
            return ['error' => "HTTP {$httpCode}: {$msg}", 'status' => $httpCode];
        }

        return is_array($decoded) ? $decoded : ['response' => $result];
    }
}
