---
id: achievements.panel.catalogue
title: Catálogo de logros y sus reglas
summary: Crea logros, decide cuándo se conceden solos y entiende por qué uno sale como «Sin fuente».
category: users-and-roles
audience: panel
order: 80
keywords: [logros, insignias, catálogo, reglas, conceder, partidas, sistemas, torneos, podio, eventos, antigüedad, secreto, sin fuente, puntos, umbral, recalcular]
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
- **Solo a mano** y **Regla no válida**: no se conceden solos. Una regla no válida se arregla editando el logro y guardando la regla de nuevo.

Cuando vuelves a encender un tipo de actividad o los logros secretos en los ajustes, los logros vuelven a evaluarse para todo el mundo, sin avisos. Mientras estuvo apagado no se concedió nada de ese tipo.

## Lo que se concede no se retira

Una concesión es para siempre: si una partida deja de contar o subes el umbral, quien ya tenía el logro lo conserva. Por eso un logro que tiene alguien no se puede borrar, solo desactivar: deja de concederse y de enseñarse, y quien lo tenía lo conserva. **Borrar** solo aparece, en el menú **⋯**, mientras nadie lo tiene, y pide marcar una casilla.

Solo una persona con permiso para conceder logros puede retirar uno, a mano y con un motivo, y entonces no vuelve a concederse solo. Lo explica la guía «Conceder y retirar logros a mano».

## Catálogo inicial

Al instalar el módulo se crea un catálogo con logros de bienvenida, partidas jugadas y dirigidas, sistemas distintos, campañas, torneos, eventos, antigüedad y propuestas. Solo empiezan activos los que tienen fuente. Puedes editarlos y borrarlos como cualquier otro. Si vuelves a crear el catálogo inicial, un logro que borraste vuelve desactivado.
