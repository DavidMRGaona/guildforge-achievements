---
id: achievements.panel.catalogue
title: Catálogo de logros y sus reglas
summary: Crea logros, decide cuándo se conceden solos y entiende por qué uno sale como «Sin fuente».
category: users-and-roles
audience: panel
order: 80
keywords: [logros, insignias, catálogo, reglas, conceder, partidas, sistemas, torneos, podio, eventos, antigüedad, secreto, sin fuente, puntos, umbral, recalcular, rareza, gente activa, destacados, perfil, menú, página de logros, comunidad, personas distintas, dirigir]
conditions:
  permissions:
    any: ['achievements:achievements.view_any', 'achievements:achievements.manage']
---
Un logro es una insignia que se gana con lo que cada persona hace en la asociación: partidas jugadas o dirigidas, sistemas distintos, torneos, eventos, años en la web… Se concede solo, en cuanto se cumple su regla, y no se retira nunca solo.

## Abrir el catálogo

En el menú lateral, abre **Personas** → **Logros**. La tabla enseña cada logro con su **Estado**, cuántas **Personas** lo tienen y un resumen de su **Regla**. En pantallas estrechas, **Ver detalles**, en el menú **⋯** de cada fila, enseña las columnas que no caben.

Para crear, editar o borrar logros hace falta el permiso de gestionar el catálogo. Con el de ver, los logros secretos salen como «Logro secreto», sin nombre ni regla.

## Crear un logro

1. Pulsa **Nuevo logro**.
2. En **Insignia**, escribe el **Nombre** y la **Descripción**, y elige el **Icono** y el **Color**. La **Imagen** es opcional: se ve en lugar del icono.
3. En **Regla**, elige **Qué se mide**:
   - **Número de actividades**: cuántas partidas, campañas, torneos, eventos, mensajes… Puedes quedarte con un **Papel** (jugar, dirigir, organizar…), con un valor concreto (**Solo con** un sistema de juego, por ejemplo) o con un **Puesto máximo** (3 es subir al podio).
   - **Valores distintos**: cuántos valores distintos hay, por ejemplo «3 sistemas distintos».
   - **Años**: años desde el alta en la web, o años como socio.
   - **Personas distintas**: con cuántas personas distintas ha coincidido alguien en partidas, campañas, torneos o eventos. **Papel** es el de la persona que consigue el logro y **Papel de las demás personas**, el de quienes se cuentan: dirección y jugador es «dirigir a 20 personas distintas». En estos logros solo valen los papeles de jugador y de dirección.
   - **Solo a mano**: no se concede nunca solo.
4. Escribe **Cuántos hacen falta** y guarda.

Al guardar, el logro se concede **sin avisos** a quien ya cumpla la regla. Lo mismo pasa al cambiar la regla o al activar un logro desactivado.

Solo se avisa de un logro recién conseguido: el que se cumple con una actividad de las dos últimas semanas, o el aniversario de los dos últimos días. Lo que ya se cumplía antes se concede sin avisos. Cuenta la fecha de la actividad, no cuándo se registró: una partida que se marca como completada semanas después de jugarse concede el logro sin aviso.

En la biblioteca de medios, la imagen de un logro secreto sale con el título «Logro secreto», pero por ahora su miniatura se ve.

Escribe la descripción con totales («Juega 10 partidas»), nunca con una partida o una campaña concreta: quién jugó una mesa no es público.

## Estados

- **Activo**: se concede solo.
- **Sin fuente**: ningún módulo activo informa de ese tipo de actividad (por ejemplo, torneos sin el módulo de torneos). En cuanto se habilita un módulo que lo trae, o vuelve a funcionar una fuente que había fallado, el logro se evalúa solo para todo el mundo, sin avisos.
- **Desactivado**: no se concede ni se enseña. Quien ya lo tenía lo conserva.
- **Tipo apagado**: su tipo de actividad está apagado en *Sistema → Configuración → Logros*.
- **Secretos apagados**: es secreto y los logros secretos están apagados en los ajustes.
- **Comunidad apagada**: es un logro de comunidad y los logros de comunidad están apagados en los ajustes.
- **Solo a mano** y **Regla no válida**: no se conceden solos. Una regla no válida se arregla editando el logro y guardando la regla de nuevo.

