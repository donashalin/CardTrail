<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/** Invented accounts for local testing. Never put real staff or card details here. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->account('Alex Accounts', 'admin@cardtrail.test', ['is_admin' => true, 'is_cardholder' => false]);
        $this->account('Sam Cardholder', 'sam@cardtrail.test', [], '1001', 'S CARDHOLDER');
        $this->account('Jo Manager', 'jo@cardtrail.test', [], '1002', 'J MANAGER');
        $this->account('Morgan Microsoft', 'morgan@cardtrail.test', ['use_microsoft' => true, 'password' => null], '1003', 'M MICROSOFT');
    }

    private function account(string $name, string $email, array $attributes = [], ?string $last4 = null, ?string $cardholderName = null): void
    {
        $user = User::updateOrCreate(['email' => $email], [
            'name' => $name,
            'password' => Hash::make('password'),
            ...$attributes,
        ]);

        if ($last4) {
            $user->cardIdentifiers()->firstOrCreate(['last4' => $last4], ['cardholder_name' => $cardholderName]);
        }
    }
}
