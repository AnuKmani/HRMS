<?php

namespace App\Policies;

use App\Models\DocumentType;
use App\Models\User;

/**
 * Who may *read* the document catalogue.
 *
 * Two abilities, both the same answer, and that is the point: a document
 * type is configuration — a name, three flags, a warning window — and
 * knowing that Emirates IDs expire says nothing about anybody's Emirates
 * ID. There is no row scope because there are no rows about people.
 *
 * Writing is not offered here at all. No endpoint in this application
 * creates, edits or retires a type, so there is deliberately no `manage`
 * ability for a caller to be denied: an ability that exists and always
 * refuses is a 403 with no explanation, and an ability that exists and
 * succeeds is an endpoint somebody forgot to remove. The catalogue is
 * seeded and edited as configuration — see DocumentTypeSeeder and
 * docs/DATABASE.md.
 *
 * `viewAny` is `documents.view`, the same coarse gate the upload form
 * needs: a screen that cannot draw its type picker is a screen that cannot
 * be used, and gating reference data behind `employees.view` would refuse
 * exactly the ordinary employee who is meant to attach their own passport.
 */
class DocumentTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('documents.view');
    }

    public function view(User $user, DocumentType $type): bool
    {
        return $user->can('documents.view');
    }
}
