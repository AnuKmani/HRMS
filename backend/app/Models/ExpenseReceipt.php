<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A receipt file attached to an expense.
 *
 * The row is metadata about a private file and nothing more: `path` is a
 * UUID-named key under `storage/app/private/expense-receipts/`, minted by
 * ExpenseReceiptStore and never handed to a client. The only way to the
 * bytes is GET /api/v1/expenses/{expense}/receipts/{receipt}, which is
 * behind ExpensePolicy — you cannot list receipts, cannot guess a path and
 * cannot fetch one for an expense you could not already read.
 *
 * `original_name` is stored as *data* so a person can recognise their own
 * file in a list; it is never used to open, serve or resolve anything, and
 * the path a client would have had is discarded before anything is written.
 */
class ExpenseReceipt extends Model
{
    use HasFactory;

    protected $fillable = [
        'expense_id',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'uploaded_by',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    /* ----------------------------------------------------------- relations */

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /* ------------------------------------------------------------- helpers */

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }
}
