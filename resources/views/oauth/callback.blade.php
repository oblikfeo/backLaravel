<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Авторизация через VK</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            color: white;
        }
        .container {
            text-align: center;
            padding: 2rem;
        }
        .spinner {
            border: 4px solid rgba(255, 255, 255, 0.3);
            border-top: 4px solid white;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 1rem;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="spinner"></div>
        <p>Авторизация успешна! Перенаправление...</p>
    </div>

    <script>
        // Получаем токен и данные пользователя из сервера
        const token = @json($token ?? null);
        const user = @json($user ?? null);
        const frontendUrl = @json($frontendUrl);
        const error = @json($error ?? null);

        if (error) {
            // Если есть ошибка, перенаправляем с параметром error
            const redirectUrl = new URL(frontendUrl);
            redirectUrl.searchParams.set('error', error);
            setTimeout(() => {
                window.location.href = redirectUrl.toString();
            }, 1000);
        } else if (token) {
            // Сохраняем токен в localStorage (опционально)
            localStorage.setItem('auth_token', token);
            if (user) {
                localStorage.setItem('user', JSON.stringify(user));
            }

            // Перенаправляем на фронтенд с токеном в URL
            const redirectUrl = new URL(frontendUrl);
            redirectUrl.searchParams.set('token', token);
            
            // Перенаправляем через небольшую задержку для лучшего UX
            setTimeout(() => {
                window.location.href = redirectUrl.toString();
            }, 500);
        } else {
            // Если нет токена и нет ошибки, просто перенаправляем
            setTimeout(() => {
                window.location.href = frontendUrl;
            }, 1000);
        }
    </script>
</body>
</html>
