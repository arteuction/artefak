# ADR-001 — Nx Monorepo като целева архитектура

**Статус:** Прието  
**Дата:** 2026-11-11  
**Автор:** metacosmicactus  
**Заменя частично:** `docs/ai/GITHUB_REPOSITORY_CONSOLIDATION.md` — инструментът за workspace (там: pnpm workspaces + опционален Turborepo) става **Nx**. Останалото в него остава в сила: archive-not-delete, без пренаписване на история, Laravel → `legacy/laravel/` при Stage 4 (не `archive/`), "не обединявай други репозитории преди да са ясни границите".  

---

## Контекст

ARTeuCtion екосистемата се състои от четири приложения, споделящи обща бизнес логика и API:

| Приложение | Технология | Текущо местоположение |
|---|---|---|
| NestJS API | TypeScript / NestJS 12 | `apps/api/` |
| euction (аукционна платформа) | Vite + React | предстои |
| artefak.eu (арт marketplace) | Next.js (или Vite + React) | отделен repo / AI Studio build |
| ArtMetro | Kotlin / Gradle (Android) | предстои |

Допълнително: Laravel PHP backend е активен и остава migration authority по време на NestJS transition.

**Проблем:** Без обща monorepo структура не може да се:
- управлява споделен API contract между frontend и backend
- пуска само засегнатите тестове при промяна в един компонент
- разделя deploy permissions за различните приложения в единна CI/CD среда

---

## Решение

**Nx monorepo** — постепенна интеграция, без начално преместване на приложенията.

### Целева структура

```
/
├── apps/
│   ├── api/                 # NestJS API (вече е тук)
│   ├── web-auction/         # euction — Vite + React
│   ├── web-artefak/         # artefak.eu — Next.js
│   └── android/             # ArtMetro — Kotlin/Gradle
│
├── packages/
│   ├── api-contracts/       # Публични DTO, Zod схеми, API типове
│   ├── ui-web/              # Споделени React компоненти
│   └── config/              # Споделени tsconfig, ESLint конфигурации
│
├── nx.json
├── package.json
└── ...
```

Това е **целевата** структура — не инструкция за незабавно преместване.

---

## Защо Nx, а не Turborepo

| Критерий | Nx | Turborepo |
|---|---|---|
| NestJS / Node.js | ✓ | ✓ |
| React / Vite | ✓ | ✓ |
| Java / Gradle | `@nx/gradle` plugin | основно чрез custom tasks |
| Смесен стек (JS + Android) | по-подходящ | фокус върху JS/TS |
| Project graph | ✓ affected builds | ✓ task graph |

Решаващ фактор: `@nx/gradle` интеграцията позволява Android да участва в `nx affected` без да се преписва като JavaScript проект.  
Изисква: Java 17+, Gradle 8+. Версиите трябва да се верифицират преди стъпка 4.

---

## Архитектурни ограничения (задължителни)

1. **`api-contracts`** съдържа само публични DTO/API типове и Zod валидатори. Не съдържа Prisma модели, database entities или вътрешна backend логика.

2. **`ui-web`** е само за React web приложенията. Android запазва native Kotlin архитектура — не се конвертира в JS.

3. **Laravel** остава непокътнат докато NestJS migration и cutover не са независимо валидирани (вж. `docs/ai/HARD_BLOCKER_STATUS.md`).

4. **Monorepo ≠ общ deployment.** CI/CD pipelines, secrets, approvals и deployment permissions остават разделени по приложение.

---

## План за внедряване

### Фаза 1 — Read-only audit
Установяване на точната структура, branch стратегия, package manager, Gradle конфигурация, CI/CD workflows, migration и deployment процеси.  
**Блокер:** Без тази информация следващите стъпки не могат да се изпълнят правилно.

### Фаза 2 — Nx foundation
```bash
npx nx@latest init
```
Добавя `nx.json` и `package.json` scripts към root без да мести файлове.  
Съществуващите приложения продължават да работят непроменени.

### Фаза 3 — Project registration
Регистрираме `apps/api`, euction и artefak.eu с реалните им `build`, `test`, `lint` задачи в `project.json` файлове.  
`nx affected --target=test` започва да работи за TypeScript проектите.

### Фаза 4 — Android / Gradle интеграция
```bash
npm install --save-dev @nx/gradle
```
Интегрираме Gradle задачите в Nx project graph. Android build архитектурата не се променя.  
**Предпоставка:** Java 17+ и Gradle 8+ верифицирани.

### Фаза 5 — Shared packages
`api-contracts` се създава само когато има реално повторно използване на типове между frontend и backend.  
`ui-web` се създава само когато euction и artefak.eu споделят компоненти.  
Не се създават пакети превантивно.

### Фаза 6 — CI/CD
- `nx affected` заменя full build в PR проверките
- Remote caching (Nx Cloud или self-hosted) за по-бързи CI runs
- Отделни deployment workflows с разделени secrets и approvals по приложение

---

## Отхвърлени алтернативи

**Turborepo** — отхвърлен поради слаба нативна поддръжка на Gradle/Android.

**Polyrepo с shared npm package** — отхвърлен като преждевременен. Версионирането на shared пакети добавя friction без реална полза докато екипите са малки и промените са чести.

**Git submodules** — отхвърлен. Sync friction при всеки contributor, лоша developer experience.

---

## Последствия

- Всеки нов frontend проект трябва да се регистрира в Nx преди merge в main
- `api-contracts` package въвежда breaking change политика — semver за публични API промени
- Android интеграцията изисква верификация на Java/Gradle версии преди Фаза 4
- Laravel директорията остава извън Nx project graph докато е migration authority; при Stage 4 се мести с обикновен `git mv` в `legacy/laravel/` (вж. `LARAVEL_EXIT_PLAN.md`)
- Съществуващият фронтенд е в `resources/frontend/`. Фаза 3 го **регистрира на място**; преместването в `apps/web-*` е отделно решение, не част от тази ADR. Целевата структура по-горе е ориентир, не задължение
- Android (ArtMetro) и artefak.eu не са открити в това repository — тяхното местоположение трябва да се установи във Фаза 1, преди да се обещава `apps/android` или `apps/web-artefak`
