# Logros

Logros, niveles y reconocimientos entre las personas de la asociación.

## Requisitos

- GuildForge core `^3.86`
- PHP >= 8.5, Laravel >= 13, Filament 5

El core rechaza el módulo si no se cumplen (`module.json` → `requires`).

## Qué hace

Un catálogo de logros que se conceden solos con lo que cada persona hace en la asociación, a partir del registro de actividad, o a mano y en lote desde el panel, con su historial. Cada mecánica restante (perfil, avisos, temporadas, retos…) llega con su propia issue.

## Registro de actividad

Los logros se calculan con lo que cuentan las fuentes de actividad del core (`docs/modules/activity-sources.md`): quién jugó o dirigió una partida, quién organizó un torneo, quién fue a un evento… El módulo guarda una copia, cosa por cosa, en la tabla `achievements_facts`: una fila por fuente, cosa, persona y papel, con el tipo de actividad, cuándo fue (en la zona de almacenamiento), sus atributos (`{"game_system": {"id": "pf2", "label": "Pathfinder 2"}}`) y el puesto si lo hay.

- **Al momento.** Cuando una fuente avisa de un cambio (`ActivityRecorded`), un listener en cola vuelve a leer esa cosa con `ActivityReaderInterface::facts()` y sustituye sus filas. Si la fuente falta o falla, no se toca nada: solo una respuesta leída cambia el registro, y el lector del core ya avisa una vez al día. Si falla, el listener lo vuelve a intentar más tarde (3 intentos en total); si falta, no.
- **Una cosa cada vez.** La lectura y la escritura de cada cosa se hacen con su bloqueo (tabla `achievements_subject_locks`, una fila por cosa), así que un aviso y un recálculo de la misma cosa nunca se mezclan, aunque la cosa aún no tenga hechos.
- **Aviso al evaluador.** Tras cada lectura correcta de una cosa, y tras cada página del recálculo, se lanza `AchievementFactsChanged` (evento interno del módulo) con las personas de antes y de ahora, haya cambiado algo o no: el evaluador las vuelve a mirar, y conceder dos veces no concede nada.
- **Tipos apagados.** Un tipo de actividad apagado en los ajustes se sigue registrando; solo deja de evaluarse. Al volver a encenderlo desde la página de ajustes, los logros se evalúan otra vez para todo el mundo con el registro, en cola y sin avisos.
- **Cuentas.** Al anonimizar una cuenta (`UserAnonymized`) se borran todos sus hechos, en cola, y el lector del core ya no los devuelve. Al escribir, además, se descartan los hechos de cuentas ya anonimizadas. Las cuentas desactivadas conservan sus hechos: la rareza y la clasificación las dejarán fuera.

## Catálogo de logros

*Personas → Logros* (orden 50), con la política `AchievementPolicy`: `achievements.view_any`, `manage` o `grant` para ver la tabla, `achievements.manage` para crear, editar y borrar. Quien no gestiona el catálogo abre cada logro en su página de ver (un secreto, nunca). Cada logro tiene nombre (60 caracteres), descripción (200), icono y color de las listas cerradas del core (`ProfileBadgeIcons::NAMES`, `ProfileBadgeColour`), imagen opcional, puntos, secreto, activo y una regla. Tabla `achievements_catalogue` (el prefijo del módulo es obligatorio).

- **Imagen.** Se sube a Cloudinary por el disco `images`, en `achievements/badges/<año>/<mes>/`, o se elige de la biblioteca de medios. La fuente de medios `achievements.badge-image` reclama esa carpeta; el modelo libera la imagen sustituida o borrada a través del core. El uso de un logro secreto se titula «Logro secreto», pero su miniatura sí se ve en la biblioteca (que solo abre el equipo, con `media.view_any`): el core solo puede ocultar carpetas enteras, y nunca borra lo que hay en ellas. Ocultar un uso concreto llegará con DavidMRGaona/GuildForge#830.
- **Secretos.** Quien no tiene un logro secreto ve «Logro secreto», sin nombre, regla, icono ni imagen: en el panel (quien no puede gestionar el catálogo; tampoco lo encuentra al buscar), en el título de su uso en la biblioteca de medios y en `AchievementCatalogueInterface`, la lectura que usarán las páginas. Con los logros secretos apagados en los ajustes, los secretos no se evalúan.
- **Estado.** La columna *Estado* dice si el evaluador mira el logro y, si no, por qué: *Activo*, *Desactivado*, *Solo a mano*, *Regla no válida*, *Sin fuente* (ningún módulo activo informa de su tipo), *Tipo apagado* o *Secretos apagados*. Es la misma decisión que toma el evaluador (`AchievementStatusRule`).
- **Borrar** solo se ofrece mientras nadie tiene el logro (`AchievementPolicy::delete()`, y la clave ajena `restrictOnDelete` en la base de datos), y pide una casilla. Un logro que alguien tiene solo se desactiva.

