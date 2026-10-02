<?php

namespace App\Services\Training;

use App\Models\TrainingProgram;
use App\Models\User;

/**
 * The only thing that writes a training program.
 *
 * Thin on purpose — the interesting rules are vocabulary rules (a code that
 * is already taken, a type that is retired) and they live in the form
 * request where the payload can be checked before anything is written. What
 * *this* class exists for is the seam: the brief asks for training
 * assignment, completion and certificate changes to go through services so
 * audit logging can be attached to one place in Phase 12, and a program's
 * catalogue belongs with them.
 *
 * One rule that has to be here rather than in a request: **retiring a
 * program with people still on it is allowed, and reactivating it is
 * allowed too.** The cohorts cannot be un-run, so a retired program keeps
 * every row it produced; it simply disappears from the assign form's
 * picker. That is a statement about the *world* rather than about the
 * payload, which is the general shape of everything in this file.
 */
class TrainingProgramService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): TrainingProgram
    {
        $program = new TrainingProgram([
            'training_type_id' => $data['training_type_id'],
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'provider' => $data['provider'] ?? null,
            'duration_days' => $data['duration_days'] ?? null,
            'certificate_required' => (bool) ($data['certificate_required'] ?? false),
            'certificate_validity_days' => $data['certificate_validity_days'] ?? null,
            'status' => $data['status'] ?? TrainingProgram::STATUS_ACTIVE,
        ]);

        $program->save();

        return $program;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, TrainingProgram $program, array $data): TrainingProgram
    {
        $program->training_type_id = $data['training_type_id'] ?? $program->training_type_id;
        $program->code = $data['code'] ?? $program->code;
        $program->name = $data['name'] ?? $program->name;
        $program->description = $this->pick($data, 'description', $program->description);
        $program->provider = $this->pick($data, 'provider', $program->provider);
        $program->duration_days = $this->pick($data, 'duration_days', $program->duration_days);
        $program->certificate_validity_days = $this->pick(
            $data,
            'certificate_validity_days',
            $program->certificate_validity_days,
        );
        $program->status = $data['status'] ?? $program->status;

        if (array_key_exists('certificate_required', $data)) {
            $program->certificate_required = (bool) $data['certificate_required'];
        }

        $program->save();

        return $program;
    }

    /**
     * `array_key_exists` semantics for a partial update — a key sent as null
     * clears the column, a key not sent at all leaves it alone.
     */
    private function pick(array $data, string $key, mixed $current): mixed
    {
        return array_key_exists($key, $data) ? $data[$key] : $current;
    }
}
