<?php

namespace Modules\Watchlist\Services;

use App\Domains\Transaction\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Watchlist\Models\Watchlist;

/**
 * WL-8: surfaces payees the user spent meaningful money on this month but isn't
 * tracking with any watchlist yet. Used by a weekly job to suggest "add these
 * to a watchlist" via notification with a deep-link to the create modal.
 */
class WatchlistAutoSuggestService
{
    /**
     * @return array<int, array{payee_id: int, payee_name: ?string, total: float, transactions_count: int}>
     */
    public function getTopUntrackedPayees(int $teamId, ?Carbon $month = null, int $limit = 3): array
    {
        $startOfMonth = ($month ?? Carbon::now())->copy()->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        $watchlistedPayeeIds = $this->payeesAlreadyTracked($teamId);

        $query = Transaction::byTeam($teamId)
            ->verified()
            ->expenses()
            ->whereBetween('transactions.date', [
                $startOfMonth->format('Y-m-d'),
                $endOfMonth->format('Y-m-d'),
            ])
            ->whereNotNull('transactions.payee_id')
            ->whereNotIn('transactions.payee_id', $watchlistedPayeeIds)
            ->leftJoin('payees', 'payees.id', '=', 'transactions.payee_id')
            ->groupBy('transactions.payee_id', 'payees.name')
            ->orderByDesc(DB::raw('SUM(transactions.total)'))
            ->limit($limit)
            ->select([
                'transactions.payee_id',
                'payees.name as payee_name',
                DB::raw('SUM(transactions.total) as total'),
                DB::raw('COUNT(transactions.id) as transactions_count'),
            ]);

        return $query->get()->map(fn ($row) => [
            'payee_id' => (int) $row->payee_id,
            'payee_name' => $row->payee_name,
            'total' => (float) $row->total,
            'transactions_count' => (int) $row->transactions_count,
        ])->all();
    }

    /**
     * @return array<int, int> Payee IDs referenced by any payee-type watchlist for the team.
     */
    private function payeesAlreadyTracked(int $teamId): array
    {
        $watchlists = Watchlist::query()
            ->where('team_id', $teamId)
            ->where('type', Watchlist::TYPE_PAYEE)
            ->pluck('input');

        $ids = [];
        foreach ($watchlists as $input) {
            if (is_array($input)) {
                foreach ($input as $payeeId) {
                    $ids[] = (int) $payeeId;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
