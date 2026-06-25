<?php

namespace App\Controller;

use App\Entity\RagQuery;
use App\Entity\User;
use App\Service\ChunkRetriever;
use App\Service\DocxBuilder;
use App\Service\RagAnswerer;
use App\Service\RagCache;
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
    ) {}

    /** Spend one credit + log usage (no-op for unlimited users). */
    private function spend(?User $user, bool $unlimited, ?int $volumeId): void
    {
        if ($unlimited || !$user instanceof User) {
            return;
        }
        $user->spendAiCredit();
        $this->em->persist(new RagQuery($user->getId(), $volumeId));
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
            $this->spend($user, $unlimited, $volumeId);
            return new JsonResponse([
                'answer' => $hit['answer'],
                'citations' => $hit['citations'],
                'used' => true,
                'cached' => true,
            ]);
        }

        $result = $this->rag->answer($question, 8, $volumeId);

        if ($result['used'] ?? false) {
            $this->spend($user, $unlimited, $volumeId);
            $this->cache->put($question, $volumeId, (string) $result['answer'], $result['citations'] ?? []);
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
                $this->spend($user, $unlimited, $volumeId);
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
            $this->spend($user, $unlimited, $volumeId);

            $full = '';
            $this->rag->streamChat($this->rag->systemPrompt(), $prep['user'], function (string $tok) use ($emit, &$full) {
                $full .= $tok;
                $emit('token', ['t' => $tok]);
            });
            $emit('done', []);

            // Persist for next time (first write wins).
            if (trim($full) !== '') {
                $this->cache->put($question, $volumeId, $full, $prep['citations']);
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
