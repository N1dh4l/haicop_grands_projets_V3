<?php
require_once '../Config/Database.php';
require_once '../Config/Security.php';
require_once '../Config/Permissions.php';

Security::startSecureSession();
Security::requireLogin();

// Vérifier les permissions
if (!Permissions::canEditProjet($_SESSION['user_id'])) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لحذف الجلسات';
    header('Location: commissions.php');
    exit;
}

$database = new Database();
$db = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validation CSRF
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception('Token de sécurité invalide');
        }
        
        $idCom = isset($_POST['idCom']) ? intval($_POST['idCom']) : 0;
        
        if ($idCom <= 0) {
            throw new Exception('معرف الجلسة غير صالح');
        }
        
        // Vérifier que la commission existe
        $checkQuery = "SELECT numCommission FROM commission WHERE idCom = :idCom";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':idCom', $idCom);
        $checkStmt->execute();
        $commission = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$commission) {
            throw new Exception('الجلسة غير موجودة');
        }
        
        // Commencer la transaction
        $db->beginTransaction();
        
        // Récupérer les documents liés (mahdar et qarar)
        $docsQuery = "SELECT cheminAcces FROM document WHERE type IN (1, 2) AND idExterne = :idCom";
        $docsStmt = $db->prepare($docsQuery);
        $docsStmt->bindParam(':idCom', $idCom);
        $docsStmt->execute();
        $documents = $docsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Supprimer les fichiers physiques
        foreach ($documents as $doc) {
            if (file_exists($doc['cheminAcces'])) {
                unlink($doc['cheminAcces']);
            }
        }
        
        // Supprimer les documents de la base
        $deleteDocsQuery = "DELETE FROM document WHERE type IN (1, 2) AND idExterne = :idCom";
        $deleteDocsStmt = $db->prepare($deleteDocsQuery);
        $deleteDocsStmt->bindParam(':idCom', $idCom);
        $deleteDocsStmt->execute();
        
        // Supprimer les relations projetcommission
        $deleteProjetsQuery = "DELETE FROM projetcommission WHERE idCom = :idCom";
        $deleteProjetsStmt = $db->prepare($deleteProjetsQuery);
        $deleteProjetsStmt->bindParam(':idCom', $idCom);
        $deleteProjetsStmt->execute();
        
        // Supprimer la commission
        $deleteCommissionQuery = "DELETE FROM commission WHERE idCom = :idCom";
        $deleteCommissionStmt = $db->prepare($deleteCommissionQuery);
        $deleteCommissionStmt->bindParam(':idCom', $idCom);
        $deleteCommissionStmt->execute();
        
        // Journal
        $action = "حذف الجلسة رقم " . $commission['numCommission'];
        $idUser = $_SESSION['user_id'] ?? 0;
        $queryJournal = "INSERT INTO journal (idUser, action, date) VALUES (:idUser, :action, NOW())";
        $stmtJournal = $db->prepare($queryJournal);
        $stmtJournal->bindParam(':idUser', $idUser);
        $stmtJournal->bindParam(':action', $action);
        $stmtJournal->execute();
        
        $db->commit();
        
        $_SESSION['success_message'] = 'تم حذف الجلسة بنجاح';
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['error_message'] = $e->getMessage();
    }
}

header('Location: commissions.php');
exit;
?>