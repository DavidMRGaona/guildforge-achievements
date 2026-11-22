<?php

declare(strict_types=1);

return [
    'categories' => [
        'section' => 'Logros',
        'earned' => [
            'label' => 'Logros que consigo',
            'description' => 'Un aviso cuando consigues un logro. Los que se conceden a la vez llegan juntos, en un solo aviso.',
        ],
        'announcements' => [
            'label' => 'Logros poco comunes de otras personas',
            'description' => 'Un aviso cuando alguien cuyo perfil puedes ver consigue un logro que tiene poca gente o el de un reto, si esa persona quiere anunciarlo.',
        ],
    ],

    'earned' => [
        'one' => 'Has conseguido :name',
        'many' => 'Has conseguido :count logros',
        'grouped' => 'Has conseguido logros nuevos',
    ],

    'announced' => [
        'one' => ':person ha conseguido :name',
        'secret' => ':person ha conseguido un logro secreto',
        'many' => ':person ha conseguido :count logros',
        'grouped' => ':person ha conseguido más logros',
    ],

    'list' => [
        'and' => 'y',
        'more' => ':count más|:count más',
        'secrets' => 'un logro secreto|:count logros secretos',
    ],
];
