<?php

namespace App\Services;

class PixCacheRepository
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
     * Carrega todas as cobranças salvas do JSON com lock de leitura
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
     * Busca Pix ativo (não expirado e pendente de pagamento) para um número de pedido
     */
    public function getActivePixForOrder(int|string $orderNumber): ?array
    {
        $orderNumber = (string) $orderNumber;
        $records = $this->getAll();
        $now = time();

        foreach ($records as $record) {
            if ((string)($record['order_number'] ?? '') === $orderNumber) {
                $expiresAt = isset($record['expires_at']) ? strtotime($record['expires_at']) : 0;
                $status = strtoupper($record['status'] ?? '');

                // Se já estiver pago, retorna o registro indicando que foi pago
                if ($status === 'PAID' || $status === 'CONCLUIDA') {
                    return $record;
                }

                // Se ainda for pendente e a data de expiração for maior que o timestamp atual
                if ($status === 'PENDING' || $status === 'ATIVA') {
                    if ($expiresAt > $now) {
                        return $record;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Busca Pix pelo TXID
     */
    public function getByTxid(string $txid): ?array
    {
        $records = $this->getAll();
        foreach ($records as $record) {
            if (($record['txid'] ?? '') === $txid) {
                return $record;
            }
        }
        return null;
    }

    /**
     * Salva ou atualiza um registro de Pix com lock exclusivo
     */
    public function save(array $record): bool
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

            $txid = $record['txid'] ?? null;
            $updated = false;

            if ($txid) {
                foreach ($data as $index => $item) {
                    if (($item['txid'] ?? null) === $txid) {
                        $data[$index] = array_merge($item, $record, ['updated_at' => date('Y-m-d H:i:s')]);
                        $updated = true;
                        break;
                    }
                }
            }

            if (!$updated) {
                $record['created_at'] = $record['created_at'] ?? date('Y-m-d H:i:s');
                $record['updated_at'] = date('Y-m-d H:i:s');
                $data[] = $record;
            }

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
            return true;
        }

        fclose($fp);
        return false;
    }

    /**
     * Atualiza o status de um Pix pelo TXID
     */
    public function updateStatus(string $txid, string $newStatus, array $extraData = []): bool
    {
        $record = $this->getByTxid($txid);
        if (!$record) {
            return false;
        }

        $record['status'] = strtoupper($newStatus);
        foreach ($extraData as $key => $val) {
            $record[$key] = $val;
        }

        return $this->save($record);
    }
}
