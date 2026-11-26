# Pendientes — sdk-runtime

> **Estado (2026-11-25):** publicada la **1.14.1** (`TypedArray` y `Map` genéricos: los elementos de una colección generada tienen tipo para el análisis estático; solo PHPDoc). Antes la **1.14.0** (compresión de respuestas por defecto; los eventos de un flujo llegan cuando se envían; pruebas con servidor real). Antes: **1.13.0** (formato `nested` y `pathAllowReserved`) y **1.12.1** (1.12.0: la llamada solo serializa y la respuesta se da por buena, D25; 1.12.1: solo documentación). Publicados también: TypedArray 1.3.1, sdk-generator 1.14.0, casafari-sdk 0.7.0 y redsys-sdk 0.1.0. Publicada la **1.13.0**.
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
- [ ] Compresión: el cliente Guzzle por defecto no envía `Accept-Encoding` (no se fija `decode_content`), así que un servidor que comprime solo a petición nunca comprime para un SDK; una respuesta gzip que llegue sí se descomprime. Decidir si el cliente por defecto lo pide (`RealTransportTest::testADeflatedAnswerArrivesDecoded` fija hoy el comportamiento).

**Formatos**
- [ ] Códecs para modelos XML y CSV estructurados (hoy son cadenas opacas).
- [ ] Transcodificación de charsets.
- [ ] Multipart secuencial (`itemSchema`/`itemEncoding`), errores binarios sin buffer completo, multipart con entradas no seekables y reconexión automática de SSE.

**Modelos**
- [ ] Política configurable para propiedades JSON desconocidas (hoy: una respuesta las ignora y un modelo que construyes las rechaza; no se puede cambiar).
- [ ] Diferencias de `pattern` entre JSON Schema (ECMA-262) y PCRE.

**Rendimiento**
- [ ] **Coste de la primera llamada en un proceso nuevo.** Medido el 2026-11-26 sin red (cliente escrito a mano para PokeAPI, respuesta simulada con Guzzle, mediana de 15 procesos nuevos por caso): la primera llamada tarda unos 2,9 ms sin OPcache y unos 1,0 ms con él (`opcache.file_cache`), y la segunda unos 0,09 ms. La primera carga 34 ficheros PHP más (40 ya estaban cargados al conectar). `docs/why.md` da unas 1,4 veces el tiempo de OpenAPI Generator (54–57 ficheros frente a 29–30) y deja sin medir el efecto de OPcache. Investigar cómo reducirlo: (a) cuántos de esos 34 ficheros son imprescindibles en la primera llamada y si se pueden cargar solo cuando hacen falta o agrupar los más usados; (b) qué parte del ~1 ms que queda con OPcache es calcular una vez por clase y por operación (tipos de propiedades, reglas, plan de la operación), y si el generador puede emitir esos metadatos ya calculados o el runtime puede conservarlos entre peticiones; (c) si `opcache.preload` ayuda y cómo documentarlo; (d) repetir la medición con un SDK generado y con OpenAPI Generator en las mismas condiciones, para no mezclar cifras. Criterio de cierre: un script de medición reproducible (como el workflow `Audit speed`) y una cifra antes y después, sin cambiar el comportamiento. Propuesto el 2026-11-26.

**Pruebas con servidor real (local)**
- [x] `RealTransportTest` y `RealSecurityTest` (servidor guionizado en `127.0.0.1`, cliente Guzzle por defecto): reintentos y `Retry-After`, redirecciones y credenciales entre orígenes, tiempo de espera, conexión rechazada, gzip, middleware, Bearer, Basic, claves de API y OAuth2 (credenciales de cliente, renovación, password, código con PKCE).
- [x] También con servidor real: subida y descarga de 32 MiB con 16 MiB de memoria (`tests/real-streaming-smoke.php`, en `ci/run-tests.sh`), OIDC (descubrimiento, claves y rotación; `RealOpenIdTest`), flujos de eventos y JSON Lines que llegan con el tiempo (`RealStreamingResponseTest`) y mTLS con una CA creada en el test (`RealMutualTlsTest`).
- [ ] Sin cubrir con servidor real: reconexión automática de SSE (no existe: ver arriba), HTTP/2 y proxies.

