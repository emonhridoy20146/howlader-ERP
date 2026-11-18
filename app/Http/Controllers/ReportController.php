<?php

namespace App\Http\Controllers;

use App\Exports\BranchInStockPricingExport;
use App\Exports\BranchTargetsExport;
use App\Exports\CollectorWorkbookExport;
use App\Exports\CustomerDuesExport;
use App\Exports\CustomerJournalExport;
use App\Exports\CustomerPaymentByBranchExport;
use App\Exports\DailyExpenseByBranchExport;
use App\Exports\HighRiskCustomersExport;
use App\Exports\InstallmentDurationExport;
use App\Exports\InstallmentOverdueExport;
use App\Exports\InvoiceOutstandingByCustomerExport;
use App\Exports\ItemWisePurchaseExport;
use App\Exports\ItemWiseSalesExport;
use App\Exports\MonthlyCollectionExcelExport;
use App\Exports\MonthToDateSalesExport;
use App\Exports\OpenPurchaseOrdersExport;
use App\Exports\ReceivablesOverdueExcelExport;
use App\Exports\RepresentativeWiseSalesExport;
use App\Exports\SalesByDayBranchExport;
use App\Exports\SalesSummaryExport;
use App\Exports\StockByLocationExport;
use App\Exports\StockSummaryExport;
use App\Exports\TransferLedgerExport;
use App\Exports\WalletTransactionsExport;
use App\Exports\YearlyCollectionSheetExport;
use App\Models\AccountsReceivableLedger;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Wallet;
use App\Services\BranchTargetsReportService;
use App\Services\ReportDataService;
use App\Services\Reports\PurchaseReportService;
use App\Services\Reports\SalesReportService;
use App\Services\Reports\YearlyCollectionSheetService;
use App\Support\CompanyPad;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportDataService $reportData,
        private readonly SalesReportService $salesReportService,
        private readonly PurchaseReportService $purchaseReportService,
        private readonly BranchTargetsReportService $branchTargetsReport,
    ) {}

    public function index(Request $request): View
    {
        $report = (string) $request->input('report', ReportDataService::REPORT_SLUGS[0]);
        if (! in_array($report, ReportDataService::REPORT_SLUGS, true) && $report !== 'item-wise-sales') {
            $report = ReportDataService::REPORT_SLUGS[0];
        }

        $stockFilters = $this->reportData->stockByLocationFilters($request);
        $branchInStockPricingFilters = $this->reportData->branchInStockPricingFilters($request);
        $salesFilters = $this->reportData->salesSummaryFilters($request);
        $lowStockFilters = $this->reportData->lowStockFilters($request);
        $customerDuesFilters = $this->reportData->customerDuesFilters($request);
        $openPoFilters = $this->reportData->openPurchaseOrdersFilters($request);
        $stockSummaryFilters = $this->reportData->stockSummaryFilters($request);
        $itemWiseSalesFilters = $this->salesReportService->itemWiseSalesFilters($request);
        $representativeWiseSalesFilters = $this->salesReportService->representativeWiseSalesFilters($request);
        $itemWisePurchaseFilters = $this->purchaseReportService->itemWisePurchaseFilters($request);
        $creditOverdueFilters = $this->reportData->creditOverdueFilters($request);
        $invoiceOutstandingByCustomerFilters = $this->reportData->invoiceOutstandingByCustomerFilters($request);
        $supplierDuesAndPaymentsFilters = $this->reportData->supplierDuesAndPaymentsFilters($request);
        $salesByDayBranchFilters = $this->reportData->salesByDayBranchFilters($request);
        $customerPaymentByBranchFilters = $this->reportData->customerPaymentByBranchFilters($request);
        $dailyExpenseByBranchFilters = $this->reportData->dailyExpenseByBranchFilters($request);
        $branchTargetsFilters = $this->branchTargetsReport->filters($request);
        $profitLossSheetFilters = $this->reportData->profitLossSheetFilters($request);
        $incomeExpenditureStatementFilters = $this->reportData->incomeExpenditureStatementFilters($request);
        $monthlyCollectionFilters = $this->reportData->monthlyCollectionFilters($request);
        $installmentDurationFilters = $this->reportData->installmentDurationFilters($request);
        $walletTransactionsFilters = $this->reportData->walletTransactionsFilters($request);
        $transferLedgerFilters = $this->reportData->transferLedgerFilters($request);

        $highRiskFilters = $this->reportData->highRiskCustomersFilters($request);
        $yearlyCollectionService = app(YearlyCollectionSheetService::class);
        $yearlyCollectionFilters = $yearlyCollectionService->filters(new Request);

        $customerJournalFilters = [
            'customer_id' => (string) $request->input('customer_id', ''),
            'date_from' => (string) $request->input('date_from', ''),
            'date_to' => (string) $request->input('date_to', ''),
        ];

        $filterCustomers = Customer::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'customer_code', 'name', 'phone']);

        return view('reports.index', [
            'report' => $report,
            'stockFilters' => $stockFilters,
            'branchInStockPricingFilters' => $branchInStockPricingFilters,
            'salesFilters' => $salesFilters,
            'lowStockFilters' => $lowStockFilters,
            'customerDuesFilters' => $customerDuesFilters,
            'openPoFilters' => $openPoFilters,
            'itemWiseSalesFilters' => $itemWiseSalesFilters,
            'representativeWiseSalesFilters' => $representativeWiseSalesFilters,
            'itemWisePurchaseFilters' => $itemWisePurchaseFilters,
            'creditOverdueFilters' => $creditOverdueFilters,
            'invoiceOutstandingByCustomerFilters' => $invoiceOutstandingByCustomerFilters,
            'supplierDuesAndPaymentsFilters' => $supplierDuesAndPaymentsFilters,
            'salesByDayBranchFilters' => $salesByDayBranchFilters,
            'customerPaymentByBranchFilters' => $customerPaymentByBranchFilters,
            'dailyExpenseByBranchFilters' => $dailyExpenseByBranchFilters,
            'branchTargetsFilters' => $branchTargetsFilters,
            'profitLossSheetFilters' => $profitLossSheetFilters,
            'incomeExpenditureStatementFilters' => $incomeExpenditureStatementFilters,
            'monthlyCollectionFilters' => $monthlyCollectionFilters,
            'installmentDurationFilters' => $installmentDurationFilters,
            'walletTransactionsFilters' => $walletTransactionsFilters,
            'transferLedgerFilters' => $transferLedgerFilters,
            'stockSummaryFilters' => $stockSummaryFilters,
            'customerJournalFilters' => $customerJournalFilters,
            'highRiskFilters' => $highRiskFilters,
            'yearlyCollectionFilters' => $yearlyCollectionFilters,
            'yearlyCollectionCollectors' => $yearlyCollectionService->collectorOptions(),
            'filterLocations' => $this->reportData->stockByLocationFilterLocations(),
            'stockSummaryFilterProducts' => $this->reportData->stockSummaryFilterProducts(),
            'stockSummaryFilterCategories' => $this->reportData->stockSummaryFilterCategories(),
            'stockSummaryFilterBrands' => $this->reportData->stockSummaryFilterBrands(),
            'filterBranches' => $this->reportData->salesSummaryFilterBranches(),
            'filterExpenseAccounts' => $this->reportData->dailyExpenseByBranchFilterExpenseAccounts(),
            'filterSuppliers' => $this->reportData->openPurchaseOrdersFilterSuppliers(),
            'filterWarehouseLocations' => $this->reportData->openPurchaseOrdersFilterLocations(),
            'filterRepresentatives' => $this->salesReportService->filterRepresentatives(),
            'filterCustomers' => $filterCustomers,
            'filterWallets' => Wallet::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'kind']),
        ]);
    }

    public function stockByLocation(Request $request): View
    {
        $data = $this->reportData->stockByLocationPaginated($request);

        return view('reports.stock-by-location', [
            'rows' => $data['rows'],
            'filters' => $data['filters'],
            'kpis' => $data['kpis'],
            'filterLocations' => $data['filterLocations'],
        ]);
    }

    public function branchInStockPricing(Request $request): View
    {
        $data = $this->reportData->branchInStockPricingPaginated($request);

        return view('reports.branch-in-stock-pricing', [
            'rows' => $data['rows'],
            'filters' => $data['filters'],
            'kpis' => $data['kpis'],
            'filterLocations' => $data['filterLocations'],
            'filterCategories' => $data['filterCategories'],
        ]);
    }

    public function stockSummary(Request $request): View
    {
        $data = $this->reportData->stockSummaryPaginated($request);

        return view('reports.stock-summary', [
            'rows' => $data['rows'],
            'filters' => $data['filters'],
            'kpis' => $data['kpis'],
            'filterLocations' => $data['filterLocations'],
            'filterProducts' => $data['filterProducts'],
            'filterCategories' => $data['filterCategories'],
            'filterBrands' => $data['filterBrands'],
        ]);
    }

    public function salesSummary(Request $request): View
    {
        $data = $this->reportData->salesSummaryPaginated($request);

        return view('reports.sales-summary', [
            'rows' => $data['rows'],
            'filters' => $data['filters'],
            'kpis' => $data['kpis'],
            'filterBranches' => $data['filterBranches'],
            'filterSummary' => $data['filterSummary'],
        ]);
    }

    public function monthToDateSales(Request $request): View
    {
        $data = $this->reportData->monthToDateSalesPaginated($request);

        return view('reports.month-to-date-sales', [
            'rows' => $data['rows'],
            'filters' => $data['filters'],
            'kpis' => $data['kpis'],
            'summaryByBranch' => $data['summaryByBranch'],
            'summaryByType' => $data['summaryByType'],
            'filterBranches' => $data['filterBranches'],
            'filterSummary' => $data['filterSummary'],
        ]);
    }

    public function installmentOverdue(Request $request): View
    {
        $data = $this->reportData->installmentOverduePaginated($request);

        return view('reports.installment-overdue', [
            'rows' => $data['rows'],
            'filters' => $data['filters'],
            'kpis' => $data['kpis'],
            'filterBranches' => $data['filterBranches'],
        ]);
    }

    public function installmentDuration(Request $request): View
    {
        $data = $this->reportData->installmentDurationPaginated($request);

        return view('reports.installment-duration', [
            'rows' => $data['rows'],
            'filters' => $data['filters'],
            'kpis' => $data['kpis'],
            'filterBranches' => $data['filterBranches'],
        ]);
    }

    public function creditOverdue(Request $request): View
    {
        $data = $this->reportData->creditOverduePaginated($request);

        return view('reports.credit-overdue', [
            'rows' => $data['rows'],
            'installmentRows' => $data['installmentRows'],
            'maturedRows' => $data['maturedRows'],
            'filters' => $data['filters'],
            'kpis' => $data['kpis'],
            'filterBranches' => $data['filterBranches'],
        ]);
    }

    public function lowStock(Request $request): View
    {
        $data = $this->reportData->lowStockPaginated($request);

        return view('reports.low-stock', [
            'rows' => $data['rows'],
            'filters' => $data['filters'],
            'kpis' => $data['kpis'],
            'filterLocations' => $data['filterLocations'],
        ]);
    }

    public function customerDues(Request $request): View
    {
        $data = $this->reportData->customerDuesPaginated($request);

        return view('reports.customer-dues', [
            'rows' => $data['rows'],
            'filters' => $data['filters'],
            'kpis' => $data['kpis'],
            'filterBranches' => $data['filterBranches'],
            'recalcSummary' => Cache::get('reports.customer_dues.last_recalc'),
        ]);
    }

    public function yearlyCollectionSheet(Request $request): View
    {
        $service = app(YearlyCollectionSheetService::class);
        $data = $service->paginated($service->filters($request));

        return view('reports.yearly-collection-sheet', [
            'data' => $data,
            'filters' => $data['filters'],
            'filterBranches' => $service->branchOptions(),
            'collectors' => $service->collectorOptions(),
        ]);
    }

    public function highRiskCustomers(Request $request): View
    {
        $data = $this->reportData->highRiskCustomersPaginated($request, $this->resolvePerPage($request, 50));

        return view('reports.high-risk-customers', [
            'rows' => $data['rows'],
            'filters' => $data['filters'],
            'kpis' => $data['kpis'],
            'filterBranches' => $data['filterBranches'],
        ]);
    }

    public function invoiceOutstandingByCustomer(Request $request): View
    {
        $data = $this->reportData->invoiceOutstandingByCustomerPaginated($request);

        return view('reports.invoice-outstanding-by-customer', [
            'rows' => $data['rows'],
            'filters' => $data['filters'],
            'kpis' => $data['kpis'],
            'branchTotals' => $data['branch_totals'],
            'filterBranches' => $data['filterBranches'],
        ]);
    }

    public function supplierDuesAndPayments(Request $request): View
    {
        $data = $this->reportData->supplierDuesAndPaymentsPaginated($request);

        return view('reports.supplier-dues-and-payments', [
            'rows' => $data['rows'],
            'filters' => $data['filters'],
            'kpis' => $data['kpis'],
            'filterWarehouseLocations' => $data['filterWarehouseLocations'],
        ]);
    }

    public function salesByDayBranch(Request $request): View
    {
        $data = $this->reportData->salesByDayBranchPaginated($request);

        return view('reports.sales-by-day-branch', [
            'rows' => $data['rows'],
            'filters' => $data['filters'],
            'kpis' => $data['kpis'],
            'productTotals' => $data['productTotals'],
            'filterBranches' => $data['filterBranches'],
        ]);
    }

    public function customerPaymentByBranch(Request $request): View
    {
        $data = $this->reportData->customerPaymentByBranchPaginated($request);

        return view('reports.customer-payment-by-branch', [
            'rows' => $data['rows'],
            'filters' => $data['filters'],
            'kpis' => $data['kpis'],
            'filterBranches' => $data['filterBranches'],
            'collectorTotals' => $data['collectorTotals'],
        ]);
    }

    public function dailyExpenseByBranch(Request $request): View
    {
        $data = $this->reportData->dailyExpenseByBranchPaginated($request);

        return view('reports.daily-expenses-by-branch', [
            'rows' => $data['rows'],
            'filters' => $data['filters'],
            'kpis' => $data['kpis'],
            'detailTotals' => $data['detailTotals'],
            'filterBranches' => $data['filterBranches'],
            'filterExpenseAccounts' => $data['filterExpenseAccounts'],
        ]);
    }

    public function branchTargets(Request $request): View
    {
        return view('reports.branch-targets', $this->branchTargetsReport->report($request));
    }

    public function profitLossSheet(Request $request): View
    {
        $data = $this->reportData->profitLossSheetForExport($request);

        return view('reports.profit-loss-sheet', $data);
    }

    public function incomeExpenditureStatement(Request $request): View
    {
        $data = $this->reportData->incomeExpenditureStatementForExport($request);

        return view('reports.income-expenditure-statement', $data);
    }

    public function openPurchaseOrders(Request $request): View
    {
        $data = $this->reportData->openPurchaseOrdersPaginated($request);

        return view('reports.open-purchase-orders', [
            'rows' => $data['rows'],
            'filters' => $data['filters'],
            'kpis' => $data['kpis'],
            'filterSuppliers' => $data['filterSuppliers'],
            'filterLocations' => $data['filterLocations'],
        ]);
    }

    public function itemWiseSales(Request $request): View
    {
        $data = $this->salesReportService->itemWiseSalesPaginated($request);

        return view('reports.item-wise-sales', [
            'rows' => $data['rows'],
            'filters' => $data['filters'],
            'kpis' => $data['kpis'],
            'filterBranches' => $data['filterBranches'],
        ]);
    }

    public function representativeWiseSales(Request $request): View
    {
        $data = $this->salesReportService->representativeWiseSalesPaginated($request);

        return view('reports.representative-wise-sales', [
            'rows' => $data['rows'],
            'filters' => $data['filters'],
            'kpis' => $data['kpis'],
            'filterBranches' => $data['filterBranches'],
            'filterRepresentatives' => $data['filterRepresentatives'],
            'invoiceDetailsByReferrer' => $data['invoiceDetailsByReferrer'],
        ]);
    }

    public function monthlyCollection(Request $request): View
    {
        $data = $this->reportData->monthlyCollectionPaginated($request);

        return view('reports.monthly-collection', [
            'thisMonthInstallments' => $data['thisMonthInstallments'],
            'thisMonthCredits' => $data['thisMonthCredits'],
            'overdueInstallments' => $data['overdueInstallments'],
            'overdueCredits' => $data['overdueCredits'],
            'maturedOverdueInstallments' => $data['maturedOverdueInstallments'],
            'filters' => $data['filters'],
            'filterBranches' => $data['filterBranches'],
            'periodPaymentsByInvoiceId' => $data['periodPaymentsByInvoiceId'],
            'collectorsByInvoiceId' => $data['collectorsByInvoiceId'],
            'summary' => $this->reportData->monthlyCollectionTotals($request),
        ]);
    }

    public function walletTransactions(Request $request): View
    {
        abort_unless($request->user()?->hasPermission('wallet.view'), 403);

        $data = $this->reportData->walletTransactionsPaginated($request);

        return view('reports.wallet-transactions', [
            'rows' => $data['rows'],
            'filters' => $data['filters'],
            'globalOpeningBalance' => $data['globalOpeningBalance'],
            'openingBalancesByBranch' => $data['openingBalancesByBranch'],
            'summaryByBranch' => $data['summaryByBranch'],
            'summaryBySource' => $data['summaryBySource'],
            'truncated' => $data['truncated'],
            'totalMatching' => $data['totalMatching'],
            'filterWallets' => Wallet::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'kind']),
        ]);
    }

    public function transferLedger(Request $request): View
    {
        $data = $this->reportData->transferLedgerPaginated($request);

        return view('reports.transfer-ledger', [
            'rows' => $data['rows'],
            'filters' => $data['filters'],
            'kpis' => $data['kpis'],
            'filterLocations' => $data['filterLocations'],
        ]);
    }

    public function itemWisePurchase(Request $request): View
    {
        $data = $this->purchaseReportService->itemWisePurchasePaginated($request);

        return view('reports.item-wise-purchase', [
            'rows' => $data['rows'],
            'filters' => $data['filters'],
            'kpis' => $data['kpis'],
            'filterLocations' => $data['filterLocations'],
        ]);
    }

    private function assertExportableSlug(string $slug): void
    {
        if (! in_array($slug, ReportDataService::REPORT_SLUGS, true)
            && ! in_array($slug, ['item-wise-sales', 'month-to-date-sales', 'representative-wise-sales', 'item-wise-purchase'], true)) {
            abort(404);
        }
    }

    public function print(Request $request, string $slug): View
    {
        $this->assertExportableSlug($slug);

        $companyPad = CompanyPad::settings();
        $generatedLine = 'Generated '.Carbon::now()->format('Y-m-d H:i');

        return match ($slug) {
            'stock-by-location' => $this->printStockByLocation($request, $companyPad, $generatedLine),
            'branch-in-stock-pricing' => $this->printBranchInStockPricing($request, $companyPad, $generatedLine),
            'stock-summary' => $this->printStockSummary($request, $companyPad, $generatedLine),
            'sales-summary' => $this->printSalesSummary($request, $companyPad, $generatedLine),
            'month-to-date-sales' => $this->printMonthToDateSales($request, $companyPad, $generatedLine),
            'installment-overdue' => $this->printInstallmentOverdue($request, $companyPad, $generatedLine),
            'installment-duration' => $this->printInstallmentDuration($request, $companyPad, $generatedLine),
            'credit-overdue' => $this->printCreditOverdue($request, $companyPad, $generatedLine),
            'low-stock' => $this->printLowStock($request, $companyPad, $generatedLine),
            'customer-dues' => $this->printCustomerDues($request, $companyPad, $generatedLine),
            'high-risk-customers' => $this->printHighRiskCustomers($request, $companyPad, $generatedLine),
            'yearly-collection-sheet' => $this->printYearlyCollectionSheet($request, $companyPad, $generatedLine),
            'invoice-outstanding-by-customer' => $this->printInvoiceOutstandingByCustomer($request, $companyPad, $generatedLine),
            'open-purchase-orders' => $this->printOpenPurchaseOrders($request, $companyPad, $generatedLine),
            'supplier-dues-and-payments' => $this->printSupplierDuesAndPayments($request, $companyPad, $generatedLine),
            'sales-by-day-branch' => $this->printSalesByDayBranch($request, $companyPad, $generatedLine),
            'item-wise-sales' => $this->printItemWiseSales($request, $companyPad, $generatedLine),
            'representative-wise-sales' => $this->printRepresentativeWiseSales($request, $companyPad, $generatedLine),
            'item-wise-purchase' => $this->printItemWisePurchase($request, $companyPad, $generatedLine),
            'customer-journal' => $this->printCustomerJournal($request, $companyPad, $generatedLine),
            'monthly-collection' => $this->printMonthlyCollection($request, $companyPad, $generatedLine),
            'wallet-transactions' => $this->printWalletTransactions($request, $companyPad, $generatedLine),
            'transfer-ledger' => $this->printTransferLedger($request, $companyPad, $generatedLine),
            'customer-payment-by-branch' => $this->printCustomerPaymentByBranch($request, $companyPad, $generatedLine),
            'daily-expenses-by-branch' => $this->printDailyExpenseByBranch($request, $companyPad, $generatedLine),
            'branch-targets' => $this->printBranchTargets($request, $companyPad, $generatedLine),
            'profit-loss-sheet' => $this->printProfitLossSheet($request, $companyPad, $generatedLine),
            'income-expenditure-statement' => $this->printIncomeExpenditureStatement($request, $companyPad, $generatedLine),
        };
    }

    public function export(Request $request, string $slug): Response
    {
        $this->assertExportableSlug($slug);

        $request->validate([
            'format' => 'required|string|in:'.(in_array($slug, ['high-risk-customers', 'yearly-collection-sheet'], true) ? 'xlsx,pdf' : 'xlsx'),
        ]);

        $companyPad = CompanyPad::settings();
        $generatedLine = 'Generated '.Carbon::now()->format('Y-m-d H:i');

        return match ($slug) {
            'stock-by-location' => $this->exportStockByLocation($request, $companyPad, $generatedLine),
            'branch-in-stock-pricing' => $this->exportBranchInStockPricing($request, $companyPad, $generatedLine),
            'stock-summary' => $this->exportStockSummary($request, $companyPad, $generatedLine),
            'sales-summary' => $this->exportSalesSummary($request, $companyPad, $generatedLine),
            'month-to-date-sales' => $this->exportMonthToDateSales($request, $companyPad, $generatedLine),
            'installment-overdue' => $this->exportInstallmentOverdue($request, $companyPad, $generatedLine),
            'installment-duration' => $this->exportInstallmentDuration($request, $companyPad, $generatedLine),
            'credit-overdue' => $this->exportCreditOverdue($request, $companyPad, $generatedLine),
            'low-stock' => $this->exportLowStock($request, $companyPad, $generatedLine),
            'customer-dues' => $this->exportCustomerDues($request, $companyPad, $generatedLine),
            'high-risk-customers' => $this->exportHighRiskCustomers($request, $companyPad, $generatedLine),
            'yearly-collection-sheet' => $this->exportYearlyCollectionSheet($request, $companyPad, $generatedLine),
            'invoice-outstanding-by-customer' => $this->exportInvoiceOutstandingByCustomer($request, $companyPad, $generatedLine),
            'open-purchase-orders' => $this->exportOpenPurchaseOrders($request, $companyPad, $generatedLine),
            'supplier-dues-and-payments' => $this->exportSupplierDuesAndPayments($request, $companyPad, $generatedLine),
            'sales-by-day-branch' => $this->exportSalesByDayBranch($request, $companyPad, $generatedLine),
            'item-wise-sales' => $this->exportItemWiseSales($request, $companyPad, $generatedLine),
            'representative-wise-sales' => $this->exportRepresentativeWiseSales($request, $companyPad, $generatedLine),
            'item-wise-purchase' => $this->exportItemWisePurchase($request, $companyPad, $generatedLine),
            'customer-journal' => $this->exportCustomerJournal($request, $companyPad, $generatedLine),
            'monthly-collection' => $this->exportMonthlyCollection($request, $companyPad, $generatedLine),
            'wallet-transactions' => $this->exportWalletTransactions($request, $companyPad, $generatedLine),
            'transfer-ledger' => $this->exportTransferLedger($request, $companyPad, $generatedLine),
            'customer-payment-by-branch' => $this->exportCustomerPaymentByBranch($request, $companyPad, $generatedLine),
            'daily-expenses-by-branch' => $this->exportDailyExpenseByBranch($request, $companyPad, $generatedLine),
            'branch-targets' => $this->exportBranchTargets($request, $companyPad, $generatedLine),
        };
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function exportStockByLocation(Request $request, array $companyPad, string $generatedLine): BinaryFileResponse
    {
        $payload = $this->reportData->stockByLocationForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;
        if ($payload['truncated']) {
            $subtitle .= ' · Showing first '.ReportDataService::STOCK_EXPORT_LIMIT.' of '.$payload['totalMatching'].' rows';
        }

        return Excel::download(
            new StockByLocationExport($payload['rows'], $companyPad, 'Stock by location', $subtitle),
            'stock-by-location-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printStockByLocation(Request $request, array $companyPad, string $generatedLine): View
    {
        $payload = $this->reportData->stockByLocationForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;
        if ($payload['truncated']) {
            $subtitle .= ' · Showing first '.ReportDataService::STOCK_EXPORT_LIMIT.' of '.$payload['totalMatching'].' rows';
        }

        return view('reports.exports.stock-by-location-print', [
            'companyPad' => $companyPad,
            'reportTitle' => 'Stock by location',
            'subtitle' => $subtitle,
            'rows' => $payload['rows'],
            'kpis' => $payload['kpis'],
        ]);
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function exportBranchInStockPricing(Request $request, array $companyPad, string $generatedLine): BinaryFileResponse
    {
        $payload = $this->reportData->branchInStockPricingForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;
        if ($payload['truncated']) {
            $subtitle .= ' · Showing first '.ReportDataService::STOCK_EXPORT_LIMIT.' of '.$payload['totalMatching'].' rows';
        }

        return Excel::download(
            new BranchInStockPricingExport($payload['rows'], $companyPad, 'Branch in-stock pricing', $subtitle),
            'branch-in-stock-pricing-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printBranchInStockPricing(Request $request, array $companyPad, string $generatedLine): View
    {
        $payload = $this->reportData->branchInStockPricingForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;
        if ($payload['truncated']) {
            $subtitle .= ' · Showing first '.ReportDataService::STOCK_EXPORT_LIMIT.' of '.$payload['totalMatching'].' rows';
        }

        return view('reports.exports.branch-in-stock-pricing-print', [
            'companyPad' => $companyPad,
            'reportTitle' => 'Branch in-stock pricing',
            'subtitle' => $subtitle,
            'rows' => $payload['rows'],
        ]);
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function exportStockSummary(Request $request, array $companyPad, string $generatedLine): BinaryFileResponse
    {
        $payload = $this->reportData->stockSummaryForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;
        if ($payload['truncated']) {
            $subtitle .= ' · Showing first '.ReportDataService::STOCK_EXPORT_LIMIT.' of '.$payload['totalMatching'].' rows';
        }

        return Excel::download(
            new StockSummaryExport($payload['rows'], $companyPad, 'Stock summary', $subtitle, $payload['kpis']),
            'stock-summary-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printStockSummary(Request $request, array $companyPad, string $generatedLine): View
    {
        $payload = $this->reportData->stockSummaryForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;
        if ($payload['truncated']) {
            $subtitle .= ' · Showing first '.ReportDataService::STOCK_EXPORT_LIMIT.' of '.$payload['totalMatching'].' rows';
        }

        return view('reports.exports.stock-summary-print', [
            'companyPad' => $companyPad,
            'reportTitle' => 'Stock summary',
            'subtitle' => $subtitle,
            'rows' => $payload['rows'],
            'kpis' => $payload['kpis'],
        ]);
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function exportSalesSummary(Request $request, array $companyPad, string $generatedLine): BinaryFileResponse
    {
        $payload = $this->reportData->salesSummaryForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return Excel::download(
            new SalesSummaryExport($payload['rows'], $companyPad, 'Sales summary', $subtitle),
            'sales-summary-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printSalesSummary(Request $request, array $companyPad, string $generatedLine): View
    {
        $payload = $this->reportData->salesSummaryForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return view('reports.exports.sales-summary-print', [
            'companyPad' => $companyPad,
            'reportTitle' => 'Sales summary',
            'subtitle' => $subtitle,
            'rows' => $payload['rows'],
            'kpis' => $payload['kpis'],
        ]);
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function exportInstallmentOverdue(Request $request, array $companyPad, string $generatedLine): BinaryFileResponse
    {
        $payload = $this->reportData->installmentOverdueForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return Excel::download(
            new InstallmentOverdueExport($payload['rows'], $companyPad, 'Installment overdue', $subtitle),
            'installment-overdue-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printInstallmentOverdue(Request $request, array $companyPad, string $generatedLine): View
    {
        $payload = $this->reportData->installmentOverdueForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return view('reports.exports.installment-overdue-print', [
            'companyPad' => $companyPad,
            'reportTitle' => 'Installment overdue',
            'subtitle' => $subtitle,
            'rows' => $payload['rows'],
            'kpis' => $payload['kpis'],
            'totalMatching' => $payload['totalMatching'],
        ]);
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function exportInstallmentDuration(Request $request, array $companyPad, string $generatedLine): BinaryFileResponse
    {
        $payload = $this->reportData->installmentDurationForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return Excel::download(
            new InstallmentDurationExport($payload['rows'], $companyPad, 'Installment duration', $subtitle),
            'installment-duration-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printInstallmentDuration(Request $request, array $companyPad, string $generatedLine): View
    {
        $payload = $this->reportData->installmentDurationForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return view('reports.exports.installment-duration-print', [
            'companyPad' => $companyPad,
            'reportTitle' => 'Installment duration',
            'subtitle' => $subtitle,
            'rows' => $payload['rows'],
            'kpis' => $payload['kpis'],
        ]);
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function exportCreditOverdue(Request $request, array $companyPad, string $generatedLine): BinaryFileResponse
    {
        $payload = $this->reportData->creditOverdueForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return Excel::download(
            new ReceivablesOverdueExcelExport(
                $payload['creditRows'],
                $payload['installmentRows'],
                $payload['maturedRows'],
                $companyPad,
                $subtitle
            ),
            'receivables-overdue-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printCreditOverdue(Request $request, array $companyPad, string $generatedLine): View
    {
        $filters = $this->reportData->creditOverdueFilters($request);
        $payload = $this->reportData->creditOverdueForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return view('reports.exports.receivables-overdue-print', [
            'companyPad' => $companyPad,
            'reportTitle' => 'Overdue receivables',
            'subtitle' => $subtitle,
            'filters' => $filters,
            'creditRows' => $payload['creditRows'],
            'installmentRows' => $payload['installmentRows'],
            'maturedRows' => $payload['maturedRows'],
            'kpis' => $this->reportData->creditOverdueKpisForFilters($filters),
            'detailRowLimit' => ReportDataService::STOCK_EXPORT_LIMIT,
        ]);
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function exportLowStock(Request $request, array $companyPad, string $generatedLine): BinaryFileResponse
    {
        $payload = $this->reportData->lowStockForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;
        if ($payload['truncated']) {
            $subtitle .= ' · Showing first '.ReportDataService::STOCK_EXPORT_LIMIT.' of '.$payload['totalMatching'].' rows';
        }

        return Excel::download(
            new LowStockExport($payload['rows'], $companyPad, 'Low stock', $subtitle),
            'low-stock-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printLowStock(Request $request, array $companyPad, string $generatedLine): View
    {
        $payload = $this->reportData->lowStockForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;
        if ($payload['truncated']) {
            $subtitle .= ' · Showing first '.ReportDataService::STOCK_EXPORT_LIMIT.' of '.$payload['totalMatching'].' rows';
        }

        return view('reports.exports.low-stock-print', [
            'companyPad' => $companyPad,
            'reportTitle' => 'Low stock',
            'subtitle' => $subtitle,
            'rows' => $payload['rows'],
        ]);
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function exportCustomerDues(Request $request, array $companyPad, string $generatedLine): BinaryFileResponse
    {
        $payload = $this->reportData->customerDuesForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return Excel::download(
            new CustomerDuesExport($payload['rows'], $companyPad, 'Customer dues', $subtitle),
            'customer-dues-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printCustomerDues(Request $request, array $companyPad, string $generatedLine): View
    {
        $payload = $this->reportData->customerDuesForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return view('reports.exports.customer-dues-print', [
            'companyPad' => $companyPad,
            'reportTitle' => 'Customer dues',
            'subtitle' => $subtitle,
            'rows' => $payload['rows'],
            'recalcSummary' => Cache::get('reports.customer_dues.last_recalc'),
            'totalOutstanding' => (float) ($payload['total_outstanding'] ?? 0),
        ]);
    }

    /**
     * @param  array<string, string>  $companyPad
     * @return array<string, mixed>
     */
    private function yearlyCollectionSheetPrintData(Request $request, array $companyPad, string $generatedLine, bool $chrome): array
    {
        $service = app(YearlyCollectionSheetService::class);
        $filters = $service->filters($request);
        $payload = $service->forExport($filters);
        $columns = count($payload['months']);

        return [
            'companyPad' => $companyPad,
            'reportTitle' => 'Yearly Collection Sheet',
            'subtitle' => $service->filterSummary($filters).' · '.$generatedLine,
            'data' => $payload,
            'filters' => $filters,
            'paper' => $columns > 24 ? 'a3' : 'legal',
            'showPrintChrome' => $chrome,
            'pdfMode' => ! $chrome,
        ];
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printYearlyCollectionSheet(Request $request, array $companyPad, string $generatedLine): View
    {
        return view('reports.exports.yearly-collection-sheet-print', $this->yearlyCollectionSheetPrintData($request, $companyPad, $generatedLine, true));
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function exportYearlyCollectionSheet(Request $request, array $companyPad, string $generatedLine): Response
    {
        $view = $this->yearlyCollectionSheetPrintData($request, $companyPad, $generatedLine, false);
        $filters = $view['filters'];
        $name = 'yearly-collection-sheet-'.$filters['year_from'].'-'.$filters['year_to'];

        if ($request->input('format') === 'pdf') {
            return Pdf::loadView('reports.exports.yearly-collection-sheet-print', $view)
                ->setPaper($view['paper'], 'landscape')
                ->download($name.'.pdf');
        }

        return Excel::download(new YearlyCollectionSheetExport($view['data'], $companyPad, $view['subtitle']), $name.'.xlsx');
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function exportHighRiskCustomers(Request $request, array $companyPad, string $generatedLine): Response
    {
        $payload = $this->reportData->highRiskCustomersForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        if ($request->input('format') === 'pdf') {
            return Pdf::loadView('reports.exports.high-risk-customers-print', [
                'companyPad' => $companyPad,
                'reportTitle' => 'High-risk customers',
                'subtitle' => $subtitle,
                'rows' => $payload['rows'],
                'kpis' => $payload['kpis'],
                'showPrintChrome' => false,
            ])->setPaper('a4', 'landscape')->download('high-risk-customers-'.now()->format('Ymd-His').'.pdf');
        }

        return Excel::download(
            new HighRiskCustomersExport($payload['rows'], $companyPad, 'High-risk customers', $subtitle),
            'high-risk-customers-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printHighRiskCustomers(Request $request, array $companyPad, string $generatedLine): View
    {
        $payload = $this->reportData->highRiskCustomersForExport($request);

        return view('reports.exports.high-risk-customers-print', [
            'companyPad' => $companyPad,
            'reportTitle' => 'High-risk customers',
            'subtitle' => $payload['filterSummary'].' · '.$generatedLine,
            'rows' => $payload['rows'],
            'kpis' => $payload['kpis'],
        ]);
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function exportInvoiceOutstandingByCustomer(Request $request, array $companyPad, string $generatedLine): BinaryFileResponse
    {
        $payload = $this->reportData->invoiceOutstandingByCustomerForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return Excel::download(
            new InvoiceOutstandingByCustomerExport(
                $payload['rows'],
                $payload['branch_totals'],
                $companyPad,
                'Invoice outstanding by customer',
                $subtitle
            ),
            'invoice-outstanding-by-customer-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printInvoiceOutstandingByCustomer(Request $request, array $companyPad, string $generatedLine): View
    {
        $payload = $this->reportData->invoiceOutstandingByCustomerForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return view('reports.exports.invoice-outstanding-by-customer-print', [
            'companyPad' => $companyPad,
            'reportTitle' => 'Invoice outstanding by customer',
            'subtitle' => $subtitle,
            'rows' => $payload['rows'],
            'branchTotals' => $payload['branch_totals'],
            'kpis' => $payload['kpis'],
        ]);
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function exportOpenPurchaseOrders(Request $request, array $companyPad, string $generatedLine): BinaryFileResponse
    {
        $payload = $this->reportData->openPurchaseOrdersForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return Excel::download(
            new OpenPurchaseOrdersExport($payload['rows'], $companyPad, 'Open purchase orders', $subtitle),
            'open-purchase-orders-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printOpenPurchaseOrders(Request $request, array $companyPad, string $generatedLine): View
    {
        $payload = $this->reportData->openPurchaseOrdersForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return view('reports.exports.open-purchase-orders-print', [
            'companyPad' => $companyPad,
            'reportTitle' => 'Open purchase orders',
            'subtitle' => $subtitle,
            'rows' => $payload['rows'],
        ]);
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function exportSupplierDuesAndPayments(Request $request, array $companyPad, string $generatedLine): BinaryFileResponse
    {
        $payload = $this->reportData->supplierDuesAndPaymentsForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return Excel::download(
            new SupplierDuesAndPaymentsExport($payload['rows'], $companyPad, 'Supplier dues & payments', $subtitle),
            'supplier-dues-and-payments-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printSupplierDuesAndPayments(Request $request, array $companyPad, string $generatedLine): View
    {
        $payload = $this->reportData->supplierDuesAndPaymentsForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return view('reports.exports.supplier-dues-and-payments-print', [
            'companyPad' => $companyPad,
            'reportTitle' => 'Supplier dues & payments',
            'subtitle' => $subtitle,
            'rows' => $payload['rows'],
            'totalDue' => $payload['total_due'],
            'totalPaid' => $payload['total_paid'],
        ]);
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function exportSalesByDayBranch(Request $request, array $companyPad, string $generatedLine): BinaryFileResponse
    {
        $payload = $this->reportData->salesByDayBranchForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return Excel::download(
            new SalesByDayBranchExport($payload['rows'], $companyPad, 'Daily sales by branch', $subtitle),
            'sales-by-day-branch-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printSalesByDayBranch(Request $request, array $companyPad, string $generatedLine): View
    {
        $payload = $this->reportData->salesByDayBranchForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return view('reports.exports.sales-by-day-branch-print', [
            'companyPad' => $companyPad,
            'reportTitle' => 'Daily sales by branch',
            'subtitle' => $subtitle,
            'rows' => $payload['rows'],
            'kpis' => $payload['kpis'],
            'productTotals' => $payload['productTotals'],
        ]);
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function exportCustomerPaymentByBranch(Request $request, array $companyPad, string $generatedLine): BinaryFileResponse
    {
        $payload = $this->reportData->customerPaymentByBranchForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return Excel::download(
            new CollectorWorkbookExport(
                new CustomerPaymentByBranchExport($payload['rows'], $companyPad, 'Customer payments by branch', $subtitle),
                $payload['collectorTotals'],
                $companyPad,
                'Customer payments by branch — by collector',
                $subtitle,
            ),
            'customer-payment-by-branch-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printCustomerPaymentByBranch(Request $request, array $companyPad, string $generatedLine): View
    {
        $payload = $this->reportData->customerPaymentByBranchForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return view('reports.exports.customer-payment-by-branch-print', [
            'companyPad' => $companyPad,
            'reportTitle' => 'Customer payments by branch',
            'subtitle' => $subtitle,
            'rows' => $payload['rows'],
            'kpis' => $payload['kpis'],
            'collectorTotals' => $payload['collectorTotals'],
        ]);
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function exportDailyExpenseByBranch(Request $request, array $companyPad, string $generatedLine): BinaryFileResponse
    {
        $payload = $this->reportData->dailyExpenseByBranchForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return Excel::download(
            new DailyExpenseByBranchExport($payload['rows'], $companyPad, 'Daily expenses by branch', $subtitle),
            'daily-expenses-by-branch-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printDailyExpenseByBranch(Request $request, array $companyPad, string $generatedLine): View
    {
        $payload = $this->reportData->dailyExpenseByBranchForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return view('reports.exports.daily-expenses-by-branch-print', [
            'companyPad' => $companyPad,
            'reportTitle' => 'Daily expenses by branch',
            'subtitle' => $subtitle,
            'rows' => $payload['rows'],
            'kpis' => $payload['kpis'],
            'detailTotals' => $payload['detailTotals'],
        ]);
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function exportBranchTargets(Request $request, array $companyPad, string $generatedLine): BinaryFileResponse
    {
        $payload = $this->branchTargetsReport->report($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return Excel::download(
            new BranchTargetsExport(
                $payload['rows'],
                $companyPad,
                'Branch targets',
                $subtitle,
                $payload['showMonthly'],
                $payload['showAnnual'],
                $payload['monthLabel'],
                (int) $payload['filters']['year'],
            ),
            'branch-targets-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printBranchTargets(Request $request, array $companyPad, string $generatedLine): View
    {
        $payload = $this->branchTargetsReport->report($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return view('reports.exports.branch-targets-print', [
            'companyPad' => $companyPad,
            'reportTitle' => 'Branch targets',
            'subtitle' => $subtitle,
            'rows' => $payload['rows'],
            'kpis' => $payload['kpis'],
            'filters' => $payload['filters'],
            'monthLabel' => $payload['monthLabel'],
            'showMonthly' => $payload['showMonthly'],
            'showAnnual' => $payload['showAnnual'],
        ]);
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printProfitLossSheet(Request $request, array $companyPad, string $generatedLine): View
    {
        $payload = $this->reportData->profitLossSheetForExport($request);

        return view('reports.exports.profit-loss-sheet-print', array_merge($payload, [
            'companyPad' => $companyPad,
        ]));
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printIncomeExpenditureStatement(Request $request, array $companyPad, string $generatedLine): View
    {
        $payload = $this->reportData->incomeExpenditureStatementForExport($request);

        return view('reports.exports.income-expenditure-statement-print', array_merge($payload, [
            'companyPad' => $companyPad,
        ]));
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function exportMonthToDateSales(Request $request, array $companyPad, string $generatedLine): BinaryFileResponse
    {
        $payload = $this->reportData->monthToDateSalesForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return Excel::download(
            new MonthToDateSalesExport(
                $payload['rows'],
                $companyPad,
                'Month to Date Sales',
                $subtitle,
                $payload['summaryByBranch'],
                $payload['summaryByType'],
            ),
            'month-to-date-sales-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printMonthToDateSales(Request $request, array $companyPad, string $generatedLine): View
    {
        $payload = $this->reportData->monthToDateSalesForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return view('reports.exports.month-to-date-sales-print', [
            'companyPad' => $companyPad,
            'reportTitle' => 'Month to Date Sales',
            'subtitle' => $subtitle,
            'rows' => $payload['rows'],
            'kpis' => $payload['kpis'],
            'summaryByBranch' => $payload['summaryByBranch'],
            'summaryByType' => $payload['summaryByType'],
        ]);
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function exportItemWiseSales(Request $request, array $companyPad, string $generatedLine): BinaryFileResponse
    {
        $payload = $this->salesReportService->itemWiseSalesForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return Excel::download(
            new ItemWiseSalesExport($payload['rows'], $companyPad, 'Item-wise Sales Report', $subtitle),
            'item-wise-sales-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printItemWiseSales(Request $request, array $companyPad, string $generatedLine): View
    {
        $payload = $this->salesReportService->itemWiseSalesForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return view('reports.exports.item-wise-sales-print', [
            'companyPad' => $companyPad,
            'reportTitle' => 'Item-wise Sales Report',
            'subtitle' => $subtitle,
            'rows' => $payload['rows'],
        ]);
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function exportRepresentativeWiseSales(Request $request, array $companyPad, string $generatedLine): BinaryFileResponse
    {
        $payload = $this->salesReportService->representativeWiseSalesForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return Excel::download(
            new RepresentativeWiseSalesExport(
                $payload['rows'],
                $payload['invoiceDetailsByReferrer'],
                $companyPad,
                'Representative-wise Sales Report',
                $subtitle,
            ),
            'representative-wise-sales-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printRepresentativeWiseSales(Request $request, array $companyPad, string $generatedLine): View
    {
        $payload = $this->salesReportService->representativeWiseSalesForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return view('reports.exports.representative-wise-sales-print', [
            'companyPad' => $companyPad,
            'reportTitle' => 'Representative-wise Sales Report',
            'subtitle' => $subtitle,
            'rows' => $payload['rows'],
            'invoiceDetailsByReferrer' => $payload['invoiceDetailsByReferrer'],
        ]);
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function exportItemWisePurchase(Request $request, array $companyPad, string $generatedLine): BinaryFileResponse
    {
        $payload = $this->purchaseReportService->itemWisePurchaseForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return Excel::download(
            new ItemWisePurchaseExport($payload['rows'], $companyPad, 'Item-wise Purchase Report', $subtitle),
            'item-wise-purchase-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printItemWisePurchase(Request $request, array $companyPad, string $generatedLine): View
    {
        $payload = $this->purchaseReportService->itemWisePurchaseForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return view('reports.exports.item-wise-purchase-print', [
            'companyPad' => $companyPad,
            'reportTitle' => 'Item-wise Purchase Report',
            'subtitle' => $subtitle,
            'rows' => $payload['rows'],
        ]);
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function exportCustomerJournal(Request $request, array $companyPad, string $generatedLine): BinaryFileResponse
    {
        $rows = $this->customerJournalRows($request);
        $customer = Customer::find((int) $request->input('customer_id'));
        $subtitle = ($customer ? $customer->name.' ('.$customer->customer_code.')' : 'No customer').' · '.$generatedLine;

        return Excel::download(
            new CustomerJournalExport($rows, $companyPad, 'Customer Journal', $subtitle),
            'customer-journal-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printCustomerJournal(Request $request, array $companyPad, string $generatedLine): View
    {
        $rows = $this->customerJournalRows($request);
        $customer = Customer::find((int) $request->input('customer_id'));
        $subtitle = ($customer ? $customer->name.' ('.$customer->customer_code.')' : 'No customer').' · '.$generatedLine;

        return view('reports.exports.customer-journal-print', [
            'companyPad' => $companyPad,
            'reportTitle' => 'Customer Journal',
            'subtitle' => $subtitle,
            'rows' => $rows,
            'customer' => $customer,
        ]);
    }

    /**
     * @return Collection<int, AccountsReceivableLedger>
     */
    private function customerJournalRows(Request $request): Collection
    {
        $customerId = (int) $request->input('customer_id');
        if ($customerId < 1) {
            return collect();
        }

        $dateFrom = (string) $request->input('date_from', '');
        $dateTo = (string) $request->input('date_to', '');

        $query = AccountsReceivableLedger::query()
            ->where('customer_id', $customerId)
            ->when($dateFrom !== '', fn ($q) => $q->where('event_time', '>=', $dateFrom.' 00:00:00'))
            ->when($dateTo !== '', fn ($q) => $q->where('event_time', '<=', $dateTo.' 23:59:59'))
            ->orderBy('event_time')
            ->orderBy('id');

        $rows = $query->get();

        $openingBalance = 0.0;
        if ($dateFrom !== '') {
            $openingBalance = (float) AccountsReceivableLedger::query()
                ->where('customer_id', $customerId)
                ->where('event_time', '<', $dateFrom.' 00:00:00')
                ->sum('amount_delta');
        }

        $runningBalance = $openingBalance;

        return $rows->map(function ($row) use (&$runningBalance) {
            $delta = (float) $row->amount_delta;
            $runningBalance += $delta;
            $row->running_balance = $runningBalance;
            $row->debit_amount = $delta > 0 ? $delta : 0.0;
            $row->credit_amount = $delta < 0 ? abs($delta) : 0.0;

            return $row;
        });
    }

    public function customerJournal(Request $request): View
    {
        $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $filters = [
            'customer_id' => (string) $request->input('customer_id', ''),
            'date_from' => (string) $request->input('date_from', ''),
            'date_to' => (string) $request->input('date_to', ''),
        ];

        $customers = Customer::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'customer_code', 'name', 'phone']);

        $rows = collect();
        $kpis = [
            'entries' => 0,
            'total_debit' => 0.0,
            'total_credit' => 0.0,
            'closing_balance' => 0.0,
        ];
        $selectedCustomer = null;

        if ($filters['customer_id'] !== '') {
            $rows = $this->customerJournalRows($request);
            $selectedCustomer = $customers->firstWhere('id', (int) $filters['customer_id']);

            $totalDebit = (float) $rows->sum('debit_amount');
            $totalCredit = (float) $rows->sum('credit_amount');
            $closingBalance = $rows->isNotEmpty() ? (float) $rows->last()->running_balance : 0.0;

            $openingBalance = 0.0;
            if ($filters['date_from'] !== '') {
                $openingBalance = (float) AccountsReceivableLedger::query()
                    ->where('customer_id', (int) $filters['customer_id'])
                    ->where('event_time', '<', $filters['date_from'].' 00:00:00')
                    ->sum('amount_delta');
            }

            $kpis = [
                'entries' => $rows->count(),
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'opening_balance' => $openingBalance,
                'closing_balance' => $closingBalance,
            ];
        }

        return view('reports.customer-journal', [
            'rows' => $rows,
            'filters' => $filters,
            'kpis' => $kpis,
            'customers' => $customers,
            'selectedCustomer' => $selectedCustomer,
        ]);
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function exportMonthlyCollection(Request $request, array $companyPad, string $generatedLine): BinaryFileResponse
    {
        $payload = $this->reportData->monthlyCollectionForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return Excel::download(
            new MonthlyCollectionExcelExport(
                $payload['thisMonthInstallments'],
                $payload['thisMonthCredits'],
                $payload['overdueInstallments'],
                $payload['overdueCredits'],
                $companyPad,
                $subtitle,
                $payload['periodPaymentsByInvoiceId'],
                $payload['maturedOverdueInstallments'],
                $payload['collectorsByInvoiceId'],
                $this->reportData->monthlyCollectionTotals($request)['collector_totals'],
            ),
            'monthly-collection-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printMonthlyCollection(Request $request, array $companyPad, string $generatedLine): View
    {
        $payload = $this->reportData->monthlyCollectionForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;
        $filters = $payload['filters'];
        $collectionBranchLabel = (($filters['branch_id'] ?? '') !== '')
            ? Location::query()->whereKey((int) $filters['branch_id'])->value('name')
            : null;

        return view('reports.exports.monthly-collection-print', [
            'companyPad' => $companyPad,
            'reportTitle' => 'Monthly Collection',
            'subtitle' => $subtitle,
            'thisMonthInstallmentRows' => $payload['thisMonthInstallments'],
            'thisMonthCreditRows' => $payload['thisMonthCredits'],
            'overdueInstallmentRows' => $payload['overdueInstallments'],
            'overdueCreditRows' => $payload['overdueCredits'],
            'maturedOverdueInstallmentRows' => $payload['maturedOverdueInstallments'],
            'collectionBranchLabel' => $collectionBranchLabel,
            'periodPaymentsByInvoiceId' => $payload['periodPaymentsByInvoiceId'],
            'collectorsByInvoiceId' => $payload['collectorsByInvoiceId'],
            'summary' => $this->reportData->monthlyCollectionTotals($request),
        ]);
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function exportWalletTransactions(Request $request, array $companyPad, string $generatedLine): BinaryFileResponse
    {
        abort_unless($request->user()?->hasPermission('wallet.view'), 403);

        $payload = $this->reportData->walletTransactionsForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return Excel::download(
            new WalletTransactionsExport(
                $payload['rows'],
                $payload['openingBalancesByBranch'],
                $payload['summaryByBranch'],
                $companyPad,
                'Wallet Transactions',
                $subtitle,
                $payload['summaryBySource'],
            ),
            'wallet-transactions-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printWalletTransactions(Request $request, array $companyPad, string $generatedLine): View
    {
        abort_unless($request->user()?->hasPermission('wallet.view'), 403);

        $payload = $this->reportData->walletTransactionsForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;

        return view('reports.exports.wallet-transactions-print', [
            'companyPad' => $companyPad,
            'reportTitle' => 'Wallet Transactions',
            'subtitle' => $subtitle,
            'rows' => $payload['rows'],
            'openingBalancesByBranch' => $payload['openingBalancesByBranch'],
            'summaryByBranch' => $payload['summaryByBranch'],
            'summaryBySource' => $payload['summaryBySource'],
        ]);
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function exportTransferLedger(Request $request, array $companyPad, string $generatedLine): BinaryFileResponse
    {
        $payload = $this->reportData->transferLedgerForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;
        if ($payload['truncated']) {
            $subtitle .= ' · Showing first '.ReportDataService::STOCK_EXPORT_LIMIT.' of '.$payload['totalMatching'].' rows';
        }

        return Excel::download(
            new TransferLedgerExport($payload['rows'], $companyPad, 'Transfer ledger', $subtitle),
            'transfer-ledger-'.now()->format('Ymd-His').'.xlsx'
        );
    }

    /**
     * @param  array<string, string>  $companyPad
     */
    private function printTransferLedger(Request $request, array $companyPad, string $generatedLine): View
    {
        $payload = $this->reportData->transferLedgerForExport($request);
        $subtitle = $payload['filterSummary'].' · '.$generatedLine;
        if ($payload['truncated']) {
            $subtitle .= ' · Showing first '.ReportDataService::STOCK_EXPORT_LIMIT.' of '.$payload['totalMatching'].' rows';
        }

        return view('reports.exports.transfer-ledger-print', [
            'companyPad' => $companyPad,
            'reportTitle' => 'Transfer ledger',
            'subtitle' => $subtitle,
            'rows' => $payload['rows'],
            'kpis' => $payload['kpis'],
        ]);
    }
}
