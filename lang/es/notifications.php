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
            'description' => 'Una vez al mes, un resumen de los logros que tiene poca gente o de los de retos que consiguieron el mes anterior las personas cuyo perfil puedes ver, si quieren anunciarlos.',
        ],
    ],

    'earned' => [
        'one' => 'Has conseguido :name',
        'many' => 'Has conseguido :count logros',
        'grouped' => 'Has conseguido logros nuevos',
    ],

    'announced' => [
        'one' => ':person consiguió logros raros en :month',
        'two' => ':first y :second consiguieron logros raros en :month',
        'many' => ':first, :second y :count más consiguieron logros raros en :month|:first, :second y :count más consiguieron logros raros en :month',
        'person' => ':person: :achievements',
    ],

    'list' => [
        'and' => 'y',
        'more' => ':count más|:count más',
        'secrets' => 'un logro secreto|:count logros secretos',
    ],
];
