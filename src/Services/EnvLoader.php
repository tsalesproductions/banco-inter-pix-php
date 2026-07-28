<?php

namespace App\Services;

class EnvLoader
{
    /**
     * Carrega as variáveis de ambiente a partir do arquivo .env
     */
    public static function load(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Ignora comentários
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            // Remove comentário inline se houver (respeitando aspas)
            if (str_contains($line, '#') && !str_contains($line, '"#') && !str_contains($line, "'#")) {
                $parts = explode('#', $line, 2);
                $line = trim($parts[0]);
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            // Remove aspas envolventes
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}
