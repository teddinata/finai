<?php

use App\Models\User;
use App\Services\AiAnalysisService;
use Carbon\Carbon;

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Mock data
$data = [
    'total_income' => 15000000,
    'total_expense' => 12000000,
    'net_income' => 3000000,
    'top_categories' => [
        ['category' => 'Makanan', 'total' => 5000000],
        ['category' => 'Transportasi', 'total' => 3000000],
        ['category' => 'Hiburan', 'total' => 2000000],
    ]
];

$periodLabel = '01 Jan 2026 - 31 Jan 2026';

echo "Testing AI Analysis Service...\n\n";

try {
    $service = new AiAnalysisService();
    // We can't easily mock Http here without a full test suite, so we'll try to hit the real API if key exists
    // OR we just check if the class instantiates and method exists

    if (empty(config('services.gemini.api_key'))) {
        echo "WARNING: GEMINI_API_KEY is not set in .env or config.\n";
        echo "The service will likely return an error message.\n";
    }
    else {
        echo "GEMINI_API_KEY found.\n";
    }

    echo "Sending request to AI...\n";
    $result = $service->analyze($data, $periodLabel);

    echo "\nResponse:\n";
    echo $result . "\n";

}
catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}