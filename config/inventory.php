<?php

return [
    'serial_statuses' => ['in_stock', 'reserved', 'sold', 'returned', 'damaged', 'in_transfer', 'under_service'],
    'transfer_statuses' => ['draft', 'approved', 'issued', 'received', 'cancelled'],
    'po_statuses' => ['draft', 'approved', 'partially_received', 'received', 'cancelled'],
    'sale_types' => ['default', 'credit', 'installment'],
    'payment_methods' => ['cash', 'bank', 'card', 'mobile_bank'],
    'low_stock_threshold_days' => 7,
    'price_override_min_margin' => 0.05,
    'installment_late_fee_percent' => 0.02,
    'max_credit_without_approval' => 50000,
    'max_discount_percent' => 5,
    /*
     * Internal product barcodes (Code 128): leading digit 9 + zero-padded sequence.
     * 8 digits total keeps labels compact on 25 mm thermal stock.
     */
    'barcode_length' => 8,
    'barcode_leading_digit' => '9',
    /** Compact numeric code on serialized unit labels (QR / Code 128). */
    'serial_label_code_digits' => 5,
    'owner_capital_wallet_code' => 'OWNER-0001',
];
