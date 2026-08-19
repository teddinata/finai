<?php
// app/Services/NetWorthService.php

namespace App\Services;

use App\Models\Account;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\RecurringTransaction;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use App\Models\Transfer;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sumber tunggal untuk angka "posisi" (stock): saldo, kekayaan bersih,
 * dan sisa yang aman dibelanjakan.
 *
 * Semua nilai yang dikembalikan adalah integer mentah dengan satuan yang
 * sama seperti kolom di database. Formatting diserahkan ke frontend.
 */
class NetWorthService
{
    /** Tipe akun yang mewakili utang, bukan aset. */
    public const LIABILITY_TYPES = ['credit_card'];

    /** Tipe akun yang uangnya benar-benar bisa dipakai belanja hari ini. */
    public const SPENDABLE_TYPES = ['bank', 'cash', 'ewallet', 'savings', 'other'];

    /**
     * Posisi kekayaan pada satu titik waktu.
     *
     * $asOf null berarti "sekarang" dan memakai current_balance yang sudah
     * di-cache di tabel accounts. Kalau diisi tanggal, saldo dihitung ulang
     * dari riwayat transaksi sampai tanggal tersebut.
     */
    public function snapshot(int $householdId, ?string $asOf = null): array
    {
        $accounts = $this->countedAccounts($householdId);

        $balances = $asOf === null
            ? $accounts->mapWithKeys(fn (Account $a) => [$a->id => (int) $a->current_balance])->all()
            : $this->balancesAsOf($householdId, $accounts, $asOf);

        $cash = 0;
        $cardDebt = 0;

        foreach ($accounts as $account) {
            $balance = $balances[$account->id] ?? 0;

            if ($this->isLiability($account)) {
                // Kartu kredit: saldo negatif berarti terutang.
                $cardDebt += max(0, -$balance);
            } else {
                $cash += $balance;
            }
        }

        $investments = $asOf === null
            ? (int) Investment::forHousehold($householdId)->active()->sum('current_value')
            : $this->investmentBasisAsOf($householdId, $asOf);

        $loanDebt = $this->loanOutstandingAsOf($householdId, $asOf);

        $assets = $cash + $investments;
        $liabilities = $cardDebt + $loanDebt;

        return [
            'as_of' => $asOf ?? now()->toDateString(),
            'assets' => [
                'cash' => $cash,
                'investments' => $investments,
                'total' => $assets,
            ],
            'liabilities' => [
                'credit_cards' => $cardDebt,
                'loans' => $loanDebt,
                'total' => $liabilities,
            ],
            'net_worth' => $assets - $liabilities,
            // Nilai investasi masa lalu memakai harga perolehan karena tidak
            // ada riwayat harga pasar yang tersimpan.
            'investment_basis' => $asOf === null ? 'market' : 'cost',
        ];
    }

