<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Models\Post;
use Inertia\Inertia;
use Inertia\Response;

class PostController
{
    public function index(): Response
    {
        return Inertia::render('Post/Index', [
            'posts' => Post::all(),
        ]);
    }

    /**
     * @safepoint-include user
     */
    public function show(Post $post): Response
    {
        return Inertia::render('Post/Show', [
            'post' => $post->load('user'),
        ]);
    }

    public function store(StorePostRequest $request): void
    {
        Post::create($request->validated());
    }

    public function update(UpdatePostRequest $request, Post $post): void
    {
        $post->update($request->validated());
    }

    public function destroy(Post $post): void
    {
        $post->delete();
    }
}
