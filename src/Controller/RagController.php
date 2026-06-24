<?php

namespace App\Controller;

use App\Service\ChunkRetriever;
use App\Service\DocxBuilder;
use App\Service\RagAnswerer;
use App\Service\SettingsService;
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
    ) {}

    #[Route('/asystent', name: 'app_rag')]
    public function index(): Response
    {
        if (!$this->getUser() && !$this->settings->isDemoEnabled()) {
            return $this->redirectToRoute('app_login');
        }
        $volumes = array_map(function (array $v): array {
            $v['roman'] = $this->roman($v['number']);
            return $v;
        }, $this->retriever->availableVolumes());

        return $this->render('rag/index.html.twig', ['volumes' => $volumes]);
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

        $result = $this->rag->answer($question, 8, $volumeId);

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

        $rag = $this->rag;
        $response = new StreamedResponse(function () use ($rag, $question, $volumeId) {
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

            $prep = $rag->prepare($question, 8, $volumeId);
            $emit('citations', ['citations' => $prep['citations']]);

            if (!$prep['citations']) {
                $emit('token', ['t' => $prep['empty']]);
                $emit('done', []);
                return;
            }

            $rag->streamChat($rag->systemPrompt(), $prep['user'], function (string $tok) use ($emit) {
                $emit('token', ['t' => $tok]);
            });
            $emit('done', []);
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
