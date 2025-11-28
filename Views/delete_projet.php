<?php
    require_once '../Config/Database.php';
    require_once '../Config/Security.php';
    require_once '../Config/Permissions.php';

    Security::startSecureSession();
    Security::requireLogin();

    header('Content-Type: application/json; charset=utf-8');

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_projet') {
        
        // Validation CSRF
        if (!Security::validateCSRFToken($_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'خطأ في التحقق من الأمان'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        
        $projetId = intval($_POST['projetId']);
        
        if ($projetId <= 0) {
            echo json_encode(['success' => false, 'message' => 'معرف المقترح غير صالح'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            // Vérifier que le projet existe et récupérer les infos
            $sqlCheck = "SELECT idUser, sujet FROM projet WHERE idPro = :projetId";
            $stmtCheck = $db->prepare($sqlCheck);
            $stmtCheck->bindParam(':projetId', $projetId, PDO::PARAM_INT);
            $stmtCheck->execute();
            $projet = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            
            if (!$projet) {
                echo json_encode(['success' => false, 'message' => 'المقترح غير موجود'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            // Vérifier les permissions
            if (!Permissions::canDeleteProjet($projet['idUser'])) {
                echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية لحذف هذا المقترح'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            $db->beginTransaction();
            
            // Récupérer les documents associés pour les supprimer physiquement
            $sqlDocs = "SELECT cheminAcces FROM document WHERE idPro = :projetId";
            $stmtDocs = $db->prepare($sqlDocs);
            $stmtDocs->bindParam(':projetId', $projetId, PDO::PARAM_INT);
            $stmtDocs->execute();
            $documents = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);
            
            // Supprimer les fichiers physiques
            foreach ($documents as $doc) {
                $filePath = dirname(__DIR__) . '/' . $doc['cheminAcces'];
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }
            
            // Supprimer les documents de la BD (CASCADE devrait le faire automatiquement)
            $sqlDeleteDocs = "DELETE FROM document WHERE idPro = :projetId";
            $stmtDeleteDocs = $db->prepare($sqlDeleteDocs);
            $stmtDeleteDocs->bindParam(':projetId', $projetId, PDO::PARAM_INT);
            $stmtDeleteDocs->execute();
            
            // Supprimer le projet
            $sqlDelete = "DELETE FROM projet WHERE idPro = :projetId";
            $stmtDelete = $db->prepare($sqlDelete);
            $stmtDelete->bindParam(':projetId', $projetId, PDO::PARAM_INT);
            $stmtDelete->execute();
            
            // Logger l'action
            $logSql = "INSERT INTO journal (idUser, action, date) VALUES (:idUser, :action, CURDATE())";
            $logStmt = $db->prepare($logSql);
            $logStmt->bindParam(':idUser', $_SESSION['user_id']);
            $action = "حذف المقترح رقم " . $projetId . ": " . substr($projet['sujet'], 0, 50);
            $logStmt->bindParam(':action', $action);
            $logStmt->execute();
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'تم حذف المقترح بنجاح'
            ], JSON_UNESCAPED_UNICODE);
            
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            echo json_encode([
                'success' => false,
                'message' => 'حدث خطأ أثناء حذف المقترح: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
        
    } else {
        echo json_encode(['success' => false, 'message' => 'طلب غير صالح'], JSON_UNESCAPED_UNICODE);
    }
    exit();
?>