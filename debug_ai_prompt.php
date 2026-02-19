<?php

use App\Services\AiAnalysisService;

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$service = new AiAnalysisService();

$data = [
    'total_income' => 10000000,
    'total_expense' => 0,
    'net_income' => 10000000,
    'top_categories' => [],
];

$periodLabel = '1 Feb 2026 - 28 Feb 2026';

echo "Simulating Analysis for Income-Only Data...\n";
// Not actually calling API to save quota/avoid 429
// But we can check buildPrompt
$reflection = new ReflectionClass($service);
$method = $reflection->getMethod('buildPrompt');
$method->setAccessible(true);
$prompt = $method->invoke($service, $data, $periodLabel);

echo "Generated Prompt:\n" . $prompt . "\n";