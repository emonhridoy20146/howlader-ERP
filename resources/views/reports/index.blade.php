@extends('layouts.app')
@section('title', 'Reports Hub')
@section('page-title', 'Reports Hub')
@section('content')

@php
	$stockKeys = ['q', 'location_id', 'stock_state', 'serialized', 'as_of_date', 'as_of_time'];
	$branchInStockPricingKeys = ['q', 'location_id', 'category_id', 'as_of_date', 'as_of_time'];
	$salesKeys = ['q', 'date_from', 'date_to', 'branch_id', 'payment_status', 'sale_type'];
	$lowStockKeys = ['q', 'location_id', 'as_of_date', 'as_of_time'];
	$customerDuesKeys = ['q'];
	$openPoKeys = ['q', 'status', 'supplier_id', 'location_id', 'date_from', 'date_to'];
	$itemWiseSalesKeys = ['q', 'date_from', 'date_to', 'branch_id'];
	$repWiseSalesKeys = ['q', 'date_from', 'date_to', 'branch_id', 'representative_id'];
	$itemWisePurchaseKeys = ['q', 'date_from', 'date_to', 'location_id'];
	$invoiceOutstandingByCustomerKeys = ['q', 'branch_id'];
	$monthlyCollectionKeys = ['q', 'branch_id', 'date_from', 'date_to'];
	$walletTransactionsKeys = ['wallet_id', 'date_from', 'date_to', 'source'];
	$transferLedgerKeys = ['q', 'date_from', 'date_to', 'source_location_id', 'destination_location_id', 'location_id', 'movement_type'];
	$customerPaymentByBranchKeys = ['date_from', 'date_to', 'branch_id'];
	$dailyExpenseByBranchKeys = ['date_from', 'date_to', 'branch_id', 'ledger_account_id'];
	$branchTargetsKeys = ['year', 'month', 'branch_id', 'view'];
	$financialReportKeys = ['date_from', 'date_to', 'branch_id'];

    $reports = [
        'sales' => [
            'title' => 'Sales & Orders',
            'items' => [
                ['id' => 'sales-summary', 'title' => 'Sales Summary', 'desc' => 'Overview of all sales transactions and revenue.', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />'],
                ['id' => 'month-to-date-sales', 'title' => 'Month to Date Sales', 'desc' => 'Current month sales performance.', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />'],
                ['id' => 'sales-by-day-branch', 'title' => 'Daily sales by branch', 'desc' => 'Product lines by day and branch; summary cards show invoice totals (posted & active).', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v11.25M17.25 3v11.25M3 18.75V4.875C3 4.254 3.504 3.75 4.125 3.75h15.75c.621 0 1.125.504 1.125 1.125V18.75M3 18.75h.008v.008H3v-.008ZM12 9.75v4.5m2.25-2.25h-4.5" />'],
                ['id' => 'item-wise-sales', 'title' => 'Item-wise Sales', 'desc' => 'Sales volume and revenue by product.', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />'],
                ['id' => 'representative-wise-sales', 'title' => 'Referrer-wise Sales', 'desc' => 'Sales performance broken down by referring staff member.', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />'],
                ['id' => 'customer-dues', 'title' => 'Customer Dues', 'desc' => 'Outstanding balances by customer.', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 8.25H9m6 3H9m3 6-3-3h1.5a3 3 0 1 0 0-6M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />'],
            ]
        ],
        'purchasing' => [
            'title' => 'Purchasing & Inventory',
            'items' => [
                ['id' => 'stock-summary', 'title' => 'Stock summary', 'desc' => 'Products by branch with on-hand quantities and stock value. A category includes its subcategories.', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0h7.5m-7.5 0-1 3m8.5-3 1 3m0 0 .5 1.5m-.5-1.5h-9.5m0 0-.5 1.5M9 11.25v1.5M12 9v3.75m-3-3v3.75m3-3h3.75m-3.75 0h-3.75" />'],
                ['id' => 'stock-by-location', 'title' => 'Stock by Location', 'desc' => 'Current inventory levels across all branches.', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z" />'],
                ['id' => 'branch-in-stock-pricing', 'title' => 'Branch in-stock pricing', 'desc' => 'In-stock products by branch with MRP and maximum discount by category.', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75a.75.75 0 0 1-.75.75h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M3.75 4.5h1.007M3.75 4.5v-.875A2.625 2.625 0 0 1 6.375 1.5h9.25a2.625 2.625 0 0 1 2.625 2.625V4.5M18 4.5h.008v.008H18V4.5Zm-3.75 6.75h1.875a2.625 2.625 0 0 1 2.625 2.625V15a2.625 2.625 0 0 1-2.625 2.625H4.875A2.625 2.625 0 0 1 2.25 15V11.625a2.625 2.625 0 0 1 2.625-2.625H6.75" />'],
                ['id' => 'low-stock', 'title' => 'Low Stock Alerts', 'desc' => 'Items that have fallen below their reorder level.', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />'],
                ['id' => 'open-purchase-orders', 'title' => 'Open Purchase Orders', 'desc' => 'Pending orders from suppliers.', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12" />'],
                ['id' => 'supplier-dues-and-payments', 'title' => 'Supplier dues & payments', 'desc' => 'Amount due versus payments recorded per supplier (booked POs).', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75a.75.75 0 0 1-.75.75H2.25M3.75 4.5h1.007M3.75 4.5v-.875A2.625 2.625 0 0 1 6.375 1.5h9.25a2.625 2.625 0 0 1 2.625 2.625V4.5M18 4.5h.008v.008H18V4.5Zm-2.25 4.5h1.875a2.625 2.625 0 0 1 2.625 2.625V15a2.625 2.625 0 0 1-2.625 2.625H4.875A2.625 2.625 0 0 1 2.25 15V11.625a2.625 2.625 0 0 1 2.625-2.625H6.75" />'],
                ['id' => 'item-wise-purchase', 'title' => 'Item-wise Purchases', 'desc' => 'Purchase volume and cost by product.', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />'],
                ['id' => 'transfer-ledger', 'title' => 'Transfer ledger', 'desc' => 'Stock ledger entries for inter-branch transfers (issue and receive).', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" />'],
            ]
        ],
        'finance' => [
            'title' => 'Financing',
            'items' => [    
                ['id' => 'credit-overdue', 'title' => 'Overdue receivables', 'desc' => 'Past-due credit invoices and overdue installments.', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75a.75.75 0 0 1-.75.75H3.75a.75.75 0 0 1-.75-.75V5.25m19.5 0a.75.75 0 0 0-.75-.75H5.25a.75.75 0 0 0-.75.75v10.5c0 .621.504 1.125 1.125 1.125h2.25a.75.75 0 0 0 .75-.75v-6.75a.75.75 0 0 1 1.5 0v6.75a.75.75 0 0 0 .75.75H9a.75.75 0 0 0 .75-.75V9a.75.75 0 0 1 1.5 0v6.75a.75.75 0 0 0 .75.75h1.5a.75.75 0 0 0 .75-.75v-4.5Z" />'],
                ['id' => 'installment-duration', 'title' => 'Installment duration', 'desc' => 'Planned vs. actual payoff time per installment plan — which ones ran longer than scheduled.', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />'],
                ['id' => 'invoice-outstanding-by-customer', 'title' => 'Invoice outstanding by customer', 'desc' => 'Open invoices: lines with products & serials, invoice dates, and balances (Excel & print).', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m9 9v.75m0-9h.008v.008H17.25V6ZM9.75 9h.008v.008H9.75V9Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm3.75 0h.008v.008H13.5V9Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />'],
                ['id' => 'customer-journal', 'title' => 'Customer Journal', 'desc' => 'Accounts receivable ledger for a specific customer with running balance by date.', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />'],
                ['id' => 'high-risk-customers', 'title' => 'High-Risk Customers', 'desc' => 'Customers with D/E grade or a late-payment trend, with overdue, days late and guarantor (Print / PDF / Excel).', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />'],
                ['id' => 'customer-risk', 'title' => 'Customer Profile & Risk', 'desc' => 'Full customer profile, guarantors, installment punctuality, late trend, risk grade and eligibility (Print / PDF / Excel).', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />'],
                ['id' => 'yearly-collection-sheet', 'title' => 'Yearly Collection Sheet', 'desc' => 'Unpaid installment invoices by customer with month-by-month paid/missed grid across a year range (Print / PDF / Excel).', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 0 1-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0 1 12 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25M3.375 8.25h7.5c.621 0 1.125.504 1.125 1.125" />'],
                ['id' => 'monthly-collection', 'title' => 'Monthly Collection', 'desc' => 'This month\'s installment and credit dues, plus overdue installments and past-due credits (with default sale prices).', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />'],
                ['id' => 'customer-payment-by-branch', 'title' => 'Customer payments by branch', 'desc' => 'Inbound customer receipts allocated to invoices, by branch and payment date.', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75a.75.75 0 0 1-.75.75h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M3.75 4.5h1.007M3.75 4.5v-.875A2.625 2.625 0 0 1 6.375 1.5h9.25a2.625 2.625 0 0 1 2.625 2.625V4.5M18 4.5h.008v.008H18V4.5Zm-3.75 6.75h1.875a2.625 2.625 0 0 1 2.625 2.625V15a2.625 2.625 0 0 1-2.625 2.625H4.875A2.625 2.625 0 0 1 2.25 15V11.625a2.625 2.625 0 0 1 2.625-2.625H6.75" />'],
                ['id' => 'wallet-transactions', 'title' => 'Wallet Transactions', 'desc' => 'View transaction history of a specific wallet with debit and credit details.', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M21 12a2.25 2.25 0 0 0-2.25-2.25H15a3 3 0 1 1-6 0H5.25A2.25 2.25 0 0 0 3 12m18 0v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 9m18 0V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v3" />'],
                ['id' => 'daily-expenses-by-branch', 'title' => 'Daily expenses by branch', 'desc' => 'Posted manual operating expenses by entry date, branch, and expense account.', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />'],
                ['id' => 'branch-targets', 'title' => 'Branch targets', 'desc' => 'Monthly and annual sales & collection target progress by branch.', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />'],
                ['id' => 'profit-loss-sheet', 'title' => 'Profit loss sheet', 'desc' => 'Sales, cost of goods sold, operating expenses, and net profit by branch or all branches.', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />'],
                ['id' => 'income-expenditure-statement', 'title' => 'Income & expenditure', 'desc' => 'Posted journal income and expense accounts by branch or all branches.', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0h7.5m-7.5 0-1 3m8.5-3 1 3" />'],
            ]
        ]
    ];

    if (! auth()->user()?->hasPermission('wallet.view')) {
        $reports['finance']['items'] = array_values(array_filter(
            $reports['finance']['items'],
            fn (array $item): bool => ($item['id'] ?? '') !== 'wallet-transactions',
        ));
    }

    /** Only surface the report subjects this user is allowed to open. */
    foreach ($reports as $groupKey => $group) {
        $reports[$groupKey]['items'] = array_values(array_filter(
            $group['items'],
            fn (array $item): bool => auth()->user()?->can('view-report', $item['id'] ?? '') ?? false,
        ));

        if ($reports[$groupKey]['items'] === []) {
            unset($reports[$groupKey]);
        }
    }
@endphp

<div x-data="{
    open: false,
    activeReportId: null,
    activeReportTitle: '',

    openReport(id, title) {
        this.activeReportId = id;
        this.activeReportTitle = title;
        this.open = true;
    },
    
    closeOffcanvas() {
        this.open = false;
        setTimeout(() => { this.activeReportId = null; }, 300);
    },

    getExportAction() {
        if (this.activeReportId === 'customer-risk') return '{{ route('reports.customer-risk', ['to' => 'excel']) }}';
        return '{{ url('reports') }}/' + this.activeReportId + '/export';
    },

    getPrintAction() {
        if (this.activeReportId === 'customer-risk') return '{{ route('reports.customer-risk', ['to' => 'print']) }}';
        return '{{ url('reports') }}/' + this.activeReportId + '/print';
    },

    getViewAction() {
        if (this.activeReportId === 'stock-by-location') return '{{ route('reports.stock-by-location') }}';
        if (this.activeReportId === 'branch-in-stock-pricing') return '{{ route('reports.branch-in-stock-pricing') }}';
        if (this.activeReportId === 'stock-summary') return '{{ route('reports.stock-summary') }}';
        if (this.activeReportId === 'sales-summary') return '{{ route('reports.sales-summary') }}';
        if (this.activeReportId === 'month-to-date-sales') return '{{ route('reports.month-to-date-sales') }}';
        if (this.activeReportId === 'item-wise-sales') return '{{ route('reports.item-wise-sales') }}';
        if (this.activeReportId === 'representative-wise-sales') return '{{ route('reports.representative-wise-sales') }}';
        if (this.activeReportId === 'item-wise-purchase') return '{{ route('reports.item-wise-purchase') }}';
        if (this.activeReportId === 'installment-overdue') return '{{ route('reports.installment-overdue') }}';
        if (this.activeReportId === 'credit-overdue') return '{{ route('reports.credit-overdue') }}';
        if (this.activeReportId === 'installment-duration') return '{{ route('reports.installment-duration') }}';
        if (this.activeReportId === 'low-stock') return '{{ route('reports.low-stock') }}';
        if (this.activeReportId === 'customer-dues') return '{{ route('reports.customer-dues') }}';
        if (this.activeReportId === 'invoice-outstanding-by-customer') return '{{ route('reports.invoice-outstanding-by-customer') }}';
        if (this.activeReportId === 'open-purchase-orders') return '{{ route('reports.open-purchase-orders') }}';
        if (this.activeReportId === 'supplier-dues-and-payments') return '{{ route('reports.supplier-dues-and-payments') }}';
        if (this.activeReportId === 'sales-by-day-branch') return '{{ route('reports.sales-by-day-branch') }}';
        if (this.activeReportId === 'customer-payment-by-branch') return '{{ route('reports.customer-payment-by-branch') }}';
        if (this.activeReportId === 'daily-expenses-by-branch') return '{{ route('reports.daily-expenses-by-branch') }}';
        if (this.activeReportId === 'branch-targets') return '{{ route('reports.branch-targets') }}';
        if (this.activeReportId === 'profit-loss-sheet') return '{{ route('reports.profit-loss-sheet') }}';
        if (this.activeReportId === 'income-expenditure-statement') return '{{ route('reports.income-expenditure-statement') }}';
        if (this.activeReportId === 'customer-risk') return '{{ route('reports.customer-risk') }}';
        if (this.activeReportId === 'yearly-collection-sheet') return '{{ route('reports.yearly-collection-sheet') }}';
        if (this.activeReportId === 'high-risk-customers') return '{{ route('reports.high-risk-customers') }}';
        if (this.activeReportId === 'customer-journal') return '{{ route('reports.customer-journal') }}';
        if (this.activeReportId === 'monthly-collection') return '{{ route('reports.monthly-collection') }}';
        if (this.activeReportId === 'wallet-transactions') return '{{ route('reports.wallet-transactions') }}';
        if (this.activeReportId === 'transfer-ledger') return '{{ route('reports.transfer-ledger') }}';

        return '#';
    },

    submitExport(format) {
        const f = document.getElementById('offcanvas-report-form');
        let fmt = f.querySelector('input[name=format]');
        if (! fmt) {
            fmt = document.createElement('input');
            fmt.type = 'hidden';
            fmt.name = 'format';
            f.appendChild(fmt);
        }
        fmt.disabled = false;
        fmt.value = format;
        f.method = 'get';
        f.target = '';
        f.action = this.getExportAction();
        f.submit();
    },

    submitPrint() {
        const f = document.getElementById('offcanvas-report-form');
        const fmt = f.querySelector('input[name=format]');
        if (fmt) {
            fmt.disabled = true;
        }
        f.method = 'get';
        f.target = '_blank';
        f.action = this.getPrintAction();
        f.submit();
        f.target = '';
        if (fmt) {
            fmt.disabled = false;
        }
    },

    submitViewReport() {
        const f = document.getElementById('offcanvas-report-form');
        const fmt = f.querySelector('input[name=format]');
        if (fmt) {
            fmt.disabled = true;
        }
        f.method = 'get';
        f.target = '';
        f.action = this.getViewAction();
        f.submit();
        if (fmt) {
            fmt.disabled = false;
        }
    }
}">

    <div class="space-y-8">
        @foreach($reports as $category)
            <div>
                <h3 class="mb-4 text-sm font-semibold uppercase tracking-wider text-slate-500">{{ $category['title'] }}</h3>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach($category['items'] as $item)
                        <button type="button" @click="openReport('{{ $item['id'] }}', '{{ addslashes($item['title']) }}')" class="flex flex-col text-left rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition-all hover:-translate-y-0.5 hover:border-emerald-300 hover:shadow-md hover:ring-1 hover:ring-emerald-200 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
                            <div class="mb-4 inline-flex h-12 w-12 items-center justify-center rounded-xl bg-slate-50 text-slate-600 shadow-sm ring-1 ring-inset ring-slate-200/50">
                                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    {!! $item['icon'] !!}
                                </svg>
                            </div>
                            <h4 class="text-base font-semibold text-slate-800">{{ $item['title'] }}</h4>
                            <p class="mt-1 text-sm text-slate-500">{{ $item['desc'] }}</p>
                        </button>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    <!-- Offcanvas Overlay -->
    <div x-cloak x-show="open" class="fixed inset-0 z-[60] bg-slate-900/40 backdrop-blur-sm transition-opacity" @click="closeOffcanvas()"></div>

    <!-- Offcanvas Panel -->
    <div x-cloak x-show="open" 
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="translate-x-full"
         x-transition:enter-end="translate-x-0"
         x-transition:leave="transition ease-in duration-300"
         x-transition:leave-start="translate-x-0"
         x-transition:leave-end="translate-x-full"
         class="fixed inset-y-0 right-0 z-[70] w-full max-w-md bg-white shadow-2xl flex flex-col border-l border-slate-200">
        
        <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 px-6 py-5">
            <h2 class="text-lg font-semibold text-slate-800" x-text="activeReportTitle + ' Filters'"></h2>
            <button @click="closeOffcanvas()" class="rounded-full p-2 text-slate-400 hover:bg-slate-200 hover:text-slate-700 focus:outline-none transition-colors">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-6">
            <form id="offcanvas-report-form" method="get" :action="getViewAction()">

				{{-- Use x-if so only the selected report fields exist: x-show kept duplicate name="q" inputs in the form, so exports/views picked up the wrong params. --}}
				<template x-if="activeReportId === 'stock-by-location'">
					<div>
						@include('reports.partials.stock-filter-fields', ['filters' => $stockFilters, 'filterLocations' => $filterLocations])
					</div>
				</template>
				<template x-if="activeReportId === 'branch-in-stock-pricing'">
					<div>
						@include('reports.partials.branch-in-stock-pricing-filter-fields', [
							'filters' => $branchInStockPricingFilters,
							'filterLocations' => $filterLocations,
							'filterCategories' => $stockSummaryFilterCategories,
						])
					</div>
				</template>
				<template x-if="activeReportId === 'stock-summary'">
					<div>
						@include('reports.partials.stock-summary-filter-fields', [
							'filters' => $stockSummaryFilters,
							'filterLocations' => $filterLocations,
							'filterProducts' => $stockSummaryFilterProducts,
							'filterCategories' => $stockSummaryFilterCategories,
							'filterBrands' => $stockSummaryFilterBrands,
						])
					</div>
				</template>
				<template x-if="activeReportId === 'sales-summary'">
					<div>
						@include('reports.partials.sales-filter-fields', ['filters' => $salesFilters, 'filterBranches' => $filterBranches])
					</div>
				</template>
				<template x-if="activeReportId === 'installment-overdue'">
					<div class="rounded-lg border border-sky-100 bg-sky-50 p-4 text-sm text-sky-800">
						No filters required for this report. The export will use your current branch context.
					</div>
				</template>
				<template x-if="activeReportId === 'credit-overdue'">
					<div>
						@include('reports.partials.credit-overdue-filter-fields', ['filters' => $creditOverdueFilters, 'filterBranches' => $filterBranches])
					</div>
				</template>
				<template x-if="activeReportId === 'installment-duration'">
					<div>
						@include('reports.partials.installment-duration-filter-fields', ['filters' => $installmentDurationFilters, 'filterBranches' => $filterBranches])
					</div>
				</template>
				<template x-if="activeReportId === 'invoice-outstanding-by-customer'">
					<div>
						@include('reports.partials.invoice-outstanding-by-customer-filter-fields', ['filters' => $invoiceOutstandingByCustomerFilters, 'filterBranches' => $filterBranches])
					</div>
				</template>
				<template x-if="activeReportId === 'low-stock'">
					<div>
						@include('reports.partials.low-stock-filter-fields', ['filters' => $lowStockFilters, 'filterLocations' => $filterLocations])
					</div>
				</template>
				<template x-if="activeReportId === 'customer-dues'">
					<div>
						@include('reports.partials.customer-dues-filter-fields', ['filters' => $customerDuesFilters, 'filterBranches' => $filterBranches])
					</div>
				</template>
				<template x-if="activeReportId === 'item-wise-sales'">
					<div>
						@include('reports.partials.item-wise-sales-filter-fields', ['filters' => $itemWiseSalesFilters, 'filterBranches' => $filterBranches])
					</div>
				</template>
				<template x-if="activeReportId === 'month-to-date-sales'">
					<div>
						@include('reports.partials.sales-filter-fields', ['filters' => $salesFilters, 'filterBranches' => $filterBranches])
					</div>
				</template>
				<template x-if="activeReportId === 'representative-wise-sales'">
					<div>
						@include('reports.partials.representative-wise-sales-filter-fields', ['filters' => $representativeWiseSalesFilters, 'filterBranches' => $filterBranches, 'filterRepresentatives' => $filterRepresentatives])
					</div>
				</template>
				<template x-if="activeReportId === 'item-wise-purchase'">
					<div>
						@include('reports.partials.item-wise-purchase-filter-fields', ['filters' => $itemWisePurchaseFilters, 'filterLocations' => $filterLocations])
					</div>
				</template>
				<template x-if="activeReportId === 'open-purchase-orders'">
					<div>
						@include('reports.partials.open-purchase-orders-filter-fields', ['filters' => $openPoFilters, 'filterSuppliers' => $filterSuppliers, 'filterLocations' => $filterWarehouseLocations])
					</div>
				</template>
				<template x-if="activeReportId === 'supplier-dues-and-payments'">
					<div>
						@include('reports.partials.supplier-dues-and-payments-filter-fields', ['filters' => $supplierDuesAndPaymentsFilters, 'filterWarehouseLocations' => $filterWarehouseLocations])
					</div>
				</template>
				<template x-if="activeReportId === 'sales-by-day-branch'">
					<div>
						@include('reports.partials.sales-by-day-branch-filter-fields', ['filters' => $salesByDayBranchFilters, 'filterBranches' => $filterBranches])
					</div>
				</template>
				<template x-if="activeReportId === 'customer-payment-by-branch'">
					<div>
						@include('reports.partials.customer-payment-by-branch-filter-fields', ['filters' => $customerPaymentByBranchFilters, 'filterBranches' => $filterBranches])
					</div>
				</template>
				<template x-if="activeReportId === 'daily-expenses-by-branch'">
					<div>
						@include('reports.partials.daily-expenses-by-branch-filter-fields', ['filters' => $dailyExpenseByBranchFilters, 'filterBranches' => $filterBranches, 'filterExpenseAccounts' => $filterExpenseAccounts])
					</div>
				</template>
				<template x-if="activeReportId === 'branch-targets'">
					<div>
						@include('reports.partials.branch-targets-filter-fields', ['filters' => $branchTargetsFilters, 'filterBranches' => $filterBranches])
					</div>
				</template>
				<template x-if="activeReportId === 'profit-loss-sheet'">
					<div>
						@include('reports.partials.financial-report-filter-fields', ['filters' => $profitLossSheetFilters, 'filterBranches' => $filterBranches])
					</div>
				</template>
				<template x-if="activeReportId === 'income-expenditure-statement'">
					<div>
						@include('reports.partials.financial-report-filter-fields', ['filters' => $incomeExpenditureStatementFilters, 'filterBranches' => $filterBranches])
					</div>
				</template>
				<template x-if="activeReportId === 'high-risk-customers'">
					<div>
						@include('reports.partials.high-risk-customers-filter-fields', ['filters' => $highRiskFilters, 'filterBranches' => $filterBranches])
					</div>
				</template>
				<template x-if="activeReportId === 'yearly-collection-sheet'">
					<div>
						@include('reports.partials.yearly-collection-sheet-filter-fields', ['filters' => $yearlyCollectionFilters, 'filterBranches' => $filterBranches, 'collectors' => $yearlyCollectionCollectors])
					</div>
				</template>
				<template x-if="activeReportId === 'customer-risk'">
					<div>
						@include('reports.partials.customer-risk-filter-fields', ['filterCustomers' => $filterCustomers])
					</div>
				</template>
				<template x-if="activeReportId === 'customer-journal'">
					<div>
						@include('reports.partials.customer-journal-filter-fields', ['filters' => $customerJournalFilters, 'filterCustomers' => $filterCustomers])
					</div>
				</template>
				<template x-if="activeReportId === 'monthly-collection'">
					<div>
						@include('reports.partials.monthly-collection-filter-fields', ['filters' => $monthlyCollectionFilters, 'filterBranches' => $filterBranches])
					</div>
				</template>
				<template x-if="activeReportId === 'wallet-transactions'">
					<div>
						@include('reports.partials.wallet-transactions-filter-fields', ['filters' => $walletTransactionsFilters, 'filterWallets' => $filterWallets])
					</div>
				</template>
				<template x-if="activeReportId === 'transfer-ledger'">
					<div>
						@include('reports.partials.transfer-ledger-filter-fields', ['filters' => $transferLedgerFilters, 'filterLocations' => $filterLocations])
					</div>
				</template>

            </form>
        </div>

        <div class="border-t border-slate-200 bg-slate-50 px-6 py-5">
            <div class="flex flex-col gap-3">
                <div class="flex gap-3">
                    <button type="button" @click="submitPrint()" class="flex-1 flex items-center justify-center gap-2 rounded-xl bg-slate-800 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-slate-700 transition">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2m-4 0H6m0 0v-7h12v7m-9 3h6" /></svg>
                        Print
                    </button>
                    <button type="button" @click="submitExport('xlsx')" class="flex-1 flex items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500 transition">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                        Excel
                    </button>
                </div>
                <button type="button" @click="submitViewReport()" class="flex w-full items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 transition">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                    View Report on Screen
                </button>
            </div>
        </div>
    </div>
</div>

@endsection
