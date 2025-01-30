<?php

namespace Modules\Watchlist\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Watchlist\Models\Watchlist;
use App\Domains\Transaction\Models\Transaction;
use App\Domains\Transaction\Models\TransactionLine;

class WatchlistService
{

    public  function getData($listData, $startDate = null, $endDate = null)
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

    public function getFullData($listData, $startDate = null, $endDate = null, $sub = 1)
    {
        $startDateCarbon = Carbon::createFromFormat('Y-m-d', $startDate);
        $endDateCarbon = Carbon::createFromFormat('Y-m-d', $endDate)->endOfMonth();

        $prevStartDate = $startDateCarbon->subMonth($sub)->startOfMonth()->format('Y-m-d');
        $prevEndDate = $endDateCarbon->subMonth($sub)->endOfMonth()->format('Y-m-d');

        return [
            'month' => $this->expensesInRange($listData->team_id, $startDate, $endDate, $listData),
            'prevMonth' => $this->expensesInRange($listData->team_id, $prevStartDate, $prevEndDate, $listData),
            'transactions' => $this->transactionsByCategories($listData, $prevStartDate, $endDate),
        ];
    }

     public function fullData(Watchlist $watchlist, $startDate = null, $endDate = null, $sub = 1)
    {
        $startDateCarbon = now()->startOfMonth();
        $endDateCarbon = Carbon::createFromFormat('Y-m-d', $endDate ??  date('Y-m-d'))->endOfMonth();

        $prevStartDate = $startDateCarbon->copy()->subMonth($sub)->startOfMonth()->format('Y-m-d');
        $prevEndDate = $startDateCarbon->copy()->subRealDay($sub)->endOfMonth()->format('Y-m-d');

        return [
            'month' => self::expensesInRange($watchlist->team_id, $startDateCarbon->format('Y-m-d'), $endDateCarbon->format('Y-m-d'), $watchlist),
            'prevMonth' => self::expensesInRange($watchlist->team_id, $prevStartDate, $prevEndDate, $watchlist),
        ];
    }

    public function expensesInRange($teamId, $startDate, $endDate, $listData)
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

    public  function transactions(Watchlist $watchlist, $startDate, $endDate)
    {
        $filterType = $watchlist->type;

        return Transaction::byTeam($watchlist->teamId)
        ->verified()
        ->expenses()
        ->inDateFrame($startDate, $endDate)
        ->$filterType($watchlist->input);
    }

    public  function transactionsByCategories(Watchlist $watchlist, $startDate, $endDate)
    {
        $filterType = $watchlist->type;
        $result = TransactionLine::query()
        ->byTeam($watchlist->team_id)
        ->inDateFrame($startDate, $endDate)
        ->verified()
        ->with(['transaction',  'payee',
        'category',
        'accountFrom',
        'accountTo',
        'labels'])
        ->balance()
        ->$filterType($watchlist->input)
        ->selectRaw("
            date_format(transaction_lines.date,
            '%Y-%m-01') as date,
            date_format(transaction_lines.date, '%Y-%m-01') as month,
            year(transaction_lines.date) as year,
            categories.name,
            categories.id, transaction_lines.*"
        )
        ->groupByRaw('date_format(transaction_lines.date, "%Y-%m"), categories.id')
        ->orderBy('date')
        ->get();

        $resultGroup = $result->groupBy('month')->reverse();
        return $resultGroup->map(function ($monthItems) {
            return [
                'date' => $monthItems->first()->month,
                'data' => $monthItems->sortByDesc('total_amount')->values(),
                'total' => $monthItems->sum(function ($transaction){
                    return $transaction->total_amount;
                } )
            ];
        }, $resultGroup);
    }

    public function list($teamId, $startDate, $endDate, Illuminate\Database\Eloquent\Collection $watchlist = null) {
        $teamWatchlist =  $watchlist ?? Watchlist::where('team_id', $teamId)->get();
        return array_map(function($item) use ($startDate, $endDate) {
            return array_merge($item, [
                "data" => Watchlist::getData((object) $item, $startDate, $endDate)
            ]);
        }, $teamWatchlist->toArray());
    }

    public function projected()
    {
    }
}
