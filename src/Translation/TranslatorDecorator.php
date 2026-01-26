<?php

namespace App\Translation;

use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\MessageCatalogue;

class TranslatorDecorator implements TranslatorInterface, LocaleAwareInterface, TranslatorBagInterface
{
    public function __construct(private TranslatorInterface $inner)
    {
    }

    public function trans(string $id, array $parameters = [], string $domain = null, string $locale = null): string
    {
        $translated = $this->inner->trans($id, $parameters, $domain, $locale);

        if ($translated === $id) {
            return $this->humanizeKey($id);
        }

        return $translated;
    }

    public function setLocale(string $locale): void
    {
        if ($this->inner instanceof LocaleAwareInterface) {
            $this->inner->setLocale($locale);
        }
    }

    public function getLocale(): string
    {
        if ($this->inner instanceof LocaleAwareInterface) {
            return $this->inner->getLocale();
        }

        return 'en';
    }

    public function getCatalogue(?string $locale = null): MessageCatalogueInterface
    {
        if ($this->inner instanceof TranslatorBagInterface) {
            return $this->inner->getCatalogue($locale);
        }

        return new MessageCatalogue($locale ?? $this->getLocale());
    }

    public function getCatalogues(): array
    {
        if ($this->inner instanceof TranslatorBagInterface) {
            return $this->inner->getCatalogues();
        }

        return [$this->getCatalogue()];
    }

    private function humanizeKey(string $id): string
    {
        $parts = explode('.', $id);
        $last = end($parts) ?: $id;
        $last = str_replace('_', ' ', $last);
        $last = preg_replace('/\s+/', ' ', $last);

        return ucwords($last);
    }
}
