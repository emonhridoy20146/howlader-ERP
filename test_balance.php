<?php

use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\Wallet;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$locationId = 1; // Assuming location 1 exists
$location = Location::find($locationId);
if (! $location) {
    echo 'No location 1';
    exit;
}

// Stock Value
$stockValue = DB::table('inventory_balances')
    ->join('products', 'inventory_balances.product_id', '=', 'products.id')
    ->where('inventory_balances.location_id', $locationId)
    ->selectRaw('SUM(inventory_balances.on_hand_qty * products.default_purchase_price) as total_value')
    ->value('total_value') ?? 0;

// Invoices Balance (Unpaid amount due)
$invoicesBalance = DB::table('sales_invoices')
    ->where('branch_id', $locationId)
    ->sum('amount_due') ?? 0;

// Ledgers Balance (total balance of wallets associated with this location?)
// Wallets are mapped to locations
$walletIds = DB::table('location_wallet_maps')
    ->where('location_id', $locationId)
    ->pluck('wallet_id');

$ledgerAccountIds = Wallet::whereIn('id', $walletIds)->pluck('ledger_account_id');

$ledgersBalance = DB::table('journal_lines')
    ->whereIn('ledger_account_id', $ledgerAccountIds)
    ->selectRaw('SUM(debit - credit) as balance')
    ->value('balance') ?? 0;

// Expenses Balance (Total manual expenses)
$expensesBalance = JournalEntry::where('branch_id', $locationId)
    ->where('source', 'manual_expense')
    ->join('journal_lines', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
    ->sum('journal_lines.debit') ?? 0;

echo json_encode(compact('stockValue', 'invoicesBalance', 'ledgersBalance', 'expensesBalance'));
