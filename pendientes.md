# Pendientes — sdk-runtime

> **Estado:** hoja de ruta; NO equivale a funcionalidades implementadas.
>
> **Línea base estable:** `dmorenof/sdk-runtime` **v0.31.0** (usada por `dmorenof/sdk-generator` v0.40.0).
>
> **Criterio de actualización:** `[x]` significa implementado y verificado con pruebas y CI; anotar la versión publicada. Las tareas parcialmente cubiertas conservan `[ ]` con el detalle de lo hecho y lo que falta.
>
> **Principio:** el runtime concentra transporte, ejecución, seguridad, hidratación y validación. Los SDKs generados solo declaran tipos y metadatos de operación. Reutilizar PHP, Guzzle, PSR y opis/json-schema antes de escribir utilidades propias (ver [AGENTS.md](AGENTS.md)). Los metadatos nuevos se añaden al final de `Operation` sin cambiar posiciones de constructores.

## Leyenda

Igual en los tres repositorios (runtime, generador y casafari-sdk):

- **P0:** bloquea que el runtime sirva para cualquier API; abordar primero.
- **P1:** compatibilidad amplia y requisitos de producción.
- **P2:** funciones avanzadas o compatibilidad heredada.
- **P3:** utilidades adicionales.
- **Parcial:** existe una implementación limitada; la casilla sigue abierta.

## Orden global de trabajo (acordado 2026-11-10)

Idéntico en los tres repositorios. Cada fase se publica por avances terminados (runtime primero).

1. **sdk-runtime:** cubrir lo necesario para servir a cualquier API y congelar su API pública (1.0).
2. **sdk-generator:** cumplir las especificaciones más importantes (Swagger 2.0, OpenAPI 3.0/3.1) sobre ese runtime.
3. **casafari-sdk:** evaluar y, si procede, migrar a un SDK generado.

Durante las fases 1 y 2, el SDK de Casafari generado sigue pasando como prueba de aceptación en CI del generador.

## 0. Línea base ya disponible (v0.31.0)

- [x] `ApiClient::connect()`, `resource()`, `withMiddleware()` y ejecución común de operaciones; Guzzle por defecto y cualquier cliente PSR-18.
- [x] Seguridad Basic, Bearer y API key (header, query, cookie) con alternativas AND/OR; credenciales ocultas en depuración.
- [x] DTO/Request/Response, TypedArray, Map, AnyOf, OneOf; hidratación y validación direccional (readOnly/writeOnly), discriminadores.
- [x] Serialización de parámetros: listas, objetos planos, deepObject, label/matrix, simple, delimitadas, allowReserved/allowEmptyValue, cookies escalares, content JSON/text.
- [x] Cuerpos: JSON raíz con `null` explícito frente a ausente, urlencoded, multipart (partes JSON y streams), text/plain y binario por streams PSR-7.
- [x] Respuestas: selección exacta/rango/default, mezcla JSON/binario/sin contenido, MIME concretos, errores declarados (`ApiException::payload`).
- [x] Servidores literales con `serverOverrides` por conexión.
- [x] Validación JSON Schema acotada vía opis/json-schema (rangos numéricos, unicidad, const, contadores).

## 1. [P0] Autenticación

- [x] **Sin publicar —** OAuth2 `clientCredentials` con obtención de token, Basic o body, scopes y caché (`OAuth2ClientCredentials`).
- [x] **Sin publicar —** Interfaz de almacén de tokens inyectable (`TokenStoreInterface`, memoria por defecto) y expiración con margen.
- [x] **Sin publicar —** Renovación con `refresh_token` y un único reintento tras 401. Pendiente: `refreshUrl` distinto del `tokenUrl`.
- [ ] OAuth2 `authorizationCode` con PKCE (utilidades del flujo; la interacción con el usuario queda fuera).
- [ ] OAuth2 `password` e `implicit` solo por compatibilidad heredada.
- [ ] OpenID Connect y descubrimiento de configuración.
- [ ] Scopes: asociación y comprobación.
- [ ] mTLS: certificados y claves de cliente.
- [ ] Almacenamiento seguro de credenciales y redacción en logs/excepciones.

## 2. [P0] Respuesta y transporte

