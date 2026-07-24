<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;

class UserLocaleTimezoneSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Security $security,
        private TranslatorInterface $translator,
        private string $defaultLocale,
        private string $defaultTimezone
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $user = $this->security->getUser();

        if ($user instanceof User) {
            $locale = $user->getPreferredLocale();
            $timezone = $user->getPreferredTimezone();

            if ($locale) {
                $request->setLocale($locale);
                $this->translator->setLocale($locale);
                if ($request->hasSession()) {
                    $request->getSession()->set('_locale', $locale);
                }
            }

            if ($timezone) {
                $request->attributes->set('_timezone', $timezone);
            }
        } else {
            $request->setLocale($this->defaultLocale);
            $this->translator->setLocale($this->defaultLocale);
            if ($request->hasSession()) {
                $request->getSession()->set('_locale', $this->defaultLocale);
            }

            $request->attributes->set('_timezone', $this->defaultTimezone);
        }
    }
}
