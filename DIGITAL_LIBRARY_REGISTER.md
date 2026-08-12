# Artefak Digital Library — Feature Register

> Официален каталог на функциите за **Artefak дигиталната библиотека** —
> самостоятелна платформа, отделна от phygital ArteUction auction модула.
> Всяка функция е класифицирана като **MVP**, **M2** или **Rejected**.
>
> Референция: CodeCanyon `ebook-laravel-cms-script` (Laravel 9, нWidart Modules,
> Cartalyst Sentinel auth, без Stripe SDK, без покупки). ZIP-ът е прочетен и
> анализиран — виж [Реален код от скрипта](#реален-код-от-скрипта).
>
> Кодът не се пренася директно — виж [Защо не copy-paste](#защо-не-copy-paste).

---

## Класификация

### MVP — Задължителни за първи launch

#### Каталог и метаданни

| # | Функция | Stock таблица/поле | Artefak подход |
|---|---------|-------------------|----------------|
| 1 | Каталог — списък, grid, pagination | `ebooks` | `books` |
| 2 | Заглавие, кратко и пълно описание | `ebook_translations` (i18n) | `books` (без i18n в MVP) |
| 3 | ISBN, издател, издание, брой стр., език, произход | добавени в 3 отделни миграции | едина миграция |
| 4 | Ключови думи / тагове | `key_word` — plain text поле | `book_tags` pivot |
| 5 | Целева аудитория | `target_reader` text поле | `books.target_audience` |
| 6 | Корица (cover image) | `entity_files` polymorphic zone `book_cover` | S3/local, `books.cover_path` |
| 7 | Категории (дърво, slug, описание) | `categories` + `category_translations` | `book_categories` без i18n в MVP |
| 8 | Множество автори на книга | `ebook_authors` pivot | `book_authors` pivot |
| 9 | Автор профил (bio, slug, is_verified) | `authors` таблица, отделен модул | `author_profiles` (аналог на `artist_profiles`) |
| 10 | `is_featured`, `is_active`, soft delete | `ebooks.is_featured`, `is_active`, `deleted_at` | идентично |
| 11 | Публикационна година | `ebooks.publication_year` | `books.publication_year` |
| 12 | Статус: чернова / изчакващ одобрение / публикуван | `ebooks.is_active` (bool) | `books.status` enum — по-изразителен |

#### Формати и четене

| # | Функция | Stock реализация | Artefak подход |
|---|---------|-----------------|----------------|
| 13 | PDF upload + PDF.js inline viewer | `entity_files zone=book_file`, `EbookController::pdfviewer()` | `book_files` таблица, signed URL, PDF.js |
| 14 | EPUB upload + inline reader | `EbookController::epubReader()`, отделен view | `book_files`, EPUB.js |
| 15 | Аудио preview (кратък AI-генериран откъс) | `entity_files zone=audio_book_files`, множество файлове | `book_audio_previews` — само TTS preview в MVP |
| 16 | Ограничен preview (N стр.) за непокупнали | **липсва** — всеки вижда всичко | `books.preview_pages` + middleware |
| 17 | Пълен достъп само след покупка | **липсва** — download е публичен | signed temp URL + access middleware |
| 18 | Поддържани разширения в MVP | pdf, epub, mp3/wav | pdf, epub; audio preview само AI-генериран |

#### Покупки и достъп

| # | Функция | Stock реализация | Artefak подход |
|---|---------|-----------------|----------------|
| 19 | Stripe Checkout — еднократна покупка | **липсва** — `price` е text, `buy_url` е external link | Stripe Checkout + verified webhook |
| 20 | `user_ebook_purchases` | **липсва** | `book_purchases` с PI id, amount, currency |
| 21 | Безплатни книги с записан достъп | **липсва** | `book_purchases` с `amount=0` |
| 22 | Access check при всяко зареждане | **липсва** — `download()` проверява само slug/fileID | middleware `EnsureBookAccess` |
| 23 | Signed temporary download URL | **липсва** — `copy($path, $temp)` без auth | `Storage::temporaryUrl()`, изтича след 15 мин |
| 24 | Purchase history за читателя | **липсва** | `GET /account/purchases` |

#### Автори — приходи и dashboard

| # | Функция | Stock реализация | Artefak подход |
|---|---------|-----------------|----------------|
| 25 | Author sales dashboard | **липсва** | продажби, приходи, изплатено |
| 26 | Payout history | **липсва** | статус, дата, сума |
| 27 | Лицензен договор с версии | **липсва** | приема при upload, версионирано |
| 28 | Takedown request + доказване на права | **липсва** | форма + admin queue |
| 29 | Daily upload limit per author | `setting('daily_ebook_upload_limit')` | конфигурируемо в admin settings |
| 30 | Author application + admin approval | `authors.is_verified` (bool) | `author_applications` (аналог на `artist_applications`) |

#### Финансов слой

| # | Функция | Бележка |
|---|---------|---------|
| 31 | Revenue split | **⚠ Продуктово решение изисква се** — виж [Финансов модел](#финансов-модел) |
| 32 | Refund flow — пълен и частичен | Свързан с `book_purchases` |
| 33 | Ledger credit/debit на всяка транзакция | Immutable audit trail |
| 34 | Chargeback handling | `payment_intent.payment_failed` webhook + ledger debit |

#### Търсене и навигация

| # | Функция | Stock реализация | Artefak подход |
|---|---------|-----------------|----------------|
| 35 | Full-text search | `$model->search(request('query'))` — Scout/Algolia | Scout + Meilisearch или DB FULLTEXT |
| 36 | Филтри — категория, език, формат, цена | `EbookFilter` клас | идентичен подход |
| 37 | Сортиране — нови, популярни, цена | cookie-based sort option | query param |
| 38 | Related books (по категория/автор) | `whereHas('categories', ...)`, limit 10 | идентично |
| 39 | Popular books (viewed counter) | `ebooks.viewed` integer | `books.view_count` |

#### Потребители и роли

| # | Функция | Stock реализация | Artefak подход |
|---|---------|-----------------|----------------|
| 40 | Регистрация / login — email + парола | Cartalyst Sentinel (отделен пакет) | Laravel Sanctum, Bcrypt |
| 41 | Google OAuth | обсъждан | Socialite |
| 42 | Роли: `reader | author | admin` | Sentinel roles таблица | enum на `users.role` (без пакет) |
| 43 | Email при покупка (buyer) | **липсва** | Mailable |
| 44 | Email при продажба (author) | **липсва** | Mailable |

#### Admin модерация

| # | Функция | Stock реализация | Artefak подход |
|---|---------|-----------------|----------------|
| 45 | Опашка за нови книги — approve / reject | `is_active` toggle, `ReportedEbookController` | статус enum + admin queue |
| 46 | Опашка за автори | `is_verified` bool | `author_applications` + review flow |
| 47 | Immutable admin audit log | `activity_log` (Spatie) | `admin_audit_log` (собствен, вече изграден) |
| 48 | Auto-approve за верифицирани автори | `setting('auto_approve_ebook')` | конфигурируемо |
| 49 | Reported books queue | `reported_ebooks` таблица | включено |

#### SEO

| # | Функция | Stock реализация | Artefak подход |
|---|---------|-----------------|----------------|
| 50 | OpenGraph + Schema.org (Book, Person) | `meta_data` модул | `<meta>` в blade, JSON-LD |
| 51 | Canonical URL, robots meta | `Meta` модул | blade компонент |
| 52 | XML Sitemap | `SitemapController` | `spatie/laravel-sitemap` или custom |
| 53 | Slugs | `ebooks.slug unique` | идентично |

#### AI Audio Preview

| # | Функция | Бележка |
|---|---------|---------|
| 54 | AI генериран аудио preview (TTS на откъс) | Stock не го има — наш differentiator |
| 55 | Regeneration при промяна на текста | admin action |

---

### M2 — След стабилен MVP

#### Общност и ангажираност

| # | Функция | Stock таблица | Приоритет |
|---|---------|---------------|-----------|
| 56 | Любими книги | `favorite_lists` (composite PK: user_id, ebook_id) | Висок |
| 57 | Ревюта и оценки (1–5) | `reviews` (reviewer_id, ebook_id, rating, comment, is_approved) | Висок |
| 58 | Admin moderation на ревюта | `ReviewController` admin | Висок |
| 59 | Коментари — native | `laravelista/comments` пакет | Среден |
| 60 | Сигнал / report за книга | `reported_ebooks` таблица | Среден |
| 61 | Browsing history + "Продължи четенето" | `ebook_views` таблица | Среден |
| 62 | "Препоръчано за теб" | **липсва** в stock | Нисък |

#### Допълнителни формати

| # | Функция | Stock реализация | Бележка |
|---|---------|-----------------|---------|
| 63 | DOC/DOCX/PPT/XLS viewer | Google Viewer embed (`gview`) | само за покупнали |
| 64 | Пълна аудиокнига — много глави | `zone=audio_book_files`, множество записи | `audiobook_chapters` модел |
| 65 | External URL embed | `file_type=external_link` → `copy($url, $temp)` | изисква SSRF allowlist |
| 66 | Google Drive / YouTube embed | `file_type=embed_code` | валидация на embed source |

#### Интернационализация

| # | Функция | Stock реализация | Бележка |
|---|---------|-----------------|---------|
| 67 | Многоезичен интерфейс | `ebook_translations`, `Translation` модул | i18n файлове |
| 68 | RTL поддръжка | CSS | logical properties |
| 69 | Translation editor в admin | `TranslationController` | |

#### Маркетинг и растеж

| # | Функция | Stock реализация | Бележка |
|---|---------|-----------------|---------|
| 70 | Newsletter интеграция | `Newsletter` модул, `subscribers` | Mailchimp / Resend |
| 71 | QR споделяне на книга | **липсва** | |
| 72 | Advertisement blocks | **липсва** | само ако бизнес моделът го изисква |
| 73 | Facebook OAuth | **липсва** | Socialite |

#### CMS и съдържание

| # | Функция | Stock реализация | Бележка |
|---|---------|-----------------|---------|
| 74 | Статични CMS страници | `Page` модул, `pages` таблица | |
| 75 | Homepage slider / featured | `Slider` модул, `slider_slides` | |
| 76 | Разширени навигационни менюта | `Menu` модул, 4 таблици | |
| 77 | Import / export (CSV/JSON) | `Import` модул (деактивиран в stock) | admin-only |

---

### Rejected — Не се имплементира

| Функция | Stock код | Причина |
|---------|-----------|---------|
| Password-protected книги | `ebooks.password` plaintext; `Crypt::encryptString` само в session | Паролата се пази plain text в DB. При нашия access control (purchase / role) допълнителна парола е излишна. |
| Директен download без ownership check | `download($slug, $fileID)` — проверява само `slug` и `fileID`, не дали файлът принадлежи на тази книга | Всеки с валиден fileID може да изтегли чуждо съдържание. |
| Stock `price` (text) и `buy_url` | `ebooks.price text`, `ebooks.buy_url text` | Не са числа, не са верифицирани. Заменени изцяло от Stripe Checkout. |
| PDF/EPUB route без purchase gate | `pdfviewer($slug)` и `epubReader($slug)` нямат auth/purchase check | Bypass на access control. |
| External URL fetch без SSRF guard | `file_get_contents($ebook->file_url)` в `pdfviewer()` | SSRF. Ако external embed влезе в M2, ще е с allowlist. |
| `GET /ebooks/{slug}/delete` (mutating) | `destroy($slug)` се извиква от GET route | Mutable GET — accidental delete от crawler. Заменено с `DELETE`. |
| Cartalyst Sentinel auth | `migration_cartalyst_sentinel.php` — 6+ таблици | Несъвместим с Laravel 13. Заменен от Sanctum. |
| `dev-master` зависимости | `composer.json` на скрипта | Неконтролируеми updates. |
| `.env` с реални данни | `eBook/.env` в ZIP | Никога в git. |

---

## Финансов модел

> **Блокиращо продуктово решение — трябва да се вземе преди имплементация на т. 31–34.**

| Модел | Автор | Фонд | Операции | Подходящ когато |
|-------|-------|------|----------|-----------------|
| **90 / 10** | 90 % | — | 10 % | Чист marketplace, без благотворителна цел |
| **45 / 45 / 10** | 45 % | 45 % | 10 % | ArteUction модел — висок социален компонент |
| **75 / 15 / 10** | 75 % | 15 % | 10 % | Баланс — атрактивен за автори, поддържа фонда |
| **80 / 10 / 10** | 80 % | 10 % | 10 % | По-близо до стандартен marketplace |

**Правило (при всеки модел):** split профилът се замразява при момента на продажбата.
Промяна в платформения split не засяга минали транзакции.

---

## Реален код от скрипта

Ключови находки при четене на миграциите и контролерите:

### `ebooks` таблица (4 миграции, добавяни поетапно)

```php
// 2019 — core
$table->string('password')->nullable();     // PLAINTEXT — Rejected
$table->boolean('is_private');
$table->boolean('is_active');               // само bool, без enum статус

// 2020 — добавени post-launch
$table->text('price')->nullable();          // текстово поле, не число — Rejected
$table->text('buy_url')->nullable();        // external link — Rejected
$table->text('file_url')->nullable();       // external file URL — SSRF риск
$table->text('embed_code')->nullable();     // без sanitization — XSS риск

// 2021 — метаданни
$table->string('book_edition')->nullable();
$table->string('number_of_pages')->nullable();
$table->string('book_language')->nullable();
$table->string('country_origin')->nullable();
```

### `authors` таблица

```php
$table->boolean('is_active');
$table->boolean('is_verified');   // само bool — без review history, без note
```
Нашият `author_profiles` е значително по-богат: status enum, reviewed_by, reviewed_at,
review_note, terms_accepted_at, version history.

### `favorite_lists` таблица

```php
$table->primary(['user_id', 'ebook_id']);   // composite PK — правилен подход
```
Ще ползваме същия модел в M2.

### `reviews` таблица

```php
$table->integer('rating');
$table->string('reviewer_name');    // не е FK към users — анонимни ревюта позволени
$table->boolean('is_approved');
```

### `ebook_downloads` таблица

```php
// Само user_id + ebook_id + timestamps — без file_id, без access check
// Не доказва, че потребителят има право да изтегли
```

### Уязвим download маршрут

```php
// EbookController::download($slug, $fileID)
// Проверява само дали fileID съществува в Files — не дали принадлежи на тази книга
// Не проверява дали потребителят е платил
$files = Files::where('id', $id)->firstOrFail();   // ← достатъчно е да познаеш ID
copy($path, $temp);
return response()->download($temp, $files->filename, $headers);
```

### SSRF в PDF viewer

```php
// EbookController::pdfviewer($slug)
$b64Doc = base64_encode(file_get_contents($fileURL));  // ← $fileURL е от DB, не валидиран
```

---

## Защо не copy-paste

| Проблем | Риск | Наш подход |
|---------|------|------------|
| Plaintext password в DB | Директна уязвимост | Rejected |
| Download без ownership check | Всеки с fileID изтегля чуждо съдържание | `EnsureBookAccess` middleware + signed URL |
| PDF viewer без purchase gate | Bypass на paywall | Access check преди render |
| `file_get_contents($external_url)` | SSRF | External embed само с SSRF allowlist (M2) |
| `embed_code` без sanitization | XSS | Rejected в MVP |
| `GET /ebooks/{slug}/delete` | CSRF, crawler delete | `DELETE` маршрут |
| Cartalyst Sentinel | Несъвместим с Laravel 13 | Sanctum |
| `dev-master` зависимости | Нестабилни updates | фиксирани версии |
| `price` като text | Невъзможно за финансови изчисления | `price_cents integer` |
| `.env` с реални ключове в ZIP | Credential leak | само `.env.example` в git |
| Само примерни PHPUnit тестове | Нулево функционално покритие | пълен test suite |
| Laravel 9 | EOL | Laravel 13 |

---

## Следващи стъпки (в ред)

1. **Реши финансовия модел** (split ratio) — блокира т. 31–34.
2. **Потвърди EPUB за MVP** или премести в M2 (т. 14).
3. **Реши за external URL embed** — изисква SSRF allowlist (M2 риск, т. 65).
4. **Потвърди пълни аудиокниги** — M2 или по-нататък (т. 64).
5. След тези решения → имплементация на `books`, `book_authors`, `book_files`,
   `book_purchases`, `author_profiles`, `author_applications` миграции и domain actions.
