<?php

return [
    // Grup Navigasi
    'group' => [
        'dashboard' => 'Dashboard',
        'stock'     => 'Manajemen Stok',
        'delivery'  => 'DO & Pengiriman',
        'sales'     => 'Penjualan',
        'finance'   => 'Keuangan',
        'master'    => 'Data Master',
        'reports'   => 'Laporan',
    ],

    // Item Navigasi
    'item' => [
        // Dashboard
        'main_dashboard'   => 'Dashboard Utama',
        'branch_dashboard' => 'Cabang Saya',

        // Stok
        'stock_realtime'  => 'Stok Real Time',
        'stock_mutation'  => 'Mutasi Stok',
        'stock_summary'   => 'Rekap Kosong vs Isi',
        'stock_alert'     => 'Peringatan Stok Habis',

        // DO
        'do_request'   => 'Request DO Baru',
        'do_approval'  => 'Persetujuan DO',
        'do_tracking'  => 'Lacak Kiriman',
        'do_history'   => 'Histori DO',

        // Penjualan
        'sales_input'   => 'Input Penjualan Harian',
        'sales_report'  => 'Laporan Penjualan',
        'sales_ranking' => 'Ranking Cabang',

        // Keuangan
        'finance_revenue'   => 'Omzet',
        'finance_cogs'      => 'HPP Tabung',
        'finance_logistics' => 'Biaya Logistik',
        'finance_pl'        => 'Laba Rugi',

        // Data Master
        'master_branches'    => 'Daftar Cabang',
        'master_expeditions' => 'Daftar Ekspedisi',
        'master_prices'      => 'Harga Dasar LPG',
        'master_users'       => 'User & Akses',

        // Laporan
        'report_export'       => 'Ekspor Excel',
        'report_audit'        => 'Laporan Audit Stok',
        'report_consolidated' => 'Laba Rugi Konsolidasi',
    ],
];
