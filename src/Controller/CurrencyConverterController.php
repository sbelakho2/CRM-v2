<?php

namespace App\Controller;

use App\Service\CurrencyConversionService;
use App\Service\CurrencyConverter;
use App\Service\LiveFxRateFetcher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Psr\Log\LoggerInterface;

#[Route('/currency-converter')]
#[IsGranted('ROLE_USER')]
class CurrencyConverterController extends AbstractController
{
    public function __construct(
        private CurrencyConversionService $conversionService,
        private CurrencyConverter $currencyConverter,
        private LiveFxRateFetcher $liveFxRateFetcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * Currency Converter page — main UI
     */
    #[Route('', name: 'currency_converter_index', methods: ['GET'])]
    public function index(): Response
    {
        $supported = $this->liveFxRateFetcher->getSupportedCurrencies();
        $freshness = $this->liveFxRateFetcher->checkRateFreshness();
        $displayCurrency = $this->currencyConverter->getDisplayCurrency();

        // Build full currency list with labels
        $currencies = $this->getCurrencyList();

        return $this->render('currency_converter/index.html.twig', [
            'currencies' => $currencies,
            'supported' => $supported,
            'freshness' => $freshness,
            'displayCurrency' => $displayCurrency,
        ]);
    }

    /**
     * AJAX endpoint — convert amount using live rates.
     * Also triggers a system-wide rate refresh so all FX data stays current.
     */
    #[Route('/convert', name: 'currency_converter_convert', methods: ['POST'])]
    public function convert(Request $request): JsonResponse
    {
        /** @var array<string, mixed>|null $data */
        $data = json_decode($request->getContent(), true);

        $csrfToken = $data['_csrf_token'] ?? '';
        if (!$this->isCsrfTokenValid('currency_converter_convert', is_scalar($csrfToken) ? (string) $csrfToken : '')) {
            return $this->json(['error' => 'Invalid CSRF token.'], 403);
        }

        $amountRaw = $data['amount'] ?? 0;
        $amount = is_scalar($amountRaw) ? (float) $amountRaw : 0.0;
        $fromRaw = $data['from'] ?? 'USD';
        $from = strtoupper(trim(is_scalar($fromRaw) ? (string) $fromRaw : 'USD'));
        $toRaw = $data['to'] ?? 'USD';
        $to = strtoupper(trim(is_scalar($toRaw) ? (string) $toRaw : 'USD'));

        if ($amount <= 0) {
            return $this->json(['error' => 'Amount must be greater than zero'], 400);
        }

        // Force a live fetch so we always use the freshest rate
        $liveRate = $this->liveFxRateFetcher->getLiveRate($from, $to);

        if ($liveRate) {
            $converted = round($amount * $liveRate['rate'], 6);
            $result = [
                'success' => true,
                'amount' => $amount,
                'from' => $from,
                'to' => $to,
                'rate' => round($liveRate['rate'], 6),
                'result' => $converted,
                'formatted' => $this->currencyConverter->format($converted, $to, $to),
                'source' => $liveRate['source'],
                'timestamp' => $liveRate['timestamp']->format('Y-m-d H:i:s T'),
                'stale' => false,
            ];
        } else {
            // Fallback to CurrencyConversionService (DB → fallback chain)
            $convResult = $this->conversionService->convertWithAudit($amount, $from, $to);
            $result = [
                'success' => true,
                'amount' => $amount,
                'from' => $from,
                'to' => $to,
                'rate' => $convResult['rate'],
                'result' => $convResult['amount'],
                'formatted' => $this->currencyConverter->format($convResult['amount'], $to, $to),
                'source' => $convResult['source'],
                'timestamp' => (new \DateTime())->format('Y-m-d H:i:s T'),
                'stale' => $convResult['stale'],
                'warning' => $convResult['warning'] ?? null,
            ];
        }

        return $this->json($result);
    }

    /**
     * AJAX endpoint — refresh all system FX rates from live APIs.
     * This updates the entire system's currency data.
     */
    #[Route('/refresh-rates', name: 'currency_converter_refresh', methods: ['POST'])]
    public function refreshRates(Request $request): JsonResponse
    {
        /** @var array<string, mixed>|null $data */
        $data = json_decode($request->getContent(), true);

        $csrfToken = $data['_csrf_token'] ?? '';
        if (!$this->isCsrfTokenValid('currency_converter_refresh', is_scalar($csrfToken) ? (string) $csrfToken : '')) {
            return $this->json(['error' => 'Invalid CSRF token.'], 403);
        }

        try {
            $results = $this->liveFxRateFetcher->fetchAllRates();
            $freshness = $this->liveFxRateFetcher->checkRateFreshness();

            return $this->json([
                'success' => true,
                'fetched' => count($results['success']),
                'failed' => count($results['failed']),
                'source' => $results['source'],
                'rates' => $freshness,
                'timestamp' => (new \DateTime())->format('Y-m-d H:i:s T'),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to refresh rates', ['exception' => $e]);
            return $this->json([
                'success' => false,
                'error' => 'Operation failed. Please try again.',
            ], 500);
        }
    }

    /**
     * AJAX endpoint — get current rate freshness status.
     */
    #[Route('/rate-status', name: 'currency_converter_status', methods: ['GET'])]
    public function rateStatus(): JsonResponse
    {
        $freshness = $this->liveFxRateFetcher->checkRateFreshness();

        $fresh = 0;
        $stale = 0;
        foreach ($freshness as $info) {
            if ($info['stale']) {
                $stale++;
            } else {
                $fresh++;
            }
        }

        return $this->json([
            'total' => count($freshness),
            'fresh' => $fresh,
            'stale' => $stale,
            'rates' => $freshness,
        ]);
    }

    /**
     * Full currency list with human-readable labels
     *
     * @return array<string, string>
     */
    private function getCurrencyList(): array
    {
        return [
            'USD' => 'US Dollar (USD)',
            'EUR' => 'Euro (EUR)',
            'GBP' => 'British Pound (GBP)',
            'MAD' => 'Moroccan Dirham (MAD)',
            'TND' => 'Tunisian Dinar (TND)',
            'JPY' => 'Japanese Yen (JPY)',
            'CHF' => 'Swiss Franc (CHF)',
            'CAD' => 'Canadian Dollar (CAD)',
            'AUD' => 'Australian Dollar (AUD)',
            'CNY' => 'Chinese Yuan (CNY)',
            'HKD' => 'Hong Kong Dollar (HKD)',
            'SGD' => 'Singapore Dollar (SGD)',
            'TWD' => 'New Taiwan Dollar (TWD)',
            'KRW' => 'South Korean Won (KRW)',
            'INR' => 'Indian Rupee (INR)',
            'BRL' => 'Brazilian Real (BRL)',
            'MXN' => 'Mexican Peso (MXN)',
            'ZAR' => 'South African Rand (ZAR)',
            'SEK' => 'Swedish Krona (SEK)',
            'NOK' => 'Norwegian Krone (NOK)',
            'DKK' => 'Danish Krone (DKK)',
            'PLN' => 'Polish Złoty (PLN)',
            'CZK' => 'Czech Koruna (CZK)',
            'THB' => 'Thai Baht (THB)',
            'MYR' => 'Malaysian Ringgit (MYR)',
            'PHP' => 'Philippine Peso (PHP)',
            'IDR' => 'Indonesian Rupiah (IDR)',
            'VND' => 'Vietnamese Đồng (VND)',
            'AED' => 'UAE Dirham (AED)',
            'SAR' => 'Saudi Riyal (SAR)',
            'TRY' => 'Turkish Lira (TRY)',
            'NZD' => 'New Zealand Dollar (NZD)',
            'EGP' => 'Egyptian Pound (EGP)',
        ];
    }
}
