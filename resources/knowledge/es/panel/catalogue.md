---
id: achievements.panel.catalogue
title: Catálogo de logros y sus reglas
summary: Crea logros, decide cuándo se conceden solos y entiende por qué uno sale como «Sin fuente».
category: users-and-roles
audience: panel
order: 80
keywords: [logros, insignias, catálogo, reglas, conceder, partidas, sistemas, torneos, podio, eventos, antigüedad, secreto, sin fuente, puntos, umbral, recalcular, rareza, gente activa, destacados, perfil, menú, página de logros, niveles, clasificación, temporada, comunidad, personas distintas, dirigir, temporadas, cada año, repetible, racha, rachas, meses seguidos, constancia]
conditions:
  permissions:
    any: ['achievements:achievements.view_any', 'achievements:achievements.manage', 'achievements:achievements.grant']
---
Un logro es una insignia que se gana con lo que cada persona hace en la asociación: partidas jugadas o dirigidas, sistemas distintos, torneos, eventos, años en la web… Se concede solo, en cuanto se cumple su regla, y no se retira nunca solo.

## Abrir el catálogo

En el menú lateral, abre **Personas** → **Logros**. La tabla enseña cada logro con su **Estado**, cuántas **Personas** lo tienen y un resumen de su **Regla**. En pantallas estrechas, **Ver detalles**, en el menú **⋯** de cada fila, enseña las columnas que no caben.

Para crear, editar o borrar logros hace falta el permiso de gestionar el catálogo. Con el de ver o el de conceder, los logros secretos salen como «Logro secreto», sin nombre ni regla.

## Crear un logro

1. Pulsa **Nuevo logro**.
2. En **Insignia**, escribe el **Nombre** y la **Descripción**, y elige el **Icono** y el **Color**. La **Imagen** es opcional: se ve en lugar del icono.
3. En **Regla**, elige **Qué se mide**:
   - **Número de actividades**: cuántas partidas, campañas, torneos, eventos, mensajes… Puedes quedarte con un **Papel** (jugar, dirigir, organizar…), con un valor concreto (**Solo con** un sistema de juego, por ejemplo) o con un **Puesto máximo** (3 es subir al podio).
   - **Valores distintos**: cuántos valores distintos hay, por ejemplo «3 sistemas distintos».
   - **Años**: años desde el alta en la web, o años como socio.
   - **Personas distintas**: con cuántas personas distintas ha coincidido alguien en partidas, campañas, torneos o eventos. **Papel** es el de la persona que consigue el logro y **Papel de las demás personas**, el de quienes se cuentan: dirección y jugador es «dirigir a 20 personas distintas». En estos logros solo valen los papeles de jugador y de dirección.
   - **Mentor: primeras partidas dirigidas**: a cuántas personas les ha dirigido alguien su primera partida como jugador. No pide nada más que el número (ver «Mentor», más abajo).
   - **Solo a mano**: no se concede nunca solo.
4. Escribe **Cuántos hacen falta** (salvo en **Solo a mano**) y, si sale, elige **Cuándo cuenta** (ver más abajo). **Cuándo cuenta** no sale en **Años**, **Personas distintas** ni **Mentor**.
5. Guarda.

Al guardar, el logro se concede **sin avisos** a quien ya cumpla la regla. Lo mismo pasa al cambiar la regla o al activar un logro desactivado. Un logro **Mentor** espera a la revisión de esa noche.

Solo se avisa de un logro recién conseguido: el que se cumple con una actividad de las dos últimas semanas, o el aniversario de los dos últimos días. Lo que ya se cumplía antes se concede sin avisos. Cuenta la fecha de la actividad, no cuándo se registró: una partida que se marca como completada semanas después de jugarse concede el logro sin aviso.

En la biblioteca de medios, la imagen de un logro secreto sale con el título «Logro secreto» y sin miniatura, y no se ofrece en «Elegir de la biblioteca». Si un logro deja de ser secreto, su imagen vuelve a verse.

Escribe la descripción con totales («Juega 10 partidas»), nunca con una partida o una campaña concreta: quién jugó una mesa no es público.

## Temporadas y rachas

Con **Temporadas y rachas** encendido en *Sistema → Configuración → Logros*, **Cuándo cuenta** ofrece tres opciones:

