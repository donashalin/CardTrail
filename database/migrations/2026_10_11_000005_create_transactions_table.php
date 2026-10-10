<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reconciliation_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('row_number');
            $table->date('transacted_on');
            $table->string('description', 500);
            $table->bigInteger('amount');
            $table->string('card_ref', 100)->nullable();
            $table->json('extra')->nullable();
            $table->text('purpose')->nullable();
            $table->boolean('no_receipt')->default(false);
            $table->text('no_receipt_reason')->nullable();
            $table->string('status', 20)->default('todo');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['import_id', 'row_number']);
            $table->index(['period_id', 'reconciliation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
