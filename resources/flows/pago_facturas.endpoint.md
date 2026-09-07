# Contrato del endpoint — Flow `pago_facturas`

Meta llama a un único endpoint (`POST /api/whatsapp/flow/pago-facturas`) con el cuerpo
cifrado. Tras descifrar queda un JSON plano; lo que sigue es ese JSON, no el sobre.

Todas las respuestas viajan cifradas con AES-128-GCM usando **el IV invertido bit a bit**
respecto al de la petición. Es el punto donde más se falla.

---

## 0. Health check

Meta lo dispara al publicar el Flow y de forma periódica.

```json
→ { "version": "3.0", "action": "ping" }
← { "version": "3.0", "data": { "status": "active" } }
```

## 1. INIT — abre el Flow

`flow_token` es el que emitimos al enviar el mensaje; ata la sesión a un `user_id` +
`company_id` concretos. **Nunca confiar en datos del cliente para identificarlo.**

```json
→ { "version": "3.0", "action": "INIT", "flow_token": "ft_9f3a…" }

← { "version": "3.0", "screen": "RESUMEN", "data": {
      "titular": "Juan Pérez",
      "resumen": "Tienes 3 facturas pendientes por $195.000",
      "detalle": "NT19 · ago-2026 · $65.000\nNT20 · sep-2026 · $65.000\nNT21 · oct-2026 · $65.000",
      "alcances": [
        { "id": "all",    "title": "Pagar todo",         "description": "$195.000 · 3 facturas" },
        { "id": "single", "title": "Elegir una factura", "description": "Paga solo la que quieras" }
      ]
   }}
```

Sin facturas pendientes → `screen: "AVISO"` con el mensaje correspondiente.

## 2. RESUMEN → ELEGIR_FACTURA | DATOS

```json
→ { "action": "data_exchange", "screen": "RESUMEN", "data": { "alcance": "single" } }
← { "screen": "ELEGIR_FACTURA", "data": { "facturas": [
      { "id": "13320", "title": "NT13320 · $50.000", "description": "Vence 15 sep 2026" }
   ]}}
```

Con `"alcance": "all"` se salta directo a `DATOS`.

## 3. DATOS

Los campos van precargados desde `user_data`; el cliente solo corrige.

```json
→ { "action": "data_exchange", "screen": "DATOS", "data": {
      "nombre": "Juan", "apellido": "Pérez", "correo": "juan@correo.com",
      "tipo_documento": "CC", "documento": "1094123456" }}
← { "screen": "METODO", "data": { "total": "Total a pagar: $195.000", "metodos": [...] } }
```

Aquí se llama a `generate-payment` con `checkout_type: "api"` y se guarda
`payment_id` + `token` en la sesión del Flow, **no en el `data` de la pantalla**
(todo lo que va en `data` viaja al teléfono).

## 4. METODO → BREB | EFECTIVO | SALIR

```json
→ { "action": "data_exchange", "screen": "METODO", "data": { "metodo": "breb" } }
← { "screen": "BREB", "data": { "total": "Total a pagar: $195.000", "celular": "3123456789" } }
```

Con `"metodo": "otros"` se responde `SALIR` con la `checkout_url` de EfiPay
(`checkout_type: "redirect"`, el camino que ya está en producción hoy).

## 5. BREB → QR_BREB

`POST /api/v1/payment/transaction-checkout/bre-b` devuelve `qr_breb.qr_code_image`
en base64. El componente `Image` lo consume **sin** el prefijo `data:image/png;base64,`.

```json
→ { "action": "data_exchange", "screen": "BREB", "data": { "celular": "3123456789" } }
← { "screen": "QR_BREB", "data": {
      "qr": "iVBORw0KGgo…",
      "instruccion": "Abre la app de tu banco, elige Bre-B y escanea este código. Son $195.000.",
      "vence": "El código vence a las 11:24 a. m.",
      "referencia": "netplay-sas-MULTI-1788614971-13320" }}
```

## 6. EFECTIVO → CUPON

`POST /api/v1/payment/transaction-checkout/cash`. Responde siempre en estado
`Por Pagar`; la acreditación llega después por el webhook que ya existe.

## 7. Cierre

Las cuatro pantallas terminales usan `complete`. Meta devuelve el `payload` al chat
como un mensaje `interactive.nfm_reply`, que el bot ya puede recibir:

```json
{ "resultado": "breb_generado", "referencia": "netplay-sas-MULTI-1788614971-13320" }
```

---

## Reglas que no se pueden romper

1. **El monto se recalcula en el servidor en cada paso.** Nada de montos que vengan
   del teléfono: el cliente puede alterar el payload.
2. **La factura se valida contra el `user_id` del `flow_token`.** Sin eso, un cliente
   podría pagar (o consultar) la factura de otro cambiando un `id`.
3. **Ni PAN ni CVV entran a este endpoint.** Es política de Meta y además es lo que
   nos mantiene en PCI SAQ-A. Tarjeta, PSE, Nequi y Daviplata salen por `SALIR`.
4. **La acreditación la manda el webhook, nunca el Flow.** El Flow solo genera el
   cobro. Que el cliente vea el QR no significa que pagó.
5. **`flow_token` de un solo uso y con vencimiento** (30 min, igual que el QR de Bre-B).

## Claves

Par RSA-2048; la pública se sube una vez:

```bash
openssl genrsa -out flow_private.pem 2048
openssl rsa -in flow_private.pem -pubout -out flow_public.pem
# POST /{phone_number_id}/whatsapp_business_encryption  business_public_key=@flow_public.pem
```

La privada va en `.env` (`WA_FLOW_PRIVATE_KEY`), nunca en el repositorio.
