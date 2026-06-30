<?php

namespace App\Controller;

use App\Entity\RagQuery;
use App\Entity\User;
use App\Service\ChunkRetriever;
use App\Service\DocxBuilder;
use App\Service\RagAnswerer;
use App\Service\RagCache;
use App\Service\RagHistory;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

class RagController extends AbstractController
{
    public function __construct(
        private RagAnswerer $rag,
        private SettingsService $settings,
        private DocxBuilder $docx,
        private ChunkRetriever $retriever,
        private EntityManagerInterface $em,
        private RagCache $cache,
        private RagHistory $history,
    ) {}

    /**
     * Loguje zapytanie i pobiera kredyt (konta z limitem). Konta bez limitu
     * (admin/demo) też są logowane — z kosztem 0 kredytów — by panel finansów AI
     * obejmował WSZYSTKIE realne koszty OpenRouter. Zwraca wpis do uzupełnienia o usage.
     */
    private function spend(?User $user, bool $unlimited, ?int $volumeId, string $question, bool $cached): ?RagQuery
    {
        if (!$user instanceof User) {
            return null; // demo bez konta — nie ma do kogo przypisać wpisu
        }
        if (!$unlimited) {
            $user->spendAiCredit();
        }
        $rq = new RagQuery($user->getId(), $volumeId, $question, $cached, $unlimited ? 0 : 1);
        $this->em->persist($rq);
        $this->em->flush();
        return $rq;
    }

    /** Uzupełnia wpis o realne zużycie modelu (tokeny + koszt USD). */
    private function recordUsage(?RagQuery $rq, array $usage): void
    {
        if (!$rq) {
            return;
        }
        $rq->setUsage(
            $usage['model'] ?? null,
            (int) ($usage['inputTokens'] ?? 0),
            (int) ($usage['outputTokens'] ?? 0),
            (float) ($usage['costUsd'] ?? 0),
        );
        $this->em->flush();
    }

    /** Admins and demo sessions ask without spending credits. */
    private function isUnlimited(Request $request): bool
    {
        return $this->isGranted('ROLE_ADMIN')
            || $request->getSession()->get('demo_verified') === true;
    }

    #[Route('/asystent', name: 'app_rag')]
    public function index(Request $request): Response
    {
        if (!$this->getUser() && !$this->settings->isDemoEnabled()) {
            return $this->redirectToRoute('app_login');
        }
        $volumes = array_map(function (array $v): array {
            $v['roman'] = $this->roman($v['number']);
            return $v;
        }, $this->retriever->availableVolumes());

        /** @var User|null $user */
        $user = $this->getUser();

        return $this->render('rag/index.html.twig', [
            'volumes' => $volumes,
            'unlimited' => $this->isUnlimited($request),
            'credits' => $user ? $user->getAiCredits() : 0,
        ]);
    }

    #[Route('/asystent/zapytaj', name: 'app_rag_ask', methods: ['POST'])]
    public function ask(Request $request): JsonResponse
    {
        if (!$this->getUser() && !$this->settings->isDemoEnabled()) {
            return new JsonResponse(['error' => 'Wymagane logowanie.'], 403);
        }

        $data = json_decode($request->getContent(), true) ?: [];
        $question = trim((string) ($data['question'] ?? ''));
        if ($question === '') {
            return new JsonResponse(['error' => 'Puste pytanie.'], 400);
        }
        $volumeId = isset($data['volume']) && $data['volume'] ? (int) $data['volume'] : null;

        /** @var User|null $user */
        $user = $this->getUser();
        $unlimited = $this->isUnlimited($request);

        if (!$unlimited && (!$user instanceof User || $user->getAiCredits() <= 0)) {
            return new JsonResponse([
                'answer' => 'Wykorzystałeś pulę pytań do asystenta. Doładuj pulę w zakładce „Wesprzyj".',
                'citations' => [],
                'used' => false,
                'blocked' => true,
            ]);
        }

        // Cache hit: serve stored answer, skip the paid model.
        if ($hit = $this->cache->get($question, $volumeId)) {
            $rq = $this->spend($user, $unlimited, $volumeId, $question, true);
            if ($rq) {
                $this->history->attach($rq, $question, $volumeId, $hit['answer'], $hit['citations']);
            }
            return new JsonResponse([
                'answer' => $hit['answer'],
                'citations' => $hit['citations'],
                'used' => true,
                'cached' => true,
            ]);
        }

        $result = $this->rag->answer($question, 8, $volumeId);

        if ($result['used'] ?? false) {
            $rq = $this->spend($user, $unlimited, $volumeId, $question, false);
            $this->recordUsage($rq, $result['usage'] ?? []);
            $this->cache->put($question, $volumeId, (string) $result['answer'], $result['citations'] ?? []);
            if ($rq) {
                $this->history->attach($rq, $question, $volumeId, (string) $result['answer'], $result['citations'] ?? []);
            }
        }

        return new JsonResponse($result);
    }

