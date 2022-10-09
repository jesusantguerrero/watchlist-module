<?php

namespace Modules\Watchlist\Http\Controllers;

use Freesgen\Atmosphere\Http\InertiaController;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Watchlist\Models\Watchlist;

class WatchlistController extends InertiaController {
    const DateFormat = 'Y-m-d';

    public function __construct(Watchlist $watchlist)
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

    public function getIndexProps(Request $request, $watchlist): array {
        $teamId = $request->user()->current_team_id;
        $queryParams = $request->query();
        $filters = isset($queryParams['filter']) ? $queryParams['filter'] : [];
        [$startDate, $endDate] = $this->getFilterDates($filters);

        return [
            "data" => array_map(function($item) use ($startDate, $endDate) {
                return array_merge($item, [
                    "data" => Watchlist::getData((object) $item, $startDate, $endDate)
                ]);
            }, $watchlist->toArray())
        ];
    }

    public function show(Watchlist $watchlist) {
        $filters = isset($queryParams['filter']) ? $queryParams['filter'] : [];
        [$startDate, $endDate] = $this->getFilterDates($filters);

        $resource = array_merge($watchlist->toArray(), Watchlist::getData($watchlist, $startDate, $endDate));
        return Inertia::render($this->templates['show'], [
            "resource" => $resource
        ]);
    }
}

