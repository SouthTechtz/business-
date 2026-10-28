<?php
// ============================================================
// User model — authentication & permissions
// ============================================================

class User
{
    public $user_id;
    public $tenant_id;
    public $username;
    public $email;
    public $first_name;
    public $last_name;
    public $phone;
    public $is_active;
    public $last_login;
    public $created_at;
    public $roles = [];
    private $permission_cache = null;

    /** Load a user by ID, returns User or null. */
    public static function getUserById($userId)
    {
        global $conn;
        $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return null;
        $user = new self();
        foreach ($row as $key => $value) {
            if (property_exists($user, $key)) $user->$key = $value;
        }
        $user->loadRoles();
        return $user;
    }

    /** Authenticate with username/email + password. Returns User or null. */
    public static function authenticate($identifier, $password)
    {
        global $conn;
        $stmt = $conn->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1");
        $stmt->bind_param('ss', $identifier, $identifier);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || !password_verify($password, $row['password_hash'])) {
            return null;
        }
        $user = new self();
        foreach ($row as $key => $value) {
            if (property_exists($user, $key)) $user->$key = $value;
        }
        $user->loadRoles();
        $upd = $conn->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
        $upd->bind_param('i', $user->user_id);
        $upd->execute();
        $upd->close();
        return $user;
    }

    private function loadRoles()
    {
        global $conn;
        $stmt = $conn->prepare(
            "SELECT r.role_id, r.name FROM user_roles ur
             JOIN roles r ON r.role_id = ur.role_id
             WHERE ur.user_id = ? AND ur.tenant_id = ?"
        );
        $stmt->bind_param('ii', $this->user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $this->roles = [];
        while ($row = $result->fetch_assoc()) {
            $this->roles[] = $row['name'];
        }
        $stmt->close();
    }

    /** Check whether the user has a given permission. */
    public function hasPermission($permission)
    {
        global $conn;
        if ($this->permission_cache === null) {
            $roleIds = [];
            $stmt = $conn->prepare("SELECT role_id FROM user_roles WHERE user_id = ? AND tenant_id = ?");
            $stmt->bind_param('ii', $this->user_id, $this->tenant_id);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $roleIds[] = (int)$row['role_id'];
            }
            $stmt->close();
            $this->permission_cache = [];
            if ($roleIds) {
                $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
                $sql = "SELECT DISTINCT p.name FROM permissions p
                        JOIN role_permissions rp ON rp.permission_id = p.permission_id
                        WHERE rp.role_id IN ($placeholders)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param(str_repeat('i', count($roleIds)), ...$roleIds);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $this->permission_cache[] = $row['name'];
                }
                $stmt->close();
            }
        }
        return in_array($permission, $this->permission_cache, true);
    }

    public function fullName()
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    public function isAdmin()
    {
        return $this->hasPermission('manage_users')
            || $this->hasPermission('view_reports')
            || $this->hasPermission('manage_settings');
    }
}
