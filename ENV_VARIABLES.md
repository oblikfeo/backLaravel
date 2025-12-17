# Переменные окружения для боевого сервера

## Обязательные переменные для работы приложения

### Базовые настройки приложения

```env
APP_NAME="Название приложения"
APP_ENV=production
APP_KEY=base64:... (сгенерировать через php artisan key:generate)
APP_DEBUG=false
APP_URL=https://your-domain.com
APP_LOCALE=ru
```

### База данных (PostgreSQL)

```env
DB_CONNECTION=pgsql
DB_HOST=your-db-host
DB_PORT=5432
DB_DATABASE=your-database-name
DB_USERNAME=your-db-username
DB_PASSWORD=your-db-password
```

Или используйте `DATABASE_URL`:

```env
DATABASE_URL=postgresql://username:password@host:port/database
```

### VK OAuth (опционально, для полноценной интеграции)

```env
VK_CLIENT_ID=your_vk_client_id
VK_CLIENT_SECRET=your_vk_client_secret
VK_REDIRECT_URI=https://your-domain.com/api/v1/auth/vkid/callback
```

**Примечание:** Если используется VK ID SDK на фронтенде (токен приходит напрямую от SDK), то эти переменные могут не понадобиться, так как токен уже получен на клиенте.

### Сессии и кеш

```env
SESSION_DRIVER=database
SESSION_LIFETIME=120
CACHE_STORE=database
```

### Логирование

```env
LOG_CHANNEL=stack
LOG_LEVEL=error
```

### Sanctum (для API токенов)

```env
SANCTUM_STATEFUL_DOMAINS=your-domain.com,www.your-domain.com
```

## Проверка переменных окружения

После настройки `.env` файла выполните:

```bash
php artisan config:cache
php artisan route:cache
```

## Важные замечания

1. **VK_CLIENT_ID и VK_CLIENT_SECRET** - нужны только если вы используете серверный OAuth flow через Socialite. Если токен приходит от VK ID SDK на фронтенде, эти переменные не обязательны.

2. **APP_DEBUG** - всегда должен быть `false` на боевом сервере.

3. **APP_KEY** - должен быть уникальным и секретным. Никогда не коммитьте его в git.

4. **DATABASE_URL** - если используется Railway или другой хостинг, они могут предоставлять эту переменную автоматически.





