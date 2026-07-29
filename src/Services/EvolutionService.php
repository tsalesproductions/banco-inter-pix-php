<?php

namespace App\Services;

class EvolutionService
{
    private string $apiUrl;
    private string $apiKey;
    private string $instance;
    private bool $enabled;
    private string $templateFile;

    public function __construct(
        string $apiUrl,
        string $apiKey,
        string $instance,
        bool $enabled = false,
        string $templateFile = ''
    ) {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiKey = trim($apiKey);
        $this->instance = trim($instance);
        $this->enabled = $enabled;
        $this->templateFile = $templateFile;
    }

    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->apiUrl) && !empty($this->apiKey) && !empty($this->instance);
    }

    /**
     * Sanitiza e gera as variantes de números para o Brasil (com e sem o 9º dígito)
     */
    public function getPhoneVariants(?string $phone): array
    {
        if (empty($phone)) {
            return [];
        }

        // Remove caracteres não numéricos
        $digits = preg_replace('/\D/', '', $phone);
        if (empty($digits)) {
            return [];
        }

        // Se for um número do Brasil sem DDI 55 (ex: 41999999999 ou 4199999999)
        if (strlen($digits) === 10 || strlen($digits) === 11) {
            $digits = '55' . $digits;
        }

        $variants = [$digits];

        // Se for um número celular brasileiro com DDI 55 (13 dígitos: 55 + DDD + 9 + 8 dígitos)
        // Exemplo: 5521975394966 -> Gera também sem o 9º dígito: 552175394966
        if (strlen($digits) === 13 && str_starts_with($digits, '55') && substr($digits, 4, 1) === '9') {
            $without9 = substr($digits, 0, 4) . substr($digits, 5); // 55 + DDD + 8 dígitos
            $variants[] = $without9;
        } 
        // Se for um número celular brasileiro sem o 9º dígito (12 dígitos: 55 + DDD + 8 dígitos)
        // Exemplo: 552175394966 -> Gera também com o 9º dígito: 5521975394966
        elseif (strlen($digits) === 12 && str_starts_with($digits, '55')) {
            $with9 = substr($digits, 0, 4) . '9' . substr($digits, 4);
            $variants[] = $with9;
        }

        return array_unique($variants);
    }

    /**
     * Sanitiza e retorna a variante principal do número de telefone
     */
    public function sanitizePhoneNumber(?string $phone): ?string
    {
        $variants = $this->getPhoneVariants($phone);
        return $variants[0] ?? null;
    }

    /**
     * Verifica se o número existe no WhatsApp enviando todas as variantes de 9º dígito (/chat/whatsappNumbers/{instance})
     */
    public function checkIsWhatsAppNumber(string $number): ?string
    {
        $variants = $this->getPhoneVariants($number);
        if (empty($variants)) {
            return null;
        }

        $url = "{$this->apiUrl}/chat/whatsappNumbers/{$this->instance}";
        $payload = [
            'numbers' => $variants
        ];

        $res = $this->httpRequest('POST', $url, $payload);
        if (is_array($res) && count($res) > 0) {
            foreach ($res as $item) {
                if (is_array($item) && !empty($item['exists'])) {
                    // Retorna o JID retornado pela Evolution API ou o número verificado
                    return $item['jid'] ?? $item['number'] ?? $variants[0];
                }
            }
        }

        // Fallback gracioso: Retorna a variante principal sanitizada para permitir o envio direto
        return $variants[0];
    }

    /**
     * Envia mensagem de texto simples (/message/sendText/{instance})
     */
    public function sendTextMessage(string $number, string $text): array
    {
        $url = "{$this->apiUrl}/message/sendText/{$this->instance}";
        $payload = [
            'number' => $number,
            'text'   => $text
        ];

        return $this->httpRequest('POST', $url, $payload);
    }

    /**
     * Envia imagem do QR Code ou mídia via URL (/message/sendMedia/{instance})
     */
    public function sendMediaMessage(string $number, string $mediaUrl, string $caption = '', string $fileName = 'qrcode.png'): array
    {
        $url = "{$this->apiUrl}/message/sendMedia/{$this->instance}";
        $payload = [
            'number'    => $number,
            'media'     => $mediaUrl,
            'mediatype' => 'image',
            'mimetype'  => 'image/png',
            'caption'   => $caption,
            'fileName'  => $fileName
        ];

        return $this->httpRequest('POST', $url, $payload);
    }

    /**
     * Envia notificação de Pix via WhatsApp (Imagem do QR Code + Código Copia e Cola com Template)
     */
    public function sendPixNotification(array $orderData, string $pixCopyPaste, string $qrCodeUrl): array
    {
        if (!$this->isEnabled()) {
            return [
                'success' => false,
                'message' => 'Evolution API WhatsApp desativada ou não configurada no .env'
            ];
        }

        // Tenta extrair telefone do cliente dos dados do pedido da Loja Integrada
        $phone = $orderData['cliente']['celular'] 
            ?? $orderData['cliente']['telefone']
            ?? $orderData['endereco_entrega']['celular']
            ?? $orderData['endereco_entrega']['telefone']
            ?? $orderData['endereco_cobranca']['celular']
            ?? $orderData['endereco_cobranca']['telefone']
            ?? $orderData['celular'] 
            ?? $orderData['telefone'] 
            ?? null;

        $fullName = trim($orderData['cliente']['nome'] ?? $orderData['endereco_entrega']['nome'] ?? 'Cliente');
        $firstName = explode(' ', $fullName)[0] ?? 'Cliente';
        $customerName = !empty($firstName) ? mb_convert_case(mb_strtolower($firstName, 'UTF-8'), MB_CASE_TITLE, 'UTF-8') : 'Cliente';

        $orderNumber = $orderData['numero'] ?? 'N/A';
        $valorTotal = isset($orderData['valor_total']) 
            ? 'R$ ' . number_format((float)$orderData['valor_total'], 2, ',', '.') 
            : '';

        if (!$phone) {
            return [
                'success' => false,
                'message' => "Nenhum telefone encontrado nos dados do cliente para o pedido #{$orderNumber}."
            ];
        }

        $remoteJid = $this->checkIsWhatsAppNumber($phone);
        if (!$remoteJid) {
            return [
                'success' => false,
                'message' => "O número de telefone {$phone} não está registrado no WhatsApp."
            ];
        }

        // Carrega o template de mensagem .txt
        $template = '';
        if (!empty($this->templateFile) && file_exists($this->templateFile)) {
            $template = file_get_contents($this->templateFile);
        } elseif (!empty($this->templateFile) && file_exists($this->templateFile . '.example')) {
            $template = file_get_contents($this->templateFile . '.example');
        }

        if (empty($template)) {
            $template = "Olá {nome}! Obrigado por comprar na Zargo. 🛒\n\nPara sua comodidade, enviamos abaixo as informações de pagamento via Pix para o seu Pedido #{numero_pedido} (Valor: {valor_total}).\n\n📌 Você pode escanear a foto do QR Code a seguir ou copiar o código Pix Copia e Cola diretamente na legenda da imagem.\n\nZargo Ind. e Com. de Móveis Ltda - CNPJ: 38.402.195/0001-79\nwww.zargo.com.br";
        }

        // Substitui os marcadores do template (removendo {pix_copy_paste} do texto de introdução)
        $introMessageText = str_replace(
            ['{nome}', '{numero_pedido}', '{pix_copy_paste}', '{valor_total}'],
            [$customerName, $orderNumber, '', $valorTotal],
            $template
        );
        $introMessageText = trim(str_replace("\n\n\n", "\n\n", $introMessageText));

        $results = [];

        // 1. Envia Primeiro a Mensagem Explicativa em Texto
        $textRes = $this->sendTextMessage($remoteJid, $introMessageText);
        $results['intro_text'] = $textRes;

        // 2. Envia a Imagem do QR Code contendo o Código Pix Copia e Cola ISOLADO na Legenda (caption)
        if (!empty($qrCodeUrl)) {
            $caption = !empty($pixCopyPaste) ? $pixCopyPaste : "QR Code Pix - Pedido #{$orderNumber}";
            $mediaRes = $this->sendMediaMessage($remoteJid, $qrCodeUrl, $caption, "qrcode_{$orderNumber}.png");
            $results['media'] = $mediaRes;
        }

        return [
            'success' => true,
            'remote_jid' => $remoteJid,
            'details' => $results
        ];
    }

    /**
     * Executa requisição HTTP cURL genérica para a Evolution API
     */
    private function httpRequest(string $method, string $url, ?array $body = null): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'apikey: ' . $this->apiKey
            ]
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return [
                'error' => "Erro cURL Evolution API: {$error}",
                'status' => 0
            ];
        }

        $decoded = json_decode((string) $response, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return [
            'status' => $httpCode,
            'raw'    => $response
        ];
    }
}
