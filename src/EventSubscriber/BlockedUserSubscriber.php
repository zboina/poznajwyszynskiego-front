<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Wymusza utratę dostępu natychmiast po zablokowaniu konta (is_active = false),
 * także dla użytkownika, który JEST już zalogowany. UserChecker działa tylko przy
 * logowaniu, więc bez tego zablokowany użytkownik korzystałby z serwisu do końca sesji.
 *
 * Provider odświeża encję User z bazy przy każdym żądaniu, więc isActive() odzwierciedla
 * bieżący stan — gdy jest false, czyścimy sesję i przekierowujemy na logowanie.
 */
class BlockedUserSubscriber implements EventSubscriberInterface
{
    /** Trasy, na których nie wymuszamy wylogowania (by uniknąć pętli przekierowań). */
    private const ALLOWLIST = ['app_login', 'app_logout'];

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly RouterInterface $router,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Priorytet < 8 (firewall), by token był już ustawiony, ale przed kontrolerem.
        return [KernelEvents::REQUEST => ['onRequest', 6]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof User || $user->isActive()) {
            return;
        }

        $request = $event->getRequest();
        if (in_array($request->attributes->get('_route'), self::ALLOWLIST, true)) {
            return;
        }

        // Konto zablokowane w trakcie trwającej sesji → natychmiast odetnij dostęp.
        $this->tokenStorage->setToken(null);
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->invalidate();
        }
        if ($request->hasSession()) {
            $request->getSession()->getFlashBag()->add(
                'error',
                'Twoje konto zostało zablokowane. Skontaktuj się z administratorem.'
            );
        }

        $event->setResponse(new RedirectResponse($this->router->generate('app_login')));
    }
}
