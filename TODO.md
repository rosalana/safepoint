# rosalana/safepoint — Zadání

## Kontext

Laravel Wayfinder generuje TypeScript, který je anti-pattern pro TS vývojáře. PHP namespace v TS kódu (`import { show } from '@actions/App/Http/Controllers/PostController'`) je nevhodné a nechceme to.

Safepoint je **simplified, opinionated verze Wayfinderu** — stejná technologie, jiný TS output. Vše importujeme z jednoho souboru. Package je navržen primárně pro **Inertia.js** workflow, proto nevytváříme runtime akční funkce (ty Inertia nepotřebuje).

---

## Package

```
rosalana/safepoint
```

### Instalace

```bash
composer require rosalana/safepoint
php artisan safepoint:generate
```

### Import

```ts
import { Post, User } from '@/safepoint'
import type { Routes, PageProps } from '@/safepoint'
```

---

## Technologie

Safepoint staví na stejných základech jako Wayfinder:

- nejnovější verze wayfinder je ve složce `wayfinder/` (ne v `src/`)
- Generování přes **Blade šablony** (stejně jako Wayfinder)
- Artisan command `safepoint:generate`
- Vite plugin pro watch mode (stejný přístup jako `@laravel/vite-plugin-wayfinder`) bude přidaný samostatně - toto je pouze composer package bez runtime části.

Většinu PHP infrastruktury (ServiceProvider, Command skeleton) lze zkopírovat z Wayfinderu. **Jediná část která se píše od začátku je logika generování** — tedy Blade šablony a mapování Ranger DTO → TS output. Některé věci už jsou v src připravené.

---

## Co Safepoint generuje

Jeden výstupní soubor: `resources/js/safepoint.ts` (nebo `.d.ts`)

### 1. Eloquent Models

Flat TS interfaces bez PHP namespace. Každý model má:
- všechny attributes (podle DB sloupců + casts)
- relations jako optional (`user?: User`)
- pivot data jako optional (`pivot?: { ... }`)

```ts
// --- Models --- //

export interface Post {
  // attributes
  id: number
  title: string
  body: string
  published: boolean
  user_id: number
  created_at: string
  updated_at: string
  deleted_at: string | null

  // relations (optional — záleží co je načtené)
  user?: User
  comments?: Comment[]

  // pivots (pokud je model v many-to-many)
  pivot?: {
    role: string
    created_at: string
  }
}

export interface User {
  id: number
  name: string
  email: string
  created_at: string
  updated_at: string

  posts?: Post[]
  teams?: Team[]
}
```

### 2. Routes interface

Centrální interface kde každá route má:
- `method` — HTTP metoda (`'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'`)
- `params` — route parametry (nebo `never`)
- `body` — request payload odvozený z Form Request validation rules (nebo `never`)
- `props` — Inertia props které controller předává do view, s `RequiredKeys` pro garantované atributy (nebo `never`)

```ts
// --- Routes --- //

export interface Routes {
  'post.show': {
    method: 'GET'
    params: { post: number }
    body: never
    props: {
      post: RequiredKeys<Post, 'id' | 'title' | 'body' | 'user'>
    }
  }

  'post.index': {
    method: 'GET'
    params: never
    body: never
    props: {
      posts: RequiredKeys<Post, 'id' | 'title' | 'published'>[]
    }
  }

  'post.store': {
    method: 'POST'
    params: never
    body: {
      title: string
      body: string
      published?: boolean
    }
    props: never
  }

  'post.update': {
    method: 'PUT'
    params: { post: number }
    body: {
      title?: string
      body?: string
      published?: boolean
    }
    props: never
  }

  'post.destroy': {
    method: 'DELETE'
    params: { post: number }
    body: never
    props: never
  }
}
```

**Jak se props odvozují:** Safepoint projde routes list → najde controller action → staticky analyzuje `Inertia::render()` volání v těle metody → z array klíčů a typů proměnných odvozuje props strukturu → aplikuje `RequiredKeys` podle toho co je garantovaně načteno (eager loads).

### 3. Shared Data

Odvozeno z `HandleInertiaRequests::share()`. Zvlášť od Routes, merguje se přes helper type.

```ts
export interface SharedData {
  auth: {
    user: User | null
  }
  flash: {
    success: string | null
    error: string | null
  }
}
```

### 4. Helper Types

Generované utility types pro každodenní použití.

```ts
// --- Helpers --- //

// Říká že tyto klíče jsou zaručeně přítomné v modelu
type RequiredKeys<T, K extends keyof T> = Omit<T, K> & Required<Pick<T, K>>

// Page props = props dané route + SharedData
type PageProps<T extends keyof Routes> =
  Routes[T]['props'] extends never
    ? SharedData
    : Routes[T]['props'] & SharedData

// Route params pro danou route
export type RouteParams<T extends keyof Routes> =
  Routes[T]['params'] extends never
    ? never
    : Routes[T]['params']

// Request body pro danou route
export type RouteBody<T extends keyof Routes> =
  Routes[T]['body'] extends never
    ? never
    : Routes[T]['body']
```

---

## Použití

### Page props

```ts
const { props } = usePage<PageProps<'post.show'>>()
props.post.title        // ✅ string, garantovaně přítomné
props.post.user.name    // ✅ User, garantovaně načteno
props.auth.user         // ✅ ze SharedData
```

### Route helper

```ts
route('post.show', { post: 3 })   // ✅ TS ví že post: number je required
route('post.index')                // ✅ TS ví že params nejsou potřeba
route('post.show')                 // ❌ chybí post param — compile error
route('neexistuje')                // ❌ není v Routes — compile error
```

### Inertia form

```ts
const form = useForm<'post.store'>({
  title: '',
  body: '',
})

form.post(route('post.store'))     // ✅
```

---

## Advanced Helpers (volitelné)

Tyto helpery **nemusí být součástí Safepointu** — mohou být v separátní knihovně nebo psané manuálně. Safepoint pouze generuje typy na kterých staví.

```ts
// Typed usePage wrapper
export function usePage<T extends keyof Routes>() {
  return useInertiaPage<PageProps<T>>()
}

// Typed useForm wrapper
export function useForm<T extends keyof Routes>(
  body: RouteBody<T> extends never ? Record<string, never> : RouteBody<T>
) {
  return useInertiaForm(body)
}
```

---

## Co Safepoint záměrně NEgeneruje

Na rozdíl od Wayfinderu vynecháváme:

- **Runtime akční funkce** (`PostController.show()`) — s Inertií zbytečné
- **PHP namespace struktura** v souborech — anti-pattern
- **Broadcast channels/events** — může být v další verzi jako další interface (podobně jako Routes)
- **`.env` typing** — nice-to-have, mimo scope v1
- **Více výstupních souborů** — vše v jednom `safepoint.ts`

---

## Postup implementace

1. Podívat se do `/wayfinder` jako referenci
2. Podívat se co je v src připravené
5. Napsat `safepoint:generate` Artisan command nad Rangerem
6. Napsat Blade šablony pro každou sekci (Models, Routes, SharedData, Helpers)
7. Implementovat logiku popsanou výše.
8. Výstup: jeden soubor `resources/js/safepoint.ts`
9. Otestovat na sample Laravel projektu s Inertia.js (workbench - lehce spustitelný přes composer commands podobně jako v wayfinder složce)