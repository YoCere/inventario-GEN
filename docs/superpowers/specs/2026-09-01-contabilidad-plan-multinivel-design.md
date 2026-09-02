# Plan de Cuentas Multinivel — Validación (B2) — Diseño

**Fecha:** 2026-09-01
**Estado:** Aprobado (diseño). Cambio chico → se implementa inline con TDD (sin plan-doc aparte).
**Parte de:** Mejoras contables del contador. Tema **B, pieza 2** (habilitación multinivel). Pieza 1 (IVA/IT)
en main. Alcance elegido con el usuario: **solo habilitación (validación)**, sin presentación jerárquica.

## Objetivo

Permitir crear sub-cuentas de **4-5 niveles** de forma segura. El motor ya soporta N niveles
(`ChartOfAccountService`: el padre se vuelve no-imputable al colgar hijos, el tipo del hijo debe coincidir,
`level = padre.level + 1`). Faltan dos guardas para que la profundidad no se descontrole y los códigos sean
coherentes con el árbol.

## Estado actual (con file:line)

- `ChartOfAccountService::applyParentRulesAndResolveLevel` (`app/Services/Accounting/ChartOfAccountService.php:106-138`)
  deriva `level = parent.level + 1` **sin tope**, valida que el tipo del hijo coincida con el padre, y voltea
  el padre a `allows_posting=false` si no tiene movimientos. Llamado por `create` (`:37`) y `update` estructural (`:84`).
- Cuenta raíz (sin `parent_id`) → `level = 1`, sin validaciones de padre.
- Los reportes/saldos agregan por hoja imputable y por `account_type` — agregar niveles **no** los rompe.

## Requisitos

| # | Requisito |
|---|-----------|
| R1 | **Profundidad máxima:** en `applyParentRulesAndResolveLevel`, si `level` resultante > **5** (const `MAX_LEVELS = 5`), lanzar `RuntimeException` con mensaje claro (ej. "No se pueden crear más de 5 niveles en el plan de cuentas."). |
| R2 | **Código coherente con el padre:** el `code` del hijo debe empezar con `padre.code . '.'` (ej. bajo `1.1.1.4` el hijo debe ser `1.1.1.4.x`). Si no, lanzar `RuntimeException` (ej. "El código del hijo debe comenzar con el código del padre (1.1.1.4.)."). |
| R3 | Ambas validaciones aplican en `create` y `update` estructural (ambos pasan por el método). Las cuentas **raíz** (sin padre) no se ven afectadas. Las cuentas **existentes** no se re-validan (solo altas/cambios estructurales nuevos). |

## Arquitectura

Dos guardas agregadas al método privado que ya centraliza las reglas de padre. Sin migraciones, sin vistas,
sin seeder. Cambio localizado y testeable: dado un padre y un código, el método acepta o rechaza.

## Manejo de errores

`RuntimeException` en español, consistente con las reglas existentes del servicio. La transacción de
`create`/`update` revierte cualquier volteo de padre si la validación falla después (el orden importa: validar
profundidad y código **antes** de voltear el padre).

## Testing (PHPUnit + RefreshDatabase)

- Crear nivel 5 bajo un nivel 4 → OK (`level = 5`).
- Crear nivel 6 (hijo de un nivel 5) → `RuntimeException` (profundidad).
- Hijo con código que no empieza con `padre.code . '.'` → `RuntimeException` (coherencia).
- Hijo con código coherente (`1.1.1.4.1` bajo `1.1.1.4`) → OK.
- Cuenta raíz (sin padre) → sin validación de profundidad/código; `level = 1`.
- Creación de 3 niveles existente (`1.1.01` bajo `1.1`) → sigue funcionando.
- Si la validación de código/profundidad falla, el padre **no** queda volteado a no-imputable (atomicidad).

## Fuera de alcance

- Presentación jerárquica (indentación / subtotales por grupo) — descartada por el usuario en este alcance.
- Seeding de sub-cuentas ejemplo — el usuario crea las que necesite.
- Asientos de cierre (IUE/reserva/cierre de resultados) → **tema C2** (siguiente).
