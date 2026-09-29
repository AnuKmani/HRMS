<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseReceipt;
use App\Models\Project;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsExpenses;
use Tests\TestCase;

/**
 * POST/GET/DELETE /api/v1/expenses/{expense}/receipts — the evidence behind
 * a claim for money.
 *
 * A receipt is somebody's invoice or card slip filed against a named
 * person's claim, so the file proves four things and each one has its own
 * way of being forgotten:
 *
 *  - **it is private.** Private disk, a directory the app has no route to,
 *    and a filename this server minted — because the client's filename is
 *    the one piece of metadata the upload itself creates and the one that
 *    can carry anything at all.
 *  - **the path never leaves.** Not in the payload, not in a header, not in
 *    a URL: the only way to the bytes is by naming the claim and then the
 *    receipt id, behind the policy that already governs the claim.
 *  - **the bytes are checked, not the name.** `mimes` and `mimetypes` both
 *    read the name, so three kilobytes of HTML named `note.pdf` passes both
 *    and is refused by the content rule underneath them.
 *  - **who may open one is a second, separate question.** Your own receipts
 *    read through ownership; somebody else's needs `expenses.receipts.view`
 *    on top of already being allowed to read the claim — which is why a
 *    Site Engineer can read a colleague's numbers and still be refused the
 *    document behind them.
 */
class ExpenseReceiptTest extends TestCase
{
    use BuildsExpenses, RefreshDatabase;

    /** Enough of a PDF to carry the signature CertificateContent looks for. */
    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\nstartxref\n%%EOF\n";

    private Project $project;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedExpenseStack();

        Storage::fake('local');
        Storage::fake('public');

