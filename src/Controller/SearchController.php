<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Service\EmbeddingService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SearchController extends AbstractController
{
    public function __construct(
        private DocumentRepository $documentRepository,
        private Connection $connection,
        private EmbeddingService $embeddingService,
    ) {}

    #[Route('/szukaj', name: 'app_search')]
    #[Route('/tekst/{id}-{slug}', name: 'app_search_doc', requirements: ['id' => '\d+', 'slug' => '[a-z0-9-]+'])]
    #[Route('/tekst/{id}', name: 'app_search_doc_short', requirements: ['id' => '\d+'])]
    public function index(?int $id = null, ?string $slug = null): Response
    {
        $pj = $this->documentRepository->publishedJoin();

        // If we have an id but no/wrong slug, redirect to canonical URL
        if ($id) {
            $docSlug = $this->connection->executeQuery(
                "SELECT d.slug FROM documents d {$pj} WHERE d.id = :id",
                ['id' => $id]
            )->fetchOne();

            if ($docSlug === false) {
                throw $this->createNotFoundException();
            }

            if ($slug !== $docSlug) {
                return $this->redirectToRoute('app_search_doc', [
                    'id' => $id,
                    'slug' => $docSlug ?: 'dokument',
                ], 301);
            }
        }

        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $volumesSql = $isAdmin
            ? 'SELECT id, number, title, year_from, year_to FROM volumes ORDER BY number'
            : "SELECT id, number, title, year_from, year_to FROM volumes WHERE status = 'opublikowany' ORDER BY number";
        $volumes = $this->connection->executeQuery($volumesSql)->fetchAllAssociative();

        $tags = $this->connection->executeQuery(
            'SELECT id, name, slug, color FROM tags ORDER BY name'
        )->fetchAllAssociative();

        $documentTypes = $this->documentRepository->getDocumentTypes();
        $totalCount = $this->documentRepository->getTotalCount();
        $totalWords = $this->documentRepository->getTotalWords();
        $totalChars = $this->documentRepository->getTotalChars();

        /** @var User|null $user */
        $user = $this->getUser();
        $accessLevel = $this->resolveAccessLevel($user);
        $viewsRemaining = null;

        if ($accessLevel === 'user') {
            $viewsRemaining = max(0, 5 - $this->getViewsLast24h($user->getId()));
        }

        return $this->render('search/index.html.twig', [
            'volumes' => $volumes,
            'tags' => $tags,
            'documentTypes' => $documentTypes,
            'totalCount' => $totalCount,
            'totalWords' => $totalWords,
            'totalChars' => $totalChars,
            'accessLevel' => $accessLevel,
            'viewsRemaining' => $viewsRemaining,
            'openDocId' => $id,
        ]);
    }

    #[Route('/szukaj/moje-podglady', name: 'app_my_views')]
    public function myViews(Request $request): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('app_search');
        }

        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return new Response('', 403);
        }

        $pj = $this->documentRepository->publishedJoin();
        $docs = $this->connection->executeQuery(
            "SELECT d.id, d.title, d.slug, d.document_type, dv.viewed_at
             FROM document_views dv
             JOIN documents d ON d.id = dv.document_id {$pj}
             WHERE dv.user_id = :uid AND dv.viewed_at > NOW() - INTERVAL '24 hours'
             ORDER BY dv.viewed_at DESC",
            ['uid' => $user->getId()]
        )->fetchAllAssociative();

        return $this->render('search/_my_views.html.twig', [
            'docs' => $docs,
        ]);
    }

    #[Route('/szukaj/wyniki', name: 'app_search_results')]
    public function results(Request $request): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('app_search');
        }

        /** @var User|null $user */
        $user = $this->getUser();
        $accessLevel = $this->resolveAccessLevel($user);
        $canPaginate = in_array($accessLevel, ['donator', 'vip'], true);

        $query = $request->query->get('q', '');
        $volumeId = $request->query->getInt('volume') ?: null;
        $documentType = $request->query->get('type') ?: null;
        $tagId = $request->query->getInt('tag') ?: null;
        $dateFrom = $request->query->get('date_from') ?: null;
        $dateTo = $request->query->get('date_to') ?: null;

        $limit = $canPaginate ? 20 : 10;
        $page = $canPaginate ? max(1, $request->query->getInt('page', 1)) : 1;

        // Detect if query looks like a natural language question
        $useSemanticSearch = $query && $this->isSemanticQuery($query);

        if ($useSemanticSearch) {
            $embedding = $this->embeddingService->getEmbedding($query);
        }

        if ($useSemanticSearch && !empty($embedding)) {
            $data = $this->documentRepository->hybridSearch(
                query: $query,
                queryEmbedding: $embedding,
                volumeId: $volumeId,
                documentType: $documentType,
                tagId: $tagId,
                dateFrom: $dateFrom,
                dateTo: $dateTo,
                page: $page,
                limit: $limit,
            );
        } else {
            $data = $this->documentRepository->search(
                query: $query,
                volumeId: $volumeId,
                documentType: $documentType,
                tagId: $tagId,
                dateFrom: $dateFrom,
                dateTo: $dateTo,
                page: $page,
                limit: $limit,
            );
        }

        $pages = $canPaginate ? max(1, (int) ceil($data['total'] / $limit)) : 1;

        // Get viewed document IDs for limited users
        $viewedDocIds = [];
        if ($accessLevel === 'user' && $user) {
            $viewedDocIds = $this->getViewedDocIds($user->getId());
        }

        // Truncate + highlight snippets
        foreach ($data['results'] as &$row) {
            if (!empty($row['snippet'])) {
                $raw = mb_substr($row['snippet'], 0, 200);
                if (mb_strlen($row['snippet']) > 200) {
                    $raw .= '...';
                }
                $row['snippet'] = ($query && trim($query) !== '')
                    ? $this->highlightSnippet($raw, $query)
                    : htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }
        unset($row);

        $response = $this->render('search/_results.html.twig', [
            'results' => $data['results'],
            'total' => $data['total'],
            'query' => $query,
            'canPaginate' => $canPaginate,
            'page' => $page,
            'pages' => $pages,
            'viewedDocIds' => $viewedDocIds,
        ]);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    #[Route('/dokument/{id}', name: 'app_document', requirements: ['id' => '\d+'])]
    public function document(int $id): Response
    {
        $pj = $this->documentRepository->publishedJoin();
        $doc = $this->connection->executeQuery(
            "SELECT d.*, v.number AS volume_number, v.title AS volume_title
             FROM documents d {$pj}
             WHERE d.id = :id",
            ['id' => $id]
        )->fetchAssociative();

        if (!$doc) {
            throw $this->createNotFoundException();
        }

        $tags = $this->connection->executeQuery(
            'SELECT t.id, t.name, t.slug, t.color
             FROM document_tags dt JOIN tags t ON t.id = dt.tag_id
             WHERE dt.document_id = :id ORDER BY t.name',
            ['id' => $id]
        )->fetchAllAssociative();

        /** @var User|null $user */
        $user = $this->getUser();
        $accessLevel = $this->resolveAccessLevel($user);
        $viewsRemaining = null;

        if ($accessLevel === 'user') {
            $viewsUsed = $this->getViewsLast24h($user->getId());
            $viewsRemaining = max(0, 5 - $viewsUsed);
        }

        return $this->render('search/document.html.twig', [
            'doc' => $doc,
            'tags' => $tags,
            'accessLevel' => $accessLevel,
            'viewsRemaining' => $viewsRemaining,
        ]);
    }

    #[Route('/dokument/{id}/tresc', name: 'app_document_content', requirements: ['id' => '\d+'])]
    public function documentContent(int $id, Request $request): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('app_document', ['id' => $id]);
        }

        /** @var User|null $user */
        $user = $this->getUser();
        $accessLevel = $this->resolveAccessLevel($user);

        // Guests cannot see content
        if ($accessLevel === 'guest') {
            return new Response('<div class="text-center text-muted py-4">Zaloguj się, aby zobaczyć treść dokumentu.</div>', 403);
        }

        // ROLE_USER: check 5/24h limit
        if ($accessLevel === 'user') {
            $viewsUsed = $this->getViewsLast24h($user->getId());
            $alreadyViewed = $this->hasViewedDocument($user->getId(), $id);

            if (!$alreadyViewed && $viewsUsed >= 5) {
                return new Response(
                    '<div class="text-center py-4">'
                    . '<div class="text-danger fw-bold mb-2">Limit wyczerpany</div>'
                    . '<p class="text-muted">Wykorzystałeś 5 darmowych podglądów w ciągu 24 godzin.<br>'
                    . 'Zostań <strong>Donatorem</strong>, aby uzyskać nieograniczony dostęp.</p>'
                    . '</div>',
                    403
                );
            }

            // Record view if new document
            if (!$alreadyViewed) {
                $this->recordView($user->getId(), $id);
            }
        }

        $pj = $this->documentRepository->publishedJoin();
        $content = $this->connection->executeQuery(
            "SELECT d.content FROM documents d {$pj} WHERE d.id = :id",
            ['id' => $id]
        )->fetchOne();

        if ($content === false) {
            throw $this->createNotFoundException();
        }

        $html = $this->formatContent($content ?: '');
        $footnotes = $this->getFootnotes($id);
        $html = $this->applyFootnotes($html, $footnotes);

        $response = new Response($html);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    #[Route('/dokument/{id}/podglad', name: 'app_document_preview', requirements: ['id' => '\d+'])]
    public function documentPreview(int $id, Request $request): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('app_search');
        }

        $pj = $this->documentRepository->publishedJoin();
        $doc = $this->connection->executeQuery(
            "SELECT d.*, v.number AS volume_number, v.title AS volume_title
             FROM documents d {$pj}
             WHERE d.id = :id",
            ['id' => $id]
        )->fetchAssociative();

        if (!$doc) {
            return new Response('Nie znaleziono dokumentu.', 404);
        }

        $tags = $this->connection->executeQuery(
            'SELECT t.id, t.name, t.slug, t.color
             FROM document_tags dt JOIN tags t ON t.id = dt.tag_id
             WHERE dt.document_id = :id ORDER BY t.name',
            ['id' => $id]
        )->fetchAllAssociative();

        /** @var User|null $user */
        $user = $this->getUser();
        $accessLevel = $this->resolveAccessLevel($user);
        $viewsRemaining = null;
        $content = null;
        $limitReached = false;

        $searchQuery = $request->query->get('q', '');

        if ($accessLevel !== 'guest') {
            if ($accessLevel === 'user') {
                $viewsUsed = $this->getViewsLast24h($user->getId());
                $alreadyViewed = $this->hasViewedDocument($user->getId(), $id);

                if (!$alreadyViewed && $viewsUsed >= 5) {
                    $limitReached = true;
                } else {
                    if (!$alreadyViewed) {
                        $this->recordView($user->getId(), $id);
                    }
                    $raw = $this->connection->executeQuery(
                        'SELECT content FROM documents WHERE id = :id',
                        ['id' => $id]
                    )->fetchOne();
                    $content = $this->formatContent($raw ?: '');
                    $footnotes = $this->getFootnotes($id);
                    $content = $this->applyFootnotes($content, $footnotes);
                    if ($searchQuery) {
                        $content = $this->highlightTerms($content, $searchQuery);
                    }
                }
                $viewsUsed = $this->getViewsLast24h($user->getId());
                $viewsRemaining = max(0, 5 - $viewsUsed);
            } else {
                $raw = $this->connection->executeQuery(
                    'SELECT content FROM documents WHERE id = :id',
                    ['id' => $id]
                )->fetchOne();
                $content = $this->formatContent($raw ?: '');
                $footnotes = $this->getFootnotes($id);
                $content = $this->applyFootnotes($content, $footnotes);
                if ($searchQuery) {
                    $content = $this->highlightTerms($content, $searchQuery);
                }
            }
        }

        $response = $this->render('search/_document_view.html.twig', [
            'doc' => $doc,
            'tags' => $tags,
            'accessLevel' => $accessLevel,
            'viewsRemaining' => $viewsRemaining,
            'content' => $content,
            'limitReached' => $limitReached,
        ]);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    #[Route('/dokument/{id}/pdf', name: 'app_document_pdf', requirements: ['id' => '\d+'])]
    public function documentPdf(int $id): Response
    {
        $this->denyAccessUnlessGranted('ROLE_VIP');

        $pj = $this->documentRepository->publishedJoin();
        $doc = $this->connection->executeQuery(
            "SELECT d.title, d.subtitle, d.content, d.event_date, d.location, d.document_type,
                    v.number AS volume_number, v.title AS volume_title
             FROM documents d {$pj}
             WHERE d.id = :id",
            ['id' => $id]
        )->fetchAssociative();

        if (!$doc) {
            throw $this->createNotFoundException();
        }

        $doc['content'] = $this->formatContent($doc['content'] ?? '');
        $footnotes = $this->getFootnotes($id);
        $doc['content'] = $this->applyFootnotes($doc['content'], $footnotes);

        $html = $this->renderView('search/_pdf.html.twig', ['doc' => $doc]);

        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_top' => 20,
            'margin_bottom' => 20,
            'margin_left' => 18,
            'margin_right' => 18,
            'default_font' => 'dejavuserif',
        ]);

        $mpdf->SetTitle($doc['title'] ?: 'Dokument');
        $mpdf->SetAuthor('Dzieła Zebrane Kardynała Stefana Wyszyńskiego');
        $mpdf->SetFooter('{PAGENO} / {nbpg}');
        $mpdf->WriteHTML($html);

        $filename = 'dokument-' . $id . '.pdf';
        $pdfContent = $mpdf->Output($filename, \Mpdf\Output\Destination::STRING_RETURN);

        return new Response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function resolveAccessLevel(?User $user): string
    {
        if (!$user) {
            return 'guest';
        }
        if ($this->isGranted('ROLE_VIP')) {
            return 'vip';
        }
        if ($this->isGranted('ROLE_DONATOR')) {
            return 'donator';
        }
        return 'user';
    }

    private function getViewsLast24h(int $userId): int
    {
        return (int) $this->connection->executeQuery(
            "SELECT COUNT(DISTINCT document_id) FROM document_views WHERE user_id = :uid AND viewed_at > NOW() - INTERVAL '24 hours'",
            ['uid' => $userId]
        )->fetchOne();
    }

    private function hasViewedDocument(int $userId, int $documentId): bool
    {
        return (bool) $this->connection->executeQuery(
            "SELECT 1 FROM document_views WHERE user_id = :uid AND document_id = :did AND viewed_at > NOW() - INTERVAL '24 hours' LIMIT 1",
            ['uid' => $userId, 'did' => $documentId]
        )->fetchOne();
    }

    private function getViewedDocIds(int $userId): array
    {
        $ids = $this->connection->executeQuery(
            "SELECT DISTINCT document_id FROM document_views WHERE user_id = :uid AND viewed_at > NOW() - INTERVAL '24 hours'",
            ['uid' => $userId]
        )->fetchFirstColumn();

        $map = [];
        foreach ($ids as $id) {
            $map[(int) $id] = true;
        }
        return $map;
    }

    private function recordView(int $userId, int $documentId): void
    {
        $this->connection->executeStatement(
            'INSERT INTO document_views (user_id, document_id, viewed_at) VALUES (:uid, :did, NOW())',
            ['uid' => $userId, 'did' => $documentId]
        );
    }

    private function formatContent(string $text): string
    {
        if (str_contains($text, '<p>') || str_contains($text, '<div>')) {
            return $text;
        }

        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $paragraphs = preg_split('/\n{2,}/', trim($text));

        return implode("\n", array_map(
            fn(string $p) => '<p>' . nl2br(trim($p)) . '</p>',
            array_filter($paragraphs, fn(string $p) => trim($p) !== '')
        ));
    }

    private function getFootnotes(int $documentId): array
    {
        return $this->connection->executeQuery(
            'SELECT number, content FROM footnotes WHERE document_id = :id ORDER BY number',
            ['id' => $documentId]
        )->fetchAllAssociative();
    }

    /**
     * Detect if query looks like a natural language question (vs keyword search).
     */
    private function isSemanticQuery(string $query): bool
    {
        $q = mb_strtolower(trim($query));

        // Quoted phrases → use keyword search
        if (str_contains($q, '"') || str_contains($q, "\u{201e}") || str_contains($q, "\u{201d}")) {
            return false;
        }

        // Very short queries (1-2 words) → keyword search
        $words = preg_split('/\s+/', $q);
        if (count($words) <= 2) {
            return false;
        }

        // Polish question words and natural language patterns
        $patterns = [
            '/^(co|jak|gdzie|kiedy|dlaczego|czemu|czy|kto|jaki|jaka|jakie|jakim|ile|o czym)\b/',
            '/\b(pisał|mówił|nauczał|głosił|twierdził|uważał|sądził|myślał|napisał|powiedział)\b/',
            '/\b(na temat|o tym|w sprawie|w kwestii|w kontekście|na przykład|według|zdaniem)\b/',
            '/\b(prymas|wyszyński|kardynał|stefan)\b.*\b(pisał|mówił|nauczał|głosił|myślał|uważał)\b/',
            '/\?\s*$/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $q)) {
                return true;
            }
        }

        // 4+ words without quotes → likely a sentence, use semantic
        if (count($words) >= 4) {
            return true;
        }

        return false;
    }

    /**
     * Extract plain search words from a websearch query (strips quotes, OR, -).
     */
    private function extractSearchWords(string $query): array
    {
        // Remove quotes and websearch operators
        $clean = str_replace(['"', "\u{201e}", "\u{201d}"], ' ', $query);
        $clean = preg_replace('/\bor\b/i', ' ', $clean);
        $clean = preg_replace('/(?:^|\s)-/', ' ', $clean);

        $words = preg_split('/\s+/', trim($clean));
        return array_values(array_filter($words, fn(string $w) => mb_strlen($w) >= 2));
    }

    /**
     * Highlight search terms in HTML content (skips HTML tags).
     */
    private function highlightTerms(string $html, string $query): string
    {
        $words = $this->extractSearchWords($query);
        if (!$words) {
            return $html;
        }

        $pattern = implode('|', array_map(fn(string $w) => preg_quote($w, '/'), $words));

        // Split HTML into tags and text segments, highlight only text
        return preg_replace_callback(
            '/([^<]*)(<[^>]*>)?/',
            function (array $m) use ($pattern) {
                $text = $m[1];
                $tag = $m[2] ?? '';
                if ($text !== '') {
                    $text = preg_replace(
                        '/(' . $pattern . ')/iu',
                        '<mark class="search-hl">$1</mark>',
                        $text
                    );
                }
                return $text . $tag;
            },
            $html
        );
    }

    /**
     * Highlight search terms in a plain-text snippet (escapes HTML first).
     */
    private function highlightSnippet(string $text, string $query): string
    {
        $words = $this->extractSearchWords($query);
        if (!$words) {
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $pattern = implode('|', array_map(fn(string $w) => preg_quote($w, '/'), $words));

        return preg_replace(
            '/(' . $pattern . ')/iu',
            '<mark class="search-hl">$1</mark>',
            $escaped
        );
    }

    private function applyFootnotes(string $html, array $footnotes): string
    {
        if (!$footnotes) {
            return $html;
        }

        // Build lookup map number => escaped content
        $map = [];
        foreach ($footnotes as $fn) {
            $map[(int) $fn['number']] = htmlspecialchars($fn['content'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        // Replace [N] markers with superscript links + tooltip
        $html = preg_replace_callback('/\[(\d+)\]/', function (array $m) use ($map) {
            $n = (int) $m[1];
            $tooltip = $map[$n] ?? '';
            return '<sup class="footnote-ref"><a href="#fn-' . $n . '" id="fnref-' . $n . '"'
                . ($tooltip ? ' data-tooltip="' . $tooltip . '"' : '')
                . '>' . $n . '</a></sup>';
        }, $html);

        // Build footnotes section
        $section = '<div class="footnotes"><hr><ol>';
        foreach ($footnotes as $fn) {
            $n = (int) $fn['number'];
            $section .= '<li id="fn-' . $n . '">' . $map[$n] . ' <a href="#fnref-' . $n . '" class="footnote-back">&uarr;</a></li>';
        }
        $section .= '</ol></div>';

        return $html . $section;
    }
}