Cuando vuelves a encender un tipo de actividad, los logros secretos o los de comunidad en los ajustes, los logros vuelven a evaluarse para todo el mundo, sin avisos. Mientras estuvo apagado no se concedió nada de ese tipo.

## Logros de comunidad

Premian a quien trae y cuida gente, no solo a quien juega mucho: jugar con muchas personas distintas o dirigir a muchas personas. Se encienden y se apagan con **Logros de comunidad** en *Sistema → Configuración → Logros*.

- Solo cuentan personas, nunca dicen quiénes: ni el logro ni el progreso («7 de 10») nombran a nadie.
- Cada persona cuenta una vez, aunque hayáis coincidido en muchas partidas. Nadie se cuenta a sí mismo.
- Las cuentas anonimizadas dejan de contar. Las de quien oculta sus logros siguen contando, porque solo se da el número.
- Un logro de comunidad conseguido no se pierde aunque luego el número baje: por ejemplo, si una partida deja de contar.
- El progreso se guarda unos minutos, así que una partida nueva puede tardar ese rato en reflejarse. Si no se puede contar, no se enseña: nunca sale un 0 que no es.

## Lo que se concede no se retira

Una concesión es para siempre: si una partida deja de contar o subes el umbral, quien ya tenía el logro lo conserva. Por eso un logro que tiene alguien no se puede borrar, solo desactivar: deja de concederse y de enseñarse, y quien lo tenía lo conserva. **Borrar** solo aparece, en el menú **⋯**, mientras nadie lo tiene, y pide marcar una casilla.

Solo una persona con permiso para conceder logros puede retirar uno, a mano y con un motivo, y entonces no vuelve a concederse solo. Lo explica la guía «Conceder y retirar logros a mano».

## Lo que ve la gente

Cada persona ve sus logros en la sección **Logros** de su perfil público, con su fecha y su **rareza**, y quien puede ver ese perfil los ve también. Solo el dueño ve los que le faltan y cuánto lleva («7 de 10»), mientras **Progreso visible** esté encendido en los ajustes. Hasta tres logros destacados salen junto a su nombre, en su perfil, en su tarjeta y en el foro: los que marque con la estrella o, si no elige, sus tres más raros. Cada persona puede ocultar sus logros a los demás desde su sección.

La **rareza** es la parte de la gente activa que tiene un logro: cuentas que han participado en algo en los últimos doce meses, sin las desactivadas ni las anonimizadas. Se calcula cada noche y cada vez que se concede un logro. Con **Rareza** apagada en los ajustes no se enseña en ningún sitio.

En el perfil, los demás ven la fecha de cada logro solo con mes y año, porque quienes juegan juntos lo consiguen a la vez y la hora exacta diría con quién se jugó; el dueño ve el día. En el panel, quien puede conceder o gestionar logros ve la fecha completa. Los logros secretos de otra persona no se ven uno a uno: solo cuántos tiene. Si marcas como secreto un logro que alguien tenía destacado, deja de estar destacado para todo el mundo, porque nadie eligió enseñarlo siendo secreto.

La página **Logros** de la web enseña los logros que se pueden conseguir ahora, con la rareza de cada uno; los secretos salen como «Logro secreto». No salen los que están **Sin fuente**, **Tipo apagado**, **Secretos apagados** ni con **Regla no válida**, salvo a quien ya los tiene. Al habilitar el módulo se añade un enlace a ella en el menú de la cabecera; puedes moverlo o borrarlo en **Sitio web** → **Navegación**, y no vuelve.

## Catálogo inicial

Al instalar el módulo se crea un catálogo con logros de bienvenida, partidas jugadas y dirigidas, sistemas distintos, campañas, torneos, eventos, antigüedad, propuestas y comunidad (jugar con 10 personas distintas y dirigir a 20). Solo empiezan activos los que tienen fuente. Puedes editarlos y borrarlos como cualquier otro. Si vuelves a crear el catálogo inicial, un logro que borraste vuelve desactivado.
