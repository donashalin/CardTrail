<?php

use App\Models\Import;
use App\Models\Period;
use App\Models\Reconciliation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/** Build a statement CSV. Each row is a list of cells; cells containing commas must be quoted by the caller. */
function statementCsv(array $rows, string $header = 'Date,Description,Card Member,Account #,Amount'): string
{
    return $header."\n".implode("\n", array_map(fn (array $row) => implode(',', $row), $rows))."\n";
}

/** A draft import whose stored file is this CSV. Call Storage::fake('local') first. */
function importFromCsv(string $csv, array $attributes = []): Import
{
    $import = Import::factory()->create(['original_filename' => 'statement.csv', ...$attributes]);
    Storage::disk('local')->put($import->stored_path, $csv);

    return $import;
}

/** A line on this cardholder's reconciliation, in a published period unless one is given. */
function lineFor(User $user, array $attributes = [], ?Period $period = null): Transaction
{
    $period ??= Period::factory()->published()->create();
    $import = $period->imports()->first() ?? Import::factory()->for($period)->confirmed()->create();
    $reconciliation = Reconciliation::firstOrCreate(['period_id' => $period->id, 'user_id' => $user->id]);

    return Transaction::factory()->for($import)->create(['reconciliation_id' => $reconciliation->id, ...$attributes]);
}