- **Siempre (una sola vez)**: cuenta todo lo que la persona ha hecho, y el logro se consigue una vez.
- **Por temporada (cada año)**: una temporada es un año natural. Solo cuenta lo de ese año, y el logro se vuelve a conseguir cada año en que se cumpla: «10 partidas en un año». Cada concesión guarda su año («Temporada 2027»). Un logro **Solo a mano** también puede ser por temporada, por ejemplo «La partida del año»: se concede a mano una vez cada año.
- **Racha de meses seguidos** (solo con **Número de actividades**): pide actividad en varios meses naturales seguidos. Escribe los **Meses seguidos** (de 2 a 36) y **Cuántas hacen falta cada mes** (con 1 basta una al mes). Un mes sin actividad corta la racha. Se consigue una sola vez, salvo que marques **Dentro de cada temporada**: entonces la racha empieza de cero cada año, se puede conseguir una vez por temporada y tiene 12 meses como mucho.

Los días, los meses y los años se cuentan con la hora de la asociación: una partida a las 23:30 del 31 de diciembre es del año que acaba.

Al guardar un logro por temporada, se concede, sin avisos, cada temporada pasada en que alguien ya lo cumplía. Si una partida deja de contar después, la temporada conseguida se conserva.

Al conceder a mano o en lote un logro por temporada, eliges la **Temporada**: la actual, que sale por defecto, o la anterior, para lo que se hizo justo antes de cambiar de año. En lote, la confirmación dice para qué temporada es. Solo reciben el logro las cuentas que no lo tienen esa temporada.

Cuando alguien ya tiene el logro, **Cuándo cuenta** y **Dentro de cada temporada** quedan bloqueados: no se puede pasar de «una sola vez» a «por temporada», ni al revés, porque las concesiones que ya hay dejarían de tener sentido. Los meses de una racha sí se pueden cambiar. Si necesitas otra opción, crea un logro nuevo. Las tablas de **Personas que lo tienen** y del historial tienen la columna **Temporada**; en pantallas estrechas, en **Ver detalles**. Retirar una temporada no toca las demás.

En la descripción, habla de totales y de meses («Juega al menos una partida cada mes durante tres meses»), nunca de una partida concreta.

## Estados

- **Activo**: se concede solo.
- **Sin fuente**: ningún módulo activo informa de ese tipo de actividad (por ejemplo, torneos sin el módulo de torneos), o el módulo que lo hace está fallando. En cuanto se habilita un módulo que lo trae, el logro se evalúa solo para todo el mundo, sin avisos. Si un módulo falló un rato y vuelve a funcionar, la evaluación pendiente se reintenta durante unos veinte minutos; si tarda más, pide que se ejecute el recálculo (guía «Ajustes de los logros y recálculo»).
- **Desactivado**: no se concede ni se ofrece a quien no lo tiene. Quien ya lo tenía lo conserva, lo sigue viendo en su perfil y le sigue sumando puntos.
- **Tipo apagado**: su tipo de actividad está apagado en *Sistema → Configuración → Logros*.
- **Secretos apagados**: es secreto y los logros secretos están apagados en los ajustes.
- **Temporadas apagadas**: es por temporada o pide una racha, y **Temporadas y rachas** está apagado en los ajustes. Tampoco se concede a mano. Quien ya lo tenía lo conserva.
- **Comunidad apagada**: es un logro de comunidad y los logros de comunidad están apagados en los ajustes.
- **Solo a mano** y **Regla no válida**: no se conceden solos. Una regla no válida se arregla editando el logro y guardando la regla de nuevo.

La columna enseña el primer motivo que aplica: un logro **Solo a mano**, **Sin fuente** o con **Regla no válida** sale así aunque además esté desactivado.

Cuando vuelves a encender un tipo de actividad, los logros secretos, las temporadas y rachas o los logros de comunidad en los ajustes, los logros vuelven a evaluarse para todo el mundo, sin avisos. Mientras estuvo apagado no se concedió nada de ese tipo.

## Logros de comunidad

Premian a quien trae y cuida gente, no solo a quien juega mucho: jugar con muchas personas distintas o dirigir a muchas personas. Se encienden y se apagan con **Logros de comunidad** en *Sistema → Configuración → Logros*.

