<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BALI-11: split Loading Orders into two distinct documents.
 *
 * transportir — the transportir arranges (and pays for) its own expedition.
 * expedition  — HQ/Pusat picks the expedition and bears the delivery fee.
 *
 * Both stay on `delivery_orders` (document_type = 'lo') so existing data,
 * relations and reports keep working; `lo_kind` is the discriminator.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE delivery_orders ADD COLUMN lo_kind ENUM('transportir','expedition') NOT NULL DEFAULT 'transportir' AFTER document_type");
        } else {
            Schema::table('delivery_orders', function (Blueprint $table) {
                $table->string('lo_kind', 20)->default('transportir')->after('document_type');
            });
        }

        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->index('lo_kind');
        });

        // Every pre-existing LO is a transportir LO: the transportir used to
        // arrange its own expedition, so no cost was ever recorded by HQ.
        DB::table('delivery_orders')
            ->where('document_type', 'lo')
            ->update(['lo_kind' => 'transportir']);
    }

    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropIndex(['lo_kind']);
            $table->dropColumn('lo_kind');
        });
    }
};
