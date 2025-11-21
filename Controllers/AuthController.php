<?php
/**
 * Contrôleur d'authentification
 * Gère les opérations de connexion et déconnexion
 */
class AuthController {
    private $db;
    private $user;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->user = new User($this->db);
    }
    
    /**
     * Gérer la connexion utilisateur
     */
    public function login($login, $password, $csrf_token) {
        // Valider le token CSRF
        if (!Security::validateCSRFToken($csrf_token)) {
            return array(
                'success' => false,
                'message' => 'خطأ في التحقق من الأمان. يرجى المحاولة مرة أخرى.'
            );
        }
        
        // Nettoyer les entrées
        $login = Security::sanitizeInput($login);
        $password = Security::sanitizeInput($password);
        
        // Validation basique
        if (empty($login) || empty($password)) {
            return array(
                'success' => false,
                'message' => 'يرجى إدخال اسم المستخدم وكلمة المرور'
            );
        }
        
        // Tentative d'authentification
        if ($this->user->authenticate($login, $password)) {
            // Succès - Créer la session
            $_SESSION['user_id'] = $this->user->idUser;
            $_SESSION['user_name'] = $this->user->nomUser;
            $_SESSION['user_email'] = $this->user->emailUser;
            $_SESSION['user_type'] = $this->user->typeCpt;
            $_SESSION['user_token'] = bin2hex(random_bytes(32));
            $_SESSION['last_activity'] = time();
            
            // Enregistrer l'action dans le journal
            $this->user->logAction("تسجيل الدخول");
            
            return array(
                'success' => true,
                'message' => 'تم تسجيل الدخول بنجاح'
            );
        } else {
            // Échec - Incrémenter tentatives échouées
            $this->incrementFailedAttempts();
            
            return array(
                'success' => false,
                'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة'
            );
        }
    }
    
    /**
     * Gérer les tentatives échouées (prévention brute force)
     */
    private function incrementFailedAttempts() {
        if (!isset($_SESSION['login_attempts'])) {
            $_SESSION['login_attempts'] = 0;
        }
        $_SESSION['login_attempts']++;
        
        // Bloquer après 5 tentatives
        if ($_SESSION['login_attempts'] >= 5) {
            $_SESSION['login_blocked_until'] = time() + 900; // 15 minutes
        }
    }
    
    /**
     * Vérifier si l'utilisateur est bloqué
     */
    public function isBlocked() {
        if (isset($_SESSION['login_blocked_until'])) {
            if (time() < $_SESSION['login_blocked_until']) {
                return true;
            } else {
                // Débloquer
                unset($_SESSION['login_blocked_until']);
                unset($_SESSION['login_attempts']);
                return false;
            }
        }
        return false;
    }
}
?>