- Solo cuentan personas, nunca dicen quiénes: ni el logro ni el progreso («7 de 10») nombran a nadie.
- Cada persona cuenta una vez, aunque hayáis coincidido en muchas partidas. Nadie se cuenta a sí mismo.
- Las cuentas anonimizadas dejan de contar. Las de quien oculta sus logros siguen contando, porque solo se da el número.
- Un logro de comunidad conseguido no se pierde aunque luego el número baje: por ejemplo, si una partida deja de contar.
- El progreso se guarda unos minutos, así que una partida nueva puede tardar ese rato en reflejarse. Si no se puede contar, no se enseña: nunca sale un 0 que no es.

### Mentor

Premia a quien dirige la primera partida de alguien: la primera partida en la que esa persona juega como jugador, aunque antes haya dirigido alguna. Una campaña entera no cuenta como partida; cada una de sus sesiones, sí.

- **Se revisa cada noche**, nunca al momento, y solo para primeras partidas de hace más de 14 días. Una mesa se marca como completada a menudo días después de jugarse: con ese margen, la primera de verdad llega antes de decidir y no quedan dos mentores para la misma persona.
- **Se concede con la fecha de esa noche**, nunca con la de la partida.
- **Los demás lo ven por meses cerrados.** Un Mentor concedido en noviembre lo ven los demás a partir del 1 de diciembre, a la vez que todos los de ese mes: en su perfil, en el contador, en los puntos, en la clasificación y en la rareza. Así no se puede emparejar con la «Primera partida» de nadie. En este panel, quien solo puede ver el catálogo lo cuenta igual en **Personas**. Quien lo consigue lo ve en su perfil al momento.
- **Un mentor por persona nueva, para siempre.** Si la mesa tuvo varias personas en la dirección, el mentor es la que además creó la mesa. Si ninguna la creó, no hay mentor. Si alguien empezó con dos mesas a la misma hora, tampoco. Lo que se decide esa noche ya no cambia: aunque luego aparezca una mesa anterior o la primera deje de contar, nadie más es su mentor.
- **Dirigir con el nombre oculto.** Quien dirige sin enseñar su nombre cuenta como jugador en esa mesa: nunca es el mentor, y si era su primera partida como jugador, cuenta como persona nueva de quien dirigía a la vista.
- **El progreso** sube con la revisión de la noche, no antes.
- **Aviso.** Quien lo consigue recibe el aviso de siempre si la primera partida es de las últimas semanas. Lo que se decide de golpe, porque la partida es más antigua (al instalar el módulo, por ejemplo), se concede sin aviso.
- **Qué se deduce.** Quien lo recibe sabe que alguien de una mesa que dirigió no había jugado antes en {{site_name}}. La asociación lo acepta a cambio de este logro. Los demás solo ven que lo tiene, con el mes y el año y cuando ese mes ha terminado, nunca a quién dio la bienvenida.

## Lo que se concede no se retira

Una concesión es para siempre: si una partida deja de contar o subes el umbral, quien ya tenía el logro lo conserva. Por eso un logro que tiene alguien no se puede borrar, solo desactivar: deja de concederse y de ofrecerse, y quien lo tenía lo conserva. **Borrar**, en el menú **⋯** y en la cabecera de la ficha, solo aparece mientras nadie lo tiene y ningún reto lo usa como recompensa, y pide marcar una casilla.

El logro de recompensa de un reto que se sigue contando tampoco se puede desactivar, hacer secreto ni darle una regla. Lo explica la guía «Retos de la asociación».

Solo una persona con permiso para conceder logros puede retirar uno, a mano y con un motivo, y entonces no vuelve a concederse solo. Lo explica la guía «Conceder y retirar logros a mano».

## Lo que ve la gente

Cada persona ve sus logros en la sección **Logros** de su perfil público, con el día en que los consiguió y su **rareza**, y quien puede ver ese perfil los ve también, con la rareza y solo el mes y el año. Solo el dueño ve los que le faltan y cuánto lleva («7 de 10»), mientras **Progreso visible** esté encendido en los ajustes. En uno por temporada, ve el progreso de este año; en una racha, los meses seguidos que lleva. Los demás solo ven en qué temporadas lo consiguió, nunca con qué partidas. Hasta tres logros destacados salen junto a su nombre, en su perfil, en su tarjeta y en el foro: los que marque con la estrella o, si no elige, sus tres más raros. Cada persona puede ocultar sus logros a los demás desde su sección.