    /**
     * Deret kekayaan bersih per akhir bulan, untuk grafik pertumbuhan.
     *
     * Dihitung dari delta bulanan lalu diakumulasi, bukan dengan memanggil
     * snapshot() berulang kali, supaya jumlah query tetap konstan.
     */
    public function history(int $householdId, int $months = 12): array
    {
        $months = max(2, min(60, $months));

        $firstMonth = Carbon::now()->startOfMonth()->subMonths($months - 1);
        $dayBefore = $firstMonth->copy()->subDay()->toDateString();

        $accounts = $this->countedAccounts($householdId);
        $accountBucket = $accounts->mapWithKeys(
            fn (Account $a) => [$a->id => $this->isLiability($a) ? 'card' : 'cash']
        )->all();

        // Posisi pembuka: seluruh riwayat sebelum bulan pertama pada rentang.
        $opening = $this->balancesAsOf($householdId, $accounts, $dayBefore);
        $cash = 0;
        $cardRaw = 0;
        foreach ($accounts as $account) {
            $balance = $opening[$account->id] ?? 0;
            if ($this->isLiability($account)) {
                $cardRaw += $balance;
            } else {
                $cash += $balance;
            }
        }

        $investment = $this->investmentBasisAsOf($householdId, $dayBefore);
        $loanDebt = $this->loanOutstandingAsOf($householdId, $dayBefore);

        $rangeStart = $firstMonth->toDateString();
        $accountDeltas = $this->monthlyAccountDeltas($householdId, $accountBucket, $rangeStart);
        $investmentDeltas = $this->monthlyInvestmentDeltas($householdId, $rangeStart);
        $loanDeltas = $this->monthlyLoanDeltas($householdId, $rangeStart);

        $points = [];
        for ($i = 0; $i < $months; $i++) {
            $month = $firstMonth->copy()->addMonths($i);
            $key = $month->format('Y-m');

            $cash += $accountDeltas[$key]['cash'] ?? 0;
            $cardRaw += $accountDeltas[$key]['card'] ?? 0;
            $investment += $investmentDeltas[$key] ?? 0;
            $loanDebt = max(0, $loanDebt + ($loanDeltas[$key] ?? 0));

            $cardDebt = max(0, -$cardRaw);
            $assets = $cash + max(0, $investment);
            $liabilities = $cardDebt + $loanDebt;

            $points[] = [
                'month' => $key,
                'label' => $month->locale('id')->translatedFormat('M Y'),
                'cash' => $cash,
                'investments' => max(0, $investment),
                'liabilities' => $liabilities,
                'net_worth' => $assets - $liabilities,
            ];
        }

        // Bulan berjalan pakai nilai pasar terkini, bukan harga perolehan.
        $marketValue = (int) Investment::forHousehold($householdId)->active()->sum('current_value');
        $last = count($points) - 1;
        if ($last >= 0) {
            $points[$last]['investments'] = $marketValue;
            $points[$last]['net_worth'] = $points[$last]['cash'] + $marketValue - $points[$last]['liabilities'];
        }

        $first = $points[0]['net_worth'] ?? 0;
        $latest = $points[$last]['net_worth'] ?? 0;
        $change = $latest - $first;

        return [
            'months' => $months,
            'points' => $points,
            'change' => [
                'amount' => $change,
                // Basis negatif membuat persentase tidak bermakna, jadi dikosongkan.
                'percentage' => $first > 0 ? round($change / $first * 100, 1) : null,
            ],
            'investment_basis' => 'cost_history_market_latest',
        ];
    }

    /**
     * Berapa yang aman dibelanjakan sampai akhir bulan: kas likuid dikurangi
     * seluruh komitmen yang sudah diketahui.
     */
    public function safeToSpend(int $householdId): array
    {
        $today = Carbon::today();
        $endOfMonth = $today->copy()->endOfMonth();

        $liquid = (int) Account::forHousehold($householdId)
            ->active()
            ->includedInTotal()
            ->whereIn('type', self::SPENDABLE_TYPES)
            ->sum('current_balance');

        $upcomingBills = (int) RecurringTransaction::forHousehold($householdId)
            ->active()
            ->where('type', 'expense')
            ->whereBetween('next_occurrence', [$today->toDateString(), $endOfMonth->toDateString()])
            ->sum('amount');

        $loanDue = (int) Loan::where('household_id', $householdId)
            ->active()
            ->whereNotNull('next_payment_date')
            ->where('next_payment_date', '<=', $endOfMonth->toDateString())
            ->sum('installment_amount');

        $cardDebt = (int) Account::forHousehold($householdId)
            ->active()
            ->includedInTotal()
            ->whereIn('type', self::LIABILITY_TYPES)
            ->get()
            ->sum(fn (Account $a) => max(0, -(int) $a->current_balance));

        $goalDue = SavingsGoal::forHousehold($householdId)
            ->active()
            ->whereNotNull('deadline')
            ->where('deadline', '<=', $endOfMonth->toDateString())
            ->get()
            ->sum(fn (SavingsGoal $g) => max(0, (int) $g->target_amount - (int) $g->current_amount));

        $committed = $upcomingBills + $loanDue + $cardDebt + (int) $goalDue;
        $safe = $liquid - $committed;

        // Hari ini ikut dihitung, jadi jatah harian tidak pernah dibagi nol.
        $daysLeft = (int) $today->diffInDays($endOfMonth->copy()->startOfDay()) + 1;

        return [
            'as_of' => $today->toDateString(),
            'liquid_balance' => $liquid,
            'commitments' => [
                'upcoming_bills' => $upcomingBills,
                'loan_due' => $loanDue,
                'credit_card_debt' => $cardDebt,
                'savings_goal_due' => (int) $goalDue,
                'total' => $committed,
            ],
            'safe_to_spend' => $safe,
            'days_left' => $daysLeft,
            'daily_allowance' => $safe > 0 ? intdiv($safe, $daysLeft) : 0,
        ];
    }

