<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\DailySale;
use App\Models\DeliveryOrder;
use App\Models\Expedition;
use App\Models\LpgPrice;
use App\Models\OperationalCost;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ─── Branches ────────────────────────────────────────────
        $pusat = Branch::create([
            'code'     => 'HQ-001',
            'name'     => 'Pusat / HQ',
            'city'     => 'Jakarta',
            'province' => 'DKI Jakarta',
            'address'  => 'Jl. Sudirman No. 1',
            'phone'    => '021-1234567',
            'status'   => 'active',
        ]);

        $bandung = Branch::create([
            'code'       => 'CBG-001',
            'name'       => 'Cabang Bandung',
            'city'       => 'Bandung',
            'province'   => 'Jawa Barat',
            'address'    => 'Jl. Braga No. 25',
            'phone'      => '022-9876543',
            'status'     => 'active',
            'regional_id'=> $pusat->id,
        ]);

        $surabaya = Branch::create([
            'code'       => 'CSB-001',
            'name'       => 'Cabang Surabaya',
            'city'       => 'Surabaya',
            'province'   => 'Jawa Timur',
            'address'    => 'Jl. Pemuda No. 45',
            'phone'      => '031-5555222',
            'status'     => 'active',
            'regional_id'=> $pusat->id,
        ]);

        $semarang = Branch::create([
            'code'       => 'CSM-001',
            'name'       => 'Cabang Semarang',
            'city'       => 'Semarang',
            'province'   => 'Jawa Tengah',
            'address'    => 'Jl. Pandanaran No. 10',
            'phone'      => '024-3333111',
            'status'     => 'active',
            'regional_id'=> $pusat->id,
        ]);

        $yogya = Branch::create([
            'code'       => 'CYK-001',
            'name'       => 'Cabang Yogyakarta',
            'city'       => 'Yogyakarta',
            'province'   => 'DI Yogyakarta',
            'address'    => 'Jl. Malioboro No. 77',
            'phone'      => '0274-444555',
            'status'     => 'active',
            'regional_id'=> $pusat->id,
        ]);

        // ─── Users (4 levels) ────────────────────────────────────
        User::create([
            'name'      => 'Avery — Owner Pusat',
            'email'     => 'owner@sumenergynetwork.com',
            'password'  => Hash::make('password'),
            'role'      => 'owner_pusat',
            'branch_id' => $pusat->id,
            'phone'     => '0811-0000001',
            'status'    => 'active',
        ]);

        User::create([
            'name'      => 'Reza — Regional Leader',
            'email'     => 'regional@sumenergynetwork.com',
            'password'  => Hash::make('password'),
            'role'      => 'regional_leader',
            'branch_id' => $pusat->id,
            'phone'     => '0811-0000002',
            'status'    => 'active',
        ]);

        User::create([
            'name'      => 'Budi — Owner Cabang Bandung',
            'email'     => 'bandung@sumenergynetwork.com',
            'password'  => Hash::make('password'),
            'role'      => 'owner_cabang',
            'branch_id' => $bandung->id,
            'phone'     => '0811-0000003',
            'status'    => 'active',
        ]);

        User::create([
            'name'      => 'Sari — Staff Gudang Bandung',
            'email'     => 'staff@sumenergynetwork.com',
            'password'  => Hash::make('password'),
            'role'      => 'staff_gudang',
            'branch_id' => $bandung->id,
            'phone'     => '0811-0000004',
            'status'    => 'active',
        ]);

        // ─── Expeditions ─────────────────────────────────────────
        $ekspedisiA = Expedition::create([
            'name'           => 'PT Ekspedisi Cepat',
            'code'           => 'EXP-A',
            'phone'          => '021-7777888',
            'contact_person' => 'Pak Joko',
            'status'         => 'active',
        ]);

        // ─── Stock Items (demo data per branch) ──────────────────
        $branches = [$pusat, $bandung, $surabaya, $semarang, $yogya];
        foreach ($branches as $branch) {
            StockItem::create([
                'branch_id'    => $branch->id,
                'cylinder_type'=> '3kg',
                'qty_full'     => rand(30, 500),
                'qty_empty'    => rand(10, 200),
                'qty_damaged'  => rand(0, 10),
                'recorded_at'  => today(),
            ]);
            StockItem::create([
                'branch_id'    => $branch->id,
                'cylinder_type'=> '12kg',
                'qty_full'     => rand(10, 150),
                'qty_empty'    => rand(5, 50),
                'qty_damaged'  => rand(0, 5),
                'recorded_at'  => today(),
            ]);
        }

        // ─── Daily Sales (last 14 days demo) ─────────────────────
        $ownerUser = User::where('role', 'owner_cabang')->first();
        foreach ([$bandung, $surabaya, $semarang] as $branch) {
            for ($i = 0; $i < 14; $i++) {
                DailySale::create([
                    'branch_id'     => $branch->id,
                    'cylinder_type' => '3kg',
                    'buyer_type'    => 'retail',
                    'quantity'      => rand(20, 100),
                    'selling_price' => 20000,
                    'sale_date'     => today()->subDays($i),
                    'created_by'    => $ownerUser->id,
                ]);
            }
        }

        // ─── Pending DO (demo) ────────────────────────────────────
        $owner = User::where('role', 'owner_pusat')->first();
        DeliveryOrder::create([
            'do_number'             => 'DO2026-001',
            'origin_branch_id'      => $pusat->id,
            'destination_branch_id' => $bandung->id,
            'expedition_id'         => $ekspedisiA->id,
            'cylinder_type'         => '3kg',
            'quantity_ordered'      => 500,
            'container_number'      => 'CONT-20260501',
            'order_date'            => today()->subDays(2),
            'eta'                   => today()->addDays(3),
            'status'                => 'pending_approval',
            'requested_by'          => User::where('role', 'owner_cabang')->first()->id,
        ]);

        DeliveryOrder::create([
            'do_number'             => 'DO2026-002',
            'origin_branch_id'      => $pusat->id,
            'destination_branch_id' => $surabaya->id,
            'cylinder_type'         => '12kg',
            'quantity_ordered'      => 200,
            'order_date'            => today()->subDays(5),
            'eta'                   => today()->addDays(1),
            'status'                => 'in_transit',
            'requested_by'          => User::where('role', 'owner_cabang')->first()->id,
            'approved_by'           => $owner->id,
            'approved_at'           => now()->subDays(4),
        ]);

        // ─── LPG Prices ──────────────────────────────────────────
        $priceData = [
            ['cylinder_type' => '3kg',   'purchase_price' => 14500,  'selling_price' => 18000],
            ['cylinder_type' => '5.5kg', 'purchase_price' => 38000,  'selling_price' => 45000],
            ['cylinder_type' => '12kg',  'purchase_price' => 85000,  'selling_price' => 95000],
            ['cylinder_type' => '50kg',  'purchase_price' => 350000, 'selling_price' => 390000],
        ];

        foreach ($priceData as $price) {
            LpgPrice::create(array_merge($price, [
                'effective_date' => today()->startOfMonth(),
                'created_by'     => $owner->id,
            ]));
        }

        // ─── Operational Costs (last 30 days demo) ───────────────
        $costBranches = [$bandung, $surabaya, $semarang];
        $categories   = ['fuel', 'salary', 'logistics', 'levy', 'other'];
        $descriptions = [
            'fuel'      => ['BBM operasional kendaraan', 'Bensin genset', 'Solar truk pengiriman'],
            'salary'    => ['Gaji karyawan harian', 'Lembur bulan ini', 'Tunjangan transport'],
            'logistics' => ['Ongkos kirim tabung kosong', 'Biaya bongkar muat', 'Sewa armada'],
            'levy'      => ['Retribusi pasar', 'Biaya izin operasional', 'Iuran RT/kebersihan'],
            'other'     => ['Perlengkapan kantor', 'Biaya tak terduga', 'Perawatan gudang'],
        ];

        foreach ($costBranches as $branch) {
            for ($i = 0; $i < 30; $i++) {
                $category = $categories[array_rand($categories)];
                OperationalCost::create([
                    'branch_id'     => $branch->id,
                    'cost_category' => $category,
                    'description'   => $descriptions[$category][array_rand($descriptions[$category])],
                    'amount'        => rand(50000, 500000),
                    'cost_date'     => today()->subDays($i),
                    'created_by'    => $ownerUser->id,
                ]);
            }
        }
    }
}
