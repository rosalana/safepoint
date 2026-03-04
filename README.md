[![Rosalana](https://raw.githubusercontent.com/rosalana/.github/main/Safepoint_Banner.png)](https://github.com/rosalana)

**Rosalana Safepoint** is a TypeScript type generator for **Laravel + Inertia.js applications.**

It generates a **single TypeScript file** from your Laravel application — models, routes, shared Inertia data, and helper types — all in one flat output optimized for end-to-end type safety.

This package is designed primarily for:

- Laravel developers using **Inertia.js**
- teams who want **type-safe props, route params, and request bodies**
- projects that use **static analysis** over runtime reflection
- monorepo setups with **multiple Laravel apps**

## Installation

```bash
composer require rosalana/safepoint
```

## Why Safepoint?

Inertia.js moves data between PHP and JavaScript — but without types, that boundary is invisible.

Typing it manually means:

- duplicating model definitions
- guessing what props a page receives
- no autocomplete for route params or request bodies
- types going out of sync as the app grows

**Rosalana Safepoint does not require you to write types manually.**
It reads your Laravel application using static analysis and generates them for you.

> [!NOTE]
> Safepoint uses [`laravel/ranger`](https://github.com/laravel/ranger) and [`laravel/surveyor`](https://github.com/laravel/surveyor) under the hood to inspect your models, routes, and shared data without running the application.

## What it generates

Running `php artisan safepoint:generate` produces `resources/js/types/safepoint.ts`:

```ts
// --- Models --- //

export interface Post {
  id: number
  title: string
  body: string
  published: boolean
  user_id: number
  created_at: string | null
  updated_at: string | null

  user?: User | null
}

// --- Routes --- //

export interface Routes {
  'post.show': {
    method: 'GET'
    params: { post: number }
    body: never
    props: {
      post: RequiredKeys<Post, 'id' | 'title' | 'body' | 'published' | 'user_id' | 'created_at' | 'updated_at'>
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
}

// --- Shared Data --- //

export interface SharedData {
  auth: {
    user: {
      id: number
      name: string
      email: string
    } | null
  }
  flash: {
    success: string | null
    error: string | null
  }
}

// --- Helpers --- //

type RequiredKeys<T, K extends keyof T> = Omit<T, K> & Required<Pick<T, K>>

export type PageProps<T extends keyof Routes> =
  Routes[T]['props'] extends never
    ? SharedData
    : Routes[T]['props'] & SharedData

export type RouteParams<T extends keyof Routes> =
  Routes[T]['params'] extends never
    ? never
    : Routes[T]['params']

export type RouteBody<T extends keyof Routes> =
  Routes[T]['body'] extends never
    ? never
    : Routes[T]['body']
```

## Usage

```bash
php artisan safepoint:generate
```

### Options

| Option | Description |
| --- | --- |
| `--path=` | Custom output path (default: `resources/js/types/safepoint.ts`) |
| `--base-path=` | Comma-separated base paths (default: `base_path()`) |
| `--app-path=` | Comma-separated app paths (default: `app_path()`) |

### Multiple paths (monorepo)

```bash
php artisan safepoint:generate \
  --base-path=/app,/packages/blog \
  --app-path=/app/app,/packages/blog/src
```

Only models and routes whose source files are under `--app-path` are included. Vendor models (e.g. `DatabaseNotification` from the `Notifiable` trait) are automatically filtered out.

## How it works

Safepoint inspects your application statically — no runtime required:

- **Models** — attributes from DB schema, relations from PHPDoc
- **Routes** — HTTP method, URL parameters, request validation rules, Inertia props
- **Shared data** — from `HandleInertiaRequests::share()`

## PHPDoc annotations

Since Safepoint uses static analysis, it can't detect runtime behavior like `$post->load('user')` or manually built responses. Use PHPDoc annotations on controller methods to override or extend the generated types.

| Annotation | Example | Effect |
| --- | --- | --- |
| `@safepoint-ignore` | `@safepoint-ignore` | Skip this route entirely |
| `@safepoint-include` | `@safepoint-include user, comments` | Add relations to the `RequiredKeys` key list for props |
| `@safepoint-prop` | `@safepoint-prop pagination { current_page: number; total: number }` | Add or override a prop's TypeScript type |
| `@safepoint-body` | `@safepoint-body token string` | Add or override a body field's TypeScript type |
| `@safepoint-param` | `@safepoint-param slug string` | Add or override a URL parameter's TypeScript type |

```php
/**
 * @safepoint-include user
 * @safepoint-prop meta { total: number; page: number }
 */
public function show(Post $post): Response
{
    return Inertia::render('Post/Show', [
        'post' => $post->load('user'),
        'meta' => [...],
    ]);
}
```

> [!NOTE]
> `@safepoint-include` only adds relations that actually exist on the model — unknown relation names are silently ignored.

## Working with generated types

### PageProps

Use `PageProps<T>` to get fully typed page props in your Inertia pages:

```ts
import type { PageProps } from '@/types/safepoint'
import { usePage } from '@inertiajs/vue3'

const { post } = usePage<PageProps<'post.show'>>().props
// post is: RequiredKeys<Post, 'id' | 'title' | ...> & SharedData
```

### RouteParams & RouteBody

```ts
import type { RouteParams, RouteBody } from '@/types/safepoint'

// Typed route parameters
const params: RouteParams<'post.show'> = { post: 1 }

// Typed request body
const body: RouteBody<'post.store'> = { title: 'Hello', body: 'World' }
```

### Extending the generated types

Since the generated file is overwritten on each run, **do not edit it directly.** Instead, create a separate file and use TypeScript [declaration merging](https://www.typescriptlang.org/docs/handbook/declaration-merging.html):

```ts
// resources/js/safepoint-extend.ts
declare module './types/safepoint' {

  // Add computed or custom fields to a model
  interface Post {
    computed_title?: string
  }

  // Add routes that aren't in Laravel's router (e.g. client-side only)
  interface Routes {
    'frontend.dashboard': {
      method: 'GET'
      params: never
      body: never
      props: { widgets: unknown[] }
    }
  }

  // Add extra keys to shared data
  interface SharedData {
    locale: string
  }

}
```

Import this file alongside `safepoint.ts` and TypeScript will merge the declarations automatically.


## License

Rosalana Safepoint is open-source under the [MIT license](/LICENCE), allowing you to freely use, modify, and distribute it with minimal restrictions.

You may not be able to use our systems but you can use our code to build your own.

For details on how to contribute or how the Rosalana ecosystem is maintained, please refer to each repository's individual guidelines.

**Questions or feedback?**

Feel free to open an issue or contribute with a pull request. Happy coding with Rosalana!
