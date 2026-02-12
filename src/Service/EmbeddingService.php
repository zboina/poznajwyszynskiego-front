<?php

namespace App\Service;

class EmbeddingService
{
    private const OLLAMA_URL = 'http://localhost:11434/api/embeddings';
    private const MODEL = 'all-minilm';

    public function getEmbedding(string $text): ?array
    {
        $payload = json_encode([
            'model' => self::MODEL,
            'prompt' => $text,
        ]);

        $ch = curl_init(self::OLLAMA_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return null;
        }

        $data = json_decode($response, true);

        return $data['embedding'] ?? null;
    }
}
