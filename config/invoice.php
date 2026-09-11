<?php

return [
    'company' => [
        'name' => 'albedo THE EDUCATOR',
        'phone' => '+91 8590 800 133',
        'email' => 'Hello.albedoeducator@gmail.com',
        'website' => 'albedoeducator.com',
        'social' => '@albedoeducator',
        'address' => '2nd Floor, Korambayil Corporate Mall, Calicut Road, Manjeri, Malappuram, Kerala - INDIA.',
    ],

    'payment' => [
        'gpay_number' => '9747858725',
        'gpay_id' => '9747858725-2@okbizaxis',
        'account_number' => '023706700003011',
        'account_name' => 'Albedo Educator Private Limited',
        'ifsc' => 'DLXB0000237',
        'branch' => 'DHANLAXMI BANK LIMITED, MANJERI',
    ],

    'assets' => [
        // Derived from albedo-marketing/public/favicon.ico (JPEG avoids DomPDF GD requirement).
        'logo' => public_path('invoice/logo.jpg'),
    ],

    'number_prefix' => 'AQ',
];
