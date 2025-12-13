<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostController extends Controller
{
    /**
     * Получить список постов (тестовый endpoint)
     */
    public function index(Request $request): JsonResponse
    {
        $posts = [
            [
                'userId' => 1,
                'id' => 1,
                'title' => 'Первый тестовый пост',
                'body' => 'Это тело первого тестового поста. Здесь может быть любой текст.',
            ],
            [
                'userId' => 1,
                'id' => 2,
                'title' => 'Второй тестовый пост',
                'body' => 'Это тело второго тестового поста. Ещё один пример контента.',
            ],
        ];

        return response()->json($posts);
    }
}

