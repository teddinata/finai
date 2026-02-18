<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\UsageLog;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class TransactionController extends Controller
{
    /**
     * Get all transactions
     */
    public function index(Request $request)
    {
        $household = $request->user()->household;

        $query = Transaction::forHousehold($household->id)
                           ->with(['category', 'creator', 'items']);

        // Filters
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->has('source')) {
            $query->where('source', $request->source);
        }

        if ($request->has('metode_pembayaran')) {
            $query->where('metode_pembayaran', $request->metode_pembayaran);
        }

        if ($request->has('start_date')) {
            $query->where('tanggal', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->where('tanggal', '<=', $request->end_date);
        }

        if ($request->has('month') && $request->has('year')) {
            $query->forMonth($request->year, $request->month);
        }

         // Add parent category filter
        if ($request->filled('parent_category')) {
            $query->whereHas('category', function($q) use ($request) {
                $q->where('parent_category_slug', $request->parent_category);
            });
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('merchant', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        // Sort
        $sortBy = $request->get('sort_by', 'tanggal');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $transactions = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'transactions' => $transactions->through(function ($transaction) {
                return $this->formatTransactionResponse($transaction);
            }),
        ]);
    }

    /**
     * Get single transaction
     */
    public function show(Request $request, Transaction $transaction)
    {
        // Check authorization
        if ($transaction->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $transaction->load(['category', 'creator', 'items']);

        return response()->json([
            'transaction' => $this->formatTransactionResponse($transaction),
        ]);
    }

    /**
     * Create transaction manually
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:income,expense',
            'merchant' => 'required|string|max:255',
            'tanggal' => 'required|date',
            'category_id' => 'required|exists:categories,id',
            'account_id' => 'required|exists:accounts,id',  // ✅ REQUIRED
            'total' => 'required|integer|min:1',
            'notes' => 'nullable|string',
            'source' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            $household = $request->user()->household;

            // Verify account belongs to household
            $account = Account::where('id', $validated['account_id'])
                            ->where('household_id', $household->id)
                            ->first();

            if (!$account) {
                return response()->json([
                    'message' => 'Account not found'
                ], 404);
            }

            // Create transaction
            $transaction = Transaction::create([
                'household_id' => $household->id,
                'created_by' => $request->user()->id,
                'type' => $validated['type'],
                'merchant' => $validated['merchant'],
                'tanggal' => $validated['tanggal'],
                'category_id' => $validated['category_id'],
                'account_id' => $validated['account_id'],  // ✅ SAVE account_id
                'subtotal' => $validated['total'],
                'diskon' => 0,
                'total' => $validated['total'],
                'source' => $validated['source'] ?? 'manual',
                'notes' => $validated['notes'],
            ]);

            // ✅ Update account balance - Handled by Transaction Observer
            // if ($validated['type'] === 'income') { ... }

            DB::commit();

            // ✅ Load account relationship
            $transaction->load(['category', 'creator', 'items', 'account']);

            return response()->json([
                'message' => 'Transaction created successfully',
                'transaction' => $this->formatTransactionResponse($transaction)
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create transaction',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Scan receipt and create transaction
     */
    // public function scan(Request $request)
    // {
    //     $household = $request->user()->household;

    //     // Check AI scan limit
    //     $limit = $household->getFeatureLimit('max_ai_scans_per_month');
    //     if (UsageLog::hasReachedLimit($household->id, 'ai_scan', $limit)) {
    //         return response()->json([
    //             'message' => 'Monthly AI scan limit reached',
    //             'limit' => $limit,
    //         ], 429);
    //     }

    //     // Check transaction limit
    //     $transactionLimit = $household->getFeatureLimit('max_transactions_per_month');
    //     if (UsageLog::hasReachedLimit($household->id, 'transaction', $transactionLimit)) {
    //         return response()->json([
    //             'message' => 'Monthly transaction limit reached',
    //             'limit' => $transactionLimit,
    //         ], 429);
    //     }

    //     $validated = $request->validate([
    //         'image' => 'required|image|mimes:jpg,jpeg,png|max:5120', // 5MB max
    //     ]);

    //     DB::beginTransaction();
    //     try {
    //         // Store image
    //         $imagePath = $request->file('image')->store('receipts', 'public');

    //         // Call Gemini AI to extract data
    //         $extractedData = $this->extractReceiptData($imagePath);

    //         if (!$extractedData) {
    //             throw new \Exception('Failed to extract data from receipt');
    //         }

    //         // Create transaction
    //         $transaction = Transaction::create([
    //             'household_id' => $household->id,
    //             'created_by' => $request->user()->id,
    //             'category_id' => $this->detectCategory($extractedData['merchant'] ?? null),
    //             'merchant' => $extractedData['merchant'] ?? 'Unknown',
    //             'tanggal' => $extractedData['tanggal'] ?? now()->toDateString(),
    //             'subtotal' => $extractedData['subtotal'] ?? $extractedData['total'],
    //             'diskon' => $extractedData['diskon'] ?? 0,
    //             'total' => $extractedData['total'],
    //             'metode_pembayaran' => $extractedData['metode_pembayaran'] ?? 'cash',
    //             'source' => 'scan',
    //             'receipt_image' => $imagePath,
    //         ]);

    //         // Create items if available
    //         if (isset($extractedData['items']) && is_array($extractedData['items'])) {
    //             foreach ($extractedData['items'] as $item) {
    //                 TransactionItem::create([
    //                     'transaction_id' => $transaction->id,
    //                     'nama' => $item['nama'],
    //                     'qty' => $item['qty'] ?? 1,
    //                     'harga_satuan' => $item['harga_satuan'] ?? 0,
    //                 ]);
    //             }
    //         }

    //         // Log usage
    //         UsageLog::logUsage($household->id, 'ai_scan', 1, $request->user()->id);
    //         UsageLog::logUsage($household->id, 'transaction', 1, $request->user()->id);

    //         DB::commit();

    //         return response()->json([
    //             'message' => 'Receipt scanned successfully',
    //             'transaction' => $this->formatTransactionResponse($transaction->load(['category', 'items'])),
    //             'extracted_data' => $extractedData,
    //         ], 201);

    //     } catch (\Exception $e) {
    //         DB::rollBack();
            
    //         // Delete uploaded image if transaction failed
    //         if (isset($imagePath)) {
    //             Storage::disk('public')->delete($imagePath);
    //         }

    //         return response()->json([
    //             'message' => 'Failed to scan receipt',
    //             'error' => $e->getMessage(),
    //         ], 500);
    //     }
    // }

    /**
     * Update transaction
     */
    public function update(Request $request, Transaction $transaction)
    {
        // Check authorization
        if ($transaction->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'merchant' => 'sometimes|string|max:255',
            'tanggal' => 'sometimes|date',
            'category_id' => 'nullable|exists:categories,id',
            'total' => 'sometimes|integer|min:0',
            'subtotal' => 'nullable|integer|min:0',
            'diskon' => 'nullable|integer|min:0',
            'metode_pembayaran' => 'sometimes|in:cash,transfer,kartu_kredit,kartu_debit,ewallet,other',
            'notes' => 'nullable|string|max:1000',
            'items' => 'nullable|array',
            'items.*.id' => 'nullable|exists:transaction_items,id',
            'items.*.nama' => 'required|string|max:255',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.harga_satuan' => 'required|integer|min:0',
        ]);

        DB::beginTransaction();
        try {
            $transaction->update($validated);

            // Update items if provided
            if (isset($validated['items'])) {
                // Delete items not in the update
                $itemIds = collect($validated['items'])->pluck('id')->filter();
                $transaction->items()->whereNotIn('id', $itemIds)->delete();

                foreach ($validated['items'] as $itemData) {
                    if (isset($itemData['id'])) {
                        // Update existing item
                        TransactionItem::where('id', $itemData['id'])->update([
                            'nama' => $itemData['nama'],
                            'qty' => $itemData['qty'],
                            'harga_satuan' => $itemData['harga_satuan'],
                        ]);
                    } else {
                        // Create new item
                        TransactionItem::create([
                            'transaction_id' => $transaction->id,
                            'nama' => $itemData['nama'],
                            'qty' => $itemData['qty'],
                            'harga_satuan' => $itemData['harga_satuan'],
                        ]);
                    }
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Transaction updated successfully',
                'transaction' => $this->formatTransactionResponse($transaction->fresh()->load(['category', 'items'])),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update transaction',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete transaction
     */
    public function destroy(Request $request, Transaction $transaction)
    {
        // Check authorization
        if ($transaction->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $transaction->delete();

        return response()->json([
            'message' => 'Transaction deleted successfully',
        ]);
    }

    /**
     * Scan receipt and create transaction
     */
    public function scan(Request $request)
    {
        $household = $request->user()->household;

        // 1. Cek Limit Feature
        $limit = $household->getFeatureLimit('max_ai_scans_per_month');
        if (UsageLog::hasReachedLimit($household->id, 'ai_scan', $limit)) {
            return response()->json([
                'message' => 'Monthly AI scan limit reached',
                'limit' => $limit,
            ], 429);
        }

        // 2. Validate Image
        $request->validate([
            'image' => 'required|image|mimes:jpg,jpeg,png|max:5120', // 5MB max
        ]);

        DB::beginTransaction();
        try {
            // 3. Store Image
            $imagePath = $request->file('image')->store('receipts', 'public');

            // 4. Call Gemini AI (Sekarang sudah return Cents)
            $extractedData = $this->extractReceiptData($imagePath);

            if (!$extractedData || isset($extractedData['error'])) {
                throw new \Exception($extractedData['error'] ?? 'Gagal membaca struk');
            }

            // 5. Create Transaction
            $transaction = Transaction::create([
                'household_id' => $household->id,
                'created_by' => $request->user()->id,
                'type' => 'expense',
                'category_id' => $this->detectCategory($extractedData['merchant'] ?? null),
                'merchant' => $extractedData['merchant'] ?? 'Unknown Merchant',
                'tanggal' => $extractedData['tanggal'] ?? now()->toDateString(),
                
                // Data ini sudah dalam CENTS (dari fungsi extractReceiptData)
                'subtotal' => $extractedData['subtotal'], 
                'diskon' => $extractedData['diskon'],
                'total' => $extractedData['total'],
                
                'metode_pembayaran' => $this->normalizePaymentMethod($extractedData['metode_pembayaran'] ?? 'cash'),
                'source' => 'scan',
                'receipt_image' => $imagePath,
                'notes' => 'Auto-scanned via Gemini AI',
            ]);

            // 6. Create Items
            if (!empty($extractedData['items']) && is_array($extractedData['items'])) {
                foreach ($extractedData['items'] as $item) {
                    TransactionItem::create([
                        'transaction_id' => $transaction->id,
                        'nama' => $item['nama'],
                        'qty' => $item['qty'],
                        // Ini juga sudah CENTS
                        'harga_satuan' => $item['harga_satuan'], 
                        'harga_total' => $item['harga_total'],
                    ]);
                }
            }

            // 7. Log Usage
            UsageLog::logUsage($household->id, 'ai_scan', 1, $request->user()->id);
            UsageLog::logUsage($household->id, 'transaction', 1, $request->user()->id);

            DB::commit();

            return response()->json([
                'message' => 'Receipt scanned successfully',
                'transaction' => $this->formatTransactionResponse($transaction->load(['category', 'items'])),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            if (isset($imagePath)) {
                Storage::disk('public')->delete($imagePath);
            }

            return response()->json([
                'message' => 'Failed to scan receipt',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Chat with AI to create transaction
     */
    public function chat(Request $request) 
    {
        $household = $request->user()->household;

        // 1. Cek Limit Feature
        // $limit = $household->getFeatureLimit('max_ai_chats_per_month'); // Implement limits later if needed
        
        $request->validate([
            'message' => 'required|string|max:1000',
        ]);

        DB::beginTransaction();
        try {
            // 2. Get Accounts for context
            $accounts = Account::forHousehold($household->id)
                ->active()
                ->get(['id', 'name', 'type']);

            // 3. Call Gemini AI
            $extractedData = $this->extractChatData($request->message, $accounts);
            


            if (!$extractedData || isset($extractedData['error'])) {
                throw new \Exception($extractedData['error'] ?? 'Gagal memproses pesan');
            }

            // 4. Create Transaction
            $transaction = Transaction::create([
                'household_id' => $household->id,
                'created_by' => $request->user()->id,
                'type' => 'expense', // Default, AI might override if income detected
                'category_id' => $extractedData['category_id'] ?? $this->detectCategory($extractedData['merchant'] ?? null),
                'account_id' => $extractedData['account_id'] ?? null,
                'merchant' => $extractedData['merchant'] ?? 'Unknown',
                'tanggal' => $extractedData['tanggal'] ?? now()->toDateString(),
                'subtotal' => $extractedData['subtotal'],
                'diskon' => $extractedData['diskon'],
                'total' => $extractedData['total'],
                'metode_pembayaran' => 'cash', // Default if account not linked to method
                'source' => 'chat',
                'notes' => $extractedData['notes'] ?? 'Auto-generated via Chat',
            ]);
            

            
            // Override type if AI detected income
            if (isset($extractedData['type']) && in_array($extractedData['type'], ['income', 'expense'])) {
                $transaction->type = $extractedData['type'];
                $transaction->save();
            }

            // 5. Create Items
            if (!empty($extractedData['items']) && is_array($extractedData['items'])) {
                foreach ($extractedData['items'] as $item) {
                    TransactionItem::create([
                        'transaction_id' => $transaction->id,
                        'nama' => $item['nama'],
                        'qty' => $item['qty'],
                        'harga_satuan' => $item['harga_satuan'],
                        'harga_total' => $item['harga_total'],
                    ]);
                }
            }

            // 6. Update Account Balance - Handled by Transaction Observer
            // if ($transaction->account_id) { ... }

            // 7. Log Usage
            UsageLog::logUsage($household->id, 'transaction', 1, $request->user()->id);

            DB::commit();

            return response()->json([
                'message' => 'Transaksi berhasil dicatat',
                'transaction' => $this->formatTransactionResponse($transaction->load(['category', 'items', 'account'])),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Gagal memproses chat',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Extract receipt data using Gemini AI
     * Returns amounts in CENTS (IDR)
     */
    private function extractReceiptData(string $imagePath): ?array
    {
        $fullPath = storage_path('app/public/' . $imagePath);
        if (!file_exists($fullPath)) {
            return ['error' => "File not found at: $fullPath"];
        }
        
        $imageContent = base64_encode(file_get_contents($fullPath));

        $prompt = "Analyze this receipt image. Extract transaction details.
        
        Rules:
        1. Return ONLY valid JSON.
        2. 'tanggal' format: YYYY-MM-DD.
        3. 'metode_pembayaran' one of: cash, transfer, kartu_kredit, kartu_debit, ewallet.
        4. Prices must be RAW INTEGERS (IDR). Example: if 15.000, return 15000. DO NOT include decimals or cents yet.
        
        JSON Schema:
        {
            \"merchant\": \"Store Name\",
            \"tanggal\": \"2024-01-30\",
            \"items\": [
                {\"nama\": \"Item Name\", \"qty\": 1, \"harga_satuan\": 10000}
            ],
            \"subtotal\": 10000,
            \"diskon\": 0,
            \"total\": 10000,
            \"metode_pembayaran\": \"cash\"
        }";

        return $this->sendGeminiRequest($prompt, $imageContent);
    }

    /**
     * Extract chat data using Gemini AI
     */
    /**
     * Extract chat data using Gemini AI
     */
    private function extractChatData(string $message, $accounts): ?array
    {
        $accountContext = $accounts->map(function($acc) {
            return "- ID: {$acc->id}, Name: {$acc->name} ({$acc->type})";
        })->implode("\n");

        // Fetch Categories
        $categories = \App\Models\Category::where('household_id', auth()->user()->household_id)
            ->orWhereNull('household_id')
            ->get(['id', 'name', 'type']);

        $categoryContext = $categories->map(function($cat) {
            return "- ID: {$cat->id}, Name: {$cat->name} ({$cat->type})";
        })->implode("\n");

        $prompt = "Analyze this transaction chat message. Extract transaction details.
        
        Current Context:
        Today is " . now()->toDateString() . ".
        
        Available Accounts:
        {$accountContext}

        Available Categories:
        {$categoryContext}

        Rules:
        1. Parse the user's message to find transaction details.
        2. 'account_id': Match account mentioned.
        3. 'type': 
           - Detect if it's EXPENSE (spending) or INCOME (receiving). 
           - 'Gaji', 'Bonus', 'Terima uang' -> INCOME.
           - 'Beli', 'Bayar', 'Jajan' -> EXPENSE.
        4. 'category_id': MUST match item/merchant to one of Available Categories BY ID.
           - IF 'type' is INCOME, look ONLY at INCOME categories (e.g. 'Gaji', 'Bonus').
           - IF 'type' is EXPENSE, look ONLY at EXPENSE categories.
           - Examples: 
             - 'Token Listrik'/'Listrik' -> 'Tagihan & Utilitas'
             - 'Gaji' -> 'Gaji'
             - 'Bensin' -> 'Transportasi'
             - 'Makan' -> 'Makanan & Minuman'
        5. 'merchant': Store/Person name. 
           - INFER generic name if specific name missing (e.g. 'listrik' -> 'PLN', 'gaji' -> 'Kantor/Perusahaan').
           - If truly unknown, return '-'.
        6. Prices are RAW INTEGERS (IDR).
        7. Return ONLY valid JSON.

        JSON Schema:
        {
            \"merchant\": \"Store Name or Generic Name or '-'\",
            \"items\": [
                {\"nama\": \"Item Name\", \"qty\": 1, \"harga_satuan\": 10000}
            ],
            \"subtotal\": 10000,
            \"diskon\": 0,
            \"total\": 10000,
            \"account_id\": 123,
            \"category_id\": 456,
            \"type\": \"expense\" OR \"income\",
            \"notes\": \"Original message or summary\"
        }

        Message: \"{$message}\"
        ";

        return $this->sendGeminiRequest($prompt);
    }

    /**
     * Send request to Gemini API
     */
    private function sendGeminiRequest(string $prompt, ?string $imageBase64 = null): array
    {
        try {
            $model = 'gemini-2.5-flash';
            $apiKey = config('services.gemini.api_key');
            
            Log::info("Gemini Request Debug", [
                'has_key' => !empty($apiKey),
                'key_start' => substr($apiKey, 0, 4),
                'config_value' => config('services.gemini.api_key'), // Careful with logs usually but for debug
            ]);

            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

            $parts = [['text' => $prompt]];
            if ($imageBase64) {
                $parts[] = ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => $imageBase64]];
            }

            $client = \Illuminate\Support\Facades\Http::withHeaders([
                'Content-Type' => 'application/json',
            ]);

            $response = $client->post($url, [
                'contents' => [
                    ['parts' => $parts]
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => 4000,
                    'response_mime_type' => 'application/json',
                ]
            ]);

            if ($response->status() === 429) {
                return ['error' => 'Limit penggunaan AI tercapai (Gemini Quota). Silakan tunggu sebentar sebelum mencoba lagi.'];
            }

            if ($response->failed()) {
                throw new \Exception('Gemini API Error: ' . $response->body());
            }

            $result = $response->json();
            $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;

            if (!$text) return ['error' => 'Empty AI Response'];

            $cleanJson = str_replace(['```json', '```'], '', $text);
            $data = json_decode($cleanJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                \Log::error('JSON Parse Error', ['text' => $text]);
                return ['error' => 'Invalid JSON Format from AI'];
            }

            // Calculation and formatting
            $total = (int) ($data['total'] ?? 0);
            $subtotal = (int) ($data['subtotal'] ?? $total);
            $diskon = (int) ($data['diskon'] ?? 0);

            $data['total'] = $total;
            $data['subtotal'] = $subtotal;
            $data['diskon'] = $diskon;

            if (!empty($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as &$item) {
                    $qty = (int) ($item['qty'] ?? 1);
                    $hargaSatuan = (int) ($item['harga_satuan'] ?? 0);
                    
                    $item['harga_satuan'] = $hargaSatuan;
                    $item['harga_total'] = ($hargaSatuan * $qty);
                }
            } else {
                $data['items'] = [];
            }

            return $data;

        } catch (\Exception $e) {
            \Log::error("Gemini API Error: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Helper to normalize payment method
     */
    private function normalizePaymentMethod($method): string
    {
        $valid = ['cash', 'transfer', 'kartu_kredit', 'kartu_debit', 'ewallet', 'other'];
        $slug = strtolower(str_replace(' ', '_', $method));
        return in_array($slug, $valid) ? $slug : 'cash';
    }

    /**
     * Detect category from merchant name
     */
    private function detectCategory(?string $merchant): ?int
    {
        if (!$merchant) return null;

        $merchant = strtolower($merchant);

        $categoryKeywords = [
            'Makanan & Minuman' => ['indomaret', 'alfamart', 'mcd', 'kfc', 'starbucks', 'restaurant', 'cafe', 'warung', 'resto'],
            'Transportasi' => ['grab', 'gojek', 'blue bird', 'taxi', 'trans', 'spbu', 'pertamina', 'shell'],
            'Kesehatan' => ['apotek', 'kimia farma', 'guardian', 'rumah sakit', 'klinik', 'hospital'],
            'Belanja Kebutuhan' => ['hypermart', 'carrefour', 'giant', 'supermarket', 'minimarket'],
        ];

        foreach ($categoryKeywords as $categoryName => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($merchant, $keyword)) {
                    $category = \App\Models\Category::where('name', $categoryName)
                                                    ->where('is_default', true)
                                                    ->first();
                    return $category?->id;
                }
            }
        }

        return null;
    }

    /**
     * Format transaction response
     */
    private function formatTransactionResponse(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'type' => $transaction->type,
            'merchant' => $transaction->merchant,
            'tanggal' => $transaction->tanggal->format('Y-m-d'),
            'subtotal' => $transaction->subtotal,
            'diskon' => $transaction->diskon,
            'total' => $transaction->total,
            'formatted_total' => $transaction->getFormattedTotal(),
            'metode_pembayaran' => $transaction->metode_pembayaran,
            'source' => $transaction->source,
            'notes' => $transaction->notes,
            'receipt_image' => $transaction->getReceiptUrl(),
            'category' => $transaction->category ? [
                'id' => $transaction->category->id,
                'name' => $transaction->category->name,
                'icon' => $transaction->category->icon,
                'color' => $transaction->category->color,
            ] : null,
            'account' => $transaction->account ? [  // ✅ INCLUDE account
                'id' => $transaction->account->id,
                'name' => $transaction->account->name,
                'icon' => $transaction->account->icon,
                'color' => $transaction->account->color,
            ] : null,
            'creator' => [
                'id' => $transaction->creator->id,
                'name' => $transaction->creator->name,
            ],
            'items' => $transaction->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'nama' => $item->nama,
                    'qty' => $item->qty,
                    'harga_satuan' => $item->harga_satuan,
                    'harga_total' => $item->harga_total,
                    'formatted_harga' => $item->getFormattedHargaTotal(),
                ];
            }),
            'created_at' => $transaction->created_at,
            'updated_at' => $transaction->updated_at,
        ];
    }
}
 