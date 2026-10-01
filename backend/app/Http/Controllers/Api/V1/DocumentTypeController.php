<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentTypeResource;
use App\Http\Responses\PaginatedResponse;
use App\Models\DocumentType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/document-types
 *
 * Reference data, and only reference data — no endpoint in this
 * application creates, edits or retires a document type, because the
 * catalogue is configuration (docs/DATABASE.md) rather than something an
 * API client should be able to rewrite mid-conversation. That is the same
 * stance expense categories take: an operator adds a row, and the services
 * read it back at runtime.
 *
 * Read is gated by `documents.view` alone: knowing that Emirates IDs exist
 * and what they ask for is not a disclosure about anybody, and the upload
 * form needs the list *before* it has a file to attach — which is exactly
 * when an `employees.view`-shaped gate would refuse the screen and leave
 * the user staring at an empty dropdown.
 *
 * An `inactive` type is returned too, because an edit form for a document
 * filed under one still has to render its label; what is filtered is the
 * choice to *file* under it, and that is `Rule::exists(...)->where(status)`
 * in StoreEmployeeDocumentRequest rather than a silent omission here.
 */
class DocumentTypeController extends Controller
{
    use BuildsResourceLists;

    /**
     * GET /api/v1/document-types
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', DocumentType::class);

        $query = DocumentType::query();

        if ($this->truthy($request, 'active_only')) {
            $query->active();
        }

        if ($term = $this->searchTerm($request)) {
            $like = '%'.$this->escapeLike($term).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)
                ->orWhere('code', 'like', $like));
        }

        $page = $query
            ->orderBy(
                $this->sortColumn($request, ['sort_order', 'name', 'code', 'created_at'], 'sort_order'),
                $this->sortDirection($request, 'asc'),
            )
            ->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Document types.',
            DocumentTypeResource::collection($page),
            $page,
        );
    }

    private function truthy(Request $request, string $key): bool
    {
        $value = $this->param($request, $key);

        return $value !== null && in_array(strtolower($value), ['1', 'true', 'yes'], true);
    }
}
