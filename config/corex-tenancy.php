<?php

declare(strict_types=1);

return [
    'wakeup' => [
        // The portable default never touches the root database. A deployment
        // explicitly chooses root_registry only after installing corex/wakeup.
        'driver' => env('COREX_TENANCY_WAKEUP_DRIVER', 'local_sweep'),
        'root_connection' => env('COREX_TENANCY_WAKEUP_ROOT_CONNECTION', 'pgsql'),
        'lease_seconds' => (int) env('COREX_TENANCY_WAKEUP_LEASE_SECONDS', 60),
    ],
];
