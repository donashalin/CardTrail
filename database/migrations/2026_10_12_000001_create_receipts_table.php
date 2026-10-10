<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Deleting a line (e.g. removing an import) puts its receipts back in the owner's inbox.
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('disk', 50);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->string('source', 20);
            $table->unsignedBigInteger('inbound_message_id')->nullable()->index();
            $table->timestamps();
            $table->index(['user_id', 'transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
