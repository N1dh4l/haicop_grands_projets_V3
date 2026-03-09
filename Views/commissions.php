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
    $page_title= "لجنة المشاريع الكبرى - رئاسة الحكومة";

    // Traitement de l'ajout de commission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_commission') {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        
        // Vérifier si PHP a rejeté l'upload à cause des limites php.ini
        if (isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0 && empty($_POST) && empty($_FILES)) {
            echo json_encode([
                'success' => false,
                'message' => 'حجم الملف يتجاوز الحد المسموح به في إعدادات الخادم. يرجى التواصل مع المسؤول لرفع الحد إلى 20 ميغابايت.'
            ]);
            exit;
        }
        
        try {
            // Validation CSRF
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                throw new Exception('Token de sécurité invalide');
            }
            
            // Récupérer les données
            $numCommission = isset($_POST['numCommission']) ? intval($_POST['numCommission']) : 0;
            $dateCommission = isset($_POST['dateCommission']) ? $_POST['dateCommission'] : '';
            $projets = isset($_POST['projets']) ? $_POST['projets'] : array();
            $naturePcs = isset($_POST['naturePcs']) ? $_POST['naturePcs'] : array();
            
            // Validation des champs obligatoires
            if ($numCommission <= 0) {
                throw new Exception('عدد الجلسة مطلوب');
            }
            if (empty($dateCommission)) {
                throw new Exception('تاريخ الجلسة مطلوب');
            }
            if (empty($projets) || count($projets) == 0) {
                throw new Exception('يجب إضافة مشروع واحد على الأقل');
            }
            
            // Valider chaque projet
            foreach ($projets as $index => $idPro) {
                if (intval($idPro) <= 0) {
                    throw new Exception('يجب اختيار مشروع صالح في السطر ' . ($index + 1));
                }
                if (!isset($naturePcs[$index]) || intval($naturePcs[$index]) <= 0) {
                    throw new Exception('يجب اختيار نوعية المقترح في السطر ' . ($index + 1));
                }
            }
            
            // Vérifier si le numéro de commission existe déjà
            $checkQuery = "SELECT COUNT(*) as count FROM commission WHERE numCommission = :numCommission";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->bindParam(':numCommission', $numCommission);
            $checkStmt->execute();
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                throw new Exception('عدد الجلسة موجود مسبقا');
            }
            
            // Commencer la transaction
            $db->beginTransaction();
            
            // Insérer la commission
            $queryCommission = "INSERT INTO commission (numCommission, dateCommission) VALUES (:numCommission, :dateCommission)";
            $stmtCommission = $db->prepare($queryCommission);
            $stmtCommission->bindParam(':numCommission', $numCommission);
            $stmtCommission->bindParam(':dateCommission', $dateCommission);
            $stmtCommission->execute();
            
            $idCom = $db->lastInsertId();
            
            // Insérer chaque projet dans projetcommission
            foreach ($projets as $index => $idPro) {
                $naturePc = intval($naturePcs[$index]);
                
                $queryProjetCommission = "INSERT INTO projetcommission (idPro, idCom, naturePc) VALUES (:idPro, :idCom, :naturePc)";
                $stmtProjetCommission = $db->prepare($queryProjetCommission);
                $stmtProjetCommission->bindParam(':idPro', $idPro);
                $stmtProjetCommission->bindParam(':idCom', $idCom);
                $stmtProjetCommission->bindParam(':naturePc', $naturePc);
                $stmtProjetCommission->execute();
                
                // Mettre à jour l'état du projet selon la nature
                // 20 (إدراج وقتي) ou 21 (إدراج نهائي) → état 2
                // 22 (إسناد وقتي) → état 3
                // 23 (إسناد نهائي) → état 4
                if ($naturePc == 20 || $naturePc == 21) {
                    $newEtat = 2;
                } elseif ($naturePc == 22) {
                    $newEtat = 3;
                } elseif ($naturePc == 23) {
                    $newEtat = 4;
                } else {
                    $newEtat = $naturePc;
                }
                $queryUpdateProjet = "UPDATE projet SET etat = :etat WHERE idPro = :idPro";
                $stmtUpdateProjet = $db->prepare($queryUpdateProjet);
                $stmtUpdateProjet->bindParam(':etat', $newEtat);
                $stmtUpdateProjet->bindParam(':idPro', $idPro);
                $stmtUpdateProjet->execute();
            }
            
            // Traiter le fichier محضر الجلسة (optionnel)
            $uploadedFile = false;
            
            if (isset($_FILES['fichierMahdar']) && $_FILES['fichierMahdar']['error'] === UPLOAD_ERR_OK) {
                $libDocMahdar = isset($_POST['libDocMahdar']) ? trim($_POST['libDocMahdar']) : '';
                
                if (empty($libDocMahdar)) {
                    throw new Exception('عنوان ملف المحضر مطلوب');
                }
                
                $fileTmpPath = $_FILES['fichierMahdar']['tmp_name'];
                $fileName = $_FILES['fichierMahdar']['name'];
                $fileSize = $_FILES['fichierMahdar']['size'];
                $fileNameCmps = explode(".", $fileName);
                $fileExtension = strtolower(end($fileNameCmps));
                
                // Extensions autorisées
                $allowedExtensions = array('pdf', 'doc', 'docx', 'xls', 'xlsx');
                
                if (!in_array($fileExtension, $allowedExtensions)) {
                    throw new Exception('نوع الملف غير مقبول للمحضر');
                }
                
                if ($fileSize > 20971520) { // 20MB
                    throw new Exception('حجم ملف المحضر يجب أن يكون أقل من 20 ميغابايت');
                }
                
                // Générer un nom unique
                $newFileName = 'mahdar_' . $idCom . '_' . time() . '.' . $fileExtension;
                $uploadFileDir = '../uploads/commissions/';
                
                if (!file_exists($uploadFileDir)) {
                    mkdir($uploadFileDir, 0755, true);
                }
                
                $dest_path = $uploadFileDir . $newFileName;
                
                if (move_uploaded_file($fileTmpPath, $dest_path)) {
                    // Insérer dans la table document (lié au premier projet de la commission)
                    $firstProjetId = intval($projets[0]);
                    $queryDoc = "INSERT INTO document (idPro, libDoc, cheminAcces, type, idExterne) 
                                VALUES (:idPro, :libDoc, :cheminAcces, :type, :idExterne)";
                    $stmtDoc = $db->prepare($queryDoc);
                    $stmtDoc->bindParam(':idPro', $firstProjetId);
                    $stmtDoc->bindParam(':libDoc', $libDocMahdar);
                    $stmtDoc->bindParam(':cheminAcces', $dest_path);
                    $type = 25; // Type pour محضر الجلسة
                    $stmtDoc->bindParam(':type', $type);
                    $stmtDoc->bindParam(':idExterne', $idCom);
                    $stmtDoc->execute();
                    
                    $uploadedFile = true;
                }
            }
            
            // Valider la transaction
            $db->commit();
            
            $message = 'تمت إضافة الجلسة بنجاح مع ' . count($projets) . ' مشروع(مشاريع)';
            if ($uploadedFile) {
                $message .= ' ومحضر الجلسة';
            }
            
            echo json_encode([
                'success' => true,
                'message' => $message
            ]);
            
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
        exit;
    }

    // Récupérer la liste des projets avec état 1 OR 21 OR 22 OR 23 pour le select
    $queryProjets = "SELECT idPro, sujet FROM projet WHERE etat IN (2, 3) ORDER BY dateCreation DESC";    $stmtProjets = $db->prepare($queryProjets);
    $stmtProjets->execute();
    $projets = $stmtProjets->fetchAll(PDO::FETCH_ASSOC);

    // Récupérer les filtres
    $filterSearch = isset($_GET['search']) ? Security::sanitizeInput($_GET['search']) : '';
    $filterYear = isset($_GET['year']) ? Security::sanitizeInput($_GET['year']) : '';
    
    // Récupérer les années disponibles
    $sqlYears = "SELECT DISTINCT YEAR(dateCommission) as year 
                FROM commission 
                WHERE dateCommission IS NOT NULL 
                ORDER BY year DESC";
    $stmtYears = $db->prepare($sqlYears);
    $stmtYears->execute();
    $years = $stmtYears->fetchAll(PDO::FETCH_ASSOC);

    // Nombre d'éléments par page
    if (isset($_GET['items_per_page']) && $_GET['items_per_page'] === 'all') {
        $itemsPerPage = 999999;
        $showAll = true;
    } else {
        $itemsPerPage = isset($_GET['items_per_page']) ? min(100, max(10, intval($_GET['items_per_page']))) : 10;
        $showAll = false;
    }

    $currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $offset = ($currentPage - 1) * $itemsPerPage;

    // Compter le nombre total de commissions
    // Compter le nombre total de commissions (SANS duplication)
$sqlCount = "SELECT COUNT(DISTINCT c.idCom) as total
            FROM commission c";

// Ajouter WHERE seulement si nécessaire
$whereAdded = false;
if (!empty($filterSearch)) {
    $sqlCount .= " WHERE (c.numCommission LIKE :search 
                  OR EXISTS (
                      SELECT 1 FROM projetcommission pc 
                      JOIN projet p ON pc.idPro = p.idPro 
                      WHERE pc.idCom = c.idCom AND p.sujet LIKE :search
                  ))";
    $whereAdded = true;
}
if (!empty($filterYear)) {
    $sqlCount .= $whereAdded ? " AND" : " WHERE";
    $sqlCount .= " YEAR(c.dateCommission) = :year";
}

