# Plantillas de Meta para avisos de pago

Se usan **solo** cuando la ventana de 24 h está cerrada, es decir cuando el
cliente pagó desde el portal sin habernos escrito antes. Si nos escribió, sale
el mensaje libre, que es más completo.

Crear en *WhatsApp Manager → Plantillas de mensajes*:

- Categoría: **Utilidad** (no Marketing: se rechaza y además cobra distinto)
- Idioma: **Español (COL)** → código `es_CO`

Los nombres deben quedar exactamente así; el código los busca por nombre.

---

## 1. `pago_confirmado`

```
Hola {{1}}, confirmamos tu pago.

Valor: {{2}}
Medio de pago: {{3}}
Comprobante: {{4}}

Con esto {{5}}. Gracias por estar al día.
```

Ejemplo para la revisión de Meta:
`Juan` · `$60.000` · `Nequi` · `594192` · `tu factura quedó pagada`

## 2. `pago_pendiente`

```
Hola {{1}}, registramos tu pago por {{2}} con {{3}}, pero todavía no se ha acreditado.

Comprobante: {{4}}

Si pagaste en efectivo o por transferencia puede tardar unas horas. Te avisamos apenas se acredite. No necesitas volver a pagar.
```

Ejemplo: `Juan` · `$60.000` · `Efectivo (Efecty)` · `594192`

## 3. `pago_no_completado`

```
Hola {{1}}, tu pago por {{2}} con {{3}} no pudo procesarse.

Comprobante: {{4}}

No se te hizo ningún cobro y tu factura sigue pendiente. Puedes intentarlo de nuevo con otro medio de pago.
```

Ejemplo: `Juan` · `$60.000` · `Tarjeta Visa` · `594192`

---

## Notas

- Meta rechaza saltos de línea y tabulaciones **dentro de una variable**. El
  código ya los colapsa a espacios antes de enviar.
- Una plantilla que empieza o termina en variable suele rechazarse; por eso
  todas abren con "Hola".
- Mientras no estén aprobadas, el aviso simplemente no sale y queda registrado
  en el log. Nada se rompe.
