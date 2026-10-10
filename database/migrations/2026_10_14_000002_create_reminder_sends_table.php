<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reconciliation_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('offset_days');
            $table->date('due_date');
            $table->timestamps();
            // A changed due date gets its own reminders; the same day's reminder never goes twice.
            $table->unique(['reconciliation_id', 'offset_days', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_sends');
    }
};