$stmtCount = $db->prepare($sqlCount);
if (!empty($filterSearch)) {
    $searchParam = "%{$filterSearch}%";
    $stmtCount->bindParam(':search', $searchParam);
}
if (!empty($filterYear)) {
    $stmtCount->bindParam(':year', $filterYear);
}
$stmtCount->execute();
$totalItems = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalItems / $itemsPerPage);

    // Récupérer les commissions de base (SANS jointures qui dupliquent)
    $sqlCommissions = "SELECT c.idCom, c.numCommission, c.dateCommission
                    FROM commission c
                    WHERE 1=1 ";

    if (!empty($filterSearch)) {
        // Pour la recherche, on fait un sous-requête
        $sqlCommissions .= " AND (c.numCommission LIKE :search 
                            OR EXISTS (
                                SELECT 1 FROM projetcommission pc 
                                JOIN projet p ON pc.idPro = p.idPro 
                                WHERE pc.idCom = c.idCom AND p.sujet LIKE :search
                            ))";
    }
    if (!empty($filterYear)) {
        $sqlCommissions .= " AND YEAR(c.dateCommission) = :year";
    }

    $sqlCommissions .= " ORDER BY c.dateCommission ASC, c.numCommission ASC
                        LIMIT :limit OFFSET :offset";

    $stmtCommissions = $db->prepare($sqlCommissions);
    if (!empty($filterSearch)) {
        $searchParam = "%{$filterSearch}%";
        $stmtCommissions->bindParam(':search', $searchParam);
    }
    if (!empty($filterYear)) {
        $stmtCommissions->bindParam(':year', $filterYear);
    }
    $stmtCommissions->bindParam(':limit', $itemsPerPage, PDO::PARAM_INT);
    $stmtCommissions->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmtCommissions->execute();
    $commissions = $stmtCommissions->fetchAll(PDO::FETCH_ASSOC);

    // Pour chaque commission, récupérer les détails
    foreach ($commissions as &$commission) {
        // Récupérer les projets avec leurs natures
        $sqlProjets = "SELECT p.idPro, p.sujet, pc.naturePc,
                        CASE pc.naturePc
                            WHEN 20 THEN 'إدراج وقتي'
                            WHEN 21 THEN 'إدراج نهائي'
                            WHEN 22 THEN 'إسناد وقتي'
                            WHEN 23 THEN 'إسناد نهائي'
                            ELSE 'غير محدد'
                        END as natureLibelle
                    FROM projetcommission pc
                    JOIN projet p ON pc.idPro = p.idPro
                    WHERE pc.idCom = :idCom
                    ORDER BY p.sujet";
        
        $stmtProjets = $db->prepare($sqlProjets);
        $stmtProjets->bindParam(':idCom', $commission['idCom']);
        $stmtProjets->execute();
        $commission['projets_list'] = $stmtProjets->fetchAll(PDO::FETCH_ASSOC);
        
        // Récupérer محضر الجلسة
        $sqlMahdar = "SELECT idDoc, libDoc, cheminAcces FROM document 
                    WHERE type = 25 AND idExterne = :idCom LIMIT 1";
        $stmtMahdar = $db->prepare($sqlMahdar);
        $stmtMahdar->bindParam(':idCom', $commission['idCom']);
        $stmtMahdar->execute();
        $mahdar = $stmtMahdar->fetch(PDO::FETCH_ASSOC);
        
        $commission['mahdarPath'] = $mahdar ? $mahdar['cheminAcces'] : null;
        $commission['mahdarLibelle'] = $mahdar ? $mahdar['libDoc'] : null;
        $commission['mahdarId'] = $mahdar ? $mahdar['idDoc'] : null;
        
        // Récupérer قرار اللجنة
        $sqlQarar = "SELECT idDoc, libDoc, cheminAcces FROM document 
                    WHERE type = 26 AND idExterne = :idCom LIMIT 1";
        $stmtQarar = $db->prepare($sqlQarar);
        $stmtQarar->bindParam(':idCom', $commission['idCom']);
        $stmtQarar->execute();
        $qarar = $stmtQarar->fetch(PDO::FETCH_ASSOC);
        
        $commission['qararPath'] = $qarar ? $qarar['cheminAcces'] : null;
        $commission['qararLibelle'] = $qarar ? $qarar['libDoc'] : null;
        $commission['qararId'] = $qarar ? $qarar['idDoc'] : null;
    }
    // Traitement de l'upload de قرار اللجنة
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_qarar') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        // Validation CSRF
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception('Token de sécurité invalide');
        }
        
        // Vérifier les permissions
        if (!Permissions::canEditProjet($_SESSION['user_id'])) {
            throw new Exception('ليس لديك صلاحية لإضافة قرار اللجنة');
        }
        
        $idCom = isset($_POST['idCom']) ? intval($_POST['idCom']) : 0;
        $libDocQarar = isset($_POST['libDocQarar']) ? trim($_POST['libDocQarar']) : '';
        
        if ($idCom <= 0) throw new Exception('معرف الجلسة غير صالح');
        if (empty($libDocQarar)) throw new Exception('عنوان قرار اللجنة مطلوب');
        
        // Vérifier que la commission existe
        $checkQuery = "SELECT idCom FROM commission WHERE idCom = :idCom";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':idCom', $idCom);
        $checkStmt->execute();
        if ($checkStmt->rowCount() == 0) throw new Exception('الجلسة غير موجودة');
        
        if (!isset($_FILES['fichierQarar']) || $_FILES['fichierQarar']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('يرجى اختيار ملف قرار اللجنة');
        }
        
        $fileTmpPath   = $_FILES['fichierQarar']['tmp_name'];
        $fileName      = $_FILES['fichierQarar']['name'];
        $fileSize      = $_FILES['fichierQarar']['size'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];
        if (!in_array($fileExtension, $allowedExtensions)) throw new Exception('نوع الملف غير مقبول');
        if ($fileSize > 20971520) throw new Exception('حجم الملف يجب أن يكون أقل من 20 ميغابايت');
        
        $db->beginTransaction();
        
        // Récupérer le premier projet
        $getProjetQuery = "SELECT idPro FROM projetcommission WHERE idCom = :idCom LIMIT 1";
        $getProjetStmt  = $db->prepare($getProjetQuery);
        $getProjetStmt->bindParam(':idCom', $idCom);
        $getProjetStmt->execute();
        $projetData = $getProjetStmt->fetch(PDO::FETCH_ASSOC);
        if (!$projetData) throw new Exception('لم يتم العثور على مشروع مرتبط بهذه الجلسة');
        $idPro = $projetData['idPro'];
        
        // Uploader le fichier
        $newFileName   = 'qarar_' . $idCom . '_' . time() . '.' . $fileExtension;
        $uploadFileDir = '../uploads/commissions/';
        if (!file_exists($uploadFileDir)) mkdir($uploadFileDir, 0755, true);
        $dest_path = $uploadFileDir . $newFileName;
        
        if (!move_uploaded_file($fileTmpPath, $dest_path)) throw new Exception('فشل في رفع الملف');
        
        // Supprimer l'ancien قرار s'il existe déjà
        $getOld = "SELECT cheminAcces FROM document WHERE type = 26 AND idExterne = :idCom";
        $stmtOld = $db->prepare($getOld);
        $stmtOld->bindParam(':idCom', $idCom);
        $stmtOld->execute();
        $old = $stmtOld->fetch(PDO::FETCH_ASSOC);
        if ($old && file_exists($old['cheminAcces'])) unlink($old['cheminAcces']);
        
        $db->prepare("DELETE FROM document WHERE type = 26 AND idExterne = :idCom")
           ->execute([':idCom' => $idCom]);
        
        // Insérer le nouveau document
        $queryDoc = "INSERT INTO document (idPro, libDoc, cheminAcces, type, idExterne) 
                    VALUES (:idPro, :libDoc, :cheminAcces, :type, :idExterne)";
        $stmtDoc = $db->prepare($queryDoc);
        $stmtDoc->bindParam(':idPro', $idPro);
        $stmtDoc->bindParam(':libDoc', $libDocQarar);
        $stmtDoc->bindParam(':cheminAcces', $dest_path);
        $type = 26;
        $stmtDoc->bindParam(':type', $type);
        $stmtDoc->bindParam(':idExterne', $idCom);
        $stmtDoc->execute();
        
        // Journal
        $action  = "إضافة قرار اللجنة للجلسة رقم " . $idCom . " - " . $libDocQarar;
        $idUser  = $_SESSION['user_id'] ?? 0;
        $db->prepare("INSERT INTO journal (idUser, action, date) VALUES (:idUser, :action, NOW())")
           ->execute([':idUser' => $idUser, ':action' => $action]);
        
        $db->commit();
        
        echo json_encode(['success' => true, 'message' => 'تم رفع قرار اللجنة بنجاح']);
        
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        if (isset($dest_path) && file_exists($dest_path)) unlink($dest_path);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
    // Traitement de la modification de commission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_commission') {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        
        // Vérifier si PHP a rejeté l'upload à cause des limites php.ini
        if (isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0 && empty($_POST) && empty($_FILES)) {
            echo json_encode([
                'success' => false,
                'message' => 'حجم الملف يتجاوز الحد المسموح به في إعدادات الخادم. يرجى التواصل مع المسؤول لرفع الحد إلى 20 ميغابايت.'
            ]);
            exit;
        }
        
        try {
            // Validation CSRF
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                throw new Exception('Token de sécurité invalide');
            }
            
            // Vérifier les permissions
            if (!Permissions::canEditProjet($_SESSION['user_id'])) {
                throw new Exception('ليس لديك صلاحية لتعديل الجلسة');
            }
            
            // Récupérer les données
            $idCom = isset($_POST['idCom']) ? intval($_POST['idCom']) : 0;
            $numCommission = isset($_POST['numCommission']) ? intval($_POST['numCommission']) : 0;
            $dateCommission = isset($_POST['dateCommission']) ? $_POST['dateCommission'] : '';
            $projets = isset($_POST['projets']) ? $_POST['projets'] : array();
            $naturePcs = isset($_POST['naturePcs']) ? $_POST['naturePcs'] : array();
            
            // Validation
            if ($idCom <= 0) {
                throw new Exception('معرف الجلسة غير صالح');
            }
            if ($numCommission <= 0) {
                throw new Exception('عدد الجلسة مطلوب');
            }
            if (empty($dateCommission)) {
                throw new Exception('تاريخ الجلسة مطلوب');
            }
            if (empty($projets) || count($projets) == 0) {
                throw new Exception('يجب إضافة مشروع واحد على الأقل');
            }
            
            // Vérifier que la commission existe
            $checkQuery = "SELECT idCom, numCommission FROM commission WHERE idCom = :idCom";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->bindParam(':idCom', $idCom);
            $checkStmt->execute();
            $existingCom = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$existingCom) {
                throw new Exception('الجلسة غير موجودة');
            }
            
            // Vérifier si le nouveau numéro existe déjà (sauf pour cette commission)
            if ($numCommission != $existingCom['numCommission']) {
                $checkNumQuery = "SELECT COUNT(*) as count FROM commission WHERE numCommission = :numCommission AND idCom != :idCom";
                $checkNumStmt = $db->prepare($checkNumQuery);
                $checkNumStmt->bindParam(':numCommission', $numCommission);
                $checkNumStmt->bindParam(':idCom', $idCom);
                $checkNumStmt->execute();
                $result = $checkNumStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result['count'] > 0) {
                    throw new Exception('عدد الجلسة موجود مسبقا');
                }
            }
            
            // Commencer la transaction
            $db->beginTransaction();
            
            // Mettre à jour la commission
            $queryUpdateCom = "UPDATE commission SET numCommission = :numCommission, dateCommission = :dateCommission WHERE idCom = :idCom";
            $stmtUpdateCom = $db->prepare($queryUpdateCom);
            $stmtUpdateCom->bindParam(':numCommission', $numCommission);
            $stmtUpdateCom->bindParam(':dateCommission', $dateCommission);
            $stmtUpdateCom->bindParam(':idCom', $idCom);
            $stmtUpdateCom->execute();
            
            // Supprimer les anciens projets
            $queryDeleteProjets = "DELETE FROM projetcommission WHERE idCom = :idCom";
            $stmtDeleteProjets = $db->prepare($queryDeleteProjets);
            $stmtDeleteProjets->bindParam(':idCom', $idCom);
            $stmtDeleteProjets->execute();
            
            // Insérer les nouveaux projets et mettre à jour les états
            foreach ($projets as $index => $idPro) {
                $naturePc = intval($naturePcs[$index]);
                
                // Insérer dans projetcommission
                $queryProjetCom = "INSERT INTO projetcommission (idPro, idCom, naturePc) VALUES (:idPro, :idCom, :naturePc)";
                $stmtProjetCom = $db->prepare($queryProjetCom);
                $stmtProjetCom->bindParam(':idPro', $idPro);
                $stmtProjetCom->bindParam(':idCom', $idCom);
                $stmtProjetCom->bindParam(':naturePc', $naturePc);
                $stmtProjetCom->execute();
                
                // Mettre à jour l'état du projet selon la nature choisie
                // 20 (إدراج وقتي) ou 21 (إدراج نهائي) → état 2
                // 22 (إسناد وقتي) → état 3
                // 23 (إسناد نهائي) → état 4
                if ($naturePc == 20 || $naturePc == 21) {
                    $newEtat = 2;
                } elseif ($naturePc == 22) {
                    $newEtat = 3;
                } elseif ($naturePc == 23) {
                    $newEtat = 4;
                } else {
                    $newEtat = $naturePc;
                }
                $queryUpdateProjet = "UPDATE projet SET etat = :etat WHERE idPro = :idPro";
                $stmtUpdateProjet = $db->prepare($queryUpdateProjet);
                $stmtUpdateProjet->bindParam(':etat', $newEtat);
                $stmtUpdateProjet->bindParam(':idPro', $idPro);
                $stmtUpdateProjet->execute();
            }
            
            // Traiter le nouveau fichier محضر si fourni
            $uploadedMahdar = false;
            if (isset($_FILES['fichierMahdar']) && $_FILES['fichierMahdar']['error'] === UPLOAD_ERR_OK) {
                $libDocMahdar = isset($_POST['libDocMahdar']) ? trim($_POST['libDocMahdar']) : '';
                
                if (empty($libDocMahdar)) {
                    throw new Exception('عنوان ملف المحضر مطلوب');
                }
                
                $fileTmpPath = $_FILES['fichierMahdar']['tmp_name'];
                $fileName = $_FILES['fichierMahdar']['name'];
                $fileSize = $_FILES['fichierMahdar']['size'];
                $fileNameCmps = explode(".", $fileName);
                $fileExtension = strtolower(end($fileNameCmps));
                
                $allowedExtensions = array('pdf', 'doc', 'docx', 'xls', 'xlsx');
                
                if (!in_array($fileExtension, $allowedExtensions)) {
                    throw new Exception('نوع الملف غير مقبول للمحضر');
                }
                
                if ($fileSize > 20971520) {
                    throw new Exception('حجم ملف المحضر يجب أن يكون أقل من 20 ميغابايت');
                }
                
                // Supprimer l'ancien fichier محضر
                $getOldMahdar = "SELECT cheminAcces FROM document WHERE type = 25 AND idExterne = :idCom";                $stmtOldMahdar = $db->prepare($getOldMahdar);
                $stmtOldMahdar->bindParam(':idCom', $idCom);
                $stmtOldMahdar->execute();
                $oldMahdar = $stmtOldMahdar->fetch(PDO::FETCH_ASSOC);
                
                if ($oldMahdar && file_exists($oldMahdar['cheminAcces'])) {
                    unlink($oldMahdar['cheminAcces']);
                }
                
                // Supprimer l'ancienne entrée
                $deleteOldMahdar = "DELETE FROM document WHERE type = 25 AND idExterne = :idCom";
                $stmtDeleteMahdar = $db->prepare($deleteOldMahdar);
                $stmtDeleteMahdar->bindParam(':idCom', $idCom);
                $stmtDeleteMahdar->execute();
                
                // Uploader le nouveau fichier
                $newFileName = 'mahdar_' . $idCom . '_' . time() . '.' . $fileExtension;
                $uploadFileDir = '../uploads/commissions/';
                
                if (!file_exists($uploadFileDir)) {
                    mkdir($uploadFileDir, 0755, true);
                }
                
                $dest_path = $uploadFileDir . $newFileName;
                
                if (move_uploaded_file($fileTmpPath, $dest_path)) {
                    $firstProjetId = intval($projets[0]);
                    $queryDoc = "INSERT INTO document (idPro, libDoc, cheminAcces, type, idExterne) 
                                VALUES (:idPro, :libDoc, :cheminAcces, :type, :idExterne)";
                    $stmtDoc = $db->prepare($queryDoc);
                    $stmtDoc->bindParam(':idPro', $firstProjetId);
                    $stmtDoc->bindParam(':libDoc', $libDocMahdar);
                    $stmtDoc->bindParam(':cheminAcces', $dest_path);
                    $type = 25;
                    $stmtDoc->bindParam(':type', $type);
                    $stmtDoc->bindParam(':idExterne', $idCom);
                    $stmtDoc->execute();
                    
                    $uploadedMahdar = true;
                }
            } else if (!empty($_POST['libDocMahdar'])) {
                // Mettre à jour seulement le libellé si fourni
                $libDocMahdar = trim($_POST['libDocMahdar']);
                $updateLibDoc = "UPDATE document SET libDoc = :libDoc WHERE type = 25 AND idExterne = :idCom";     
                $stmtUpdateLib = $db->prepare($updateLibDoc);
                $stmtUpdateLib->bindParam(':libDoc', $libDocMahdar);
                $stmtUpdateLib->bindParam(':idCom', $idCom);
                $stmtUpdateLib->execute();
            }
            
            // ========= AJOUTER ICI - Traiter قرار اللجنة =========
            if (isset($_FILES['fichierQarar']) && $_FILES['fichierQarar']['error'] === UPLOAD_ERR_OK) {
                $libDocQarar = isset($_POST['libDocQarar']) ? trim($_POST['libDocQarar']) : '';
                
                if (empty($libDocQarar)) {
                    throw new Exception('عنوان قرار اللجنة مطلوب');
                }
                
                $fileTmpPath = $_FILES['fichierQarar']['tmp_name'];
                $fileName    = $_FILES['fichierQarar']['name'];
                $fileSize    = $_FILES['fichierQarar']['size'];
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                
                $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];
                if (!in_array($fileExtension, $allowedExtensions)) {
                    throw new Exception('نوع الملف غير مقبول لقرار اللجنة');
                }
                if ($fileSize > 20971520) {
                    throw new Exception('حجم ملف قرار اللجنة يجب أن يكون أقل من 20 ميغابايت');
                }
                
                // Supprimer l'ancien قرار s'il existe
                $getOldQarar = "SELECT cheminAcces FROM document WHERE type = 26 AND idExterne = :idCom";
                $stmtOldQarar = $db->prepare($getOldQarar);
                $stmtOldQarar->bindParam(':idCom', $idCom);
                $stmtOldQarar->execute();
                $oldQarar = $stmtOldQarar->fetch(PDO::FETCH_ASSOC);
                if ($oldQarar && file_exists($oldQarar['cheminAcces'])) {
                    unlink($oldQarar['cheminAcces']);
                }
                
                $deleteOldQarar = "DELETE FROM document WHERE type = 26 AND idExterne = :idCom";
                $stmtDeleteQarar = $db->prepare($deleteOldQarar);
                $stmtDeleteQarar->bindParam(':idCom', $idCom);
                $stmtDeleteQarar->execute();
                
                // Uploader le nouveau fichier
                $newFileName   = 'qarar_' . $idCom . '_' . time() . '.' . $fileExtension;
                $uploadFileDir = '../uploads/commissions/';
                if (!file_exists($uploadFileDir)) {
                    mkdir($uploadFileDir, 0755, true);
                }
                $dest_path = $uploadFileDir . $newFileName;
                
                if (move_uploaded_file($fileTmpPath, $dest_path)) {
                    $firstProjetId = intval($projets[0]);
                    $queryDoc = "INSERT INTO document (idPro, libDoc, cheminAcces, type, idExterne) 
                                VALUES (:idPro, :libDoc, :cheminAcces, :type, :idExterne)";
                    $stmtDoc = $db->prepare($queryDoc);
                    $stmtDoc->bindParam(':idPro', $firstProjetId);
                    $stmtDoc->bindParam(':libDoc', $libDocQarar);
                    $stmtDoc->bindParam(':cheminAcces', $dest_path);
                    $type = 26;
                    $stmtDoc->bindParam(':type', $type);
                    $stmtDoc->bindParam(':idExterne', $idCom);
                    $stmtDoc->execute();
                }
            } else if (!empty($_POST['libDocQarar'])) {
                // Mettre à jour seulement le libellé
                $libDocQarar = trim($_POST['libDocQarar']);
                $updateLibDoc = "UPDATE document SET libDoc = :libDoc WHERE type = 26 AND idExterne = :idCom";
                $stmtUpdateLib = $db->prepare($updateLibDoc);
                $stmtUpdateLib->bindParam(':libDoc', $libDocQarar);
                $stmtUpdateLib->bindParam(':idCom', $idCom);
                $stmtUpdateLib->execute();
            }
            // ========= FIN AJOUT قرار =========
            
            // Journal
            $action = "تعديل الجلسة رقم " . $numCommission . " بتاريخ " . $dateCommission;
            
            // Journal
            $action = "تعديل الجلسة رقم " . $numCommission . " بتاريخ " . $dateCommission;
            $idUser = $_SESSION['user_id'] ?? 0;
            
            $queryJournal = "INSERT INTO journal (idUser, action, date) VALUES (:idUser, :action, NOW())";
            $stmtJournal = $db->prepare($queryJournal);
            $stmtJournal->bindParam(':idUser', $idUser);
            $stmtJournal->bindParam(':action', $action);
            $stmtJournal->execute();
            
            // Valider la transaction
            $db->commit();
            
            $message = 'تم تعديل الجلسة بنجاح';
            if ($uploadedMahdar) {
                $message .= ' وتحديث محضر الجلسة';
            }
            
            echo json_encode([
                'success' => true,
                'message' => $message
            ]);
            
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
        exit;
    }
    // Fonction pour construire l'URL de pagination
    function buildPaginationUrl($page) {
        $params = $_GET;
        $params['page'] = $page;
        return 'commissions.php?' . http_build_query($params);
    }
    // Générer le token CSRF si non existant
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrf_token = $_SESSION['csrf_token'];
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
            padding: 10px 18px !important;
            border-radius: 8px;
            font-size: 14px !important;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 3px;
            min-width: 75px !important;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            white-space: nowrap;
        }

        .btn-action:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 5px rgba(0,0,0,0.15);
        }

        .btn-edit {
            background: linear-gradient(135deg, #FFC107 0%, #FFB300 100%);
            color: #333;
        }

        .btn-edit:hover {
            background: linear-gradient(135deg, #FFB300 0%, #FFA000 100%);
            color: #000;
        }

        .btn-view {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
        }

        .btn-view:hover {
            background: linear-gradient(135deg, #138496 0%, #0f6674 100%);
        }

        .btn-delete {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
        }

        .btn-delete:hover {
            background: linear-gradient(135deg, #c82333 0%, #bd2130 100%);
        }
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
        
        /* NOUVEAUX STYLES POUR AJOUT DYNAMIQUE */
        .projets-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .projets-section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .projets-section-header h3 {
            margin: 0;
            color: #333;
            font-size: 18px;
        }
        
        .btn-add-projet {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-add-projet:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        #projetsContainer {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .projet-row {
            display: grid;
            grid-template-columns: 1fr 1fr 40px;
            gap: 15px;
            align-items: end;
            background: white;
            padding: 15px;
            border-radius: 8px;
            border: 2px solid #e0e0e0;
        }
        
        .btn-remove {
            background: #dc3545;
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 6px;
            font-size: 20px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn-remove:hover {
            background: #c82333;
            transform: scale(1.1);
        }
        
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .projet-row {
                grid-template-columns: 1fr;
            }
            
            .btn-remove {
                width: 100%;
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            transform: translateY(-2px);
        }

        .pagination .active span {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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

        /* Styles spécifiques pour les colonnes du tableau */
        .projects-table {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        
        .projects-table table {
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

        .projects-table th:nth-child(1),
        .projects-table td:nth-child(1) {
            width: 8%;
        }

        .projects-table th:nth-child(2),
        .projects-table td:nth-child(2) {
            width: 10%;
        }

        .projects-table th:nth-child(3) {
            width: 30%;
        }

        .projects-table th:nth-child(4) {
            width: 12%;
        }

        .projects-table th:nth-child(5),
        .projects-table td:nth-child(5) {
            width: 13%;
            text-align: center;
        }

        .projects-table th:nth-child(6),
        .projects-table td:nth-child(6) {
            width: 13%;
            text-align: center;
        }

        .projects-table th:nth-child(7),
        .projects-table td:nth-child(7) {
            width: 14%;
            text-align: center;
        }
        
        /* Styles pour les boutons d'action - identiques à projets.php */
        .btn-action {
            padding: 6px 11px !important;
            border-radius: 5px;
            font-size: 11px !important;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 3px;
            min-width: 60px !important;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            white-space: nowrap;
        }

        .btn-action:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 5px rgba(0,0,0,0.15);
        }

        .btn-edit {
            background: linear-gradient(135deg, #FFC107 0%, #FFB300 100%);
            color: #333;
        }

        .btn-edit:hover {
            background: linear-gradient(135deg, #FFB300 0%, #FFA000 100%);
            color: #000;
        }

        .btn-delete {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
        }

        .btn-delete:hover {
            background: linear-gradient(135deg, #c82333 0%, #bd2130 100%);
        }
        
        /* Styles pour les boutons d'export */
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

        .btn-export:active {
            transform: translateY(0);
        }

        .btn-export svg {
            transition: transform 0.3s ease;
        }

        .btn-export:hover svg {
            transform: scale(1.1);
        }

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
            top: 50%;
            left: 50%;
            margin-left: -10px;
            margin-top: -10px;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @media (max-width: 768px) {
            .export-buttons-container > div {
                flex-direction: column;
                width: 100%;
            }
            
            .btn-export {
                width: 100%;
                justify-content: center;
            }
        }
        
    </style>


</head>
<body>
    <?php include 'includes/header.php'; ?>
    <section class="content-section" style="padding: 40px 0;">
        <div class="container">
            <div class="admin-header">
                <h2>📋 قائمة الجلسات</h2>
            </div>
            <div class="filters-section">
                <form method="GET">
                    <div class="filters-grid">
                        <!-- Recherche -->
                        <div class="filter-group">
                            <label>البحث</label>
                            <input type="text" name="search" placeholder="ابحث عن مقترح أو رقم جلسة..." value="<?php echo htmlspecialchars($filterSearch); ?>">
                        </div>
                        
                        <!-- السنة -->
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
                        <button type="submit" class="btn btn-primary btn-sm">🔍 بحث</button>
                        <a href="commissions.php" class="btn btn-secondary btn-sm">🔄 إعادة تعيين</a>
                    </div>
                </form>
            </div>
            <!-- Remplacez la section de la table (ligne ~660-720) par ce code : -->

        <div class="projects-table">
            <!-- Boutons d'exportation + Ajout -->
            <div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; direction: rtl;">
                <!-- Bouton إضافة جلسة à droite (RTL = right) -->
                <?php if (Permissions::canCreateProjet()): ?>
                <button type="button" class="btn btn-success btn-sm" id="btnOpenModal">➕ إضافة جلسة</button>
                <?php endif; ?>
                <!-- Boutons export à gauche (RTL = left) -->
                <div style="display: inline-flex; gap: 10px; background: white; padding: 10px; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); direction: ltr;">
                    <span style="color: #666; font-size: 11px; align-self: center; margin-left: 5px;">📥 تحميل</span>
                    <button onclick="exportData('excel')" class="btn-export btn-export-excel" title="تصدير إلى Excel">
                        <span>Excel</span>
                    </button>
                    <button onclick="exportData('word')" class="btn-export btn-export-word" title="تصدير إلى Word">
                        <span>Word</span>
                    </button>
                    <button onclick="exportData('pdf')" class="btn-export btn-export-pdf" title="تصدير إلى PDF">
                        <span>PDF</span>
                    </button>
                </div>
            </div>
    
    <?php if (count($commissions) > 0): ?>
            <table style="width: 100%;">
                <thead>
                    <tr>
                        <th>عدد الجلسة</th>
                        <th>تاريخ الجلسة</th>
                        <th>المقترحات المعروضة</th>
                        <th>نوعية المقترح</th>
                        <th>محضر الجلسة</th>
                        <th>قرار اللجنة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($commissions as $row): ?>                     
                           <tr>
                            <!-- عدد الجلسة -->
                            <td style="font-weight: bold; font-size: 16px; text-align: center;">
                                <div style="display: flex; flex-direction: column; align-items: center; gap: 5px;">
                                    <span style="font-size: 18px; color: #667eea;">
                                        <?php echo htmlspecialchars($row['numCommission']); ?>
                                    </span>
                                    <span style="font-size: 12px; color: #999; background: #f8f9fa; padding: 2px 8px; border-radius: 10px;">
                                        <?php echo date('Y', strtotime($row['dateCommission'])); ?>
                                    </span>
                                </div>
                            </td>
                            
                            <!-- تاريخ الجلسة -->
                            <td style="text-align: center;">
                                <div style="white-space: nowrap; font-size: 16px;font-weight: bold;">
                                   <?php echo date('d/m/Y', strtotime($row['dateCommission'])); ?>
                                </div>
                            </td>
                            
                            <!-- المقترحات المعروضة -->
                            <td>
                                <?php if (!empty($row['projets_list'])): ?>
                                    <div style="max-height: 300px; overflow-y: auto;">
                                        <?php foreach ($row['projets_list'] as $index => $p): ?>
                                            <div style="padding: 10px; margin: 5px 0; background: #f8f9fa; border-right: 3px solid #667eea; border-radius: 5px;">
                                                <div style="display: flex; align-items: start; gap: 10px;">
                                                    <span style="background: #667eea; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; min-width: 25px; text-align: center;">
                                                        <?php echo $index + 1; ?>
                                                    </span>
                                                    <span style="flex: 1; line-height: 1.4;">
                                                        <?php echo htmlspecialchars($p['sujet']); ?>
                                                    </span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #999; font-style: italic;">لا يوجد</span>
                                <?php endif; ?>
                            </td>
                            
                            <!-- نوعية المقترح -->
                            <td>
                                <?php if (!empty($row['projets_list'])): ?>
                                    <div style="max-height: 300px; overflow-y: auto;">
                                        <?php foreach ($row['projets_list'] as $p): 
                                            $badgeClass = '';
                                            $natureText = $p['natureLibelle'];
                                            
                                            if (strpos($natureText, 'إدراج') !== false) {
                                                $badgeClass = 'badge-processing';
                                            } else if (strpos($natureText, 'إسناد') !== false) {
                                                $badgeClass = 'badge-approved';
                                            }
                                        ?>
                                            <div style="margin: 5px 0;">
                                                <span class="badge <?php echo $badgeClass; ?>" style="display: block; text-align: center; padding: 8px; font-size: 13px;">
                                                    <?php echo htmlspecialchars($natureText); ?>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #999; font-style: italic;">لا يوجد</span>
                                <?php endif; ?>
                            </td>
                            
                            <td style="text-align: center;">
                                <?php if (!empty($row['mahdarPath'])): ?>
                                    <a href="<?php echo htmlspecialchars($row['mahdarPath']); ?>" 
                                    target="_blank" 
                                    class="btn-action btn-view" 
                                    title="<?php echo htmlspecialchars($row['mahdarLibelle'] ?? 'محضر الجلسة'); ?>"
                                    style="display: inline-flex; align-items: center; gap: 5px; padding: 8px 15px;">
                                        📄 عرض 
                                    </a>
                                <?php else: ?>
                                    <span style="color: #999; font-style: italic;">لا يوجد</span>
                                <?php endif; ?>
                            </td>

                            <!-- قرار اللجنة -->
                            <td style="text-align: center;">
                                <?php if (!empty($row['qararPath'])): ?>
                                    <a href="<?php echo htmlspecialchars($row['qararPath']); ?>" 
                                    target="_blank" 
                                    class="btn-action btn-view" 
                                    title="<?php echo htmlspecialchars($row['qararLibelle'] ?? 'قرار اللجنة'); ?>"
                                    style="display: inline-flex; align-items: center; gap: 5px; padding: 8px 15px;">
                                        📋 عرض
                                    </a>
                                <?php else: ?>
                                    <?php if (Permissions::canEditProjet($_SESSION['user_id'])): ?>
                                        <button type="button" 
                                                class="btn-action btn-success" 
                                                onclick="openQararModal(<?php echo $row['idCom']; ?>)"
                                                style="display: inline-flex; align-items: center; gap: 5px; padding: 8px 15px; border: none; cursor: pointer;">
                                            ➕ 
                                        </button>
                                    <?php else: ?>
                                        <span style="color: #999; font-style: italic;">لا يوجد</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            
                            <!-- الإجراءات -->
                            <td style="text-align: center;">
                                <div style="display: flex; gap: 5px; justify-content: center; flex-wrap: nowrap;">
                                    <?php if (Permissions::canEditProjet($_SESSION['user_id'])): ?>
                                        <button onclick="openEditCommissionModal(<?php echo $row['idCom']; ?>)" 
                                                class="btn-action btn-edit"
                                                title="تعديل الجلسة">
                                            تعديل
                                        </button>
                                        <button onclick="confirmDeleteCommission(<?php echo $row['idCom']; ?>)" 
                                                class="btn-action btn-delete"
                                                title="حذف الجلسة">
                                            حذف
                                        </button>
                                    <?php else: ?>
                                        <span style="color: #999; font-style: italic;">-</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                
            </table>
    <?php else: ?>
        <div style="text-align: center; padding: 60px 20px;">
            <div style="font-size: 48px; color: #ddd; margin-bottom: 20px;">📋</div>
            <h3 style="color: #999; margin-bottom: 10px;">لا توجد جلسات</h3>
            <p style="color: #bbb;">لم يتم إنشاء أي جلسة بعد</p>
            <?php if (Permissions::canCreateProjet()): ?>
                <button type="button" class="btn btn-success" id="btnOpenModal" style="margin-top: 20px;">
                    ➕ إضافة جلسة جديدة
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
            
            <?php if ($totalPages > 1): ?>
                <div class="pagination-container">
                    <div class="pagination-info">
                        عرض <?php echo (($currentPage - 1) * $itemsPerPage) + 1; ?> - 
                        <?php echo min($currentPage * $itemsPerPage, $totalItems); ?> 
                        من أصل <?php echo $totalItems; ?> جلسة
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
                        $range = 2;
                        
                        if ($currentPage > $range + 1) {
                            echo '<li><a href="' . buildPaginationUrl(1) . '">1</a></li>';
                            if ($currentPage > $range + 2) {
                                echo '<li><span class="dots">...</span></li>';
                            }
                        }
                        
                        for ($i = max(1, $currentPage - $range); $i <= min($totalPages, $currentPage + $range); $i++) {
                            if ($i == $currentPage) {
                                echo '<li class="active"><span>' . $i . '</span></li>';
                            } else {
                                echo '<li><a href="' . buildPaginationUrl($i) . '">' . $i . '</a></li>';
                            }
                        }
                        
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
            <!--
                ==========================================
                OPTION: Sélecteur du nombre d'éléments par page
                ========================================== 
            -->
            <div class="items-per-page" style="margin-top: 15px; text-align: center;">
                <label style="color: #666; font-size: 14px; margin-left: 10px;">عدد المقترحات في الصفحة:</label>
                <select id="itemsPerPageSelect" style="padding: 8px 12px; border: 2px solid #e0e0e0; border-radius: 6px; font-size: 14px;">
                    <option value="all">الكل</option>
                    <option value="10" selected>10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>
    </section>
    
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success" style="margin: 20px auto; max-width: 1200px;">
            ✓ <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-error" style="margin: 20px auto; max-width: 1200px;">
            ✕ <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
        </div>
    <?php endif; ?>
    <!-- MODAL ajout commission -->
    <div id="addCommissionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>➕ إضافة جلسة</h2>
                <span class="close" id="btnCloseModal">&times;</span>
            </div>
            <div class="modal-body">
                <div id="modalAlert"></div>    
                <form id="addCommissionForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="add_commission">
                    
                    <div class="form-grid">
                        
                        <!-- 1. عدد الجلسة -->
                        <div class="form-group">
                            <label>عدد الجلسة <span class="required">*</span></label>
                            <input type="number" name="numCommission" class="form-control" required min="1">
                        </div> 
                        
                        <!-- 2. تاريخ الجلسة -->
                        <div class="form-group">
                            <label>تاريخ الجلسة <span class="required">*</span></label>
                            <input type="date" name="dateCommission" class="form-control" required 
                                value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                    
                    <!-- NOUVELLE SECTION: المشاريع المعروضة -->
                    <div class="projets-section">
                        <div class="projets-section-header">
                            <h3>المشاريع المعروضة <span class="required">*</span></h3>
                            <button type="button" class="btn-add-projet" onclick="addProjet()">
                                ➕ إضافة مشروع
                            </button>
                        </div>
                        
                        <div id="projetsContainer">
                            <!-- Premier projet (par défaut) -->
                            <div class="projet-row" data-index="0">
                                <div class="form-group" style="margin: 0;">
                                    <label>المشروع <span class="required">*</span></label>
                                    <select name="projets[]" class="form-control" required>
                                        <option value="">-- اختر المشروع --</option>
                                        <?php foreach ($projets as $projet): ?>
                                            <option value="<?php echo $projet['idPro']; ?>">
                                                <?php echo htmlspecialchars($projet['sujet']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group" style="margin: 0;">
                                    <label>نوعية المقترح <span class="required">*</span></label>
                                    <select name="naturePcs[]" class="form-control" required>
                                        <option value="">-- اختر النوعية --</option>
                                        <option value="20">إدراج وقتي</option>
                                        <option value="21">إدراج نهائي</option>
                                        <option value="22">إسناد وقتي</option>
                                        <option value="23">إسناد نهائي</option>      
                                    </select>
                                </div>
                                
                                <button type="button" class="btn-remove" onclick="removeProjet(0)" style="visibility: hidden;">×</button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- SECTION FICHIERS -->
                    <div class="form-grid">
                        <!-- 3. ملف محضر الجلسة (اختياري) -->
                        <div class="form-group">
                            <label>محضر الجلسة <span style="color: #999;">(اختياري)</span></label>
                            <input type="file" name="fichierMahdar" id="fichierMahdar" class="form-control" 
                                accept=".pdf,.doc,.docx,.xls,.xlsx">
                            <small style="color: #666; font-size: 12px; display: block; margin-top: 5px;">
                                الحجم الأقصى: 20MB - الأنواع المقبولة: PDF, Word, Excel
                            </small>
                        </div>
                        
                        <!-- 4. عنوان محضر الجلسة -->
                        <div class="form-group">
                            <label>عنوان ملف المحضر <span id="mahdarRequired" style="color: #999;">(اختياري)</span></label>
                            <input type="text" name="libDocMahdar" id="libDocMahdar" class="form-control" 
                                placeholder="أدخل عنوان المحضر">
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-success">✓ حفظ الجلسة</button>
                        <button type="button" class="btn btn-secondary" id="btnCancelModal">✕ إلغاء</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- MODAL AJOUT QARAR -->
    <div id="addQararModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h2>➕ إضافة قرار اللجنة</h2>
                <span class="close" id="btnCloseQararModal">&times;</span>
            </div>
            <div class="modal-body">
                <div id="qararModalAlert"></div>
                
                <form id="addQararForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="upload_qarar">
                    <input type="hidden" name="idCom" id="qararIdCom" value="">
                    
                    <div class="form-group">
                        <label>عنوان قرار اللجنة <span class="required">*</span></label>
                        <input type="text" name="libDocQarar" id="libDocQarar" class="form-control" 
                            placeholder="أدخل عنوان قرار اللجنة" required>
                    </div>
                    
                    <div class="form-group">
                        <label>ملف قرار اللجنة <span class="required">*</span></label>
                        <input type="file" name="fichierQarar" id="fichierQarar" class="form-control" 
                            accept=".pdf,.doc,.docx,.xls,.xlsx" required>
                        <small style="color: #666; font-size: 12px; display: block; margin-top: 5px;">
                            الحجم الأقصى: 20MB - الأنواع المقبولة: PDF, Word, Excel
                        </small>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-success">✓ حفظ القرار</button>
                        <button type="button" class="btn btn-secondary" id="btnCancelQararModal">✕ إلغاء</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- MODAL MODIFICATION COMMISSION - À ajouter avant </body> -->
    <div id="editCommissionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>✏️ تعديل الجلسة</h2>
                <span class="close" id="btnCloseEditModal">&times;</span>
            </div>
            <div class="modal-body">
                <div id="editModalAlert"></div>
                
                <form id="editCommissionForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="edit_commission">
                    <input type="hidden" name="idCom" id="editIdCom">
                    
                    <div class="form-grid">
                        <!-- عدد الجلسة -->
                        <div class="form-group">
                            <label>عدد الجلسة <span class="required">*</span></label>
                            <input type="number" name="numCommission" id="editNumCommission" class="form-control" required min="1">
                        </div>
                        
                        <!-- تاريخ الجلسة -->
                        <div class="form-group">
                            <label>تاريخ الجلسة <span class="required">*</span></label>
                            <input type="date" name="dateCommission" id="editDateCommission" class="form-control" required>
                        </div>
                    </div>
                    
                    <!-- المشاريع المعروضة -->
                    <div class="projets-section">
                        <div class="projets-section-header">
                            <h3>المشاريع المعروضة <span class="required">*</span></h3>
                            <button type="button" class="btn-add-projet" onclick="addEditProjet()">
                                ➕ إضافة مشروع
                            </button>
                        </div>
                        
                        <div id="editProjetsContainer">
                            <!-- Les projets seront chargés dynamiquement ici -->
                        </div>
                    </div>
                    
                    <!-- Fichiers actuels -->
                    <div class="form-grid">
                        <div class="form-group">
                            <label>تحديث محضر الجلسة <span style="color: #999;">(اختياري)</span></label>
                            <input type="file" name="fichierMahdar" id="editFichierMahdar" class="form-control" 
                                accept=".pdf,.doc,.docx,.xls,.xlsx">
                            <small style="color: #666; font-size: 12px; display: block; margin-top: 5px;">
                                الحجم الأقصى: 20MB - اترك فارغاً للاحتفاظ بالملف الحالي
                            </small>                            
                        </div>
                        
                        <div class="form-group">
                            <label>عنوان ملف المحضر  <span style="color: #999;">(اختياري)</span></label>
                            <input type="text" name="libDocMahdar" id="editLibDocMahdar" class="form-control" 
                                placeholder="أدخل عنوان المحضر">
                        </div>
                        <div class="form-group">
                            
                            <label>تحديث قرار اللجنة <span style="color: #999;">(اختياري)</span></label>
                            <input type="file" name="fichierQarar" id="editFichierQarar" class="form-control" 
                                accept=".pdf,.doc,.docx,.xls,.xlsx">
                            <small style="color: #666; font-size: 12px; display: block; margin-top: 5px;">
                                الحجم الأقصى: 20MB - اترك فارغاً للاحتفاظ بالملف الحالي
                            </small>
                        </div>

                        <div class="form-group">
                            <label>عنوان قرار اللجنة <span style="color: #999;">(اختياري)</span></label>
                            <input type="text" name="libDocQarar" id="editLibDocQarar" class="form-control" 
                                placeholder="أدخل عنوان قرار اللجنة">
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-success">✓ حفظ التعديلات</button>
                        <button type="button" class="btn btn-secondary" id="btnCancelEditModal">✕ إلغاء</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <?php include 'includes/footer.php'; ?>

    <script>
        // Variables globales
        var modal = document.getElementById('addCommissionModal');
        var btnOpen = document.getElementById('btnOpenModal');
        var btnClose = document.getElementById('btnCloseModal');
        var btnCancel = document.getElementById('btnCancelModal');
        var projetIndex = 1; // Pour suivre l'index des projets ajoutés

        // Liste des projets (générée depuis PHP)
        var projetsOptions = `
            <option value="">-- اختر المشروع --</option>
            <?php foreach ($projets as $projet): ?>
                <option value="<?php echo $projet['idPro']; ?>">
                    <?php echo htmlspecialchars($projet['sujet']); ?>
                </option>
            <?php endforeach; ?>
        `;

        // Ouvrir le modal ajout commission
        if (btnOpen) {
            btnOpen.onclick = function() {
                modal.classList.add('show');
                document.body.style.overflow = 'hidden';
            }
        }

        // Fermer le modal ajout commission
        function fermerModal() {
            modal.classList.remove('show');
            document.body.style.overflow = 'auto';
            
            // Réinitialiser le formulaire
            document.getElementById('addCommissionForm').reset();
            document.getElementById('modalAlert').innerHTML = '';
            
            // Réinitialiser la liste des projets (garder seulement le premier)
            var container = document.getElementById('projetsContainer');
            var rows = container.querySelectorAll('.projet-row');
            
            // Supprimer tous les projets sauf le premier
            for (var i = 1; i < rows.length; i++) {
                rows[i].remove();
            }
            
            // Cacher le bouton de suppression du premier
            var firstRow = container.querySelector('.projet-row');
            if (firstRow) {
                firstRow.querySelector('.btn-remove').style.visibility = 'hidden';
            }
            
            projetIndex = 1;
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
        }

        // Fonction pour ajouter un nouveau projet
        function addProjet() {
            var container = document.getElementById('projetsContainer');
            
            var newRow = document.createElement('div');
            newRow.className = 'projet-row';
            newRow.setAttribute('data-index', projetIndex);
            
            newRow.innerHTML = `
                <div class="form-group" style="margin: 0;">
                    <label>المشروع <span class="required">*</span></label>
                    <select name="projets[]" class="form-control" required>
                        ${projetsOptions}
                    </select>
                </div>
                
                <div class="form-group" style="margin: 0;">
                    <label>نوعية المقترح <span class="required">*</span></label>
                    <select name="naturePcs[]" class="form-control" required>
                        <option value="">-- اختر النوعية --</option>
                        <option value="20">إدراج وقتي</option>
                        <option value="21">إدراج نهائي</option>
                        <option value="22">إسناد وقتي</option>
                        <option value="23">إسناد نهائي</option>      
                    </select>
                </div>
                
                <button type="button" class="btn-remove" onclick="removeProjet(${projetIndex})">×</button>
            `;
            
            container.appendChild(newRow);
            
            // Afficher le bouton de suppression du premier projet si plus d'un projet
            updateRemoveButtons();
            
            projetIndex++;
        }

        // Fonction pour supprimer un projet
        function removeProjet(index) {
            var row = document.querySelector(`.projet-row[data-index="${index}"]`);
            if (row) {
                row.remove();
                updateRemoveButtons();
            }
        }

        // Fonction pour mettre à jour l'affichage des boutons de suppression
        function updateRemoveButtons() {
            var rows = document.querySelectorAll('.projet-row');
            
            if (rows.length === 1) {
                // Si un seul projet, cacher le bouton de suppression
                rows[0].querySelector('.btn-remove').style.visibility = 'hidden';
            } else {
                // Si plusieurs projets, afficher tous les boutons
                rows.forEach(function(row) {
                    row.querySelector('.btn-remove').style.visibility = 'visible';
                });
            }
        }

        // Validation du fichier محضر الجلسة
        document.getElementById('fichierMahdar')?.addEventListener('change', function() {
            var file = this.files[0];
            var libDocInput = document.getElementById('libDocMahdar');
            var requiredSpan = document.getElementById('mahdarRequired');
            
            if (file) {
                var fileSize = file.size / 1024 / 1024; // En MB
                var allowedTypes = ['application/pdf', 'application/msword', 
                                   'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                   'application/vnd.ms-excel',
                                   'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
                
                if (fileSize > 20) {
                    alert('حجم الملف يجب أن يكون أقل من 20 ميغابايت');
                    this.value = '';
                    libDocInput.required = false;
                    requiredSpan.innerHTML = '(اختياري)';
                    requiredSpan.style.color = '#999';
                    return false;
                }
                
                if (!allowedTypes.includes(file.type)) {
                    alert('نوع الملف غير مقبول. يرجى اختيار ملف PDF أو Word أو Excel');
                    this.value = '';
                    libDocInput.required = false;
                    requiredSpan.innerHTML = '(اختياري)';
                    requiredSpan.style.color = '#999';
                    return false;
                }
                
                // Si un fichier est sélectionné, rendre le champ de titre obligatoire
                libDocInput.required = true;
                requiredSpan.innerHTML = '*';
                requiredSpan.style.color = '#dc3545';
            } else {
                // Si aucun fichier, le champ de titre n'est pas obligatoire
                libDocInput.required = false;
                requiredSpan.innerHTML = '(اختياري)';
                requiredSpan.style.color = '#999';
            }
        });

        // Soumettre le formulaire
        document.getElementById('addCommissionForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Vérifier qu'il y a au moins un projet
            var projetsSelects = document.querySelectorAll('select[name="projets[]"]');
            if (projetsSelects.length === 0) {
                alert('يجب إضافة مشروع واحد على الأقل');
                return false;
            }
            
            // Vérifier que tous les projets sont sélectionnés
            var allSelected = true;
            var selectedProjects = new Set();
            
            projetsSelects.forEach(function(select, index) {
                if (!select.value) {
                    allSelected = false;
                } else {
                    // Vérifier les doublons
                    if (selectedProjects.has(select.value)) {
                        alert('لا يمكن إضافة نفس المشروع مرتين');
                        allSelected = false;
                        return;
                    }
                    selectedProjects.add(select.value);
                }
            });
            
            if (!allSelected) {
                alert('يرجى اختيار جميع المشاريع بشكل صحيح');
                return false;
            }
            
            // Vérifier que toutes les نوعية sont sélectionnées
            var naturePcsSelects = document.querySelectorAll('select[name="naturePcs[]"]');
            var allNatureSelected = true;
            
            naturePcsSelects.forEach(function(select) {
                if (!select.value) {
                    allNatureSelected = false;
                }
            });
            
            if (!allNatureSelected) {
                alert('يرجى اختيار نوعية المقترح لجميع المشاريع');
                return false;
            }
            
            var formData = new FormData(this);
            var alertDiv = document.getElementById('modalAlert');
            
            // Afficher le loader
            alertDiv.innerHTML = '<div style="text-align: center; padding: 15px;"><div style="display: inline-block; border: 3px solid #f3f3f3; border-top: 3px solid #667eea; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite;"></div><p style="margin-top: 10px;">جاري الحفظ...</p></div>';
            
            fetch('commissions.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(text => {
                try {
                    var data = JSON.parse(text);
                    if (data.success) {
                        alertDiv.innerHTML = '<div class="alert alert-success">✓ ' + data.message + '</div>';
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else {
                        alertDiv.innerHTML = '<div class="alert alert-error">✕ ' + data.message + '</div>';
                    }
                } catch(e) {
                    console.error('Réponse non-JSON:', text);
                    alertDiv.innerHTML = '<div class="alert alert-error">✕ خطأ في الخادم: ' + text.substring(0, 200) + '</div>';
                }
            })
            .catch(function(error) {
                console.error('Error:', error);
                alertDiv.innerHTML = '<div class="alert alert-error">✕ حدث خطأ في الاتصال</div>';
            });
        });

        // Changement d'éléments par page
        document.getElementById('itemsPerPageSelect')?.addEventListener('change', function() {
            var params = new URLSearchParams(window.location.search);
            
            if (this.value === 'all') {
                params.set('items_per_page', 'all');
            } else {
                params.set('items_per_page', this.value);
            }
            
            params.delete('page');
            window.location.href = 'commissions.php?' + params.toString();
        });

        // Fonction pour confirmer la suppression d'une commission
        function confirmDeleteCommission(idCom) {
            if (confirm('هل أنت متأكد من حذف هذه الجلسة؟\n\nتحذير: سيتم حذف جميع المشاريع المرتبطة بهذه الجلسة!')) {
                // Créer un formulaire pour la suppression
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = 'delete_commission.php';
                
                var inputId = document.createElement('input');
                inputId.type = 'hidden';
                inputId.name = 'idCom';
                inputId.value = idCom;
                
                var inputToken = document.createElement('input');
                inputToken.type = 'hidden';
                inputToken.name = 'csrf_token';
                inputToken.value = '<?php echo $csrf_token; ?>';
                
                form.appendChild(inputId);
                form.appendChild(inputToken);
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Variables pour le modal Qarar
        var qararModal = document.getElementById('addQararModal');
        var btnCloseQarar = document.getElementById('btnCloseQararModal');
        var btnCancelQarar = document.getElementById('btnCancelQararModal');

        // Fonction pour ouvrir le modal Qarar
        function openQararModal(idCom) {
            document.getElementById('qararIdCom').value = idCom;
            document.getElementById('addQararForm').reset();
            document.getElementById('qararModalAlert').innerHTML = '';
            qararModal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        // Fonction pour fermer le modal Qarar
        function fermerQararModal() {
            qararModal.classList.remove('show');
            document.body.style.overflow = 'auto';
            document.getElementById('addQararForm').reset();
            document.getElementById('qararModalAlert').innerHTML = '';
        }

        // Événements de fermeture
        if (btnCloseQarar) {
            btnCloseQarar.onclick = fermerQararModal;
        }
        if (btnCancelQarar) {
            btnCancelQarar.onclick = fermerQararModal;
        }

        // Fermer en cliquant à l'extérieur
        window.addEventListener('click', function(event) {
            if (event.target == qararModal) {
                fermerQararModal();
            }
        });

        // Validation du fichier Qarar
        document.getElementById('fichierQarar')?.addEventListener('change', function() {
            var file = this.files[0];
            
            if (file) {
                var fileSize = file.size / 1024 / 1024; // En MB
                var allowedTypes = ['application/pdf', 'application/msword', 
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
                
                if (fileSize > 20) {
                    alert('حجم الملف يجب أن يكون أقل من 20 ميغابايت');
                    this.value = '';
                    return false;
                }
                
                if (!allowedTypes.includes(file.type)) {
                    alert('نوع الملف غير مقبول. يرجى اختيار ملف PDF أو Word أو Excel');
                    this.value = '';
                    return false;
                }
            }
        });

        // Soumettre le formulaire Qarar
        document.getElementById('addQararForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            
            var formData = new FormData(this);
            var alertDiv = document.getElementById('qararModalAlert');
            
            // Afficher le loader
            alertDiv.innerHTML = '<div style="text-align: center; padding: 15px;"><div style="display: inline-block; border: 3px solid #f3f3f3; border-top: 3px solid #667eea; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite;"></div><p style="margin-top: 10px;">جاري الرفع...</p></div>';
            
            fetch('commissions.php', {
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
        });
        // Variables pour le modal d'édition
        var editModal = document.getElementById('editCommissionModal');
        var btnCloseEdit = document.getElementById('btnCloseEditModal');
        var btnCancelEdit = document.getElementById('btnCancelEditModal');
        var editProjetIndex = 0;
        var currentEditAllProjets = []; // projets disponibles pour la commission en cours d'édition

        // Liste des projets disponibles
        var availableProjets = `
            <option value="">-- اختر المشروع --</option>
            <?php foreach ($projets as $projet): ?>
                <option value="<?php echo $projet['idPro']; ?>">
                    <?php echo htmlspecialchars($projet['sujet']); ?>
                </option>
            <?php endforeach; ?>
        `;

        // Fonction pour ouvrir le modal d'édition
        function openEditCommissionModal(idCom) {
            // Réinitialiser
            document.getElementById('editCommissionForm').reset();
            document.getElementById('editModalAlert').innerHTML = '';
            document.getElementById('editIdCom').value = idCom;
            
            // Afficher un loader
            document.getElementById('editProjetsContainer').innerHTML = '<p style="text-align: center; padding: 20px;">جاري التحميل...</p>';
            
            // Charger les données de la commission (cache-busting)
            fetch('get_commission.php?idCom=' + idCom + '&_=' + Date.now())
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Remplir les champs de base
                        document.getElementById('editNumCommission').value = data.commission.numCommission;
                        document.getElementById('editDateCommission').value = data.commission.dateCommission;
                        
                       
                        
                        // Construire la liste des projets disponibles pour le select :
                        // On utilise allProjets si disponible (nouveau get_commission.php),
                        // sinon on se rabat uniquement sur les projets liés à cette commission
                        var listeDisponible = (data.allProjets && data.allProjets.length > 0)
                            ? data.allProjets
                            : (data.projets || []).map(function(p) {
                                return { idPro: p.idPro, sujet: p.sujet };
                              });
                        currentEditAllProjets = listeDisponible;
                        
                        // Charger les projets liés à CETTE commission
                        var container = document.getElementById('editProjetsContainer');
                        container.innerHTML = '';
                        editProjetIndex = 0;
                        
                        if (data.projets && data.projets.length > 0) {
                            data.projets.forEach(function(projet, index) {
                                var row = document.createElement('div');
                                row.className = 'projet-row';
                                row.setAttribute('data-index', editProjetIndex);
                                
                                // S'assurer que le projet lié est dans la liste (même s'il n'a pas l'état éligible)
                                var dansList = listeDisponible.some(function(p) {
                                    return parseInt(p.idPro) === parseInt(projet.idPro);
                                });
                                var optionsList = dansList
                                    ? listeDisponible
                                    : [{ idPro: projet.idPro, sujet: projet.sujet }].concat(listeDisponible);
                                
                                // Construire les <option> avec le projet de cette commission sélectionné
                                var projetOptions = '<option value="">-- اختر المشروع --</option>';
                                optionsList.forEach(function(p) {
                                    var sel = (parseInt(p.idPro) === parseInt(projet.idPro)) ? ' selected' : '';
                                    projetOptions += '<option value="' + p.idPro + '"' + sel + '>' + p.sujet + '</option>';
                                });
                                
                                var natureOptions = '<option value="">-- اختر النوعية --</option>'
                                    + '<option value="20"' + (projet.naturePc == 20 ? ' selected' : '') + '>إدراج وقتي</option>'
                                    + '<option value="21"' + (projet.naturePc == 21 ? ' selected' : '') + '>إدراج نهائي</option>'
                                    + '<option value="22"' + (projet.naturePc == 22 ? ' selected' : '') + '>إسناد وقتي</option>'
                                    + '<option value="23"' + (projet.naturePc == 23 ? ' selected' : '') + '>إسناد نهائي</option>';
                                
                                var hideRemove = (index === 0 && data.projets.length === 1) ? 'hidden' : 'visible';
                                
                                row.innerHTML = '<div class="form-group" style="margin: 0;">'
                                    + '<label>المشروع <span class="required">*</span></label>'
                                    + '<select name="projets[]" class="form-control" required>'
                                    + projetOptions
                                    + '</select></div>'
                                    + '<div class="form-group" style="margin: 0;">'
                                    + '<label>نوعية المقترح <span class="required">*</span></label>'
                                    + '<select name="naturePcs[]" class="form-control" required>'
                                    + natureOptions
                                    + '</select></div>'
                                    + '<button type="button" class="btn-remove" onclick="removeEditProjet(' + editProjetIndex + ')" style="visibility: ' + hideRemove + ';">×</button>';
                                
                                container.appendChild(row);
                                editProjetIndex++;
                            });
                        } else {
                            // Ajouter un projet vide si aucun projet lié
                            addEditProjet();
                        }
                        
                        // Ouvrir le modal
                        editModal.classList.add('show');
                        document.body.style.overflow = 'hidden';
                    } else {
                        alert('خطأ في تحميل بيانات الجلسة: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('حدث خطأ في تحميل البيانات');
                });
        }

        // Fonction pour fermer le modal d'édition
        function fermerEditModal() {
            editModal.classList.remove('show');
            document.body.style.overflow = 'auto';
            document.getElementById('editCommissionForm').reset();
            document.getElementById('editModalAlert').innerHTML = '';
        }

        if (btnCloseEdit) {
            btnCloseEdit.onclick = fermerEditModal;
        }
        if (btnCancelEdit) {
            btnCancelEdit.onclick = fermerEditModal;
        }

        // Ajouter un projet dans l'édition
        function addEditProjet() {
            var container = document.getElementById('editProjetsContainer');
            
            var newRow = document.createElement('div');
            newRow.className = 'projet-row';
            newRow.setAttribute('data-index', editProjetIndex);
            
            // Construire les options depuis currentEditAllProjets (chargé dynamiquement par l'API)
            var projetOptions = '<option value="">-- اختر المشروع --</option>';
            currentEditAllProjets.forEach(function(p) {
                projetOptions += `<option value="${p.idPro}">${p.sujet}</option>`;
            });
            
            newRow.innerHTML = `
                <div class="form-group" style="margin: 0;">
                    <label>المشروع <span class="required">*</span></label>
                    <select name="projets[]" class="form-control" required>
                        ${projetOptions}
                    </select>
                </div>
                <div class="form-group" style="margin: 0;">
                    <label>نوعية المقترح <span class="required">*</span></label>
                    <select name="naturePcs[]" class="form-control" required>
                        <option value="">-- اختر النوعية --</option>
                        <option value="20">إدراج وقتي</option>
                        <option value="21">إدراج نهائي</option>
                        <option value="22">إسناد وقتي</option>
                        <option value="23">إسناد نهائي</option>
                    </select>
                </div>
                <button type="button" class="btn-remove" onclick="removeEditProjet(${editProjetIndex})">×</button>
            `;
            
            container.appendChild(newRow);
            updateEditRemoveButtons();
            editProjetIndex++;
        }

        // Supprimer un projet dans l'édition
        function removeEditProjet(index) {
            var row = document.querySelector(`#editProjetsContainer .projet-row[data-index="${index}"]`);
            if (row) {
                row.remove();
                updateEditRemoveButtons();
            }
        }

        // Mettre à jour les boutons de suppression
        function updateEditRemoveButtons() {
            var rows = document.querySelectorAll('#editProjetsContainer .projet-row');
            
            if (rows.length === 1) {
                rows[0].querySelector('.btn-remove').style.visibility = 'hidden';
            } else {
                rows.forEach(function(row) {
                    row.querySelector('.btn-remove').style.visibility = 'visible';
                });
            }
        }

        // Soumettre le formulaire d'édition
        document.getElementById('editCommissionForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            
            var formData = new FormData(this);
            var alertDiv = document.getElementById('editModalAlert');
            
            // Afficher le loader
            alertDiv.innerHTML = '<div style="text-align: center; padding: 15px;"><div style="display: inline-block; border: 3px solid #f3f3f3; border-top: 3px solid #667eea; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite;"></div><p style="margin-top: 10px;">جاري الحفظ...</p></div>';
            
            fetch('commissions.php', {
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
        });

        // Fermer en cliquant à l'extérieur
        window.addEventListener('click', function(event) {
            if (event.target == editModal) {
                fermerEditModal();
            }
        });
        
        // Fonction d'exportation
        function exportData(format) {
            const button = event.target.closest('.btn-export');
            button.classList.add('export-loading');
            button.disabled = true;
            
            // Créer un formulaire pour soumettre les données
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'export_commissions.php';
            form.target = '_blank';
            
            const formatInput = document.createElement('input');
            formatInput.type = 'hidden';
            formatInput.name = 'format';
            formatInput.value = format;
            form.appendChild(formatInput);
            
            // Ajouter les filtres actuels
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput && searchInput.value) {
                const search = document.createElement('input');
                search.type = 'hidden';
                search.name = 'search';
                search.value = searchInput.value;
                form.appendChild(search);
            }
            
            const yearSelect = document.querySelector('select[name="year"]');
            if (yearSelect && yearSelect.value) {
                const year = document.createElement('input');
                year.type = 'hidden';
                year.name = 'year';
                year.value = yearSelect.value;
                form.appendChild(year);
            }
            
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
            
            // Réactiver le bouton après un court délai
            setTimeout(() => {
                button.classList.remove('export-loading');
                button.disabled = false;
            }, 2000);
        }
    </script>
</body>
</html>