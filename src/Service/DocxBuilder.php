<?php

namespace App\Service;

/**
 * Minimal, dependency-free DOCX writer (OOXML via ZipArchive).
 * Converts the assistant's lightweight Markdown answer into a formatted Word
 * document with a header, the question, the answer and a numbered source list.
 */
class DocxBuilder
{
    private const BRAND = '722F37';

    /**
     * @param array{question:string, answer:string, citations:array<int,array>} $data
     * @return string DOCX file bytes
     */
    public function build(array $data): string
    {
        $body = '';
        $body .= $this->heading('Asystent Dzieł Zebranych kard. Stefana Wyszyńskiego', 30, true, self::BRAND);
        $body .= $this->paragraph([$this->run('Odpowiedź wygenerowana na podstawie tekstów źródłowych. Wymaga weryfikacji z oryginałem.', ['i' => true, 'sz' => 18, 'color' => '777777'])]);
        $body .= $this->spacer();

        $body .= $this->heading('Pytanie', 24, true, self::BRAND);
        $body .= $this->paragraph([$this->run((string) ($data['question'] ?? ''), ['b' => true])]);
        $body .= $this->spacer();

        $body .= $this->heading('Odpowiedź', 24, true, self::BRAND);
        $body .= $this->markdownToBody((string) ($data['answer'] ?? ''));

        if (!empty($data['citations'])) {
            $body .= $this->spacer();
            $body .= $this->heading('Źródła', 24, true, self::BRAND);
            foreach ($data['citations'] as $c) {
                $line = sprintf('[%d] %s — %s', $c['n'] ?? 0, $c['title'] ?? '', $c['label'] ?? '');
                $body .= $this->paragraph([$this->run($line, ['sz' => 20])]);
            }
        }

        return $this->zip($this->documentXml($body));
    }

    /** Convert a small Markdown subset to OOXML paragraphs. */
    private function markdownToBody(string $md): string
    {
        $md = str_replace(["\r\n", "\r"], "\n", $md);
        $out = '';
        foreach (explode("\n", $md) as $line) {
            $trim = trim($line);
            if ($trim === '') {
                continue;
            }
            // Headings
            if (preg_match('/^(#{1,4})\s+(.*)$/', $trim, $m)) {
                $sz = [1 => 28, 2 => 26, 3 => 24, 4 => 22][strlen($m[1])] ?? 22;
                $out .= $this->heading($m[2], $sz, true, self::BRAND);
                continue;
            }
            // Bullets
            if (preg_match('/^[\*\-]\s+(.*)$/', $trim, $m)) {
                $out .= $this->paragraph(
                    array_merge([$this->run('•  ', ['color' => self::BRAND, 'b' => true])], $this->inlineRuns($m[1])),
                    ['indent' => 360]
                );
                continue;
            }
            $out .= $this->paragraph($this->inlineRuns($trim));
        }
        return $out;
    }

    /** Split a line into runs honouring **bold** segments. */
    private function inlineRuns(string $text): array
    {
        $parts = preg_split('/(\*\*.+?\*\*)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $runs = [];
        foreach ($parts as $p) {
            if (preg_match('/^\*\*(.+?)\*\*$/u', $p, $m)) {
                $runs[] = $this->run($m[1], ['b' => true]);
            } else {
                $runs[] = $this->run($p);
            }
        }
        return $runs ?: [$this->run('')];
    }

    private function run(string $text, array $opt = []): string
    {
        $rPr = '';
        if (!empty($opt['b'])) { $rPr .= '<w:b/>'; }
        if (!empty($opt['i'])) { $rPr .= '<w:i/>'; }
        if (!empty($opt['sz'])) { $rPr .= '<w:sz w:val="' . (int) $opt['sz'] . '"/>'; }
        if (!empty($opt['color'])) { $rPr .= '<w:color w:val="' . $opt['color'] . '"/>'; }
        $rPr = $rPr ? "<w:rPr>{$rPr}</w:rPr>" : '';
        return '<w:r>' . $rPr . '<w:t xml:space="preserve">' . $this->esc($text) . '</w:t></w:r>';
    }

    private function paragraph(array $runs, array $opt = []): string
    {
        $pPr = '';
        if (!empty($opt['indent'])) { $pPr .= '<w:ind w:left="' . (int) $opt['indent'] . '"/>'; }
        $pPr .= '<w:spacing w:after="120"/>';
        return '<w:p><w:pPr>' . $pPr . '</w:pPr>' . implode('', $runs) . '</w:p>';
    }

    private function heading(string $text, int $sz, bool $bold, string $color): string
    {
        return '<w:p><w:pPr><w:spacing w:before="160" w:after="80"/></w:pPr>'
            . $this->run($text, ['b' => $bold, 'sz' => $sz, 'color' => $color])
            . '</w:p>';
    }

    private function spacer(): string
    {
        return '<w:p><w:pPr><w:spacing w:after="0"/></w:pPr></w:p>';
    }

    private function documentXml(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>' . $body
            . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
            . '<w:pgMar w:top="1417" w:right="1417" w:bottom="1417" w:left="1417"/></w:sectPr>'
            . '</w:body></w:document>';
    }

    private function zip(string $documentXml): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>');

        $zip->addFromString('word/document.xml', $documentXml);
        $zip->close();

        $bytes = file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
