<?php

namespace App\Http\Resources;

use App\Models\ExpenseReceipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One receipt's metadata — deliberately all of it.
 *
 * `path` is never emitted: it is a name under `storage/app/private/`, and
 * the whole point of it being private is that no response describes where
 * the bytes are. A client fetches them by id through
 * `GET /api/v1/expenses/{expense}/receipts/{receipt}`, which is behind
 * ExpensePolicy — you cannot list receipts and cannot fetch one for a claim
 * you could not already read.
 *
 * `original_name` IS emitted, because a person recognises "IMG_0142.jpg"
 * faster than a uuid and because it is data stored for display. It is never
 * used to open, serve or resolve anything, and it is never echoed into a
 * response header either — the download's filename is minted by
 * ExpenseReceiptStore from the uuid.
 *
 * `url` is the id-based route a client calls rather than a link to follow:
 * deliberately relative and deliberately not signed, because a signed URL is
 * a bearer credential in a screenshot, a log line and a forwarded email.
 */
class ExpenseReceiptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ExpenseReceipt $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,
            'expense_id' => $resource->expense_id,

            'original_name' => $resource->original_name,
            'mime_type' => $resource->mime_type,
            'size_bytes' => $resource->size_bytes,

            'is_image' => $resource->isImage(),
            'is_pdf' => $resource->isPdf(),

            // The only route to the bytes. Ids, not paths, and no query
            // string: `no-store` headers on the response do the rest.
            'url' => sprintf(
                '/api/v1/expenses/%d/receipts/%d',
                (int) $resource->expense_id,
                (int) $resource->id,
            ),

            'uploaded_by' => $resource->uploaded_by,
            'created_at' => $resource->created_at?->toIso8601String(),
        ];
    }
}
