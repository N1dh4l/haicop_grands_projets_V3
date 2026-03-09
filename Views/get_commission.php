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
    
    // Récupérer les projets associés à CETTE commission
    $sqlProjets = "SELECT pc.idPro, pc.naturePc, p.sujet
                   FROM projetcommission pc
                   JOIN projet p ON pc.idPro = p.idPro
                   WHERE pc.idCom = :idCom
                   ORDER BY p.sujet";
    $stmtProjets = $db->prepare($sqlProjets);
    $stmtProjets->bindParam(':idCom', $idCom);
    $stmtProjets->execute();
    $projets = $stmtProjets->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer tous les projets disponibles pour le select :
    // états éligibles (2, 3) + projets déjà liés à cette commission
    $sqlAllProjets = "SELECT DISTINCT p.idPro, p.sujet 
                      FROM projet p
                      WHERE p.etat IN (2, 3)
                         OR p.idPro IN (
                             SELECT pc2.idPro FROM projetcommission pc2 WHERE pc2.idCom = :idCom2
                         )
                      ORDER BY p.idPro DESC";
    $stmtAllProjets = $db->prepare($sqlAllProjets);
    $stmtAllProjets->bindParam(':idCom2', $idCom);
    $stmtAllProjets->execute();
    $allProjets = $stmtAllProjets->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer محضر الجلسة (type = 25)
    $sqlMahdar = "SELECT idDoc, libDoc, cheminAcces FROM document 
                  WHERE type = 25 AND idExterne = :idCom LIMIT 1";
    $stmtMahdar = $db->prepare($sqlMahdar);
    $stmtMahdar->bindParam(':idCom', $idCom);
    $stmtMahdar->execute();
    $mahdar = $stmtMahdar->fetch(PDO::FETCH_ASSOC);
    
    $commission['mahdarPath']    = $mahdar ? $mahdar['cheminAcces'] : null;
    $commission['mahdarLibelle'] = $mahdar ? $mahdar['libDoc']      : null;
    $commission['mahdarId']      = $mahdar ? $mahdar['idDoc']       : null;
    
    // Récupérer قرار اللجنة (type = 26)
    $sqlQarar = "SELECT idDoc, libDoc, cheminAcces FROM document 
                 WHERE type = 26 AND idExterne = :idCom LIMIT 1";
    $stmtQarar = $db->prepare($sqlQarar);
    $stmtQarar->bindParam(':idCom', $idCom);
    $stmtQarar->execute();
    $qarar = $stmtQarar->fetch(PDO::FETCH_ASSOC);
    
    $commission['qararPath']    = $qarar ? $qarar['cheminAcces'] : null;
    $commission['qararLibelle'] = $qarar ? $qarar['libDoc']      : null;
    $commission['qararId']      = $qarar ? $qarar['idDoc']       : null;
    
    echo json_encode([
        'success'    => true,
        'commission' => $commission,
        'projets'    => $projets,
        'allProjets' => $allProjets
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>