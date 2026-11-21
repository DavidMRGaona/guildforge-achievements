# Logros

Logros, niveles y reconocimientos entre las personas de la asociación.

## Requisitos

- GuildForge core `^3.86`
- PHP >= 8.5, Laravel >= 13, Filament 5

El core rechaza el módulo si no se cumplen (`module.json` → `requires`).

## Qué hace

Por ahora, la base del módulo (permisos y ajustes) y el registro de actividad del que saldrán los logros. Cada mecánica llega con su propia issue.

## Registro de actividad

Los logros se calculan con lo que cuentan las fuentes de actividad del core (`docs/modules/activity-sources.md`): quién jugó o dirigió una partida, quién organizó un torneo, quién fue a un evento… El módulo guarda una copia, cosa por cosa, en la tabla `achievements_facts`: una fila por fuente, cosa, persona y papel, con el tipo de actividad, cuándo fue (en la zona de almacenamiento), sus atributos (`{"game_system": {"id": "pf2", "label": "Pathfinder 2"}}`) y el puesto si lo hay.

- **Al momento.** Cuando una fuente avisa de un cambio (`ActivityRecorded`), un listener en cola vuelve a leer esa cosa con `ActivityReaderInterface::facts()` y sustituye sus filas en una transacción. Si la fuente falta o falla, no se toca nada: solo una respuesta leída cambia el registro, y el lector del core ya avisa una vez al día.
- **Después de cada cambio** se lanza `AchievementFactsChanged` (evento interno del módulo) con las personas de antes y de ahora, para volver a evaluar sus logros.
- **Tipos apagados.** Un tipo de actividad apagado en los ajustes se sigue registrando; solo deja de evaluarse. Al encenderlo no hace falta recalcular el registro.
- **Cuentas.** Al anonimizar una cuenta (`UserAnonymized`) se borran todos sus hechos, y el lector del core ya no los devuelve. Las cuentas desactivadas los conservan: la rareza y la clasificación las dejarán fuera.

### Recalcular el historial

Hay que ejecutarlo **una vez después de instalar el módulo**, para registrar lo que pasó antes, y siempre que una fuente haya faltado o fallado durante un tiempo:

```bash
php artisan achievements:recalculate
```

- Recorre `history()` de cada fuente activa, página a página, y sustituye los hechos de cada cosa. `--source=game-tables.session` recorre solo esa fuente.
- Una cosa guardada que ya no aparece en un historial leído entero se retira. Si una página falla, o el historial vuelve a una página ya leída, esa fuente se para ahí: se guarda lo leído y no se retira nada.
- Se puede repetir: una segunda pasada no cambia nada. No envía avisos: lo que conceda más adelante será en silencio.
- `--dry-run` cuenta lo que cambiaría sin escribir nada.
- Al terminar muestra, por fuente, cuántas cosas ha leído, cuántas ha actualizado y cuántas ha retirado. Sale con error si alguna fuente no se ha podido leer entera.

## Permisos

| Permiso | Para qué |
|---|---|
| `achievements.view_any` | Ver los logros en el panel |
| `achievements.manage` | Catálogo, retos y tipos de reconocimiento (sugerido para el rol `editor`) |
| `achievements.grant` | Conceder, conceder en lote y retirar logros (sin rol por defecto) |
| `achievements.configure` | La página de ajustes (sin rol por defecto) |

## Ajustes

*Sistema → Configuración → Logros* (hueco 128 del clúster de configuración). Cada mecánica tiene su interruptor; una mecánica apagada no se ve, sus rutas responden 404 y sus reglas no se evalúan, y lo ya concedido se conserva.

| Ajuste | Por defecto |
|---|---|
| Progreso visible, rareza, logros secretos, temporadas y rachas, logros de comunidad, concesión en lote | Encendidos |
| Anuncios de logros raros | Encendido, con umbral de rareza del 10 % |
| Puntos, niveles, clasificación, retos | Apagados |
| Reconocimientos | Apagados, con un plazo de 14 días |
| Un interruptor por tipo de actividad | Encendidos (un tipo sin interruptor cuenta) |

El resto del módulo lee los ajustes a través de `AchievementsSettingsInterface` (`progressEnabled()`, `rarityEnabled()`, `announcementsRarityThreshold()`, `metricKindEnabled($kind)`…), nunca de la tabla.

## Instalación

1. Descarga `achievements-X.Y.Z.zip` de la última release de `DavidMRGaona/guildforge-achievements` en GitHub.
2. En el panel, activa el modo mantenimiento (**Sistema → Configuración → Configuración del sitio** → pestaña **Mantenimiento**).
3. En **Módulos**, pulsa **Instalar / actualizar** y sube el ZIP.
4. Pulsa **Habilitar** en el módulo: la primera vez ejecuta sus migraciones.
5. Desactiva el modo mantenimiento.
6. Ejecuta `php artisan achievements:recalculate` una vez, para registrar la actividad anterior a la instalación.

Las versiones nuevas se aplican desde **Actualizaciones de módulos** (también con el modo mantenimiento activo) o con `php artisan module:update achievements`.

### En desarrollo

Con el repositorio clonado en `src/modules/achievements`, desde `src/`:

```bash
php artisan module:discover
php artisan module:enable achievements
```

Los componentes Vue se compilan con las herramientas del core:

```bash
docker exec guildforge_node npm run build:module -- achievements
```

En las releases los assets los compila el CI y van dentro del ZIP.

## Tests

Desde `src/` (o dentro del contenedor `guildforge_app`):

```bash
XDEBUG_MODE=off vendor/bin/phpunit -c modules/achievements/phpunit.xml
```

`php artisan test` no ejecuta los tests de los módulos.

Los componentes Vue tienen sus tests en `tests/js` (Vitest, Vue Test Utils y happy-dom) y se ejecutan con las herramientas del core, porque importan sus fuentes con `@/`:

```bash
docker exec guildforge_node sh -c 'cd modules/achievements && ../../node_modules/.bin/vitest run'
```

`tests/js/setup.ts` carga los textos del core y del módulo en castellano y sustituye Inertia (router y enlaces) y el layout del sitio.

## Calidad del código

El módulo pasa las mismas herramientas que el core, con su configuración: Rector, Pint, PHPStan, ESLint, Prettier, `vue-tsc` y Vitest. En cada pull request las ejecuta el job *Quality* de `tests.yml`. Desde la raíz del core:

```bash
make check MODULE=achievements
```

## Publicar una versión

1. Sube `version` en `module.json` (un módulo nuevo empieza en `0.0.0`, sin publicar).
2. Crea y empuja la etiqueta `vX.Y.Z` (debe coincidir con `version`).
3. El workflow `release.yml` ejecuta los tests contra el core (`main`, o la variable `GUILDFORGE_HOST_REF`), compila los assets y publica `achievements-X.Y.Z.zip` con su `.sha256` en la release.
