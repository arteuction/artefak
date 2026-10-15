---
paths:
  - "resources/js/marketplace/**"
  - "resources/css/**"
  - "tests/e2e/**"
  - "playwright.config.*"
---
# Frontend React/design rules

- Reuse the existing React/Vite/router/query/auth/API-client architecture. Do not introduce Nuxt/Vue or a second component system.
- Reuse CSS custom-property tokens and existing UI primitives. Do not hardcode new colours or spacing when a semantic token exists.
- Preserve generated OpenAPI types; do not hand-maintain conflicting API interfaces.
- Keep Artwork identity separate from current ArtLot pricing/availability.
- Keep server-confirmed business state distinct from pending/optimistic UI state. Reconcile from the canonical API after websocket reconnect or uncertain result.
- Every component needs loading, empty, error and relevant long-content states; test Bulgarian Cyrillic strings and responsive layouts.
- Images must reserve dimensions, use appropriate derivatives/srcset/sizes, handle failure and avoid unnecessary full-resolution downloads.
- Honor keyboard interaction, visible focus, contrast and `prefers-reduced-motion`; prefer CSS motion for simple effects.
- Keep development-only tools out of the production bundle. Report typecheck/build/Playwright commands actually run.
