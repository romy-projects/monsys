<?php

return [
    // Dashboard widgets
    'total_stock'        => 'Total All Stock',
    'total_stock_id'     => 'Total Semua Stok',
    'today_sales'        => "Today's Sales",
    'today_sales_id'     => 'Penjualan Hari Ini',
    'pending_do'         => 'Pending DO',
    'pending_do_id'      => 'DO Menunggu',
    'low_stock_branches' => 'Low Stock Branches',
    'low_stock_id'       => 'Cabang Stok Menipis',

    // DO status
    'do_status' => [
        'draft'            => 'Draft',
        'pending_approval' => 'Pending Approval / Menunggu Persetujuan',
        'approved'         => 'Approved / Disetujui',
        'in_transit'       => 'In Transit / Dalam Perjalanan',
        'delivered'        => 'Delivered / Terkirim',
        'cancelled'        => 'Cancelled / Dibatalkan',
    ],

    // Roles
    'role' => [
        'owner_pusat'    => 'Owner Pusat / HQ Owner',
        'regional_leader'=> 'Regional Leader',
        'owner_cabang'   => 'Branch Owner / Owner Cabang',
        'staff_gudang'   => 'Warehouse Staff / Staff Gudang',
    ],

    // Cylinder types
    'cylinder' => [
        '3kg'   => '3 Kg',
        '5.5kg' => '5.5 Kg',
        '12kg'  => '12 Kg',
        '50kg'  => '50 Kg',
    ],

    // Cost categories
    'cost_category' => [
        'fuel'      => 'Fuel / BBM',
        'salary'    => 'Salary / Gaji',
        'logistics' => 'Logistics / Ongkir',
        'levy'      => 'Levy / Retribusi',
        'other'     => 'Other / Lain-lain',
    ],
];
