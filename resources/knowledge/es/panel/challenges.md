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

La recompensa es un logro normal del catálogo. Antes de crear el reto, crea en **Personas** → **Logros** un logro activo, no secreto y que se conceda **a mano**, por ejemplo «Diciembre en la mesa». Así ninguna regla lo concede sola; solo se consigue con el reto o si alguien lo concede a mano.

Mientras un reto lo use como recompensa, ese logro no se puede borrar. Mientras el reto se siga contando, tampoco se puede desactivar, hacer secreto ni darle una regla. Si el logro deja de cumplir estas condiciones, el reto no se enseña en la web y nadie recibe la recompensa hasta que vuelva a cumplirlas.

En un reto para socios, quien vea la recompensa en el perfil de alguien puede deducir que esa persona es socia. Tenlo en cuenta al elegir el logro y su descripción. Por eso el resumen mensual de anuncios solo la cuenta de quien ya enseña en su perfil que es socio.

## Crear un reto

1. Abre **Personas** → **Logros** → **Retos** y pulsa **Nuevo reto**.
2. Escribe el **Nombre** y, si quieres, una **Descripción**. Las dos se ven en la web.
3. Elige el **Primer día** y el **Último día**. Cuentan los días enteros, de las 0:00 del primero a las 23:59 del último, en la hora de la asociación. Un reto dura al menos 28 días, contando el primero y el último. El total de un reto más corto, como uno de una tarde o de un fin de semana, diría quién estuvo en esa mesa o en ese evento.
4. En **Quién participa**, elige **Todas las personas** o **Solo socios**. Con «Solo socios» cuenta solo lo que hacen las personas que son socias. Mientras duran sus fechas, se mira cada vez que se cuenta. Pasado el último día, queda fijo quién era socio en el último recuento: entrar o salir de la asociación después ya no cambia nada. Si después llega un hecho tardío de alguien que aún no había contado, se mira una sola vez si es socio en ese momento, y se queda así.
5. Elige el **Logro de recompensa**.
6. En **Qué se cuenta**, elige la **Actividad** (partidas, eventos, torneos…). Solo salen las que registra algún módulo instalado.
7. En **Cómo se suma**, elige una opción:
   - **En total**: cada partida o evento cuenta una vez, juegue quien juegue;
   - **Participaciones**: cuenta cada persona en cada partida, así que una mesa de cinco suma cinco;
   - **Personas distintas**: cuenta cuántas personas distintas han participado.
8. Si quieres, limita lo que cuenta por **Papel** (solo quien juega, por ejemplo), por **Puesto máximo** en torneos o por un valor, como un sistema de juego.
9. Escribe el **Objetivo** y pulsa **Crear**.

El reto se cuenta al guardarlo y otra vez cada vez que un módulo registra actividad nueva. También se cuenta cada noche, porque quién es socio puede cambiar sin que cambie nada más.

## Editar un reto

Mientras no se haya conseguido, puedes cambiarlo todo. Si un reto guardado dura menos de 28 días, no se puede volver a guardar hasta que alargues sus fechas. Si cambias **Quién participa**, el reto se vuelve a contar desde cero con el nuevo público: no se enseña nada del recuento anterior, y un reto que pasa a ser para socios no enseña su total hasta la noche siguiente. Si ya ha empezado, el formulario te avisa: cambiar lo que se cuenta, sus fechas o quién participa hace que se vuelva a contar, y el progreso puede bajar. Las recompensas ya concedidas se quedan.

Cuando el reto se consigue, lo que cuenta, sus fechas, quién participa y la recompensa quedan fijos, porque las recompensas se concedieron por eso. Solo puedes cambiar el nombre y la descripción.

## Estados

- **Próximamente**: aún no ha llegado su primer día.
- **En marcha**: está en sus fechas y aún no se ha conseguido.
- **Conseguido**: el total ha llegado al objetivo. Sigue así aunque el total baje después, por ejemplo si se borra una partida.
- **Terminado**: pasaron sus fechas sin llegar al objetivo.
- **Cerrado**: lo cerraste a mano antes de conseguirlo.

## Quién recibe la recompensa

