<?php

namespace Tests\Unit;

use App\Services\CustomerRiskService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class CustomerRiskServiceTest extends TestCase
{
    private function cfg(): array
    {
        return [
            'grace_days' => 3,
            'late_bands' => [7, 30, 60, 90],
            'trend' => ['recent_window_months' => 6, 'min_lates_in_window' => 3, 'slope_threshold' => 1.0, 'slope_sample' => 8, 'streak_threshold' => 2],
            'weights' => ['on_time' => 40, 'avg_late' => 20, 'max_late' => 10, 'recent' => 15, 'overdue' => 10, 'defaults' => 5],
            'grades' => ['A' => 85, 'B' => 70, 'C' => 55, 'D' => 40],
            'trend_max_grade' => 'C',
        ];
    }

    /** @param list<int> $days days-late per installment, oldest first; spaced monthly ending 1 month before asOf */
    private function series(array $days, Carbon $asOf): array
    {
        $n = count($days);
        $out = [];
        foreach ($days as $i => $d) {
            $out[] = ['due' => $asOf->copy()->subMonths($n - $i), 'days_late' => $d, 'is_late' => $d > 3];
        }

        return $out;
    }

    public function test_days_late_paid_on_time_late_unpaid_and_future(): void
    {
        $asOf = Carbon::parse('2026-06-30');
        $due = Carbon::parse('2026-06-01');

        $this->assertSame(0, CustomerRiskService::daysLate($due, Carbon::parse('2026-06-01'), $asOf));
        $this->assertSame(0, CustomerRiskService::daysLate($due, Carbon::parse('2026-05-20'), $asOf));
        $this->assertSame(10, CustomerRiskService::daysLate($due, Carbon::parse('2026-06-11'), $asOf));
        $this->assertSame(29, CustomerRiskService::daysLate($due, null, $asOf));
        $this->assertSame(0, CustomerRiskService::daysLate(Carbon::parse('2026-07-15'), null, $asOf));
    }

    public function test_bands_respect_grace(): void
    {
        $b = [7, 30, 60, 90];
        $this->assertSame('On time', CustomerRiskService::bandFor(3, 3, $b));
        $this->assertSame('1-7 days', CustomerRiskService::bandFor(5, 3, $b));
        $this->assertSame('8-30 days', CustomerRiskService::bandFor(10, 3, $b));
        $this->assertSame('90+ days', CustomerRiskService::bandFor(120, 3, $b));
    }

    public function test_single_spike_among_on_time_payments_is_isolated(): void
    {
        $asOf = Carbon::parse('2026-06-30');
        $days = [0, 0, 0, 0, 0, 20, 0, 0, 0, 0, 0, 0];
        $r = CustomerRiskService::classify($this->series($days, $asOf), $asOf, $this->cfg()['trend']);

        $this->assertSame('isolated', $r['classification']);
    }

    public function test_three_lates_in_six_months_is_trend(): void
    {
        $asOf = Carbon::parse('2026-06-30');
        $days = [0, 0, 0, 0, 0, 0, 10, 0, 12, 0, 9, 0];
        $r = CustomerRiskService::classify($this->series($days, $asOf), $asOf, $this->cfg()['trend']);

        $this->assertSame('trend', $r['classification']);
        $this->assertStringContainsString('late payments in the last 6 months', $r['reason']);
    }

    public function test_rising_days_late_is_trend_via_slope(): void
    {
        $asOf = Carbon::parse('2026-06-30');
        $cfg = $this->cfg()['trend'];
        $cfg['min_lates_in_window'] = 99;
        $cfg['streak_threshold'] = 99;
        $r = CustomerRiskService::classify($this->series([0, 0, 0, 2, 5, 9, 14], $asOf), $asOf, $cfg);

        $this->assertSame('trend', $r['classification']);
        $this->assertGreaterThanOrEqual(1.0, $r['slope']);
        $this->assertStringContainsString('rising', $r['reason']);
    }

    public function test_consecutive_late_streak_is_trend(): void
    {
        $asOf = Carbon::parse('2026-06-30');
        $cfg = $this->cfg()['trend'];
        $cfg['min_lates_in_window'] = 99;
        $cfg['slope_threshold'] = 99;
        $r = CustomerRiskService::classify($this->series([0, 0, 0, 0, 0, 0, 0, 8, 9], $asOf), $asOf, $cfg);

        $this->assertSame('trend', $r['classification']);
        $this->assertSame(2, $r['current_streak']);
    }

    public function test_no_lates_is_none_and_decreasing_is_improving(): void
    {
        $asOf = Carbon::parse('2026-06-30');
        $this->assertSame('none', CustomerRiskService::classify($this->series([0, 1, 0, 2], $asOf), $asOf, $this->cfg()['trend'])['classification']);

        $r = CustomerRiskService::classify($this->series([20, 15, 10, 5, 2, 0], $asOf), $asOf, $this->cfg()['trend']);
        $this->assertTrue($r['improving']);
    }

    public function test_score_and_grade(): void
    {
        $cfg = $this->cfg();
        $perfect = CustomerRiskService::score([
            'matured' => 12, 'on_time_rate' => 1.0, 'avg_days_late' => 0.0, 'max_days_late' => 0,
            'late_6m' => 0, 'overdue_amount' => 0.0, 'outstanding' => 500.0, 'has_default' => false,
        ], $cfg['weights']);

        $this->assertSame(100.0, $perfect['total']);
        $this->assertSame('A', CustomerRiskService::grade($perfect['total'], false, false, $cfg));
        $this->assertSame('C', CustomerRiskService::grade($perfect['total'], true, false, $cfg));
        $this->assertSame('E', CustomerRiskService::grade($perfect['total'], false, true, $cfg));
    }

    public function test_no_history_has_no_score_and_na_grade(): void
    {
        $cfg = $this->cfg();
        $score = CustomerRiskService::score(['matured' => 0, 'on_time_rate' => null], $cfg['weights']);

        $this->assertNull($score['total']);
        $this->assertSame('N/A', CustomerRiskService::grade(null, false, false, $cfg));
    }

    public function test_slope_and_streaks(): void
    {
        $this->assertEqualsWithDelta(1.0, CustomerRiskService::slope([0, 1, 2, 3]), 0.0001);
        $this->assertSame(['longest' => 3, 'current' => 0], CustomerRiskService::streaks([true, true, true, false]));
    }
}
