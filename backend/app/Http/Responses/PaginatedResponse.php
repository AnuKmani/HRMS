<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The list half of the envelope, sitting beside ApiResponse rather than in it
 * so the success shape stays exactly as documented for single resources.
 *
 *   { "success": true, "message": "...",
 *     "data": { "items": [...], "meta": { ... } } }
 *
 * `items` is always an array and `meta` always carries the four numbers a
 * client needs to draw a pager. A list endpoint therefore never has to be
 * special-cased by the caller, and adding `?page=` later changes no contract.
 */
class PaginatedResponse
{
    /**
     * @param  AnonymousResourceCollection  $items  already built, so eager
     *                                              loading decisions live in the controller, not here
     */
    public static function make(string $message, AnonymousResourceCollection $items, LengthAwarePaginator $page): JsonResponse
    {
        return ApiResponse::success($message, (object) [
            'items' => $items,
            'meta' => (object) [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                // Simpler than assembling links for a client that paginates
                // by number, and honest about what "next" means on the last
                // page (it does not exist, so it is null rather than a URL
                // that would 404).
                'has_next' => $page->hasMorePages(),
            ],
        ]);
    }
}
