---
id: achievements.panel.challenges
title: Retos de la asociación
summary: Crea objetivos entre todas las personas, como «40 partidas en diciembre», con un logro de recompensa para quien contribuya.
category: users-and-roles
audience: panel
order: 82
keywords: [retos, reto, objetivo, objetivo común, entre todos, colectivo, comunidad, recompensa, barra de progreso, diciembre, partidas, eventos, socios, cerrar reto, pie de página, ventana emergente, página de enlaces, bloque]
related: [achievements.panel.catalogue, achievements.site.achievements]
conditions:
  permissions:
    any: ['achievements:achievements.view_any', 'achievements:achievements.manage']
---
Un reto es un objetivo que se consigue entre todas las personas, sin competir: «entre todos, 40 partidas en diciembre». Tiene unas fechas, un total que alcanzar y un logro de recompensa. Cuando se alcanza, cada persona que contribuyó recibe el logro.

## Encender los retos

Los retos están apagados al instalar el módulo. Para encenderlos, abre *Sistema → Configuración → Logros* y enciende **Retos**. Si los apagas, desaparecen del panel y de la web y dejan de contarse, pero las recompensas ya concedidas se quedan.

## Preparar la recompensa

La recompensa es un logro normal del catálogo. Antes de crear el reto, crea en **Personas** → **Logros** un logro activo, no secreto y que se conceda **a mano**, por ejemplo «Diciembre en la mesa». Así nadie lo consigue por otro camino. Mientras un reto lo use como recompensa, ese logro no se puede borrar.

## Crear un reto

1. Abre **Personas** → **Logros** → **Retos** y pulsa **Nuevo reto**.
2. Escribe el **Nombre** y, si quieres, una **Descripción**. Las dos se ven en la web.
3. Elige el **Primer día** y el **Último día**. Cuentan los días enteros, de las 0:00 del primero a las 23:59 del último, en la hora de la asociación.
4. En **Quién participa**, elige **Todas las personas** o **Solo socios**. Con «Solo socios» cuenta solo lo que hacen las personas que son socias en el momento de contar.
5. Elige el **Logro de recompensa**.
6. En **Qué se cuenta**, elige la **Actividad** (partidas, eventos, torneos…). Solo salen las que registra algún módulo instalado.
7. En **Cómo se suma**, elige una opción:
   - **En total**: cada partida o evento cuenta una vez, juegue quien juegue;
   - **Participaciones**: cuenta cada persona en cada partida, así que una mesa de cinco suma cinco;
   - **Personas distintas**: cuenta cuántas personas distintas han participado.
8. Si quieres, limita lo que cuenta por **Papel** (solo quien juega, por ejemplo), por **Puesto máximo** en torneos o por un valor, como un sistema de juego.
9. Escribe el **Objetivo** y pulsa **Crear**.

El reto se cuenta al guardarlo y otra vez cada vez que un módulo registra actividad nueva. También se cuenta cada noche, porque quién es socio puede cambiar sin que cambie nada más.

## Estados

- **Próximamente**: aún no ha llegado su primer día.
- **En marcha**: está en sus fechas y aún no se ha conseguido.
- **Conseguido**: el total ha llegado al objetivo. Sigue así aunque el total baje después, por ejemplo si se borra una partida.
- **Terminado**: pasaron sus fechas sin llegar al objetivo.
- **Cerrado**: lo cerraste a mano antes de conseguirlo.

## Quién recibe la recompensa

Al conseguirse el reto, recibe el logro cada persona que contribuyó con algo que cuenta. Quien contribuye después, mientras sigan sus fechas, también lo recibe. Lo que se registra tarde, como una mesa que se marca como terminada unos días después, sigue contando durante 30 días tras el último día.

Si el anuncio de logros está encendido, la recompensa se anuncia cuando el reto se consigue o la persona contribuye en los últimos días. Lo que se cuenta con mucho retraso, o en un recálculo, se concede sin avisos.

Como cualquier logro, la recompensa no se pierde. Si se la retiras a alguien a mano, el reto no se la vuelve a dar.

## Lo que ve la gente

En la página **Logros** de la web salen los retos en marcha, los que empiezan en los próximos 30 días y los que terminaron hace menos de 30. Cada uno enseña su barra de progreso, sus fechas y su recompensa, y cuántas personas han contribuido.

Solo se ven totales: un reto nunca dice qué partidas, sesiones o eventos han contado. Cada persona con la sesión iniciada ve además cuántas veces ha participado ella.

De un reto conseguido se ve cuántas personas tienen la recompensa. Además, se nombran hasta doce de ellas, solo si quien mira puede ver su perfil y solo con su nombre público. No se nombra a quien oculta sus logros.

## El bloque del reto

El bloque **Reto de la asociación** enseña un reto con su barra de progreso y un enlace a la página **Logros**. Puedes ponerlo en el pie de página, en una ventana emergente o en la página de enlaces. En sus ajustes puedes elegir un reto. Si no eliges ninguno, enseña el reto en marcha que antes termina o, si no hay ninguno, el siguiente en empezar. Sin retos que enseñar, el bloque no se ve.

## Cerrar o borrar un reto

Para terminar un reto antes de tiempo, abre el reto o su menú **⋯** y pulsa **Cerrar**. Al cerrarlo deja de contar y nadie más recibe la recompensa. Quien ya la tiene la conserva. Un reto cerrado no se puede volver a abrir ni editar.

Solo se puede borrar un reto que no se ha conseguido. Uno conseguido se cierra, porque explica por qué se concedieron sus recompensas.
