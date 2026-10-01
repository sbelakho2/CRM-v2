<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BomLine;
use OnnxRuntime\InferenceSession;
use Psr\Log\LoggerInterface;

/**
 * ONNX ML-Based Price Imputation Service v2
 *
 * Uses a trained PricingTransformer neural network (ONNX Runtime) to estimate
 * component prices when external API pricing is unavailable.
 *
 * Model architecture: Transformer with categorical embeddings, text tokenization,
 * and continuous feature projection. Trained on 300K+ synthetic + real DigiKey samples.
 *
 * Performance (DigiKey spot-check, 84 components):
 *   - Overall MdAPE: 23.4%  (vs 56% old heuristic)
 *   - 50% within 25% error, 61% within 50%
 *   - $0.01–$0.10: MdAPE 20.8%
 *   - $0.10–$1.00: MdAPE 20.4%
 *   - $1.00–$10.00: MdAPE 20.5%
 *   - R² (validation): 0.9802
 */
class PriceImputationService
{
    private const MODEL_DIR = __DIR__ . '/../../ml/models';

    /**
     * ONNX session (instance of OnnxRuntime\InferenceSession when the optional
     * binding package is installed). Untyped on purpose: the binding is an
     * optional dependency that may be absent from the runtime environment.
     *
     * @var object|null
     */
    private $session = null;

    /** @var array<string, int> Category → encoded ID */
    private array $categoryEncoder = [];

    /** @var array<string, int> Supplier → encoded ID */
    private array $supplierEncoder = [];

    /** @var array<string, int> Manufacturer → encoded ID */
    private array $manufacturerEncoder = [];

    /** @var array<string, int> Industry → encoded ID */
    private array $industryEncoder = [];

    /** @var array<string, int> Word → token ID */
    private array $tokenizer = [];

    /** Price scaler parameters for inverse transform */
    private float $scalerMean = 0.0;
    private float $scalerScale = 1.0;
    private float $priceFloor = 0.001;

    private int $maxTextLen = 48;
    private bool $modelLoaded = false;

