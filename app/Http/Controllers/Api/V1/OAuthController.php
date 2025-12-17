<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;

class OAuthController extends Controller
{
    /**
     * Тестовый endpoint для проверки конфигурации
     */
    public function vkidTest(): JsonResponse
    {
        try {
            $config = config('services.vk');
            $hasConfig = !empty($config['client_id']) && !empty($config['client_secret']) && !empty($config['redirect']);
            
            // Пробуем создать драйвер
            $driver = null;
            $driverError = null;
            try {
                $driver = Socialite::driver('vkid');
            } catch (\Exception $e) {
                $driverError = $e->getMessage();
            }
            
            \Log::info('VK ID Test', [
                'has_config' => $hasConfig,
                'config_keys' => $hasConfig ? array_keys($config) : [],
                'driver_created' => $driver !== null,
                'driver_error' => $driverError,
            ]);
            
            return response()->json([
                'config_exists' => $hasConfig,
                'config' => $hasConfig ? [
                    'client_id' => substr($config['client_id'], 0, 5) . '...',
                    'has_secret' => !empty($config['client_secret']),
                    'redirect' => $config['redirect'],
                ] : null,
                'driver_works' => $driver !== null,
                'driver_error' => $driverError,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }

    /**
     * Перенаправление на VK ID для авторизации
     */
    public function vkidRedirect(): \Illuminate\Http\RedirectResponse
    {
        try {
            \Log::info('VK ID Redirect called', [
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
            
            $redirectUrl = Socialite::driver('vkid')
                ->stateless() // Для API без сессий
                ->redirect()
                ->getTargetUrl();
            
            \Log::info('VK ID Redirect URL generated', [
                'url' => $redirectUrl,
                'has_scope' => strpos($redirectUrl, 'scope=') !== false,
            ]);
            
            return redirect($redirectUrl);
        } catch (\Exception $e) {
            \Log::error('VK ID Redirect Exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Callback после авторизации через VK ID (стандартный OAuth flow через Socialite)
     */
    public function vkidCallback(Request $request): JsonResponse
    {
        try {
            \Log::info('VK ID Callback started', [
                'query_params' => $request->query(),
                'has_code' => $request->has('code'),
                'has_error' => $request->has('error'),
            ]);

            // Проверяем на ошибки от VK
            if ($request->has('error')) {
                \Log::error('VK returned error', [
                    'error' => $request->get('error'),
                    'error_description' => $request->get('error_description'),
                ]);
                
                return response()->json([
                    'message' => 'Ошибка авторизации VK',
                    'error' => $request->get('error'),
                    'error_description' => $request->get('error_description'),
                ], 400);
            }

            // Проверяем наличие code
            if (!$request->has('code')) {
                \Log::error('VK ID Callback: no code parameter');
                return response()->json([
                    'message' => 'Отсутствует код авторизации',
                ], 400);
            }

            // Получаем пользователя через Socialite (автоматически обменивает code на token)
            $vkUser = Socialite::driver('vkid')
                ->stateless() // Для API без сессий
                ->user();

            \Log::info('VK ID Callback: user received', [
                'user_id' => $vkUser->getId(),
                'email' => $vkUser->getEmail(),
                'name' => $vkUser->getName(),
            ]);

            // Ищем или создаём пользователя
            $user = User::where('provider', 'vkid')
                ->where('provider_id', (string) $vkUser->getId())
                ->first();

            if (!$user) {
                // Генерируем email если его нет
                $email = $vkUser->getEmail() ?? "vk_{$vkUser->getId()}@vkid.local";

                // Проверяем, может быть email уже используется
                $existingUser = User::where('email', $email)
                    ->where('provider', '!=', 'vkid')
                    ->first();

                if ($existingUser) {
                    // Связываем OAuth с существующим аккаунтом
                    $existingUser->update([
                        'provider' => 'vkid',
                        'provider_id' => (string) $vkUser->getId(),
                        'avatar' => $vkUser->getAvatar() ?? $existingUser->avatar,
                    ]);
                    $user = $existingUser;
                } else {
                    // Создаём нового пользователя
                    $user = User::create([
                        'name' => $vkUser->getName() ?: 'VK User',
                        'email' => $email,
                        'password' => null,
                        'provider' => 'vkid',
                        'provider_id' => (string) $vkUser->getId(),
                        'avatar' => $vkUser->getAvatar() ?? null,
                    ]);
                }
            } else {
                // Обновляем данные существующего пользователя
                $updateData = [];
                if ($vkUser->getName()) {
                    $updateData['name'] = $vkUser->getName();
                }
                if ($vkUser->getAvatar()) {
                    $updateData['avatar'] = $vkUser->getAvatar();
                }
                if (!empty($updateData)) {
                    $user->update($updateData);
                }
            }

            // Создаём токен для пользователя
            $token = $user->createToken('vkid-token')->plainTextToken;

            // Получаем URL фронтенда из env или используем дефолтный
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            
            // Возвращаем HTML страницу, которая автоматически перенаправит на фронтенд с токеном
            return response()->view('oauth.callback', [
                'token' => $token,
                'user' => $user,
                'frontendUrl' => $frontendUrl,
            ]);

        } catch (\Exception $e) {
            \Log::error('VK ID Callback Exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Получаем URL фронтенда из env
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            $errorMessage = config('app.debug') ? $e->getMessage() : 'Внутренняя ошибка сервера';
            
            // Возвращаем HTML страницу с ошибкой
            return response()->view('oauth.callback', [
                'token' => null,
                'user' => null,
                'frontendUrl' => $frontendUrl,
                'error' => $errorMessage,
            ], 500);
        }
    }
}
