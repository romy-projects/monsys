<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // T7-01: document_type discriminator (so/do/lo/po)
        // MySQL: use ENUM + AFTER clause; SQLite: use string column (SQLite doesn't enforce ENUM)
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE delivery_orders ADD COLUMN document_type ENUM('so','do','lo','po') NOT NULL DEFAULT 'do' AFTER do_number");
        } else {
            Schema::table('delivery_orders', function (Blueprint $table) {
                $table->string('document_type', 10)->default('do')->after('do_number');
            });
        }

        // T7-02: counterparty fields (Pertamina or Branch)
        Schema::table('delivery_orders', function (Blueprint $table) {
            if (DB::getDriverName() === 'mysql') {
                $table->enum('counterparty_type', ['pertamina', 'branch'])->nullable()->after('document_type')
                    ->comment('Who the order is with: Pertamina (upstream) or Branch (downstream)');
            } else {
                $table->string('counterparty_type', 20)->nullable()->after('document_type')
                    ->comment('Who the order is with: Pertamina (upstream) or Branch (downstream)');
            }
            $table->string('counterparty_name', 200)->nullable()->after('counterparty_type')
                ->comment('Free-text name, e.g. Pertamina or branch name');
        });

        // T7-03: reference chain fields
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->string('so_number', 100)->nullable()->after('counterparty_name')
                ->comment('Reference SO number (for DO/LO created from a Sales Order)');
            $table->string('po_number', 100)->nullable()->after('so_number')
                ->comment('Reference PO number (for DO created from a Purchase Order)');
        });

        // T7-04: loading fields for LO
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->date('loading_date')->nullable()->after('po_number')
                ->comment('Date the tabung was loaded onto the transportir vehicle');
            $table->foreignId('loaded_by')->nullable()->after('loading_date')
                ->constrained('users')->nullOnDelete()
                ->comment('User who confirmed the loading');
        });

        // T7-05: container_no — existing container_number field already covers this.
        // No new column needed; verified in DeliveryOrder model + resource.
    }

    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('loaded_by');
            $table->dropColumn(['loading_date', 'po_number', 'so_number', 'counterparty_name', 'counterparty_type']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE delivery_orders DROP COLUMN document_type");
        } else {
            Schema::table('delivery_orders', function (Blueprint $table) {
                $table->dropColumn('document_type');
            });
        }
    }
};
