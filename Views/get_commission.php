<?php

ob_start();
require_once '../Config/Database.php';
require_once '../Config/Security.php';
require_once '../Config/Permissions.php';

Security::startSecureSession();
Security::requireLogin();

header('Content-Type: application/json; charset=utf-8');

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Récupérer l'ID de la commission
    $idCom = isset($_GET['idCom']) ? intval($_GET['idCom']) : 0;
    
    if ($idCom <= 0) {
        throw new Exception('معرف الجلسة غير صالح');
    }
    
    // Récupérer les informations de base de la commission
    $sqlCommission = "SELECT idCom, numCommission, dateCommission FROM commission WHERE idCom = :idCom";
    $stmtCommission = $db->prepare($sqlCommission);
    $stmtCommission->bindParam(':idCom', $idCom);
    $stmtCommission->execute();
    $commission = $stmtCommission->fetch(PDO::FETCH_ASSOC);
    
    if (!$commission) {
        throw new Exception('الجلسة غير موجودة');
    }
    
    // Récupérer les projets associés
    $sqlProjets = "SELECT pc.idPro, pc.naturePc, p.sujet
                   FROM projetcommission pc
                   JOIN projet p ON pc.idPro = p.idPro
                   WHERE pc.idCom = :idCom
                   ORDER BY p.sujet";
    $stmtProjets = $db->prepare($sqlProjets);
    $stmtProjets->bindParam(':idCom', $idCom);
    $stmtProjets->execute();
    $projets = $stmtProjets->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer le محضر
    $sqlMahdar = "SELECT idDoc, libDoc, cheminAcces FROM document 
                  WHERE type = 1 AND idExterne = :idCom LIMIT 1";
    $stmtMahdar = $db->prepare($sqlMahdar);
    $stmtMahdar->bindParam(':idCom', $idCom);
    $stmtMahdar->execute();
    $mahdar = $stmtMahdar->fetch(PDO::FETCH_ASSOC);
    
    $commission['mahdarPath'] = $mahdar ? $mahdar['cheminAcces'] : null;
    $commission['mahdarLibelle'] = $mahdar ? $mahdar['libDoc'] : null;
    $commission['mahdarId'] = $mahdar ? $mahdar['idDoc'] : null;
    
    echo json_encode([
        'success' => true,
        'commission' => $commission,
        'projets' => $projets
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>