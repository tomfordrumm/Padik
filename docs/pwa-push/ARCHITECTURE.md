# PWA и Push-уведомления: архитектура

## Текущая база

В проекте уже есть важные части:

- Laravel notifications через `database` и `broadcast`;
- таблица `notifications`;
- Reverb/Echo private channels;
- user notification channel `App.Models.User.{id}`;
- room channels `rooms.{conversation}`;
- unread counters в `conversation_participants`;
- секретные сообщения не пишутся в `messages`, а передаются как encrypted realtime payload.

Новая архитектура должна достроить Web Push и PWA, не ломая существующий realtime UX.

## Основные компоненты

### PWA shell

Файлы/зоны:

- `public/manifest.webmanifest`;
- PWA icons в `public/`;
- `resources/views/app.blade.php`;
- `resources/js/app.ts`;
- service worker в `public/` или через Vite build pipeline.

Responsibilities:

- installability metadata;
- service worker registration;
- asset caching;
- offline fallback для навигации;
- push/click event handling.

### Push subscriptions backend

Новые сущности:

- `PushSubscription` model;
- `push_subscriptions` migration;
- `PushSubscriptionController`;
- `PushSubscriptionData` или Form Request при необходимости;
- `PushNotificationService`;
- `SendWebPushNotification` job;
- VAPID generation command.

Предлагаемая таблица `push_subscriptions`:

| column | type | note |
| --- | --- | --- |
| `id` | bigint | primary |
| `user_id` | foreign id | cascade delete |
| `endpoint` | text/string | unique |
| `public_key` | text | p256dh |
| `auth_token` | text | auth |
| `content_encoding` | string nullable | default `aes128gcm` |
| `user_agent` | text nullable | для диагностики |
| `last_used_at` | timestamp nullable | обновлять при subscribe/send |
| `expires_at` | timestamp nullable | из `expirationTime` |
| `created_at` | timestamp | Laravel |
| `updated_at` | timestamp | Laravel |

Индексы:

- unique `endpoint`;
- index `user_id`;
- optional index `expires_at`.

### Push API routes

Routes под `auth` и `verified`:

```text
GET    /push-subscriptions/key
POST   /push-subscriptions
DELETE /push-subscriptions
POST   /push-presence
DELETE /push-presence
```

Контракты:

`GET /push-subscriptions/key`

```json
{
  "public_key": "VAPID_PUBLIC_KEY"
}
```

`POST /push-subscriptions`

```json
{
  "endpoint": "https://push.example/subscription-id",
  "expiration_time": "2026-06-26T12:00:00.000Z",
  "keys": {
    "p256dh": "base64url",
    "auth": "base64url"
  },
  "user_agent": "optional"
}
```

Response:

```json
{
  "enabled": true
}
```

`DELETE /push-subscriptions`

```json
{
  "endpoint": "https://push.example/subscription-id"
}
```

Response:

```json
{
  "enabled": false
}
```

`POST /push-presence`

```json
{
  "conversation_id": 123
}
```

Presence storage:

- key: `push-presence:user:{user_id}:conversation:{conversation_id}:session:{session_id}`;
- TTL: 60-90 seconds;
- client refresh interval: 25-30 seconds while chat is active and document visible.

### Notification dispatch

Existing Laravel notifications should remain the source of domain notification data.

Recommended approach:

1. Keep existing `database` and `broadcast` channels.
2. Add push dispatch from notification flow via a dedicated service/job.
3. Do not block request lifecycle.
4. Normalize payloads through one application-level DTO.

Canonical push payload:

```json
{
  "type": "direct_message",
  "title": "New direct message",
  "body": "Alice sent you a message",
  "action_url": "https://padik.test/dms/2",
  "notification_id": "uuid-or-null",
  "conversation_id": 10,
  "sender_id": 2,
  "tag": "conversation-10",
  "timestamp": "2026-06-26T12:00:00Z"
}
```

The service worker should use:

- `title`;
- `body`;
- `data.action_url`;
- `tag`;
- optional `badge`;
- optional `icon`.

### Suppression for active chat

Push suppression checks whether recipient is actively viewing the target conversation.

Rules:

- suppress only OS push;
- do not suppress database notification unless existing product behavior requires that;
- do not suppress realtime Echo broadcast;
- TTL expiry means "not active";
- failed presence writes should not break chat usage.

Implementation sketch:

```php
if ($pushPresence->isViewingConversation($recipient, $conversation)) {
    return;
}

SendWebPushNotification::dispatch($recipient->id, $payload);
```

Frontend:

- when `currentRoom` changes, register active conversation;
- refresh heartbeat while visible;
- clear on unmount, route leave, or visibility hidden if possible.

### Encrypted mailbox for secret chats

New entity: `SecretChatDelivery` or `SecretMessageDelivery`.

