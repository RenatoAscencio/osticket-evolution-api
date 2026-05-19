# Notificaciones WhatsApp con Evolution API para osTicket

[![Licencia: GPL v2](https://img.shields.io/badge/Licencia-GPL_v2-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt)
[![osTicket](https://img.shields.io/badge/osTicket-%E2%89%A5%201.17-orange.svg)](https://github.com/osTicket/osTicket)
[![Evolution API](https://img.shields.io/badge/Evolution_API-v2-green.svg)](https://doc.evolution-api.com/)

> *Read this in [English](./README.md).*

Plugin de osTicket que envía notificaciones por **WhatsApp** a través de [Evolution API](https://doc.evolution-api.com/) tanto a los **usuarios finales** como a los **administradores** en los eventos del ciclo de vida del ticket que tú elijas, verificando primero si el teléfono del cliente realmente tiene WhatsApp.

---

## Funcionalidades

- **Notifica a clientes y admins por separado.** Los usuarios reciben mensajes en su WhatsApp; los admins reciben en una lista configurable de números.
- **Verificación previa de WhatsApp.** Antes de enviar a un cliente, el plugin consulta a Evolution API si ese número existe en WhatsApp y cachea la respuesta. Se acabaron los envíos perdidos.
- **Matriz evento × audiencia.** Toggle independiente para cada combinación, ej. "Ticket creado → notificar cliente ON, → notificar admins ON" vs "Cambio de estado → notificar cliente ON, → notificar admins OFF":
  - Ticket creado → cliente / admin
  - Respuesta del cliente → admin
  - Respuesta del staff → cliente / admin
  - Cambio de estado → cliente / admin
  - Cambio de asignación → admin
- **Opt-in por cliente (privacidad).** Opcional: añade un checkbox al formulario de usuario para que cada cliente active/desactive las notificaciones WhatsApp desde su perfil de osTicket. Ver [docs/user-opt-in.md](./docs/user-opt-in.md).
- **Plantillas dobles.** Cada evento tiene plantillas independientes para cliente y para admin, con tono y contenido distintos.
- **Normalización de teléfonos.** Acepta cualquier formato (espacios, guiones, `+`, paréntesis, ceros, prefijos nacionales) y normaliza a E.164 sólo dígitos.
- **Credenciales enmascaradas.** API key y Sentry DSN son `PasswordField` — no se muestran en la UI después de guardar.
- **Redacción de PII en logs.** Números de teléfono y mensajes se enmascaran/truncan en el log de PHP incluso con logging detallado activo. Seguro para hosting compartido.
- **Integración opcional con Sentry.** Errores del plugin — y opcionalmente todos los errores PHP de osTicket — pueden reportarse a Sentry. Sin Composer (cliente mínimo de envelope).
- **Revisión de seguridad incluida.** Ver [SECURITY.md](./SECURITY.md) para threat model, trust boundaries y accepted risks.
- **Pensado para PR upstream.** Estilo y licencia (GPL-2.0) acordes al repo oficial `osTicket/osTicket-plugins`.

---

## Empezar

### 1. Tener Evolution API corriendo

Si aún no tienes una instancia, sigue la [doc oficial](https://doc.evolution-api.com/). Necesitas:

- Base URL (ej. `https://evo.example.com`)
- Nombre de instancia (ej. `soporte`)
- API key

### 2. Copiar el plugin a osTicket

```bash
# Desde la raíz del repo:
rsync -av plugin/ /ruta/a/osticket/include/plugins/evolution-api/
```

En el panel de osTicket: **Manage → Plugins → Add New Plugin**, busca *Evolution API Notifications (WhatsApp)*, instálalo y entra a configurarlo.

Guía paso a paso (Docker local, deploy a producción, capturas): [INSTALL.md](./INSTALL.md).

### 3. Configurar

Obligatorios:

| Sección | Campo | Notas |
| ------- | ----- | ----- |
| Evolution API | Base URL, Instance, API key | Los tres son obligatorios. |
| Teléfonos | Código de país por defecto | Sólo dígitos, sin `+`. Se usa cuando un número viene sin código de país. |
| Destinatarios | Números WhatsApp de admins | Uno por línea, con código de país, sin `+`. |
| Misc | URL base de osTicket | Para construir el `{{ticket_link}}` en los mensajes. |

Opcionales pero recomendados:

- **Verificar existencia en WhatsApp antes de enviar a clientes** (por defecto activo)
- **Sentry DSN** para capturar errores del plugin en producción
- **Logging detallado** mientras pruebas, apagado en producción

---

## Cómo funciona

```
Señal de osTicket (ticket.created / threadentry.created / model.updated)
         │
         ▼
  EvolutionApiNotificationsPlugin            ◄── toggles por evento + plantillas dobles
         │
   ┌─────┴─────┐
   ▼           ▼
 Cliente     Admins
 (1 teléfono) (N teléfonos)
   │           │
   ▼           ▼
 PhoneNumberNormalizer  ─► WhatsAppNumberCache  ─► EvolutionApiClient
                                                       │
                                                       ▼
                                                Evolution API (HTTP)
                                                       │
                                                       ▼
                                                  WhatsApp
```

Los errores salen al log de PHP (siempre) y a Sentry (cuando hay DSN configurado).

Más detalle: [docs/architecture.md](./docs/architecture.md).

---

## Pruebas locales

La carpeta `docker/` trae un stack listo con osTicket 1.18.3 + MariaDB y el plugin montado:

```bash
cd docker
docker compose up -d
# osTicket: http://localhost:8081
# Admin:    http://localhost:8081/scp/
```

Ver [docker/README.md](./docker/README.md) para instrucciones de primer arranque.

---

## Deploy a producción

El repo incluye un script de deploy genérico controlado por variables de entorno:

```bash
cp scripts/.env.example scripts/.env       # gitignored
$EDITOR scripts/.env                       # rellena REMOTE, REMOTE_PLUGIN_DIR, etc.
source scripts/.env && ./scripts/deploy.sh --dry-run
source scripts/.env && ./scripts/deploy.sh
```

Paso a paso: [docs/deploy-production.md](./docs/deploy-production.md).

---

## Licencia

[GPL-2.0-or-later](./LICENSE). Compatible con [osTicket](https://github.com/osTicket/osTicket) y el repo oficial [osTicket/osTicket-plugins](https://github.com/osTicket/osTicket-plugins).
