---
id: achievements.panel.recognitions
title: Reconocimientos
summary: Enciende los reconocimientos entre quienes juegan, prepara sus tipos y borra uno desde la ficha de una persona.
category: users-and-roles
audience: panel
order: 83
keywords: [reconocimientos, reconocer, tipos de reconocimiento, gran narración, buen compañero, ayuda a quien empieza, gran ambiente, moderar, borrar reconocimiento, plazo, privacidad, ficha de usuario]
related: [achievements.panel.catalogue, achievements.site.recognitions]
conditions:
  permissions:
    any: ['achievements:achievements.view_any', 'achievements:achievements.manage', 'achievements:achievements.moderate_recognitions']
---
Con los reconocimientos, quienes han jugado juntos en una partida o una campaña se dicen algo bueno: «Gran narración», «Buen compañero»… Quien lo recibe ve quién se lo dio. Los demás solo ven en su perfil cuántos tiene de cada tipo, contados hasta el mes anterior.

## Encenderlos

Están apagados al instalar el módulo. Para encenderlos, abre *Sistema → Configuración → Logros*, enciende **Reconocimientos entre personas** y elige el **plazo para reconocer**: los días, desde el día de la partida, en los que se puede hacer (14 por defecto).

Si los apagas, desaparecen de la web y nadie puede dar más, pero los ya dados se guardan.

## Los tipos

En *Personas → Logros → Tipos de reconocimiento* están los tipos que se pueden dar. Vienen cuatro: «Gran narración», «Buen compañero», «Ayuda a quien empieza» y «Gran ambiente». Cada uno tiene nombre, icono y color.

- Para que un tipo deje de darse, **apágalo**: los que ya se dieron siguen contando.
- Solo se puede **borrar** un tipo que no se ha dado nunca.

Hace falta el permiso «Gestionar el catálogo, los retos y los tipos de reconocimiento».

## Lo que se cuida

Quién jugó con quién no es público. Por eso:

- Solo se puede reconocer a quien estuvo en la misma partida o campaña, y solo durante el plazo. Los torneos no cuentan.
- No se ofrece a quien ha ocultado sus logros, a quien no tiene nombre público ni a nadie cuyo perfil no pueda ver quien reconoce.
- Los totales del perfil y los logros que cuentan reconocimientos se actualizan el día 1 de cada mes, nunca justo después de una partida.
- Nadie más que quien lo recibe sabe quién lo dio.

## Borrar un reconocimiento

No hay una lista con todos. Para borrar uno, abre la ficha de la persona en *Personas → Usuarios*. Al final está la tabla **Reconocimientos**, con lo que dio y lo que recibió, la otra persona y el mes. No sale la partida. Abre el menú **⋯** de la fila, pulsa **Borrar** y marca la casilla.

Hace falta el permiso «Ver y borrar los reconocimientos de cada persona». No lo tiene ningún rol por defecto: esa tabla dice quién jugó con quién, así que dalo solo a quien modera la asociación. También hay que poder ver la cuenta de la persona.

Quien recibe un reconocimiento también puede quitarlo desde su página **Reconocimientos**. Y se borran solos al anonimizar la cuenta de cualquiera de las dos personas.

## Logros por reconocimientos

En la regla de un logro puedes elegir **Reconocimientos recibidos**, de cualquier tipo o de uno, y cuántos hacen falta. Se cuentan el día 1 de cada mes, con lo recibido hasta el mes anterior, y entonces se conceden.
