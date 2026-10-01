<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One kind of document, and the three rules it asks of anything filed
 * under it.
 *
 * `requires_*` travels to the client for the same reason
 * ExpenseResource ships `requires_receipt`: a form that does not know
 * "this type needs a number" would let somebody finish one that was never
 * going to be accepted, and a 422 after the fact is a worse experience
 * than a field marked required before it is typed into.
 *
 * `expiry_warning_days` comes too, because it is what "expiring soon"
 * means for this kind of document and the label on a chip has to say
 * something true. Zero here does not mean "never warns" — it means the
 * configured default applies — which is exactly how
 * DocumentType::warningDays() reads it.
 */
class DocumentTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'requires_document_number' => (bool) $this->requires_document_number,
            'requires_issue_date' => (bool) $this->requires_issue_date,
            'requires_expiry_date' => (bool) $this->requires_expiry_date,
            'expiry_warning_days' => (int) $this->expiry_warning_days,
            'status' => $this->status,
            'sort_order' => (int) $this->sort_order,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
