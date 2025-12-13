<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Providers\VkIdProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class OAuthController extends Controller
{
    /**
     * Редирект на VK ID OAuth
     */
    public function redirect(string $provider): \Illuminate\Http\RedirectResponse
    {
        if ($provider !== 'vkid') {
            abort(404, 'Провайдер не поддерживается');
        }

        return Socialite::buildProvider(VkIdProvider::class, [
            'client_id' => env('VK_CLIENT_ID'),
            'client_secret' => env('VK_CLIENT_SECRET'),
            'redirect' => env('VK_REDIRECT_URI'),
        ])->stateless()->redirect();
    }

    /**
     * Обработка callback от OAuth провайдера
     */
    public function callback(string $provider, Request $request): JsonResponse
    {
        if ($provider !== 'vkid') {
            abort(404, 'Провайдер не поддерживается');
        }

        try {
            $socialUser = Socialite::buildProvider(VkIdProvider::class, [
                'client_id' => env('VK_CLIENT_ID'),
                'client_secret' => env('VK_CLIENT_SECRET'),
                'redirect' => env('VK_REDIRECT_URI'),
            ])->stateless()->user();

            // Ищем пользователя по provider и provider_id
            $user = User::where('provider', $provider)
                ->where('provider_id', $socialUser->getId())
                ->first();

            // Если пользователь не найден, создаём нового
            if (!$user) {
                // Генерируем email если его нет
                $email = $socialUser->getEmail();
                if (!$email) {
                    $email = "vk_{$socialUser->getId()}@vkid.local";
                }

                // Проверяем, может быть email уже используется другим пользователем
                $existingUser = User::where('email', $email)
                    ->where('provider', '!=', $provider)
                    ->first();

                if ($existingUser) {
                    // Связываем OAuth с существующим аккаунтом
                    $existingUser->update([
                        'provider' => $provider,
                        'provider_id' => $socialUser->getId(),
                        'avatar' => $socialUser->getAvatar() ?: $existingUser->avatar,
                    ]);
                    $user = $existingUser;
                } else {
                    // Создаём нового пользователя
                    $user = User::create([
                        'name' => $socialUser->getName() ?: 'VK User',
                        'email' => $email,
                        'password' => null, // OAuth пользователи без пароля
                        'provider' => $provider,
                        'provider_id' => (string) $socialUser->getId(),
                        'avatar' => $socialUser->getAvatar(),
                    ]);
                }
            } else {
                // Обновляем данные существующего пользователя
                $updateData = [];
                if ($socialUser->getName()) {
                    $updateData['name'] = $socialUser->getName();
                }
                if ($socialUser->getAvatar()) {
                    $updateData['avatar'] = $socialUser->getAvatar();
                }
                if (!empty($updateData)) {
                    $user->update($updateData);
                }
            }

            // Создаём токен для пользователя
            $token = $user->createToken('oauth-token')->plainTextToken;

            // Возвращаем токен и данные пользователя
            return response()->json([
                'message' => 'Успешная авторизация через ' . strtoupper($provider),
                'user' => $user,
                'token' => $token,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка авторизации',
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}

