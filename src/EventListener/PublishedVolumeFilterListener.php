<?php

namespace App\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Disables the published_volume Doctrine filter for admin users,
 * so they can see documents from all volumes regardless of status.
 */
#[AsEventListener(event: 'kernel.request', priority: 10)]
class PublishedVolumeFilterListener
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if ($this->security->isGranted('ROLE_ADMIN')) {
            $filters = $this->em->getFilters();
            if ($filters->isEnabled('published_volume')) {
                $filters->disable('published_volume');
            }
        }
    }
}
