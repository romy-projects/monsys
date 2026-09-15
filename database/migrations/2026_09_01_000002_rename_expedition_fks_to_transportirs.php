<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── payables: expedition_id → transportir_id ─────────────────
        if (Schema::hasColumn('payables', 'expedition_id') && ! Schema::hasColumn('payables', 'transportir_id')) {
            Schema::table('payables', function (Blueprint $table) {
                $table->dropForeign(['expedition_id']);
                $table->renameColumn('expedition_id', 'transportir_id');
                $table->foreign('transportir_id')->references('id')->on('transportirs')->cascadeOnDelete();
            });
        }

        // ── vehicles: expedition_id → transportir_id ─────────────────
        if (Schema::hasColumn('vehicles', 'expedition_id') && ! Schema::hasColumn('vehicles', 'transportir_id')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->dropForeign(['expedition_id']);
                $table->renameColumn('expedition_id', 'transportir_id');
                $table->foreign('transportir_id')->references('id')->on('transportirs')->nullOnDelete();
            });
        }

        // ── delivery_orders: expedition_id → transportir_id ──────────
        if (Schema::hasColumn('delivery_orders', 'expedition_id') && ! Schema::hasColumn('delivery_orders', 'transportir_id')) {
            Schema::table('delivery_orders', function (Blueprint $table) {
                $table->dropForeign(['expedition_id']);
                $table->renameColumn('expedition_id', 'transportir_id');
                $table->foreign('transportir_id')->references('id')->on('transportirs')->nullOnDelete();
            });
        }

        // ── delivery_orders: add NEW expedition_id (branch-shipping) ─
        if (! Schema::hasColumn('delivery_orders', 'expedition_id')) {
            Schema::table('delivery_orders', function (Blueprint $table) {
                $table->foreignId('expedition_id')->nullable()->after('transportir_id');
            });
        }

        // MySQL leaves the old FK's index behind after dropForeign + renameColumn, and the
        // new transportir FK reuses that leftover index. Drop the transportir FK first, then
        // the leftover index, then re-add the transportir FK (which creates a fresh index).
        if (DB::getDriverName() === 'mysql') {
            $leftover = DB::select("SHOW INDEX FROM delivery_orders WHERE Key_name = 'delivery_orders_expedition_id_foreign'");
            if (! empty($leftover)) {
                Schema::table('delivery_orders', function (Blueprint $table) {
                    $table->dropForeign('delivery_orders_transportir_id_foreign');
                });
                DB::statement('ALTER TABLE delivery_orders DROP INDEX delivery_orders_expedition_id_foreign');
                Schema::table('delivery_orders', function (Blueprint $table) {
                    $table->foreign('transportir_id', 'delivery_orders_transportir_id_foreign')
                        ->references('id')->on('transportirs')->nullOnDelete();
                });
            }
        }

        // Add the new FK with a custom name to avoid any name collision.
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->foreign('expedition_id', 'delivery_orders_expedition_id_fk_branch')
                ->references('id')->on('expeditions')->nullOnDelete();
        });

        // ── Clear expeditions — data was copied to transportirs ──────
        DB::table('expeditions')->delete();
    }

    public function down(): void
    {
        // ── delivery_orders: drop new expedition_id FK + column ──────
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropForeign('delivery_orders_expedition_id_fk_branch');
            $table->dropColumn('expedition_id');
        });

        // ── delivery_orders: transportir_id → expedition_id ──────────
        if (Schema::hasColumn('delivery_orders', 'transportir_id') && ! Schema::hasColumn('delivery_orders', 'expedition_id')) {
            Schema::table('delivery_orders', function (Blueprint $table) {
                $table->dropForeign(['transportir_id']);
                $table->renameColumn('transportir_id', 'expedition_id');
                $table->foreign('expedition_id')->references('id')->on('expeditions')->nullOnDelete();
            });
        }

        // ── vehicles: transportir_id → expedition_id ─────────────────
        if (Schema::hasColumn('vehicles', 'transportir_id') && ! Schema::hasColumn('vehicles', 'expedition_id')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->dropForeign(['transportir_id']);
                $table->renameColumn('transportir_id', 'expedition_id');
                $table->foreign('expedition_id')->references('id')->on('expeditions')->nullOnDelete();
            });
        }

        // ── payables: transportir_id → expedition_id ─────────────────
        if (Schema::hasColumn('payables', 'transportir_id') && ! Schema::hasColumn('payables', 'expedition_id')) {
            Schema::table('payables', function (Blueprint $table) {
                $table->dropForeign(['transportir_id']);
                $table->renameColumn('transportir_id', 'expedition_id');
                $table->foreign('expedition_id')->references('id')->on('expeditions')->cascadeOnDelete();
            });
        }
    }
};
