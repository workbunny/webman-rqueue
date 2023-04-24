<?php declare(strict_types=1);

/**
 * Get config
 * @param string|null $key
 * @param $default
 * @return array|mixed|null
 */
function config(string $key = null, $default = null): mixed
{
    return [
        'default' => [
            'host'     => '172.17.0.1',//'redis',
            'password' => '',
            'port'     => 6379,
            'database' => 0,
        ],
    ];
}
