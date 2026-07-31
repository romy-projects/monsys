<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add 'branch' to buyer_type enum (MySQL requires DB::statement for enum modification)
        DB::statement("ALTER TABLE receivables MODIFY COLUMN buyer_type ENUM('retail','agen','industri','branch') NOT NULL DEFAULT 'retail'");

        // Add debtor_branch_id FK for piutang pangkalan/cabang lain
        Schema::table('receivables', function (Blueprint $table) {
            $table->foreignId('debtor_branch_id')->nullable()->after('buyer_name')
                ->constrained('branches')->nullOnDelete()
                ->comment('Set when buyer_type=branch — direct FK to debtor branch');
        });
    }

    public function down(): void
    {
        Schema::table('receivables', function (Blueprint $table) {
            $table->dropForeign(['debtor_branch_id']);
            $table->dropColumn('debtor_branch_id');
        });

        DB::statement("ALTER TABLE receivables MODIFY COLUMN buyer_type ENUM('retail','agen','industri') NOT NULL DEFAULT 'retail'");
    }
};
