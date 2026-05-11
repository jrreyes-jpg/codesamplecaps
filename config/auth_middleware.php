<?php
// Shared authentication/session helpers for protected pages.

if (!function_exists('auth_start_session')) {
    function auth_start_session(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.use_only_cookies', '1');

            $secureCookie = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $secureCookie,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            session_start();
        }
    }
}

if (!function_exists('auth_apply_no_cache_headers')) {
    function auth_apply_no_cache_headers(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, s-maxage=0, proxy-revalidate');
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
        header('Surrogate-Control: no-store');
        header('Vary: Cookie');
    }
}

if (!function_exists('auth_apply_logout_headers')) {
    function auth_apply_logout_headers(): void
    {
        auth_apply_no_cache_headers();
        header('Clear-Site-Data: "cache"');
    }
}

if (!function_exists('auth_destroy_session')) {
    function auth_destroy_session(): void
    {
        auth_start_session();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => (bool)$params['secure'],
                    'httponly' => (bool)$params['httponly'],
                    'samesite' => $params['samesite'] ?? 'Lax',
                ]
            );
        }

        session_destroy();
    }
}

if (!function_exists('auth_redirect_to_login')) {
    function auth_redirect_to_login(array $query = []): void
    {
        $target = '/codesamplecaps/LOGIN/php/login.php';
        if (!empty($query)) {
            $target .= '?' . http_build_query($query);
        }
        header('Location: ' . $target);
        exit();
    }
}

if (!function_exists('auth_dashboard_path_for_role')) {
    function auth_dashboard_path_for_role(?string $role): ?string
    {
        $dashboardPaths = [
            'super_admin' => '/codesamplecaps/SUPERADMIN/dashboards/super_admin_dashboard.php',
            'engineer' => '/codesamplecaps/ENGINEER/dashboards/engineer_dashboard.php',
            'foreman' => '/codesamplecaps/FOREMAN/dashboards/foreman_dashboard.php',
            'client' => '/codesamplecaps/CLIENT/dashboards/client_dashboard.php',
        ];

        return $dashboardPaths[$role] ?? null;
    }
}

if (!function_exists('auth_redirect_authenticated_user')) {
    function auth_redirect_authenticated_user(): void
    {
        auth_start_session();

        $role = $_SESSION['role'] ?? null;
        $dashboardPath = auth_dashboard_path_for_role(is_string($role) ? $role : null);

        if (
            $dashboardPath !== null
            && auth_session_is_valid_for_roles(['super_admin', 'engineer', 'foreman', 'client'])
        ) {
            header('Location: ' . $dashboardPath);
            exit();
        }
    }
}

if (!function_exists('auth_render_back_button_logout_script')) {
    function auth_render_back_button_logout_script(): void
    {
        static $rendered = false;

        if ($rendered) {
            return;
        }

        $rendered = true;
        echo <<<'HTML'
<script>
(function () {
    if (!window.history || !window.history.pushState) {
        return;
    }

    var logoutUrl = '/codesamplecaps/LOGIN/php/logout.php?nav=back';
    window.history.replaceState({ protectedPage: true }, document.title, window.location.href);
    window.history.pushState({ protectedPage: true, guard: true }, document.title, window.location.href);

    window.addEventListener('popstate', function () {
        window.location.replace(logoutUrl);
    });
})();
</script>
HTML;
    }
}

if (!function_exists('auth_enforce_activity_timeout')) {
    function auth_enforce_activity_timeout(int $idleTimeoutSeconds = 900): void
    {
        $userId = $_SESSION['user_id'] ?? null;
        if ($userId === null) {
            return;
        }

        $now = time();
        $lastActivity = (int)($_SESSION['last_activity_at'] ?? $now);

        if (($now - $lastActivity) > $idleTimeoutSeconds) {
            auth_destroy_session();
            auth_redirect_to_login(['timeout' => '1']);
        }

        $_SESSION['last_activity_at'] = $now;
    }
}

if (!function_exists('auth_user_agent_fingerprint')) {
    function auth_user_agent_fingerprint(): string
    {
        return hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
    }
}

if (!function_exists('auth_login_user')) {
    function auth_login_user(array $user): void
    {
        auth_start_session();
        session_regenerate_id(true);

        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['name'] = (string)$user['full_name'];
        $_SESSION['role'] = (string)$user['role'];
        $_SESSION['logged_in_at'] = time();
        $_SESSION['last_activity_at'] = time();
        $_SESSION['auth_user_agent'] = auth_user_agent_fingerprint();
    }
}

if (!function_exists('auth_session_is_valid_for_roles')) {
    function auth_session_is_valid_for_roles(array $roles): bool
    {
        $userId = $_SESSION['user_id'] ?? null;
        $role = $_SESSION['role'] ?? null;
        $userAgent = $_SESSION['auth_user_agent'] ?? null;

        return is_int($userId)
            && $userId > 0
            && is_string($role)
            && in_array($role, $roles, true)
            && is_string($userAgent)
            && hash_equals($userAgent, auth_user_agent_fingerprint());
    }
}

if (!function_exists('require_role')) {
    function require_role($role): void
    {
        auth_start_session();
        auth_apply_no_cache_headers();
        auth_enforce_activity_timeout();

        if (!auth_session_is_valid_for_roles([(string)$role])) {
            auth_destroy_session();
            auth_redirect_to_login();
        }
    }
}

if (!function_exists('require_any_role')) {
    function require_any_role(array $roles): void
    {
        auth_start_session();
        auth_apply_no_cache_headers();
        auth_enforce_activity_timeout();

        if (!auth_session_is_valid_for_roles($roles)) {
            auth_destroy_session();
            auth_redirect_to_login();
        }
    }
}

if (!function_exists('current_user_id')) {
    function current_user_id()
    {
        return $_SESSION['user_id'] ?? null;
    }
}

if (!function_exists('current_user_role')) {
    function current_user_role()
    {
        return $_SESSION['role'] ?? null;
    }
}

if (!function_exists('auth_csrf_token')) {
    function auth_csrf_token(string $namespace = 'default'): string
    {
        auth_start_session();

        if (!isset($_SESSION['csrf_tokens']) || !is_array($_SESSION['csrf_tokens'])) {
            $_SESSION['csrf_tokens'] = [];
        }

        if (
            empty($_SESSION['csrf_tokens'][$namespace])
            || !is_string($_SESSION['csrf_tokens'][$namespace])
        ) {
            $_SESSION['csrf_tokens'][$namespace] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_tokens'][$namespace];
    }
}

if (!function_exists('auth_is_valid_csrf')) {
    function auth_is_valid_csrf(?string $token, string $namespace = 'default'): bool
    {
        auth_start_session();

        $sessionToken = $_SESSION['csrf_tokens'][$namespace] ?? null;

        return is_string($token)
            && $token !== ''
            && is_string($sessionToken)
            && hash_equals($sessionToken, $token);
    }
}