    /** Guards the "model files not found" log so it fires once per process. */
    private bool $missingModelLogged = false;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Impute a price for a component when API pricing is unavailable.
     *
     * Uses ONNX neural network inference with fallback to heuristic if model unavailable.
     *
     * @param BomLine|array<string, mixed> $component
     * @return array{price: float, confidence: float, method: string, factors: array<string, mixed>}
     */
    public function imputePrice(BomLine|array $component): array
    {
        $data = $this->normalizeInput($component);

        // Try ONNX model first
        if ($this->ensureModelLoaded()) {
            try {
                return $this->imputeWithOnnx($data);
            } catch (\Throwable $e) {
                $this->logger->error('ONNX inference failed, falling back to heuristic', [
                    'mpn' => $data['mpn'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback: simple heuristic
        return $this->imputeWithHeuristic($data);
    }

    /**
     * Batch impute prices for multiple components.
     *
     * @param array<int|string, BomLine|array<string, mixed>> $components
     * @return array<int|string, array{price: float, confidence: float, method: string, factors: array<string, mixed>}> Array of imputation results keyed by index
     */
    public /**
 * @param array<string|int, mixed> $components
 */
function batchImputePrices(array $components): array
    {
        $results = [];
        foreach ($components as $index => $component) {
            $results[$index] = $this->imputePrice($component);
        }
        return $results;
    }

    /**
     * Get model information for transparency.
     *
     * @return array<string, bool|int|string>
     */
    public function getModelInfo(): array
    {
        $loaded = $this->ensureModelLoaded();

        return [
            'version' => $loaded ? 'onnx_transformer_v2' : 'heuristic_fallback',
            'algorithm' => $loaded ? 'PricingTransformer (ONNX)' : 'heuristic_multiplier_chain',
            'model_loaded' => $loaded,
            'categories' => count($this->categoryEncoder),
            'manufacturers' => count($this->manufacturerEncoder),
            'vocabulary_size' => count($this->tokenizer),
            'training_data' => '200K synthetic + 52K HuggingFace + 220K DigiKey (5.5K × 40)',
            'spot_check_mdape' => '23.4% overall (84 DigiKey components)',
            'last_updated' => '2026-02-07',
        ];
    }

    // =========================================================================
    // ONNX INFERENCE
    // =========================================================================

    /**
     * Run ONNX model inference to predict price.
     *
     * @param array{mpn: string, manufacturer: string, description: string, quantity: int, supplier: string, source: string, industry: string, moq: int, lead_time_days: float, reliability_score: float, complexity_factor: float} $data
     * @return array{price: float, confidence: float, method: string, factors: array<string, mixed>}
     */
    private /**
 * @param array<string|int, mixed> $data
 */
function imputeWithOnnx(array $data): array
    {
        $category = $this->detectCategory($data);
        $supplier = $this->detectSupplier($data);
        $manufacturer = $data['manufacturer'];
        $industry = $data['industry'];

        // Encode categorical features
        $categoryId = $this->categoryEncoder[$category] ?? 0;
        $supplierId = $this->supplierEncoder[$supplier] ?? 0;
        $manufacturerId = $this->resolveManufacturerId($manufacturer);
        $industryId = $this->industryEncoder[$industry] ?? 0;

        // Encode text (description + MPN)
        $textIds = $this->encodeText($data['description'] . ' ' . $data['mpn']);

        // Build continuous features (8 total, matching training pipeline)
        $quantity = max(1, $data['quantity']);
        $moq = max(1, $data['moq']);
        $leadTime = $data['lead_time_days'];
        $reliability = $data['reliability_score'];
        $complexity = $data['complexity_factor'];
        $qtyMoqRatio = $quantity / max($moq, 1);
        $isAboveMoq = $quantity >= $moq ? 1.0 : 0.0;
        $volumeTier = min(4, (int) floor(log10(max($quantity, 1))));

        $continuous = [
            log1p($quantity),           // log_quantity
            log1p($moq),               // log_moq
            $leadTime / 30.0,          // normalized lead time
            $reliability,              // reliability score
            $complexity,               // complexity factor
            $qtyMoqRatio / 10.0,       // normalized qty/moq ratio
            $isAboveMoq,              // binary: above MOQ?
            $volumeTier / 4.0,         // normalized volume tier
        ];

        // Run inference — ONNX expects arrays wrapped in batch dimension
        $session = $this->session;
        if ($session === null || !method_exists($session, 'run')) {
            throw new \RuntimeException('ONNX session not initialised');
        }
        /** @var list<list<float>> $output */
        $output = $session->run(null, [
            'category_id' => [$categoryId],
            'supplier_id' => [$supplierId],
            'manufacturer_id' => [$manufacturerId],
            'industry_id' => [$industryId],
            'text_ids' => [$textIds],
            'continuous' => [$continuous],
        ]);

        // Output is scaled log-space prediction: price = exp(pred * scale + mean) - floor
        $scaledPrediction = $output[0][0];
        $logPrice = $scaledPrediction * $this->scalerScale + $this->scalerMean;
        $price = exp($logPrice) - $this->priceFloor;
        $price = max(0.0001, round($price, 6));

        // Confidence based on input data quality
        $confidence = $this->calculateConfidence($data, $category);

        $this->logger->info('ONNX price imputation completed', [
            'mpn' => $data['mpn'],
            'imputed_price' => $price,
            'confidence' => round($confidence, 2),
            'category' => $category,
            'supplier' => $supplier,
            'manufacturer_id' => $manufacturerId,
            'scaled_prediction' => $scaledPrediction,
        ]);

        return [
            'price' => $price,
            'confidence' => round($confidence, 2),
            'method' => 'onnx_transformer_v2',
            'factors' => [
                'category' => $category,
                'supplier' => $supplier,
                'manufacturer' => $manufacturer,
                'industry' => $industry,
                'quantity' => $quantity,
                'model' => 'PricingTransformer',
                'scaled_prediction' => round($scaledPrediction, 4),
            ],
        ];
    }

    // =========================================================================
    // MODEL LOADING
    // =========================================================================

    /**
     * Lazily load the ONNX model and all encoding artifacts.
     */
    private function ensureModelLoaded(): bool
    {
        if ($this->modelLoaded) {
            return true;
        }

        $modelPath = self::MODEL_DIR . '/pricing_model.onnx';
        $configPath = self::MODEL_DIR . '/pricing_config.json';
        $tokenizerPath = self::MODEL_DIR . '/pricing_tokenizer.json';
        $scalerPath = self::MODEL_DIR . '/price_scaler.json';

        if (!file_exists($modelPath) || !file_exists($configPath)
            || !file_exists($tokenizerPath) || !file_exists($scalerPath)) {
            // Log once per process — a missing ml/models directory is a
            // persistent deployment condition, not a per-request event.
            if (!$this->missingModelLogged) {
                $this->missingModelLogged = true;
                $this->logger->warning('ONNX model files not found, using heuristic fallback', [
                    'model_dir' => self::MODEL_DIR,
                    'model_exists' => file_exists($modelPath),
                    'config_exists' => file_exists($configPath),
                    'tokenizer_exists' => file_exists($tokenizerPath),
                    'scaler_exists' => file_exists($scalerPath),
                ]);
            }
            return false;
        }

        try {
            // The ONNX Runtime PHP binding is an optional dependency: when the
            // extension/package is absent we degrade to the heuristic fallback
            // instead of relying on catching the class-not-found Error below.
            if (!class_exists(InferenceSession::class)) {
                $this->logger->warning('ONNX Runtime binding not available, using heuristic fallback');
                return false;
            }

            // Load ONNX session
            $this->session = new InferenceSession($modelPath);

            // Load config with encoders
            $configRaw = file_get_contents($configPath);
            if ($configRaw === false) {
                throw new \RuntimeException("Unable to read model config: {$configPath}");
            }
            /** @var array{encoders?: array{category?: array<string, int>, supplier?: array<string, int>, manufacturer?: array<string, int>, industry?: array<string, int>}, max_text_len?: int} $config */
            $config = json_decode($configRaw, true, 512, \JSON_THROW_ON_ERROR);
            $this->categoryEncoder = $config['encoders']['category'] ?? [];
            $this->supplierEncoder = $config['encoders']['supplier'] ?? [];
            $this->manufacturerEncoder = $config['encoders']['manufacturer'] ?? [];
            $this->industryEncoder = $config['encoders']['industry'] ?? [];
            $this->maxTextLen = $config['max_text_len'] ?? 48;

            // Load tokenizer
            $tokenizerRaw = file_get_contents($tokenizerPath);
            if ($tokenizerRaw === false) {
                throw new \RuntimeException("Unable to read tokenizer: {$tokenizerPath}");
            }
            /** @var array{word2idx?: array<string, int>} $tokenizerData */
            $tokenizerData = json_decode($tokenizerRaw, true, 512, \JSON_THROW_ON_ERROR);
            $this->tokenizer = $tokenizerData['word2idx'] ?? [];

            // Load price scaler
            $scalerRaw = file_get_contents($scalerPath);
            if ($scalerRaw === false) {
                throw new \RuntimeException("Unable to read price scaler: {$scalerPath}");
            }
            /** @var array{mean?: int|float, scale?: int|float, price_floor?: int|float} $scalerData */
            $scalerData = json_decode($scalerRaw, true, 512, \JSON_THROW_ON_ERROR);
            $this->scalerMean = (float) ($scalerData['mean'] ?? 0.0);
            $this->scalerScale = (float) ($scalerData['scale'] ?? 1.0);
            $this->priceFloor = (float) ($scalerData['price_floor'] ?? 0.001);

            $this->modelLoaded = true;

            $this->logger->info('ONNX pricing model loaded', [
                'categories' => count($this->categoryEncoder),
                'suppliers' => count($this->supplierEncoder),
                'manufacturers' => count($this->manufacturerEncoder),
                'vocab_size' => count($this->tokenizer),
                'scaler_mean' => $this->scalerMean,
                'scaler_scale' => $this->scalerScale,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to load ONNX model', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    // =========================================================================
    // TEXT ENCODING (matches Python ElectronicsTokenizer.encode)
    // =========================================================================

    /**
     * Tokenize text into integer IDs, matching the Python training tokenizer.
     *
     * @return int[] Array of token IDs, padded/truncated to maxTextLen
     */
    private function encodeText(string $text): array
    {
        $text = strtolower($text);
        preg_match_all('/[a-z0-9]+/', $text, $matches);
        $tokens = array_slice($matches[0], 0, $this->maxTextLen);

        $ids = [];
        foreach ($tokens as $token) {
            $ids[] = $this->tokenizer[$token] ?? 1; // 1 = <UNK>
        }

        // Pad to maxTextLen with 0 = <PAD>
        while (count($ids) < $this->maxTextLen) {
            $ids[] = 0;
        }

        return $ids;
    }

    // =========================================================================
    // FEATURE DETECTION (category, supplier, manufacturer)
    // =========================================================================

    /**
     * Detect component category from MPN and description using pattern matching.
     * Maps to the model's 25 trained categories.
     *
     * @param array{mpn: string, manufacturer: string, description: string, quantity: int, supplier: string, source: string, industry: string, moq: int, lead_time_days: float, reliability_score: float, complexity_factor: float} $data
     */
    private /**
 * @param array<string|int, mixed> $data
 */
function detectCategory(array $data): string
    {
        $mpn = strtolower($data['mpn']);
        $desc = strtolower($data['description']);
        $combined = "$mpn $desc";

        // Order matters: more specific categories first
        $patterns = [
            'ic_microcontroller' => [
                '/\b(mcu|microcontroller|micro.*controller|pic|avr|8051)\b/',
                '/^(atmega|attiny|stm32|pic\d|msp430|esp32|samd|nrf52)/i',
            ],
            'ic_memory' => [
                '/\b(memory|flash|eeprom|sram|dram|nor|nand|sdram)\b/',
                '/^(mt\d|is\d|w25|at24|sst|m25|s25|gd25)/i',
            ],
            'ic_power' => [
                '/\b(regulator|ldo|dc.*dc|buck|boost|pmic|charger)\b/',
                '/^(lm78|lm79|lm317|ams117|mp\d|tps\d|lt\d)/i',
            ],
            'ic_analog' => [
                '/\b(adc|dac|op.*amp|amplifier|comparator|analog)\b/',
                '/^(ad\d|lm358|lm324|tl\d|op\d|ina\d)/i',
            ],
            'ic_logic' => [
                '/\b(logic|gate|buffer|driver|flip.*flop|counter|shift)\b/',
                '/^(74hc|74ls|74lv|cd40|sn74)/i',
            ],
            'mosfet' => [
                '/\b(mosfet|power\s*fet)\b/',
                '/^(irfz|irf\d|fdn|ao\d|si\d{4}|bss)/i',
            ],
            'igbt' => [
                '/\b(igbt)\b/',
            ],
            'transistor' => [
                '/\b(transistor|bjt|jfet)\b/',
                '/^(2n|bc\d|bd\d|bf\d|mmbt)/i',
            ],
            'resistor' => [
                '/\b(resistor|res|ohm|Ω)\b/',
                '/^(rc|crcw|era|rnc|rtf|rm|rk|rl|rn)[a-z0-9]/i',
                '/\d+[kmr]?\d*\s*(ohm|Ω)/i',
            ],
            'capacitor' => [
                '/\b(capacitor|cap|ceramic|mlcc|tant|electrolytic)\b/',
                '/^(cl|grm|gcc|c0g|x7r|x5r|y5v|np0|tps|tmk|08055c)/i',
                '/\d+[npμu]f/i',
            ],
            'inductor' => [
                '/\b(inductor|ind|choke|coil|ferrite)\b/',
                '/^(lqh|nlv|brl|sdcl|dlf|cdrh)/i',
                '/\d+[nμu]h/i',
            ],
            'diode' => [
                '/\b(diode|zener|schottky|tvs|esd)\b/',
                '/^(1n|bat|bas|bav|bzx|smaj|smbj|udzs)/i',
            ],
            'led' => [
                '/\b(led|light.*emitting|indicator)\b/',
                '/^(kp|apt|cree|ln|osram|lumileds)/i',
            ],
            'crystal_oscillator' => [
                '/\b(crystal|xtal|oscillator|resonator|mhz|khz)\b/',
                '/^(abm|hc49|act|ndk|fa\-)/i',
            ],
            'connector' => [
                '/\b(connector|header|socket|terminal|pin|receptacle|plug|jack)\b/',
                '/^(molex|jst|amp|te|hirose|samtec|mill\-?max)/i',
            ],
            'relay' => [
                '/\b(relay|switch.*relay|ssr)\b/',
                '/^(omron|panasonic|song.*chuan|finder)/i',
            ],
            'switch' => [
                '/\b(switch|button|toggle|dip\s*switch|tact)\b/',
            ],
            'transformer' => [
                '/\b(transformer|xfmr|toroid)\b/',
            ],
            'sensor' => [
                '/\b(sensor|accelerometer|gyro|temperature|pressure|humidity)\b/',
                '/^(bme|bmp|mpu|lis|lsm|hdc|tmp|si70)/i',
            ],
            'wire_harness' => [
                '/\b(wire\s*harness|wiring|cable\s*harness)\b/',
            ],
            'cable_assembly' => [
                '/\b(cable\s*assembly|cable|coax)\b/',
            ],
            'pcb_bare' => [
                '/\b(pcb|printed\s*circuit\s*board|bare\s*board)\b/',
            ],
            'pcba_assembly' => [
                '/\b(pcba|assembly|populated)\b/',
            ],
            'mechanical_part' => [
                '/\b(enclosure|bracket|heatsink|standoff|screw|nut|washer|spacer)\b/',
            ],
            'machined_part' => [
                '/\b(machined|cnc|milled|turned|casting)\b/',
            ],
        ];

        foreach ($patterns as $category => $categoryPatterns) {
            foreach ($categoryPatterns as $pattern) {
                if (preg_match($pattern, $combined)) {
                    // Verify the category exists in the model's encoder
                    if (isset($this->categoryEncoder[$category])) {
                        return $category;
                    }
                }
            }
        }

        // Default to resistor (most common, safe median) if model is loaded
        // otherwise return a generic key
        return isset($this->categoryEncoder['resistor']) ? 'resistor' : array_key_first($this->categoryEncoder) ?? 'resistor';
    }

    /**
     * Detect likely supplier context. Defaults to DigiKey (most common reference pricing).
     *
     * @param array{mpn: string, manufacturer: string, description: string, quantity: int, supplier: string, source: string, industry: string, moq: int, lead_time_days: float, reliability_score: float, complexity_factor: float} $data
     */
    private /**
 * @param array<string|int, mixed> $data
 */
function detectSupplier(array $data): string
    {
        $source = strtolower($data['source']);

        if (str_contains($source, 'mouser')) {
            return 'mouser';
        }
        if (str_contains($source, 'digikey') || str_contains($source, 'digi-key')) {
            return 'digikey';
        }
        if (str_contains($source, 'arrow')) {
            return 'arrow';
        }
        if (str_contains($source, 'avnet')) {
            return 'avnet';
        }
        if (str_contains($source, 'newark') || str_contains($source, 'farnell')) {
            return 'newark';
        }
        if (str_contains($source, 'alibaba')) {
            return 'alibaba_gold';
        }

        // Default to DigiKey as reference pricing baseline
        return 'digikey';
    }

    /**
     * Resolve manufacturer name to encoder ID using fuzzy matching.
     */
    private function resolveManufacturerId(string $manufacturer): int
    {
        if (empty($manufacturer)) {
            return 0;
        }

        // Exact match
        if (isset($this->manufacturerEncoder[$manufacturer])) {
            return $this->manufacturerEncoder[$manufacturer];
        }

        // Case-insensitive search
        $mfrLower = strtolower(trim($manufacturer));
        foreach ($this->manufacturerEncoder as $name => $id) {
            if (strtolower($name) === $mfrLower) {
                return $id;
            }
        }

        // Partial/fuzzy match (e.g., "TI" → "Texas Instruments")
        $aliases = [
            'ti' => 'Texas Instruments',
            'adi' => 'Analog Devices',
            'st' => 'STMicroelectronics',
            'onsemi' => 'onsemi',
            'on semi' => 'ON Semiconductor',
            'mchp' => 'Microchip',
            'microchip technology' => 'Microchip Technology',
            'nxp' => 'NXP Semiconductors',
            'maxim' => 'Maxim Integrated',
            'vishay' => 'Vishay',
        ];

        if (isset($aliases[$mfrLower]) && isset($this->manufacturerEncoder[$aliases[$mfrLower]])) {
            return $this->manufacturerEncoder[$aliases[$mfrLower]];
        }

        // Substring match
        foreach ($this->manufacturerEncoder as $name => $id) {
            if (str_contains(strtolower($name), $mfrLower) || str_contains($mfrLower, strtolower($name))) {
                return $id;
            }
        }

        return 0; // Unknown manufacturer
    }

    // =========================================================================
    // CONFIDENCE SCORING
    // =========================================================================

    /**
     * Calculate confidence score based on input data quality.
     *
     * @param array{mpn: string, manufacturer: string, description: string, quantity: int, supplier: string, source: string, industry: string, moq: int, lead_time_days: float, reliability_score: float, complexity_factor: float, package?: string} $data
     */
    private /**
 * @param array<string|int, mixed> $data
 */
function calculateConfidence(array $data, string $category): float
    {
        $confidence = 0.60; // Base confidence for ONNX model (higher than heuristic)

        if (!empty($data['mpn'])) {
            $confidence += 0.08;
        }
        if (!empty($data['manufacturer'])) {
            $confidence += 0.08;
        }
        if (!empty($data['description'])) {
            $confidence += 0.08;
        }
        if (!empty($data['package'] ?? null)) {
            $confidence += 0.04;
        }

        // Known category boosts confidence
        if ($category !== 'resistor' || stripos($data['description'], 'resist') !== false) {
            $confidence += 0.07;
        }

        return min(0.95, $confidence);
    }

    // =========================================================================
    // HEURISTIC FALLBACK (used when ONNX model is unavailable)
    // =========================================================================

    private const HEURISTIC_BASE_PRICES = [
        'resistor' => 0.006, 'capacitor' => 0.02, 'inductor' => 0.08,
        'diode' => 0.04, 'transistor' => 0.04, 'mosfet' => 1.20,
        'igbt' => 5.00, 'led' => 0.10, 'crystal_oscillator' => 0.60,
        'connector' => 0.45, 'switch' => 0.35, 'relay' => 2.80,
        'transformer' => 4.00, 'sensor' => 2.80,
        'ic_microcontroller' => 3.50, 'ic_memory' => 2.00,
        'ic_analog' => 1.20, 'ic_power' => 1.60, 'ic_logic' => 0.22,
        'wire_harness' => 25.00, 'cable_assembly' => 12.00,
        'pcb_bare' => 8.00, 'pcba_assembly' => 35.00,
        'mechanical_part' => 3.00, 'machined_part' => 15.00,
    ];

    /**
     * Simple heuristic fallback when ONNX is not available.
     *
     * @param array{mpn: string, manufacturer: string, description: string, quantity: int, supplier: string, source: string, industry: string, moq: int, lead_time_days: float, reliability_score: float, complexity_factor: float} $data
     * @return array{price: float, confidence: float, method: string, factors: array<string, mixed>}
     */
    private /**
 * @param array<string|int, mixed> $data
 */
function imputeWithHeuristic(array $data): array
    {
        $category = $this->detectCategoryHeuristic($data);
        $basePrice = self::HEURISTIC_BASE_PRICES[$category] ?? 0.15;

        $quantity = max(1, $data['quantity']);
        $volumeDiscount = 1.0;
        if ($quantity >= 10000) {
            $volumeDiscount = 0.45;
        } elseif ($quantity >= 1000) {
            $volumeDiscount = 0.62;
        } elseif ($quantity >= 100) {
            $volumeDiscount = 0.80;
        }

        $price = max(0.0001, round($basePrice * $volumeDiscount, 6));

        $this->logger->info('Heuristic price imputation (ONNX unavailable)', [
            'mpn' => $data['mpn'],
            'imputed_price' => $price,
            'category' => $category,
        ]);

        return [
            'price' => $price,
            'confidence' => 0.40,
            'method' => 'heuristic_fallback',
            'factors' => [
                'category' => $category,
                'base_price' => $basePrice,
                'volume_discount' => $volumeDiscount,
                'quantity' => $quantity,
                'note' => 'ONNX model unavailable, using heuristic baseline',
            ],
        ];
    }

    /**
     * Simplified category detection for heuristic fallback (no encoder dependency).
     *
     * @param array{mpn: string, manufacturer: string, description: string, quantity: int, supplier: string, source: string, industry: string, moq: int, lead_time_days: float, reliability_score: float, complexity_factor: float} $data
     */
    private /**
 * @param array<string|int, mixed> $data
 */
function detectCategoryHeuristic(array $data): string
    {
        $combined = strtolower($data['mpn'] . ' ' . $data['description']);

        $simplePatterns = [
            'ic_microcontroller' => '/\b(mcu|microcontroller|stm32|atmega|pic\d|esp32)\b/',
            'ic_memory' => '/\b(memory|flash|eeprom|sram|dram|sdram)\b/',
            'ic_power' => '/\b(regulator|ldo|buck|boost|pmic)\b/',
            'ic_analog' => '/\b(adc|dac|op.amp|amplifier|comparator)\b/',
            'ic_logic' => '/\b(logic|gate|buffer|74hc|74ls|sn74)\b/',
            'resistor' => '/\b(resistor|res|ohm)\b/',
            'capacitor' => '/\b(capacitor|cap|ceramic|mlcc)\b/',
            'inductor' => '/\b(inductor|choke|coil|ferrite)\b/',
            'diode' => '/\b(diode|zener|schottky|tvs)\b/',
            'mosfet' => '/\b(mosfet)\b/',
            'transistor' => '/\b(transistor|bjt)\b/',
            'led' => '/\b(led|light.emitting)\b/',
            'connector' => '/\b(connector|header|socket|terminal)\b/',
            'relay' => '/\b(relay)\b/',
            'sensor' => '/\b(sensor|accelerometer|gyro|temp)\b/',
            'crystal_oscillator' => '/\b(crystal|oscillator|xtal)\b/',
            // Additional categories for mechanical/assembly parts
            'wire_harness' => '/\b(wire.harness|cable.harness|wiring|harness)\b/',
            'cable_assembly' => '/\b(cable.assembly|cable|ribbon.cable|coaxial)\b/',
            'pcb_bare' => '/\b(pcb|pc.bare|printed.circuit|bare.board)\b/',
            'pcba_assembly' => '/\b(pcba|pcb.assembly|smt.assembly|board.assembly)\b/',
            'mechanical_part' => '/\b(mechanical|bracket|mount|enclosure|housing|chassis)\b/',
            'machined_part' => '/\b(machined|cnc|lathe|milled|turned)\b/',
        ];

        foreach ($simplePatterns as $cat => $pattern) {
            if (preg_match($pattern, $combined)) {
                return $cat;
            }
        }

        return 'resistor'; // Safe default
    }

    // =========================================================================
    // INPUT NORMALIZATION
    // =========================================================================

    /**
     * Normalize any accepted input into the full feature set used by the
     * inference and heuristic paths. Missing optional features fall back to
     * the same defaults the consumers previously applied via `??`.
     *
     * @param BomLine|array<string, mixed> $component
     * @return array{mpn: string, manufacturer: string, description: string, quantity: int, supplier: string, source: string, industry: string, moq: int, lead_time_days: float, reliability_score: float, complexity_factor: float}
     */
    private function normalizeInput(BomLine|array $component): array
    {
        if ($component instanceof BomLine) {
            // Fix C2: BomLine entity has NO getPackage() method (only getMpn, getManufacturer,
            // getDescription, getQuantity). Remove the package field since neither BomLine
            // nor the array path necessarily carries it.
            return [
                'mpn' => $component->getMpn() ?? '',
                'manufacturer' => $component->getManufacturer() ?? '',
                'description' => $component->getDescription() ?? '',
                'quantity' => $component->getQuantity() ?? 1,
                'supplier' => '',
                'source' => '',
                'industry' => 'ems_contract',
                'moq' => 1,
                'lead_time_days' => 7.0,
                'reliability_score' => 0.9,
                'complexity_factor' => 1.0,
            ];
        }

        return [
            'mpn' => self::toString($component['mpn'] ?? null, ''),
            'manufacturer' => self::toString($component['manufacturer'] ?? null, ''),
            'description' => self::toString($component['description'] ?? null, ''),
            'quantity' => self::toInt($component['quantity'] ?? null, 1),
            'supplier' => self::toString($component['supplier'] ?? null, ''),
            'source' => self::toString($component['source'] ?? null, ''),
            'industry' => self::toString($component['industry'] ?? null, 'ems_contract'),
            'moq' => self::toInt($component['moq'] ?? null, 1),
            'lead_time_days' => self::toFloat($component['lead_time_days'] ?? null, 7.0),
            'reliability_score' => self::toFloat($component['reliability_score'] ?? null, 0.9),
            'complexity_factor' => self::toFloat($component['complexity_factor'] ?? null, 1.0),
        ];
    }

    /** Coerce a raw (possibly non-scalar) array value to string, falling back to the default. */
    private static function toString(mixed $value, string $default): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    /** Coerce a raw (possibly non-scalar) array value to int, falling back to the default. */
    private static function toInt(mixed $value, int $default): int
    {
        return is_scalar($value) ? (int) $value : $default;
    }

    /** Coerce a raw (possibly non-scalar) array value to float, falling back to the default. */
    private static function toFloat(mixed $value, float $default): float
    {
        return is_scalar($value) ? (float) $value : $default;
    }
}
