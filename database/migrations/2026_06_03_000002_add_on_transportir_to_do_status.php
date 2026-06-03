<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
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

    public function down(): void
    {
        DB::statement("ALTER TABLE delivery_orders MODIFY COLUMN status ENUM(
            'draft',
            'pending_approval',
            'approved',
            'in_transit',
            'delivered',
            'cancelled'
        ) NOT NULL DEFAULT 'draft'");
    }
};
