# rosalana/safepoint

An opinionated alternative to [Laravel Wayfinder](https://github.com/laravel/wayfinder) that generates a **single TypeScript file** from your Laravel application — models, routes, shared Inertia data, and helper types — all in one flat output optimized for [Inertia.js](https://inertiajs.com/).

## What it generates

Running `php artisan safepoint:generate` produces `resources/js/safepoint.ts`:

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

## Requirements

- PHP 8.2+
- Laravel 12+
- Inertia.js ([`inertiajs/inertia-laravel`](https://github.com/inertiajs/inertia-laravel))

## Installation

```bash
composer require rosalana/safepoint
```

## Usage

```bash
php artisan safepoint:generate
```

The file is written to `resources/js/safepoint.ts` by default.

### Options

| Option | Description |
|--------|-------------|
| `--path=` | Custom output path (default: `resources/js/safepoint.ts`) |
| `--base-path=` | Comma-separated base paths (default: `base_path()`) |
| `--app-path=` | Comma-separated app paths (default: `app_path()`) |

### Multiple paths (monorepo)

```bash
php artisan safepoint:generate \
  --base-path=/app,/packages/blog \
  --app-path=/app/app,/packages/blog/src
```

## How it works

Safepoint uses [`laravel/ranger`](https://github.com/laravel/ranger) and [`laravel/surveyor`](https://github.com/laravel/surveyor) under the hood to perform static analysis of your application. It inspects:

- **Models** — attributes (from DB schema) and relations (from PHPDoc)
- **Routes** — HTTP method, URL parameters, request validation rules, Inertia props
- **Shared data** — from `HandleInertiaRequests::share()`

Only models and routes whose source files are under `--app-path` are included. Vendor models (e.g. `DatabaseNotification` from the `Notifiable` trait) are automatically filtered out.

## Extending the generated types

Since the generated file is overwritten on each run, **do not edit it directly**. Instead, create a separate file and use TypeScript [declaration merging](https://www.typescriptlang.org/docs/handbook/declaration-merging.html):

```ts
// resources/js/safepoint-extend.ts
declare module './safepoint' {

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

## Props typing with `PageProps`

Use `PageProps<T>` to get fully typed page props in your Inertia pages:

```ts
import type { PageProps } from '@/safepoint'
import { usePage } from '@inertiajs/vue3'

const { post } = usePage<PageProps<'post.show'>>().props
// post is: RequiredKeys<Post, 'id' | 'title' | ...> & SharedData
```

## Route params and body

```ts
import type { RouteParams, RouteBody } from '@/safepoint'

// Typed route parameters
const params: RouteParams<'post.show'> = { post: 1 }

// Typed request body
const body: RouteBody<'post.store'> = { title: 'Hello', body: 'World' }
```

## Development

This package uses [Orchestra Testbench](https://packages.tools/testbench/) for development.

```bash
# Install dependencies
composer install

# Build workbench
composer build

# Run the generator against the workbench app
composer generate

# Serve the workbench app locally
composer serve
```

> **Note:** `composer generate` requires a SQLite database with migrations applied. The script handles this automatically.
