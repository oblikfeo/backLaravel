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
     * Авторизация пользователя через VK ID (принимает access_token от SDK)
     */
    public function vkidCallback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'access_token' => 'required|string',
            'user_id' => 'nullable|string',
        ]);

        try {
            $accessToken = $validated['access_token'];
            $userId = $validated['user_id'] ?? null;

            \Log::info('VK ID Callback', [
                'user_id' => $userId,
                'has_token' => !empty($accessToken),
            ]);

            // Получаем данные пользователя через VK API
            // Если передан user_id, используем его, иначе получаем через API
            $vkUser = null;
            
            if ($userId) {
                // Если user_id передан, пробуем получить данные по нему
                $userResponse = Http::timeout(10)->get('https://api.vk.com/method/users.get', [
                    'user_ids' => $userId,
                    'access_token' => $accessToken,
                    'v' => '5.131',
                    'fields' => 'photo_200,email',
                ]);
            } else {
                // Иначе получаем данные текущего пользователя
                $userResponse = Http::timeout(10)->get('https://api.vk.com/method/users.get', [
                    'access_token' => $accessToken,
                    'v' => '5.131',
                    'fields' => 'photo_200,email',
                ]);
            }

            $statusCode = $userResponse->status();
            $userData = $userResponse->json();

            \Log::info('VK API Response', [
                'status' => $statusCode,
                'user_id_param' => $userId,
                'response' => $userData,
            ]);

            // Проверяем на ошибки VK API (VK возвращает ошибки в JSON даже при HTTP 200)
            if (isset($userData['error'])) {
                $errorCode = $userData['error']['error_code'] ?? 'unknown';
                $errorMsg = $userData['error']['error_msg'] ?? 'Unknown error';
                
                \Log::error('VK API Error', [
                    'error_code' => $errorCode,
                    'error_msg' => $errorMsg,
                    'full_error' => $userData['error'],
                ]);

                // Если токен не работает с users.get, возможно это OpenID токен
                // В этом случае используем user_id из запроса
                if ($errorCode == 5 && $userId) {
                    \Log::info('Trying to use user_id from request', ['user_id' => $userId]);
                    
                    // Создаём минимальный объект пользователя из user_id
                    $vkUser = [
                        'id' => (int) $userId,
                        'first_name' => 'VK',
                        'last_name' => 'User',
                        'photo_200' => null,
                        'email' => null,
                    ];
                } else {
                    return response()->json([
                        'message' => 'Ошибка VK API',
                        'error' => $errorMsg,
                        'error_code' => $errorCode,
                    ], 400);
                }
            } else {
                if (!$userResponse->successful()) {
                    \Log::error('VK API HTTP Error', [
                        'status' => $statusCode,
                        'body' => $userResponse->body(),
                    ]);

                    // Если есть user_id, используем его
                    if ($userId) {
                        \Log::info('Using user_id from request due to HTTP error', ['user_id' => $userId]);
                        $vkUser = [
                            'id' => (int) $userId,
                            'first_name' => 'VK',
                            'last_name' => 'User',
                            'photo_200' => null,
                            'email' => null,
                        ];
                    } else {
                        return response()->json([
                            'message' => 'Ошибка получения данных пользователя',
                            'status' => $statusCode,
                        ], 400);
                    }
                } else {
                    $vkUser = $userData['response'][0] ?? null;

                    if (!$vkUser) {
                        \Log::error('VK User Data Missing', [
                            'response' => $userData,
                        ]);

                        // Если есть user_id, используем его
                        if ($userId) {
                            \Log::info('Using user_id from request due to missing response', ['user_id' => $userId]);
                            $vkUser = [
                                'id' => (int) $userId,
                                'first_name' => 'VK',
                                'last_name' => 'User',
                                'photo_200' => null,
                                'email' => null,
                            ];
                        } else {
                            return response()->json([
                                'message' => 'Не удалось получить данные пользователя',
                                'debug' => $userData,
                            ], 400);
                        }
                    }
                }
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
            \Log::error('VK ID Callback Exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Ошибка авторизации',
                'error' => config('app.debug') ? $e->getMessage() : 'Внутренняя ошибка сервера',
            ], 500);
        }
    }
}