Al conseguirse el reto, recibe el logro cada persona que contribuyó con algo que cuenta, en ese mismo momento y con un aviso en su campana, también en un reto para socios, aunque los demás no lo vean conseguido hasta la noche. Quien contribuye después también lo recibe, mientras el reto se siga contando. Lo que se registra tarde, como una mesa que se marca como terminada unos días después, sigue contando hasta 30 días después del último día, siempre que haya ocurrido dentro de las fechas.

La recompensa se concede en los recuentos normales, nunca en un recálculo. Si un recálculo descubre que el reto se ha conseguido, la recompensa llega en el siguiente recuento, como mucho a la noche siguiente. Cada persona sabe enseguida, en la campana, que ha recibido la recompensa. Si los anuncios de logros están encendidos, quien puede ver su perfil lo sabe en el resumen del mes siguiente, solo si esa persona eligió anunciar sus logros. El resumen la cuenta en el mes en que se concedió, que es cuando se descubrió que el reto se había conseguido.

Como cualquier logro, la recompensa no se pierde. Si se la retiras a alguien a mano, el reto no se la vuelve a dar.

## Lo que ve la gente

En la página **Logros** de la web salen los retos en marcha, los que empiezan en los próximos 30 días y los que terminaron hace menos de 30. Cada uno enseña su barra de progreso, sus fechas, su recompensa y cuántas personas han contribuido. Un reto que aún no se ha contado lo dice, en lugar de enseñar un 0. No sale un reto cuya actividad no registra ningún módulo o está apagada en *Sistema → Configuración → Logros*.

Solo se ven totales: un reto nunca dice qué partidas, sesiones o eventos han contado. Cada persona con la sesión iniciada ve además cuántas veces ha participado ella, al momento. En un reto para socios, solo los socios ven su parte.

En un reto para socios, el total, cuántas personas han contribuido y si se ha conseguido se actualizan una vez al día, con el recuento de la noche. Así, un total que sube una hora después de acabar una mesa no dice quién estaba en ella ni que es socio. Hasta la primera noche, el reto dice que aún no se ha contado. La recompensa se concede igualmente en cuanto se consigue, y aquí, en el panel, el progreso es siempre el del momento. Los retos para todas las personas se actualizan en cuanto se cuentan.

De un reto conseguido se nombra a algunas de las personas que tienen la recompensa, hasta doce. Solo salen perfiles que quien mira puede ver, con su nombre público y nunca quien oculta sus logros. En un reto para socios, además, solo salen quienes ya enseñan en su perfil que son socios. No se dice cuántas personas tienen la recompensa.

Esa lista se actualiza solo el día 1 de cada mes, a la misma hora que el resumen de anuncios. Al encender **Retos** se hace una vez en ese momento, y si la del día 1 falla, se repite una vez al día siguiente. Hasta entonces no aparece nadie nuevo, aunque ya tenga la recompensa, para que un nombre nuevo no diga cuándo la consiguió. Así, un reto conseguido a mitad de mes no nombra a nadie hasta el día 1 siguiente. Sí desaparece antes quien pierde la recompensa u oculta sus logros o su perfil.

## El bloque del reto

El bloque **Reto de la asociación** enseña un reto con su barra de progreso y un enlace a la página **Logros**. En un reto para socios, el bloque enseña el total de la noche, como la página. Puedes ponerlo en el pie de página, en una ventana emergente o en la página de enlaces. En sus ajustes puedes elegir un reto. Si no eliges ninguno, enseña el reto en marcha que antes termina o, si no hay ninguno, el siguiente en empezar. Sin retos que enseñar, el bloque no se ve.

## Cerrar o borrar un reto

Para terminar un reto antes de tiempo, abre el reto o su menú **⋯** y pulsa **Cerrar**. Al cerrarlo deja de contar y nadie más recibe la recompensa. Quien ya la tiene la conserva. Un reto cerrado no se puede volver a abrir ni editar. En la web, un reto cerrado solo se sigue viendo si se consiguió; en uno para socios, si ya se veía conseguido la noche anterior o desde la noche siguiente.

Solo se puede borrar un reto que no se ha conseguido. Uno conseguido se cierra, porque explica por qué se concedieron sus recompensas.
