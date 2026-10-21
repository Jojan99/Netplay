// Intervalo de reporte (Periodic Inform) de los Skyworth (GN630V con firmware de Claro).
//
// De fábrica se reportan una vez al día (86400 s): lo que no se les pueda aplicar al
// momento esperaría hasta 24 h, el ACS tardaría en enterarse de un cambio de IP y el
// panel los daría por caídos (2 h). Cada hora, como los C-Data (intervalo-cdata).
//
// Mientras la plataforma les cambia la conexión (etiqueta cambio_rapido), cada minuto,
// igual que intervalo-huawei. Son TR-181: la raíz es Device.
const rapido = declare("Tags.cambio_rapido", {value: 1});
const enCambio = !!(rapido.value && rapido.value[0]);

declare("Device.ManagementServer.PeriodicInformEnable", {value: 1}, {value: true});
declare("Device.ManagementServer.PeriodicInformInterval", {value: 1}, {value: enCambio ? 60 : 3600});
