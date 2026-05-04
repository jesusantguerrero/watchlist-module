<?php

namespace Modules\Watchlist\Models;

use App\Domains\Transaction\Models\Transaction;
use App\Domains\Transaction\Models\TransactionLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class Watchlist extends Model
{
    use HasFactory;
    public const TYPE_PAYEE = 'payees';
    public const TYPE_CATEGORY = 'categories';
    public const TYPE_CATEGORY_GROUP = 'groups';
    public const TYPE_TAGS = 'tags';

    protected $fillable = ['team_id', 'user_id', 'name', 'input', 'type', 'target'];

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

        $daysElapsed = $start->diffInDays($now) + 1;
        $daysInPeriod = $start->diffInDays($end) + 1;
        $projected = $daysElapsed > 0 ? $total * ($daysInPeriod / $daysElapsed) : $total;

        return [
            'projected' => round($projected, 2),
            'days_elapsed' => $daysElapsed,
            'days_in_period' => $daysInPeriod,
            'is_current_period' => true,
        ];
    }

    public static function expensesInRange($teamId, $startDate, $endDate, $listData)
    {
        $filterType = $listData->type;

        return Transaction::byTeam($teamId)
        ->verified()
        ->expenses()
        ->inDateFrame($startDate, $endDate)
        ->select(DB::raw('SUM(total) as total, currency_code, count(id) as transactionsCount, max(date) as lastTransactionDate'))
        ->$filterType($listData->input)
        ->first();
    }

    public  function transactions( $startDate, $endDate)
    {
        $filterType = $this->type;

        return Transaction::byTeam($this->teamId)
        ->verified()
        ->expenses()
        ->inDateFrame($startDate, $endDate)
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