### La regla

JSON versionado en la columna `rule`, leído por `AchievementRule::tryFrom()`:

```json
{"version": 1, "metric": "count", "kind": "game_session",
 "filters": {"role": "player", "attribute": {"key": "game_system", "value": "<id>", "label": "Pathfinder 2"}, "placement_max": 3},
 "threshold": 10, "window": {"type": "all_time"}}
{"version": 1, "metric": "distinct", "kind": "game_session", "key": "game_system", "threshold": 3}
{"version": 1, "metric": "years", "basis": "account", "threshold": 5}
{"version": 1, "metric": "manual"}
```

- `count`: cosas (tipo de fuente + id) del tipo de actividad en las que la persona tiene un hecho que pasa todos los filtros; varios papeles en la misma cosa cuentan una vez. `placement_max` deja fuera los hechos sin puesto.
- `distinct`: valores distintos del atributo `key` entre esos hechos.
- `years`: `account`, años completos desde el primer hecho de tipo `account`, contados en la zona de visualización; `membership`, cosas de tipo `membership_year`.
- `manual`: nunca se concede solo.
- La lectura nunca lanza excepciones, y lo que no entiende deja la regla **ilegible**, nunca «cumplida por todo el mundo»: otra versión, una métrica, tipo, papel, base o ventana desconocidos, una clave inesperada, un valor del tipo equivocado o fuera de rango. Un logro con la regla ilegible no se evalúa y se avisa una vez al día.
- Sitio para lo que viene: temporadas y rachas serán tipos nuevos de `window`; los logros de comunidad y los retos, métricas nuevas. Hasta que una versión las lea, una regla que las use es ilegible para las anteriores.
- `filters.attribute.label` es solo el nombre del valor al guardar, para el panel; no interviene en la evaluación.

El formulario construye la regla con los tipos que ofrecen las fuentes activas ahora (más el del logro que se edita, marcado «sin fuente») y con los atributos y valores que ofrecen (`OffersActivityAttributes`). Una combinación que `AchievementRule` no lee se rechaza al guardar.

## Concesiones

Tabla `achievements_grants`: cuenta, logro, temporada (nula por ahora), `granted_at`, origen (`rule`, `manual`, `bulk` o `challenge`), quién la hizo (`actor_id`) y `silent`. Única por cuenta, logro y temporada (`season_key`, `''` sin temporada, porque dos NULL nunca chocan en un índice único).

- Se concede solo a través de `AchievementGranterInterface::grant(NewGrant)`. Lo ya concedido no se vuelve a conceder (`INSERT … ON CONFLICT DO NOTHING`, así que dos trabajos a la vez escriben una fila), ni a una cuenta que no existe o está anonimizada (se lee con bloqueo compartido), ni a un logro borrado.
- Cada concesión nueva lanza `AchievementGranted` (ids, origen, `silent`) cuando su transacción se confirma; nunca dentro de la red de seguridad de la impersonación.
- **Nunca se retira sola**: el evaluador solo añade y un logro con titulares no se puede borrar. Retirarla a mano (`AchievementGranterInterface::revoke()`, con motivo obligatorio) es la única forma de quitarla; lanza `AchievementRevoked` (ids) tras el commit.
- **Lo retirado no vuelve solo.** `grant()` rechaza una concesión por regla, por reto o en lote si la última entrada del historial de esa cuenta y ese logro es una retirada. Solo una concesión a mano la devuelve. El evaluador tampoco la cuenta, ni en un `--dry-run`. `revoke()` y la anonimización bloquean primero la fila de la cuenta y después la concesión, y `grant()` la tiene con un bloqueo compartido mientras lee el historial: una concesión que corre a la vez que una retirada o espera y la ve, o termina antes y la retirada se la lleva.
- Al anonimizar una cuenta (`UserAnonymized`) se borran sus concesiones, en cola.

### A mano y en lote

En el panel, con `achievements.grant` (`AchievementPolicy::grant()` y `revoke()`). Un logro secreto solo lo concede o retira quien también gestiona el catálogo, y uno desactivado se puede retirar pero no conceder.

