<?php

namespace App\Service;

/**
 * Retrieval-Augmented answering over Wyszyński's collected works.
 * Retrieves chunks, asks a local LLM (Ollama) to answer ONLY from them, and
 * returns the answer together with numbered citations that carry the printed
 * page reference and a link target to the source document.
 *
 * Generation provider is switchable: 'ollama' (local, nothing leaves the
 * server) or 'openrouter' (cloud, fast/cheap — only the question + retrieved
 * passages are sent out). Embeddings stay local regardless. Configured via env
 * (RAG_LLM_PROVIDER, RAG_LLM_MODEL, OPENROUTER_API_KEY).
 */
class RagAnswerer
{
    private const OLLAMA_CHAT_URL = 'http://localhost:11434/api/chat';
    private const OPENROUTER_URL = 'https://openrouter.ai/api/v1/chat/completions';

    private const SYSTEM_PROMPT = <<<TXT
        Jesteś asystentem naukowym wydania „Dzieł Zebranych" kardynała Stefana Wyszyńskiego.
        Odpowiadasz WYŁĄCZNIE na podstawie ponumerowanych fragmentów źródłowych podanych poniżej.

        Zasady:
        - Nie korzystaj z wiedzy spoza fragmentów. Nie zmyślaj faktów, dat ani cytatów.
        - Po każdym twierdzeniu podaj odnośnik do użytego fragmentu w nawiasie kwadratowym, np. [1] lub [2][3].
        - Jeśli fragmenty nie zawierają odpowiedzi, napisz wprost: „W udostępnionych fragmentach nie ma odpowiedzi na to pytanie."
        - Odpowiadaj rzeczowo, po polsku, w tonie odpowiednim dla tekstów Prymasa.
        - Gdy cytujesz dosłownie, użyj cudzysłowu.
        - O autorze pisz ZAWSZE z szacunkiem i z tytułem: „Kardynał Wyszyński", „Ksiądz Kardynał Wyszyński", „Kardynał Stefan Wyszyński", „Prymas Wyszyński" lub „Prymas Tysiąclecia". NIGDY nie używaj samego nazwiska („Wyszyński uważał…"). Pierwszą wzmiankę w odpowiedzi podaj w pełnej formie (np. „Ksiądz Kardynał Stefan Wyszyński"), w dalszych można skrótowo „Kardynał Wyszyński" lub „Prymas".
        TXT;

    public function __construct(
        private ChunkRetriever $retriever,
        private string $provider = 'ollama',
        private string $openrouterModel = 'google/gemini-2.5-flash',
        private string $ollamaModel = 'llama3.1:8b',
        private string $openrouterKey = '',
    ) {}

    private function useOpenRouter(): bool
    {
        return $this->provider === 'openrouter' && $this->openrouterKey !== '';
    }

    /**
     * @return array{answer:string, citations:array<int,array>, used:bool}
     */
    public function answer(string $question, int $k = 8, ?int $volumeId = null): array
    {
        $prep = $this->prepare($question, $k, $volumeId);
        if (!$prep['citations']) {
            return ['answer' => $prep['empty'], 'citations' => [], 'used' => false];
        }
        $answer = $this->chat(self::SYSTEM_PROMPT, $prep['user']);
        return [
            'answer' => $answer ?? 'Nie udało się wygenerować odpowiedzi (model niedostępny).',
            'citations' => $prep['citations'],
            'used' => $answer !== null,
        ];
    }

    /**
     * Retrieve + build prompt without generating. Used by the streaming endpoint.
     * @return array{user:string, citations:array<int,array>, empty:string}
     */
    public function prepare(string $question, int $k = 8, ?int $volumeId = null): array
    {
        $chunks = $this->retriever->retrieve($question, $k, $volumeId);
        if (!$chunks) {
            return ['user' => '', 'citations' => [], 'empty' => 'W udostępnionych fragmentach nie ma odpowiedzi na to pytanie.'];
        }
        [$context, $citations] = $this->buildContext($chunks);
        return [
            'user' => "PYTANIE:\n{$question}\n\nFRAGMENTY ŹRÓDŁOWE:\n{$context}",
            'citations' => $citations,
            'empty' => '',
        ];
    }

    public function systemPrompt(): string
    {
        return self::SYSTEM_PROMPT;
    }

    /**
     * Stream tokens from the local model. $onToken is called with each text delta.
     */
    public function streamChat(string $system, string $user, callable $onToken): void
    {
        if ($this->useOpenRouter()) {
            $this->streamOpenRouter($system, $user, $onToken);
        } else {
            $this->streamOllama($system, $user, $onToken);
        }
    }

    private function streamOllama(string $system, string $user, callable $onToken): void
    {
        $payload = json_encode([
            'model' => $this->ollamaModel,
            'stream' => true,
            'options' => ['temperature' => 0.2, 'num_ctx' => 8192, 'num_predict' => 800],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ]);

        $buffer = '';
        $ch = curl_init(self::OLLAMA_CHAT_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 600,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$buffer, $onToken) {
                $buffer .= $data;
                // Ollama streams newline-delimited JSON objects.
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $obj = json_decode($line, true);
                    $tok = $obj['message']['content'] ?? '';
                    if ($tok !== '') {
                        $onToken($tok);
                    }
                }
                return strlen($data);
            },
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * @param array<int,array> $chunks
     * @return array{0:string, 1:array<int,array>}
     */
    private function buildContext(array $chunks): array
    {
        $blocks = [];
        $citations = [];
        foreach ($chunks as $i => $c) {
            $n = $i + 1;
            $ref = $this->citationLabel($c);
            $blocks[] = "[{$n}] ({$ref})\n" . trim($c['content']);
            $citations[] = [
                'n' => $n,
                'document_id' => $c['document_id'],
                'chunk_id' => $c['id'],
                'title' => $c['title'],
                'slug' => $c['slug'],
                'volume_number' => $c['volume_number'],
                'page_start' => $c['page_start'],
                'page_end' => $c['page_end'],
                'label' => $ref,
            ];
        }
        return [implode("\n\n", $blocks), $citations];
    }

    private function citationLabel(array $c): string
    {
        $label = 'Tom ' . $this->roman($c['volume_number']);
        if ($c['page_start'] !== null) {
            $label .= ', s. ' . $c['page_start'];
            if ($c['page_end'] !== null && $c['page_end'] !== $c['page_start']) {
                $label .= '–' . $c['page_end'];
            }
        }
        return $label;
    }

    private function roman(int $n): string
    {
        $map = [1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD', 100 => 'C', 90 => 'XC',
                50 => 'L', 40 => 'XL', 10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I'];
        $out = '';
        foreach ($map as $val => $sym) {
            while ($n >= $val) {
                $out .= $sym;
                $n -= $val;
            }
        }
        return $out ?: (string) $n;
    }

    private function chat(string $system, string $user): ?string
    {
        return $this->useOpenRouter()
            ? $this->chatOpenRouter($system, $user)
            : $this->chatOllama($system, $user);
    }

    private function chatOllama(string $system, string $user): ?string
    {
        $payload = json_encode([
            'model' => $this->ollamaModel,
            'stream' => false,
            'options' => [
                'temperature' => 0.2,
                'num_ctx' => 8192,      // fit retrieved context (Ollama default 2048 would truncate it)
                'num_predict' => 800,
            ],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ]);

        $ch = curl_init(self::OLLAMA_CHAT_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 600,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$resp) {
            return null;
        }
        $data = json_decode($resp, true);
        $content = $data['message']['content'] ?? null;
        return is_string($content) ? trim($content) : null;
    }

    private function chatOpenRouter(string $system, string $user): ?string
    {
        $ch = curl_init(self::OPENROUTER_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->openrouterModel,
                'temperature' => 0.2,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
            ]),
            CURLOPT_HTTPHEADER => $this->openRouterHeaders(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$resp) {
            return null;
        }
        $content = json_decode($resp, true)['choices'][0]['message']['content'] ?? null;
        return is_string($content) ? trim($content) : null;
    }

    private function streamOpenRouter(string $system, string $user, callable $onToken): void
    {
        $payload = json_encode([
            'model' => $this->openrouterModel,
            'temperature' => 0.2,
            'stream' => true,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ]);

        $buffer = '';
        $ch = curl_init(self::OPENROUTER_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $this->openRouterHeaders(),
            CURLOPT_TIMEOUT => 120,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$buffer, $onToken) {
                $buffer .= $data;
                // OpenAI-style SSE: "data: {json}\n\n", terminated by "data: [DONE]".
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    if ($line === '' || !str_starts_with($line, 'data:')) {
                        continue;
                    }
                    $json = trim(substr($line, 5));
                    if ($json === '[DONE]') {
                        continue;
                    }
                    $tok = json_decode($json, true)['choices'][0]['delta']['content'] ?? '';
                    if ($tok !== '') {
                        $onToken($tok);
                    }
                }
                return strlen($data);
            },
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    private function openRouterHeaders(): array
    {
        return [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->openrouterKey,
            'HTTP-Referer: https://poznajwyszynskiego.pl',
            'X-Title: Poznaj Wyszynskiego',
        ];
    }
}