La **rareza** es la parte de la gente activa que tiene un logro: cuentas que han participado en algo en los últimos doce meses, sin las desactivadas ni las anonimizadas. Se calcula una vez al día, por la noche, nunca al conceder ni al retirar, y sale en porcentajes enteros. Por la misma noche se actualiza lo que ven los demás de cada perfil (sus logros, su contador y sus destacadas): una concesión de hoy les sale mañana, para que dos personas que juegan juntas no se delaten porque sus logros suban a la vez. La persona ve los suyos al momento. En el panel, la columna **Personas** va al momento para quien puede conceder o gestionar logros, que ve igualmente quién los tiene; con solo el permiso de ver, sale el número de la última noche. Con **Rareza** apagada en los ajustes no se enseña en ningún sitio.

En el perfil, los demás ven la fecha de cada logro solo con mes y año, porque quienes juegan juntos lo consiguen a la vez y la hora exacta diría con quién se jugó; el dueño ve el día. En el panel, quien puede conceder o gestionar logros ve la fecha completa. Los logros secretos de otra persona no se ven uno a uno: solo cuántos tiene. Si marcas como secreto un logro que alguien tenía destacado, deja de estar destacado para todo el mundo, porque nadie eligió enseñarlo siendo secreto.

La página **Logros** de la web enseña los logros que se pueden conseguir ahora, con la rareza de cada uno; los secretos salen como «Logro secreto». Los que se repiten llevan «Cada temporada», y cada persona ve si lo tiene este año y cuánto lleva. Solo salen los que están **Activo** o **Solo a mano**, más los que cada persona ya tiene, aunque estén desactivados o apagados. Al habilitar el módulo se añade un enlace a ella en el menú de la cabecera; puedes moverlo o borrarlo en **Sitio web** → **Navegación**, y no vuelve.

## Puntos, niveles y clasificación

Los **puntos** de cada logro se escriben en su ficha. Mientras **Puntos** esté encendido en *Sistema → Configuración → Logros*, cada persona suma los puntos de sus logros y los ve en su perfil, desde siempre y en la temporada en curso (el año natural). Los demás ven ese total con lo conseguido hasta la noche anterior, para que nadie vea subir a la vez los puntos de quienes juegan juntos; lo que se quita (una retirada, un logro que pasa a secreto) y los cambios de puntos de un logro se aplican al momento. Tampoco ven los logros secretos que ellos no tienen, salvo los que el dueño haya destacado, también en la clasificación: así nadie adivina un secreto restando. El dueño ve los suyos al momento. Si cambias los puntos de un logro, cambian los totales de todos los que lo tienen. Un logro desactivado sigue sumando a quien lo tiene.

Los **niveles** necesitan los puntos encendidos. En los ajustes, con **Niveles** encendido, cada nivel tiene un nombre y los puntos desde los que empieza. El primero empieza en 0 y cada uno necesita más puntos que el anterior. Hay seis de partida, que puedes cambiar; mientras no los cambies, sus nombres siguen el idioma del sitio. El nivel sale en la fila de datos del perfil y en la sección **Logros**.

La **clasificación** también necesita los puntos encendidos. Es la página **Clasificación** de la web, solo para cuentas con sesión y sin verificación de email pendiente. Cada persona ve en ella solo los perfiles que puede ver, con su nombre público, y su puesto se cuenta entre ellos. No salen las cuentas desactivadas o anonimizadas, las que no tienen nombre público, quien oculta sus logros ni quien ha elegido no salir desde su perfil. Con **Temporadas y rachas** encendido se puede ver también por temporadas. La página se puede añadir al menú en **Sitio web** → **Navegación**.

## Catálogo inicial

Al instalar el módulo se crea un catálogo con logros de bienvenida, partidas jugadas y dirigidas, primera mesa creada que llega a jugarse, sistemas distintos, campañas, torneos, eventos, antigüedad, propuestas y comunidad (jugar con 10 personas distintas, dirigir a 20 y mentor). Solo empiezan activos los que tienen fuente. Puedes editarlos y borrarlos como cualquier otro. El catálogo inicial solo se vuelve a crear desde el servidor, con el comando que explica la guía «Ajustes de los logros y recálculo»; un logro que borraste vuelve desactivado.