- [x] **Sin publicar —** Acceso tipado a cabeceras de respuesta declaradas (`ApiClient::lastResponse()`, `ResponseHeaders`, metadato `responseHeaders`) y `Set-Cookie`. Pendiente en el generador: emitir `responseHeaders` desde `responses[*].headers`.
- [ ] Política configurable de redirecciones `3xx`.
- [ ] Reintentos y backoff para 429/5xx y errores de red, con respeto de `Retry-After`; desactivados por defecto.
- [ ] Timeouts y opciones de transporte documentados y configurables por cliente.
- [ ] **Parcial — Respuestas sin contenido:** estados explícitos cubiertos; faltan políticas para HEAD/205 y combinaciones restantes.
- [ ] Persistencia general de cookies.

## 3. [P1] Negociación y formatos

- [ ] Selección explícita de `Content-Type`/`Accept` entre representaciones alternativas y wildcard MIME.
- [ ] Interfaz de códecs para modelos XML/CSV estructurados (hoy son strings opacos).
- [ ] Entradas no seekables y garantías de streaming generales; errores binarios sin buffer completo.
- [ ] Transcodificación de charsets.

## 4. [P1] Servidores

- [ ] Servidores relativos y resolución frente a la base URL.
- [ ] Selección dinámica entre varios servidores y API de variables con nombre.

## 5. [P1] Validación JSON Schema

- [ ] **Parcial:** numéricos, unicidad, const escalar/null y contadores cubiertos. Faltan `contains`/`minContains`, const complejo y `not`.
- [ ] `if`/`then`/`else`, `dependentRequired`/`dependentSchemas`.
- [ ] `prefixItems` y tuplas; `patternProperties`, `propertyNames`.
- [ ] `unevaluatedProperties`/`unevaluatedItems`.
- [ ] Uniones nativas de objetos/arrays.
- [ ] Validadores de `format` (fecha, hora, email, hostname, ip, uri, uuid, json-pointer…) con política explícita.
- [ ] Diferencias de `pattern` entre JSON Schema y PCRE.
- [ ] Política configurable para propiedades JSON desconocidas (ignorar, conservar, rechazar).

## 5b. [P2] Funciones avanzadas

- [ ] Streaming `text/event-stream` (SSE), `application/jsonl` y `application/json-seq`.
- [ ] Método HTTP `QUERY` y métodos adicionales.
- [ ] Multipart secuencial (`itemSchema`/`itemEncoding`).
- [ ] Cookies estructuradas/JSON y arrays/objetos en cookies.

## 6. [P0] Cierre 1.0

- [ ] Definir y documentar qué es API pública y qué es interna (`@internal`).
- [ ] Política de compatibilidad (SemVer desde 1.0) y de deprecaciones.
- [ ] Prueba de compatibilidad con SDKs generados por versiones 0.x anteriores del generador.
- [ ] Registro en Packagist y verificación de instalación aislada.
- [ ] Documentación de uso del runtime independiente del generador.
- [ ] Cobertura de pruebas y PHPStan máximo en la matriz PHP 8.3–8.5 y dependencias mínimas (ya en CI; mantener).

## Cierre de 0.2.0 → 1.0.0 (decidido 2026-11-10)

- La línea de trabajo es `feature/p0-contract-fidelity` (runtime) coordinada con `release/0.2.0` (generador). Al terminarla, runtime y generador se publican como **v1.0.0**, runtime primero.
- **Sin PR:** la entrega es una release (cambio de `VERSION` → tag inmutable y GitHub Release por el workflow existente). Si la protección de `main` exigiera un PR, se trata como trámite técnico (ver [AGENTS.md](AGENTS.md)).
- Hasta entonces se acumula sin publicar; `VERSION`, tags y la línea base estable (v0.31.0) no se tocan.
- **Antes de la 1.0.0** (obligatorio): ejecutar por primera vez PHPUnit, PHPStan máximo, matriz PHP 8.3–8.5, dependencias mínimas e instalación aislada sobre todo lo acumulado; congelar la API pública (apartado 6).

## Historial de seguimiento

| Fecha | Cambio | Referencia |
| --- | --- | --- |
| 2026-11-10 | Se crea esta hoja de ruta con la leyenda común y el orden global runtime → generador → Casafari. | Conversación de planificación |