    /**
     * Rincian saldo per akun, dipakai halaman Dompet.
     */
    public function accountBreakdown(int $householdId, ?string $asOf = null): array
    {
        $accounts = Account::forHousehold($householdId)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $balances = $asOf === null
            ? $accounts->mapWithKeys(fn (Account $a) => [$a->id => (int) $a->current_balance])->all()
            : $this->balancesAsOf($householdId, $accounts, $asOf);

        return $accounts->map(function (Account $account) use ($balances) {
            $balance = $balances[$account->id] ?? 0;

            return [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type,
                'type_label' => $account->getTypeLabel(),
                'institution' => $account->institution,
                'icon' => $account->icon,
                'color' => $account->color,
                'balance' => $balance,
                'is_liability' => $this->isLiability($account),
                'include_in_total' => (bool) $account->include_in_total,
            ];
        })->all();
    }

    private function isLiability(Account $account): bool
    {
        return in_array($account->type, self::LIABILITY_TYPES, true);
    }

    /** Akun aktif yang ikut dihitung ke total. */
    private function countedAccounts(int $householdId): Collection
    {
        return Account::forHousehold($householdId)
            ->active()
            ->includedInTotal()
            ->get();
    }

    /**
     * Saldo tiap akun per tanggal tertentu, dihitung dengan rumus yang sama
     * seperti Account::updateBalance() tapi dibatasi sampai $asOf.
     *
     * @param  Collection<int, Account>  $accounts
     * @return array<int, int>
     */
    private function balancesAsOf(int $householdId, Collection $accounts, string $asOf): array
    {
        $balances = $accounts->mapWithKeys(
            fn (Account $a) => [$a->id => (int) $a->initial_balance]
        )->all();

        if ($balances === []) {
            return [];
        }

        $ids = array_keys($balances);

        $rows = Transaction::where('household_id', $householdId)
            ->whereIn('account_id', $ids)
            ->whereIn('type', ['income', 'expense'])
            ->where('tanggal', '<=', $asOf)
            ->groupBy('account_id', 'type')
            ->select('account_id', 'type', DB::raw('SUM(total) as total'))
            ->get();

        foreach ($rows as $row) {
            $amount = (int) $row->total;
            $balances[$row->account_id] += $row->type === 'income' ? $amount : -$amount;
        }

        $out = Transfer::where('household_id', $householdId)
            ->where('tanggal', '<=', $asOf)
            ->groupBy('from_account_id')
            ->select('from_account_id', DB::raw('SUM(amount) as total'))
            ->pluck('total', 'from_account_id');

        foreach ($out as $accountId => $amount) {
            if (array_key_exists($accountId, $balances)) {
                $balances[$accountId] -= (int) $amount;
            }
        }

        $in = Transfer::where('household_id', $householdId)
            ->where('tanggal', '<=', $asOf)
            ->groupBy('to_account_id')
            ->select('to_account_id', DB::raw('SUM(amount) as total'))
            ->pluck('total', 'to_account_id');

        foreach ($in as $accountId => $amount) {
            if (array_key_exists($accountId, $balances)) {
                $balances[$accountId] += (int) $amount;
            }
        }

        return $balances;
    }

    /**
     * Perubahan saldo per bulan, dipisah bucket kas dan kartu kredit.
     *
     * @param  array<int, string>  $accountBucket
     * @return array<string, array{cash: int, card: int}>
     */
    private function monthlyAccountDeltas(int $householdId, array $accountBucket, string $from): array
    {
        if ($accountBucket === []) {
            return [];
        }

        $ids = array_keys($accountBucket);
        $deltas = [];

        $add = function (string $month, string $bucket, int $amount) use (&$deltas) {
            $deltas[$month] ??= ['cash' => 0, 'card' => 0];
            $deltas[$month][$bucket] += $amount;
        };

        $transactions = Transaction::where('household_id', $householdId)
            ->whereIn('account_id', $ids)
            ->whereIn('type', ['income', 'expense'])
            ->where('tanggal', '>=', $from)
            ->get(['account_id', 'tanggal', 'type', 'total']);

        foreach ($transactions as $transaction) {
            $amount = (int) $transaction->total;
            $add(
                Carbon::parse($transaction->tanggal)->format('Y-m'),
                $accountBucket[$transaction->account_id],
                $transaction->type === 'income' ? $amount : -$amount
            );
        }

        $transfers = Transfer::where('household_id', $householdId)
            ->where('tanggal', '>=', $from)
            ->get(['from_account_id', 'to_account_id', 'tanggal', 'amount']);

        foreach ($transfers as $transfer) {
            $month = Carbon::parse($transfer->tanggal)->format('Y-m');
            $amount = (int) $transfer->amount;

            if (isset($accountBucket[$transfer->from_account_id])) {
                $add($month, $accountBucket[$transfer->from_account_id], -$amount);
            }
            if (isset($accountBucket[$transfer->to_account_id])) {
                $add($month, $accountBucket[$transfer->to_account_id], $amount);
            }
        }

        return $deltas;
    }

