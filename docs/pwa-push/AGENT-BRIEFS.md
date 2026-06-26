# PWA и Push-уведомления: брифы для агентов

## Перед стартом любого агента

Общий контекст:

- проект: Laravel 13, Inertia Vue 3, Reverb, Wayfinder, Tailwind 4;
- текущий messenger уже имеет database/broadcast notifications и realtime room channels;
- документы-источники: `docs/pwa-push/PRD.md`, `docs/pwa-push/ARCHITECTURE.md`, `docs/pwa-push/TASKS.md`;
- реализация должна соблюдать AGENTS.md и Laravel Boost guidelines;
- перед изменениями Laravel/Inertia/Vite использовать `search-docs`;
- не делать unrelated refactors.

## Agent A: PWA shell / installability

Prompt:

```text
Ты работаешь в /Volumes/Code/WM/Padik. Реализуй только поток A из docs/pwa-push/TASKS.md: PWA shell/installability.

Прочитай docs/pwa-push/PRD.md, ARCHITECTURE.md и TASKS.md. Не трогай backend push subscriptions, notification domain integration, Settings UI и encrypted mailbox.

Нужно добавить manifest, PWA icons, meta/link tags, service worker registration, safe asset/offline caching, push и notificationclick handlers по canonical payload contract.

Особое внимание: не кэшируй authenticated Inertia pages как offline-first и не ломай dev workflow. Используй существующие frontend conventions. После изменений запусти релевантные проверки, минимум npm run build; если менял TypeScript, запусти npm run types:check.

В финале дай список файлов, проверок и оставшихся рисков.
```

Expected ownership:

- `public/manifest.webmanifest`;
- `public/*pwa*icon*`;
- `resources/views/app.blade.php`;
- `resources/js/app.ts`;
- service worker file/module.

## Agent B: Web Push backend infrastructure

Prompt:

```text
Ты работаешь в /Volumes/Code/WM/Padik. Реализуй только поток B из docs/pwa-push/TASKS.md: Web Push backend infrastructure.

Прочитай docs/pwa-push/PRD.md, ARCHITECTURE.md и TASKS.md. Не трогай PWA manifest/service worker UI, notification domain integration, active chat UX и encrypted mailbox.

Нужно добавить composer dependency для Web Push, VAPID config, push_subscriptions migration/model, auth routes/controllers для public key/subscribe/unsubscribe, push sending service/job, cleanup invalid endpoints и artisan command push:vapid-generate.

Следуй Laravel conventions. Используй php artisan make:* где уместно. Добавь feature/unit tests для subscription lifecycle и command/service behavior. После route changes обнови Wayfinder, если проект этого требует. Запусти Pint и focused tests.

В финале дай список файлов, проверок и точный контракт, который должен использовать frontend.
```

Expected ownership:

- `composer.json` / `composer.lock`;
- `config/services.php` или отдельный config;
- migration/model/controller/request/job/service/command;
- routes;
- backend tests;
- generated Wayfinder files if routes changed and generation is needed.

## Agent C: Notification integration + active chat suppression

Prompt:

```text
Ты работаешь в /Volumes/Code/WM/Padik. Реализуй только поток C из docs/pwa-push/TASKS.md: notification domain integration и active chat suppression.

Прочитай docs/pwa-push/PRD.md, ARCHITECTURE.md и TASKS.md. Не реализуй subscription persistence, service worker rendering, Settings UI или encrypted mailbox storage, кроме минимальной интеграции с уже существующими контрактами.

Нужно подключить existing messenger notifications/events к private Web Push payloads: DM, mention, room invitation, secret chat invitation, secret message generic payload после появления delivery source. Push не должен содержать plaintext message body. Push не должен отправляться sender и recipient, который сейчас находится в открытом conversation.

Добавь backend presence service/route/controller для active conversation heartbeat, если он еще не реализован. Добавь tests: payload privacy, queued push, no push for active conversation, push when presence missing/expired.

Сохрани существующие database/broadcast notifications. После PHP изменений запусти Pint и focused tests.

В финале дай список файлов, проверок и все assumptions по интеграции с Agent B/D/E.
```