        $this->project = Project::factory()->create();
        $this->site = Site::factory()->create(['project_id' => $this->project->id]);
    }

    /* ------------------------------------------------------------ storage */

    public function test_receipts_land_on_the_private_disk_under_a_name_the_client_never_chosen(): void
    {
        [$user] = $this->signInAs('Employee');
        $id = $this->claim(['expense_category_id' => $this->expenseCategoryId('TRANSPORT')])['id'];

        $response = $this->post('/api/v1/expenses/'.$id.'/receipts', [
            'receipts' => [
                UploadedFile::fake()->createWithContent('taxi-fare.pdf', self::PDF),
                UploadedFile::fake()->image('fare.jpg', 100, 100),
            ],
        ]);

        $response->assertOk();
        $receipts = $response->json('data.receipts');

        $this->assertCount(2, $receipts, 'Several receipts on one claim, one row each.');
        $this->assertSame(['taxi-fare.pdf', 'fare.jpg'], array_column($receipts, 'original_name'));
        $this->assertSame('application/pdf', $receipts[0]['mime_type']);
        $this->assertGreaterThan(0, $receipts[0]['size_bytes']);
        $this->assertTrue($receipts[0]['is_pdf']);
        $this->assertTrue($receipts[1]['is_image']);
        $this->assertSame(
            '/api/v1/expenses/'.$id.'/receipts/'.$receipts[0]['id'],
            $receipts[0]['url'],
            'Ids, not paths — and no signed query string to forward in an email.',
        );

        // The path is metadata the server keeps, not a fact the client is
        // told — a stored path says where every other stored path is.
        $this->assertArrayNotHasKey('path', $receipts[0]);
        $this->assertStringNotContainsString('expense-receipts/', $response->getContent());

        $files = Storage::disk('local')->allFiles();
        $this->assertCount(2, $files);

        foreach ($files as $file) {
            $this->assertStringStartsWith('expense-receipts/'.$id.'/', $file);
            $this->assertStringNotContainsString('taxi-fare', $file, 'The client\'s name was not used to store it.');
            $this->assertStringNotContainsString('fare.', basename($file), 'Nor to mint one.');
        }

        $this->assertSame([], Storage::disk('public')->allFiles(), 'Nothing reached a disk any URL serves.');

        // The row names the *account* that filed it, not the HR record: two
        // people can share an employee row's post, and the receipt is
        // attributed to whoever held the session.
        $rows = ExpenseReceipt::query()->orderBy('id')->get();
        $this->assertSame([$user->id, $user->id], $rows->pluck('uploaded_by')->all());
    }

    public function test_a_receipt_is_read_back_only_through_its_own_claims_route(): void
    {
        $this->signInAs('Employee');
        $id = $this->claim(['expense_category_id' => $this->expenseCategoryId('TRANSPORT')])['id'];

        $this->post('/api/v1/expenses/'.$id.'/receipts', [
            'receipts' => [UploadedFile::fake()->createWithContent('taxi-fare.pdf', self::PDF)],
        ])->assertOk();

        $receiptId = (int) ExpenseReceipt::query()->where('expense_id', $id)->value('id');

        $download = $this->get('/api/v1/expenses/'.$id.'/receipts/'.$receiptId);

        $download->assertOk();
        $this->assertStringContainsString(
            'attachment',
            (string) $download->headers->get('content-disposition'),
            'A document is a download, never something a browser renders next to somebody else\'s session.',
        );
        $this->assertStringContainsString(
            'no-store',
            (string) $download->headers->get('cache-control'),
            'A shared device must not keep an invoice in its cache.',
        );
        $this->assertSame(
            'application/pdf',
            (string) $download->headers->get('content-type'),
            'Served as what it is, not as whatever the upload was labelled.',
        );

        // No claim, no receipt: there is nothing to fetch without naming
        // both, so a path guess has nowhere to start from.
        $this->get('/api/v1/expenses/'.($id + 999).'/receipts/'.$receiptId)->assertNotFound();
    }

    /* -------------------------------------------------------- authorization */

    public function test_an_employee_reads_their_own_receipts_and_never_a_colleagues(): void
    {
        $this->signInAs('Employee');
        $id = $this->claim(['expense_category_id' => $this->expenseCategoryId('TRANSPORT')])['id'];

        $this->post('/api/v1/expenses/'.$id.'/receipts', [
            'receipts' => [UploadedFile::fake()->image('mine.jpg', 80, 80)],
        ])->assertOk();

        $receiptId = (int) ExpenseReceipt::query()->where('expense_id', $id)->value('id');

        // A colleague holds `expenses.view` for their own history, and
        // `expenses.create` for their own claims. Neither is an invitation.
        $this->become($this->makeSeat('Employee')[0]);

        $this->getJson('/api/v1/expenses/'.$id)->assertForbidden();
        $this->get('/api/v1/expenses/'.$id.'/receipts/'.$receiptId)->assertForbidden();
        $this->deleteJson('/api/v1/expenses/'.$id.'/receipts/'.$receiptId)->assertForbidden();

        $this->assertSame(
            1,
            ExpenseReceipt::query()->where('expense_id', $id)->count(),
            'The refusal left the document where it was.',
        );

        // The back office may read the claim, and the receipt follows it.
        $this->become($this->makeSeat('HR Admin')[0]);
        $this->get('/api/v1/expenses/'.$id.'/receipts/'.$receiptId)->assertOk();
    }

    public function test_the_receipts_grant_separates_reading_the_numbers_from_opening_the_document(): void
    {
        $engineer = $this->makeSeat('Site Engineer');
        $supervisor = $this->makeSeat('Site Supervisor');

        // Two roles, two columns on the same site: the engineer manages it,
        // the supervisor runs it. Both put them in `scopedSiteIds`.
        Site::query()->where('id', $this->site->id)->update([
            'site_manager_id' => $engineer[1]->id,
            'site_supervisor_id' => $supervisor[1]->id,
        ]);

        [, $claimant] = $this->signInAs('Employee', [
            'primary_site_id' => $this->site->id,
            'primary_project_id' => $this->project->id,
        ]);

        $id = $this->claim([
            'expense_category_id' => $this->expenseCategoryId('TRANSPORT'),
            'project_id' => $this->project->id,
            'site_id' => $this->site->id,
        ])['id'];

        $this->post('/api/v1/expenses/'.$id.'/receipts', [
            'receipts' => [UploadedFile::fake()->createWithContent('docket.pdf', self::PDF)],
        ])->assertOk();

        $receiptId = (int) ExpenseReceipt::query()->where('expense_id', $id)->value('id');

        // The supervisor of this very site: may read the claim, and holds
        // `expenses.receipts.view`, so the evidence follows.
        $this->become($supervisor[0]);
        $this->getJson('/api/v1/expenses/'.$id)->assertOk();
        $this->get('/api/v1/expenses/'.$id.'/receipts/'.$receiptId)->assertOk();

        // The site engineer is scoped to the same site through their own
        // column, so they read the claim too — but their grant deliberately
        // stops short of `expenses.receipts.view`, and the document stays
        // closed. Two permissions, two answers, one claim.
        $this->become($engineer[0]);
        $this->getJson('/api/v1/expenses/'.$id)->assertOk();
        $this->get('/api/v1/expenses/'.$id.'/receipts/'.$receiptId)->assertForbidden();

        $this->assertSame($claimant->id, Expense::query()->findOrFail($id)->employee_id);
    }

    public function test_a_receipt_belongs_to_one_claim_and_cannot_be_reached_through_another(): void
    {
        $this->signInAs('Employee');

        $first = $this->claim(['expense_category_id' => $this->expenseCategoryId('TRANSPORT')])['id'];
        $second = $this->claim(['expense_category_id' => $this->expenseCategoryId('TRANSPORT')])['id'];

        $this->post('/api/v1/expenses/'.$first.'/receipts', [
            'receipts' => [UploadedFile::fake()->image('on-the-first.jpg', 60, 60)],
        ])->assertOk();

        $receiptId = (int) ExpenseReceipt::query()->where('expense_id', $first)->value('id');

        // Both claims are the caller's, so the refusal is not about who is
        // asking — the receipt simply is not attached to the second one.
        $this->get('/api/v1/expenses/'.$second.'/receipts/'.$receiptId)->assertForbidden();
        $this->deleteJson('/api/v1/expenses/'.$second.'/receipts/'.$receiptId)->assertForbidden();

        $this->assertSame(1, ExpenseReceipt::query()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    /* ---------------------------------------------------------- validation */

    public function test_a_file_that_is_not_a_receipt_is_refused_and_nothing_is_written(): void
    {
        $this->signInAs('Employee');
        $id = $this->claim(['expense_category_id' => $this->expenseCategoryId('TRANSPORT')])['id'];

        // A payload wearing a PDF's name. `mimes` and `mimetypes` both read
        // the *name*, so this is exactly what the content rule exists for.
        $this->post('/api/v1/expenses/'.$id.'/receipts', [
            'receipts' => [UploadedFile::fake()->createWithContent('note.pdf', '<html><body>hi</body></html>')],
        ])->assertUnprocessable();

        $this->assertSame([], Storage::disk('local')->allFiles());

        // A name outside the allow-list, however plausible its bytes.
        $this->post('/api/v1/expenses/'.$id.'/receipts', [
            'receipts' => [UploadedFile::fake()->createWithContent('notes.txt', self::PDF)],
        ])->assertUnprocessable();

        $this->assertSame([], Storage::disk('local')->allFiles());

        // The ceiling is a config value, not a constant.
        config(['hrms.storage.expense_receipt_max_kilobytes' => 4]);

        $this->post('/api/v1/expenses/'.$id.'/receipts', [
            'receipts' => [UploadedFile::fake()->createWithContent(
                'big.pdf',
                str_repeat('%PDF-1.4 a valid-looking line', 2000),
            )],
        ])->assertUnprocessable();

        $this->assertSame([], Storage::disk('local')->allFiles());

        // Nothing at all: an empty batch is not a request to attach nothing.
        $this->post('/api/v1/expenses/'.$id.'/receipts', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('receipts');

        $this->assertSame(0, ExpenseReceipt::query()->count(), 'A refused upload writes no row either.');
    }

    public function test_a_category_that_demands_evidence_will_not_submit_without_it(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');
        $this->signInAs('Employee', ['reporting_manager_id' => $supervisor[1]->id]);

        // Transport ships with `requires_receipt = true`.
        $needsPaper = $this->claim(['expense_category_id' => $this->expenseCategoryId('TRANSPORT')])['id'];

        $this->postJson('/api/v1/expenses/'.$needsPaper.'/submit')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('receipts');

        $this->assertSame(
            'draft',
            Expense::query()->findOrFail($needsPaper)->status,
            'The refusal left it editable — the folder fills up after the claim is written.',
        );

        // Other does not, and says so from its own row.
        $noPaper = $this->claim(['expense_category_id' => $this->expenseCategoryId('OTHER')])['id'];
        $this->postJson('/api/v1/expenses/'.$noPaper.'/submit')->assertOk();

        // Evidence arrives, and the claim goes.
        $this->post('/api/v1/expenses/'.$needsPaper.'/receipts', [
            'receipts' => [UploadedFile::fake()->createWithContent('fare.pdf', self::PDF)],
        ])->assertOk();

        $this->postJson('/api/v1/expenses/'.$needsPaper.'/submit')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');
    }

    /* -------------------------------------------------------------- state */

    public function test_receipts_can_only_be_changed_while_the_claim_is_a_draft(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');
        $this->signInAs('Employee', ['reporting_manager_id' => $supervisor[1]->id]);

        $id = $this->claim(['expense_category_id' => $this->expenseCategoryId('TRANSPORT')])['id'];

        $this->post('/api/v1/expenses/'.$id.'/receipts', [
            'receipts' => [UploadedFile::fake()->image('first.jpg', 50, 50)],
        ])->assertOk();

        $receiptId = (int) ExpenseReceipt::query()->where('expense_id', $id)->value('id');

        $this->postJson('/api/v1/expenses/'.$id.'/submit')->assertOk();

        // A claim waiting in a queue is not a draft, and its evidence is
        // part of what the approver is reading.
        $this->post('/api/v1/expenses/'.$id.'/receipts', [
            'receipts' => [UploadedFile::fake()->image('second.jpg', 50, 50)],
        ])->assertStatus(409);

        $this->deleteJson('/api/v1/expenses/'.$id.'/receipts/'.$receiptId)->assertStatus(409);

        $this->assertSame(1, ExpenseReceipt::query()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_removing_a_receipt_takes_the_bytes_with_it(): void
    {
        $this->signInAs('Employee');
        $id = $this->claim(['expense_category_id' => $this->expenseCategoryId('TRANSPORT')])['id'];

        $this->post('/api/v1/expenses/'.$id.'/receipts', [
            'receipts' => [
                UploadedFile::fake()->image('keep.jpg', 50, 50),
                UploadedFile::fake()->createWithContent('drop.pdf', self::PDF),
            ],
        ])->assertOk();

        $dropId = (int) ExpenseReceipt::query()
            ->where('expense_id', $id)
            ->where('mime_type', 'application/pdf')
            ->value('id');

        $this->deleteJson('/api/v1/expenses/'.$id.'/receipts/'.$dropId)
            ->assertOk()
            ->assertJsonPath('data.receipt_count', 1);

        $this->assertSame(1, ExpenseReceipt::query()->where('expense_id', $id)->count());
        $this->assertCount(1, Storage::disk('local')->allFiles(), 'The row is gone and so are its bytes.');

        $this->deleteJson('/api/v1/expenses/'.$id.'/receipts/'.$dropId)->assertNotFound();
    }

    public function test_a_claim_carries_a_bounded_number_of_receipts(): void
    {
        $this->signInAs('Employee');
        $id = $this->claim(['expense_category_id' => $this->expenseCategoryId('TRANSPORT')])['id'];

        $this->post('/api/v1/expenses/'.$id.'/receipts', [
            'receipts' => collect(range(1, 6))
                ->map(fn (int $n) => UploadedFile::fake()->image('batch-a-'.$n.'.jpg', 40, 40))
                ->all(),
        ])->assertOk();

        $this->post('/api/v1/expenses/'.$id.'/receipts', [
            'receipts' => collect(range(7, 10))
                ->map(fn (int $n) => UploadedFile::fake()->image('batch-b-'.$n.'.jpg', 40, 40))
                ->all(),
        ])->assertOk();

        $this->assertSame(10, ExpenseReceipt::query()->where('expense_id', $id)->count());

        // Eleven is one too many — the ceiling is a fact about a claim, not
        // about how the batch happened to be split.
        $this->post('/api/v1/expenses/'.$id.'/receipts', [
            'receipts' => [UploadedFile::fake()->image('one-too-many.jpg', 40, 40)],
        ])->assertStatus(422);

        $this->assertSame(10, ExpenseReceipt::query()->where('expense_id', $id)->count());
    }
}
