<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Jedyne źródło HTML-a treści dokumentu: akapity, znaczniki stron [s. N],
 * przypisy i (opcjonalnie) podświetlenie cytowanego fragmentu z asystenta AI.
 *
 * Używają go wszystkie widoki czytania pojedynczego dokumentu: Biblioteka (VIP),
 * wyszukiwarka (podgląd i pełny tekst), modal asystenta AI oraz eksport PDF.
 * Wygląd tego HTML-a definiuje jeden arkusz: templates/partials/_document_text.html.twig.
 */
class DocumentReader
{
    private const PAGE_MARKER_OPEN = "\u{E000}PB:";
    private const PAGE_MARKER_CLOSE = "\u{E001}";
    private const HL_OPEN = "\u{E002}";
    private const HL_CLOSE = "\u{E003}";

    public function __construct(
        private Connection $connection,
        private string $audioDir,
    ) {}

    /**
     * Treść dokumentu jako HTML: akapity + znaczniki stron wydania + przypisy.
     *
     * @param string|null $frag Id fragmentu (document_chunks) do podświetlenia jako cytat.
     */
    public function render(int $documentId, ?string $frag = null): string
    {
        $raw = (string) ($this->connection->executeQuery(
            'SELECT content FROM documents WHERE id = :id',
            ['id' => $documentId]
        )->fetchOne() ?: '');

        return $this->renderHtml($raw, $documentId, $frag);
    }

    /**
     * Wariant dla wywołań, które treść mają już wczytaną (kontrola dostępu,
     * publishedJoin, eksport PDF) — żeby nie odpytywać bazy drugi raz.
     *
     * @param string|null $frag Id fragmentu (document_chunks) do podświetlenia jako cytat.
     */
    public function renderHtml(string $raw, int $documentId, ?string $frag = null): string
    {
        if ($raw === '') {
            return '';
        }

        $html = $this->formatContent($raw, $this->pageBreaks($documentId), $this->chunkHighlight($frag, $documentId, $raw));

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
    /**
     * @param array<int, array{paragraph?:int,char_in_para?:int,page?:int}>|null $pageBreaks
     * @param array{start:int,end:int}|null $highlight Zakres bajtowy w $text do opakowania jako cytat.
     */
    private function formatContent(string $text, ?array $pageBreaks = null, ?array $highlight = null): string
    {
        if (str_contains($text, '<p>') || str_contains($text, '<div>')) {
            return $text;
        }

        if (!empty($pageBreaks) || $highlight !== null) {
            $text = $this->injectSentinels($text, $pageBreaks ?? [], $highlight);
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

        if ($highlight !== null) {
            $html = str_replace(
                [self::HL_OPEN, self::HL_CLOSE],
                ['<mark class="rag-hl">', '</mark>'],
                $html
            );
        }

        return $html;
    }

    /**
     * Wstawia znaczniki stron i (opcjonalnie) podświetlenia (sentinele PUA) w tekst
     * po bezwzględnych offsetach bajtowych, w jednym przebiegu — dzięki czemu jedne
     * nie przesuwają drugich. Podświetlenie jest cięte na granicach akapitów, więc
     * żaden <mark> nie przechodzi przez </p>.
     *
     * @param array<int, array{paragraph?:int,char_in_para?:int,page?:int}> $pageBreaks
     * @param array{start:int,end:int}|null $highlight
     */
    private function injectSentinels(string $content, array $pageBreaks, ?array $highlight = null): string
    {
        $ins = []; // [offset, order, text] — order rozstrzyga remisy na tym samym offsecie

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
                            $ins[] = [$abs + $off, 1, self::PAGE_MARKER_OPEN . (int) $b['page'] . self::PAGE_MARKER_CLOSE];
                        }
                    }
                    $abs += strlen($segment);
                    if (!$isSeparator) {
                        $paraIdx++;
                    }
                }
            }
        }

        if ($highlight !== null) {
            $s = max(0, (int) $highlight['start']);
            $e = min(strlen($content), (int) $highlight['end']);
            if ($e > $s) {
                $ins[] = [$s, 0, self::HL_OPEN];   // order 0 → na lewo od znacznika strony na tym samym offsecie
                $ins[] = [$e, 2, self::HL_CLOSE];  // order 2 → na prawo
                if (preg_match_all('/\n{2,}/', $content, $m, PREG_OFFSET_CAPTURE)) {
                    foreach ($m[0] as [$sepStr, $sepOff]) {
                        $sepEnd = $sepOff + strlen($sepStr);
                        if ($sepOff > $s && $sepEnd < $e) {
                            $ins[] = [$sepOff, 2, self::HL_CLOSE]; // zamknij przed pustą linią
                            $ins[] = [$sepEnd, 0, self::HL_OPEN];  // otwórz ponownie w następnym akapicie
                        }
                    }
                }
            }
        }

        if (!$ins) {
            return $content;
        }

        usort($ins, fn($a, $b) => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]));
        for ($i = count($ins) - 1; $i >= 0; $i--) {
            [$off, , $txt] = $ins[$i];
            $content = substr($content, 0, $off) . $txt . substr($content, $off);
        }
        return $content;
    }

    /**
     * Zamienia id fragmentu na zakres bajtowy w surowej treści dokumentu.
     * Tekst fragmentu to te same słowa co w źródle (białe znaki znormalizowano
     * przy chunkowaniu), więc kotwiczymy się na kilku pierwszych i ostatnich
     * tokenach wzorcem tolerancyjnym na białe znaki i bierzemy zakres pomiędzy.
     *
     * @return array{start:int,end:int}|null
     */
    private function chunkHighlight(?string $frag, int $documentId, string $content): ?array
    {
        if ($frag === null || $frag === '' || !ctype_digit($frag) || $content === '') {
            return null;
        }
        $chunkText = $this->connection->executeQuery(
            'SELECT content FROM document_chunks WHERE id = :cid AND document_id = :did',
            ['cid' => (int) $frag, 'did' => $documentId]
        )->fetchOne();
        if ($chunkText === false) {
            return null;
        }

        $toks = preg_split('/\s+/u', trim((string) $chunkText), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$toks) {
            return null;
        }
        $pat = fn(array $t) => '/' . implode('\s+', array_map(fn($x) => preg_quote($x, '/'), $t)) . '/su';

        $head = array_slice($toks, 0, 12);
        if (@preg_match($pat($head), $content, $mh, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $start = $mh[0][1];

        $tail = array_slice($toks, -12);
        if (@preg_match($pat($tail), $content, $mt, PREG_OFFSET_CAPTURE, $start) === 1) {
            $end = $mt[0][1] + strlen($mt[0][0]);
        } else {
            $end = $start + strlen($mh[0][0]);
        }
        return ['start' => $start, 'end' => $end];
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
