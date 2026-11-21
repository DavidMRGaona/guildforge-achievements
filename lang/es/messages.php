<?php

declare(strict_types=1);

return [
    'title' => 'Logros',

    'permissions' => [
        'achievements' => [
            'view_any' => 'Ver los logros en el panel',
            'manage' => 'Gestionar el catálogo, los retos y los tipos de reconocimiento',
            'grant' => 'Conceder y retirar logros',
            'configure' => 'Configurar los logros',
        ],
    ],

    'recalculate' => [
        'complete' => ':type: leídos: :subjects · actualizados: :changed · retirados: :removed',
        'missing' => ':type: no hay ninguna fuente activa de este tipo; no se ha cambiado nada',
        'failed' => ':type: la fuente ha fallado (leídos: :subjects · actualizados: :changed); se guarda lo leído y no se retira nada',
        'cycle' => ':type: el historial vuelve a una página ya leída (leídos: :subjects · actualizados: :changed); se para aquí y no se retira nada',
        'no_sources' => 'No hay ninguna fuente de actividad activa.',
        'dry_run' => 'Simulación: no se ha guardado nada.',
    ],

    'settings' => [
        'navigation' => 'Logros',
        'title' => 'Logros',
        'save' => 'Guardar',
        'saved' => 'Ajustes guardados',
        'days' => 'días',

        'sections' => [
            'achievements' => 'Logros',
            'achievements_description' => 'Una mecánica apagada no se ve, sus páginas no existen y sus reglas no se evalúan. Lo que ya se haya concedido se conserva.',
            'announcements' => 'Anuncios',
            'announcements_description' => 'Avisos sobre los logros poco frecuentes de otras personas. Solo los reciben quienes pueden ver el perfil y solo si la persona lo permite.',
            'gamification' => 'Puntos y clasificación',
            'gamification_description' => 'Mecánicas de competición amistosa. Están apagadas hasta que la asociación las quiera.',
            'recognitions' => 'Reconocimientos',
            'recognitions_description' => 'Lo que se dan entre sí quienes han compartido una partida, una campaña o un torneo.',
            'metric_kinds' => 'Tipos de actividad',
            'metric_kinds_description' => 'Qué actividad cuenta para los logros. Un tipo apagado deja de contar, pero lo ya concedido no se retira.',
        ],

        'fields' => [
            'progress' => 'Progreso visible',
            'rarity' => 'Rareza',
            'secrets' => 'Logros secretos',
            'seasons_and_streaks' => 'Temporadas y rachas',
            'community' => 'Logros de comunidad',
            'bulk_grants' => 'Concesión en lote',
            'announcements' => 'Anuncios de logros raros',
            'announcements_rarity_threshold' => 'Umbral de rareza',
            'points' => 'Puntos',
            'levels' => 'Niveles',
            'leaderboard' => 'Clasificación',
            'challenges' => 'Retos',
            'recognitions' => 'Reconocimientos entre personas',
            'recognitions_window_days' => 'Plazo para reconocer',
        ],

        'helpers' => [
            'progress' => 'Cada persona ve cuánto le falta para un logro («7 de 10»).',
            'rarity' => 'Muestra qué parte de la gente activa tiene cada logro.',
            'secrets' => 'Hay logros que no se enseñan hasta conseguirlos.',
            'seasons_and_streaks' => 'Logros que se repiten cada temporada y rachas de meses seguidos.',
            'community' => 'Logros que se consiguen entre todas las personas de la asociación.',
            'bulk_grants' => 'Quien pueda conceder logros puede darlos a varias personas a la vez.',
            'announcements' => 'Avisar a la gente de los logros poco frecuentes que consigue otra persona.',
            'announcements_rarity_threshold' => 'Solo se anuncian los logros que tiene este porcentaje de la gente activa o menos.',
            'points' => 'Cada logro da puntos.',
            'levels' => 'Los puntos suben de nivel.',
            'leaderboard' => 'Página con la clasificación de quienes aparecen en ella.',
            'challenges' => 'Objetivos colectivos con una recompensa para todas las personas que contribuyen.',
            'recognitions' => 'Tras una partida, una campaña o un torneo, los participantes pueden reconocerse.',
            'recognitions_window_days' => 'Días, desde que acaba la partida, la campaña o el torneo, en los que se puede reconocer.',
        ],

        'metric_kinds' => [
            'game_session' => 'Partidas',
            'campaign' => 'Campañas',
            'tournament' => 'Torneos',
            'event' => 'Eventos',
            'forum_post' => 'Mensajes del foro',
            'forum_discussion' => 'Debates del foro',
            'membership_year' => 'Años como socio',
            'survey_response' => 'Respuestas a encuestas',
            'venue_booking' => 'Reservas del local',
            'account' => 'Antigüedad de la cuenta',
            'profile_completed' => 'Perfil completado',
            'proposal' => 'Propuestas',
        ],
    ],
];