- **En el logro** (página de ver o de editar): *Personas que lo tienen*, con **Conceder** (una cuenta por su nombre, motivo opcional y si se avisa), **Conceder en lote** y **Retirar** en el menú **⋯** de cada fila; debajo, *Historial de concesiones*.
- **Cuentas que se pueden ver.** Desde el logro solo se encuentran, se eligen y se retiran cuentas que quien concede puede ver (`UserPolicy::view()`, lo mismo que pide la ficha de la cuenta). Las demás salen como «Cuenta oculta», en *Personas que lo tienen* y en el historial, y la búsqueda por nombre (`name` o `display_name`) solo se ofrece con `users.view_any`.
- **En la ficha de la cuenta** (*Personas → Usuarios*): *Logros* y *Historial de logros*, al final de la página. El core no tiene un hueco para módulos ahí, así que se cuelgan del render hook `PAGE_END` de Filament con alcance a `EditUser` (`AccountAchievementsHook`), como dos componentes Livewire (`AccountAchievements`, `AccountGrantLog`) que comprueban el acceso en cada petición (`AccountGrantsAccess`: `achievements.grant` o `manage`, y poder ver la cuenta).
- **Retirar** pide un diálogo con motivo obligatorio y una casilla.
- **En lote** (`BulkGrantInterface`), solo con *Concesión en lote* encendida. Se elige una cosa de una fuente de actividad entre las más recientes del registro, descrita con `ActivityReaderInterface::describe()` para quien concede (lo que no le enseña no aparece ni se puede conceder), y un papel; se concede a quien lo tuvo según `facts()` del lector, solo si el resultado es `Read`. Dos pasos: qué, y después cuántas personas, el aviso (en silencio o con aviso) y una casilla. Con `GrantSource::Bulk`, de 100 en 100 personas (`PER_BATCH`), cada tanda en su transacción: si una falla (un deadlock, por ejemplo), lo concedido en las anteriores se queda y el resultado es `Partial`, con cuántas se concedieron; repetir no concede dos veces. Con más de 1000 personas (`MAX_PEOPLE`) no se concede nada (`TooMany`). El selector busca entre las 200 cosas más recientes del registro de ese tipo (`RECENT_SUBJECTS`). Falla cerrado: ajustes leídos con `readStrictly()`, logro activo, cosa visible y hechos leídos; si algo falla, no se concede nada.
- **Privacidad del lote.** Quién jugó o asistió no es público: el diálogo y el resultado solo dan números (`BulkGrantCountDTO`: personas, ya lo tenían, se les retiró, concedidos), nunca una lista de nombres.
- **Impersonación en modo «Solo mirar»**: todas estas acciones se rechazan (comportamiento por defecto del core; ninguna está marcada como de lectura).

### Historial

Tabla `achievements_grant_log`, de solo lectura en el panel: logro (y su nombre, que se conserva si el logro se borra), cuenta, quién (`actor_id`, nulo para las reglas), acción (`granted` o `revoked`), origen (`rule`, `manual`, `bulk`, `challenge`; una retirada siempre es `manual`), temporada y motivo (500 caracteres). Se escribe en la misma transacción que la concesión o la retirada, también para las concesiones por regla.

Al anonimizar una cuenta, sus entradas se quedan **sin la cuenta y sin el motivo** (que se escribió sobre esa persona): así sigue constando lo que hicieron otras personas al conceder o retirar, sin nada que la identifique. Las entradas en las que la cuenta anonimizada fue quien concedió o retiró se quedan como están.

## Evaluador

`AchievementEvaluatorInterface` concede lo que el registro dice que ya se cumple:

- Escucha `AchievementFactsChanged` en cola, después del commit, y evalúa solo las cuentas que trae; en silencio si viene de un recálculo.
- Mira solo los logros activos con una regla legible y no manual, cuyo tipo tiene una fuente activa y está encendido en los ajustes (y los secretos, solo con los logros secretos encendidos).
- **Falla cerrado.** Lee los ajustes con `readStrictly()`, que distingue «nunca guardados» (valores por defecto) de «no se pudieron leer» (tabla inaccesible o un valor que no es un objeto JSON). Si no puede leerlos no evalúa nada, avisa una vez al día y el trabajo se reintenta; tras el último intento falla y queda en `failed_jobs`. Los ajustes se leen clave a clave: un `metric_kinds` mal formado apaga todos los tipos, un interruptor de tipo que no es booleano apaga ese tipo y un `secrets` mal formado queda apagado; se avisa una vez al día.
- **Avisos.** Una concesión se anuncia solo si la evaluación no es silenciosa y la regla se acaba de cumplir (`AnnouncementWindow`, sobre `RuleEvaluator::metAt()`): el hecho que alcanzó el umbral es de los últimos 14 días, o el aniversario cayó en los últimos 2 días de la zona de visualización. Lo que se cumplía antes se concede en silencio, venga de donde venga la evaluación (un recálculo que no llegó a correr, un tipo encendido de nuevo).
- Crear un logro, cambiar su regla, activarlo o cambiar si es secreto lo evalúa para todo el mundo con el registro, en cola y en silencio. Encender de nuevo un tipo de actividad o los logros secretos en los ajustes evalúa todos los logros.
- Habilitar un módulo (`ModuleEnabled`) evalúa en silencio los logros de los tipos que ofrecen sus fuentes. Si el tipo de un logro tiene una fuente registrada pero no disponible ahora, el trabajo se reintenta con espera (4 intentos) y después termina sin fallar.
- `EvaluateAchievementsJob` es único mientras espera (mismos logros, módulo, silencio y alcance) y tiene un tiempo máximo de 15 minutos.
- Los años de cuenta cambian solo con la fecha y se cuentan por fechas de calendario en la zona de visualización: cada noche se evalúan para todo el mundo y solo se anuncian los aniversarios de los últimos días.

