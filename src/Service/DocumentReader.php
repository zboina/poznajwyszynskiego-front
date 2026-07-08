<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Renderowanie treści dokumentu do czytania: akapity, znaczniki stron [s. N]
 * i przypisy — używane przez widok Biblioteki (VIP). Logika akapitów/stron/
 * przypisów jest odpowiednikiem tej z SearchController; docelowo warto zunifikować
 * oba miejsca na tym serwisie (na razie kopia, by nie ruszać krytycznej ścieżki
 * wyszukiwarki). Wersja czytelni nie zawiera podświetleń wyszukiwania.
 */
class DocumentReader
{
    private const PAGE_MARKER_OPEN = "\u{E000}PB:";
    private const PAGE_MARKER_CLOSE = "\u{E001}";

    public function __construct(
        private Connection $connection,
        private string $audioDir,
    ) {}

    /** Treść dokumentu jako HTML: akapity + znaczniki stron wydania + przypisy. */
    public function render(int $documentId): string
    {
        $raw = (string) ($this->connection->executeQuery(
            'SELECT content FROM documents WHERE id = :id',
            ['id' => $documentId]
        )->fetchOne() ?: '');
        if ($raw === '') {
            return '';
        }

        $html = $this->formatContent($raw, $this->pageBreaks($documentId));
        return $this->applyFootnotes($html, $this->footnotes($documentId));
    }

    /**
     * Metadane opublikowanego nagrania audio dokumentu (najstarsze), gdy plik
     * istnieje w katalogu uploadów. Sam plik streamuje trasa app_document_audio.
     *
     * @return array{mime:string,title:?string}|null
     */
    public function publishedAudio(int $documentId): ?array
    {
        $row = $this->connection->executeQuery(
            "SELECT audio_file_name, mime_type, title
             FROM audio_recordings
             WHERE document_id = :id
               AND is_published = true
               AND audio_file_name IS NOT NULL AND audio_file_name <> ''
             ORDER BY created_at ASC, id ASC
             LIMIT 1",
            ['id' => $documentId]
        )->fetchAssociative();

        if (!$row) {
            return null;
        }

        $file = rtrim($this->audioDir, '/') . '/' . basename((string) $row['audio_file_name']);
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        $mime = $row['mime_type'] ?: match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'm4a', 'mp4' => 'audio/mp4',
            'ogg' => 'audio/ogg',
            'wav' => 'audio/wav',
            default => 'audio/mpeg',
        };

        return ['mime' => $mime, 'title' => $row['title'] ?: null];
    }

    /**
     * @param array<int, array{paragraph?:int,char_in_para?:int,page?:int}>|null $pageBreaks
     */
    private function formatContent(string $text, ?array $pageBreaks): string
    {
        if (str_contains($text, '<p>') || str_contains($text, '<div>')) {
            return $text;
        }

        if (!empty($pageBreaks)) {
            $text = $this->injectSentinels($text, $pageBreaks);
        }

        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $paragraphs = preg_split('/\n{2,}/', trim($text));

        $html = implode("\n", array_map(
            fn(string $p) => '<p>' . nl2br(trim($p)) . '</p>',
            array_filter($paragraphs, fn(string $p) => trim($p) !== '')
        ));

        if (!empty($pageBreaks)) {
            $open = preg_quote(self::PAGE_MARKER_OPEN, '/');
            $close = preg_quote(self::PAGE_MARKER_CLOSE, '/');
            $html = preg_replace_callback(
                '/' . $open . '(\d+)' . $close . '/u',
                fn($m) => '<span class="page-marker" id="strona-' . $m[1] . '" data-page="' . $m[1] . '" title="Strona ' . $m[1] . ' wydania drukowanego">[s. ' . $m[1] . ']</span>',
                $html
            ) ?? $html;
        }

        return $html;
    }

    /**
     * Wstawia znaczniki stron (sentinel PUA) w tekst po bezwzględnych offsetach
     * bajtowych, wewnątrz właściwych akapitów.
     *
     * @param array<int, array{paragraph?:int,char_in_para?:int,page?:int}> $pageBreaks
     */
    private function injectSentinels(string $content, array $pageBreaks): string
    {
        $ins = []; // [offset, text]

        $byPara = [];
        foreach ($pageBreaks as $b) {
            if (isset($b['paragraph'], $b['page'])) {
                $byPara[(int) $b['paragraph']][] = $b;
            }
        }
        if ($byPara) {
            $segments = preg_split('/(\n\s*\n)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
            if ($segments !== false) {
                $paraIdx = 0;
                $abs = 0;
                foreach ($segments as $j => $segment) {
                    $isSeparator = ($j % 2) === 1;
                    if (!$isSeparator && isset($byPara[$paraIdx])) {
                        foreach ($byPara[$paraIdx] as $b) {
                            $off = max(0, min(strlen($segment), (int) ($b['char_in_para'] ?? 0)));
                            $ins[] = [$abs + $off, self::PAGE_MARKER_OPEN . (int) $b['page'] . self::PAGE_MARKER_CLOSE];
                        }
                    }
                    $abs += strlen($segment);
                    if (!$isSeparator) {
                        $paraIdx++;
                    }
                }
            }
        }

        if (!$ins) {
            return $content;
        }

        usort($ins, fn($a, $b) => $a[0] <=> $b[0]);
        for ($i = count($ins) - 1; $i >= 0; $i--) {
            [$off, $txt] = $ins[$i];
            $content = substr($content, 0, $off) . $txt . substr($content, $off);
        }
        return $content;
    }

    /** @return array<int, array{paragraph?:int,char_in_para?:int,page?:int}>|null */
    private function pageBreaks(int $documentId): ?array
    {
        $raw = $this->connection->executeQuery(
            'SELECT page_breaks FROM documents WHERE id = :id',
            ['id' => $documentId]
        )->fetchOne();
        if (!$raw) {
            return null;
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($decoded) ? $decoded : null;
    }

    /** @return list<array{number:int|string,content:string}> */
    private function footnotes(int $documentId): array
    {
        return $this->connection->executeQuery(
            'SELECT number, content FROM footnotes WHERE document_id = :id ORDER BY number',
            ['id' => $documentId]
        )->fetchAllAssociative();
    }

    /** @param list<array{number:int|string,content:string}> $footnotes */
    private function applyFootnotes(string $html, array $footnotes): string
    {
        if (!$footnotes) {
            return $html;
        }

        $map = [];
        foreach ($footnotes as $fn) {
            $map[(int) $fn['number']] = htmlspecialchars($fn['content'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $html = preg_replace_callback('/\[(\d+)\]/', function (array $m) use ($map) {
            $n = (int) $m[1];
            $tooltip = $map[$n] ?? '';
            return '<sup class="footnote-ref"><a href="#fn-' . $n . '" id="fnref-' . $n . '"'
                . ($tooltip ? ' data-tooltip="' . $tooltip . '"' : '')
                . '>' . $n . '</a></sup>';
        }, $html);

        $section = '<div class="footnotes"><hr><ol>';
        foreach ($footnotes as $fn) {
            $n = (int) $fn['number'];
            $section .= '<li id="fn-' . $n . '">' . $map[$n] . ' <a href="#fnref-' . $n . '" class="footnote-back">&uarr;</a></li>';
        }
        $section .= '</ol></div>';

        return $html . $section;
    }
}
