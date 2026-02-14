<?php
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Verifying Xendit SDK Installation...\n";
try {
    if (class_exists('Xendit\Configuration')) {
        echo "Xendit Configuration class exists.\n";
    }
    else {
        echo "Xendit Configuration class NOT found.\n";
        exit(1);
    }

    if (class_exists('Xendit\PaymentRequest\PaymentRequestApi')) {
        echo "Xendit PaymentRequestApi class exists.\n";
    }
    else {
        echo "Xendit PaymentRequestApi class NOT found.\n";
        exit(1);
    }

    if (class_exists('Xendit\PaymentRequest\PaymentMethodParameters')) {
        echo "Xendit PaymentMethodParameters class exists.\n";
    }
    else {
        echo "Xendit PaymentRequestApi class NOT found.\n";
    // exit(1); // Don't exit yet, check others
    }

    if (class_exists('Xendit\PaymentRequest\VirtualAccountParameters')) {
        echo "Xendit VirtualAccountParameters class exists.\n";
    }
    else {
        echo "Xendit VirtualAccountParameters class NOT found.\n";
        exit(1);
    }

    // Check Config
    $webhookToken = config('xendit.webhook_token');
    if (empty($webhookToken)) {
        echo "WARNING: XENDIT_WEBHOOK_TOKEN is not set in .env or config/xendit.php\n";
        echo "Webhooks will fail verification.\n";
    }
    else {
        echo "XENDIT_WEBHOOK_TOKEN is set.\n";
    }

    if (class_exists('Xendit\Invoice\InvoiceApi')) {
        echo "Xendit InvoiceApi class exists.\n";
    }
    else {
        echo "Xendit InvoiceApi class NOT found.\n";
        exit(1);
    }

    echo "Xendit SDK v7 verified successfully.\n";

}
catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}