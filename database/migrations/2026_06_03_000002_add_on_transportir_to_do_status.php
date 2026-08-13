<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL: modify the enum to add 'on_transportir'
        // SQLite: no-op — SQLite doesn't enforce enum constraints, so the value is already accepted
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE delivery_orders MODIFY COLUMN status ENUM(
                'draft',
                'pending_approval',
                'approved',
                'in_transit',
                'on_transportir',
                'delivered',
                'cancelled'
            ) NOT NULL DEFAULT 'draft'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE delivery_orders MODIFY COLUMN status ENUM(
                'draft',
                'pending_approval',
                'approved',
                'in_transit',
                'delivered',
                'cancelled'
            ) NOT NULL DEFAULT 'draft'");
        }
    }
};
