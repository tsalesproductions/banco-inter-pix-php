<?php

namespace App\Services;

class WppQueueRepository
{
    private string $filePath;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!file_exists($this->filePath)) {
            file_put_contents($this->filePath, json_encode([], JSON_PRETTY_PRINT));
        }
    }

    /**
     * Carrega todos os itens da fila com lock de leitura
     */
    public function getAll(): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }

        $fp = fopen($this->filePath, 'r');
        if (!$fp) {
            return [];
        }

        flock($fp, LOCK_SH);
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Adiciona ou atualiza um item na fila de envio de WhatsApp com lock exclusivo
     */
    public function enqueue(
        int|string $orderNumber,
        string $pixCode,
        string $pixQrCode,
        array $orderData,
        int $delayMinutes = 10
    ): array {
        $orderNumber = (int)$orderNumber;
        $now = time();
        $scheduledTime = $now + ($delayMinutes * 60);
        $scheduledAtStr = date('Y-m-d H:i:s', $scheduledTime);

        $fp = fopen($this->filePath, 'c+');
        if (!$fp) {
            throw new \RuntimeException("Não foi possível abrir o arquivo de fila: {$this->filePath}");
        }

        $resultItem = null;

        if (flock($fp, LOCK_EX)) {
            $content = stream_get_contents($fp);
            $data = json_decode($content, true);
            if (!is_array($data)) {
                $data = [];
            }

            // Verifica se já existe um item 'pending' para o mesmo número de pedido
            $foundIndex = null;
            foreach ($data as $index => $item) {
                if ((int)($item['order_number'] ?? 0) === $orderNumber && ($item['status'] ?? '') === 'pending') {
                    $foundIndex = $index;
                    break;
                }
            }

            if ($foundIndex !== null) {
                // Atualiza o item existente com novos dados e novo scheduled_at se necessário
                $data[$foundIndex]['pix_code'] = $pixCode;
                $data[$foundIndex]['pix_qrcode'] = $pixQrCode;
                $data[$foundIndex]['order_data'] = $orderData;
                $data[$foundIndex]['updated_at'] = date('Y-m-d H:i:s');
                $data[$foundIndex]['scheduled_at'] = $scheduledAtStr;
                $resultItem = $data[$foundIndex];
            } else {
                // Cria novo registro de fila
                $newItem = [
                    'id'           => 'q_' . $orderNumber . '_' . $now,
                    'order_number' => $orderNumber,
                    'pix_code'     => $pixCode,
                    'pix_qrcode'   => $pixQrCode,
                    'order_data'   => $orderData,
                    'status'       => 'pending', // pending, sent, skipped_paid, skipped_cancelled, failed
                    'created_at'   => date('Y-m-d H:i:s'),
                    'scheduled_at' => $scheduledAtStr,
                    'processed_at' => null,
                    'attempts'     => 0,
                    'log'          => null
                ];
                $data[] = $newItem;
                $resultItem = $newItem;
            }

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);

            return $resultItem;
        }

        fclose($fp);
        throw new \RuntimeException("Não foi possível adquirir lock exclusivo no arquivo de fila.");
    }

    /**
     * Retorna todos os itens pendentes que já atingiram o horário de agendamento (scheduled_at <= now)
     */
    public function getDueItems(): array
    {
        $all = $this->getAll();
        $now = time();
        $due = [];

        foreach ($all as $item) {
            if (($item['status'] ?? '') === 'pending') {
                $scheduledTs = isset($item['scheduled_at']) ? strtotime($item['scheduled_at']) : 0;
                if ($scheduledTs <= $now) {
                    $due[] = $item;
                }
            }
        }

        return $due;
    }

    /**
     * Atualiza o status de um item da fila pelo seu ID exclusivo
     */
    public function updateStatus(string $id, string $status, array $extraData = []): bool
    {
        $fp = fopen($this->filePath, 'c+');
        if (!$fp) {
            return false;
        }

        if (flock($fp, LOCK_EX)) {
            $content = stream_get_contents($fp);
            $data = json_decode($content, true);
            if (!is_array($data)) {
                $data = [];
            }

            $updated = false;
            foreach ($data as $index => $item) {
                if (($item['id'] ?? '') === $id) {
                    $data[$index]['status'] = $status;
                    $data[$index]['processed_at'] = date('Y-m-d H:i:s');
                    foreach ($extraData as $k => $v) {
                        $data[$index][$k] = $v;
                    }
                    $updated = true;
                    break;
                }
            }

            if ($updated) {
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                fflush($fp);
            }

            flock($fp, LOCK_UN);
            fclose($fp);
            return $updated;
        }

        fclose($fp);
        return false;
    }
}
