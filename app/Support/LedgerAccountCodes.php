<?php

namespace App\Support;

final class LedgerAccountCodes
{
    public const ACCOUNTS_RECEIVABLE = '1200-AR';

    /** Non-wallet debit side for backdated / backfill customer payments (wallet balance unchanged). */
    public const HISTORICAL_PAYMENT_CLEARING = '1250-HISTORICAL-PAYMENT';

    public const ACCOUNTS_PAYABLE = '2100-AP';

    public const PO_LOGISTICS_EXPENSE = '5310-PO-LOGISTICS';

    public const SALES_REVENUE = '4100-SALES';

    public const BAD_DEBT_EXPENSE = '6200-BAD-DEBT';

    /** Cash discount / settlement reduction applied when recording customer payment (not bad debt). */
    public const PAYMENT_SETTLEMENT_DISCOUNT = '6180-PAYMENT-DISCOUNT';

    /** Contra entry when recording initial cash/bank in a wallet (double-entry). */
    public const OPENING_BALANCE_EQUITY = '3900-OPENING-EQUITY';
}