Suggested table `secret_message_deliveries`:

| column | type | note |
| --- | --- | --- |
| `id` | uuid/string | client-visible message id |
| `conversation_id` | foreign id | cascade delete |
| `sender_id` | foreign id | cascade delete |
| `recipient_id` | foreign id | cascade delete |
| `ciphertext` | text | encrypted content only |
| `iv` | string/text | AES-GCM IV |
| `sender_fingerprint` | string | current E2EE fingerprint |
| `delivered_at` | timestamp nullable | set after ack |
| `read_at` | timestamp nullable | optional, not required MVP |
| `expires_at` | timestamp nullable | cleanup policy |
| `created_at` | timestamp | Laravel |
| `updated_at` | timestamp | Laravel |

Indexes:

- `recipient_id`, `conversation_id`, `delivered_at`;
- `conversation_id`, `created_at`;
- optional `expires_at`.

Routes:

```text
GET    /secret-chats/{conversation:slug}/deliveries
POST   /secret-chats/{conversation:slug}/deliveries/{delivery}/ack
```

Existing `POST /secret-chats/{conversation:slug}/messages` changes behavior:

- validate sender is participant;
- create one encrypted delivery per recipient participant;
- broadcast to online room participants as today;
- enqueue generic secret push for recipients not active in the chat;
- return sender-side message id and timestamp.

`GET /deliveries` returns pending encrypted messages for current user:

```json
{
  "messages": [
    {
      "id": "uuid",
      "sender_id": 2,
      "author": "Alice",
      "ciphertext": "...",
      "iv": "...",
      "sender_fingerprint": "...",
      "time": "12:30",
      "created_at": "2026-06-26T12:30:00Z"
    }
  ]
}
```

Ack semantics:

- client decrypts and appends message;
- client sends ack;
- server marks `delivered_at` or deletes row;
- if ack fails, later fetch may return duplicate, so client must de-duplicate by `id`.

Security constraints:

- never store plaintext;
- never include ciphertext in OS push payload;
- only participants can create/fetch/ack deliveries;
- recipient can fetch only their own pending deliveries;
- sender cannot read recipient mailbox.

### Service worker behavior

Required events:

- `install`;
- `activate`;
- `fetch` for assets/offline fallback;
- `push`;
- `notificationclick`;
- optional `pushsubscriptionchange`.

`push` handling:

- parse JSON payload;
- show notification with private text;
- include `data.action_url`;
- use stable `tag` per conversation to reduce notification spam.

`notificationclick` handling:

- close notification;
- focus existing matching client if available;
- otherwise open `action_url`;
- default to `/r/general` if URL missing or invalid.

### Frontend push composable

Create `usePushNotifications`.

Responsibilities:

- detect support: `serviceWorker`, `PushManager`, `Notification`;
- read permission;
- fetch VAPID public key;
- subscribe through `registration.pushManager.subscribe`;
- serialize and send subscription to backend;
- unsubscribe current device;
- expose status for Settings/Notifications and dropdown prompt.

Suggested statuses:

- `unsupported`;
- `default`;
- `enabled`;
- `disabled`;
- `denied`;
- `loading`;
- `error`.

### Settings / Notifications page

Route:

```text
GET /settings/notifications
```

Controller can be lightweight Inertia render. The actual browser permission state lives client-side.

UI:

- current device status;
- enable button;
- disable button;
- denied/unsupported explanations;
- iOS install guidance if browser requires installed PWA.

### VAPID command

Command:

```text
php artisan push:vapid-generate
```

Output:

```text
VAPID_PUBLIC_KEY=...
VAPID_PRIVATE_KEY=...
VAPID_SUBJECT=mailto:admin@example.com
```

The command should not write `.env` automatically unless explicitly designed and tested. Printing env lines is safer for self-hosted setup.

### Dependency policy

Composer dependencies are allowed for this feature. Prefer a maintained Web Push / Laravel notification channel package with clear VAPID support, or a low-level Web Push library wrapped behind app services.

NPM dependencies are allowed only if they materially simplify service worker/PWA build. Avoid changing frontend build architecture unless required.

## Verification strategy

Backend:

- feature tests for subscribe/update/unsubscribe;
- feature tests for active chat suppression;
- feature tests for secret encrypted mailbox permissions and no plaintext persistence;
- unit/feature tests for push payload privacy.

Frontend:

- TypeScript type check;
- build;
- manual Application panel check for manifest/service worker;
- browser test or manual run for notification permission states where feasible.

End-to-end manual checks:

1. Install PWA.
2. Enable push.
3. Send DM from another user while recipient app is closed.
4. Confirm OS push opens correct conversation.
5. Open same conversation in recipient browser and send another DM.
6. Confirm OS push is suppressed.
7. Send secret message while recipient is offline.
8. Confirm generic OS push and encrypted message appears after opening chat.