### Recalcular el historial

Hay que ejecutarlo **una vez después de instalar el módulo**, para registrar lo que pasó antes, y siempre que una fuente haya faltado o fallado durante un tiempo:

```bash
php artisan achievements:recalculate
```

- Recorre `history()` de cada fuente activa, página a página. `--source=game-tables.session` recorre solo esa fuente.
- La página solo indica qué cosas han cambiado: si una no coincide con lo guardado, se vuelve a leer con `facts()` con su bloqueo, y se guarda lo que diga.
- Al terminar un historial leído entero, cada cosa guardada que no aparecía se vuelve a leer con `facts()`. Solo se retira si su fuente responde que ya no tiene hechos; una cosa que terminó durante el recorrido se queda.
- Una fuente se para sin retirar nada si una página o una de esas lecturas falla, si el historial vuelve a una página ya leída o si pasa de 5000 páginas. Lo ya leído se guarda.
- Se puede repetir: una segunda pasada no cambia nada. No envía avisos: lo que conceda más adelante será en silencio.
- `--dry-run` cuenta lo que cambiaría sin escribir nada.
- Las cuentas afectadas se evalúan después, en cola y en silencio: lo que conceda no avisa a nadie.
- `--achievement=<id>` evalúa un logro para todo el mundo con el registro tal como está, sin leer las fuentes, y en silencio: después de crear un logro o cambiar un umbral (el panel ya lo hace solo). `--user=<id>` evalúa una sola cuenta; las dos juntas, un logro para una cuenta. No se combinan con `--source`. Con `--dry-run` cuentan lo que concederían.
- Al terminar muestra, por fuente, cuántas cosas ha leído, cuántas ha actualizado y cuántas ha retirado. Sale con error si alguna fuente no se ha podido leer entera.

## Catálogo inicial

Al habilitar el módulo por primera vez, su seeder crea un catálogo que cada sitio puede editar y borrar: bienvenida (primera partida, perfil completo, primer mensaje en el foro), partidas jugadas (1, 10, 50 y 100), dirigidas (1 y 10), primera mesa creada que llega a jugarse, sistemas distintos (3 y 10), campaña completada, primer torneo, podio, 5 eventos, 1, 3 y 5 años de cuenta y primera propuesta aceptada. Solo empiezan activos los que tienen fuente; los demás salen como *Sin fuente*.

```bash
php artisan achievements:install-starter-catalogue
```

Es idempotente: crea solo los que faltan (por su `starter_key`) y deja como están los que ya hay, editados o no. Un logro inicial que se borró vuelve desactivado (`achievements_starter_keys` recuerda los que se crearon). Encola una sola evaluación para todos los que crea activos.

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
| Progreso visible, rareza, logros secretos, temporadas y rachas, logros de comunidad | Encendidos |
| Concesión en lote | Apagada (también si el valor guardado está mal formado) |
| Anuncios de logros raros | Encendido, con umbral de rareza del 10 % |
| Puntos, niveles, clasificación, retos | Apagados |
| Reconocimientos | Apagados, con un plazo de 14 días |
| Un interruptor por tipo de actividad | Encendidos (un tipo sin interruptor cuenta) |

El resto del módulo lee los ajustes a través de `AchievementsSettingsInterface` (`progressEnabled()`, `rarityEnabled()`, `announcementsRarityThreshold()`, `metricKindEnabled($kind)`…), nunca de la tabla.

## Instalación

1. Descarga `achievements-X.Y.Z.zip` de la última release de `DavidMRGaona/guildforge-achievements` en GitHub.
2. En el panel, activa el modo mantenimiento (**Sistema → Configuración → Configuración del sitio** → pestaña **Mantenimiento**).
3. En **Módulos**, pulsa **Instalar / actualizar** y sube el ZIP.
4. Pulsa **Habilitar** en el módulo: la primera vez ejecuta sus migraciones y crea el catálogo inicial.
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
