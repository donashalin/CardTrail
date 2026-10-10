<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Demo accounts have a known password, so they must never reach a real server.
        if (app()->environment('local', 'testing')) {
            $this->call(DemoSeeder::class);
        }
    }
}
