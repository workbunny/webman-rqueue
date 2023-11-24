<?php declare(strict_types=1);

use function Workbunny\WebmanRqueue\runtime_path;

return [
    'connections' => [
        'local-storage' => [
            'driver'   => 'sqlite',
            'database' => runtime_path() . '/workbunny/webman-rqueue/temp.db',
            'prefix'   => '',
        ],
    ]
];
