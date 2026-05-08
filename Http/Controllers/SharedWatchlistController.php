<?php

namespace Modules\Watchlist\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Watchlist\Models\Watchlist;
use Modules\Watchlist\Services\WatchlistService;

/**
 * WL-MARKETING: read-only public view of a watchlist via share token.
 *
 * Mirrors the share-token pattern used by SharedShoppingListController.
 * Token-protected (no auth), strips owner/team identifiers, exposes only
 * monthly totals + 12-month timeline. Transactions and individual payees
 * are NOT included to avoid leaking purchase history.
 */
class SharedWatchlistController
{
    public function __construct(private WatchlistService $watchlistService) {}

    public function show(string $token, Request $request)
    {
        $watchlist = Watchlist::where('share_token', $token)->firstOrFail();

        $startDate = $request->query('start') ?: now()->startOfMonth()->format('Y-m-d');
        $endDate = $request->query('end') ?: now()->endOfMonth()->format('Y-m-d');

        $data = $this->watchlistService->getFullData($watchlist, $startDate, $endDate, 1);

        return view('shared-watchlist', [
            'name' => $watchlist->name,
            'type' => $watchlist->type,
            'target' => (float) ($watchlist->target ?? 0),
            'direction' => $watchlist->direction,
            'month' => $data['month'] ?? null,
            'prevMonth' => $data['prevMonth'] ?? null,
            'monthlySeries' => $data['monthlySeries'] ?? [],
        ]);
    }

    /**
     * Owner action: enable share by ensuring (or rotating) the token.
     */
    public function store(Watchlist $watchlist, Request $request): RedirectResponse
    {
        $this->authorizeOwner($watchlist, $request);
        $watchlist->ensureShareToken();

        return back();
    }

    /**
     * Owner action: revoke the share token, disabling the public URL.
     */
    public function destroy(Watchlist $watchlist, Request $request): RedirectResponse
    {
        $this->authorizeOwner($watchlist, $request);
        $watchlist->revokeShareToken();

        return back();
    }

    private function authorizeOwner(Watchlist $watchlist, Request $request): void
    {
        abort_unless(
            $request->user() && (int) $watchlist->team_id === (int) $request->user()->current_team_id,
            403,
        );
    }
}
