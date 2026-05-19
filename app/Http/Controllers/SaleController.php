<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\{Sale, SaleItem, Product, InventoryLog, JournalEntry, JournalLine, Account};
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SaleController extends Controller
{
    public function pos()
    {
        $products = Product::where('stock', '>', 0)->orderBy('name')->get();
        return view('sales.pos', compact('products'));
    }

    public function checkout(Request $request)
    {
        $request->validate([
            'items'          => 'required|array|min:1',
            'items.*.id'     => 'required|exists:products,id',
            'items.*.qty'    => 'required|integer|min:1',
            'payment_method' => 'required|in:cash,dana,qris,transfer',
            'cash_received'  => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $total = 0;
            $itemsData = [];

            // Validate stock & build cart
            foreach ($request->items as $item) {
                $product = Product::findOrFail($item['id']);
                if ($product->stock < $item['qty']) {
                    return response()->json([
                        'success' => false,
                        'message' => "Stok {$product->name} tidak cukup. Tersedia: {$product->stock}"
                    ], 422);
                }
                $subtotal = $product->selling_price * $item['qty'];
                $total += $subtotal;
                $itemsData[] = [
                    'product'  => $product,
                    'qty'      => $item['qty'],
                    'subtotal' => $subtotal,
                ];
            }

            $cashReceived  = $request->cash_received ?? $total;
            $changeAmount  = max(0, $cashReceived - $total);
            $invoiceNumber = Sale::generateInvoiceNumber();

            // Create sale
            $sale = Sale::create([
                'invoice_number' => $invoiceNumber,
                'user_id'        => session('biztrack_user'),
                'total_amount'   => $total,
                'payment_method' => $request->payment_method,
                'cash_received'  => $cashReceived,
                'change_amount'  => $changeAmount,
            ]);

            // Create sale items & deduct stock
            foreach ($itemsData as $itemData) {
                $product = $itemData['product'];
                $qty     = $itemData['qty'];

                SaleItem::create([
                    'sale_id'    => $sale->id,
                    'product_id' => $product->id,
                    'quantity'   => $qty,
                    'unit_price' => $product->selling_price,
                    'cost_price' => $product->cost_price,
                    'subtotal'   => $itemData['subtotal'],
                ]);

                // Reduce stock
                $stockBefore = $product->stock;
                $product->decrement('stock', $qty);
                $stockAfter = $product->fresh()->stock;

                // Log inventory movement
                InventoryLog::create([
                    'product_id'   => $product->id,
                    'type'         => 'sale',
                    'quantity'     => $qty,
                    'stock_before' => $stockBefore,
                    'stock_after'  => $stockAfter,
                    'reference'    => $invoiceNumber,
                    'notes'        => "Penjualan - {$invoiceNumber}",
                ]);
            }

            // AIS: Create journal entry automatically
            $this->createSaleJournal($sale);

            DB::commit();

            return response()->json([
                'success'        => true,
                'sale_id'        => $sale->id,
                'invoice_number' => $invoiceNumber,
                'total'          => $total,
                'change'         => $changeAmount,
                'message'        => 'Transaksi berhasil!'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan: ' . $e->getMessage()], 500);
        }
    }

    /**
     * AIS Core: Automatically create journal entries for every sale
     * Cash   -> Debit: Kas (1100)
     * Dana   -> Debit: Dana Wallet (1110)
     * QRIS   -> Debit: Bank (1120)
     * Transfer -> Debit: Bank (1120)
     * All    -> Credit: Sales Revenue (4100)
     */
    private function createSaleJournal(Sale $sale): void
    {
        $debitAccountCode = match($sale->payment_method) {
            'cash'     => '1100',
            'dana'     => '1110',
            'qris'     => '1120',
            'transfer' => '1120',
            default    => '1100',
        };

        $debitAccount  = Account::where('code', $debitAccountCode)->first();
        $creditAccount = Account::where('code', '4100')->first();

        if (!$debitAccount || !$creditAccount) return;

        $paymentLabel = match($sale->payment_method) {
            'cash'     => 'Tunai',
            'dana'     => 'Dana',
            'qris'     => 'QRIS',
            'transfer' => 'Transfer Bank',
            default    => 'Tunai',
        };

        $journal = JournalEntry::create([
            'reference'   => $sale->invoice_number,
            'description' => "Penjualan {$paymentLabel} - {$sale->invoice_number}",
            'entry_date'  => now()->toDateString(),
            'sale_id'     => $sale->id,
            'created_by'  => session('biztrack_user'),
        ]);

        // Debit: payment account
        JournalLine::create([
            'journal_entry_id' => $journal->id,
            'account_id'       => $debitAccount->id,
            'debit'            => $sale->total_amount,
            'credit'           => 0,
            'description'      => "Penerimaan penjualan - {$sale->invoice_number}",
        ]);

        // Credit: sales revenue
        JournalLine::create([
            'journal_entry_id' => $journal->id,
            'account_id'       => $creditAccount->id,
            'debit'            => 0,
            'credit'           => $sale->total_amount,
            'description'      => "Pendapatan penjualan - {$sale->invoice_number}",
        ]);
    }

    public function index(Request $request)
    {
        $query = Sale::with(['items.product', 'user'])->orderBy('created_at', 'desc');

        if ($request->filled('search')) {
            $query->where('invoice_number', 'like', "%{$request->search}%");
        }

        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->date);
        }

        if ($request->filled('payment')) {
            $query->where('payment_method', $request->payment);
        }

        $sales = $query->paginate(20)->withQueryString();
        $totalFiltered = $query->sum('total_amount');

        return view('sales.index', compact('sales', 'totalFiltered'));
    }

    public function show(Sale $sale)
    {
        $sale->load(['items.product', 'user']);
        return view('sales.show', compact('sale'));
    }

    public function receipt(Sale $sale)
    {
        $sale->load(['items.product', 'user']);
        return view('sales.receipt', compact('sale'));
    }
}
