<?php

namespace Modules\Watchlist\Models;

use App\Domains\Transaction\Models\Transaction;
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

    public function transactions()
    {
    }

    public function projected()
    {
    }
}
