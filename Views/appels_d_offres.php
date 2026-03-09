<?php
    ob_start();
    require_once '../Config/Database.php';
    require_once '../Config/Security.php';
    require_once '../Config/Permissions.php';

    Security::startSecureSession();
    Security::requireLogin();

    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
        Security::logout();
    }
    $_SESSION['last_activity'] = time();

    $database = new Database();
    $db = $database->getConnection();

    // ==========================================
    // TRAITEMENT AJAX - AJOUT D'APPEL D'OFFRE
    // ==========================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_appel_offre') {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            // 1. Validation CSRF
            if (!Security::validateCSRFToken($_POST['csrf_token'])) {
                echo json_encode(['success' => false, 'message' => 'خطأ في التحقق من الأمان'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            // 2. Récupération des données
            $idProjet = intval($_POST['idpro']);
            
            // 3. Vérifier que le projet existe
            $sqlCheck = "SELECT idUser FROM projet WHERE idPro = :idProjet";
            $stmtCheck = $db->prepare($sqlCheck);
            $stmtCheck->bindParam(':idProjet', $idProjet, PDO::PARAM_INT);
            $stmtCheck->execute();
            $projetCheck = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            
            if (!$projetCheck) {
                echo json_encode(['success' => false, 'message' => 'المشروع غير موجود'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            // 4. Vérifier les permissions
            if (!Permissions::canEditProjet($projetCheck['idUser'])) {
                echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية لإضافة صفقات'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            // 5. Valider qu'il y a au moins un lot
            if (!isset($_POST['lots']) || empty($_POST['lots'])) {
                echo json_encode(['success' => false, 'message' => 'يجب إضافة صفقة واحدة على الأقل'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            // 6. Validation du fichier
            $uploadError = null;
            $cheminDocument = null;
            
            if (isset($_FILES['fichier']) && $_FILES['fichier']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['fichier'];
                $fileName = $file['name'];
                $fileTmpName = $file['tmp_name'];
                $fileSize = $file['size'];
                $fileError = $file['error'];
                
                // Vérifier la taille (max 10MB)
                if ($fileSize > 10485760) {
                    echo json_encode(['success' => false, 'message' => 'حجم الملف يجب أن يكون أقل من 10 ميغابايت'], JSON_UNESCAPED_UNICODE);
                    exit();
                }
                
                // Vérifier l'extension
                $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                
                if (!in_array($fileExtension, $allowedExtensions)) {
                    echo json_encode(['success' => false, 'message' => 'نوع الملف غير مقبول. يرجى اختيار ملف PDF أو Word أو Excel'], JSON_UNESCAPED_UNICODE);
                    exit();
                }
                
                // Créer le nom du fichier sécurisé
                $uploadDir = '../uploads/appels_offres/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $newFileName = 'appel_offre_' . $idProjet . '_' . time() . '.' . $fileExtension;
                $cheminDocument = $uploadDir . $newFileName;
                
                // Déplacer le fichier
                if (!move_uploaded_file($fileTmpName, $cheminDocument)) {
                    echo json_encode(['success' => false, 'message' => 'فشل تحميل الملف'], JSON_UNESCAPED_UNICODE);
                    exit();
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'يجب إرفاق ملف الإسناد'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            // 7. Début de la transaction
            $db->beginTransaction();
            
            try {
                // 8. Créer l'appel d'offre
                $sqlAppel = "INSERT INTO appeloffre (idPro, dateCreation) VALUES (:idPro, CURDATE())";
                $stmtAppel = $db->prepare($sqlAppel);
                $stmtAppel->bindParam(':idPro', $idProjet, PDO::PARAM_INT);
                $stmtAppel->execute();
                
                $idAppelOffre = $db->lastInsertId();
                
                // 9. Insérer le document dans la table document
                $sqlDoc = "INSERT INTO document (idPro, libDoc, cheminAcces, type, idExterne) 
                        VALUES (:idPro, :libDoc, :cheminAcces, 30, :idExterne)";
                $stmtDoc = $db->prepare($sqlDoc);
                $libDoc = 'ملف الإسناد';
                $stmtDoc->bindParam(':idPro', $idProjet, PDO::PARAM_INT);
                $stmtDoc->bindParam(':libDoc', $libDoc);
                $stmtDoc->bindParam(':cheminAcces', $cheminDocument);
                $stmtDoc->bindParam(':idExterne', $idAppelOffre, PDO::PARAM_INT);
                $stmtDoc->execute();
                
                // 10. Insérer les lots
                $sqlLot = "INSERT INTO lot (sujetLot, idFournisseur, somme, idAppelOffre) 
                        VALUES (:sujetLot, :idFournisseur, :somme, :idAppelOffre)";
                $stmtLot = $db->prepare($sqlLot);
                
                $lotsCount = 0;
                foreach ($_POST['lots'] as $lot) {
                    $sujetLot = Security::sanitizeInput($lot['sujetLot']);
                    $idFournisseur = intval($lot['idFournisseur']);
                    $somme = floatval($lot['somme']);
                    
                    // Validation
                    if (empty($sujetLot) || $idFournisseur <= 0 || $somme <= 0) {
                        throw new Exception('معلومات الصفقة غير صحيحة');
                    }
                    
                    $stmtLot->bindParam(':sujetLot', $sujetLot);
                    $stmtLot->bindParam(':idFournisseur', $idFournisseur, PDO::PARAM_INT);
                    $stmtLot->bindParam(':somme', $somme);
                    $stmtLot->bindParam(':idAppelOffre', $idAppelOffre, PDO::PARAM_INT);
                    $stmtLot->execute();
                    $lotsCount++;
                }
                
                // 11. Logger l'action
                $logSql = "INSERT INTO journal (idUser, action, date) VALUES (:idUser, :action, CURDATE())";
                $logStmt = $db->prepare($logSql);
                $logStmt->bindParam(':idUser', $_SESSION['user_id']);
                $action = "إضافة صفقة رقم {$idAppelOffre} للمشروع رقم {$idProjet} مع {$lotsCount} صفقات";
                $logStmt->bindParam(':action', $action);
                $logStmt->execute();
                
                $db->commit();
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'تم إضافة الصفقة بنجاح مع ' . $lotsCount . ' صفقات'
                ], JSON_UNESCAPED_UNICODE);
                
            } catch (PDOException $e) {
                $db->rollBack();
                // Supprimer le fichier uploadé en cas d'erreur
                if ($cheminDocument && file_exists($cheminDocument)) {
                    unlink($cheminDocument);
                }
                throw $e;
            }
            
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            // Supprimer le fichier uploadé en cas d'erreur
            if (isset($cheminDocument) && $cheminDocument && file_exists($cheminDocument)) {
                unlink($cheminDocument);
            }
            echo json_encode([
                'success' => false, 
                'message' => 'حدث خطأ: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    // ==========================================
    // RÉCUPÉRATION DES APPELS D'OFFRES
    // ==========================================
    $searchQuery = isset($_GET['search']) ? Security::sanitizeInput($_GET['search']) : '';
    $filterMinistere = isset($_GET['ministere']) ? Security::sanitizeInput($_GET['ministere']) : '';

    $sql = "SELECT 
                ao.idApp,
                ao.dateCreation,
                p.idUser,
                p.sujet as projetSujet,
                p.idPro,
                m.libMinistere,
                e.libEtablissement,
                COUNT(l.lidLot) as nombreLots,
                SUM(l.somme) as montantTotal,
                (SELECT cheminAcces FROM document WHERE idExterne = ao.idApp AND type = 30 LIMIT 1) as cheminDocument,
                p.etat as projetEtat
            FROM appeloffre ao
            INNER JOIN projet p ON ao.idPro = p.idPro
            LEFT JOIN ministere m ON p.idMinistere = m.idMinistere
            LEFT JOIN etablissement e ON p.idEtab = e.idEtablissement
            LEFT JOIN lot l ON ao.idApp = l.idAppelOffre
            WHERE 1=1";

    if (!empty($searchQuery)) {
        $sql .= " AND (p.sujet LIKE :search OR m.libMinistere LIKE :search)";
    }
    if (!empty($filterMinistere)) {
        $sql .= " AND p.idMinistere = :ministere";
    }

    $sql .= " GROUP BY ao.idApp ORDER BY ao.dateCreation DESC";

    $stmt = $db->prepare($sql);

    if (!empty($searchQuery)) {
        $searchParam = "%{$searchQuery}%";
        $stmt->bindParam(':search', $searchParam);
    }
    if (!empty($filterMinistere)) {
        $stmt->bindParam(':ministere', $filterMinistere);
    }

    $stmt->execute();
    $appelsOffres = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Liste des ministères
    $sqlMin = "SELECT idMinistere, libMinistere FROM ministere ORDER BY libMinistere";
    $stmtMin = $db->prepare($sqlMin);
    $stmtMin->execute();
    $ministeres = $stmtMin->fetchAll(PDO::FETCH_ASSOC);

    // Liste des projets avec naturePc = 23 (approuvés par la commission)
    $sqlProjets = "SELECT DISTINCT p.idPro, p.sujet 
                FROM projet p
                INNER JOIN projetcommission pc ON p.idPro = pc.idPro
                WHERE pc.naturePc = 23 OR pc.naturePc = 22
                ORDER BY p.sujet";
    $stmtProjets = $db->prepare($sqlProjets);
    $stmtProjets->execute();
    $projetsDisponibles = $stmtProjets->fetchAll(PDO::FETCH_ASSOC);

    $csrf_token = Security::generateCSRFToken();
    $page_title = "قائمة الصفقات - نظام إدارة المشاريع";
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
                // Vérifier si un document existe déjà
                $sqlCheckDoc = "SELECT idDoc FROM document WHERE idExterne = :idApp AND type = 30 LIMIT 1";
                $stmtCheckDoc = $db->prepare($sqlCheckDoc);
                $stmtCheckDoc->bindParam(':idApp', $idAppel, PDO::PARAM_INT);
                $stmtCheckDoc->execute();
                $existingDoc = $stmtCheckDoc->fetch(PDO::FETCH_ASSOC);
                
                if ($existingDoc) {
                    // Mettre à jour le document existant
                    $sqlUpdateDoc = "UPDATE document SET cheminAcces = :cheminAcces WHERE idExterne = :idApp AND type = 30";
                    $stmtUpdateDoc = $db->prepare($sqlUpdateDoc);
                    $stmtUpdateDoc->bindParam(':cheminAcces', $nouveauFichier);
                    $stmtUpdateDoc->bindParam(':idApp', $idAppel, PDO::PARAM_INT);
                    $stmtUpdateDoc->execute();
                } else {
                    // Insérer un nouveau document
                    $libDoc = 'ملف إسناد الصفقة رقم ' . $idAppel;
                    $sqlInsertDoc = "INSERT INTO document (idPro, libDoc, cheminAcces, type, idExterne) 
                                     VALUES (:idPro, :libDoc, :cheminAcces, 30, :idApp)";
                    $stmtInsertDoc = $db->prepare($sqlInsertDoc);
                    $stmtInsertDoc->bindParam(':idPro', $idProjet, PDO::PARAM_INT);
                    $stmtInsertDoc->bindParam(':libDoc', $libDoc);
                    $stmtInsertDoc->bindParam(':cheminAcces', $nouveauFichier);
                    $stmtInsertDoc->bindParam(':idApp', $idAppel, PDO::PARAM_INT);
                    $stmtInsertDoc->execute();
                }
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
// ACTION: GET DETAILS APPEL D'OFFRE (AJAX)
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_details') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $idApp = isset($_GET['id']) ? intval($_GET['id']) : 0;

    if ($idApp <= 0) {
        echo json_encode(['success' => false, 'message' => 'معرف غير صالح'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    try {
        $sqlA = "SELECT ao.idApp, ao.dateCreation,
                        p.sujet,
                        m.libMinistere,
                        e.libEtablissement
                 FROM appeloffre ao
                 INNER JOIN projet p        ON ao.idPro = p.idPro
                 LEFT JOIN  ministere m     ON p.idMinistere = m.idMinistere
                 LEFT JOIN  etablissement e ON p.idEtab = e.idEtablissement
                 WHERE ao.idApp = :idApp";
        $stA = $db->prepare($sqlA);
        $stA->bindParam(':idApp', $idApp, PDO::PARAM_INT);
        $stA->execute();
        $appel = $stA->fetch(PDO::FETCH_ASSOC);

        if (!$appel) {
            echo json_encode(['success' => false, 'message' => 'الصفقة غير موجودة'], JSON_UNESCAPED_UNICODE);
            exit();
        }

        $sqlDoc = "SELECT idDoc, libDoc, cheminAcces FROM document
                   WHERE idExterne = :idApp AND type = 30 LIMIT 1";
        $stDoc = $db->prepare($sqlDoc);
        $stDoc->bindParam(':idApp', $idApp, PDO::PARAM_INT);
        $stDoc->execute();
        $document = $stDoc->fetch(PDO::FETCH_ASSOC);

        $sqlLots = "SELECT l.sujetLot, l.somme, f.nomFour
                    FROM lot l
                    INNER JOIN fournisseur f ON f.idFour = l.idFournisseur
                    WHERE l.idAppelOffre = :idApp
                    ORDER BY l.lidLot";
        $stLots = $db->prepare($sqlLots);
        $stLots->bindParam(':idApp', $idApp, PDO::PARAM_INT);
        $stLots->execute();
        $lots = $stLots->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'  => true,
            'appel'    => $appel,
            'document' => $document ?: null,
            'lots'     => $lots
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
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
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .filters-section {
            background: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .filter-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        .filter-group input, .filter-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
        }
        .filter-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
        }
        .btn-sm {
            padding: 7px 16px !important;
            font-size: 12px !important;
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-secondary {
            background: #f5f7fa;
            color: #333;
        }
        .btn-success {
            background: #4caf50;
            color: white;
        }
        .btn-success:hover {
            background: #45a049;
            transform: translateY(-2px);
        }
        .projects-table {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        th, td {
            padding: 15px;
            text-align: center;
        }
        td {
            border-bottom: 1px solid #f0f0f0;
        }
        tbody tr:hover {
            background: #f8f9fa;
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 11px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            font-family: inherit;
            border: none;
            cursor: pointer;
            text-decoration: none;
            margin: 0 2px;
            transition: filter 0.15s, transform 0.15s, box-shadow 0.15s;
            box-shadow: 0 2px 5px rgba(0,0,0,0.16);
            letter-spacing: 0.3px;
        }
        .btn-action:hover {
            filter: brightness(1.1);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.25);
        }
        .btn-action:active { transform: translateY(0); filter: brightness(0.95); }
        .btn-add-file { background: linear-gradient(135deg, #26c6da 0%, #00838f 100%); color: white; }
        .btn-edit   { background: linear-gradient(135deg, #ffca28 0%, #f9a825 100%); color: #4a2c00; }
        .btn-delete { background: linear-gradient(135deg, #ef5350 0%, #b71c1c 100%); color: white; }

        /* MODAL */
        .modal {
            display: none;
            position: fixed;
            z-index: 99999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.7);
        }
        .modal.show {
            display: block !important;
            animation: fadeIn 0.3s;
        }
        .modal-content {
            background-color: white;
            margin: 2% auto;
            border-radius: 15px;
            width: 90%;
            max-width: 900px;
            box-shadow: 0 10px 50px rgba(0,0,0,0.5);
            max-height: 95vh;
            overflow-y: auto;
        }
        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px 30px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .close {
            color: white;
            font-size: 35px;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
        }
        .modal-body {
            padding: 30px;
        }
        .form-group-full {
            margin-bottom: 20px;
        }
        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
        }
        .lots-table {
            width: 100%;
            margin-top: 20px;
            border-collapse: collapse;
        }
        .lots-table thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .lots-table th {
            padding: 12px;
            text-align: center;
        }
        .lots-table td {
            padding: 10px;
            border-bottom: 1px solid #e0e0e0;
        }
        .btn-add-lot {
            background: #28a745;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }
        .btn-remove-lot {
            background: #dc3545;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
        }
        .required { color: #dc3545; }

        /* MODAL DÉTAILS */
        .details-info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }
        .details-info-item {
            padding: 12px 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border-right: 4px solid #667eea;
        }
        .details-info-label {
            font-size: 12px;
            font-weight: 600;
            color: #888;
            margin-bottom: 4px;
        }
        .details-info-value {
            font-size: 15px;
            color: #333;
            font-weight: 500;
        }
        .details-lots-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .details-lots-table thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .details-lots-table th, .details-lots-table td {
            padding: 11px 14px;
            text-align: center;
            border-bottom: 1px solid #eee;
        }
        .details-lots-table .total-row {
            background: #f0f0f5;
            font-weight: bold;
        }
        .details-section-title {
            font-size: 17px;
            font-weight: 700;
            color: #667eea;
            margin: 20px 0 10px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e0e0f0;
        }
        .details-loading {
            text-align: center;
            padding: 50px;
            color: #888;
            font-size: 16px;
        }

        .admin-header {
            background: linear-gradient(135deg, #4a90e2 0%, #357abd 100%);
            color: white;
            padding: 40px;
            border-radius: 15px;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .admin-header h2 {
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .admin-header p {
            font-size: 16px;
            opacity: 0.9;
        }

        /* ===== BOUTONS EXPORT ===== */
        .btn-export {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 11px;
            border: none;
            border-radius: 5px;
            font-weight: 600;
            font-size: 11px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            font-family: Arial, sans-serif;
        }
        .btn-export:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        .btn-export:active { transform: translateY(0); }
        .btn-export-excel {
            background: linear-gradient(135deg, #217346 0%, #2d9a5a 100%);
            color: white;
        }
        .btn-export-excel:hover {
            background: linear-gradient(135deg, #1a5c37 0%, #257d4b 100%);
        }
        .btn-export-word {
            background: linear-gradient(135deg, #2b579a 0%, #3d6fc4 100%);
            color: white;
        }
        .btn-export-word:hover {
            background: linear-gradient(135deg, #1f3f6d 0%, #2d5294 100%);
        }
        .btn-export-pdf {
            background: linear-gradient(135deg, #d32f2f 0%, #f44336 100%);
            color: white;
        }
        .btn-export-pdf:hover {
            background: linear-gradient(135deg, #a82424 0%, #d32f2f 100%);
        }
        .export-loading {
            position: relative;
            pointer-events: none;
            opacity: 0.7;
        }
        .export-loading::after {
            content: "";
            position: absolute;
            top: 50%; left: 50%;
            margin-left: -10px; margin-top: -10px;
            width: 20px; height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <section class="content-section" style="padding: 40px 0;">
        <div class="container">
            <div class="admin-header">
                <h2>📋 قائمة الصفقات</h2>
            </div>
            <div class="filters-section">
                <form method="GET">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label>البحث</label>
                            <input type="text" name="search" placeholder="ابحث عن صفقة..." 
                                   value="<?php echo htmlspecialchars($searchQuery); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label>الوزارة</label>
                            <select name="ministere">
                                <option value="">جميع الوزارات</option>
                                <?php foreach ($ministeres as $min): ?>
                                    <option value="<?php echo $min['idMinistere']; ?>" 
                                            <?php echo $filterMinistere == $min['idMinistere'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($min['libMinistere']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                            <button type="submit" class="btn btn-primary btn-sm">🔍 بحث</button>
                            <a href="appels_d_offres.php" class="btn btn-secondary btn-sm">🔄 إعادة تعيين</a>
                            
                    </div>
                </form>
            </div>
            
            <div class="projects-table">
                <div style="margin-bottom:18px; display:flex; justify-content:space-between; align-items:center; direction:rtl;">
                    <?php if (Permissions::canCreateProjet()): ?>
                        <button type="button" id="btnOpenModal" style="
                            display: inline-flex;
                            align-items: center;
                            gap: 9px;
                            padding: 10px 17px;
                            background: linear-gradient(135deg, #56ab2f 0%, #2d6a0f 100%);
                            color: #fff;
                            border: none;
                            border-radius: 12px;
                            font-size: 15px;
                            font-weight: 700;
                            font-family: inherit;
                            cursor: pointer;
                            
                            transition: transform 0.15s ease, box-shadow 0.15s ease;
                            letter-spacing: 0.4px;
                        "
                        onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 24px rgba(45,106,15,0.50), inset 0 1px 0 rgba(255,255,255,0.18)'"
                        onmouseout="this.style.transform='';this.style.boxShadow='0 5px 18px rgba(45,106,15,0.38), inset 0 1px 0 rgba(255,255,255,0.18)'"
                        onmousedown="this.style.transform='translateY(0)'"
                        >➕ إضافة صفقة</button>
                    <?php else: ?>
                    <div></div>
                    <?php endif; ?>
                    <div style="display:inline-flex; align-items:center; gap:7px; background:white; padding:9px 13px; border-radius:10px; box-shadow:0 2px 8px rgba(0,0,0,0.09); direction:ltr;">
                        <span style="color:#888; font-size:11px; margin-left:4px; white-space:nowrap;">📥 تحميل</span>
                        <button onclick="exportData('excel')" class="btn-export btn-export-excel" title="تصدير إلى Excel"><span>Excel</span></button>
                        <button onclick="exportData('word')"  class="btn-export btn-export-word"  title="تصدير إلى Word"><span>Word</span></button>
                        <button onclick="exportData('pdf')"   class="btn-export btn-export-pdf"   title="تصدير إلى PDF"><span>PDF</span></button>
                    </div>
                </div>
                
                <?php 
                    $grandTotal = array_sum(array_column($appelsOffres, 'montantTotal'));
                    if (count($appelsOffres) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 35%;">المشروع</th>
                                <th>الوزارة</th>
                                <th>حالة المشروع</th>
                                <th>عدد الأقساط</th>
                                <th>المبلغ الإجمالي</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($appelsOffres as $ao):?>
                            <tr>
                                <td>
                                    <span onclick="openDetailsModal(<?php echo $ao['idApp']; ?>)" 
                                          style="cursor:pointer; color:#667eea; font-weight:600; text-decoration:underline;"
                                          title="انقر لعرض التفاصيل">
                                        <?php echo htmlspecialchars($ao['projetSujet']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($ao['libMinistere']); ?></td>
                                <td>
                                    <?php if ($ao['projetEtat'] == 3): ?>
                                        <span style="background:#fff3cd; color:#856404; padding:4px 10px; border-radius:20px; font-size:12px; font-weight:600;">إسناد وقتي</span>
                                    <?php elseif ($ao['projetEtat'] == 4): ?>
                                        <span style="background:#d4edda; color:#155724; padding:4px 10px; border-radius:20px; font-size:12px; font-weight:600;">إسناد نهائي</span>
                                    <?php else: ?>
                                        <span style="background:#e2e3e5; color:#383d41; padding:4px 10px; border-radius:20px; font-size:12px; font-weight:600;"><?php echo $ao['projetEtat']; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $ao['nombreLots']; ?></td>
                                <td><?php echo number_format($ao['montantTotal'], 2); ?> مليون دينار</td>
                                <td>
                                    <?php if (!empty($ao['cheminDocument'])): ?>
                                        <a href="<?php echo htmlspecialchars($ao['cheminDocument']); ?>"
                                           target="_blank" class="btn-action btn-add-file">ملف</a>
                                    <?php endif; ?>
                                    <?php if (Permissions::canEditProjet($ao['idUser'] ?? 0)): ?>
                                        <button type="button"
                                                class="btn-action btn-edit"
                                                onclick="openEditAppelOffreModal(<?php echo $ao['idApp']; ?>)">
                                            تعديل
                                        </button>
                                        <button type="button"
                                                class="btn-action btn-delete"
                                                onclick="openDeleteAppelOffreModal(<?php echo $ao['idApp']; ?>, '<?php echo htmlspecialchars($ao['projetSujet'], ENT_QUOTES); ?>')">
                                            حذف
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php   endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                                <td colspan="3" style="padding: 14px 15px; font-weight: 700; font-size: 15px; text-align: center;">
                                    المجموع الإجمالي
                                </td>
                                <td style="padding: 14px 15px; font-weight: 700; font-size: 18px; text-align: center;">
                                    <?php echo number_format($grandTotal, 2).  'مليون دينار'; ?>  
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                <?php else: ?>
                    <p style="text-align: center; padding: 40px; color: #666;">لا توجد صفقات</p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!--  MODAL addAppelOffreModal -->
    <div id="addAppelOffreModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>إضافة صفقة جديدة</h2>
                <span class="close" id="btnCloseModal">&times;</span>
            </div>
            <div class="modal-body">
                <div id="modalAlert"></div>
                
                <form id="addAppelOffreForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="add_appel_offre">
                    
                    <div class="form-group-full">
                        <label>المشروع <span class="required">*</span></label>
                        <select name="idpro" id="idpro" class="form-control" required>
                            <option value="">-- اختر المشروع --</option>
                            <?php foreach ($projetsDisponibles as $projet): ?>
                                <option value="<?php echo $projet['idPro']; ?>">
                                    <?php echo htmlspecialchars($projet['sujet']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group-full">
                        <label>ملف الإسناد (PDF, Word, Excel) <span class="required">*</span></label>
                        <input type="file" name="fichier" id="fichier" class="form-control" 
                               accept=".pdf,.doc,.docx,.xls,.xlsx" required>
                        <small style="color: #666; font-size: 12px; display: block; margin-top: 5px;">
                            الحجم الأقصى: 10MB - الأنواع المقبولة: PDF, Word, Excel
                        </small>
                    </div>

                    <div class="lots-section">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                            <h3>📦 الصفقات</h3>
                            <button type="button" class="btn-add-lot" id="btnAddLot">➕ إضافة صفقة</button>
                        </div>

                        <table class="lots-table" id="lotsTable">
                            <thead>
                                <tr>
                                    <th>الصفقة <span class="required">*</span></th>
                                    <th>صاحب الصفقة <span class="required">*</span></th>
                                    <th>المبلغ <span class="required">*</span></th>
                                    <th>الإجراء</th>
                                </tr>
                            </thead>
                            <tbody id="lotsTableBody"></tbody>
                            <tfoot>
                                <tr style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                                    <td colspan="3" style="padding: 14px 15px; font-weight: 700; font-size: 15px; text-align: right;">
                                        المجموع الإجمالي
                                    </td>
                                    <td style="padding: 14px 15px; font-weight: 700; font-size: 15px; direction: ltr; text-align: left;">
                                        <span id="modalGrandTotal">0.00</span> دينار
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    
                    <div style="margin-top: 20px; text-align: center;">
                        <button type="submit" class="btn btn-success">✓ حفظ الصفقة</button>
                        <button type="button" class="btn btn-secondary" id="btnCancelModal">✕ إلغاء</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- MODAL DE MODIFICATION -->
    <div id="editAppelOffreModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>✏️ تعديل الصفقة</h2>
                <span class="close" onclick="closeEditAppelOffreModal()">&times;</span>
            </div>
            <div class="modal-body" id="editModalBody">
                <!-- Contenu chargé dynamiquement -->
            </div>
        </div>
    </div>

    <!-- MODAL DE SUPPRESSION -->
    <div id="deleteAppelOffreModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h2>🗑️ حذف الصفقة</h2>
                <span class="close" onclick="closeDeleteAppelOffreModal()">&times;</span>
            </div>
            <div class="modal-body" id="deleteModalBody">
                <!-- Contenu chargé dynamiquement -->
            </div>
        </div>
    </div>


    <!-- MODAL DÉTAILS APPEL D'OFFRE -->
    <div id="detailsAppelOffreModal" class="modal">
        <div class="modal-content" style="max-width: 850px;">
            <div class="modal-header">
                <h2>📋 تفاصيل الصفقة</h2>
                <span class="close" onclick="closeDetailsModal()">&times;</span>
            </div>
            <div class="modal-body" id="detailsModalBody">
                <div class="details-loading">جاري التحميل...</div>
            </div>
        </div>
    </div>

        <?php include 'includes/footer.php'; ?>

    <script>
        let lotCounter = 0;
        let fournisseurs = [];
        const modal = document.getElementById('addAppelOffreModal');

        // Charger les fournisseurs
        async function loadFournisseurs() {
            try {
                const response = await fetch('get_fournisseurs.php');
                const data = await response.json();
                if (data.success) {
                    fournisseurs = data.fournisseurs;
                }
            } catch (error) {
                console.error('Erreur:', error);
            }
        }

        function createFournisseurOptions() {
            let options = '<option value="">-- اختر صاحب الصفقة --</option>';
            fournisseurs.forEach(f => {
                options += `<option value="${f.idFournisseur}">${f.nomFournisseur}</option>`;
            });
            return options;
        }

        function updateModalTotal() {
            let total = 0;
            document.querySelectorAll('#lotsTableBody input[type="number"]').forEach(input => {
                total += parseFloat(input.value) || 0;
            });
            const el = document.getElementById('modalGrandTotal');
            if (el) el.textContent = total.toLocaleString('fr-TN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }

        function addLotRow() {
            lotCounter++;
            const tbody = document.getElementById('lotsTableBody');
            const row = document.createElement('tr');
            row.id = `lot-row-${lotCounter}`;
            row.innerHTML = `
                <td><input type="text" name="lots[${lotCounter}][sujetLot]" class="form-control" placeholder="موضوع الصفقة" required></td>
                <td><select name="lots[${lotCounter}][idFournisseur]" class="form-control" required>${createFournisseurOptions()}</select></td>
                <td><input type="number" name="lots[${lotCounter}][somme]" class="form-control" step="0.01" min="0" placeholder="0.00" required oninput="updateModalTotal()"></td>
                <td><button type="button" class="btn-remove-lot" onclick="removeLotRow(${lotCounter})">🗑️</button></td>
            `;
            tbody.appendChild(row);
            updateModalTotal();
        }

        function removeLotRow(id) {
            const row = document.getElementById(`lot-row-${id}`);
            if (row && document.querySelectorAll('#lotsTableBody tr').length > 1) {
                row.remove();
                updateModalTotal();
            } else {
                alert('يجب أن تحتفظ بصفقة واحدة على الأقل');
            }
        }

        // Ouvrir le modal
        document.getElementById('btnOpenModal').onclick = function() {
            modal.classList.add('show');
            loadFournisseurs().then(() => addLotRow());
        }

        // Fermer le modal
        function fermerModal() {
            modal.classList.remove('show');
            document.getElementById('addAppelOffreForm').reset();
            document.getElementById('lotsTableBody').innerHTML = '';
            lotCounter = 0;
        }

        document.getElementById('btnCloseModal').onclick = fermerModal;
        document.getElementById('btnCancelModal').onclick = fermerModal;
        document.getElementById('btnAddLot').addEventListener('click', addLotRow);

        // Validation du fichier
        document.getElementById('fichier').onchange = function() {
            var file = this.files[0];
            if (file) {
                var fileSize = file.size / 1024 / 1024; // En MB
                var allowedTypes = ['application/pdf', 'application/msword', 
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
                
                if (fileSize > 10) {
                    alert('حجم الملف يجب أن يكون أقل من 10 ميغابايت');
                    this.value = '';
                    return false;
                }
                
                if (!allowedTypes.includes(file.type)) {
                    alert('نوع الملف غير مقبول. يرجى اختيار ملف PDF أو Word أو Excel');
                    this.value = '';
                    return false;
                }
            }
        };

        // Soumission du formulaire
        document.getElementById('addAppelOffreForm').onsubmit = function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const alertDiv = document.getElementById('modalAlert');
            
            alertDiv.innerHTML = '<div style="text-align: center; padding: 15px;">جاري الحفظ...</div>';
            
            fetch('appels_d_offres.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alertDiv.innerHTML = '<div style="background: #d4edda; color: #155724; padding: 12px; border-radius: 8px;">✓ ' + data.message + '</div>';
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    alertDiv.innerHTML = '<div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 8px;">✕ ' + data.message + '</div>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alertDiv.innerHTML = '<div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 8px;">✕ حدث خطأ في الاتصال</div>';
            });
        };

        document.addEventListener('DOMContentLoaded', loadFournisseurs);

        // ================================================================
// FONCTION POUR OUVRIR LE MODAL DE MODIFICATION D'APPEL D'OFFRE
// ================================================================

/**
 * Ouvre le modal de modification et charge les données de l'appel d'offre
 * @param {number} idAppel - ID de l'appel d'offre à modifier
 */
function openEditAppelOffreModal(idAppel) {
    // Afficher le modal
    const modal = document.getElementById('editAppelOffreModal');
    modal.classList.add('show');
    modal.style.display = 'flex';
    
    // Afficher un loader pendant le chargement
    const modalBody = document.getElementById('editModalBody');
    modalBody.innerHTML = `
        <div style="text-align: center; padding: 40px;">
            <div class="loader" style="margin: 0 auto 20px;"></div>
            <p>جاري تحميل البيانات...</p>
        </div>
    `;
    
    // Charger les données de l'appel d'offre via AJAX
    fetch(`get_appel_offre_data.php?id=${idAppel}`)
        .then(response => response.text())
        .then(text => {
            try {
                // Nettoyer le texte (BOM, espaces, caractères parasites)
                const clean = text.trim().replace(/^\uFEFF/, '').replace(/^[^{[]+/, '');
                const data = JSON.parse(clean);
                if (data.success) {
                    renderEditForm(
                        data.appelOffre,
                        data.lots        || [],
                        data.projets     || [],
                        data.fournisseurs|| []
                    );
                } else {
                    showEditError(data.message || 'حدث خطأ في تحميل البيانات');
                }
            } catch(e) {
                console.error('Réponse non-JSON:', text);
                showEditError('خطأ في الخادم: ' + text.substring(0, 300));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showEditError('حدث خطأ في الاتصال بالخادم: ' + error.message);
        });
}

/**
 * Affiche le formulaire de modification avec les données chargées
 */
function renderEditForm(appelOffre, lots, projets, fournisseurs) {
    const modalBody = document.getElementById('editModalBody');
    
    // Stocker les fournisseurs globalement pour l'ajout de lots
    window.editFournisseurs = fournisseurs;
    
    modalBody.innerHTML = `
        <div id="editModalAlert"></div>
        
        <form id="editAppelOffreForm" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="${document.querySelector('input[name="csrf_token"]') ? document.querySelector('input[name="csrf_token"]').value : '<?php echo $csrf_token; ?>'}">
            <input type="hidden" name="action" value="update_appel_offre">
            <input type="hidden" name="idApp" id="editIdApp" value="${appelOffre.idApp}">
            
            <!-- SECTION PROJET -->
            <div class="form-group-full">
                <label>المشروع <span class="required">*</span></label>
                <select name="idpro" id="editIdPro" class="form-control" required>
                    <option value="">-- اختر المشروع --</option>
                    ${projets.map(p => `
                        <option value="${p.idPro}" ${p.idPro == appelOffre.idPro ? 'selected' : ''}>
                            ${escapeHtml(p.sujet)}
                        </option>
                    `).join('')}
                </select>
            </div>

            <!-- SECTION FICHIER -->
            <div class="form-group-full">
                <label>ملف الإسناد (اختياري - اترك فارغاً للاحتفاظ بالملف الحالي)</label>
                <input type="file" name="fichier" id="editFichier" class="form-control" 
                       accept=".pdf,.doc,.docx,.xls,.xlsx">
                <small style="color: #666; font-size: 12px; display: block; margin-top: 5px;">
                    الحجم الأقصى: 10MB - الأنواع المقبولة: PDF, Word, Excel
                </small>
                ${appelOffre.documentPath ? `
                    <div style="margin-top: 10px; padding: 10px; background: #f0f0f0; border-radius: 6px;">
                        <strong>الملف الحالي:</strong> 
                        <a href="${appelOffre.documentPath}" target="_blank" style="color: #667eea;">
                            📄 عرض الملف
                        </a>
                    </div>
                ` : ''}
            </div>

            <!-- SECTION LOTS -->
            <div class="lots-section">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3>📦 الصفقات</h3>
                    <button type="button" class="btn-add-lot" onclick="addEditLotRow()">
                        ➕ إضافة صفقة
                    </button>
                </div>

                <table class="lots-table">
                    <thead>
                        <tr>
                            <th>الصفقة <span class="required">*</span></th>
                            <th>صاحب الصفقة <span class="required">*</span></th>
                            <th>المبلغ <span class="required">*</span></th>
                            <th>الإجراء</th>
                        </tr>
                    </thead>
                    <tbody id="editLotsTableBody">
                        ${renderEditLots(lots, fournisseurs)}
                    </tbody>
                </table>
            </div>

            <!-- BOUTONS D'ACTION -->
            <div style="margin-top: 30px; text-align: center;">
                <button type="submit" class="btn btn-success" style="min-width: 150px;">
                    ✓ حفظ التعديلات
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeEditAppelOffreModal()" 
                        style="min-width: 150px;">
                    ✕ إلغاء
                </button>
            </div>
        </form>
    `;
    
    // Initialiser le compteur de lots
    window.editLotCounter = lots.length;
    
    // Attacher l'événement de soumission du formulaire
    document.getElementById('editAppelOffreForm').addEventListener('submit', handleEditFormSubmit);
    
    // Attacher la validation du fichier
    document.getElementById('editFichier').addEventListener('change', validateEditFile);
}

/**
 * Génère le HTML des lignes de lots pour le formulaire d'édition
 */
function renderEditLots(lots, fournisseurs) {
    return lots.map((lot, index) => `
        <tr id="edit-lot-row-${index}">
            <td>
                <input type="text" 
                       name="lots[${index}][sujetLot]" 
                       class="form-control" 
                       value="${escapeHtml(lot.sujetLot)}" 
                       placeholder="موضوع الصفقة" 
                       required>
            </td>
            <td>
                <select name="lots[${index}][idFournisseur]" class="form-control" required>
                    <option value="">-- اختر صاحب الصفقة --</option>
                    ${fournisseurs.map(f => `
                        <option value="${f.idFournisseur}" ${f.idFournisseur == lot.idFournisseur ? 'selected' : ''}>
                            ${escapeHtml(f.nomFournisseur)}
                        </option>
                    `).join('')}
                </select>
            </td>
            <td>
                <input type="number" 
                       name="lots[${index}][somme]" 
                       class="form-control" 
                       value="${lot.somme}" 
                       step="0.01" 
                       min="0" 
                       placeholder="0.00" 
                       required>
            </td>
            <td>
                <button type="button" 
                        class="btn-remove-lot" 
                        onclick="removeEditLotRow(${index})"
                        ${lots.length === 1 ? 'disabled' : ''}>
                    🗑️
                </button>
            </td>
        </tr>
    `).join('');
}

/**
 * Ajoute une nouvelle ligne de lot dans le formulaire d'édition
 */
function addEditLotRow() {
    window.editLotCounter = window.editLotCounter || 0;
    window.editLotCounter++;
    
    const tbody = document.getElementById('editLotsTableBody');
    const rowId = `edit-lot-row-${window.editLotCounter}`;
    
    const fournisseurOptions = window.editFournisseurs.map(f => `
        <option value="${f.idFournisseur}">${escapeHtml(f.nomFournisseur)}</option>
    `).join('');
    
    const newRow = document.createElement('tr');
    newRow.id = rowId;
    newRow.innerHTML = `
        <td>
            <input type="text" 
                   name="lots[${window.editLotCounter}][sujetLot]" 
                   class="form-control" 
                   placeholder="موضوع الصفقة" 
                   required>
        </td>
        <td>
            <select name="lots[${window.editLotCounter}][idFournisseur]" class="form-control" required>
                <option value="">-- اختر صاحب الصفقة --</option>
                ${fournisseurOptions}
            </select>
        </td>
        <td>
            <input type="number" 
                   name="lots[${window.editLotCounter}][somme]" 
                   class="form-control" 
                   step="0.01" 
                   min="0" 
                   placeholder="0.00" 
                   required>
        </td>
        <td>
            <button type="button" 
                    class="btn-remove-lot" 
                    onclick="removeEditLotRow(${window.editLotCounter})">
                🗑️
            </button>
        </td>
    `;
    
    tbody.appendChild(newRow);
}

/**
 * Supprime une ligne de lot du formulaire d'édition
 */
function removeEditLotRow(rowId) {
    const row = document.getElementById(`edit-lot-row-${rowId}`);
    const tbody = document.getElementById('editLotsTableBody');
    
    // Vérifier qu'il reste au moins un lot
    if (tbody.children.length <= 1) {
        showEditAlert('يجب أن تحتوي الصفقة على قسط واحد على الأقل', 'warning');
        return;
    }
    
    if (row) {
        row.remove();
    }
}

/**
 * Gère la soumission du formulaire de modification
 */
function handleEditFormSubmit(e) {
    e.preventDefault();
    
    const form = e.target;
    const formData = new FormData(form);
    const alertDiv = document.getElementById('editModalAlert');
    
    // Désactiver le bouton de soumission
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '⏳ جاري الحفظ...';
    
    // Afficher un message de chargement
    alertDiv.innerHTML = `
        <div style="background: #d1ecf1; color: #0c5460; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
            جاري حفظ التعديلات...
        </div>
    `;
    
    // Envoyer les données
    fetch('appels_d_offres.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Afficher un message de succès
            alertDiv.innerHTML = `
                <div style="background: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                    ✓ ${data.message}
                </div>
            `;
            
            // Recharger la page après 1.5 secondes
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            // Afficher le message d'erreur
            alertDiv.innerHTML = `
                <div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                    ✕ ${data.message}
                </div>
            `;
            
            // Réactiver le bouton
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alertDiv.innerHTML = `
            <div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                ✕ حدث خطأ في الاتصال بالخادم
            </div>
        `;
        
        // Réactiver le bouton
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
}

/**
 * Valide le fichier uploadé
 */
function validateEditFile(e) {
    const file = e.target.files[0];
    
    if (!file) return;
    
    // Vérifier la taille (max 10MB)
    const maxSize = 10 * 1024 * 1024; // 10MB en bytes
    if (file.size > maxSize) {
        showEditAlert('حجم الملف يجب أن يكون أقل من 10 ميغابايت', 'error');
        e.target.value = '';
        return false;
    }
    
    // Vérifier le type de fichier
    const allowedTypes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    ];
    
    if (!allowedTypes.includes(file.type)) {
        showEditAlert('نوع الملف غير مقبول. يرجى اختيار ملف PDF أو Word أو Excel', 'error');
        e.target.value = '';
        return false;
    }
    
    return true;
}

/**
 * Ferme le modal de modification
 */
function closeEditAppelOffreModal() {
    const modal = document.getElementById('editAppelOffreModal');
    modal.classList.remove('show');
    modal.style.display = 'none';
    
    // Nettoyer le contenu
    const modalBody = document.getElementById('editModalBody');
    modalBody.innerHTML = '';
    
    // Réinitialiser les variables globales
    window.editLotCounter = 0;
    window.editFournisseurs = [];
}

/**
 * Affiche un message d'alerte dans le modal d'édition
 */
function showEditAlert(message, type = 'info') {
    const alertDiv = document.getElementById('editModalAlert');
    
    const colors = {
        success: { bg: '#d4edda', color: '#155724', icon: '✓' },
        error: { bg: '#f8d7da', color: '#721c24', icon: '✕' },
        warning: { bg: '#fff3cd', color: '#856404', icon: '⚠️' },
        info: { bg: '#d1ecf1', color: '#0c5460', icon: 'ℹ️' }
    };
    
    const style = colors[type] || colors.info;
    
    alertDiv.innerHTML = `
        <div style="background: ${style.bg}; color: ${style.color}; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
            ${style.icon} ${message}
        </div>
    `;
    
    // Faire défiler vers le haut du modal
    document.querySelector('.modal-content').scrollTop = 0;
}

/**
 * Affiche une erreur dans le modal
 */
function showEditError(message) {
    const modalBody = document.getElementById('editModalBody');
    modalBody.innerHTML = `
        <div style="text-align: center; padding: 40px;">
            <div style="font-size: 48px; margin-bottom: 20px;">❌</div>
            <p style="color: #dc3545; font-size: 16px;">${message}</p>
            <button onclick="closeEditAppelOffreModal()" class="btn btn-secondary" style="margin-top: 20px;">
                إغلاق
            </button>
        </div>
    `;
}

/**
 * Échappe les caractères HTML pour éviter les injections XSS
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ================================================================
// FONCTION POUR OUVRIR LE MODAL DE SUPPRESSION D'APPEL D'OFFRE
// ================================================================

/**
 * Ouvre le modal de confirmation de suppression
 * @param {number} idAppel - ID de l'appel d'offre à supprimer
 * @param {string} projetNom - Nom du projet (pour affichage)
 */
function openDeleteAppelOffreModal(idAppel, projetNom) {
    const modal = document.getElementById('deleteAppelOffreModal');
    modal.classList.add('show');
    modal.style.display = 'flex';
    
    // Remplir le contenu du modal
    const modalBody = document.getElementById('deleteModalBody');
    modalBody.innerHTML = `
        <div style="text-align: center; padding: 20px;">
            <div style="font-size: 64px; color: #dc3545; margin-bottom: 20px;">⚠️</div>
            
            <h3 style="margin-bottom: 15px; color: #333;">هل أنت متأكد من حذف هذه الصفقة؟</h3>
            
            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 20px 0;">
                <strong>المشروع:</strong> ${escapeHtml(projetNom)}
            </div>
            
            <p style="color: #666; margin-bottom: 30px;">
                سيتم حذف جميع البيانات المرتبطة بهذه الصفقة بشكل نهائي ولا يمكن التراجع عن هذا الإجراء.
            </p>
            
            <div id="deleteModalAlert"></div>
            
            <form id="deleteAppelOffreForm" onsubmit="handleDeleteFormSubmit(event, ${idAppel})">
                <input type="hidden" name="csrf_token" value="${document.querySelector('input[name="csrf_token"]') ? document.querySelector('input[name="csrf_token"]').value : '<?php echo $csrf_token; ?>'}">
                <input type="hidden" name="action" value="delete_appel_offre">
                <input type="hidden" name="idApp" value="${idAppel}">
                
                <div style="display: flex; gap: 15px; justify-content: center;">
                    <button type="submit" class="btn btn-danger" style="min-width: 150px;">
                        🗑️ نعم، احذف الصفقة
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="closeDeleteAppelOffreModal()" 
                            style="min-width: 150px;">
                        ✕ إلغاء
                    </button>
                </div>
            </form>
        </div>
    `;
}

/**
 * Gère la soumission du formulaire de suppression
 */
function handleDeleteFormSubmit(e, idAppel) {
    e.preventDefault();
    
    const form = e.target;
    const formData = new FormData(form);
    const alertDiv = document.getElementById('deleteModalAlert');
    
    // Désactiver les boutons
    const deleteBtn = form.querySelector('button[type="submit"]');
    const cancelBtn = form.querySelector('button[type="button"]');
    const originalText = deleteBtn.innerHTML;
    
    deleteBtn.disabled = true;
    cancelBtn.disabled = true;
    deleteBtn.innerHTML = '⏳ جاري الحذف...';
    
    // Afficher un message de chargement
    alertDiv.innerHTML = `
        <div style="background: #fff3cd; color: #856404; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
            جاري حذف الصفقة...
        </div>
    `;
    
    // Envoyer la demande de suppression
    fetch('appels_d_offres.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Afficher un message de succès
            alertDiv.innerHTML = `
                <div style="background: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                    ✓ ${data.message}
                </div>
            `;
            
            // Recharger la page après 1.5 secondes
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            // Afficher le message d'erreur
            alertDiv.innerHTML = `
                <div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                    ✕ ${data.message}
                </div>
            `;
            
            // Réactiver les boutons
            deleteBtn.disabled = false;
            cancelBtn.disabled = false;
            deleteBtn.innerHTML = originalText;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alertDiv.innerHTML = `
            <div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                ✕ حدث خطأ في الاتصال بالخادم
            </div>
        `;
        
        // Réactiver les boutons
        deleteBtn.disabled = false;
        cancelBtn.disabled = false;
        deleteBtn.innerHTML = originalText;
    });
}

/**
 * Ferme le modal de suppression
 */
function closeDeleteAppelOffreModal() {
    const modal = document.getElementById('deleteAppelOffreModal');
    modal.classList.remove('show');
    modal.style.display = 'none';
    
    // Nettoyer le contenu
    const modalBody = document.getElementById('deleteModalBody');
    modalBody.innerHTML = '';
}

// ================================================================
// GESTION DES ÉVÉNEMENTS GLOBAUX
// ================================================================

// Fermer les modals en cliquant en dehors
window.addEventListener('click', function(event) {
    const editModal = document.getElementById('editAppelOffreModal');
    const deleteModal = document.getElementById('deleteAppelOffreModal');
    
    if (event.target === editModal) {
        closeEditAppelOffreModal();
    }
    
    if (event.target === deleteModal) {
        closeDeleteAppelOffreModal();
    }
});

// Fermer les modals avec la touche Échap
window.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeEditAppelOffreModal();
        closeDeleteAppelOffreModal();
        closeDetailsModal();
    }
});

// ================================================================
// MODAL DÉTAILS APPEL D'OFFRE
// ================================================================
function openDetailsModal(idApp) {
    const modal = document.getElementById('detailsAppelOffreModal');
    const body  = document.getElementById('detailsModalBody');
    body.innerHTML = '<div class="details-loading">⏳ جاري التحميل...</div>';
    modal.classList.add('show');
    modal.style.display = 'block';

    fetch('appels_d_offres.php?action=get_details&id=' + idApp + '&_=' + Date.now())
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(text => {
            try {
                return JSON.parse(text);
            } catch(e) {
                throw new Error('الاستجابة ليست JSON صحيحة: ' + text.substring(0, 200));
            }
        })
        .then(data => {
            if (!data.success) {
                body.innerHTML = '<div class="details-loading" style="color:#dc3545;">❌ ' + data.message + '</div>';
                return;
            }
            const ao = data.appel;
            const lots = data.lots;
            const doc  = data.document;

            let docHtml = doc
                ? `<a href="${escapeHtml(doc.cheminAcces)}" target="_blank" style="color:#667eea;font-weight:600;">📄 تحميل الملف</a>`
                : '<span style="color:#aaa;">لا يوجد ملف</span>';

            let lotsRows = lots.map((l, i) => `
                <tr>
                    <td>${i + 1}</td>
                    <td style="text-align:right">${escapeHtml(l.sujetLot)}</td>
                    <td>${escapeHtml(l.nomFour)}</td>
                    <td style="direction:ltr;text-align:left;">${Number(l.somme).toLocaleString('fr-TN', {minimumFractionDigits:2})} دينار</td>
                </tr>
            `).join('');

            const total = lots.reduce((s, l) => s + parseFloat(l.somme || 0), 0);

            body.innerHTML = `
                <div class="details-info-grid">
                    <div class="details-info-item" style="grid-column:1/-1;">
                        <div class="details-info-label">المشروع</div>
                        <div class="details-info-value">${escapeHtml(ao.sujet)}</div>
                    </div>
                    <div class="details-info-item">
                        <div class="details-info-label">الوزارة</div>
                        <div class="details-info-value">${escapeHtml(ao.libMinistere || '-')}</div>
                    </div>
                    <div class="details-info-item">
                        <div class="details-info-label">المؤسسة</div>
                        <div class="details-info-value">${escapeHtml(ao.libEtablissement || '-')}</div>
                    </div>
                    <div class="details-info-item">
                        <div class="details-info-label">تاريخ الإنشاء</div>
                        <div class="details-info-value">${escapeHtml(ao.dateCreation)}</div>
                    </div>
                    <div class="details-info-item">
                        <div class="details-info-label">عدد الأقساط</div>
                        <div class="details-info-value">${lots.length}</div>
                    </div>
                    <div class="details-info-item" style="grid-column:1/-1;">
                        <div class="details-info-label">ملف الإسناد</div>
                        <div class="details-info-value">${docHtml}</div>
                    </div>
                </div>

                <div class="details-section-title">📦 قائمة الصفقات</div>
                <table class="details-lots-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>موضوع الصفقة</th>
                            <th>صاحب الصفقة</th>
                            <th>المبلغ (دينار)</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${lotsRows}
                        <tr class="total-row">
                            <td colspan="3">المجموع الإجمالي</td>
                            <td style="direction:ltr;text-align:left;">${total.toLocaleString('fr-TN', {minimumFractionDigits:2})} دينار</td>
                        </tr>
                    </tbody>
                </table>
            `;
        })
        .catch(err => {
            body.innerHTML = '<div class="details-loading" style="color:#dc3545; font-size:13px; text-align:right; padding:20px;">❌ ' + err.message + '</div>';
        });
}

function closeDetailsModal() {
    const modal = document.getElementById('detailsAppelOffreModal');
    modal.classList.remove('show');
    modal.style.display = 'none';
}

// Fermer en cliquant en dehors
window.addEventListener('click', function(event) {
    if (event.target === document.getElementById('detailsAppelOffreModal')) {
        closeDetailsModal();
    }
});

        // Fonction d'exportation
        function exportData(format) {
            const button = event.target.closest('.btn-export');
            button.classList.add('export-loading');
            button.disabled = true;

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'export_appels_offres.php';
            form.target = '_blank';

            const formatInput = document.createElement('input');
            formatInput.type = 'hidden';
            formatInput.name = 'format';
            formatInput.value = format;
            form.appendChild(formatInput);

            // Transmettre les filtres actifs
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput && searchInput.value) {
                const s = document.createElement('input');
                s.type = 'hidden'; s.name = 'search'; s.value = searchInput.value;
                form.appendChild(s);
            }

            const yearSelect = document.querySelector('select[name="year"]');
            if (yearSelect && yearSelect.value) {
                const y = document.createElement('input');
                y.type = 'hidden'; y.name = 'year'; y.value = yearSelect.value;
                form.appendChild(y);
            }

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);

            setTimeout(() => {
                button.classList.remove('export-loading');
                button.disabled = false;
            }, 2000);
        }
    </script>
</body>
</html>