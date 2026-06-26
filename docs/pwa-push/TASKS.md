# PWA и Push-уведомления: задачи для параллельной работы

## Рабочая модель

Фичу можно делать четырьмя параллельными потоками. Каждый поток должен держаться своего scope и не забирать задачи соседнего агента без явного согласования.

Общие правила:

- перед backend/frontend изменениями использовать Laravel Boost `search-docs` для релевантных Laravel/Inertia/Vite тем;
- следовать существующим паттернам проекта;
- добавлять тесты к каждому behavioral change;
- не менять unrelated код;
- после изменения routes запускать Wayfinder generation, если это требуется проектом;
- после PHP изменений запускать Pint;
- после frontend изменений запускать type/build checks по возможности.

## Поток A: PWA shell / installability

Цель: приложение можно установить как PWA, service worker безопасно обслуживает shell/assets и умеет принимать push/click payloads.

Scope:

- `public/manifest.webmanifest`;
- PWA icons 192/512/maskable;
- meta/link tags в `resources/views/app.blade.php`;
- service worker file;
- registration в `resources/js/app.ts` или отдельном модуле;
- asset/offline caching strategy;
- `push` и `notificationclick` handlers в service worker;
- базовая iOS standalone/install detection helper, если это не пересекается с UI потоком.

Не входит:

- backend push subscriptions;
- Settings/Notifications UI;
- actual push sending;
- encrypted mailbox.

Acceptance criteria:

- manifest валиден и подключен;
- service worker регистрируется в production-like build;
- authenticated Inertia responses не кэшируются как offline-first;
- push event может показать notification из canonical payload;
- notification click открывает `action_url`;
- `npm run build` проходит.

Риски:

- агрессивное кэширование auth HTML может показывать устаревшие/чужие данные;
- Vite dev service worker может мешать разработке, поэтому registration должен быть аккуратным.

## Поток B: Web Push backend infrastructure

Цель: сервер хранит подписки устройств, имеет VAPID config/command и умеет отправлять payload на все устройства пользователя через queue.

Scope:

- composer dependency for Web Push;
- config/env keys for VAPID;
- `push_subscriptions` migration;
- `PushSubscription` model/factory if useful;
- form request/controller/routes for key, subscribe, unsubscribe;
- service for serializing/sending push;
- queue job for Web Push delivery;
- cleanup invalid endpoints;
- `push:vapid-generate` artisan command;
- tests for subscription lifecycle and VAPID command behavior.

Не входит:

- deciding which messenger events push;
- frontend permission UX;
- service worker display behavior beyond agreed payload contract;
- active conversation presence unless split out from C/D.

Acceptance criteria:

- authenticated user can store/update subscription by endpoint;
- deleting subscription removes only current endpoint;
- multiple subscriptions per user are supported;
- invalid endpoint cleanup is covered by tests or service abstraction test;
- VAPID public key endpoint returns configured public key;
- command prints env-ready keys;
- feature tests pass.

Dependencies:

- may proceed independently after route names/contract are agreed in `ARCHITECTURE.md`.

## Поток C: Notification domain integration + active chat suppression

Цель: существующие messenger events/notifications создают private push payloads, а OS push не отправляется пользователю, который уже открыт в соответствующем чате.

Scope:

- map existing notifications to canonical private push payloads;
- direct message push;
- mention push;
- room invitation push;
- secret chat invitation push;
- secret message push trigger, после появления encrypted mailbox contract;
- active conversation presence backend service;
- route/controller for presence heartbeat if not done by another stream;
- tests for "recipient in active conversation => no OS push";
- tests for "recipient not active => queued push";
- privacy tests: body does not include message plaintext.

Не входит:

- subscription persistence internals;
- frontend permission UI;
- service worker rendering;
- encrypted mailbox storage implementation if assigned to D/E.

Acceptance criteria:

