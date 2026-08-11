# Maestro web

Next.js 16 App Router application for the public site and the owner, teacher, family, and student experiences.

```bash
pnpm install
pnpm dev
```

Quality gates:

```bash
pnpm lint
pnpm test:run
pnpm build
```

Authenticated domain data comes from Laravel through a server-only data layer. Laravel policies remain the authorization boundary; no access decision may rely on a Next.js redirect alone.
