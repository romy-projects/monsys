<?php

return [
    // Navigation Groups
    'group' => [
        'dashboard' => 'Dashboard',
        'stock'     => 'Stock Management',
        'delivery'  => 'DO & Delivery',
        'sales'     => 'Sales',
        'finance'   => 'Finance',
        'master'    => 'Master Data',
        'reports'   => 'Reports',
    ],

    // Navigation Items
    'item' => [
        // Dashboard
        'main_dashboard'   => 'Main Dashboard',
        'branch_dashboard' => 'My Branch',

        // Stock
        'stock_realtime'  => 'Real-time Stock',
        'stock_mutation'  => 'Stock Mutations',
        'stock_summary'   => 'Empty vs Full Summary',
        'stock_alert'     => 'Low Stock Alerts',
        'stock_close'     => 'Daily Stock Close',

        // DO
        'do_request'   => 'Request DO',
        'do_approval'  => 'DO Approvals',
        'do_tracking'  => 'Shipment Tracking',
        'do_history'   => 'DO History',

        // Sales
        'sales_input'   => 'Daily Sales Input',
        'sales_report'  => 'Sales Reports',
        'sales_ranking' => 'Branch Rankings',
        'sales_target'  => 'Sales Targets',

        // Finance
        'finance_revenue'    => 'Revenue',
        'finance_cogs'       => 'Cost of Goods',
        'finance_logistics'  => 'Logistics Costs',
        'finance_pl'         => 'Profit & Loss',
        'receivables'        => 'Receivables',
        'receivables_aging'  => 'Receivables Aging',

        // Master
        'master_branches'    => 'Branches',
        'master_expeditions' => 'Expeditions',
        'master_prices'      => 'LPG Prices',
        'master_users'       => 'Users & Access',
        'customer'           => 'Customers',
        'vehicle'            => 'Vehicles & Drivers',

        // Reports
        'report_export'      => 'Export Excel',
        'report_audit'       => 'Stock Audit',
        'report_consolidated'=> 'Consolidated P&L',
        'notification_log'   => 'Notification Log',
    ],
];
