<?php

namespace App\Controller;

use App\Service\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    public function __construct(private SettingsService $settings) {}

    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        if ($this->getUser() || $this->settings->isDemoEnabled()) {
            return $this->redirectToRoute('app_search');
        }
        return $this->redirectToRoute('app_login');
    }

    #[Route('/content', name: 'app_home_content')]
    public function content(Request $request): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('app_home');
        }

        $response = $this->render('home/_content.html.twig');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
