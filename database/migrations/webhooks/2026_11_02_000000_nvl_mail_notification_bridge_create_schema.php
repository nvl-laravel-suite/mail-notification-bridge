<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\MailNotificationBridge\Webhooks\ReceiptStore;
use Nvl\Support\Config\PackageStorage;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::connection(PackageStorage::connection('mail-notification-bridge'))->getSchemaBuilder()->create(ReceiptStore::table(), function (Blueprint $table) {
            $table->id();
            $table->string('connection', 100);
            $table->uuid('event_id');
            $table->mediumText('payload');
            $table->string('payload_hash', 64);
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('enqueue_until')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['connection', 'event_id'], 'nvl_mail_bridge_receipt_identity');
            $table->index(['status', 'next_attempt_at'], 'nvl_mail_bridge_receipt_recovery');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::connection(PackageStorage::connection('mail-notification-bridge'))->getSchemaBuilder()->dropIfExists(ReceiptStore::table());
    }
};
