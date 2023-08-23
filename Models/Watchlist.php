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
    public const TYPE_LABELS = 'labels';

    protected $fillable = ['team_id', 'user_id', 'name', 'input', 'type', 'target'];

    /**
    * The attributes that should be cast to native types.
    *
    * @var array
    */
    protected $casts = [
        'input' => 'array',
    ];

    public static function getData($listData, $startDate = null, $endDate = null)
    {
        $startDateCarbon = Carbon::createFromFormat('Y-m-d', $startDate);
        $endDateCarbon = Carbon::createFromFormat('Y-m-d', $endDate)->endOfMonth();

        $prevStartDate = $startDateCarbon->subMonth(1)->startOfMonth()->format('Y-m-d');
        $prevEndDate = $endDateCarbon->subMonth(1)->endOfMonth()->format('Y-m-d');

        return [
            'month' => self::expensesInRange($listData->team_id, $startDate, $endDate, $listData),
            'prevMonth' => self::expensesInRange($listData->team_id, $prevStartDate, $prevEndDate, $listData)
        ];
    }

    public static function getFullData($listData, $startDate = null, $endDate = null)
    {
        $startDateCarbon = Carbon::createFromFormat('Y-m-d', $startDate);
        $endDateCarbon = Carbon::createFromFormat('Y-m-d', $endDate)->endOfMonth();

        $prevStartDate = $startDateCarbon->subMonth(1)->startOfMonth()->format('Y-m-d');
        $prevEndDate = $endDateCarbon->subMonth(1)->endOfMonth()->format('Y-m-d');

        return [
            'month' => self::expensesInRange($listData->team_id, $startDate, $endDate, $listData),
            'prevMonth' => self::expensesInRange($listData->team_id, $prevStartDate, $prevEndDate, $listData),
            'transactions' => $listData->transactionsByCategories($prevStartDate, $endDate)
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

    public function projected()
    {
    }
}