- DM push payload is private and queued;
- mention push payload is private and queued;
- invitation push payload is private and queued;
- no push to sender;
- no OS push when recipient active in same conversation;
- push still sends when presence expired/missing;
- existing in-app database/broadcast notifications continue working.

Dependencies:

- uses B's push sender service/job interface;
- coordinates with D for presence heartbeat payload.

## Поток D: Frontend notification UX + presence heartbeat

Цель: пользователь может включить/выключить push на устройстве, видеть статус, а клиент сообщает серверу активный чат для suppression.

Scope:

- `usePushNotifications` composable;
- Settings/Notifications page;
- route/controller/Inertia shell for settings page if needed;
- prompt in notification dropdown when supported and permission is `default`;
- browser permission handling;
- subscribe/unsubscribe current device through backend routes;
- iOS install guidance;
- active conversation heartbeat while chat is visible;
- cleanup heartbeat on route leave/unmount/visibility hidden.

Не входит:

- backend subscription storage internals;
- actual Web Push delivery;
- PWA icon/manifest work unless needed for UI detection;
- encrypted mailbox backend.

Acceptance criteria:

- unsupported/denied/default/enabled/disabled states are distinct;
- permission request happens only from user action;
- enable stores subscription on backend;
- disable unsubscribes browser subscription and deletes backend endpoint;
- dropdown prompt is not shown when denied/unsupported/enabled;
- active conversation heartbeat sends `conversation_id` and refreshes periodically;
- type check/build pass.

Dependencies:

- uses B routes for subscription;
- uses C route for presence heartbeat.

## Поток E: Secret chat encrypted mailbox

Цель: секретные сообщения надежно доставляются offline без хранения plaintext.

Этот поток можно выделить отдельно, потому что он больше и рисковее, чем обычный Web Push.

Scope:

- migration/model for encrypted secret deliveries;
- route/controller for pending deliveries fetch and ack;
- adjust `SecretChatMessageController` to store encrypted deliveries per recipient;
- keep realtime broadcast for online recipients;
- frontend fetch pending deliveries on secret chat open;
- decrypt pending deliveries through existing `useSecretChat`;
- ack successfully processed deliveries;
- de-duplicate by delivery/message id;
- generic secret push trigger for pending delivery;
- tests for permissions, no plaintext persistence, ack and duplicates.

Не входит:

- changing cryptographic algorithm unless necessary;
- storing plaintext;
- offline sending;
- cross-device secret key sync.

Acceptance criteria:

- offline recipient receives pending encrypted secret message after opening chat;
- server stores ciphertext/iv only;
- recipient can fetch only own pending deliveries;
- sender cannot fetch recipient deliveries;
- ack removes or marks delivery so it is not repeatedly shown;
- client de-duplicates if ack failed and fetch repeats;
- push payload for secret message is generic.

Dependencies:

- uses C/B push service for generic secret push;
- coordinates with D for frontend secret chat open lifecycle.

## Suggested merge order

1. A: PWA shell can merge early.
2. B: backend push subscriptions and VAPID.
3. D partial: frontend enable/disable UI once B routes exist.
4. C: notification integration and suppression once B service exists and D heartbeat contract is stable.
5. E: encrypted mailbox, or run in parallel but merge after careful review because it changes secret chat delivery semantics.
6. Final integration pass: routes, Wayfinder, build, focused tests, manual mobile/PWA checks.

## Cross-stream contracts

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

Subscription request:

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

Presence heartbeat:

```json
{
  "conversation_id": 123
}
```

Secret pending deliveries response:

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

## Final verification checklist

- `php artisan test --compact` or focused feature tests for changed areas.
- `vendor/bin/pint --dirty --format agent` after PHP edits.
- `npm run types:check` after TS/Vue edits.
- `npm run build` after PWA/service worker/frontend edits.
- Manual installability check in browser Application panel.
- Manual push check on at least one desktop Chromium browser.
- Manual mobile check for install prompt/standalone behavior.
