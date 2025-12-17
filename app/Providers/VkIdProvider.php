<?php

namespace App\Providers;

use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;

class VkIdProvider extends AbstractProvider implements ProviderInterface
{
    /**
     * VK ID OAuth endpoints
     * VK использует id.vk.com для международной версии и id.vk.ru для российской
     * Попробуем использовать .com, если не работает - можно переключить на .ru
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
     * Переопределяем buildAuthUrlFromBase, чтобы убедиться, что scope передается
     */
    protected function buildAuthUrlFromBase($url, $state)
    {
        $query = $this->getCodeFields($state);
        
        \Log::info('VK ID buildAuthUrlFromBase', [
            'url' => $url,
            'scope' => $query['scope'] ?? 'NOT_SET',
            'all_params' => array_keys($query),
        ]);
        
        return $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * {@inheritdoc}
     */
    protected function getCodeFields($state = null): array
    {
        $fields = parent::getCodeFields($state);
        
        // VK ID требует scope для OAuth 2.1
        // Используем openid для базовой авторизации и email для получения email
        // Важно: scope должен быть строкой с пробелами, не массивом
        $fields['scope'] = 'openid email';
        
        // Убеждаемся, что response_type = code (для Authorization Code Flow)
        $fields['response_type'] = 'code';
        
        // Убеждаемся, что redirect_uri передается (должен совпадать с настройками в VK)
        if (empty($fields['redirect_uri'])) {
            $fields['redirect_uri'] = $this->redirectUrl;
        }
        
        \Log::info('VK ID getCodeFields', [
            'scope' => $fields['scope'] ?? 'NOT_SET',
            'response_type' => $fields['response_type'] ?? 'not_set',
            'client_id' => $fields['client_id'] ?? 'not_set',
            'redirect_uri' => $fields['redirect_uri'] ?? 'not_set',
            'state' => $fields['state'] ?? 'not_set',
        ]);
        
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
        \Log::info('getUserByToken called', ['token_length' => strlen($token)]);
        
        // Сначала пробуем получить данные через OpenID UserInfo endpoint (если это OpenID токен)
        try {
            $userInfoResponse = $this->getHttpClient()->get($this->baseUrl . '/oauth/userinfo', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
            ]);

            $userInfoData = json_decode($userInfoResponse->getBody()->getContents(), true);
            
            if (!empty($userInfoData) && isset($userInfoData['sub'])) {
                \Log::info('Got user data from OpenID UserInfo', ['user_id' => $userInfoData['sub'] ?? null]);
                
                // Преобразуем OpenID формат в формат VK API
                $userData = [
                    'id' => $userInfoData['sub'] ?? null,
                    'first_name' => $userInfoData['given_name'] ?? '',
                    'last_name' => $userInfoData['family_name'] ?? '',
                    'email' => $userInfoData['email'] ?? null,
                    'photo_200' => $userInfoData['picture'] ?? null,
                ];
                
                // Дополнительно получаем данные через VK API для полноты
                if (!empty($userData['id'])) {
                    try {
                        $vkApiResponse = $this->getHttpClient()->get($this->apiUrl . '/method/users.get', [
                            'query' => [
                                'user_ids' => $userData['id'],
                                'access_token' => $token,
                                'v' => '5.199',
                                'fields' => 'photo_200,email',
                            ],
                        ]);

                        $vkApiData = json_decode($vkApiResponse->getBody()->getContents(), true);
                        if (isset($vkApiData['response'][0])) {
                            // Объединяем данные
                            $userData = array_merge($userData, $vkApiData['response'][0]);
                        }
                    } catch (\Exception $e) {
                        \Log::warning('VK API call failed, using OpenID data only', ['error' => $e->getMessage()]);
                    }
                }
                
                return $userData;
            }
        } catch (\Exception $e) {
            \Log::warning('OpenID UserInfo failed, trying VK API', ['error' => $e->getMessage()]);
        }

        // Fallback: пробуем получить данные через VK API напрямую
        try {
            $response = $this->getHttpClient()->get($this->apiUrl . '/method/users.get', [
                'query' => [
                    'access_token' => $token,
                    'v' => '5.199',
                    'fields' => 'photo_200,email',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['response'][0])) {
                \Log::info('Got user data from VK API', ['user_id' => $data['response'][0]['id'] ?? null]);
                return $data['response'][0];
            }
            
            if (isset($data['error'])) {
                \Log::error('VK API returned error', ['error' => $data['error']]);
            }
        } catch (\Exception $e) {
            \Log::error('VK API Error in getUserByToken', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // Fallback - возвращаем минимальные данные
        \Log::warning('getUserByToken: returning empty array');
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
        $fields = parent::getTokenFields($code);
        $fields['grant_type'] = 'authorization_code';
        
        \Log::info('getTokenFields', [
            'code_length' => strlen($code),
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUrl,
        ]);
        
        return $fields;
    }
    
    /**
     * {@inheritdoc}
     */
    protected function getAccessTokenResponse($code)
    {
        try {
            $response = parent::getAccessTokenResponse($code);
            
            \Log::info('getAccessTokenResponse', [
                'response_keys' => is_array($response) ? array_keys($response) : 'not_array',
                'has_access_token' => is_array($response) && isset($response['access_token']),
                'response_type' => gettype($response),
            ]);
            
            // VK ID может возвращать токен в поле 'access_token' или 'token'
            if (is_array($response)) {
                if (isset($response['token']) && !isset($response['access_token'])) {
                    $response['access_token'] = $response['token'];
                }
            }
            
            return $response;
        } catch (\Exception $e) {
            \Log::error('getAccessTokenResponse Exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}

