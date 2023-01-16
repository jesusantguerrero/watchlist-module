<?php

namespace Modules\Watchlist\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use Modules\Watchlist\Models\Watchlist;

class WatchlistApiController extends BaseController {
    const DateFormat = 'Y-m-d';

    public function __construct(Watchlist $watchlist)
    {
        $this->model = $watchlist;
        $this->searchable = ["id", "name"];
        $this->sorts = ['name'];
        $this->includes = [];
        $this->appends = [];
    }

    public function parser($results): array {
        $queryParams = request()->query();
        $filters = isset($queryParams['filter']) ? $queryParams['filter'] : [];
        [$startDate, $endDate] = $this->getFilterDates($filters);

        return [
            "data" => array_map(function($item) use ($startDate, $endDate) {
                return array_merge($item, [
                    "data" => Watchlist::getData((object) $item, $startDate, $endDate)
                ]);
            }, $results->toArray())
        ];
    }
}

