<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Narzędzia zestawu testowego asystenta (rag-eval/): wywołanie LLM zwracające
 * JSON oraz dopasowanie „kotwicy” (cytatu wzorcowego) do treści fragmentu.
 *
 * Kotwica to dosłowne zdanie z tekstu, a nie id fragmentu — dzięki temu zestaw
 * przetrwa przebudowę fragmentów (app:build-chunks) i zmianę modelu embeddingów.
 */
class RagEvalTools
{
    private const OPENROUTER_URL = 'https://openrouter.ai/api/v1/chat/completions';

    /** Ile trójek słów kotwicy musi być we fragmencie, by uznać go za trafienie. */
    public const MIN_COVERAGE = 0.6;

    public float $spentUsd = 0.0;

    public function __construct(
        #[Autowire(env: 'OPENROUTER_API_KEY')] private string $openrouterKey,
    ) {}

    /** Zapytanie do modelu z wymuszonym JSON-em; null przy błędzie. */
    public function llmJson(string $model, string $system, string $user): ?array
    {
        $ch = curl_init(self::OPENROUTER_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $model,
                'temperature' => 0.3,
                'response_format' => ['type' => 'json_object'],
                'usage' => ['include' => true],
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->openrouterKey,
                'HTTP-Referer: https://poznajwyszynskiego.pl',
                'X-Title: Poznaj Wyszynskiego (rag-eval)',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$resp) {
            throw new \RuntimeException('OpenRouter HTTP ' . $code . ': ' . substr((string) $resp, 0, 300));
        }
        $data = json_decode($resp, true);
        $this->spentUsd += (float) ($data['usage']['cost'] ?? 0);
        $content = (string) ($data['choices'][0]['message']['content'] ?? '');
        // Niektóre modele mimo response_format owijają JSON w ```json … ```.
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', trim($content));
        $json = json_decode($content, true);
        return is_array($json) ? $json : null;
    }

    /** Małe litery, bez interpunkcji i cudzysłowów, pojedyncze spacje. */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
        return trim($s);
    }

    /** Odsetek trójek słów kotwicy obecnych w treści fragmentu (0..1). */
    public static function coverage(string $anchor, string $content): float
    {
        $a = explode(' ', self::normalize($anchor));
        $haystack = ' ' . self::normalize($content) . ' ';
        if (count($a) < 3) {
            return str_contains($haystack, ' ' . implode(' ', $a) . ' ') ? 1.0 : 0.0;
        }
        $total = 0;
        $found = 0;
        for ($i = 0; $i + 2 < count($a); $i++) {
            $total++;
            if (str_contains($haystack, ' ' . $a[$i] . ' ' . $a[$i + 1] . ' ' . $a[$i + 2] . ' ')) {
                $found++;
            }
        }
        return $found / $total;
    }
}
