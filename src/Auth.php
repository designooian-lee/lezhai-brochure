<?php
declare(strict_types=1);

namespace Lezhai;

final class Auth
{
    public static function check(): bool
    {
        return ($_SESSION['admin_authenticated'] ?? false) === true;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: ' . base_path('admin/login'));
            exit;
        }
    }

    public static function attempt(string $username, string $password): bool
    {
        $pdo = Database::connection();
        $identity = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'local') . '|' . strtolower($username));
        $stmt = $pdo->prepare('SELECT attempts, blocked_until FROM login_attempts WHERE identity_hash = ?');
        $stmt->execute([$identity]);
        $attempt = $stmt->fetch();
        if ($attempt && $attempt['blocked_until'] && strtotime($attempt['blocked_until']) > time()) {
            return false;
        }
        $valid = hash_equals(Config::get('ADMIN_USERNAME', 'admin'), $username)
            && password_verify($password, Config::get('ADMIN_PASSWORD_HASH'));
        if ($valid) {
            $pdo->prepare('DELETE FROM login_attempts WHERE identity_hash = ?')->execute([$identity]);
            session_regenerate_id(true);
            $_SESSION['admin_authenticated'] = true;
            return true;
        }
        $pdo->prepare(
            "INSERT INTO login_attempts(identity_hash, attempts, blocked_until, updated_at)
             VALUES (?, 1, NULL, NOW())
             ON CONFLICT(identity_hash) DO UPDATE SET
                attempts = CASE WHEN login_attempts.updated_at < NOW() - INTERVAL '15 minutes' THEN 1 ELSE login_attempts.attempts + 1 END,
                blocked_until = CASE WHEN login_attempts.attempts + 1 >= 5 THEN NOW() + INTERVAL '15 minutes' ELSE NULL END,
                updated_at = NOW()"
        )->execute([$identity]);
        return false;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', ['expires'=>time()-42000,'path'=>$params['path'],'domain'=>$params['domain'],'secure'=>$params['secure'],'httponly'=>$params['httponly'],'samesite'=>$params['samesite']?:'Lax']);
        }
        session_destroy();
    }

    public static function csrf(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
        }
        return $_SESSION['csrf'];
    }

    public static function verifyCsrf(): void
    {
        $stored=(string)($_SESSION['csrf']??'');$provided=(string)($_POST['_csrf']??'');
        if ($stored==='' || $provided==='' || !hash_equals($stored,$provided)) {
            http_response_code(419);
            exit('页面已过期，请返回后重试。');
        }
    }
}
