<?php

return [
    'grace_days' => 3, // paid <= due + grace counts as on time
    'late_bands' => [7, 30, 60, 90], // display buckets: 1-7, 8-30, 31-60, 61-90, 90+

    'trend' => [
        'recent_window_months' => 6,
        'min_lates_in_window' => 3, // >= 3 lates in the window => trend
        'slope_threshold' => 1.0, // days-late per installment (least-squares slope)
        'slope_sample' => 8, // last N matured installments used for the slope
        'streak_threshold' => 2, // >= 2 consecutive lates => trend
    ],

    'weights' => ['on_time' => 40, 'avg_late' => 20, 'max_late' => 10, 'recent' => 15, 'overdue' => 10, 'defaults' => 5],

    'grades' => ['A' => 85, 'B' => 70, 'C' => 55, 'D' => 40], // else E

    'trend_max_grade' => 'C',

    // High-Risk Customers report: grade in these OR classification = trend
    'high_risk_grades' => ['D', 'E'],

    'eligibility' => [
        'A' => ['max_amount_multiplier' => 1.5, 'max_tenure_months' => 12, 'min_down_payment_pct' => 10],
        'B' => ['max_amount_multiplier' => 1.0, 'max_tenure_months' => 9, 'min_down_payment_pct' => 20],
        'C' => ['max_amount_multiplier' => 0.6, 'max_tenure_months' => 6, 'min_down_payment_pct' => 30],
        'D' => ['max_amount_multiplier' => 0.3, 'max_tenure_months' => 3, 'min_down_payment_pct' => 50],
        'E' => ['max_amount_multiplier' => 0, 'max_tenure_months' => 0, 'min_down_payment_pct' => 100],
    ],

    'chart_months' => 12,
];
