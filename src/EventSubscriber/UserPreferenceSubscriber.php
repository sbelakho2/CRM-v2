<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Bundle\SecurityBundle\Security;

class UserPreferenceSubscriber implements EventSubscriberInterface
{
    public function __construct(private Security $security) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 20],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return;
        }

        $preferredLocale = $user->getPreferredLocale();
        if ($preferredLocale) {
            $request->setLocale($preferredLocale);
            if ($request->hasSession()) {
                $request->getSession()->set('_locale', $preferredLocale);
            }
        }

        $preferredTimezone = $user->getPreferredTimezone();
        if ($preferredTimezone) {
            @date_default_timezone_set($preferredTimezone);
        }
    }
}
