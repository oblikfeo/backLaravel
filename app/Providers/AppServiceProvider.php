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
        Socialite::extend('vkid', function ($app) {
            $config = $app['config']['services.vk'];
            
            // Проверяем наличие обязательных параметров
            if (empty($config['client_id']) || empty($config['client_secret']) || empty($config['redirect'])) {
                throw new \InvalidArgumentException('VK OAuth credentials are not configured. Please check your .env file.');
            }
            
            return Socialite::buildProvider(
                VkIdProvider::class,
                [
                    'client_id' => $config['client_id'],
                    'client_secret' => $config['client_secret'],
                    'redirect' => $config['redirect'],
                ]
            );
        });
    }
}
