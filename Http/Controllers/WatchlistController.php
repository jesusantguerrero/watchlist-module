<?php

namespace Modules\Watchlist\Http\Controllers;

use Illuminate\Http\Request;
use Modules\Watchlist\Models\Watchlist;
use Freesgen\Atmosphere\Http\InertiaController;
use Modules\Watchlist\Services\WatchlistService;

class WatchlistController extends InertiaController {
    const DateFormat = 'Y-m-d';

    public function __construct(Watchlist $watchlist, private WatchlistService $watchlistService)
    {
        $this->model = $watchlist;
        $this->templates = [
            'index' => 'Finance/Watchlist',
            'show' => 'Finance/WatchlistShow'
        ];
        $this->searchable = ["id", "name"];
        $this->sorts = ['name'];
        $this->includes = [];
        $this->appends = [];
    }

    public function getIndexProps(Request $request, $watchlist = null): array {
        $queryParams = $request->query();
        $filters = isset($queryParams['filter']) ? $queryParams['filter'] : [];
        [$startDate, $endDate] = $this->getFilterDates($filters);

        if (!$watchlist) return [];

        return [
            "data" => $this->watchlistService->list(auth()->user()->current_team_id, $startDate, $endDate, $watchlist)
        ];
    }

    public function show(Watchlist $watchlist) {
        $queryParams = request()->query();
        $filters = isset($queryParams['filter']) ? $queryParams['filter'] : [];
        [$startDate, $endDate] = $this->getFilterDates($filters);



        $resource = array_merge($watchlist->toArray(), $this->watchlistService->getFullData($watchlist, $startDate, $endDate, 2));

        return inertia($this->templates['show'], [
            "resource" => $resource,
            "watchlist" => $this->watchlistService->list(auth()->user()->current_team_id, $startDate, $endDate)
        ]);
    }
}

