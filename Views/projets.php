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

    // ==========================================
    // INITIALISER LA BASE DE DONNÉES ICI (AVANT TOUT)
    // ==========================================
    $database = new Database();
    $db = $database->getConnection();

    // Traitement de l'upload du التقرير الرقابي
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_taqrir') {
        // Nettoyer tout buffer de sortie
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            // 1. Validation CSRF
            if (!Security::validateCSRFToken($_POST['csrf_token'])) {
                echo json_encode(['success' => false, 'message' => 'خطأ في التحقق من الأمان'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            // 2. Récupération et validation des données
            $projetId = intval($_POST['projetId']);
            $libDoc = Security::sanitizeInput($_POST['libDoc']);
            
            if (empty($libDoc)) {
                echo json_encode(['success' => false, 'message' => 'يرجى إدخال عنوان التقرير'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            // 3. Vérifier que le projet existe
            $sqlCheck = "SELECT idUser FROM projet WHERE idPro = :projetId";
            $stmtCheck = $db->prepare($sqlCheck);
            $stmtCheck->bindParam(':projetId', $projetId, PDO::PARAM_INT);
            $stmtCheck->execute();
            $projetCheck = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            
            if (!$projetCheck) {
                echo json_encode(['success' => false, 'message' => 'المشروع غير موجود. الرجاء التحقق من رقم المشروع'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            // 4. Vérifier les permissions
            if (!Permissions::canEditProjet($projetCheck['idUser'])) {
                echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية لتعديل هذا المقترح'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            // 5. Vérifier le fichier uploadé
            if (!isset($_FILES['fichier_taqrir']) || $_FILES['fichier_taqrir']['error'] !== UPLOAD_ERR_OK) {
                $errorMsg = 'لم يتم اختيار ملف';
                if (isset($_FILES['fichier_taqrir']['error'])) {
                    switch ($_FILES['fichier_taqrir']['error']) {
                        case UPLOAD_ERR_INI_SIZE:
                        case UPLOAD_ERR_FORM_SIZE:
                            $errorMsg = 'حجم الملف كبير جداً (الحد الأقصى 5MB)';
                            break;
                        case UPLOAD_ERR_PARTIAL:
                            $errorMsg = 'تم رفع الملف جزئياً فقط';
                            break;
                        case UPLOAD_ERR_NO_FILE:
                            $errorMsg = 'لم يتم اختيار ملف';
                            break;
                        default:
                            $errorMsg = 'حدث خطأ في رفع الملف';
                    }
                }
                echo json_encode(['success' => false, 'message' => $errorMsg], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            // 6. Validation de la taille du fichier (5MB max)
            $maxFileSize = 10 * 1024 * 1024; // 5MB en bytes
            if ($_FILES['fichier_taqrir']['size'] > $maxFileSize) {
                echo json_encode(['success' => false, 'message' => 'حجم الملف يجب أن يكون أقل من 5 ميغابايت'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            // 7. Créer le dossier s'il n'existe pas
            $uploadDir = dirname(__DIR__) . '/uploads/documents/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            // 8. Validation du type de fichier
            $fileName = $_FILES['fichier_taqrir']['name'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];
            
            if (!in_array($fileExtension, $allowedExtensions)) {
                echo json_encode(['success' => false, 'message' => 'نوع الملف غير مقبول. استخدم PDF, Word أو Excel'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            // 9. Générer un nom de fichier unique
            $newFileName = 'taqrir_' . $projetId . '_' . time() . '.' . $fileExtension;
            $filePath = $uploadDir . $newFileName;
            $filePathDB = '../uploads/documents/' . $newFileName;
            
            // 10. Déplacer le fichier uploadé
            if (!move_uploaded_file($_FILES['fichier_taqrir']['tmp_name'], $filePath)) {
                echo json_encode(['success' => false, 'message' => 'فشل في رفع الملف'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            // 11. Insertion dans la base de données
            $db->beginTransaction();
            
            try {
                // Vérifier s'il existe déjà un تقرير رقابي pour ce projet
                $sqlCheckExisting = "SELECT idDoc FROM document WHERE idPro = :idPro AND type = 11";
                $stmtCheckExisting = $db->prepare($sqlCheckExisting);
                $stmtCheckExisting->bindParam(':idPro', $projetId, PDO::PARAM_INT);
                $stmtCheckExisting->execute();
                
                if ($stmtCheckExisting->rowCount() > 0) {
                    // Mettre à jour l'existant
                    $existingDoc = $stmtCheckExisting->fetch(PDO::FETCH_ASSOC);
                    $sqlUpdate = "UPDATE document 
                                SET libDoc = :libDoc, cheminAcces = :cheminAcces 
                                WHERE idDoc = :idDoc";
                    $stmtUpdate = $db->prepare($sqlUpdate);
                    $stmtUpdate->bindParam(':libDoc', $libDoc);
                    $stmtUpdate->bindParam(':cheminAcces', $filePathDB);
                    $stmtUpdate->bindParam(':idDoc', $existingDoc['idDoc'], PDO::PARAM_INT);
                    $stmtUpdate->execute();
                } else {
                    // Insérer un nouveau document type 11
                    $sqlDoc = "INSERT INTO document (idPro, libDoc, cheminAcces, type, idExterne) 
                            VALUES (:idPro, :libDoc, :cheminAcces, 11, :idExterne)";
                    $stmtDoc = $db->prepare($sqlDoc);
                    $stmtDoc->bindParam(':idPro', $projetId, PDO::PARAM_INT);
                    $stmtDoc->bindParam(':libDoc', $libDoc);
                    $stmtDoc->bindParam(':cheminAcces', $filePathDB);
                    $stmtDoc->bindParam(':idExterne', $projetId, PDO::PARAM_INT);
                    $stmtDoc->execute();
                }
                
                // 12. Mettre à jour l'état du projet à 1 (الإحالة على اللجنة)
                $sqlUpdateEtat = "UPDATE projet SET etat = 1 WHERE idPro = :projetId";
                $stmtUpdateEtat = $db->prepare($sqlUpdateEtat);
                $stmtUpdateEtat->bindParam(':projetId', $projetId, PDO::PARAM_INT);
                $stmtUpdateEtat->execute();
                
                // 13. Logger l'action
                $logSql = "INSERT INTO journal (idUser, action, date) VALUES (:idUser, :action, CURDATE())";
                $logStmt = $db->prepare($logSql);
                $logStmt->bindParam(':idUser', $_SESSION['user_id']);
                $action = "إضافة التقرير الرقابي للمقترح رقم " . $projetId . ": " . $libDoc . " - تغيير الحالة إلى الإحالة على اللجنة";
                $logStmt->bindParam(':action', $action);
                $logStmt->execute();
                
                $db->commit();
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'تم إضافة التقرير الرقابي بنجاح'
                ], JSON_UNESCAPED_UNICODE);
                
            } catch (PDOException $e) {
                $db->rollBack();
                // Supprimer le fichier uploadé en cas d'erreur BD
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
                throw $e;
            }
            
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            echo json_encode([
                'success' => false, 
                'message' => 'حدث خطأ: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    // Traitement de l'ajout de fichier supplémentaire
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_file') {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            // 1. Validation CSRF
            if (!Security::validateCSRFToken($_POST['csrf_token'])) {
                echo json_encode(['success' => false, 'message' => 'خطأ في التحقق من الأمان'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            // 2. Récupération et validation des données
            $projetId = intval($_POST['projetId']);
            $libDoc = Security::sanitizeInput($_POST['libDoc']);
            $typeDoc = Security::sanitizeInput($_POST['typeDoc']);
            
            if (empty($libDoc) || empty($typeDoc)) {
                echo json_encode(['success' => false, 'message' => 'يرجى ملء جميع الحقول المطلوبة'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            // 3. Vérifier que le projet existe
            $sqlCheck = "SELECT idUser FROM projet WHERE idPro = :projetId";
            $stmtCheck = $db->prepare($sqlCheck);
            $stmtCheck->bindParam(':projetId', $projetId, PDO::PARAM_INT);
            $stmtCheck->execute();
            $projetCheck = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            
            if (!$projetCheck) {
                echo json_encode(['success' => false, 'message' => 'المشروع غير موجود'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            // 4. Vérifier les permissions
            if (!Permissions::canEditProjet($projetCheck['idUser'])) {
                echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية لتعديل هذا المقترح'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            // 5. Vérifier le fichier uploadé
            if (!isset($_FILES['fichier_supplementaire']) || $_FILES['fichier_supplementaire']['error'] !== UPLOAD_ERR_OK) {
                $errorMsg = 'لم يتم اختيار ملف';
                if (isset($_FILES['fichier_supplementaire']['error'])) {
                    switch ($_FILES['fichier_supplementaire']['error']) {
                        case UPLOAD_ERR_INI_SIZE:
                        case UPLOAD_ERR_FORM_SIZE:
                            $errorMsg = 'حجم الملف كبير جداً (الحد الأقصى 10MB)';
                            break;
                        case UPLOAD_ERR_PARTIAL:
                            $errorMsg = 'تم رفع الملف جزئياً فقط';
                            break;
                        case UPLOAD_ERR_NO_FILE:
                            $errorMsg = 'لم يتم اختيار ملف';
                            break;
                        default:
                            $errorMsg = 'حدث خطأ في رفع الملف';
                    }
                }
                echo json_encode(['success' => false, 'message' => $errorMsg], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            // 6. Validation de la taille du fichier (10MB max)
            $maxFileSize = 10 * 1024 * 1024;
            if ($_FILES['fichier_supplementaire']['size'] > $maxFileSize) {
                echo json_encode(['success' => false, 'message' => 'حجم الملف يجب أن يكون أقل من 10 ميغابايت'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            // 7. Créer le dossier s'il n'existe pas
            $uploadDir = dirname(__DIR__) . '/uploads/documents/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            // 8. Validation du type de fichier
            $fileName = $_FILES['fichier_supplementaire']['name'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];
            
            if (!in_array($fileExtension, $allowedExtensions)) {
                echo json_encode(['success' => false, 'message' => 'نوع الملف غير مقبول. استخدم PDF, Word أو Excel'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            // 9. Générer un nom de fichier unique
            $newFileName = 'file_' . $projetId . '_' . time() . '.' . $fileExtension;
            $filePath = $uploadDir . $newFileName;
            $filePathDB = '../uploads/documents/' . $newFileName;
            
            // 10. Déplacer le fichier uploadé
            if (!move_uploaded_file($_FILES['fichier_supplementaire']['tmp_name'], $filePath)) {
                echo json_encode(['success' => false, 'message' => 'فشل في رفع الملف'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            // 11. Déterminer le type de document (20 pour مراسلة, 21 pour اخرى)
            $typeDocNum = ($typeDoc === 'مراسلة') ? 20 : 21;
            
            // 12. Insertion dans la base de données
            $db->beginTransaction();
            
            try {
                $sqlDoc = "INSERT INTO document (idPro, libDoc, cheminAcces, type, idExterne) 
                        VALUES (:idPro, :libDoc, :cheminAcces, :type, :idExterne)";
                $stmtDoc = $db->prepare($sqlDoc);
                $stmtDoc->bindParam(':idPro', $projetId, PDO::PARAM_INT);
                $stmtDoc->bindParam(':libDoc', $libDoc);
                $stmtDoc->bindParam(':cheminAcces', $filePathDB);
                $stmtDoc->bindParam(':type', $typeDocNum, PDO::PARAM_INT);
                $stmtDoc->bindParam(':idExterne', $projetId, PDO::PARAM_INT);
                $stmtDoc->execute();
                
                // 13. Logger l'action
                $logSql = "INSERT INTO journal (idUser, action, date) VALUES (:idUser, :action, CURDATE())";
                $logStmt = $db->prepare($logSql);
                $logStmt->bindParam(':idUser', $_SESSION['user_id']);
                $action = "إضافة ملف (" . $typeDoc . ") للمقترح رقم " . $projetId . ": " . $libDoc;
                $logStmt->bindParam(':action', $action);
                $logStmt->execute();
                
                $db->commit();
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'تم إضافة الملف بنجاح'
                ], JSON_UNESCAPED_UNICODE);
                
            } catch (PDOException $e) {
                $db->rollBack();
                // Supprimer le fichier uploadé en cas d'erreur BD
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
                throw $e;
            }
            
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            echo json_encode([
                'success' => false, 
                'message' => 'حدث خطأ: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    if (!Permissions::canCreateProjet() && $_SERVER['REQUEST_METHOD'] === 'POST') {
        echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية لإضافة مقترحات']);
        exit();
    }

    // Traitement AJAX pour l'ajout de projet
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_projet') {
        header('Content-Type: application/json');
        
        if (!Security::validateCSRFToken($_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'خطأ في التحقق من الأمان']);
            exit();
        }
        
        $idMinistere = Security::sanitizeInput($_POST['idMinistere']);
        $idEtab = Security::sanitizeInput($_POST['idEtab']);
        $idGov = Security::sanitizeInput($_POST['id_Gov']); // NOUVEAU
        $sujet = Security::sanitizeInput($_POST['sujet']);
        $dateArrive = Security::sanitizeInput($_POST['dateArrive']);
        $procedurePro = Security::sanitizeInput($_POST['procedurePro']);
        $cout = Security::sanitizeInput($_POST['cout']);
        $proposition = Security::sanitizeInput($_POST['proposition']);
        $idRapporteur = Security::sanitizeInput($_POST['idRapporteur']);
        $libDoc = Security::sanitizeInput($_POST['libDoc']);

        
        if (empty($idEtab) || $idEtab === 'الوزارة') {
            $idEtab = null;
        }
        
        try {
            $db->beginTransaction();
            
            $sql = "INSERT INTO projet (idMinistere, idEtab, id_Gov, sujet, dateArrive, procedurePro, cout, proposition, idUser, etat, dateCreation) 
            VALUES (:idMinistere, :idEtab, :idGov, :sujet, :dateArrive, :procedurePro, :cout, :proposition, :idRapporteur, 0, NOW())";

            $stmt = $db->prepare($sql);
            $stmt->bindParam(':idMinistere', $idMinistere);
            $stmt->bindParam(':idEtab', $idEtab);
            $stmt->bindParam(':idGov', $idGov); // NOUVEAU
            $stmt->bindParam(':sujet', $sujet);
            $stmt->bindParam(':dateArrive', $dateArrive);
            $stmt->bindParam(':procedurePro', $procedurePro);
            $stmt->bindParam(':cout', $cout);
            $stmt->bindParam(':proposition', $proposition);
            $stmt->bindParam(':idRapporteur', $idRapporteur);
            
            if ($stmt->execute()) {
                $projetId = $db->lastInsertId();
                
                // Gestion du fichier المقترح
                if (isset($_FILES['fichier']) && $_FILES['fichier']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = dirname(__DIR__) . '/uploads/documents/';
                    
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    
                    $fileName = $_FILES['fichier']['name'];
                    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];
                    
                    if (in_array($fileExtension, $allowedExtensions)) {
                        $newFileName = 'doc_' . $projetId . '_' . time() . '.' . $fileExtension;
                        $filePath = $uploadDir . $newFileName;
                        $filePathDB = '../uploads/documents/' . $newFileName;
                        
                        if (move_uploaded_file($_FILES['fichier']['tmp_name'], $filePath)) {
                            $sqlDoc = "INSERT INTO document (idPro, libDoc, cheminAcces, type, idExterne) 
                                    VALUES (:idPro, :libDoc, :cheminAcces, 1, :idExterne)";
                            $stmtDoc = $db->prepare($sqlDoc);
                            $stmtDoc->bindParam(':idPro', $projetId);
                            $stmtDoc->bindParam(':libDoc', $libDoc);
                            $stmtDoc->bindParam(':cheminAcces', $filePathDB);
                            $stmtDoc->bindParam(':idExterne', $projetId);
                            $stmtDoc->execute();
                        }
                    }
                }
                
                // Log l'action
                $logSql = "INSERT INTO journal (idUser, action, date) VALUES (:idUser, :action, CURDATE())";
                $logStmt = $db->prepare($logSql);
                $logStmt->bindParam(':idUser', $_SESSION['user_id']);
                $action = "إضافة مقترح جديد رقم {$projetId}: " . substr($sujet, 0, 50);
                $logStmt->bindParam(':action', $action);
                $logStmt->execute();
                
                $db->commit();
                echo json_encode(['success' => true, 'message' => 'تم إضافة المقترح بنجاح']);
            } else {
                $db->rollBack();
                echo json_encode(['success' => false, 'message' => 'فشل في إضافة المقترح']);
            }
        } catch (PDOException $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'حدث خطأ في قاعدة البيانات']);
        }
        exit();
    }

    // ==========================================
    // TRAITEMENT DE MODIFICATION DE PROJET
    // ==========================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_projet') {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            // Validation CSRF
            if (!Security::validateCSRFToken($_POST['csrf_token'])) {
                echo json_encode(['success' => false, 'message' => 'خطأ في التحقق من الأمان'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            // Récupération des données
            $projetId = intval($_POST['projetId']);
            $idMinistere = Security::sanitizeInput($_POST['idMinistere']);
            $idEtab = Security::sanitizeInput($_POST['idEtab']);
            $idGov = Security::sanitizeInput($_POST['id_Gov']); 
            $sujet = Security::sanitizeInput($_POST['sujet']);
            $dateArrive = Security::sanitizeInput($_POST['dateArrive']);
            $procedurePro = Security::sanitizeInput($_POST['procedurePro']);
            $cout = Security::sanitizeInput($_POST['cout']);
            $proposition = Security::sanitizeInput($_POST['proposition']);
            $idRapporteur = Security::sanitizeInput($_POST['idRapporteur']);
            
            if (empty($idEtab) || $idEtab === 'الوزارة') {
                $idEtab = null;
            }
            
            // Vérifier que le projet existe et permissions
            $sqlCheck = "SELECT idUser FROM projet WHERE idPro = :projetId";
            $stmtCheck = $db->prepare($sqlCheck);
            $stmtCheck->bindParam(':projetId', $projetId, PDO::PARAM_INT);
            $stmtCheck->execute();
            $projetCheck = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            
            if (!$projetCheck) {
                echo json_encode(['success' => false, 'message' => 'المشروع غير موجود'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            if (!Permissions::canEditProjet($projetCheck['idUser'])) {
                echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية لتعديل هذا المقترح'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            $db->beginTransaction();
            
            // Mise à jour du projet
            $sqlUpdate = "UPDATE projet SET 
                idMinistere = :idMinistere,
                idEtab = :idEtab,
                id_Gov = :idGov,
                sujet = :sujet,
                dateArrive = :dateArrive,
                procedurePro = :procedurePro,
                cout = :cout,
                proposition = :proposition,
                idUser = :idRapporteur
                WHERE idPro = :projetId";

            $stmtUpdate = $db->prepare($sqlUpdate);
            $stmtUpdate->bindParam(':idMinistere', $idMinistere);
            $stmtUpdate->bindParam(':idEtab', $idEtab);
            $stmtUpdate->bindParam(':idGov', $idGov); // NOUVEAU
            $stmtUpdate->bindParam(':sujet', $sujet);
            $stmtUpdate->bindParam(':dateArrive', $dateArrive);
            $stmtUpdate->bindParam(':procedurePro', $procedurePro);
            $stmtUpdate->bindParam(':cout', $cout);
            $stmtUpdate->bindParam(':proposition', $proposition);
            $stmtUpdate->bindParam(':idRapporteur', $idRapporteur);
            $stmtUpdate->bindParam(':projetId', $projetId, PDO::PARAM_INT);

            
        
            // Mise à jour du fichier المقترح si fourni
            if (isset($_FILES['fichier_muqtarah']) && $_FILES['fichier_muqtarah']['error'] === UPLOAD_ERR_OK) {
                $libDocMuqtarah = Security::sanitizeInput($_POST['libDocMuqtarah']);
                
                $uploadDir = dirname(__DIR__) . '/uploads/documents/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                $fileName = $_FILES['fichier_muqtarah']['name'];
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];
                
                if (in_array($fileExtension, $allowedExtensions)) {
                    $newFileName = 'doc_' . $projetId . '_' . time() . '.' . $fileExtension;
                    $filePath = $uploadDir . $newFileName;
                    $filePathDB = '../uploads/documents/' . $newFileName;
                    
                    if (move_uploaded_file($_FILES['fichier_muqtarah']['tmp_name'], $filePath)) {
                        // Vérifier si document existe
                        $sqlCheckDoc = "SELECT idDoc, cheminAcces FROM document WHERE idPro = :idPro AND type = 1";
                        $stmtCheckDoc = $db->prepare($sqlCheckDoc);
                        $stmtCheckDoc->bindParam(':idPro', $projetId, PDO::PARAM_INT);
                        $stmtCheckDoc->execute();
                        
                        if ($stmtCheckDoc->rowCount() > 0) {
                            // Supprimer ancien fichier
                            $oldDoc = $stmtCheckDoc->fetch(PDO::FETCH_ASSOC);
                            $oldFilePath = dirname(__DIR__) . '/' . ltrim($oldDoc['cheminAcces'], './');
                            if (file_exists($oldFilePath)) {
                                unlink($oldFilePath);
                            }
                            
                            // Mettre à jour
                            $sqlUpdateDoc = "UPDATE document SET libDoc = :libDoc, cheminAcces = :cheminAcces WHERE idDoc = :idDoc";
                            $stmtUpdateDoc = $db->prepare($sqlUpdateDoc);
                            $stmtUpdateDoc->bindParam(':libDoc', $libDocMuqtarah);
                            $stmtUpdateDoc->bindParam(':cheminAcces', $filePathDB);
                            $stmtUpdateDoc->bindParam(':idDoc', $oldDoc['idDoc'], PDO::PARAM_INT);
                            $stmtUpdateDoc->execute();
                        } else {
                            // Insérer nouveau
                            $sqlInsertDoc = "INSERT INTO document (idPro, libDoc, cheminAcces, type, idExterne) 
                                            VALUES (:idPro, :libDoc, :cheminAcces, 1, :idExterne)";
                            $stmtInsertDoc = $db->prepare($sqlInsertDoc);
                            $stmtInsertDoc->bindParam(':idPro', $projetId);
                            $stmtInsertDoc->bindParam(':libDoc', $libDocMuqtarah);
                            $stmtInsertDoc->bindParam(':cheminAcces', $filePathDB);
                            $stmtInsertDoc->bindParam(':idExterne', $projetId);
                            $stmtInsertDoc->execute();
                        }
                    }
                }
            }
            
            // Mise à jour du fichier التقرير الرقابي si fourni
            if (isset($_FILES['fichier_taqrir_update']) && $_FILES['fichier_taqrir_update']['error'] === UPLOAD_ERR_OK) {
                $libDocTaqrir = Security::sanitizeInput($_POST['libDocTaqrir']);
                
                $uploadDir = dirname(__DIR__) . '/uploads/documents/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                $fileName = $_FILES['fichier_taqrir_update']['name'];
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];
                
                if (in_array($fileExtension, $allowedExtensions)) {
                    $newFileName = 'taqrir_' . $projetId . '_' . time() . '.' . $fileExtension;
                    $filePath = $uploadDir . $newFileName;
                    $filePathDB = '../uploads/documents/' . $newFileName;
                    
                    if (move_uploaded_file($_FILES['fichier_taqrir_update']['tmp_name'], $filePath)) {
                        // Vérifier si document existe
                        $sqlCheckDoc = "SELECT idDoc, cheminAcces FROM document WHERE idPro = :idPro AND type = 11";
                        $stmtCheckDoc = $db->prepare($sqlCheckDoc);
                        $stmtCheckDoc->bindParam(':idPro', $projetId, PDO::PARAM_INT);
                        $stmtCheckDoc->execute();
                        
                        if ($stmtCheckDoc->rowCount() > 0) {
                            // Supprimer ancien fichier
                            $oldDoc = $stmtCheckDoc->fetch(PDO::FETCH_ASSOC);
                            $oldFilePath = dirname(__DIR__) . '/' . ltrim($oldDoc['cheminAcces'], './');
                            if (file_exists($oldFilePath)) {
                                unlink($oldFilePath);
                            }
                            
                            // Mettre à jour
                            $sqlUpdateDoc = "UPDATE document SET libDoc = :libDoc, cheminAcces = :cheminAcces WHERE idDoc = :idDoc";
                            $stmtUpdateDoc = $db->prepare($sqlUpdateDoc);
                            $stmtUpdateDoc->bindParam(':libDoc', $libDocTaqrir);
                            $stmtUpdateDoc->bindParam(':cheminAcces', $filePathDB);
                            $stmtUpdateDoc->bindParam(':idDoc', $oldDoc['idDoc'], PDO::PARAM_INT);
                            $stmtUpdateDoc->execute();
                        } else {
                            // Insérer nouveau
                            $sqlInsertDoc = "INSERT INTO document (idPro, libDoc, cheminAcces, type, idExterne) 
                                            VALUES (:idPro, :libDoc, :cheminAcces, 11, :idExterne)";
                            $stmtInsertDoc = $db->prepare($sqlInsertDoc);
                            $stmtInsertDoc->bindParam(':idPro', $projetId);
                            $stmtInsertDoc->bindParam(':libDoc', $libDocTaqrir);
                            $stmtInsertDoc->bindParam(':cheminAcces', $filePathDB);
                            $stmtInsertDoc->bindParam(':idExterne', $projetId);
                            $stmtInsertDoc->execute();
                        }
                    }
                }
            }
            
            // Logger l'action
            $logSql = "INSERT INTO journal (idUser, action, date) VALUES (:idUser, :action, CURDATE())";
            $logStmt = $db->prepare($logSql);
            $logStmt->bindParam(':idUser', $_SESSION['user_id']);
            $action = "تعديل المقترح رقم " . $projetId;
            $logStmt->bindParam(':action', $action);
            $logStmt->execute();
            
            $db->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => 'تم تعديل المقترح بنجاح'
            ], JSON_UNESCAPED_UNICODE);
            
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            echo json_encode([
                'success' => false, 
                'message' => 'حدث خطأ: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

        // Récupération des projets
        $searchQuery = isset($_GET['search']) ? Security::sanitizeInput($_GET['search']) : '';
        $filterEtat = isset($_GET['etat']) ? Security::sanitizeInput($_GET['etat']) : '';
        $filterMinistere = isset($_GET['ministere']) ? Security::sanitizeInput($_GET['ministere']) : '';

        $sql = "SELECT p.*, m.libMinistere, e.libEtablissement, u.nomUser,
                CASE 
                    WHEN p.etat = 0 THEN 'بصدد الدرس'
                    WHEN p.etat = 1 THEN 'الإحالة على اللجنة'
                    WHEN p.etat = 2 THEN 'الموافقة'
                    WHEN p.etat = 3 THEN 'عدم الموافقة'
                    ELSE 'غير معروف'
                END as etatLib,
                (SELECT idDoc FROM document WHERE idPro = p.idPro AND type = 1 LIMIT 1) as docMuqtarahId,
                (SELECT cheminAcces FROM document WHERE idPro = p.idPro AND type = 1 LIMIT 1) as cheminAccesMuqtarah,
                (SELECT cheminAcces FROM document WHERE idPro = p.idPro AND type = 11 LIMIT 1) as cheminAccesTaqrir,
                (SELECT idDoc FROM document WHERE idPro = p.idPro AND type = 11 LIMIT 1) as docTaqrirId
                FROM projet p
                LEFT JOIN ministere m ON p.idMinistere = m.idMinistere
                LEFT JOIN etablissement e ON p.idEtab = e.idEtablissement
                LEFT JOIN user u ON p.idUser = u.idUser
                WHERE 1=1";

        // Filtre selon le rôle
    $filterYear = isset($_GET['year']) ? Security::sanitizeInput($_GET['year']) : '';

        // Récupérer les années disponibles des projets
        $sqlYears = "SELECT DISTINCT YEAR(dateArrive) as year 
                    FROM projet 
                    WHERE dateArrive IS NOT NULL 
                    ORDER BY year DESC";
        $stmtYears = $db->prepare($sqlYears);
        $stmtYears->execute();
        $years = $stmtYears->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($searchQuery)) {
            $sql .= " AND (p.sujet LIKE :search OR m.libMinistere LIKE :search OR e.libEtablissement LIKE :search)";
        }
        if (!empty($filterEtat)) {
            $sql .= " AND p.etat = :etat";
        }
        if (!empty($filterMinistere)) {
            $sqlCount .= " AND p.idMinistere = :ministere";
        }
        
        if (!empty($filterYear)) {
            $sqlCount .= " AND YEAR(p.dateArrive) = :year";
        }
        if (!empty($filterYear)) {
            $sql .= " AND YEAR(p.dateArrive) = :year";
        }
        // PUIS dans les bindParam (pour COUNT):
        if (!empty($filterYear)) {
            $stmtCount->bindParam(':year', $filterYear);
        }

        // ET pour la requête principale:
        if (!empty($filterYear)) {
            $stmt->bindParam(':year', $filterYear);
        }

        $sql .= " ORDER BY p.dateCreation DESC";
        $stmt = $db->prepare($sql);

        if (!empty($searchQuery)) {
            $searchParam = "%{$searchQuery}%";
            $stmt->bindParam(':search', $searchParam);
        }
        if (!empty($filterEtat)) {
            $stmt->bindParam(':etat', $filterEtat);
        }
        if (!empty($filterMinistere)) {
            $stmt->bindParam(':ministere', $filterMinistere);
        }

        $stmt->execute();
        $projets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Liste des ministères
        $sqlMin = "SELECT idMinistere, libMinistere FROM ministere ORDER BY libMinistere";
        $stmtMin = $db->prepare($sqlMin);
        $stmtMin->execute();
        $ministeres = $stmtMin->fetchAll(PDO::FETCH_ASSOC);

        // Liste des rapporteurs (Admin et Rapporteur uniquement)
        $sqlRapp = "SELECT idUser, nomUser FROM user WHERE typeCpt IN (2, 3) ORDER BY nomUser";
        $stmtRapp = $db->prepare($sqlRapp);
        $stmtRapp->execute();
        $rapporteurs = $stmtRapp->fetchAll(PDO::FETCH_ASSOC);

        // Liste des gouvernorats
        $sqlGov = "SELECT idGov, libGov FROM gouvernorat ORDER BY libGov";
        $stmtGov = $db->prepare($sqlGov);
        $stmtGov->execute();
        $gouvernorats = $stmtGov->fetchAll(PDO::FETCH_ASSOC);

        $csrf_token = Security::generateCSRFToken();
        $page_title= "لجنة المشاريع الكبرى - رئاسة الحكومة";
        // Nombre d'éléments par page
        $itemsPerPage = 10;

        // Page actuelle (par défaut 1)
        $currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

        // Calculer l'offset
        $offset = ($currentPage - 1) * $itemsPerPage;

        // ==========================================
        // COMPTER LE NOMBRE TOTAL DE PROJETS (pour la pagination)
        // ==========================================
        $sqlCount = "SELECT COUNT(*) as total
            FROM projet p
            LEFT JOIN ministere m ON p.idMinistere = m.idMinistere
            LEFT JOIN etablissement e ON p.idEtab = e.idEtablissement
            LEFT JOIN user u ON p.idUser = u.idUser
            WHERE 1=1";

        // Ajouter les mêmes filtres que pour la requête principale
        $sqlCount .= Permissions::getProjectsWhereClause();

        if (!empty($searchQuery)) {
        $sqlCount .= " AND (p.sujet LIKE :search OR m.libMinistere LIKE :search OR e.libEtablissement LIKE :search)";
        }
        if (!empty($filterEtat)) {
            $sqlCount .= " AND p.etat = :etat";
        }
        if (!empty($filterMinistere)) {
            $sqlCount .= " AND p.idMinistere = :ministere";
        }
        if (!empty($filterYear)) {
            $sqlCount .= " AND YEAR(p.dateArrive) = :year";
        }

        $stmtCount = $db->prepare($sqlCount);

        if (!empty($searchQuery)) {
            $searchParam = "%{$searchQuery}%";
            $stmtCount->bindParam(':search', $searchParam);
        }
        if (!empty($filterEtat)) {
            $stmtCount->bindParam(':etat', $filterEtat);
        }
        if (!empty($filterMinistere)) {
            $stmtCount->bindParam(':ministere', $filterMinistere);
        }

        $stmtCount->execute();
        $totalItems = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
        $totalPages = ceil($totalItems / $itemsPerPage);

        // Requête principale (reste identique mais avec le filtre année)
        // ...
        if (!empty($filterYear)) {
            $sql .= " AND YEAR(p.dateArrive) = :year";
        }
        // ...
        if (!empty($filterYear)) {
            $stmt->bindParam(':year', $filterYear);
        }

        // ==========================================
        // REQUÊTE PRINCIPALE AVEC LIMIT
        // ==========================================
        $sql = "SELECT p.*, m.libMinistere, e.libEtablissement, u.nomUser,
                CASE 
                    WHEN p.etat = 0 THEN 'بصدد الدرس'
                    WHEN p.etat = 1 THEN 'الإحالة على اللجنة'
                    WHEN p.etat = 2 THEN 'الموافقة'
                    WHEN p.etat = 3 THEN 'عدم الموافقة'
                    ELSE 'غير معروف'
                END as etatLib,
                (SELECT idDoc FROM document WHERE idPro = p.idPro AND type = 1 LIMIT 1) as docMuqtarahId,
                (SELECT cheminAcces FROM document WHERE idPro = p.idPro AND type = 1 LIMIT 1) as cheminAccesMuqtarah,
                (SELECT cheminAcces FROM document WHERE idPro = p.idPro AND type = 11 LIMIT 1) as cheminAccesTaqrir,
                (SELECT idDoc FROM document WHERE idPro = p.idPro AND type = 11 LIMIT 1) as docTaqrirId
                FROM projet p
                LEFT JOIN ministere m ON p.idMinistere = m.idMinistere
                LEFT JOIN etablissement e ON p.idEtab = e.idEtablissement
                LEFT JOIN user u ON p.idUser = u.idUser
                WHERE 1=1";

        $sql .= Permissions::getProjectsWhereClause();

        if (!empty($searchQuery)) {
            $sql .= " AND (p.sujet LIKE :search OR m.libMinistere LIKE :search OR e.libEtablissement LIKE :search)";
        }
        if (!empty($filterEtat)) {
            $sql .= " AND p.etat = :etat";
        }
        
        if (!empty($filterMinistere)) {
            $sql .= " AND p.idMinistere = :ministere";
        }

        $sql .= " ORDER BY p.dateCreation DESC LIMIT :limit OFFSET :offset";

        $stmt = $db->prepare($sql);

        if (!empty($searchQuery)) {
            $searchParam = "%{$searchQuery}%";
            $stmt->bindParam(':search', $searchParam);
        }
        if (!empty($filterEtat)) {
            $stmt->bindParam(':etat', $filterEtat);
        }
        if (!empty($filterMinistere)) {
            $stmt->bindParam(':ministere', $filterMinistere);
        }

        $stmt->bindParam(':limit', $itemsPerPage, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();
        $projets = $stmt->fetchAll(PDO::FETCH_ASSOC);


        // ==========================================
        // FONCTION POUR CONSTRUIRE L'URL DE PAGINATION
        // ==========================================
        function buildPaginationUrl($page) {
            $params = $_GET;
            $params['page'] = $page;
            return 'projets.php?' . http_build_query($params);
        }
        
        // Nombre d'éléments par page
        if (isset($_GET['items_per_page']) && $_GET['items_per_page'] === 'all') {
            // Si "الكل" est sélectionné, afficher tous les résultats
            $itemsPerPage = 999999; // Un grand nombre
            $showAll = true;
        } else {
            $itemsPerPage = isset($_GET['items_per_page']) ? min(100, max(10, intval($_GET['items_per_page']))) : 10;
            $showAll = false;
        }

        // Page actuelle (par défaut 1)
        $currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

        // Calculer l'offset
        $offset = ($currentPage - 1) * $itemsPerPage;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .stats-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-box {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            text-align: center;
        }
        .stat-box .number {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 8px;
        }
        .stat-box .label {
            color: #666;
            font-size: 14px;
        }
        .filters-section {
            background: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); /* Ajusté pour 5 colonnes */
            gap: 20px;
            margin-bottom: 20px;
        }
        .filter-group {
            position: relative;
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
            background: linear-gradient(135deg, #FF6B35 0%, #F7931E 100%);
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
            background: linear-gradient(135deg, #FF6B35 0%, #F7931E 100%);
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
        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
            .badge-pending { background: #fff3cd; color: #856404; }
            .badge-processing { background: #d1ecf1; color: #0c5460; }
            .badge-approved { background: #d4edda; color: #155724; }
            .badge-rejected { background: #f8d7da; color: #721c24; }
            .btn-action {
                padding: 6px 12px;
                border-radius: 6px;
                font-size: 12px;
                text-decoration: none;
                margin: 0 2px;
            }
            .btn-view { background: #17a2b8; color: white; }
            .btn-edit { background: #ffc107; color: #333; }
            .btn-delete { background: #dc3545; color: white; }

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
            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
            .modal-content {
                background-color: white;
                margin: 2% auto;
                border-radius: 15px;
                width: 90%;
                max-width: 900px;
                box-shadow: 0 10px 50px rgba(0,0,0,0.5);
                animation: slideDown 0.4s;
                max-height: 95vh;
                overflow-y: auto;
            }
            @keyframes slideDown {
                from {
                    transform: translateY(-100px);
                    opacity: 0;
                }
                to {
                    transform: translateY(0);
                    opacity: 1;
                }
            }
            .modal-header {
                background: linear-gradient(135deg, #FF6B35 0%, #F7931E 100%);
                color: white;
                padding: 25px 30px;
                border-radius: 15px 15px 0 0;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .modal-header h2 {
                margin: 0;
                font-size: 24px;
            }
            .close {
                color: white;
                font-size: 35px;
                font-weight: bold;
                cursor: pointer;
                line-height: 1;
                transition: transform 0.3s;
            }
            .close:hover {
                transform: scale(1.2);
            }
            .modal-body {
                padding: 30px;
            }
            .form-grid {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
                margin-bottom: 20px;
            }
            .form-group-full {
                grid-column: 1 / -1;
            }
            .form-group label {
                display: block;
                margin-bottom: 8px;
                font-weight: 600;
                color: #333;
            }
            .form-group label .required {
                color: #dc3545;
            }
            .form-control {
                width: 100%;
                padding: 12px;
                border: 2px solid #e0e0e0;
                border-radius: 8px;
                font-size: 14px;
                transition: border-color 0.3s;
                font-family: inherit;
            }
            .form-control:focus {
                outline: none;
                border-color: #667eea;
                box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            }
            textarea.form-control {
                resize: vertical;
                min-height: 100px;
            }
            .modal-footer {
                padding: 20px 30px;
                border-top: 1px solid #e0e0e0;
                display: flex;
                gap: 15px;
                justify-content: center;
            }
            .info-box {
                background: #e7f3ff;
                border-right: 4px solid #2196F3;
                padding: 12px 15px;
                border-radius: 8px;
                margin-bottom: 20px;
                font-size: 14px;
                color: #1565C0;
            }
            .alert {
                padding: 12px 16px;
                border-radius: 8px;
                margin-bottom: 20px;
                font-size: 14px;
            }
            .alert-success {
                background: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
            }
            .alert-error {
                background: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
            }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            @media (max-width: 768px) {
                .form-grid {
                    grid-template-columns: 1fr;
                }
            }
            .pagination-container {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-top: 30px;
                padding: 20px;
                background: white;
                border-radius: 12px;
                box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            }

            .pagination-info {
                color: #666;
                font-size: 14px;
            }

            .pagination {
                display: flex;
                gap: 8px;
                list-style: none;
                padding: 0;
                margin: 0;
            }

            .pagination li {
                display: inline-block;
            }

            .pagination a,
            .pagination span {
                display: inline-block;
                padding: 10px 16px;
                border-radius: 8px;
                text-decoration: none;
                color: #333;
                background: #f5f7fa;
                transition: all 0.3s;
                font-weight: 500;
                min-width: 44px;
                text-align: center;
            }

            .pagination a:hover {
                background: linear-gradient(135deg, #FF6B35 0%, #F7931E 100%);
                color: white;
                transform: translateY(-2px);
            }

            .pagination .active span {
                background: linear-gradient(135deg, #FF6B35 0%, #F7931E 100%);
                color: white;
                box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
            }

            .pagination .disabled {
                opacity: 0.5;
                cursor: not-allowed;
                pointer-events: none;
            }

            .pagination .dots {
                padding: 10px 8px;
                background: transparent;
                color: #999;
            }

            @media (max-width: 768px) {
                .pagination-container {
                    flex-direction: column;
                    gap: 15px;
                }
                
                .pagination {
                    flex-wrap: wrap;
                    justify-content: center;
                }
            }
            /* Ajuster les largeurs des colonnes du tableau */
            table th:nth-child(1), /* الموضوع */
            table td:nth-child(1) {
                width: 15%;
                max-width: 200px;
            }

            /* Ajuster les largeurs des colonnes du tableau */
            table th:nth-child(1), /* الموضوع */
            table td:nth-child(1) {
                width: 18%;
                max-width: 250px;
            }

            table th:nth-child(2), /* الوزارة */
            table td:nth-child(2) {
                width: 11%;
                min-width: 110px;
            }

            table th:nth-child(3), /* المؤسسة */
            table td:nth-child(3) {
                width: 9%;
            }

            table th:nth-child(4), /* تاريخ الوصول */
            table td:nth-child(4) {
                width: 7%;
            }

            table th:nth-child(5), /* الكلفة */
            table td:nth-child(5) {
                width: 7%;
            }

            table th:nth-child(6), /* الحالة */
            table td:nth-child(6) {
                width: 11%;
                min-width: 110px;
            }

            table th:nth-child(7), /* المستخدم */
            table td:nth-child(7) {
                width: 9%;
                min-width: 90px;
            }

            table th:nth-child(8), /* المقترح */
            table td:nth-child(8) {
                width: 3%;
            }

            table th:nth-child(9), /* التقرير الرقابي */
            table td:nth-child(9) {
                width: 3%;
            }

            table th:nth-child(10), /* الإجراءات */
            table td:nth-child(10) {
                width: 18%;
            }

            /* Contrôle du débordement du texte pour الموضوع */
            table td:nth-child(1) {
                white-space: normal;
                word-wrap: break-word;
                overflow-wrap: break-word;
                line-height: 1.4;
            }
            
            .actions-container {
            display: flex;
            flex-direction: column;
            gap: 4px;
            padding: 5px;
        }

        .btn-action {
            padding: 6px 10px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.4s ease;
            border: 2px solid;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: transparent;
            position: relative;
            overflow: hidden;
        }

        .btn-action::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            transition: left 0.4s ease;
            z-index: -1;
        }

        .btn-action:hover::before {
            left: 0;
        }

        /* Bouton Modifier */
        .btn-edit {
            border-color: #ffc107;
            color: #ffc107;
        }

        .btn-edit::before {
            background: linear-gradient(135deg, #FF6B35 0%,#FF6B35);
        }

        .btn-edit:hover {
            color: white;
            border-color: #ff9800;
        }

        /* Bouton Ajouter */
        .btn-add-file {
            border-color: #17a2b8;
            color: #17a2b8;
        }

        .btn-add-file::before {
            background: linear-gradient(135deg, #FF6B35, #FF6B35);
        }

        .btn-add-file:hover {
            color: white;
            border-color: #138496;
        }

        /* Bouton Supprimer */
        .btn-delete {
            border-color: #dc3545;
            color: #dc3545;
        }

        .btn-delete::before {
            background: linear-gradient(135deg, #dc3545, #c82333);
        }

        .btn-delete:hover {
            color: white;
            border-color: #c82333;
        }
        .admin-header {
            background: linear-gradient(135deg, #FF6B35 0%, #F7931E 100%);
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
        .details-modal-content {
            background-color: white;
            margin: 1% auto;
            border-radius: 20px;
            width: 95%;
            max-width: 1200px;
            box-shadow: 0 15px 60px rgba(0,0,0,0.6);
            animation: slideDown 0.4s;
            max-height: 95vh;
            overflow-y: auto;
        }

        .details-header {
            background: linear-gradient(135deg, #FF6B35 0%, #F7931E 100%);
            color: white;
            padding: 30px;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .details-header h2 {
            margin: 0;
            font-size: 28px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .details-body {
            padding: 40px;
        }

        .info-section {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
        }

        .info-section h3 {
            color: #667eea;
            margin-bottom: 20px;
            font-size: 22px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 15px;
            border-bottom: 3px solid #667eea;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .info-item {
            background: white;
            padding: 15px;
            border-radius: 10px;
            border-right: 4px solid #667eea;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .info-item label {
            display: block;
            font-weight: 700;
            color: #555;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .info-item .value {
            color: #333;
            font-size: 16px;
            word-wrap: break-word;
        }

        .full-width {
            grid-column: 1 / -1;
        }

        .documents-section {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 25px;
            margin-top: 25px;
        }

        .documents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .document-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: all 0.3s;
            border-top: 4px solid #667eea;
        }

        .document-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }

        .doc-type {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .doc-type-1 { background: #e3f2fd; color: #1976d2; }
        .doc-type-11 { background: #f3e5f5; color: #7b1fa2; }
        .doc-type-20 { background: #e8f5e9; color: #388e3c; }
        .doc-type-21 { background: #fff3e0; color: #f57c00; }

        .doc-title {
            font-weight: 600;
            color: #333;
            margin: 10px 0;
            font-size: 16px;
            line-height: 1.4;
        }

        .doc-actions {
            margin-top: 15px;
            display: flex;
            gap: 10px;
        }

        .btn-view-doc {
            flex: 1;
            padding: 10px 15px;
            background: linear-gradient(135deg, #FF6B35 0%, #F7931E 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-view-doc:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .no-documents {
            text-align: center;
            padding: 40px;
            color: #999;
            font-size: 16px;
        }

        .status-badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 25px;
            font-weight: 600;
            font-size: 14px;
        }

        @media (max-width: 768px) {
            .details-modal-content {
                width: 98%;
                margin: 2% auto;
            }
            
            .details-body {
                padding: 20px;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .documents-grid {
                grid-template-columns: 1fr;
            }
        }
            .details-modal-content {
            background-color: white;
            margin: 1% auto;
            border-radius: 20px;
            width: 95%;
            max-width: 1400px;
            box-shadow: 0 15px 60px rgba(0,0,0,0.6);
            animation: slideDown 0.4s;
            max-height: 95vh;
            overflow-y: auto;
        }

        .details-header {
            background: linear-gradient(135deg, #FF6B35 0%, #F7931E 100%);
            color: white;
            padding: 30px;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .details-header h2 {
            margin: 0;
            font-size: 28px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .details-body {
            padding: 30px;
        }

        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.08);
            border-radius: 12px;
            overflow: hidden;
        }

        .details-table thead {
            background: linear-gradient(135deg, #FF6B35 0%, #F7931E 100%);
            color: white;
        }

        .details-table th {
            padding: 15px;
            text-align: center;
            font-weight: 700;
            font-size: 16px;
        }

        .details-table td {
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
            text-align: right;
        }

        .details-table tbody tr:hover {
            background: #f8f9fa;
        }

        .details-table tbody tr:last-child td {
            border-bottom: none;
        }

        .label-cell {
            background: #f8f9fa;
            font-weight: 700;
            color: #555;
            width: 25%;
            text-align: center;
        }

        .value-cell {
            color: #333;
            font-size: 15px;
        }

        .full-row td {
            text-align: right;
            line-height: 1.8;
        }

        .section-title {
            background: linear-gradient(135deg, #FF6B35 0%, #F7931E 100%);
            color: white;
            padding: 12px 20px;
            border-radius: 10px;
            margin: 30px 0 20px 0;
            font-size: 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .documents-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.08);
            border-radius: 12px;
            overflow: hidden;
        }

        .documents-table thead {
            background: linear-gradient(135deg, #FF6B35 0%, #F7931E 100%);
            color: white;
        }

        .documents-table th {
            padding: 15px;
            text-align: center;
            font-weight: 700;
            font-size: 15px;
        }

        .documents-table td {
            padding: 12px;
            border-bottom: 1px solid #e0e0e0;
            text-align: center;
        }

        .documents-table tbody tr:hover {
            background: #f8f9fa;
        }

        .doc-type-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .doc-type-1 { background: #e3f2fd; color: #1976d2; }
        .doc-type-11 { background: #f3e5f5; color: #7b1fa2; }
        .doc-type-20 { background: #e8f5e9; color: #388e3c; }
        .doc-type-21 { background: #fff3e0; color: #f57c00; }

        .btn-view-doc {
            padding: 8px 20px;
            background: linear-gradient(135deg, #FF6B35 0%, #F7931E 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-view-doc:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .no-documents {
            text-align: center;
            padding: 60px 20px;
            color: #999;
            font-size: 16px;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .status-badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 25px;
            font-weight: 600;
            font-size: 14px;
        }

        @media (max-width: 768px) {
            .details-modal-content {
                width: 98%;
                margin: 2% auto;
            }
            
            .details-body {
                padding: 15px;
            }
            
            .details-table th,
            .details-table td {
                padding: 10px;
                font-size: 13px;
            }
            
            .label-cell {
                width: 35%;
            }
        }   
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <section class="content-section" style="padding: 40px 0;">
        <div class="container">
            <div class="admin-header">
                <h2>📋 قائمة المقترحات</h2>
            </div>
             <div class="filters-section">
                <form method="GET">
                    <div class="filters-grid">
                        <!-- Recherche -->
                        <div class="filter-group">
                            <label>البحث</label>
                            <input type="text" name="search" placeholder="ابحث عن مقترح..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                        </div>
                        
                        <!-- الوزارة -->
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
                        
                        <!-- المؤسسات -->
                        <div class="filter-group">
                            <label>المؤسسات</label>
                            <select name="ministere">
                                <option value="">جميع المؤسسات</option>
                                <option value=""> </option>
                            </select>
                        </div>
                        
                        <!-- الحالة -->
                        <div class="filter-group">
                            <label>الحالة</label>
                            <select name="etat">
                                <option value="">جميع الحالات</option>
                                <option value="0" <?php echo $filterEtat === '0' ? 'selected' : ''; ?>>بصدد الدرس</option>
                                <option value="1" <?php echo $filterEtat === '1' ? 'selected' : ''; ?>>الإحالة على اللجنة</option>
                                <option value="2" <?php echo $filterEtat === '2' ? 'selected' : ''; ?>>الموافقة</option>
                                <option value="3" <?php echo $filterEtat === '3' ? 'selected' : ''; ?>>عدم الموافقة</option>
                            </select>
                        </div>
                        
                        <!-- ✨ NOUVEAU: السنة -->
                        <div class="filter-group">
                            <label>السنة</label>
                            <select name="year">
                                <option value="">جميع السنوات</option>
                                <?php foreach ($years as $year): ?>
                                    <option value="<?php echo $year['year']; ?>" 
                                            <?php echo $filterYear == $year['year'] ? 'selected' : ''; ?>>
                                        <?php echo $year['year']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">🔍 بحث</button>
                        <a href="projets.php" class="btn btn-secondary">🔄 إعادة تعيين</a>
                        <?php if (Permissions::canCreateProjet()): ?>
                            <button type="button" class="btn btn-success" id="btnOpenModal">➕ إضافة مقترح</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <div class="projects-table">
                <?php if (count($projets) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>الموضوع</th>
                                <th>الوزارة</th>
                                <th>المؤسسة</th>
                                <th>الكلفة (م.د.ت)</th>
                                <th>الحالة</th>
                                <th>المستخدم</th>
                                <th>المقترح</th>
                                <th>التقرير الرقابي</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($projets as $projet): ?>
                                <tr>
                                    <td style="text-align: right;">
                                        <a href="javascript:void(0)" 
                                        onclick="openDetailsModal(<?php echo $projet['idPro']; ?>)"
                                        style="color: #667eea; text-decoration: none; font-weight: 600; cursor: pointer; transition: color 0.3s;"
                                        onmouseover="this.style.color='#764ba2'"
                                        onmouseout="this.style.color='#667eea'">
                                            <?php echo htmlspecialchars(substr($projet['sujet'], 0, 300)); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($projet['libMinistere']); ?></td>
                                    <td><?php echo htmlspecialchars($projet['libEtablissement']); ?></td>
                                    <td><?php echo number_format($projet['cout'], 2, '.', ' '); ?></td>
                                    <td>
                                        <span class="badge <?php 
                                            switch($projet['etat']) {
                                                case 0: echo 'badge-pending'; break;
                                                case 1: echo 'badge-processing'; break;
                                                case 2: echo 'badge-approved'; break;
                                                case 3: echo 'badge-rejected'; break;
                                                default: echo 'badge-pending';
                                            }
                                        ?>">
                                            <?php echo $projet['etatLib']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($projet['nomUser']); ?></td>
                                    <td>
                                        <?php if ($projet['docMuqtarahId']): ?>
                                            <a href="<?php echo $projet['cheminAccesMuqtarah'];?>" target="_blank" class="btn-action"
                                                style="background: #dddeddff; color: white; padding: 6px 10px; border-radius: 6px; text-decoration: none; font-size: 12px; display: inline-flex; align-items: center; gap: 4px;">
                                               👁️
                                            </a>
                                        <?php else: ?>
                                            <span style="color: #dddeddff;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td style="text-align: center;">
                                        <?php if (!empty($projet['cheminAccesTaqrir']) && $projet['docTaqrirId']): ?>
                                            <!-- Document existe: Boutons Voir + Modifier -->
                                            <div style="display: flex; gap: 5px; justify-content: center; align-items: center;">
                                                <a href="<?php echo htmlspecialchars($projet['cheminAccesTaqrir']); ?>" 
                                                target="_blank"
                                                class="btn-action"
                                                style="background: #dddeddff; color: white; padding: 6px 10px; border-radius: 6px; text-decoration: none; font-size: 12px; display: inline-flex; align-items: center; gap: 4px;"
                                                title="عرض التقرير الرقابي">
                                                    <span>👁️</span>
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <!-- Document n'existe pas: Bouton Ajouter -->
                                            <?php if (Permissions::canEditProjet($projet['idUser'])): ?>
                                                <button onclick="openTaqrirModal(<?php echo $projet['idPro']; ?>)" 
                                                        class="btn-action"
                                                        style="background: linear-gradient(135deg, #FF6B35 0%, #F7931E 100%); color: white; border: none; padding: 8px 16px; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 600; box-shadow: 0 2px 8px rgba(255,152,0,0.3); transition: all 0.3s; display: inline-flex; align-items: center; gap: 6px;"
                                                        onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(255,152,0,0.4)'"
                                                        onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(255,152,0,0.3)'"
                                                        title="إضافة التقرير الرقابي">
                                                    <span>إضافة</span>
                                                </button>
                                            <?php else: ?>
                                                <span style="color: #999; font-size: 14px;">لا يوجد</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="actions-container">
                                            <?php if (Permissions::canEditProjet($projet['idUser'])): ?>
                                                <!-- Bouton Modifier -->
                                                <button onclick="openEditModal(<?php echo $projet['idPro']; ?>)" 
                                                        class="btn-action btn-edit"
                                                        title="تعديل المقترح">
                                                        تعديل
                                                </button>
                                                
                                                <!-- Bouton Ajouter fichier -->
                                                <button onclick="openAddFileModal(<?php echo $projet['idPro']; ?>)" 
                                                        class="btn-action btn-add-file"
                                                        title="إضافة ملف جديد">
                                                    ملف
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if (Permissions::canDeleteProjet($projet['idUser'])): ?>
                                                <!-- Bouton Supprimer -->
                                                <button onclick="confirmDelete(<?php echo $projet['idPro']; ?>)" 
                                                        class="btn-action btn-delete"
                                                        title="حذف المقترح">
                                                    حذف
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>    
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="text-align: center; padding: 40px; color: #666;">لا توجد مقترحات</p>
                <?php endif; ?>
            </div>
                <?php if ($totalPages > 1): ?>
                    <div class="pagination-container">
                        <div class="pagination-info">
                            عرض <?php echo (($currentPage - 1) * $itemsPerPage) + 1; ?> - 
                            <?php echo min($currentPage * $itemsPerPage, $totalItems); ?> 
                            من أصل <?php echo $totalItems; ?> مقترح
                        </div>
                        
                        <ul class="pagination">
                            <!-- Bouton Précédent -->
                            <li class="<?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                <?php if ($currentPage > 1): ?>
                                    <a href="<?php echo buildPaginationUrl($currentPage - 1); ?>">« السابق</a>
                                <?php else: ?>
                                    <span>« السابق</span>
                                <?php endif; ?>
                            </li>
                            
                            <?php
                            // Logique d'affichage des numéros de page
                            $range = 2; // Nombre de pages à afficher de chaque côté
                            
                            // Première page
                            if ($currentPage > $range + 1) {
                                echo '<li><a href="' . buildPaginationUrl(1) . '">1</a></li>';
                                if ($currentPage > $range + 2) {
                                    echo '<li><span class="dots">...</span></li>';
                                }
                            }
                            
                            // Pages autour de la page actuelle
                            for ($i = max(1, $currentPage - $range); $i <= min($totalPages, $currentPage + $range); $i++) {
                                if ($i == $currentPage) {
                                    echo '<li class="active"><span>' . $i . '</span></li>';
                                } else {
                                    echo '<li><a href="' . buildPaginationUrl($i) . '">' . $i . '</a></li>';
                                }
                            }
                            
                            // Dernière page
                            if ($currentPage < $totalPages - $range) {
                                if ($currentPage < $totalPages - $range - 1) {
                                    echo '<li><span class="dots">...</span></li>';
                                }
                                echo '<li><a href="' . buildPaginationUrl($totalPages) . '">' . $totalPages . '</a></li>';
                            }
                            ?>
                            
                            <!-- Bouton Suivant -->
                            <li class="<?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                                <?php if ($currentPage < $totalPages): ?>
                                    <a href="<?php echo buildPaginationUrl($currentPage + 1); ?>">التالي »</a>
                                <?php else: ?>
                                    <span>التالي »</span>
                                <?php endif; ?>
                            </li>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <!-- ==========================================
                        OPTION: Sélecteur du nombre d'éléments par page
                        ========================================== -->
                    <!-- REMPLACER toute la section "items-per-page" par: -->
                    <div class="items-per-page" style="margin-top: 15px; text-align: center;">
                        <label style="color: #666; font-size: 14px; margin-left: 10px;">عدد المقترحات في الصفحة:</label>
                        <select id="itemsPerPageSelect" style="padding: 8px 12px; border: 2px solid #e0e0e0; border-radius: 6px; font-size: 14px;">
                            <option value="all">الكل</option>
                            <option value="10" <?php echo (!isset($_GET['items_per_page']) || $_GET['items_per_page'] == 10) ? 'selected' : ''; ?>>10</option>
                            <option value="25" <?php echo (isset($_GET['items_per_page']) && $_GET['items_per_page'] == 25) ? 'selected' : ''; ?>>25</option>
                            <option value="50" <?php echo (isset($_GET['items_per_page']) && $_GET['items_per_page'] == 50) ? 'selected' : ''; ?>>50</option>
                            <option value="100" <?php echo (isset($_GET['items_per_page']) && $_GET['items_per_page'] == 100) ? 'selected' : ''; ?>>100</option>
                        </select>
                    </div>
        </div>
    </section>

    <!-- Modal d'ajout  -->
    <div id="addProjetModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>➕ إضافة مقترح </h2>
                <span class="close" id="btnCloseModal">&times;</span>
            </div>
            <div class="modal-body">
                <div id="modalAlert"></div>
                <form id="addProjetForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="add_projet">
                    
                    <div class="form-grid">
                        <!-- 1. الموضوع -->
                        <div class="form-group form-group-full">
                            <label>الموضوع <span class="required">*</span></label>
                            <textarea name="sujet" class="form-control" required 
                                      placeholder=" موضوع المقترح ..."></textarea>
                        </div>
                        
                        <!-- 2. الوزارة -->
                        <div class="form-group">
                            <label>الوزارة <span class="required">*</span></label>
                            <select name="idMinistere" id="modalMinistere" class="form-control" required>
                                <option value="">-- اختر الوزارة --</option>
                                <?php foreach ($ministeres as $min): ?>
                                    <option value="<?php echo $min['idMinistere']; ?>">
                                        <?php echo htmlspecialchars($min['libMinistere']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- 3. المؤسسة -->
                        <div class="form-group">
                            <label>المؤسسة <span class="required">*</span></label>
                            <select name="idEtab" id="modalEtab" class="form-control" required>
                                <option value="">--أختر الوزارة --</option>
                            </select>
                        </div>
                        
                        <!-- 4. تاريخ الإعلام -->
                        <div class="form-group">
                            <label> تاريخ التعهد <span class="required">*</span></label>
                            <input type="date" name="dateArrive" class="form-control" required 
                                   value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <!-- 5. الإجراء -->
                        <div class="form-group">
                            <label>صيغة المشروع <span class="required">*</span></label>
                            <select name="procedurePro" class="form-control" required>
                                <option value="">-- اختر الصيغة --</option>
                                <option value="جديد"> مشروع جديد </option>
                                <option value="بصدد الإنجاز">بصدد الإنجاز</option>
                            </select>
                        </div>
                        
                        <!-- 6. الكلفة -->
                        <div class="form-group">
                            <label>الكلفة التقديرية (د.ت) <span class="required">*</span></label>
                            <input type="number" name="cout" class="form-control" required 
                                   step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label>الولاية <span class="required">*</span></label>
                                <select name="id_Gov" id="editGouvernorat" class="form-control" required>
                                    <option value="">-- اختر الولاية --</option>
                                    <?php foreach ($gouvernorats as $gov): ?>
                                        <option value="<?php echo $gov['idGov']; ?>">
                                            <?php echo htmlspecialchars($gov['libGov']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                        </div>
                        
                        <!-- 8. المقترح -->
                        <div class="form-group form-group-full">
                            <label>المقترح <span class="required">*</span></label>
                            <textarea name="proposition" class="form-control" required 
                                      placeholder="أدخل تفاصيل المقترح والتوصيات..."></textarea>
                        </div>
                        
                        <!-- 9. المقرر -->
                        <div class="form-group form-group-full">
                            <label>المقرر  <span class="required">*</span></label>
                            <select name="idRapporteur" class="form-control" required>
                                <option value="">-- اختر المقرر --</option>
                                <?php foreach ($rapporteurs as $rapp): ?>
                                    <option value="<?php echo $rapp['idUser']; ?>"
                                            <?php echo ($rapp['idUser'] == $_SESSION['user_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($rapp['nomUser']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                       
                        
                        <!-- 11. الملف -->
                        <div class="form-group ">
                            <label>الملف (PDF, Word, Excel) <span class="required">*</span></label>
                            <input type="file" name="fichier" id="fichier" class="form-control" 
                                   accept=".pdf,.doc,.docx,.xls,.xlsx" required>
                            <small style="color: #666; font-size: 12px; display: block; margin-top: 5px;">
                                الحجم الأقصى: 5MB - الأنواع المقبولة: PDF, Word, Excel
                            </small>
                        </div>
                         <!-- 10. عنوان الملف -->
                        <div class="form-group">
                            <label>عنوان الملف <span class="required">*</span></label>
                            <input type="text" name="libDoc" class="form-control" required 
                                   placeholder="أدخل عنوان المقترح">
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-success">✓ حفظ المقترح</button>
                        <button type="button" class="btn btn-secondary" id="btnCancelModal">✕ إلغاء</button>
                    </div>
                </form>
            </div>
        </div>
        </div>
    <!-- MODAL AJOUT التقرير الرقابي -->
    <div id="taqrirModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>📊 إضافة التقرير الرقابي</h2>
                <span class="close" id="btnCloseTaqrir">&times;</span>
            </div>
            <div class="modal-body">
                <div id="taqrirAlert"></div>
                
                <form id="taqrirForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="upload_taqrir">
                    <input type="hidden" name="projetId" id="taqrirProjetId">
                    
                    <div class="form-group">
                        <label>عنوان التقرير <span class="required">*</span></label>
                        <input type="text" name="libDoc" class="form-control" required 
                               placeholder="أدخل عنوان التقرير الرقابي">
                    </div>
                    
                    <div class="form-group">
                        <label>الملف (PDF, Word, Excel) <span class="required">*</span></label>
                        <input type="file" name="fichier_taqrir" id="fichier_taqrir" class="form-control" 
                               accept=".pdf,.doc,.docx,.xls,.xlsx" required>
                        <small style="color: #666; font-size: 12px; display: block; margin-top: 5px;">
                            الحجم الأقصى: 5MB
                        </small>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-success">✓ رفع التقرير</button>
                        <button type="button" class="btn btn-secondary" id="btnCancelTaqrir">✕ إلغاء</button>
                    </div>
                </form>
            </div>
        </div>
     </div>
    <!-- MODAL MODIFICATION PROJET -->
    <div id="editProjetModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>✏️ تعديل المقترح</h2>
                    <span class="close" id="btnCloseEdit">&times;</span>
                </div>
                <div class="modal-body">
                    <div id="editModalAlert"></div>
                    
                    <form id="editProjetForm" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="update_projet">
                        <input type="hidden" name="projetId" id="editProjetId">
                        
                        <div class="form-grid">
                            <!-- 1. الموضوع -->
                            <div class="form-group form-group-full">
                                <label>الموضوع <span class="required">*</span></label>
                                <textarea name="sujet" id="editSujet" class="form-control" required 
                                        placeholder="موضوع المقترح..."></textarea>
                            </div>
                            
                            <!-- 2. الوزارة -->
                            <div class="form-group">
                                <label>الوزارة <span class="required">*</span></label>
                                <select name="idMinistere" id="editMinistere" class="form-control" required>
                                    <option value="">-- اختر الوزارة --</option>
                                    <?php foreach ($ministeres as $min): ?>
                                        <option value="<?php echo $min['idMinistere']; ?>">
                                            <?php echo htmlspecialchars($min['libMinistere']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- 3. المؤسسة -->
                            <div class="form-group">
                                <label>المؤسسة <span class="required">*</span></label>
                                <select name="idEtab" id="editEtab" class="form-control" required>
                                    <option value="">-- اختر الوزارة أولاً --</option>
                                </select>
                            </div>
                            
                            <!-- 4. تاريخ التعهد -->
                            <div class="form-group">
                                <label>تاريخ التعهد <span class="required">*</span></label>
                                <input type="date" name="dateArrive" id="editDateArrive" class="form-control" required>
                            </div>
                            
                            <!-- 5. الإجراء -->
                            <div class="form-group">
                                <label>صيغة المشروع <span class="required">*</span></label>
                                <select name="procedurePro" id="editProcedure" class="form-control" required>
                                    <option value="">-- اختر الصيغة --</option>
                                    <option value="جديد">مشروع جديد</option>
                                    <option value="بصدد الإنجاز">بصدد الإنجاز</option>
                                </select>
                            </div>
                            
                            <!-- 6. الكلفة -->
                            <div class="form-group">
                                <label>الكلفة التقديرية (د.ت) <span class="required">*</span></label>
                                <input type="number" name="cout" id="editCout" class="form-control" required 
                                    step="0.01" min="0" placeholder="0.00">
                            </div>
                            <!-- 7. الولاية -->
                            <div class="form-group">
                                <label>الولاية <span class="required">*</span></label>
                                <select name="id_Gov" id="modalGouvernorat" class="form-control" required>
                                    <option value="">-- اختر الولاية --</option>
                                    <?php foreach ($gouvernorats as $gov): ?>
                                        <option value="<?php echo $gov['idGov']; ?>">
                                            <?php echo htmlspecialchars($gov['libGov']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- 8. المقترح -->
                            <div class="form-group form-group-full">
                                <label>المقترح <span class="required">*</span></label>
                                <textarea name="proposition" id="editProposition" class="form-control" required 
                                        placeholder="أدخل تفاصيل المقترح والتوصيات..."></textarea>
                            </div>
                            
                            <!-- 9. المقرر -->
                            <div class="form-group">
                                <label>المقرر  <span class="required">*</span></label>
                                <select name="idRapporteur" id="editRapporteur" class="form-control" required>
                                    <option value="">-- اختر المقرر --</option>
                                    <?php foreach ($rapporteurs as $rapp): ?>
                                        <option value="<?php echo $rapp['idUser']; ?>">
                                            <?php echo htmlspecialchars($rapp['nomUser']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <!-- 10. ملف المقترح -->
                            <div class="form-group form-group-full">
                                <label>المقترح</label>
                                <input type="text" name="libDocMuqtarah" id="editLibDocMuqtarah" 
                                    class="form-control" placeholder="عنوان الملف" style="margin-bottom: 10px;">
                                <input type="file" name="fichier_muqtarah" id="editFichierMuqtarah" 
                                    class="form-control" accept=".pdf,.doc,.docx,.xls,.xlsx">
                                <small style="color: #666; font-size: 12px; display: block; margin-top: 5px;">
                                    اترك فارغاً للاحتفاظ بالملف الحالي
                                </small>
                            </div>
                            
                            <!-- 11. ملف التقرير الرقابي -->
                            <div class="form-group form-group-full">
                                <label>تحديث التقرير الرقابي</label>
                                <input type="text" name="libDocTaqrir" id="editLibDocTaqrir" 
                                    class="form-control" placeholder="عنوان التقرير" style="margin-bottom: 10px;">
                                <input type="file" name="fichier_taqrir_update" id="editFichierTaqrir" 
                                    class="form-control" accept=".pdf,.doc,.docx,.xls,.xlsx">
                                <small style="color: #666; font-size: 12px; display: block; margin-top: 5px;">
                                    اترك فارغاً للاحتفاظ بالملف الحالي
                                </small>
                            </div>
                        </div>
                        
                        <div class="modal-footer">
                            <button type="submit" class="btn btn-success">✓ حفظ التعديلات</button>
                            <button type="button" class="btn btn-secondary" id="btnCancelEdit">✕ إلغاء</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>                                
    <!-- MODAL DE CONFIRMATION DE SUPPRESSION -->
    <div id="deleteModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #FF6B35 0%, #F7931E 100%);">
                <h2>⚠️ تأكيد الحذف</h2>
                <span class="close" id="btnCloseDelete">&times;</span>
            </div>
            <div class="modal-body">
                <div id="deleteAlert"></div>
                
                <p style="text-align: center; font-size: 18px; color: #333; margin: 30px 0;">
                    هل أنت متأكد من حذف هذا المقترح؟
                </p>
                <p style="text-align: center; font-size: 14px; color: #dc3545; margin-bottom: 30px;">
                    ⚠️ هذا الإجراء لا يمكن التراجع عنه!
                </p>
                
                <form id="deleteForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="delete_projet">
                    <input type="hidden" name="projetId" id="deleteProjetId">
                    
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-danger" style="background: #dc3545;">
                            ✓ نعم، احذف
                        </button>
                        <button type="button" class="btn btn-secondary" id="btnCancelDelete">
                            ✕ إلغاء
                        </button>
                    </div>
                </form>
            </div>
        </div>
    
        </div>

    <!-- MODAL AJOUT FICHIER SUPPLÉMENTAIRE -->
    <div id="addFileModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>📎 إضافة ملف جديد</h2>
                <span class="close" id="btnCloseAddFile">&times;</span>
            </div>
            <div class="modal-body">
                <div id="addFileAlert"></div>
                
                <form id="addFileForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="add_file">
                    <input type="hidden" name="projetId" id="addFileProjetId">
                    
                    <div class="form-group">
                        <label>عنوان الملف <span class="required">*</span></label>
                        <input type="text" name="libDoc" class="form-control" required 
                            placeholder="أدخل عنوان الملف">
                    </div>
                    
                    <div class="form-group">
                        <label>نوع الملف <span class="required">*</span></label>
                        <select name="typeDoc" class="form-control" required>
                            <option value="">-- اختر النوع --</option>
                            <option value="مراسلة">مراسلة</option>
                            <option value="اخرى">اخرى</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>الملف (PDF, Word, Excel) <span class="required">*</span></label>
                        <input type="file" name="fichier_supplementaire" id="fichier_supplementaire" 
                            class="form-control" accept=".pdf,.doc,.docx,.xls,.xlsx" required>
                        <small style="color: #666; font-size: 12px; display: block; margin-top: 5px;">
                            الحجم الأقصى: 10MB
                        </small>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-success">✓ رفع الملف</button>
                        <button type="button" class="btn btn-secondary" id="btnCancelAddFile">✕ إلغاء</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- MODAL DÉTAILS DU PROJET -->
    <div id="detailsModal" class="modal">
        <div class="details-modal-content">
            <div class="details-header">
                <h2>
                    <span>📋</span>
                    <span id="detailsTitle">تفاصيل المقترح</span>
                </h2>
                <span class="close" id="btnCloseDetails">&times;</span>
            </div>
            
            <div class="details-body">
                <div id="detailsContent">
                    <div style="text-align: center; padding: 40px;">
                        <div style="display: inline-block; border: 4px solid #f3f3f3; border-top: 4px solid #667eea; border-radius: 50%; width: 50px; height: 50px; animation: spin 1s linear infinite;"></div>
                        <p style="margin-top: 20px; color: #666;">جاري التحميل...</p>
                    </div>
                </div>
            </div>
        </div>
        </div>
   
     <?php include 'includes/footer.php'; ?>

    <script>
        // Variables globales
        var modal = document.getElementById('addProjetModal');
        var btnOpen = document.getElementById('btnOpenModal');
        var btnClose = document.getElementById('btnCloseModal');
        var btnCancel = document.getElementById('btnCancelModal');
        
        var taqrirModal = document.getElementById('taqrirModal');
        var btnCloseTaqrir = document.getElementById('btnCloseTaqrir');
        var btnCancelTaqrir = document.getElementById('btnCancelTaqrir');
        
        // Ouvrir modal التقرير الرقابي
        function openTaqrirModal(projetId) {
            document.getElementById('taqrirProjetId').value = projetId;
            taqrirModal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        // Fermer modal التقرير الرقابي
        function closeTaqrirModal() {
            taqrirModal.classList.remove('show');
            document.body.style.overflow = 'auto';
            document.getElementById('taqrirForm').reset();
            document.getElementById('taqrirAlert').innerHTML = '';
        }
        
        if (btnCloseTaqrir) {
            btnCloseTaqrir.onclick = closeTaqrirModal;
        }
        
        if (btnCancelTaqrir) {
            btnCancelTaqrir.onclick = closeTaqrirModal;
        }
        
        // Soumettre التقرير الرقابي
        document.getElementById('taqrirForm').onsubmit = function(e) {
            e.preventDefault();
            
            var formData = new FormData(this);
            var alertDiv = document.getElementById('taqrirAlert');
            
            alertDiv.innerHTML = '<div style="text-align: center; padding: 15px;"><div style="display: inline-block; border: 3px solid #f3f3f3; border-top: 3px solid #ff9800; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite;"></div><p style="margin-top: 10px;">جاري الرفع...</p></div>';
            
            fetch('projets.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alertDiv.innerHTML = '<div class="alert alert-success">✓ ' + data.message + '</div>';
                    setTimeout(function() {
                        window.location.reload();
                    }, 1500);
                } else {
                    alertDiv.innerHTML = '<div class="alert alert-error">✕ ' + data.message + '</div>';
                }
            })
            .catch(function(error) {
                console.error('Error:', error);
                alertDiv.innerHTML = '<div class="alert alert-error">✕ حدث خطأ في الاتصال</div>';
            });
        };
        
        // Validation fichier التقرير الرقابي
        document.getElementById('fichier_taqrir').onchange = function() {
            var file = this.files[0];
            if (file) {
                var fileSize = file.size / 1024 / 1024;
                var allowedTypes = ['application/pdf', 'application/msword', 
                                   'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                   'application/vnd.ms-excel',
                                   'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
                
                if (fileSize > 10) {
                    alert('حجم الملف يجب أن يكون أقل من 5 ميغابايت');
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
        
        // Ouvrir le modal ajout projet
        if (btnOpen) {
            btnOpen.onclick = function() {
                modal.classList.add('show');
                document.body.style.overflow = 'hidden';
            }
        }
        
        // Fermer le modal ajout projet
        function fermerModal() {
            modal.classList.remove('show');
            document.body.style.overflow = 'auto';
            document.getElementById('addProjetForm').reset();
            document.getElementById('modalEtab').disabled = true;
            document.getElementById('modalAlert').innerHTML = '';
        }
        
        if (btnClose) {
            btnClose.onclick = fermerModal;
        }
        if (btnCancel) {
            btnCancel.onclick = fermerModal;
        }
        
        // Fermer en cliquant à l'extérieur
        window.onclick = function(event) {
            if (event.target == modal) {
                fermerModal();
            }
            if (event.target == taqrirModal) {
                closeTaqrirModal();
            }
        }
        
        // Charger les établissements
        document.getElementById('modalMinistere').onchange = function() {
            var ministereId = this.value;
            var etabSelect = document.getElementById('modalEtab');
            
            etabSelect.innerHTML = '<option value="">جاري التحميل...</option>';
            
            if (ministereId) {
                fetch('get_etablissements.php?ministere=' + ministereId)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.etablissements.length > 0) {
                            etabSelect.innerHTML = '<option value="">-- الوزارة --</option>';
                            data.etablissements.forEach(function(etab) {
                                var option = document.createElement('option');
                                option.value = etab.idEtablissement;
                                option.textContent = etab.libEtablissement;
                                etabSelect.appendChild(option);
                            });
                        } else {
                            etabSelect.innerHTML = '<option value="">-- الوزارة --</option>';
                        }
                    })
                    .catch(function(error) {
                        console.error('Error:', error);
                        etabSelect.innerHTML = '<option value="">-- الوزارة --</option>';
                    });
            } else {
                etabSelect.innerHTML = '<option value="">-- الوزارة --</option>';
            }
        };
        
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
                    alert('حجم الملف يجب أن يكون أقل من 5 ميغابايت');
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
        
        // Soumettre le formulaire
        document.getElementById('addProjetForm').onsubmit = function(e) {
            e.preventDefault();
            
            var formData = new FormData(this);
            var alertDiv = document.getElementById('modalAlert');
            
            alertDiv.innerHTML = '<div style="text-align: center; padding: 15px;"><div style="display: inline-block; border: 3px solid #f3f3f3; border-top: 3px solid #667eea; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite;"></div><p style="margin-top: 10px;">جاري الحفظ...</p></div>';
            
            fetch('projets.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alertDiv.innerHTML = '<div class="alert alert-success">✓ ' + data.message + '</div>';
                    setTimeout(function() {
                        window.location.reload();
                    }, 1500);
                } else {
                    alertDiv.innerHTML = '<div class="alert alert-error">✕ ' + data.message + '</div>';
                }
            })
            .catch(function(error) {
                console.error('Error:', error);
                alertDiv.innerHTML = '<div class="alert alert-error">✕ حدث خطأ في الاتصال</div>';
            });
        };
        // REMPLACER la fonction de changement d'éléments par page:
        document.getElementById('itemsPerPageSelect')?.addEventListener('change', function() {
            var params = new URLSearchParams(window.location.search);
            
            if (this.value === 'all') {
                params.set('items_per_page', 'all');
            } else {
                params.set('items_per_page', this.value);
            }
            
            params.delete('page'); // Revenir à la première page
            window.location.href = 'projets.php?' + params.toString();
        });

        // Variables pour le modal de suppression
        var deleteModal = document.getElementById('deleteModal');
        var btnCloseDelete = document.getElementById('btnCloseDelete');
        var btnCancelDelete = document.getElementById('btnCancelDelete');

        // Fonction pour ouvrir le modal de confirmation de suppression
        function confirmDelete(projetId) {
            document.getElementById('deleteProjetId').value = projetId;
            deleteModal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        // Fonction pour fermer le modal de suppression
        function closeDeleteModal() {
            deleteModal.classList.remove('show');
            document.body.style.overflow = 'auto';
            document.getElementById('deleteForm').reset();
            document.getElementById('deleteAlert').innerHTML = '';
        }

        if (btnCloseDelete) {
            btnCloseDelete.onclick = closeDeleteModal;
        }

        if (btnCancelDelete) {
            btnCancelDelete.onclick = closeDeleteModal;
        }

        // Soumettre le formulaire de suppression
        document.getElementById('deleteForm').onsubmit = function(e) {
            e.preventDefault();
            
            var formData = new FormData(this);
            var alertDiv = document.getElementById('deleteAlert');
            
            alertDiv.innerHTML = '<div style="text-align: center; padding: 15px;"><div style="display: inline-block; border: 3px solid #f3f3f3; border-top: 3px solid #dc3545; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite;"></div><p style="margin-top: 10px;">جاري الحذف...</p></div>';
            
            fetch('delete_projet.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alertDiv.innerHTML = '<div class="alert alert-success">✓ ' + data.message + '</div>';
                    setTimeout(function() {
                        window.location.reload();
                    }, 1500);
                } else {
                    alertDiv.innerHTML = '<div class="alert alert-error">✕ ' + data.message + '</div>';
                }
            })
            .catch(function(error) {
                    console.error('Error:', error);
                    alertDiv.innerHTML = '<div class="alert alert-error">✕ حدث خطأ في الاتصال</div>';
                });
            };

            // Mise à jour de la fonction window.onclick pour inclure le modal de suppression
            var originalWindowClick = window.onclick;
            window.onclick = function(event) {
                if (event.target == modal) {
                    fermerModal();
                }
                if (event.target == taqrirModal) {
                    closeTaqrirModal();
                }
                if (event.target == deleteModal) {
                    closeDeleteModal();
                }
                if (event.target == editModal) {
                    closeEditModal();
                }
                if (event.target == addFileModal) {
                    closeAddFileModal();
                }
            }
        
        // Variables pour le modal d'ajout de fichier
        var addFileModal = document.getElementById('addFileModal');
        var btnCloseAddFile = document.getElementById('btnCloseAddFile');
        var btnCancelAddFile = document.getElementById('btnCancelAddFile');

        // Fonction pour ouvrir le modal d'ajout de fichier
        function openAddFileModal(projetId) {
            document.getElementById('addFileProjetId').value = projetId;
            addFileModal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        // Fonction pour fermer le modal d'ajout de fichier
        function closeAddFileModal() {
            addFileModal.classList.remove('show');
            document.body.style.overflow = 'auto';
            document.getElementById('addFileForm').reset();
            document.getElementById('addFileAlert').innerHTML = '';
        }

        if (btnCloseAddFile) {
            btnCloseAddFile.onclick = closeAddFileModal;
        }

        if (btnCancelAddFile) {
            btnCancelAddFile.onclick = closeAddFileModal;
        }

        // Validation fichier supplémentaire
        document.getElementById('fichier_supplementaire').onchange = function() {
            var file = this.files[0];
            if (file) {
                var fileSize = file.size / 1024 / 1024;
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

        // Soumettre le formulaire d'ajout de fichier
        document.getElementById('addFileForm').onsubmit = function(e) {
            e.preventDefault();
            
            var formData = new FormData(this);
            var alertDiv = document.getElementById('addFileAlert');
            
            alertDiv.innerHTML = '<div style="text-align: center; padding: 15px;"><div style="display: inline-block; border: 3px solid #f3f3f3; border-top: 3px solid #17a2b8; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite;"></div><p style="margin-top: 10px;">جاري الرفع...</p></div>';
            
            fetch('projets.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alertDiv.innerHTML = '<div class="alert alert-success">✓ ' + data.message + '</div>';
                    setTimeout(function() {
                        window.location.reload();
                    }, 1500);
                } else {
                    alertDiv.innerHTML = '<div class="alert alert-error">✕ ' + data.message + '</div>';
                }
            })
            .catch(function(error) {
                console.error('Error:', error);
                alertDiv.innerHTML = '<div class="alert alert-error">✕ حدث خطأ في الاتصال</div>';
            });
        };

        
        // ==========================================
        // MODAL MODIFICATION
        // ==========================================
        var editModal = document.getElementById('editProjetModal');
        var btnCloseEdit = document.getElementById('btnCloseEdit');
        var btnCancelEdit = document.getElementById('btnCancelEdit');

        // Fonction pour charger les données du projet
        function openEditModal(projetId) {
            // Afficher le modal
            editModal.classList.add('show');
            document.body.style.overflow = 'hidden';
            
            // Récupérer les données du projet
            fetch('get_projet.php?id=' + projetId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        var p = data.projet;
                        
                        document.getElementById('editProjetId').value = p.idPro;
                        document.getElementById('editSujet').value = p.sujet;
                        document.getElementById('editMinistere').value = p.idMinistere;
                        document.getElementById('editDateArrive').value = p.dateArrive;
                        document.getElementById('editProcedure').value = p.procedurePro;
                        document.getElementById('editCout').value = p.cout;
                        document.getElementById('editProposition').value = p.proposition;
                        document.getElementById('editRapporteur').value = p.idUser;
                        document.getElementById('editGouvernorat').value = p.id_Gov; 
                        
                        // Charger les établissements
                        if (p.idMinistere) {
                            loadEtablissementsForEdit(p.idMinistere, p.idEtab);
                        }
                        
                        // Pré-remplir les titres de documents s'ils existent
                        if (data.docMuqtarah) {
                            document.getElementById('editLibDocMuqtarah').value = data.docMuqtarah.libDoc;
                        }
                        if (data.docTaqrir) {
                            document.getElementById('editLibDocTaqrir').value = data.docTaqrir.libDoc;
                        }
                    } else {
                        alert('خطأ في تحميل بيانات المقترح');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('حدث خطأ في الاتصال');
                });
        }

        // Charger les établissements pour le modal de modification
        function loadEtablissementsForEdit(ministereId, selectedEtabId) {
            var etabSelect = document.getElementById('editEtab');
            etabSelect.innerHTML = '<option value="">جاري التحميل...</option>';
            
            fetch('get_etablissements.php?ministere=' + ministereId)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.etablissements.length > 0) {
                        etabSelect.innerHTML = '<option value="">-- الوزارة --</option>';
                        data.etablissements.forEach(function(etab) {
                            var option = document.createElement('option');
                            option.value = etab.idEtablissement;
                            option.textContent = etab.libEtablissement;
                            if (etab.idEtablissement == selectedEtabId) {
                                option.selected = true;
                            }
                            etabSelect.appendChild(option);
                        });
                    } else {
                        etabSelect.innerHTML = '<option value="">-- الوزارة --</option>';
                    }
                });
        }

        // Changement de ministère dans le modal d'édition
        document.getElementById('editMinistere').onchange = function() {
            loadEtablissementsForEdit(this.value, null);
        };

        // Fermer le modal d'édition
        function closeEditModal() {
            editModal.classList.remove('show');
            document.body.style.overflow = 'auto';
            document.getElementById('editProjetForm').reset();
            document.getElementById('editModalAlert').innerHTML = '';
        }

        if (btnCloseEdit) {
            btnCloseEdit.onclick = closeEditModal;
        }

        if (btnCancelEdit) {
            btnCancelEdit.onclick = closeEditModal;
        }

        // Soumettre le formulaire de modification
        document.getElementById('editProjetForm').onsubmit = function(e) {
            e.preventDefault();
            
            var formData = new FormData(this);
            var alertDiv = document.getElementById('editModalAlert');
            
            alertDiv.innerHTML = '<div style="text-align: center; padding: 15px;"><div style="display: inline-block; border: 3px solid #f3f3f3; border-top: 3px solid #4caf50; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite;"></div><p style="margin-top: 10px;">جاري الحفظ...</p></div>';
            
            fetch('projets.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alertDiv.innerHTML = '<div class="alert alert-success">✓ ' + data.message + '</div>';
                    setTimeout(function() {
                        window.location.reload();
                    }, 1500);
                } else {
                    alertDiv.innerHTML = '<div class="alert alert-error">✕ ' + data.message + '</div>';
                }
            })
            .catch(function(error) {
                console.error('Error:', error);
                alertDiv.innerHTML = '<div class="alert alert-error">✕ حدث خطأ في الاتصال</div>';
            });
        };

        // Ajouter au window.onclick existant
        var originalWindowClick = window.onclick;
        window.onclick = function(event) {
            if (event.target == modal) {
                fermerModal();
            }
            if (event.target == taqrirModal) {
                closeTaqrirModal();
            }
            if (event.target == deleteModal) {
                closeDeleteModal();
            }
            if (event.target == editModal) {
                closeEditModal();
            }
        }

    // Variables pour le modal de détails
    var detailsModal = document.getElementById('detailsModal');
    var btnCloseDetails = document.getElementById('btnCloseDetails');

    // Fonction pour ouvrir le modal de détails
    function openDetailsModal(projetId) {
        detailsModal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        // Charger les détails du projet
        fetch('get_projet_details.php?id=' + projetId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayProjetDetails(data.projet, data.documents);
                } else {
                    document.getElementById('detailsContent').innerHTML = 
                        '<div class="alert alert-error">✖ ' + data.message + '</div>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('detailsContent').innerHTML = 
                    '<div class="alert alert-error">✖ حدث خطأ في الاتصال</div>';
            });
    }

    // Fonction pour afficher les détails
    function displayProjetDetails(projet, documents) {
        var statusColors = {
            0: 'badge-pending',
            1: 'badge-processing',
            2: 'badge-approved',
            3: 'badge-rejected'
        };
        
        var html = `
            <!-- Tableau des Informations Générales -->
            <table class="details-table">
                <thead>
                    <tr>
                        <th colspan="4">📝 المعلومات الأساسية</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="label-cell">الموضوع</td>
                        <td colspan="3" class="value-cell">${projet.sujet}</td>
                    </tr>
                    <tr>
                        <td class="label-cell">الوزارة</td>
                        <td class="value-cell">${projet.libMinistere || 'غير محدد'}</td>
                        <td class="label-cell">المؤسسة</td>
                        <td class="value-cell">${projet.libEtablissement || 'الوزارة'}</td>
                    </tr>
                    <tr>
                        <td class="label-cell">الولاية</td>
                        <td class="value-cell">${projet.libGov || 'غير محدد'}</td>
                        <td class="label-cell">تاريخ التعهد</td>
                        <td class="value-cell">${projet.dateArrive}</td>
                    </tr>
                    <tr>
                        <td class="label-cell">صيغة المشروع</td>
                        <td class="value-cell">${projet.procedurePro}</td>
                        <td class="label-cell">الكلفة التقديرية</td>
                        <td class="value-cell">${parseFloat(projet.cout).toLocaleString('fr-FR', {minimumFractionDigits: 2})} م.د.ت</td>
                    </tr>
                    <tr>
                        <td class="label-cell">الحالة</td>
                        <td class="value-cell">
                            <span class="status-badge ${statusColors[projet.etat]}">${projet.etatLib}</span>
                        </td>
                        <td class="label-cell">المقرر</td>
                        <td class="value-cell">${projet.nomUser || 'غير محدد'}</td>
                    </tr>
                    <tr>
                        <td class="label-cell"> المقترح</td>
                        <td colspan="3" class="value-cell">${projet.proposition}</td>
                    </tr>
                </tbody>
            </table>
            
            
            <!-- Titre de section pour les documents -->
            <div class="section-title">
                <span>📎</span>
                <span>الوثائق المرفقة (${documents.length})</span>
            </div>
            
            <!-- Tableau des Documents -->
            ${documents.length > 0 ? `
                <table class="documents-table">
                    <thead>
                        <tr>
                            <th style="width: 10%;">#</th>
                            <th style="width: 20%;">نوع الوثيقة</th>
                            <th style="width: 50%;">عنوان الوثيقة</th>
                            <th style="width: 20%;">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${documents.map((doc, index) => `
                            <tr>
                                <td>${index + 1}</td>
                                <td>
                                    <span class="doc-type-badge doc-type-${doc.type}">${doc.typeLib}</span>
                                </td>
                                <td style="text-align: right; padding-right: 20px;">${doc.libDoc}</td>
                                <td>
                                    <a href="${doc.cheminAcces}" target="_blank" class="btn-view-doc">
                                        <span>👁️</span>
                                        <span>عرض</span>
                                    </a>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            ` : `
                <div class="no-documents">
                    <span style="font-size: 48px;">📭</span>
                    <p>لا توجد وثائق مرفقة</p>
                </div>
            `}
        `;
        
        document.getElementById('detailsContent').innerHTML = html;
    }

    // Fonction pour fermer le modal de détails
    function closeDetailsModal() {
        detailsModal.classList.remove('show');
        document.body.style.overflow = 'auto';
        document.getElementById('detailsContent').innerHTML = `
            <div style="text-align: center; padding: 40px;">
                <div style="display: inline-block; border: 4px solid #f3f3f3; border-top: 4px solid #667eea; border-radius: 50%; width: 50px; height: 50px; animation: spin 1s linear infinite;"></div>
                <p style="margin-top: 20px; color: #666;">جاري التحميل...</p>
            </div>
        `;
    }

    if (btnCloseDetails) {
        btnCloseDetails.onclick = closeDetailsModal;
    }

    // Ajouter au window.onclick existant
    var existingWindowClick = window.onclick;
    window.onclick = function(event) {
        // Garder les fonctionnalités existantes
        if (event.target == modal) {
            fermerModal();
        }
        if (event.target == taqrirModal) {
            closeTaqrirModal();
        }
        if (event.target == deleteModal) {
            closeDeleteModal();
        }
        if (event.target == editModal) {
            closeEditModal();
        }
        if (event.target == addFileModal) {
            closeAddFileModal();
        }
        // Nouvelle fonctionnalité
        if (event.target == detailsModal) {
            closeDetailsModal();
        }
    }
        
    </script>
</body>
</html>