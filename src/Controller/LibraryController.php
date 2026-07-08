<?php

namespace App\Controller;

use App\Service\DocumentReader;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * BIBLIOTEKA — klimatyczna czytelnia dla Czytelników VIP: regał ze wszystkimi
 * tomami, wnętrze tomu ze spisem tekstów, oraz widok czytania pojedynczego
 * tekstu z możliwością odsłuchania nagrania audio. Dostęp gated na ROLE_VIP;
 * zalogowany bez VIP dostaje klimatyczny upsell, gość jest przekierowany do
 * logowania przez firewall (access_control ^/ = ROLE_USER).
 */
class LibraryController extends AbstractController
{
    public function __construct(
        private Connection $connection,
        private DocumentReader $reader,
    ) {}

    #[Route('/biblioteka', name: 'app_library')]
    public function index(): Response
    {
        if (!$this->isGranted('ROLE_VIP')) {
            return $this->render('library/locked.html.twig', ['scope' => 'index'], new Response('', 403));
        }

        $volumes = $this->connection->executeQuery(
            "SELECT v.id, v.number, v.title, v.year_from, v.year_to,
                    count(DISTINCT d.id) AS doc_count,
                    count(DISTINCT ar.document_id) AS audio_count
             FROM volumes v
             LEFT JOIN documents d ON d.volume_id = v.id
             LEFT JOIN audio_recordings ar
                    ON ar.document_id = d.id AND ar.is_published = true
                   AND ar.audio_file_name IS NOT NULL AND ar.audio_file_name <> ''
             WHERE v.status = 'opublikowany'
             GROUP BY v.id
             ORDER BY v.number IS NULL, v.number, v.id"
        )->fetchAllAssociative();

        return $this->render('library/index.html.twig', ['volumes' => $volumes]);
    }

    #[Route('/biblioteka/tom/{id}', name: 'app_library_volume', requirements: ['id' => '\d+'])]
    public function volume(int $id): Response
    {
        if (!$this->isGranted('ROLE_VIP')) {
            return $this->render('library/locked.html.twig', ['scope' => 'volume'], new Response('', 403));
        }

        $volume = $this->connection->executeQuery(
            "SELECT id, number, title, year_from, year_to
             FROM volumes WHERE id = :id AND status = 'opublikowany'",
            ['id' => $id]
        )->fetchAssociative();

        if (!$volume) {
            throw $this->createNotFoundException('Nie znaleziono tomu.');
        }

        $documents = $this->connection->executeQuery(
            "SELECT d.id, d.number_in_volume, d.title, d.document_type, d.location,
                    d.event_date_text, d.words_count,
                    EXISTS (
                        SELECT 1 FROM audio_recordings ar
                        WHERE ar.document_id = d.id AND ar.is_published = true
                          AND ar.audio_file_name IS NOT NULL AND ar.audio_file_name <> ''
                    ) AS has_audio
             FROM documents d
             WHERE d.volume_id = :id
             ORDER BY d.number_in_volume",
            ['id' => $id]
        )->fetchAllAssociative();

        return $this->render('library/volume.html.twig', [
            'volume' => $volume,
            'documents' => $documents,
        ]);
    }

    #[Route('/biblioteka/czytaj/{id}', name: 'app_library_read', requirements: ['id' => '\d+'])]
    public function read(int $id): Response
    {
        if (!$this->isGranted('ROLE_VIP')) {
            return $this->render('library/locked.html.twig', ['scope' => 'read'], new Response('', 403));
        }

        $doc = $this->connection->executeQuery(
            "SELECT d.id, d.title, d.subtitle, d.number_in_volume, d.location,
                    d.event_date_text, d.document_type, d.volume_id,
                    v.number AS volume_number, v.title AS volume_title
             FROM documents d
             JOIN volumes v ON v.id = d.volume_id
             WHERE d.id = :id AND v.status = 'opublikowany'",
            ['id' => $id]
        )->fetchAssociative();

        if (!$doc) {
            throw $this->createNotFoundException('Nie znaleziono tekstu.');
        }

        $volId = (int) $doc['volume_id'];
        $n = (int) $doc['number_in_volume'];

        $prev = $this->connection->executeQuery(
            "SELECT id, number_in_volume, title FROM documents
             WHERE volume_id = :vol AND number_in_volume < :n
             ORDER BY number_in_volume DESC LIMIT 1",
            ['vol' => $volId, 'n' => $n]
        )->fetchAssociative() ?: null;

        $next = $this->connection->executeQuery(
            "SELECT id, number_in_volume, title FROM documents
             WHERE volume_id = :vol AND number_in_volume > :n
             ORDER BY number_in_volume ASC LIMIT 1",
            ['vol' => $volId, 'n' => $n]
        )->fetchAssociative() ?: null;

        $audio = $this->reader->publishedAudio($id);

        return $this->render('library/read.html.twig', [
            'doc' => $doc,
            'content' => $this->reader->render($id),
            'audio' => $audio,
            'audioUrl' => $audio ? $this->generateUrl('app_document_audio', ['id' => $id]) : null,
            'prev' => $prev,
            'next' => $next,
        ]);
    }
}
