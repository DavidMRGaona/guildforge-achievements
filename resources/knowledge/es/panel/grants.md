---
id: achievements.panel.grants
title: Conceder y retirar logros a mano
summary: Da un logro a una persona o a todas las que participaron en algo, retíralo con un motivo y consulta el historial.
category: users-and-roles
audience: panel
order: 81
keywords: [logros, conceder, retirar, quitar, lote, en lote, asistentes, evento, torneo, mesa, historial, motivo, fundadora, aviso, silencio, anuncio, resumen mensual]
conditions:
  permissions:
    any: ['achievements:achievements.grant', 'achievements:achievements.manage']
---
Hay logros que no salen de ninguna regla, como «Fundadora» o «Jornadas 2027», y concesiones que a veces hay que corregir. Para conceder y retirar logros hace falta el permiso de conceder logros; con el de gestionar el catálogo puedes ver quién los tiene y el historial.

## Conceder un logro a una persona

Desde el logro:

1. Abre **Personas** → **Logros** y entra en el logro.
2. En **Personas que lo tienen**, pulsa **Conceder**.
3. Busca la **Cuenta** por su nombre. No salen las que ya lo tienen, ni las que no puedes ver: para conceder desde el logro también hace falta permiso para ver las cuentas.
4. Escribe un **Motivo** si quieres. Queda en el historial; la persona no lo ve.
5. Decide si **Avisar a la persona** y pulsa **Conceder**.

Desde la cuenta: abre **Personas** → **Usuarios**, entra en la cuenta y baja hasta **Logros**. Hace falta poder editar esa cuenta. Pulsa **Conceder** y elige el logro. Solo salen los logros activos que la cuenta aún no tiene y que tú puedes conceder.

Los logros secretos solo los ve, y por tanto solo los concede, quien gestiona el catálogo.

**Avisar a la persona** viene encendido. Con él, le llega un aviso a la campana. Si además los anuncios están encendidos, el logro es raro el día del resumen (lo tiene menos gente activa que el umbral de los ajustes) y la persona lo permite en su pestaña **Logros** y no oculta sus logros, sale en el resumen mensual de logros que reciben, el día 1 del mes siguiente, quienes pueden ver su perfil. En silencio no sale en ningún sitio.

## Conceder un logro en lote

Sirve para dar un logro a todas las personas que participaron en algo con un papel: quien asistió a un evento, quien jugó un torneo, quien dirigió una mesa…

1. En **Personas que lo tienen**, pulsa **Conceder en lote**.
2. En **De dónde**, elige el tipo de actividad (eventos, torneos, partidas…). En **Qué**, elige o escribe para buscar: solo salen las que tú puedes ver, entre las 200 más recientes de ese tipo.
3. Elige **Con qué papel** y pulsa **Siguiente**. La lista ofrece todos los papeles; si nadie tuvo ese papel ahí, no se concede a nadie.
4. Comprueba a cuántas personas se concederá, cuántas ya lo tienen y a cuántas se les retiró.

   > **Atención:** quien concede en lote ve a quién se lo concede, es decir, quién participó en esa partida, evento o reserva. Quien gestiona el catálogo también lo ve en **Personas que lo tienen** y en el historial. En el editor de roles, da **Conceder y retirar logros** o **Gestionar el catálogo, los retos y los tipos de reconocimiento** solo a quien ya puede ver esas participaciones en su módulo.
5. Elige el **Aviso**: **En silencio** o **Con aviso a cada persona**. Con aviso, a cada persona le llega un aviso a la campana; lo concedido en lote no sale nunca en el resumen mensual de logros raros, porque diría quién participó en esa cosa.
6. Escribe un **Motivo** si quieres (el mismo para todas), marca la casilla y pulsa **Conceder en lote**.

A quien ya lo tiene no le cambia nada. A quien se lo retiraron no se le vuelve a dar en lote: si hace falta, concédeselo a mano.

Si participaron más de 1000 personas, contando las que ya lo tienen, no se concede nada. Se concede de 100 en 100: si algo falla a mitad, te dice a cuántas se les ha concedido, y puedes volver a intentarlo sin que se repita a quien ya lo tiene.

La concesión en lote viene apagada: la enciende quien tiene permiso para configurar los logros, en **Sistema** → **Configuración** → **Logros**. Tampoco sale el botón en un logro desactivado, en uno secreto si no gestionas el catálogo, ni en uno por temporada con las temporadas apagadas.

## Retirar un logro

Una concesión es para siempre: los logros no se retiran solos. Retirarlo a mano es la única forma de quitarlo.

1. En **Personas que lo tienen** (o en **Logros** de la cuenta), abre el menú **⋯** de la fila y pulsa **Retirar**.
2. Escribe el **Motivo**: es obligatorio y queda en el historial.
3. Marca la casilla y pulsa **Retirar**.

Un logro retirado no vuelve a concederse solo ni en lote, aunque la persona siga cumpliendo la regla. Solo vuelve si alguien se lo concede a mano otra vez.

Si no tienes permiso para ver cuentas, en el logro las personas salen como «Cuenta oculta» y no puedes retirarles el logro.

## Historial

**Historial de concesiones**, en el logro, e **Historial de logros**, en la cuenta, enseñan cada concesión y cada retirada: qué pasó, cuándo, a quién, con qué origen (por regla, a mano, en lote, por reto o por reto de socios), la temporada, quién lo hizo («Automático» en las reglas y los retos) y el motivo. No se puede cambiar ni borrar. En pantallas estrechas, el icono del ojo de cada fila enseña las columnas que no caben.

Si una cuenta se anonimiza, pierde sus logros y su nombre y sus motivos desaparecen del historial; lo que otras personas concedieron o retiraron sigue apuntado.

## Mientras impersonas en modo «Solo mirar»

No se puede conceder ni retirar nada: el panel rechaza cada intento y te avisa.
