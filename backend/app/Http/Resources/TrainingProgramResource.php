<?php

namespace App\Http\Resources;

use App\Models\TrainingProgram;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One configurable training program the company runs.
 *
 * **Every configurable fact is in the payload, because every one of them is
 * something a form or a report has to draw** — provider, duration, whether a
 * certificate comes out of it and how long that certificate lasts. The
 * alternative (a service that reads them and a payload that does not) is
 * how a screen ends up hard-coding the very thing the brief asked to be
 * data.
 *
 * `certificate_validity_days` ships as it is stored, null and all: null
 * means "never lapses" and is a real answer a completion form needs to see,
 * while `0` would mean a card that expires the moment it is issued. Nothing
 * here computes an expiry for a *particular* person — that is
 * EmployeeTrainingResource's, and it belongs to an enrolment rather than to
 * the programme it came from.
 *
 * `is_active` mirrors `status` for the same reason DocumentTypeResource
 * does it: a picker needs a boolean and a history screen needs the word, and
 * one client should not have to derive the first from the second.
 *
 * No `can_edit`. Whether *you* may retire this programme is
 * TrainingProgramPolicy's answer, asked when you try — see
 * SiteActivityReportResource for why state ships and permission does not.
 */
class TrainingProgramResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'training_type_id' => $this->training_type_id,
            'training_type' => $this->whenLoaded(
                'trainingType',
                fn () => new TrainingTypeResource($this->trainingType),
            ),

            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'provider' => $this->provider,
            'duration_days' => $this->duration_days,

            'certificate_required' => (bool) $this->certificate_required,
            'certificate_validity_days' => $this->certificate_validity_days,

            'status' => $this->status,
            'is_active' => $this->status === TrainingProgram::STATUS_ACTIVE,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
