<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('transportirs')) {
            return;
        }

        Schema::create('transportirs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 20)->unique();
            $table->string('phone')->nullable();
            $table->string('contact_person')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });

        // Copy all existing expeditions rows → transportirs (preserving IDs).
        // Per business decision: all existing data in `expeditions` is transportir data.
        $rows = DB::table('expeditions')->get();

        foreach ($rows as $row) {
            DB::table('transportirs')->insert([
                'id'             => $row->id,
                'name'           => $row->name,
                'code'           => $row->code,
                'phone'          => $row->phone,
                'contact_person' => $row->contact_person,
                'status'         => $row->status,
                'created_at'     => $row->created_at,
                'updated_at'     => $row->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transportirs');
    }
};
