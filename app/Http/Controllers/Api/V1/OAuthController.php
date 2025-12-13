<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class OAuthController extends Controller
{
    /**
     * Обмен VK ID code на токен и авторизация пользователя
     */
    public function vkidCallback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string',
            'device_id' => 'required|string',
        ]);

        try {
            // Обмениваем code на access_token через VK ID API
            $tokenResponse = Http::asForm()->post('https://id.vk.com/oauth/token', [
                'grant_type' => 'authorization_code',
                'code' => $validated['code'],
                'client_id' => env('VK_CLIENT_ID'),
                'client_secret' => env('VK_CLIENT_SECRET'),
                'redirect_uri' => env('VK_REDIRECT_URI'),
            ]);

            if (!$tokenResponse->successful()) {
                return response()->json([
                    'message' => 'Ошибка обмена code на токен',
                    'error' => $tokenResponse->json(),
                ], 400);
            }

            $tokenData = $tokenResponse->json();
            $accessToken = $tokenData['access_token'] ?? null;

            if (!$accessToken) {
                return response()->json([
                    'message' => 'Не удалось получить access_token',
                ], 400);
            }

            // Получаем данные пользователя через VK API
            $userResponse = Http::get('https://api.vk.com/method/users.get', [
                'access_token' => $accessToken,
                'v' => '5.131',
                'fields' => 'photo_200,email',
            ]);

            if (!$userResponse->successful()) {
                return response()->json([
                    'message' => 'Ошибка получения данных пользователя',
                ], 400);
            }

            $userData = $userResponse->json();
            $vkUser = $userData['response'][0] ?? null;

            if (!$vkUser) {
                return response()->json([
                    'message' => 'Не удалось получить данные пользователя',
                ], 400);
            }

            // Ищем или создаём пользователя
            $user = User::where('provider', 'vkid')
                ->where('provider_id', (string) $vkUser['id'])
                ->first();

            if (!$user) {
                // Генерируем email если его нет
                $email = $vkUser['email'] ?? "vk_{$vkUser['id']}@vkid.local";

                // Проверяем, может быть email уже используется
                $existingUser = User::where('email', $email)
                    ->where('provider', '!=', 'vkid')
                    ->first();

                if ($existingUser) {
                    // Связываем OAuth с существующим аккаунтом
                    $existingUser->update([
                        'provider' => 'vkid',
                        'provider_id' => (string) $vkUser['id'],
                        'avatar' => $vkUser['photo_200'] ?? $existingUser->avatar,
                    ]);
                    $user = $existingUser;
                } else {
                    // Создаём нового пользователя
                    $user = User::create([
                        'name' => trim(($vkUser['first_name'] ?? '') . ' ' . ($vkUser['last_name'] ?? '')) ?: 'VK User',
                        'email' => $email,
                        'password' => null,
                        'provider' => 'vkid',
                        'provider_id' => (string) $vkUser['id'],
                        'avatar' => $vkUser['photo_200'] ?? null,
                    ]);
                }
            } else {
                // Обновляем данные существующего пользователя
                $updateData = [];
                $name = trim(($vkUser['first_name'] ?? '') . ' ' . ($vkUser['last_name'] ?? ''));
                if ($name) {
                    $updateData['name'] = $name;
                }
                if (isset($vkUser['photo_200'])) {
                    $updateData['avatar'] = $vkUser['photo_200'];
                }
                if (!empty($updateData)) {
                    $user->update($updateData);
                }
            }

            // Создаём токен для пользователя
            $token = $user->createToken('vkid-token')->plainTextToken;

            return response()->json([
                'message' => 'Успешная авторизация через VK ID',
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
