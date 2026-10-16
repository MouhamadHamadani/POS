<?php

/**
 * Vortex Energy client profile — active when POS_CLIENT=vortex.
 * Read through App\Support\Client::config(); see CLAUDE.md, "Client builds".
 */
return [
    /**
     * Business defaults written once by first-run setup (Client::seedSettings),
     * keyed by settings group. After that the admin owns them in Settings.
     */
    'settings' => [
        'general' => [
            'business_name' => 'Vortex Energy',
            // TODO(client): the Arabic trading name. Left blank on purpose so the
            // stock "ليبا سوق" is cleared rather than printed on Vortex receipts.
            'business_name_ar' => '',
        ],
        'receipt' => [
            'receipt_footer' => 'Vortex Energy — Powering the Future',
        ],
    ],
];
