<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\{Account, JournalEntry, JournalLine};

class AccountingController extends Controller
{
    public function coa()
    {
        $accounts = Account::orderBy('code')->get()->groupBy('type');
        return view('accounting.coa', compact('accounts'));
    }

    public function journal(Request $request)
    {
        $query = JournalEntry::with(['lines.account'])->orderBy('entry_date', 'desc')->orderBy('id', 'desc');

        if ($request->filled('date_from')) {
            $query->where('entry_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('entry_date', '<=', $request->date_to);
        }
        if ($request->filled('search')) {
            $query->where('reference', 'like', "%{$request->search}%")
                  ->orWhere('description', 'like', "%{$request->search}%");
        }

        $entries = $query->paginate(20)->withQueryString();
        return view('accounting.journal', compact('entries'));
    }

    public function ledger(Request $request)
    {
        $accounts = Account::orderBy('code')->get();

        $selectedAccount = null;
        $lines = collect();

        if ($request->filled('account_id')) {
            $selectedAccount = Account::find($request->account_id);
            if ($selectedAccount) {
                $query = JournalLine::with(['journalEntry'])
                    ->where('account_id', $selectedAccount->id)
                    ->whereHas('journalEntry', fn($q) => $q->orderBy('entry_date'))
                    ->orderBy('journal_entry_id');

                if ($request->filled('date_from')) {
                    $query->whereHas('journalEntry', fn($q) => $q->where('entry_date', '>=', $request->date_from));
                }
                if ($request->filled('date_to')) {
                    $query->whereHas('journalEntry', fn($q) => $q->where('entry_date', '<=', $request->date_to));
                }

                $lines = $query->get();
            }
        }

        return view('accounting.ledger', compact('accounts', 'selectedAccount', 'lines'));
    }
}
