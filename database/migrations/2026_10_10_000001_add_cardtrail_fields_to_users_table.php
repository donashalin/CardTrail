<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
            $table->boolean('use_microsoft')->default(false)->after('password');
            $table->string('external_id')->nullable()->unique()->after('use_microsoft');
            $table->boolean('is_admin')->default(false)->after('external_id');
            $table->boolean('is_cardholder')->default(true)->after('is_admin');
            $table->boolean('active')->default(true)->after('is_cardholder');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->dropColumn(['use_microsoft', 'external_id', 'is_admin', 'is_cardholder', 'active']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
