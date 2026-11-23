---
id: achievements.panel.settings
title: Ajustes de los logros y recálculo
summary: Enciende o apaga cada mecánica de los logros, elige qué actividad cuenta y registra la actividad anterior a la instalación.
category: users-and-roles
audience: panel
order: 83
keywords: [ajustes, configuración, logros, encender, apagar, interruptor, progreso, rareza, secretos, temporadas, rachas, comunidad, lote, retos, anuncios, umbral, puntos, niveles, clasificación, tipos de actividad, recalcular, recálculo, instalar, catálogo inicial, cada noche, foto]
related: [achievements.panel.catalogue, achievements.panel.grants, achievements.panel.challenges]
conditions:
  permissions:
    all: ['achievements:achievements.configure']
---
Los ajustes deciden qué partes de los logros funcionan en {{site_name}}. Están en *Sistema → Configuración → Logros*, y hace falta el permiso de configurar los logros.

[[action:filament.admin.configuracion.pages.logros|Abrir los ajustes de los logros]]

## Cómo funcionan los interruptores

Una mecánica apagada no se ve en la web, sus páginas no existen y sus reglas no se evalúan. Lo que ya se concedió se conserva y se sigue enseñando.

Al volver a encender una mecánica que concede logros, se evalúa otra vez a todo el mundo con la actividad registrada, en segundo plano y **sin avisos**: mientras estuvo apagada no se concedió nada, y lo que se concede ahora no es reciente.

Pulsa **Guardar** para aplicar los cambios.

## Logros

- **Progreso visible**: cada persona ve en su perfil cuánto le falta para cada logro («7 de 10»). Solo lo ve ella.
- **Rareza**: enseña qué parte de la gente activa tiene cada logro. Apagada no se enseña en ningún sitio, pero se sigue calculando cada noche, porque esa misma foto decide lo que ven los demás de cada perfil.
- **Logros secretos**: apagados, los logros secretos no se conceden y siguen saliendo como «Logro secreto».
- **Temporadas y rachas**: logros que se repiten cada año y rachas de meses seguidos. Apagadas, esos logros no se conceden, ni solos ni a mano.
- **Logros de comunidad**: jugar con muchas personas distintas, dirigir a muchas o dirigir la primera partida de alguien (**Mentor**). Solo se cuentan personas.
- **Concesión en lote**: viene apagada. Encendida, quien puede conceder logros puede darlos a todas las personas que participaron en algo, y por tanto ve quién participó. Lo explica la guía «Conceder y retirar logros a mano».
- **Retos**: vienen apagados. Lo explica la guía «Retos de la asociación».

## Anuncios

**Anuncios de logros raros** envía el día 1 de cada mes un resumen con los logros poco frecuentes y los de retos que consiguió otra persona el mes anterior, solo a quien puede ver su perfil y solo si esa persona lo permite en su pestaña **Logros**. Viene encendido.

En **Umbral de rareza** escribe el porcentaje: solo se anuncian los logros que tiene **menos** de ese porcentaje de la gente activa (10 % de partida, entre 0,1 y 100). Los de retos se anuncian siempre.

Si apagas los anuncios, lo que esperaba al próximo resumen se descarta y no se anuncia después.

## Puntos y clasificación

Vienen apagados.

- **Puntos**: cada logro da los puntos que tenga en su ficha.
- **Niveles**: los puntos suben de nivel. Necesita los puntos. Al encenderlo sale la lista **Niveles**: cada nivel tiene un **Nombre** (hasta 40 caracteres) y los puntos **Desde** los que empieza. Hay entre 2 y 20 niveles; el primero empieza en 0 y cada uno necesita más puntos que el anterior. Hay seis de partida: Primeros pasos (0), Aprendiz (20), Habitual (50), Pilar del grupo (100), Referente (175) y Leyenda (275). Mientras no los cambies, sus nombres siguen el idioma del sitio.
- **Clasificación**: una página con quién tiene más puntos, solo para cuentas con sesión. Necesita los puntos.

## Reconocimientos

La sección **Reconocimientos** ya tiene su interruptor y su plazo, pero todavía no hay nada que los use: encenderlos no cambia nada por ahora.

## Tipos de actividad

Cada tipo (partidas, campañas, torneos, eventos, mensajes del foro…) tiene su interruptor. Un tipo apagado deja de contar para los logros y los retos, pero la actividad se sigue registrando y lo concedido no se retira. Sus logros salen como «Tipo apagado» en el catálogo. Al volver a encenderlo, se evalúa otra vez a todo el mundo, sin avisos.

Un tipo solo cuenta si algún módulo instalado lo registra. Sin ese módulo, sus logros salen como «Sin fuente».

## Lo que pasa cada noche

Estas tareas van solas, a la hora de {{site_name}}:

- **A las 03:00**, se decide quién es **Mentor**: las primeras partidas de hace más de 14 días. Los demás lo ven cuando ha terminado el mes en que se concedió.
- **A las 03:30**, la foto de la noche: se calcula la rareza y se fija lo que los demás ven de cada perfil (logros, contador, destacados, puntos, nivel y clasificación). Lo conseguido después sale a los demás la noche siguiente.
- **A las 04:10**, se cuentan los retos. Es lo que actualiza el total de los retos **Solo socios**.
- **Cada día**, se conceden los logros de años en la web.
- **El día 1 de cada mes, a las 10:00**, salen el resumen de anuncios y la lista de quién consiguió cada reto.

## Recalcular la actividad

Los logros se calculan con la actividad que registran los módulos. Lo que pasó antes de instalar los logros no está registrado hasta que alguien con acceso al servidor ejecuta, una vez después de instalar el módulo:

```bash
php artisan achievements:recalculate
```

Vuelve a leer toda la actividad de cada módulo y concede lo que ya se cumplía, **sin avisos**. Hasta que se haga entero una vez, nadie consigue **Mentor**: decidir quién dio la bienvenida a quién necesita todas las partidas de antes. Se puede repetir sin miedo: una segunda pasada no cambia nada. Conviene repetirlo si un módulo ha fallado durante un tiempo. Al terminar dice, por módulo, cuánto ha leído y cambiado, y acaba con error si alguno no se ha podido leer entero.

Otras opciones del mismo comando:

- `--dry-run`: cuenta lo que cambiaría, sin cambiar nada.
- `--achievement=<id>`: evalúa un logro para todo el mundo con lo ya registrado. El panel ya lo hace solo al crear o cambiar un logro.
- `--user=<id>`: evalúa una sola cuenta.

Para volver a crear los logros del catálogo inicial que falten:

```bash
php artisan achievements:install-starter-catalogue
```

Solo crea los que no existen; los que editaste se quedan como están, y uno que borraste vuelve desactivado.

## Si algo no sale bien

- **No se concede nada y el panel no da error.** Si los ajustes no se pueden leer, no se evalúa nada hasta que vuelvan a poder leerse, y queda apuntado en el registro de errores.
- **Un logro sale como «Sin fuente» o «Tipo apagado».** Habilita el módulo que registra esa actividad o enciende su tipo aquí.
- **Los demás no ven nada de los logros.** En una instalación nueva, lo que ven los demás llega con la primera foto, a las 03:30.
