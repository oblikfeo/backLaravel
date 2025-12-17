<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Facades\Socialite;
use App\Providers\VkIdProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Регистрируем кастомный VK ID провайдер для Socialite
        // Регистрируем только если конфигурация доступна
        try {
            $config = config('services.vk');
            
            // Проверяем наличие обязательных параметров
            if (!empty($config['client_id']) && !empty($config['client_secret']) && !empty($config['redirect'])) {
                Socialite::extend('vkid', function ($app) use ($config) {
                    return Socialite::buildProvider(
                        VkIdProvider::class,
                        [
                            'client_id' => $config['client_id'],
                            'client_secret' => $config['client_secret'],
                            'redirect' => $config['redirect'],
                        ]
                    );
                });
            } else {
                \Log::warning('VK OAuth provider not registered: missing configuration', [
                    'has_client_id' => !empty($config['client_id']),
                    'has_client_secret' => !empty($config['client_secret']),
                    'has_redirect' => !empty($config['redirect']),
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('Failed to register VK ID provider', [
                'message' => $e->getMessage(),
            ]);
            // Не прерываем загрузку приложения, просто логируем ошибку
        }
    }
}