Expected ownership:

- notification classes or notification payload mappers;
- push dispatch integration;
- active conversation presence backend;
- tests around push decisions.

## Agent D: Frontend notification UX + presence heartbeat

Prompt:

```text
Ты работаешь в /Volumes/Code/WM/Padik. Реализуй только поток D из docs/pwa-push/TASKS.md: frontend notification UX и active conversation heartbeat.

Прочитай docs/pwa-push/PRD.md, ARCHITECTURE.md и TASKS.md. Используй Inertia Vue skill и Wayfinder conventions. Не реализуй backend subscription internals, Web Push sender, manifest/icons или encrypted mailbox.

Нужно добавить Settings/Notifications page, usePushNotifications composable, enable/disable push на текущем устройстве, browser permission states, prompt в notifications dropdown при supported/default, iOS install guidance, active conversation heartbeat while visible.

Permission request должен происходить только по пользовательскому клику. UI должен различать unsupported/default/enabled/disabled/denied/loading/error. Используй backend route contracts из ARCHITECTURE.md и сгенерированные Wayfinder routes, если они доступны.

После изменений запусти npm run types:check и npm run build, если возможно.

В финале дай список файлов, проверок и состояния, которые покрыты UI.
```

Expected ownership:

- `resources/js/composables/usePushNotifications.ts`;
- Settings/Notifications Vue page;
- layout dropdown prompt;
- active conversation heartbeat frontend;
- route imports/Wayfinder usage.

## Agent E: Secret chat encrypted mailbox

Prompt:

```text
Ты работаешь в /Volumes/Code/WM/Padik. Реализуй поток E из docs/pwa-push/TASKS.md: reliable offline delivery for secret chats via encrypted mailbox.

Прочитай docs/pwa-push/PRD.md, ARCHITECTURE.md и TASKS.md. Сохрани главный security invariant: сервер никогда не хранит plaintext secret message и OS push никогда не содержит plaintext/ciphertext.

Нужно добавить secret encrypted delivery storage, pending deliveries fetch endpoint, ack endpoint, обновить SecretChatMessageController так, чтобы он создавал encrypted delivery для recipient, сохранить realtime broadcast для online UX, добавить frontend fetch/decrypt/ack pending deliveries при открытии secret chat, de-duplicate by id, и generic secret push trigger через существующий push service when available.

Добавь tests: no rows in messages for secret chat, ciphertext-only persistence, recipient can fetch own deliveries, sender cannot fetch recipient deliveries, ack prevents repeat display/server repeat, duplicate-safe client behavior where feasible.

После route changes обнови Wayfinder, если нужно. Запусти Pint, focused PHP tests, TypeScript checks/build если менял frontend.

В финале дай список файлов, проверок и любые ограничения по E2EE/offline semantics.
```

Expected ownership:

- secret delivery migration/model/controller/request/resource;
- `SecretChatMessageController`;
- `useSecretChat` / `Conversation.vue` pending delivery integration;
- tests for encrypted mailbox.

## Integration Lead checklist

Use this after agents finish:

```text
Ты integration lead для PWA/push фичи. Прочитай docs/pwa-push/*.md и все agent summaries. Проверь overlap/conflicts между потоками A-E.

Сделай общий pass:
- routes and Wayfinder consistency;
- privacy of all push payloads;
- no aggressive caching of auth Inertia pages;
- secret messages never stored as plaintext;
- active chat suppression works with frontend heartbeat;
- multiple device subscriptions work;
- invalid subscriptions are cleaned;
- Settings/Notifications UX states are coherent;
- build/types/tests pass.

Не делай broad refactors. Исправь только integration issues. В финале дай concise summary, tests run, and residual risks.
```
