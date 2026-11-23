# Pendientes — sdk-runtime

> **Estado (2026-11-23):** publicada la **1.11.1**. En la rama de trabajo, sin publicar, está la **1.12.0** (el cambio D25: la llamada solo serializa y la respuesta se da por buena).
>
> **Qué es este fichero:** lo que **falta**. Lo que ya está hecho y publicado no se repite aquí: está en [CHANGELOG.md](CHANGELOG.md) y en [docs/DECISIONS.md](docs/DECISIONS.md). Cada punto de abajo se comprobó en el código en la fecha de arriba; si deja de ser cierto, se borra.
>
> **Principio:** el runtime concentra transporte, ejecución, seguridad e hidratación. Los SDKs generados solo declaran tipos y metadatos de operación. Se reutiliza PHP, Guzzle, PSR y Opis antes de escribir utilidad propia.

## Regla de publicación

Una versión se publica cuando el trabajo está **terminado y probado**, y solo cuando el titular lo confirma. No se suben versiones intermedias. El runtime se prueba contra la CI del generador antes de publicarse (D23).

## Hecho (resumen; el detalle está en el CHANGELOG)

- **Contrato público congelado** desde la 1.0.0 ([docs/PUBLIC_API.md](docs/PUBLIC_API.md)).
- **Seguridad:** apiKey, HTTP basic/bearer, OAuth2 (client credentials, authorization code con PKCE, password, implicit), OpenID Connect con descubrimiento y **validación de ID tokens**, mTLS, comprobación de scopes.
- **Transporte:** Guzzle/PSR-18, reintentos con `Retry-After` (opt-in), redirecciones (opt-in), middleware, streaming de cuerpos y respuestas, servidores relativos y variables.
- **Modelos:** hidratación tipada, enums, colecciones y mapas, validación de reglas al asignar con *hooks* de PHP 8.4.

## Pendiente (verificado en el código)

**Autenticación**
- [ ] OAuth2: `refreshUrl` distinto del `tokenUrl`.

**Transporte**
- [ ] Persistencia general de cookies (no hay *cookie jar*) y cookies estructuradas, JSON, arrays y objetos.
- [ ] Método HTTP `QUERY`.
- [ ] Políticas completas para respuestas sin contenido (HEAD/205 y combinaciones restantes).

**Formatos**
- [ ] Códecs para modelos XML y CSV estructurados (hoy son cadenas opacas).
- [ ] Transcodificación de charsets.
- [ ] Multipart secuencial (`itemSchema`/`itemEncoding`), errores binarios sin buffer completo, multipart con entradas no seekables y reconexión automática de SSE.

**Modelos**
- [ ] Política configurable para propiedades JSON desconocidas (hoy: una respuesta las ignora y un modelo que construyes las rechaza; no se puede cambiar).
- [ ] Diferencias de `pattern` entre JSON Schema (ECMA-262) y PCRE.

**Limitaciones conocidas de PHP 8.4 (no se pueden arreglar aquí)**
- Una propiedad con *hook* no se puede `unset` ni editar en sitio, así que las listas nativas no tienen *hook* y sus reglas no se comprueban al asignar (use colecciones tipadas).

## En rama, sin publicar (1.12.0)

- La llamada solo serializa y la respuesta se da por buena (D25); `DTO::isReadingResponse()` interno.
- Una colección construida desde una respuesta no se comprueba dos veces.
- `.gitattributes` con `export-ignore`: el paquete instalado pierde tests, docs de desarrollo, `audit/` y `ci/` (de 2,1 MB a 0,6 MB). Se conservan los servidores de prueba que usan los *smoke* del generador.
- Timeouts por defecto (`Configuration::$timeout` 30 s, `$connectTimeout` 10 s) en el cliente HTTP por defecto.
- Guía para escribir un cliente a mano (`docs/writing-an-sdk-by-hand.md`).
- Workflows `audit-casafari` y `audit-apis` (PokeAPI, Wikimedia, met.no) (memoria, CPU y disco frente a OpenAPI Generator sobre el contrato real de Casafari).

## Historial de seguimiento

| Fecha | Cambio | Referencia |
| --- | --- | --- |
| 2026-11-10 | Se crea esta hoja de ruta con la leyenda común y el orden global runtime → generador → Casafari. | Conversación de planificación |
| 2026-11-11 | Revisión de limpieza de lo acumulado (reutilización, responsabilidad única, eficiencia) con comparación lado a lado del código anterior y el nuevo; corrige `MediaType::normalize()`, un `TypeError` con SSE sin tipo, la fuga de credenciales con middleware entre orígenes y la renovación OAuth ante errores transitorios. | Ver CHANGELOG |
| 2026-11-23 | Se reescribe la hoja de ruta: lo hecho remite al CHANGELOG y solo quedan como pendientes los puntos comprobados en el código. Se fija la regla de publicación. | Revisión de limpieza |

