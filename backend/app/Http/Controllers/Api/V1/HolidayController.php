<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreHolidayRequest;
use App\Http\Requests\UpdateHolidayRequest;
use App\Http\Resources\HolidayResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\Holiday;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/holidays
 *
 * Readable by every signed-in account, writable by `holidays.manage`. There
 * is no `holidays.view` permission on purpose — see HolidayPolicy for the
 * argument — so the scoping that matters happens in Visibility, which both
 * this query and HolidayPolicy::view() read.
 *
 * What a reader sees:
 *
 *   public + company days, plus site days at sites they are assigned to or
 *   run
 *
 * ...unless they hold `holidays.manage`, in which case they see the whole
 * calendar including site days they do not run — configuring a site holiday
 * you cannot then read would be a strange kind of edit.
 *
 * Duplicate detection lives here rather than in the form request: a public
 * holiday has `site_id IS NULL`, and `Rule::unique` builds `site_id = ?`,
 * which is never true in SQL — so the rule would appear to guarantee
 * uniqueness while passing a second copy. This is a real `whereNull`, and it
 * runs *before* anything is written.
 */
class HolidayController extends Controller
{
    use BuildsResourceLists;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Holiday::class);

        $user = $request->user();
        $query = Holiday::query()->with('site');

        if (! $user->can('holidays.manage')) {
            $siteIds = Visibility::holidaySiteIds($user);

            $query->where(function ($q) use ($siteIds) {
                $q->whereIn('type', [Holiday::TYPE_PUBLIC, Holiday::TYPE_COMPANY]);

                if ($siteIds->isNotEmpty()) {
                    $q->orWhere(function ($inner) use ($siteIds) {
                        $inner->where('type', Holiday::TYPE_SITE)
                            ->whereIn('site_id', $siteIds);
                    });
                }
            });
        }

        if ($type = $this->param($request, 'type')) {
            $query->where('type', $type);
        }

        if (($status = $this->param($request, 'status')) !== null) {
            $query->where('status', $status);
        }

        if ($from = $this->param($request, 'from')) {
            $query->where('date', '>=', $from);
        }

        if ($to = $this->param($request, 'to')) {
            $query->where('date', '<=', $to);
        }

        if ($term = $this->searchTerm($request)) {
            $query->where('name', 'like', '%'.$this->escapeLike($term).'%');
        }

        $page = $query->orderBy(
            $this->sortColumn($request, ['date', 'name', 'type', 'created_at'], 'date'),
            $this->sortDirection($request),
        )->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Holidays retrieved.',
            HolidayResource::collection($page->getCollection()),
            $page,
        );
    }

    public function show(Request $request, Holiday $holiday): JsonResponse
    {
        $this->authorize('view', $holiday);

        return ApiResponse::success('Holiday retrieved.', new HolidayResource($holiday->load('site')));
    }

    public function store(StoreHolidayRequest $request): JsonResponse
    {
        $data = $request->validated();

        $this->assertNoDuplicate(
            $data['date'],
            $data['type'],
            $data['site_id'] ?? null,
        );

        $holiday = Holiday::create($data);

        return ApiResponse::created('Holiday created.', new HolidayResource($holiday->load('site')));
    }

    public function update(UpdateHolidayRequest $request, Holiday $holiday): JsonResponse
    {
        $data = $request->validated();

        // Merged, not `validated()`: a PUT that mentions only `status` still
        // needs the *effective* date, type and site to test — and those are
        // the ones the row will have afterwards.
        $candidate = array_merge($holiday->toArray(), $data);

        $this->assertNoDuplicate(
            $candidate['date'],
            $candidate['type'],
            $candidate['site_id'] ?? null,
            $holiday->id,
        );

        $holiday->fill($data)->save();

        return ApiResponse::success(
            'Holiday updated.',
            new HolidayResource($holiday->refresh()->load('site')),
        );
    }

    /**
     * Two holidays on the same day at the same scope are the same holiday.
     *
     * Keyed on (date, type, site) rather than (date, type, site, name): the
     * name is what a human typed and can differ between two people who mean
     * the same day, while the scope is what the day calculation reads.
     */
    private function assertNoDuplicate(string $date, string $type, ?int $siteId, ?int $ignoreId = null): void
    {
        $duplicate = Holiday::query()
            ->where('date', $date)
            ->where('type', $type)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->when(
                $siteId === null,
                fn ($q) => $q->whereNull('site_id'),
                fn ($q) => $q->where('site_id', $siteId),
            )
            ->exists();

        if ($duplicate) {
            abort(422, 'A holiday already exists on that date with that scope.');
        }
    }
}
