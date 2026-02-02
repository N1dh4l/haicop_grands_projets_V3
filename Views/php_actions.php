<?php
/**
 * Code PHP à ajouter dans appels_d_offres.php
 * Ce code gère les actions de mise à jour et suppression
 */

// ================================================================
// ACTION: MISE À JOUR D'UN APPEL D'OFFRE
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_appel_offre') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        // 1. Validation CSRF
        if (!Security::validateCSRFToken($_POST['csrf_token'])) {
            echo json_encode([
                'success' => false,
                'message' => 'خطأ في التحقق من الأمان'
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }
        
        // 2. Récupération et validation des données
        $idAppel = intval($_POST['idApp']);
        $idProjet = intval($_POST['idpro']);
        
        if ($idAppel <= 0 || $idProjet <= 0) {
            echo json_encode([
                'success' => false,
                'message' => 'بيانات غير صحيحة'
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }
        
        // 3. Vérifier que l'appel d'offre existe et récupérer les infos
        $sqlCheck = "SELECT ao.*, p.idUser 
                     FROM appeloffre ao
                     INNER JOIN projet p ON ao.idPro = p.idPro
                     WHERE ao.idApp = :idApp";
        $stmtCheck = $db->prepare($sqlCheck);
        $stmtCheck->bindParam(':idApp', $idAppel, PDO::PARAM_INT);
        $stmtCheck->execute();
        $appelOffre = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        
        if (!$appelOffre) {
            echo json_encode([
                'success' => false,
                'message' => 'الصفقة غير موجودة'
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }
        
        // 4. Vérifier les permissions
        if (!Permissions::canEditProjet($appelOffre['idUser'])) {
            echo json_encode([
                'success' => false,
                'message' => 'ليس لديك صلاحية لتعديل هذه الصفقة'
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }
        
        // 5. Valider les lots
        if (!isset($_POST['lots']) || empty($_POST['lots'])) {
            echo json_encode([
                'success' => false,
                'message' => 'يجب إضافة قسط واحد على الأقل'
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }
        
        // 6. Gestion du fichier (optionnel)
        $nouveauFichier = null;
        $ancienFichier = null;
        
        if (isset($_FILES['fichier']) && $_FILES['fichier']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['fichier'];
            $fileSize = $file['size'];
            $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            // Vérifier la taille (max 10MB)
            if ($fileSize > 10485760) {
                echo json_encode([
                    'success' => false,
                    'message' => 'حجم الملف يجب أن يكون أقل من 10 ميغابايت'
                ], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            // Vérifier l'extension
            $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];
            if (!in_array($fileExtension, $allowedExtensions)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'نوع الملف غير مقبول. يرجى اختيار ملف PDF أو Word أو Excel'
                ], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            // Récupérer l'ancien fichier
            $sqlOldDoc = "SELECT cheminAcces FROM document 
                          WHERE idExterne = :idApp AND type = 30";
            $stmtOldDoc = $db->prepare($sqlOldDoc);
            $stmtOldDoc->bindParam(':idApp', $idAppel, PDO::PARAM_INT);
            $stmtOldDoc->execute();
            $oldDoc = $stmtOldDoc->fetch(PDO::FETCH_ASSOC);
            if ($oldDoc) {
                $ancienFichier = $oldDoc['cheminAcces'];
            }
            
            // Créer le nom du fichier sécurisé
            $uploadDir = '../uploads/appels_offres/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $newFileName = 'appel_offre_' . $idProjet . '_' . time() . '.' . $fileExtension;
            $nouveauFichier = $uploadDir . $newFileName;
            
            // Déplacer le fichier
            if (!move_uploaded_file($file['tmp_name'], $nouveauFichier)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'فشل تحميل الملف'
                ], JSON_UNESCAPED_UNICODE);
                exit();
            }
        }
        
        // 7. Début de la transaction
        $db->beginTransaction();
        
        try {
            // 8. Mettre à jour l'appel d'offre
            $sqlUpdate = "UPDATE appeloffre SET idPro = :idPro WHERE idApp = :idApp";
            $stmtUpdate = $db->prepare($sqlUpdate);
            $stmtUpdate->bindParam(':idPro', $idProjet, PDO::PARAM_INT);
            $stmtUpdate->bindParam(':idApp', $idAppel, PDO::PARAM_INT);
            $stmtUpdate->execute();
            
            // 9. Mettre à jour le document si un nouveau fichier a été uploadé
            if ($nouveauFichier) {
                $sqlUpdateDoc = "UPDATE document 
                                 SET cheminAcces = :cheminAcces 
                                 WHERE idExterne = :idApp AND type = 30";
                $stmtUpdateDoc = $db->prepare($sqlUpdateDoc);
                $stmtUpdateDoc->bindParam(':cheminAcces', $nouveauFichier);
                $stmtUpdateDoc->bindParam(':idApp', $idAppel, PDO::PARAM_INT);
                $stmtUpdateDoc->execute();
            }
            
            // 10. Supprimer les anciens lots
            $sqlDeleteLots = "DELETE FROM lot WHERE idAppelOffre = :idApp";
            $stmtDeleteLots = $db->prepare($sqlDeleteLots);
            $stmtDeleteLots->bindParam(':idApp', $idAppel, PDO::PARAM_INT);
            $stmtDeleteLots->execute();
            
            // 11. Insérer les nouveaux lots
            $sqlLot = "INSERT INTO lot (sujetLot, idFournisseur, somme, idAppelOffre) 
                       VALUES (:sujetLot, :idFournisseur, :somme, :idAppelOffre)";
            $stmtLot = $db->prepare($sqlLot);
            
            $lotsCount = 0;
            foreach ($_POST['lots'] as $lot) {
                $sujetLot = Security::sanitizeInput($lot['sujetLot']);
                $idFournisseur = intval($lot['idFournisseur']);
                $somme = floatval($lot['somme']);
                
                // Validation
                if (empty($sujetLot) || $idFournisseur <= 0 || $somme < 0) {
                    throw new Exception('معلومات القسط غير صحيحة');
                }
                
                $stmtLot->bindParam(':sujetLot', $sujetLot);
                $stmtLot->bindParam(':idFournisseur', $idFournisseur, PDO::PARAM_INT);
                $stmtLot->bindParam(':somme', $somme);
                $stmtLot->bindParam(':idAppelOffre', $idAppel, PDO::PARAM_INT);
                $stmtLot->execute();
                $lotsCount++;
            }
            
            // 12. Logger l'action
            $logSql = "INSERT INTO journal (idUser, action, date) 
                       VALUES (:idUser, :action, CURDATE())";
            $logStmt = $db->prepare($logSql);
            $logStmt->bindParam(':idUser', $_SESSION['user_id']);
            $action = "تعديل الصفقة رقم {$idAppel} مع {$lotsCount} أقساط";
            $logStmt->bindParam(':action', $action);
            $logStmt->execute();
            
            // 13. Commit de la transaction
            $db->commit();
            
            // 14. Supprimer l'ancien fichier si un nouveau a été uploadé
            if ($ancienFichier && file_exists($ancienFichier) && $nouveauFichier) {
                unlink($ancienFichier);
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'تم تعديل الصفقة بنجاح'
            ], JSON_UNESCAPED_UNICODE);
            
        } catch (PDOException $e) {
            $db->rollBack();
            // Supprimer le nouveau fichier en cas d'erreur
            if ($nouveauFichier && file_exists($nouveauFichier)) {
                unlink($nouveauFichier);
            }
            throw $e;
        }
        
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Erreur update appel offre: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'حدث خطأ: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit();
}

// ================================================================
// ACTION: SUPPRESSION D'UN APPEL D'OFFRE
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_appel_offre') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        // 1. Validation CSRF
        if (!Security::validateCSRFToken($_POST['csrf_token'])) {
            echo json_encode([
                'success' => false,
                'message' => 'خطأ في التحقق من الأمان'
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }
        
        // 2. Récupération de l'ID
        $idAppel = intval($_POST['idApp']);
        
        if ($idAppel <= 0) {
            echo json_encode([
                'success' => false,
                'message' => 'معرف غير صحيح'
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }
        
        // 3. Vérifier que l'appel d'offre existe
        $sqlCheck = "SELECT ao.*, p.idUser 
                     FROM appeloffre ao
                     INNER JOIN projet p ON ao.idPro = p.idPro
                     WHERE ao.idApp = :idApp";
        $stmtCheck = $db->prepare($sqlCheck);
        $stmtCheck->bindParam(':idApp', $idAppel, PDO::PARAM_INT);
        $stmtCheck->execute();
        $appelOffre = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        
        if (!$appelOffre) {
            echo json_encode([
                'success' => false,
                'message' => 'الصفقة غير موجودة'
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }
        
        // 4. Vérifier les permissions
        if (!Permissions::canEditProjet($appelOffre['idUser'])) {
            echo json_encode([
                'success' => false,
                'message' => 'ليس لديك صلاحية لحذف هذه الصفقة'
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }
        
        // 5. Début de la transaction
        $db->beginTransaction();
        
        try {
            // 6. Récupérer le chemin du document avant suppression
            $sqlDoc = "SELECT cheminAcces FROM document 
                       WHERE idExterne = :idApp AND type = 30";
            $stmtDoc = $db->prepare($sqlDoc);
            $stmtDoc->bindParam(':idApp', $idAppel, PDO::PARAM_INT);
            $stmtDoc->execute();
            $document = $stmtDoc->fetch(PDO::FETCH_ASSOC);
            
            // 7. Supprimer les lots
            $sqlDeleteLots = "DELETE FROM lot WHERE idAppelOffre = :idApp";
            $stmtDeleteLots = $db->prepare($sqlDeleteLots);
            $stmtDeleteLots->bindParam(':idApp', $idAppel, PDO::PARAM_INT);
            $stmtDeleteLots->execute();
            
            // 8. Supprimer le document de la base
            $sqlDeleteDoc = "DELETE FROM document 
                             WHERE idExterne = :idApp AND type = 30";
            $stmtDeleteDoc = $db->prepare($sqlDeleteDoc);
            $stmtDeleteDoc->bindParam(':idApp', $idAppel, PDO::PARAM_INT);
            $stmtDeleteDoc->execute();
            
            // 9. Supprimer l'appel d'offre
            $sqlDeleteAppel = "DELETE FROM appeloffre WHERE idApp = :idApp";
            $stmtDeleteAppel = $db->prepare($sqlDeleteAppel);
            $stmtDeleteAppel->bindParam(':idApp', $idAppel, PDO::PARAM_INT);
            $stmtDeleteAppel->execute();
            
            // 10. Logger l'action
            $logSql = "INSERT INTO journal (idUser, action, date) 
                       VALUES (:idUser, :action, CURDATE())";
            $logStmt = $db->prepare($logSql);
            $logStmt->bindParam(':idUser', $_SESSION['user_id']);
            $action = "حذف الصفقة رقم {$idAppel}";
            $logStmt->bindParam(':action', $action);
            $logStmt->execute();
            
            // 11. Commit de la transaction
            $db->commit();
            
            // 12. Supprimer le fichier physique après le commit
            if ($document && file_exists($document['cheminAcces'])) {
                unlink($document['cheminAcces']);
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'تم حذف الصفقة بنجاح'
            ], JSON_UNESCAPED_UNICODE);
            
        } catch (PDOException $e) {
            $db->rollBack();
            throw $e;
        }
        
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Erreur delete appel offre: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'حدث خطأ: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit();
}
?>
