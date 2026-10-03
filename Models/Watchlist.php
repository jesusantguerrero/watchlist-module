<?php

namespace Modules\Watchlist\Models;

use App\Domains\Transaction\Models\Transaction;
use App\Domains\Transaction\Models\TransactionLine;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Watchlist extends Model
{
    use HasFactory;
    public const TYPE_PAYEE = 'payees';
    public const TYPE_CATEGORY = 'categories';
    public const TYPE_CATEGORY_GROUP = 'groups';
    public const TYPE_TAGS = 'tags';

    public const DIRECTION_OUTFLOW = 'outflow';
    public const DIRECTION_INFLOW = 'inflow';
    public const DIRECTION_BOTH = 'both';

    protected $fillable = ['team_id', 'user_id', 'name', 'input', 'type', 'target', 'direction', 'share_token'];

    /**
     * Generate (or reuse) a 32-char random URL slug. Idempotent: calling twice returns
     * the same token. Used by the public read-only share view (WL-MARKETING).
     */
    public function ensureShareToken(): string
    {
        if (! $this->share_token) {
            $this->share_token = Str::random(32);
            $this->save();
        }

        return $this->share_token;
    }

    public function revokeShareToken(): void
    {
        $this->share_token = null;
        $this->save();
    }

    /**
    * The attributes that should be cast to native types.
    *
    * @var array
    */
    protected $casts = [
        'input' => 'array',
        'target' => 'decimal:2',
    ];

    public static function getData($listData, $startDate = null, $endDate = null)
    {
        $startDateCarbon = Carbon::createFromFormat('Y-m-d', $startDate);
        $endDateCarbon = Carbon::createFromFormat('Y-m-d', $endDate)->endOfMonth();

        $prevStartDate = $startDateCarbon->subMonth(1)->startOfMonth()->format('Y-m-d');
        $prevEndDate = $endDateCarbon->subMonth(1)->endOfMonth()->format('Y-m-d');

        return [
            'month' => self::monthDataWithProjection($listData->team_id, $startDate, $endDate, $listData),
            'prevMonth' => self::expensesInRange($listData->team_id, $prevStartDate, $prevEndDate, $listData),
        ];
    }

    public static function getFullData($listData, $startDate = null, $endDate = null, $sub = 1)
    {
        $startDateCarbon = Carbon::createFromFormat('Y-m-d', $startDate);
        $endDateCarbon = Carbon::createFromFormat('Y-m-d', $endDate)->endOfMonth();

        $prevStartDate = $startDateCarbon->subMonth($sub)->startOfMonth()->format('Y-m-d');
        $prevEndDate = $endDateCarbon->subMonth($sub)->endOfMonth()->format('Y-m-d');

        return [
            'month' => self::monthDataWithProjection($listData->team_id, $startDate, $endDate, $listData),
            'prevMonth' => self::expensesInRange($listData->team_id, $prevStartDate, $prevEndDate, $listData),
            'transactions' => $listData->transactionsByCategories($prevStartDate, $endDate),
            'monthlySeries' => self::monthlySeries(12, $listData->team_id, $listData, $endDate),
        ];
    }

    public function fullData($startDate = null, $endDate = null, $sub = 1)
    {
        $startDateCarbon = now()->startOfMonth();
        $endDateCarbon = Carbon::createFromFormat('Y-m-d', $endDate ?? date('Y-m-d'))->endOfMonth();

        $prevStartDate = $startDateCarbon->copy()->subMonth($sub)->startOfMonth()->format('Y-m-d');
        $prevEndDate = $startDateCarbon->copy()->subRealDay($sub)->endOfMonth()->format('Y-m-d');

        return [
            'month' => self::monthDataWithProjection($this->team_id, $startDateCarbon->format('Y-m-d'), $endDateCarbon->format('Y-m-d'), $this),
            'prevMonth' => self::expensesInRange($this->team_id, $prevStartDate, $prevEndDate, $this),
        ];
    }

    /**
     * Wrap expensesInRange() with end-of-period projection.
     *
     * Returns the same fields as expensesInRange (total, currency_code, transactionsCount,
     * lastTransactionDate) plus projection metadata: projected, days_elapsed, days_in_period,
     * is_current_period.
     *
     * Projection is linear: total * (days_in_period / days_elapsed). Only computed when "now"
     * falls inside the period; otherwise projected == total.
     */
    public static function monthDataWithProjection($teamId, string $startDate, string $endDate, $listData): array
    {
        $expenses = self::expensesInRange($teamId, $startDate, $endDate, $listData);

        $base = $expenses ? $expenses->toArray() : [
            'total' => 0,
            'currency_code' => null,
            'transactionsCount' => 0,
            'lastTransactionDate' => null,
        ];

        return array_merge($base, self::projectedTotal((float) ($base['total'] ?? 0), $startDate, $endDate));
    }

    /**
     * Project the period total using a linear extrapolation from elapsed days.
     *
     * @return array{projected: float, days_elapsed: ?int, days_in_period: ?int, is_current_period: bool}
     */
    public static function projectedTotal(float $total, string $startDate, string $endDate, ?Carbon $now = null): array
    {
        $now = $now ?? now();
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfMonth();

        if ($now->lt($start) || $now->gt($end)) {
            return [
                'projected' => round($total, 2),
                'days_elapsed' => null,
                'days_in_period' => null,
                'is_current_period' => false,
            ];
        }

        $daysElapsed = (int) $start->diffInDays($now, true) + 1;
        $daysInPeriod = (int) $start->diffInDays($end, true) + 1;
        $projected = $daysElapsed > 0 ? $total * ($daysInPeriod / $daysElapsed) : $total;

        return [
            'projected' => round($projected, 2),
            'days_elapsed' => $daysElapsed,
            'days_in_period' => $daysInPeriod,
            'is_current_period' => true,
        ];
    }

    /**
     * Apply the watchlist's direction filter onto a Transaction query.
     * - outflow: only WITHDRAW (expenses, current default for back-compat)
     * - inflow:  only DEPOSIT (income — freelance, refunds, transfers in)
     * - both:    no direction filter (e.g. net flow on a category)
     */
    private static function applyDirection($query, $listData)
    {
        $direction = $listData->direction ?? self::DIRECTION_OUTFLOW;
        if ($direction === self::DIRECTION_OUTFLOW) {
            return $query->expenses();
        }
        if ($direction === self::DIRECTION_INFLOW) {
            return $query->where('transactions.direction', Transaction::DIRECTION_DEBIT)
                ->whereNotNull('category_id');
        }

        // both
        return $query->whereNotNull('category_id');
    }

    public static function expensesInRange($teamId, $startDate, $endDate, $listData)
    {
        $filterType = $listData->type;

        $query = Transaction::byTeam($teamId)
            ->verified()
            ->inDateFrame($startDate, $endDate);

        return self::applyDirection($query, $listData)
            ->select(DB::raw('SUM(total) as total, currency_code, count(id) as transactionsCount, max(date) as lastTransactionDate'))
            ->$filterType($listData->input)
            ->first();
    }

    /**
     * Return $months consecutive monthly totals ending at $endDate, oldest first.
     *
     * Months without matching transactions are filled with total = 0 so the series
     * is always exactly $months long. Used by the timeline chart in WatchlistShow.
     *
     * @return array<int, array{month: string, total: float}>
     */
    public static function monthlySeries(int $months, $teamId, $listData, string $endDate): array
    {
        $endCarbon = Carbon::parse($endDate)->endOfMonth();
        // startOfMonth() before subMonths() avoids Carbon's day-overflow when endDate is the 31st.
        $startCarbon = $endCarbon->copy()->startOfMonth()->subMonths($months - 1);

        $filterType = $listData->type;

        $query = Transaction::byTeam($teamId)
            ->verified()
            ->inDateFrame($startCarbon->format('Y-m-d'), $endCarbon->format('Y-m-d'));

        $totals = self::applyDirection($query, $listData)
            ->$filterType($listData->input)
            ->select(DB::raw("date_format(date, '%Y-%m-01') as month_key"), DB::raw('SUM(total) as total'))
            ->groupBy(DB::raw("date_format(date, '%Y-%m-01')"))
            ->pluck('total', 'month_key')
            ->all();

        $series = [];
        $cursor = $startCarbon->copy();
        for ($i = 0; $i < $months; $i++) {
            $key = $cursor->format('Y-m-01');
            $series[] = [
                'month' => $key,
                'total' => (float) ($totals[$key] ?? 0),
            ];
            $cursor->addMonth();
        }

        return $series;
    }

    public  function transactions( $startDate, $endDate)
    {
        $filterType = $this->type;

        $query = Transaction::byTeam($this->teamId)
            ->verified()
            ->inDateFrame($startDate, $endDate);

        return self::applyDirection($query, $this)
            ->$filterType($this->input);
    }

    public  function transactionsByCategories($startDate, $endDate)
    {
        $filterType = $this->type;
        $result = TransactionLine::byTeam($this->team_id)
        ->verified()
        ->balance()
        ->inDateFrame($startDate, $endDate)
        ->$filterType($this->input)
        ->selectRaw('date_format(transaction_lines.date, "%Y-%m-01") as month_date, categories.name, categories.id')
        ->groupByRaw('date_format(transaction_lines.date, "%Y-%m"), categories.id')
        ->orderBy('month_date')
        ->get();

        
        $resultGroup = $result->groupBy('month_date')->reverse();
        return $resultGroup->map(function ($monthItems) {
            return [
                'date' => $monthItems->first()->month_date,
                'data' => $monthItems->sortByDesc('total_amount')->values(),
                'total' => $monthItems->sum(function ($transaction){
                    return $transaction->total_amount;
                } )
            ];
        }, $resultGroup);
    }

}
