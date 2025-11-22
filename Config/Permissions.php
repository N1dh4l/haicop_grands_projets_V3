<?php
/**
 * Classe de gestion des permissions
 * Gère les 4 rôles: Super Admin, Admin, Rapporteur, Observateur
 */
class Permissions {
    
    // Constantes des rôles
    const SUPER_ADMIN = 1;
    const ADMIN = 2;
    const RAPPORTEUR = 3;
    const OBSERVATEUR = 4;
    
    /**
     * Vérifier si l'utilisateur est Super Admin
     */
    public static function isSuperAdmin() {
        return isset($_SESSION['user_type']) && $_SESSION['user_type'] == self::SUPER_ADMIN;
    }
    
    /**
     * Vérifier si l'utilisateur est Admin
     */
    public static function isAdmin() {
        return isset($_SESSION['user_type']) && $_SESSION['user_type'] == self::ADMIN;
    }
    
    /**
     * Vérifier si l'utilisateur est Rapporteur
     */
    public static function isRapporteur() {
        return isset($_SESSION['user_type']) && $_SESSION['user_type'] == self::RAPPORTEUR;
    }
    
    /**
     * Vérifier si l'utilisateur est Observateur
     */
    public static function isObservateur() {
        return isset($_SESSION['user_type']) && $_SESSION['user_type'] == self::OBSERVATEUR;
    }
    
    /**
     * Vérifier si l'utilisateur peut créer des projets
     */
    public static function canCreateProjet() {
        $type = $_SESSION['user_type'] ?? 4;
        return in_array($type, [self::SUPER_ADMIN, self::ADMIN, self::RAPPORTEUR]);
    }
    
    /**
     * Vérifier si l'utilisateur peut modifier un projet
     * @param int $projetUserId - ID du créateur du projet
     */
    public static function canEditProjet($projetUserId) {
        $type = $_SESSION['user_type'] ?? 4;
        $userId = $_SESSION['user_id'] ?? 0;
        
        // Super Admin peut tout modifier
        if ($type == self::SUPER_ADMIN) {
            return true;
        }
        
        // Admin et Rapporteur peuvent modifier uniquement leurs projets
        if (in_array($type, [self::ADMIN, self::RAPPORTEUR])) {
            return $projetUserId == $userId;
        }
        
        // Observateur ne peut rien modifier
        return false;
    }
    
    /**
     * Vérifier si l'utilisateur peut supprimer un projet
     * @param int $projetUserId - ID du créateur du projet
     */
    public static function canDeleteProjet($projetUserId) {
        return self::canEditProjet($projetUserId);
    }
    
    /**
     * Vérifier si l'utilisateur peut voir un projet
     * @param int $projetUserId - ID du créateur du projet
     */
    public static function canViewProjet($projetUserId) {
        $type = $_SESSION['user_type'] ?? 4;
        $userId = $_SESSION['user_id'] ?? 0;
        
        // Super Admin, Admin et Observateur peuvent voir tous les projets
        if (in_array($type, [self::SUPER_ADMIN, self::ADMIN, self::OBSERVATEUR])) {
            return true;
        }
        
        // Rapporteur peut voir uniquement ses projets
        if ($type == self::RAPPORTEUR) {
            return $projetUserId == $userId;
        }
        
        return false;
    }
    
    /**
     * Vérifier si l'utilisateur peut accéder à l'administration
     */
    public static function canAccessAdmin() {
        $type = $_SESSION['user_type'] ?? 4;
        return in_array($type, [self::SUPER_ADMIN, self::ADMIN]);
    }
    
    /**
     * Vérifier si l'utilisateur peut gérer les utilisateurs
     */
    public static function canManageUsers() {
        return self::isSuperAdmin();
    }
    
    /**
     * Obtenir le nom du rôle
     */
    public static function getRoleName($type) {
        $roles = [
            self::SUPER_ADMIN => 'مدير عام',
            self::ADMIN => 'مدير',
            self::RAPPORTEUR => 'مقرر',
            self::OBSERVATEUR => 'مراقب'
        ];
        
        return $roles[$type] ?? 'غير معروف';
    }
    
    /**
     * Obtenir la condition SQL pour filtrer les projets selon le rôle
     */
    public static function getProjectsWhereClause() {
        $type = $_SESSION['user_type'] ?? 4;
        $userId = $_SESSION['user_id'] ?? 0;
        
        // Rapporteur voit uniquement ses projets
        if ($type == self::RAPPORTEUR) {
            return " AND p.idUser = {$userId}";
        }
        
        // Les autres voient tous les projets
        return "";
    }
    
    /**
     * Rediriger si pas de permission
     */
    public static function requirePermission($permission) {
        if (!$permission) {
            header("Location: accueil.php?error=no_permission");
            exit();
        }
    }
}
?>