    /** @return array<string, int> */
    private function monthlyInvestmentDeltas(int $householdId, string $from): array
    {
        $rows = DB::table('investment_transactions as it')
            ->join('investments as i', 'i.id', '=', 'it.investment_id')
            ->where('i.household_id', $householdId)
            ->where('it.transaction_date', '>=', $from)
            ->get(['it.transaction_date', 'it.type', 'it.total_amount', 'it.fee']);

        $deltas = [];
        foreach ($rows as $row) {
            $month = Carbon::parse($row->transaction_date)->format('Y-m');
            $deltas[$month] ??= 0;
            $deltas[$month] += $this->investmentBasisDelta($row->type, (int) $row->total_amount, (int) $row->fee);
        }

        return $deltas;
    }

    /**
     * Perubahan sisa utang per bulan: pinjaman baru menambah, pembayaran mengurangi.
     *
     * @return array<string, int>
     */
    private function monthlyLoanDeltas(int $householdId, string $from): array
    {
        $deltas = [];

        $loans = Loan::where('household_id', $householdId)
            ->where('start_date', '>=', $from)
            ->get(['id', 'start_date', 'total_amount', 'initial_paid_amount']);

        foreach ($loans as $loan) {
            $month = Carbon::parse($loan->start_date)->format('Y-m');
            $deltas[$month] ??= 0;
            $deltas[$month] += (int) $loan->total_amount - (int) $loan->initial_paid_amount;
        }

        $payments = Transaction::where('household_id', $householdId)
            ->whereNotNull('loan_id')
            ->where('type', 'expense')
            ->where('tanggal', '>=', $from)
            ->get(['tanggal', 'total']);

        foreach ($payments as $payment) {
            $month = Carbon::parse($payment->tanggal)->format('Y-m');
            $deltas[$month] ??= 0;
            $deltas[$month] -= (int) $payment->total;
        }

        return $deltas;
    }

    /** Harga perolehan portofolio sampai tanggal tertentu. */
    private function investmentBasisAsOf(int $householdId, string $asOf): int
    {
        $rows = DB::table('investment_transactions as it')
            ->join('investments as i', 'i.id', '=', 'it.investment_id')
            ->where('i.household_id', $householdId)
            ->where('it.transaction_date', '<=', $asOf)
            ->get(['it.type', 'it.total_amount', 'it.fee']);

        $basis = 0;
        foreach ($rows as $row) {
            $basis += $this->investmentBasisDelta($row->type, (int) $row->total_amount, (int) $row->fee);
        }

        return max(0, $basis);
    }

    private function investmentBasisDelta(string $type, int $totalAmount, int $fee): int
    {
        return match ($type) {
            'buy' => $totalAmount + $fee,
            'sell' => -$totalAmount,
            'fee' => -$fee,
            default => 0,
        };
    }

    /** Sisa utang pinjaman; $asOf null berarti memakai nilai tersimpan. */
    private function loanOutstandingAsOf(int $householdId, ?string $asOf): int
    {
        $loans = Loan::where('household_id', $householdId)->get();

        if ($asOf === null) {
            return (int) $loans
                ->where('status', 'active')
                ->sum(fn (Loan $loan) => max(0, (int) $loan->total_amount - (int) $loan->paid_amount));
        }

        $payments = Transaction::where('household_id', $householdId)
            ->whereNotNull('loan_id')
            ->where('type', 'expense')
            ->where('tanggal', '<=', $asOf)
            ->groupBy('loan_id')
            ->select('loan_id', DB::raw('SUM(total) as total'))
            ->pluck('total', 'loan_id');

        $cutoff = Carbon::parse($asOf);
        $outstanding = 0;

        foreach ($loans as $loan) {
            if ($loan->start_date && $loan->start_date->gt($cutoff)) {
                continue;
            }

            $paid = (int) $loan->initial_paid_amount + (int) ($payments[$loan->id] ?? 0);
            $outstanding += max(0, (int) $loan->total_amount - $paid);
        }

        return $outstanding;
    }
}
