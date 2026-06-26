# PWA и Push-уведомления: PRD

## Контекст

Padik - самостоятельный realtime-мессенджер на Laravel, Inertia, Vue и Reverb. В приложении уже есть общий чат, личные диалоги, групповые комнаты, приглашения, unread-счетчики, внутрисайтовые уведомления и секретные чаты с end-to-end шифрованием.

Следующий продуктовый шаг - приблизить поведение к настоящему мобильному мессенджеру:

- приложение можно установить как PWA;
- пользователь может включить push-уведомления на устройстве;
- уведомления доставляются даже когда вкладка закрыта;
- секретные чаты получают надежную offline-доставку без хранения plaintext на сервере.

## Цели

1. Сделать Padik installable PWA на поддерживаемых браузерах.
2. Добавить Web Push для важных событий мессенджера.
3. Сохранить приватность уведомлений: по умолчанию не показывать текст сообщений в OS notification.
4. Поддержать несколько устройств одного пользователя.
5. Добавить надежную offline-доставку секретных сообщений через encrypted mailbox.
6. Не усложнять MVP настройками уведомлений сверх device-level enable/disable.

## Не цели первой итерации

- Offline отправка обычных или секретных сообщений.
- Полный offline-first режим для истории чатов.
- Расширенные пользовательские настройки по типам уведомлений.
- Quiet hours / do-not-disturb.
- Cross-device синхронизация локальных секретных ключей.
- Push для каждого сообщения в General и групповых комнатах без явного mention.

## MVP push-события

В первой версии push отправляется для:

- direct message;
- mention в комнате;
- room invitation;
- secret chat invitation;
- secret chat message.

Push не отправляется для:

- собственных сообщений отправителя;
- обычных сообщений в General;
- обычных сообщений в group room без mention;
- пользователя, который сейчас находится в открытом соответствующем чате.

## Политика текста уведомлений

По умолчанию используется приватный режим.

Direct message:

- title: `New direct message`
- body: `{sender_name} sent you a message`
- data/action_url: URL личного диалога

Mention:

- title: `You were mentioned`
- body: `{sender_name} mentioned you in {room_title}`
- data/action_url: URL сообщения или комнаты

Room invitation:

- title: `Room invitation`
- body: `{sender_name} invited you to a room`
- data/action_url: URL комнаты или notification action

Secret chat invitation:

- title: `Secret chat invitation`
- body: `{sender_name} invited you to a secret chat`
- data/action_url: URL секретного чата

Secret chat message:

- title: `New secret message`
- body: `Open Padik to read it`
- data/action_url: URL секретного чата

Секретные push-уведомления никогда не содержат plaintext и не должны содержать ciphertext.

## Secret chats: надежная offline-доставка

Текущее поведение секретных чатов: сообщение broadcastится как ciphertext и не сохраняется в `messages`. Для надежной offline-доставки нужно добавить encrypted mailbox:

- сервер сохраняет только ciphertext, IV, sender fingerprint и служебные delivery-метаданные;
- plaintext на сервере не появляется;
- получатель при открытии чата забирает pending encrypted messages;
- после успешного получения/acknowledge сервер помечает сообщения доставленными или удаляет их;
- push для secret message становится сигналом, что в mailbox есть новое encrypted сообщение.

Acceptance criteria:

- если получатель offline, отправленное secret сообщение доступно после открытия PWA;
- в обычной таблице `messages` secret message не появляется;
- в базе нет plaintext secret message;
- повторное открытие чата не создает дубликаты сообщений на клиенте;
- sender и recipient не получают разные safety/fingerprint semantics относительно текущего E2EE потока.

## PWA UX

Installability:

- приложение имеет manifest с `name`, `short_name`, `id`, `start_url`, `scope`, `display`, `theme_color`, `background_color`, icons 192/512 и maskable icon;
- service worker регистрируется из frontend entrypoint;
- authenticated Inertia pages не кэшируются как offline-first;
- assets/build/fonts могут кэшироваться безопасно;
- при offline состоянии приложение показывает понятную ошибку/fallback, но не обещает offline messaging.

iOS:

- на iOS Safari показывать подсказку установки через Add to Home Screen, если приложение не запущено standalone;
- push permission запрашивать только из установленного Home Screen web app или в supported context;
- текст подсказки должен объяснять, что уведомления на iOS доступны после установки.

## Notification UX

Добавить страницу или раздел `Settings / Notifications`.

MVP controls:

- `Enable push notifications`;
- `Disable push notifications`;
- status: enabled, disabled, denied, unsupported, not installed where relevant;
- список текущего устройства необязателен для MVP.

Дополнительно:

- маленький prompt в notification dropdown, если push supported и permission находится в `default`;
- permission request запускается только по явному действию пользователя;
- если permission `denied`, UI объясняет, что нужно включить разрешение в настройках браузера/ОС.

## Multi-device behavior

- у одного пользователя может быть несколько `PushSubscription`;
- push отправляется на все активные подписки пользователя;
- если push service возвращает permanent failure для endpoint, subscription удаляется;
- disable на одном устройстве удаляет только subscription этого устройства.

## Активный чат и suppression

Чтобы не отправлять OS push пользователю, который уже находится в открытом соответствующем чате:

- клиент сообщает серверу о текущем активном conversation;
- сервер хранит короткоживущий presence/activity state;
- перед отправкой push проверяется, есть ли у recipient активная session в этом conversation;
- realtime Echo event и in-app notification могут продолжать работать как сейчас.

MVP suppression должен быть простым и устойчивым к сбоям: если presence state отсутствует или истек, push отправляется.

## Очереди

Push отправляется через queue/job, не синхронно в request отправки сообщения.

Ожидаемое поведение:

- request создания сообщения не ждет сетевого вызова к push service;
- job обрабатывает несколько устройств получателя;
- permanent failures чистят subscription;
- transient failures ретраятся стандартной очередью Laravel.

## Метрики успеха

- Lighthouse/Application panel показывает installable PWA.
- На Android установленное PWA получает push при закрытом браузере.
- На iOS установленное Home Screen app получает push в поддерживаемом окружении.
- Direct message push не содержит plaintext тела сообщения.
- Secret message offline scenario проходит feature/browser тестом на уровне encrypted mailbox.
- Если recipient открыт в том же чате, OS push не отправляется.

## Открытые вопросы не для MVP

- Нужны ли per-room mute settings?
- Нужно ли показывать previews опционально?
- Нужно ли хранить историю доставленных encrypted secret messages дольше, чем до ack?
- Нужно ли показывать список зарегистрированных устройств в настройках?
- Нужно ли делать badge count через Badging API на поддерживаемых браузерах?
