export default {
    achievements: {
        title: 'Logros',
        rarity: {
            nobody: 'Ninguna persona activa lo tiene todavía',
            share: 'Lo tiene el {share} % de la gente activa',
        },
        profile: {
            secretMore: 'Y {count} logro secreto. | Y {count} logros secretos.',
            secretOnly: '{count} logro secreto. | {count} logros secretos.',
            earned: 'Conseguido ({count}) | Conseguidos ({count})',
            locked: 'Te falta ({count}) | Te faltan ({count})',
            progress: '{value} de {threshold}',
            progressMonths: '{value} de {threshold} meses seguidos',
            manual: 'Se concede a mano.',
            thisSeason: 'Temporada {season}',
            seasonsGot: 'Conseguido en {seasons}',
            featured: 'Destacado',
            feature: 'Destacar «{name}»',
            featuredFull: 'Ya tienes {max} destacados: quita uno para destacar otro.',
            featuredChosen:
                'Destacas {count} de {max} junto a tu nombre. Pulsa la estrella de un logro para cambiarlo.',
            featuredAuto:
                'Junto a tu nombre salen tus {max} logros más raros. Pulsa la estrella de un logro para elegirlos tú.',
            featuredAdded: '«{name}» destacado.',
            featuredRemoved: '«{name}» ya no está destacado.',
            showToOthers: 'Mostrar mis logros a los demás',
            shownHelp:
                'Quien puede ver tu perfil ve tus logros, su número y los destacados junto a tu nombre.',
            hiddenHelp: 'Solo tú ves tus logros: ni en tu perfil, ni junto a tu nombre en el foro.',
            nowHidden: 'Tus logros ya solo los ves tú.',
            nowShown: 'Tus logros vuelven a verse en tu perfil.',
            saveFailed: 'No se ha podido guardar. Inténtalo de nuevo.',
            readOnly: 'Mientras se mira la web como otra persona no se guarda nada.',
            tooMany: 'Demasiados cambios seguidos. Espera un momento y vuelve a probar.',
            seeAll: 'Ver todos los logros',
        },
        challenges: {
            title: 'Retos de la asociación',
            subtitle:
                'Objetivos entre todas las personas. Quien contribuye recibe el logro de recompensa cuando se consigue.',
            progress: '{progress} de {target} {unit}',
            days: 'Del {start} al {end}',
            membersOnly: 'Solo socios',
            states: {
                upcoming: 'Próximamente',
                running: 'En marcha',
                completed: 'Conseguido',
                ended: 'Terminado',
                closed: 'Cerrado',
            },
            contributors:
                'Nadie ha contribuido todavía | {count} persona ha contribuido | {count} personas han contribuido',
            yourContribution: 'Has participado {count} vez | Has participado {count} veces',
            noContribution: 'Todavía no has contribuido',
            reward: 'Recompensa',
            peopleTitle: 'Algunas de las personas que lo han conseguido:',
            notCounted: 'Aún no se ha contado. Objetivo: {target} {unit}.',
            block: {
                until: 'Hasta el {day}',
                startsOn: 'Empieza el {day}',
                seeAll: 'Ver los retos',
            },
        },
        account: {
            tabLabel: 'Logros',
            title: 'Logros',
            announceRare: {
                label: 'Anunciar mis logros raros',
                help: 'Una vez al mes, quien puede ver tu perfil recibe un resumen con tus logros del mes anterior que tiene menos del {threshold} % de la gente activa y los de retos. Solo dice el nombre de cada logro: ni el día ni la hora.',
                hidden: 'Ahora mismo tus logros están ocultos: no se anuncia ninguno.',
                on: 'Tus logros raros se anunciarán.',
                off: 'Tus logros ya no se anuncian.',
            },
            inLeaderboard: {
                label: 'Aparecer en la clasificación',
                help: 'Tu nombre y tus puntos salen en la clasificación de logros, que solo ve la gente con sesión iniciada que puede ver tu perfil.',
                helpOff:
                    'La clasificación está apagada ahora mismo. Si se enciende, solo aparecerás si esto está encendido.',
                on: 'Apareces en la clasificación.',
                off: 'Ya no apareces en la clasificación.',
            },
            notices: 'Los avisos de logros de la campana se eligen en',
            noticesLink: 'Avisos',
        },
        catalogue: {
            title: 'Logros',
            subtitle:
                'Insignias que se consiguen jugando, dirigiendo y participando en la asociación.',
            held: 'Tienes {count} de {total}.',
            empty: 'Todavía no hay logros.',
            rarityExplained:
                'La rareza es la parte de la gente activa (quien ha participado en algo en los últimos doce meses) que tiene cada logro.',
            youHoldIt: 'Lo tienes',
            seasonExplained:
                'Algunos logros se pueden volver a conseguir cada temporada, que dura un año natural. Ahora es la temporada {season}.',
            repeats: 'Cada temporada',
            youHoldItThisSeason: 'Lo tienes esta temporada',
            pastSeasons: 'También en {seasons}',
            progress: 'Esta temporada: {value} de {threshold}',
            progressMonths: 'Esta temporada: {value} de {threshold} meses seguidos',
        },
    },
};