    #[Route('/asystent/strumien', name: 'app_rag_stream', methods: ['GET'])]
    public function streamAnswer(Request $request): Response
    {
        if (!$this->getUser() && !$this->settings->isDemoEnabled()) {
            return new JsonResponse(['error' => 'Wymagane logowanie.'], 403);
        }

        $question = trim((string) $request->query->get('q', ''));
        $volumeId = $request->query->get('volume') ? (int) $request->query->get('volume') : null;

        /** @var User|null $user */
        $user = $this->getUser();
        $unlimited = $this->isUnlimited($request);
        $response = new StreamedResponse(function () use ($user, $unlimited, $question, $volumeId) {
            $emit = function (string $event, array $data) {
                echo "event: {$event}\n";
                echo 'data: ' . json_encode($data) . "\n\n";
                @ob_flush();
                flush();
            };

            if ($question === '') {
                $emit('error', ['message' => 'Puste pytanie.']);
                return;
            }

            // Credit gate: assistant runs on a donation-funded question pool.
            if (!$unlimited && (!$user instanceof User || $user->getAiCredits() <= 0)) {
                $emit('citations', ['citations' => []]);
                $emit('token', ['t' => 'Wykorzystałeś pulę pytań do asystenta. Aby zadawać dalej, doładuj pulę w zakładce **Wesprzyj** — każda darowizna dodaje pytania.']);
                $emit('done', []);
                return;
            }

            // Cache hit → serve the stored answer instantly, skip the paid model.
            if ($hit = $this->cache->get($question, $volumeId)) {
                $emit('citations', ['citations' => $hit['citations']]);
                $rq = $this->spend($user, $unlimited, $volumeId, $question, true);
                if ($rq) {
                    $this->history->attach($rq, $question, $volumeId, $hit['answer'], $hit['citations']);
                }
                $emit('token', ['t' => $hit['answer']]);
                $emit('done', ['cached' => true]);
                return;
            }

            $prep = $this->rag->prepare($question, 8, $volumeId);
            $emit('citations', ['citations' => $prep['citations']]);

            if (!$prep['citations']) {
                $emit('token', ['t' => $prep['empty']]);
                $emit('done', []);
                return;
            }

            // Real answer about to be generated → spend one credit (unless unlimited).
            $rq = $this->spend($user, $unlimited, $volumeId, $question, false);

            $full = '';
            $usage = $this->rag->streamChat($this->rag->systemPrompt(), $prep['user'], function (string $tok) use ($emit, &$full) {
                $full .= $tok;
                $emit('token', ['t' => $tok]);
            });
            $emit('done', []);

            // Uzupełnij wpis o realne zużycie (tokeny + koszt USD z OpenRouter).
            $this->recordUsage($rq, $usage);

            // Persist for next time (first write wins) + zapisz w historii konta.
            if (trim($full) !== '') {
                $this->cache->put($question, $volumeId, $full, $prep['citations']);
                if ($rq) {
                    $this->history->attach($rq, $question, $volumeId, $full, $prep['citations']);
                }
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no'); // disable nginx buffering
        return $response;
    }

    #[Route('/asystent/docx', name: 'app_rag_docx', methods: ['POST'])]
    public function docx(Request $request): Response
    {
        if (!$this->getUser() && !$this->settings->isDemoEnabled()) {
            return new JsonResponse(['error' => 'Wymagane logowanie.'], 403);
        }

        $data = json_decode($request->getContent(), true) ?: [];
        $question = trim((string) ($data['question'] ?? ''));
        $answer = trim((string) ($data['answer'] ?? ''));
        if ($question === '' || $answer === '') {
            return new JsonResponse(['error' => 'Brak treści do zapisania.'], 400);
        }

        $bytes = $this->docx->build([
            'question' => $question,
            'answer' => $answer,
            'citations' => is_array($data['citations'] ?? null) ? $data['citations'] : [],
        ]);

        $slug = preg_replace('/[^a-z0-9]+/i', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $question) ?: 'odpowiedz');
        $slug = trim(strtolower(substr($slug, 0, 40)), '-') ?: 'odpowiedz';

        $response = new Response($bytes);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $response->headers->set('Content-Disposition', 'attachment; filename="wyszynski-' . $slug . '.docx"');
        return $response;
    }

    #[Route('/asystent/historia', name: 'app_rag_history', methods: ['GET'])]
    public function history(): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('rag/history.html.twig', [
            'items' => $this->history->forUser($user->getId()),
        ]);
    }

    #[Route('/asystent/historia/wyczysc', name: 'app_rag_history_clear', methods: ['POST'])]
    public function historyClear(): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Wymagane logowanie.'], 403);
        }
        $n = $this->history->clearForUser($user->getId());

        return new JsonResponse(['ok' => true, 'cleared' => $n]);
    }

    #[Route('/asystent/historia/{id}/pin', name: 'app_rag_history_pin', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function historyPin(int $id): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Wymagane logowanie.'], 403);
        }
        $pinned = $this->history->togglePin($id, $user->getId());
        if ($pinned === null) {
            return new JsonResponse(['error' => 'Nie znaleziono wpisu.'], 404);
        }

        return new JsonResponse(['ok' => true, 'pinned' => $pinned]);
    }

    #[Route('/asystent/historia/{id}/usun', name: 'app_rag_history_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function historyDelete(int $id): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Wymagane logowanie.'], 403);
        }
        if (!$this->history->deleteForUser($id, $user->getId())) {
            return new JsonResponse(['error' => 'Nie znaleziono wpisu.'], 404);
        }

        return new JsonResponse(['ok' => true]);
    }

    private function roman(int $n): string
    {
        $map = [1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD', 100 => 'C', 90 => 'XC',
                50 => 'L', 40 => 'XL', 10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I'];
        $out = '';
        foreach ($map as $val => $sym) {
            while ($n >= $val) { $out .= $sym; $n -= $val; }
        }
        return $out;
    }
}
