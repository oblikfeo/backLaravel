<?php

namespace App\Providers;

use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;

class VkIdProvider extends AbstractProvider implements ProviderInterface
{
    /**
     * VK ID OAuth endpoints
     */
    protected string $baseUrl = 'https://id.vk.com';
    protected string $apiUrl = 'https://api.vk.com';

    /**
     * {@inheritdoc}
     */
    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase($this->baseUrl . '/oauth/authorize', $state);
    }

    /**
     * {@inheritdoc}
     */
    protected function getCodeFields($state = null): array
    {
        $fields = parent::getCodeFields($state);
        $fields['scope'] = 'openid,email'; // Запрашиваем email
        return $fields;
    }

    /**
     * {@inheritdoc}
     */
    protected function getTokenUrl(): string
    {
        return $this->baseUrl . '/oauth/token';
    }

    /**
     * {@inheritdoc}
     */
    protected function getUserByToken($token): array
    {
        try {
            // Пробуем получить данные через VK API
            $response = $this->getHttpClient()->get($this->apiUrl . '/method/users.get', [
                'query' => [
                    'access_token' => $token,
                    'v' => '5.131',
                    'fields' => 'photo_200',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['response'][0])) {
                return $data['response'][0];
            }
        } catch (\Exception $e) {
            // Если VK API не работает, возвращаем базовые данные
        }

        // Fallback - возвращаем минимальные данные
        return [];
    }

    /**
     * {@inheritdoc}
     */
    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => $user['id'] ?? null,
            'nickname' => $user['screen_name'] ?? null,
            'name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
            'email' => $user['email'] ?? null,
            'avatar' => $user['photo_200'] ?? null,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    protected function getTokenFields($code): array
    {
        return array_merge(parent::getTokenFields($code), [
            'grant_type' => 'authorization_code',
        ]);
    }
}

