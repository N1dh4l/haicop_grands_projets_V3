<?php
require_once '../Config/Database.php';
require_once '../Config/Security.php';

Security::startSecureSession();
Security::requireLogin();

header('Content-Type: application/json; charset=utf-8');

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $sql = "SELECT idFour as idFournisseur, nomFour as nomFournisseur 
            FROM fournisseur 
            ORDER BY nomFour";
    
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $fournisseurs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'fournisseurs' => $fournisseurs
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ في تحميل الموردين'
    ], JSON_UNESCAPED_UNICODE);
}