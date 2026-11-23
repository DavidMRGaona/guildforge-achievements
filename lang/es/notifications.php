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
        'recognitions' => [
            'label' => 'Reconocimientos',
            'description' => 'Cuando alguien con quien has jugado te reconoce algo, y una invitación a reconocer a otras personas tras tus partidas y campañas. Solo te llegan a ti.',
        ],
    ],

    'recognitions' => [
        'received' => ':person te ha reconocido: «:type»',
        'received_anonymous' => 'Te han reconocido: «:type»',
        'subject_type' => 'Reconocimiento',
        'prompt_one' => '¿Quieres reconocer a alguien de «:title»?',
        'prompt_many' => '¿Quieres reconocer a alguien de tus últimas partidas?',
        'prompt_grouped' => '¿Quieres reconocer a alguien de tus últimas partidas?',
        'prompt_body' => 'Puedes hacerlo hasta el :date.',
    ],

    'earned' => [
        'one' => 'Has conseguido :name',
        'many' => 'Has conseguido :count logros',
        'grouped' => 'Has conseguido logros nuevos',
    ],

    'announced' => [
        'one' => ':person consiguió :what en :month',
        'two' => ':first y :second consiguieron :what en :month',
        'many' => ':first, :second y :count más consiguieron :what en :month|:first, :second y :count más consiguieron :what en :month',
        'person' => ':person: :achievements',
        'what' => [
            'rare' => 'un logro raro|logros raros',
            'challenge' => 'un logro de un reto|logros de retos',
            'mixed' => 'logros raros y de retos|logros raros y de retos',
        ],
    ],

    'account' => [
        'announcements_off' => 'Los anuncios de logros están apagados ahora mismo: no se puede cambiar.',
    ],

    'list' => [
        'and' => 'y',
        'more' => ':count más|:count más',
        'secrets' => 'un logro secreto|:count logros secretos',
    ],
];
