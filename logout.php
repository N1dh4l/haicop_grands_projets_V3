<?php
require_once 'Config/Security.php';

Security::startSecureSession();

// Log action avant déconnexion
if (isset($_SESSION['user_id'])) {
    require_once 'Config/Database.php';
    require_once 'Models/User.php';
    
    $database = new Database();
    $db = $database->getConnection();
    $user = new User($db);
    $user->idUser = $_SESSION['user_id'];
    $user->logAction("تسجيل الخروج");
}

Security::logout();
?>