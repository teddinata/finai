<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    /**
     * Get all invoices
     */
    public function index(Request $request)
    {
        $household = $request->user()->household;

        $invoices = Invoice::where('household_id', $household->id)
            ->with(['payment', 'subscription.plan'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'invoices' => $invoices->through(function ($invoice) {
                return $this->formatInvoiceResponse($invoice);
            }),
        ]);
    }

    /**
     * Get single invoice
     */
    public function show(Request $request, Invoice $invoice)
    {
        if ($invoice->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $invoice->load(['payment', 'subscription.plan']);

        return response()->json([
            'invoice' => $this->formatInvoiceResponse($invoice),
        ]);
    }

    /**
     * Download invoice PDF
     */
    public function download(Request $request, Invoice $invoice)
    {
        if ($invoice->household_id !== $request->user()->household_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // TODO: Implement PDF generation
        return response()->json([
            'message' => 'PDF generation not implemented yet',
        ], 501);
    }

    /**
     * Format invoice response
     */
    private function formatInvoiceResponse(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'amount' => $invoice->amount,
            'tax' => $invoice->tax,
            'total' => $invoice->total,
            'formatted_total' => $invoice->getFormattedTotal(),
            'currency' => $invoice->currency,
            'status' => $invoice->status,
            'description' => $invoice->description,
            'line_items' => $invoice->line_items,
            'issued_at' => $invoice->issued_at,
            'due_at' => $invoice->due_at,
            'paid_at' => $invoice->paid_at,
            'subscription' => $invoice->subscription ? [
                'plan_name' => $invoice->subscription->plan->name,
            ] : null,
            'created_at' => $invoice->created_at,
        ];
    }
}