**Limitaciones conocidas de PHP 8.4 (no se pueden arreglar aquí)**
- Una propiedad con *hook* no se puede `unset` ni editar en sitio, así que las listas nativas no tienen *hook* y sus reglas no se comprueban al asignar (use colecciones tipadas).

## Publicado en la 1.13.0

- Serialización anidada `nested` (consulta, formularios urlencoded y multipart), `pathAllowReserved` (resto de ruta con barras) y el tipo del argumento `$path` (acepta un caso de enum).
- `typedarray/typedarray` pasa de `^1.1` a `^1.3.1` y las colecciones sin reglas por elemento ya no recorren los elementos con `Constraints::validate` (antes en el PR #39, ya integrado). Nota: `docs/PUBLIC_API.md` dice que estrechar un rango de dependencia es un cambio mayor; el precedente (`^1.0` → `^1.1`) lo trató como menor.

## Pendiente en el resto del proyecto

**Runtime / diseño (propuestas, sin empezar)**
- [ ] Unificar las clases raíz de los clientes generados (`Client`, `Configuration`, la fachada `<Nombre>SDK`) en el runtime. Mueve `configure()`/`current()` al runtime; es un cambio incompatible para los SDKs generados.
- [ ] Valores de enum desconocidos en respuestas: hoy el runtime lanza `ValueError` aunque «la respuesta se da por buena» (Redsys lo sufrió y usa cadenas para conjuntos abiertos). Decidir si se tolera.
- [ ] Hacer protegido `checkAll()` en TypedArray para que `validateForMode()` use su bucle rápido (otro release de TypedArray; beneficio pequeño).
- [ ] Documentación: revisar `docs/AUDIT.md` y los README del generador y de casafari-sdk con la nueva medida (bytes de PHP, no `du`).

**sdk-generator**
- [ ] Deduplicar enums de parámetros idénticos entre operaciones (Wikimedia).
- [ ] Ergonomía de la navegación por parámetros de ruta profundos (`ByProject\ByAccess\…`).
- [ ] weather.gov no se genera: refinamiento anidado de elementos de lista con objeto en línea.
- [ ] Menos archivos por SDK (hoy una clase por archivo: ~1.200 frente a ~420 de OpenAPI Generator en los tres contratos medidos).
- [ ] Quitar los restos inertes de `SDK_RUNTIME_REF` en `live-apis.yml` y en los scripts *smoke*.
- [ ] `ci/verify-published.php` (arreglado en #88) solo se ejecuta al publicar: comprobar en el próximo release.

**redsys-sdk**
- [ ] Poner `main` como rama por defecto del repositorio (Settings).
- [ ] No cubiertos: API de consultas, Bizum e InSite.


## Historial de seguimiento

| Fecha | Cambio | Referencia |
| --- | --- | --- |
| 2026-11-10 | Se crea esta hoja de ruta con la leyenda común y el orden global runtime → generador → Casafari. | Conversación de planificación |
| 2026-11-11 | Revisión de limpieza de lo acumulado (reutilización, responsabilidad única, eficiencia) con comparación lado a lado del código anterior y el nuevo; corrige `MediaType::normalize()`, un `TypeError` con SSE sin tipo, la fuga de credenciales con middleware entre orígenes y la renovación OAuth ante errores transitorios. | Ver CHANGELOG |
| 2026-11-23 | Se reescribe la hoja de ruta: lo hecho remite al CHANGELOG y solo quedan como pendientes los puntos comprobados en el código. Se fija la regla de publicación. | Revisión de limpieza |
| 2026-11-24 | Publicadas TypedArray 1.3.1, runtime 1.12.0/1.12.1, generador 1.14.0, casafari-sdk 0.7.0 y redsys-sdk 0.1.0. Se anotan todos los pendientes del proyecto. | PR #35, #37, #39 |
| 2026-11-25 | Limpieza de ramas y PR de los tres repos principales; el PR #39 (1.12.2) se integra en la 1.13.0. | Limpieza |
