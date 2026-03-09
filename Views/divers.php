<?php
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    
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
    $page_title = "إدارة البيانات المتنوعة - رئاسة الحكومة";

    // ==================== SECTEUR ====================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_secteur') {
        while (ob_get_level()) { ob_end_clean(); }
        ob_start();
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            if (!Permissions::canCreateProjet()) {
                throw new Exception('ليس لديك صلاحية لإضافة قطاعات');
            }
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                throw new Exception('خطأ في رمز الأمان');
            }
            
            $numSecteur = isset($_POST['numSecteur']) ? intval($_POST['numSecteur']) : 0;
            if ($numSecteur <= 0) {
                throw new Exception('رقم القطاع مطلوب');
            }
            
            $checkQuery = "SELECT COUNT(*) as count FROM secteur WHERE numSecteur = :numSecteur";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->bindParam(':numSecteur', $numSecteur);
            $checkStmt->execute();
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                throw new Exception('رقم القطاع موجود مسبقا');
            }
            
            $query = "INSERT INTO secteur (numSecteur) VALUES (:numSecteur)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':numSecteur', $numSecteur);
            $stmt->execute();
            
            $action = "إضافة قطاع جديد: رقم " . $numSecteur;
            $idUserLog = $_SESSION['user_id'] ?? 0;
            $queryJournal = "INSERT INTO journal (idUser, action, date) VALUES (:idUser, :action, NOW())";
            $stmtJournal = $db->prepare($queryJournal);
            $stmtJournal->bindParam(':idUser', $idUserLog);
            $stmtJournal->bindParam(':action', $action);
            $stmtJournal->execute();
            
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'تمت إضافة القطاع بنجاح'], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_secteur') {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            if (!Permissions::canEditProjet($_SESSION['user_id'])) {
                throw new Exception('ليس لديك صلاحية لتعديل القطاعات');
            }
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                throw new Exception('خطأ في رمز الأمان');
            }
            
            $idSecteur = isset($_POST['idSecteur']) ? intval($_POST['idSecteur']) : 0;
            $numSecteur = isset($_POST['numSecteur']) ? intval($_POST['numSecteur']) : 0;
            
            if ($idSecteur <= 0 || $numSecteur <= 0) {
                throw new Exception('البيانات غير صالحة');
            }
            
            $query = "UPDATE secteur SET numSecteur = :numSecteur WHERE idSecteur = :idSecteur";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':numSecteur', $numSecteur);
            $stmt->bindParam(':idSecteur', $idSecteur);
            $stmt->execute();
            
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'تم تعديل القطاع بنجاح'], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_secteur') {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            if (!Permissions::canEditProjet($_SESSION['user_id'])) {
                throw new Exception('ليس لديك صلاحية لحذف القطاعات');
            }
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                throw new Exception('خطأ في رمز الأمان');
            }
            
            $idSecteur = isset($_POST['idSecteur']) ? intval($_POST['idSecteur']) : 0;
            if ($idSecteur <= 0) {
                throw new Exception('معرف القطاع غير صالح');
            }
            
            $query = "DELETE FROM secteur WHERE idSecteur = :idSecteur";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':idSecteur', $idSecteur);
            $stmt->execute();
            
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'تم حذف القطاع بنجاح'], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    if (isset($_GET['action']) && $_GET['action'] === 'get_secteur' && isset($_GET['idSecteur'])) {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $idSecteur = intval($_GET['idSecteur']);
            $query = "SELECT * FROM secteur WHERE idSecteur = :idSecteur";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':idSecteur', $idSecteur);
            $stmt->execute();
            $secteur = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($secteur) {
                echo json_encode(['success' => true, 'secteur' => $secteur], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['success' => false, 'message' => 'القطاع غير موجود'], JSON_UNESCAPED_UNICODE);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    // ==================== GOUVERNORAT ====================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_gouvernorat') {
        while (ob_get_level()) { ob_end_clean(); }
        ob_start();
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            if (!Permissions::canCreateProjet()) {
                throw new Exception('ليس لديك صلاحية لإضافة ولايات');
            }
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                throw new Exception('خطأ في رمز الأمان');
            }
            
            $libGov = isset($_POST['libGov']) ? trim($_POST['libGov']) : '';
            $positionGov = isset($_POST['positionGov']) ? trim($_POST['positionGov']) : '';
            $idSecteur = isset($_POST['idSecteur']) ? intval($_POST['idSecteur']) : 0;
            
            if (empty($libGov) || empty($positionGov) || $idSecteur <= 0) {
                throw new Exception('جميع الحقول مطلوبة');
            }
            
            $query = "INSERT INTO gouvernorat (libGov, positionGov, idSecteur) VALUES (:libGov, :positionGov, :idSecteur)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':libGov', $libGov);
            $stmt->bindParam(':positionGov', $positionGov);
            $stmt->bindParam(':idSecteur', $idSecteur);
            $stmt->execute();
            
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'تمت إضافة الولاية بنجاح'], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_gouvernorat') {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            if (!Permissions::canEditProjet($_SESSION['user_id'])) {
                throw new Exception('ليس لديك صلاحية لتعديل الولايات');
            }
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                throw new Exception('خطأ في رمز الأمان');
            }
            
            $idGov = isset($_POST['idGov']) ? intval($_POST['idGov']) : 0;
            $libGov = isset($_POST['libGov']) ? trim($_POST['libGov']) : '';
            $positionGov = isset($_POST['positionGov']) ? trim($_POST['positionGov']) : '';
            $idSecteur = isset($_POST['idSecteur']) ? intval($_POST['idSecteur']) : 0;
            
            if ($idGov <= 0 || empty($libGov) || empty($positionGov) || $idSecteur <= 0) {
                throw new Exception('جميع الحقول مطلوبة');
            }
            
            $query = "UPDATE gouvernorat SET libGov = :libGov, positionGov = :positionGov, idSecteur = :idSecteur WHERE idGov = :idGov";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':libGov', $libGov);
            $stmt->bindParam(':positionGov', $positionGov);
            $stmt->bindParam(':idSecteur', $idSecteur);
            $stmt->bindParam(':idGov', $idGov);
            $stmt->execute();
            
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'تم تعديل الولاية بنجاح'], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_gouvernorat') {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            if (!Permissions::canEditProjet($_SESSION['user_id'])) {
                throw new Exception('ليس لديك صلاحية لحذف الولايات');
            }
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                throw new Exception('خطأ في رمز الأمان');
            }
            
            $idGov = isset($_POST['idGov']) ? intval($_POST['idGov']) : 0;
            if ($idGov <= 0) {
                throw new Exception('معرف الولاية غير صالح');
            }
            
            $query = "DELETE FROM gouvernorat WHERE idGov = :idGov";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':idGov', $idGov);
            $stmt->execute();
            
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'تم حذف الولاية بنجاح'], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    if (isset($_GET['action']) && $_GET['action'] === 'get_gouvernorat' && isset($_GET['idGov'])) {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $idGov = intval($_GET['idGov']);
            $query = "SELECT * FROM gouvernorat WHERE idGov = :idGov";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':idGov', $idGov);
            $stmt->execute();
            $gouvernorat = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($gouvernorat) {
                echo json_encode(['success' => true, 'gouvernorat' => $gouvernorat], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['success' => false, 'message' => 'الولاية غير موجودة'], JSON_UNESCAPED_UNICODE);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    // ==================== MINISTERE ====================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_ministere') {
        while (ob_get_level()) { ob_end_clean(); }
        ob_start();
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            if (!Permissions::canCreateProjet()) {
                throw new Exception('ليس لديك صلاحية لإضافة وزارات');
            }
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                throw new Exception('خطأ في رمز الأمان');
            }
            
            $libMinistere = isset($_POST['libMinistere']) ? trim($_POST['libMinistere']) : '';
            $adresseMinistere = isset($_POST['adresseMinistere']) ? trim($_POST['adresseMinistere']) : '';
            $idGov = isset($_POST['idGov']) ? intval($_POST['idGov']) : 0;
            
            if (empty($libMinistere) || empty($adresseMinistere) || $idGov <= 0) {
                throw new Exception('جميع الحقول مطلوبة');
            }
            
            $query = "INSERT INTO ministere (libMinistere, adresseMinistere, idGov) VALUES (:libMinistere, :adresseMinistere, :idGov)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':libMinistere', $libMinistere);
            $stmt->bindParam(':adresseMinistere', $adresseMinistere);
            $stmt->bindParam(':idGov', $idGov);
            $stmt->execute();
            
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'تمت إضافة الوزارة بنجاح'], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_ministere') {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            if (!Permissions::canEditProjet($_SESSION['user_id'])) {
                throw new Exception('ليس لديك صلاحية لتعديل الوزارات');
            }
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                throw new Exception('خطأ في رمز الأمان');
            }
            
            $idMinistere = isset($_POST['idMinistere']) ? intval($_POST['idMinistere']) : 0;
            $libMinistere = isset($_POST['libMinistere']) ? trim($_POST['libMinistere']) : '';
            $adresseMinistere = isset($_POST['adresseMinistere']) ? trim($_POST['adresseMinistere']) : '';
            $idGov = isset($_POST['idGov']) ? intval($_POST['idGov']) : 0;
            
            if ($idMinistere <= 0 || empty($libMinistere) || empty($adresseMinistere) || $idGov <= 0) {
                throw new Exception('جميع الحقول مطلوبة');
            }
            
            $query = "UPDATE ministere SET libMinistere = :libMinistere, adresseMinistere = :adresseMinistere, idGov = :idGov WHERE idMinistere = :idMinistere";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':libMinistere', $libMinistere);
            $stmt->bindParam(':adresseMinistere', $adresseMinistere);
            $stmt->bindParam(':idGov', $idGov);
            $stmt->bindParam(':idMinistere', $idMinistere);
            $stmt->execute();
            
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'تم تعديل الوزارة بنجاح'], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_ministere') {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            if (!Permissions::canEditProjet($_SESSION['user_id'])) {
                throw new Exception('ليس لديك صلاحية لحذف الوزارات');
            }
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                throw new Exception('خطأ في رمز الأمان');
            }
            
            $idMinistere = isset($_POST['idMinistere']) ? intval($_POST['idMinistere']) : 0;
            if ($idMinistere <= 0) {
                throw new Exception('معرف الوزارة غير صالح');
            }
            
            $query = "DELETE FROM ministere WHERE idMinistere = :idMinistere";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':idMinistere', $idMinistere);
            $stmt->execute();
            
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'تم حذف الوزارة بنجاح'], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    if (isset($_GET['action']) && $_GET['action'] === 'get_ministere' && isset($_GET['idMinistere'])) {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $idMinistere = intval($_GET['idMinistere']);
            $query = "SELECT * FROM ministere WHERE idMinistere = :idMinistere";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':idMinistere', $idMinistere);
            $stmt->execute();
            $ministere = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($ministere) {
                echo json_encode(['success' => true, 'ministere' => $ministere], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['success' => false, 'message' => 'الوزارة غير موجودة'], JSON_UNESCAPED_UNICODE);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    // ==================== ETABLISSEMENT ====================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_etablissement') {
        while (ob_get_level()) { ob_end_clean(); }
        ob_start();
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            if (!Permissions::canCreateProjet()) {
                throw new Exception('ليس لديك صلاحية لإضافة مؤسسات');
            }
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                throw new Exception('خطأ في رمز الأمان');
            }
            
            $libEtablissement = isset($_POST['libEtablissement']) ? trim($_POST['libEtablissement']) : '';
            $adrEtablissement = isset($_POST['adrEtablissement']) ? trim($_POST['adrEtablissement']) : '';
            $idMinistere = isset($_POST['idMinistere']) ? intval($_POST['idMinistere']) : 0;
            $idGouvernement = isset($_POST['idGouvernement']) ? intval($_POST['idGouvernement']) : 0;
            
            if (empty($libEtablissement) || empty($adrEtablissement) || $idMinistere <= 0 || $idGouvernement <= 0) {
                throw new Exception('جميع الحقول مطلوبة');
            }
            
            $query = "INSERT INTO etablissement (libEtablissement, adrEtablissement, idMinistere, idGouvernement) VALUES (:libEtablissement, :adrEtablissement, :idMinistere, :idGouvernement)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':libEtablissement', $libEtablissement);
            $stmt->bindParam(':adrEtablissement', $adrEtablissement);
            $stmt->bindParam(':idMinistere', $idMinistere);
            $stmt->bindParam(':idGouvernement', $idGouvernement);
            $stmt->execute();
            
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'تمت إضافة المؤسسة بنجاح'], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_etablissement') {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            if (!Permissions::canEditProjet($_SESSION['user_id'])) {
                throw new Exception('ليس لديك صلاحية لتعديل المؤسسات');
            }
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                throw new Exception('خطأ في رمز الأمان');
            }
            
            $idEtablissement = isset($_POST['idEtablissement']) ? intval($_POST['idEtablissement']) : 0;
            $libEtablissement = isset($_POST['libEtablissement']) ? trim($_POST['libEtablissement']) : '';
            $adrEtablissement = isset($_POST['adrEtablissement']) ? trim($_POST['adrEtablissement']) : '';
            $idMinistere = isset($_POST['idMinistere']) ? intval($_POST['idMinistere']) : 0;
            $idGouvernement = isset($_POST['idGouvernement']) ? intval($_POST['idGouvernement']) : 0;
            
            if ($idEtablissement <= 0 || empty($libEtablissement) || empty($adrEtablissement) || $idMinistere <= 0 || $idGouvernement <= 0) {
                throw new Exception('جميع الحقول مطلوبة');
            }
            
            $query = "UPDATE etablissement SET libEtablissement = :libEtablissement, adrEtablissement = :adrEtablissement, idMinistere = :idMinistere, idGouvernement = :idGouvernement WHERE idEtablissement = :idEtablissement";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':libEtablissement', $libEtablissement);
            $stmt->bindParam(':adrEtablissement', $adrEtablissement);
            $stmt->bindParam(':idMinistere', $idMinistere);
            $stmt->bindParam(':idGouvernement', $idGouvernement);
            $stmt->bindParam(':idEtablissement', $idEtablissement);
            $stmt->execute();
            
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'تم تعديل المؤسسة بنجاح'], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_etablissement') {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            if (!Permissions::canEditProjet($_SESSION['user_id'])) {
                throw new Exception('ليس لديك صلاحية لحذف المؤسسات');
            }
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                throw new Exception('خطأ في رمز الأمان');
            }
            
            $idEtablissement = isset($_POST['idEtablissement']) ? intval($_POST['idEtablissement']) : 0;
            if ($idEtablissement <= 0) {
                throw new Exception('معرف المؤسسة غير صالح');
            }
            
            $query = "DELETE FROM etablissement WHERE idEtablissement = :idEtablissement";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':idEtablissement', $idEtablissement);
            $stmt->execute();
            
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'تم حذف المؤسسة بنجاح'], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    if (isset($_GET['action']) && $_GET['action'] === 'get_etablissement' && isset($_GET['idEtablissement'])) {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $idEtablissement = intval($_GET['idEtablissement']);
            $query = "SELECT * FROM etablissement WHERE idEtablissement = :idEtablissement";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':idEtablissement', $idEtablissement);
            $stmt->execute();
            $etablissement = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($etablissement) {
                echo json_encode(['success' => true, 'etablissement' => $etablissement], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['success' => false, 'message' => 'المؤسسة غير موجودة'], JSON_UNESCAPED_UNICODE);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    // ==================== FOURNISSEUR ====================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_fournisseur') {
        while (ob_get_level()) { ob_end_clean(); }
        ob_start();
        header('Content-Type: application/json; charset=utf-8');
        try {
            if (!Permissions::canCreateProjet()) throw new Exception('ليس لديك صلاحية لإضافة مورّد');
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) throw new Exception('خطأ في رمز الأمان');
            $nomFour     = trim($_POST['nomFour'] ?? '');
            $adresseFour = trim($_POST['adresseFour'] ?? '');
            $telFour     = trim($_POST['telFour'] ?? '');
            $emailFour   = trim($_POST['emailFour'] ?? '');
            $rib         = trim($_POST['rib'] ?? '');
            if (empty($nomFour)) throw new Exception('اسم المورّد مطلوب');
            $stmt = $db->prepare("INSERT INTO fournisseur (nomFour, adresseFour, telFour, emailFour, rib) VALUES (:nom, :adr, :tel, :email, :rib)");
            $stmt->execute([':nom' => $nomFour, ':adr' => $adresseFour, ':tel' => $telFour, ':email' => $emailFour, ':rib' => $rib]);
            $logStmt = $db->prepare("INSERT INTO journal (idUser, action, date) VALUES (:u, :a, NOW())");
            $logStmt->execute([':u' => $_SESSION['user_id'] ?? 0, ':a' => 'إضافة مورّد: ' . $nomFour]);
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'تمت إضافة المورّد بنجاح'], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) { ob_end_clean(); echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE); }
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_fournisseur') {
        ob_clean(); header('Content-Type: application/json; charset=utf-8');
        try {
            if (!Permissions::canEditProjet($_SESSION['user_id'])) throw new Exception('ليس لديك صلاحية لتعديل المورّد');
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) throw new Exception('خطأ في رمز الأمان');
            $idFour      = intval($_POST['idFournisseur'] ?? 0);
            $nomFour     = trim($_POST['nomFour'] ?? '');
            $adresseFour = trim($_POST['adresseFour'] ?? '');
            $telFour     = trim($_POST['telFour'] ?? '');
            $emailFour   = trim($_POST['emailFour'] ?? '');
            $rib         = trim($_POST['rib'] ?? '');
            if ($idFour <= 0 || empty($nomFour)) throw new Exception('البيانات غير صالحة');
            $stmt = $db->prepare("UPDATE fournisseur SET nomFour=:nom, adresseFour=:adr, telFour=:tel, emailFour=:email, rib=:rib WHERE idFour=:id");
            $stmt->execute([':nom' => $nomFour, ':adr' => $adresseFour, ':tel' => $telFour, ':email' => $emailFour, ':rib' => $rib, ':id' => $idFour]);
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'تم تعديل المورّد بنجاح'], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) { ob_end_clean(); echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE); }
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_fournisseur') {
        ob_clean(); header('Content-Type: application/json; charset=utf-8');
        try {
            if (!Permissions::canDeleteProjet($_SESSION['user_id'])) throw new Exception('ليس لديك صلاحية للحذف');
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) throw new Exception('خطأ في رمز الأمان');
            $idFour = intval($_POST['idFournisseur'] ?? 0);
            if ($idFour <= 0) throw new Exception('معرّف المورّد غير صالح');
            $db->prepare("DELETE FROM fournisseur WHERE idFour=:id")->execute([':id' => $idFour]);
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'تم حذف المورّد بنجاح'], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) { ob_end_clean(); echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE); }
        exit();
    }

    if (isset($_GET['action']) && $_GET['action'] === 'get_fournisseur' && isset($_GET['idFournisseur'])) {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $idFour = intval($_GET['idFournisseur']);
            $stmt = $db->prepare("SELECT * FROM fournisseur WHERE idFour = :id");
            $stmt->execute([':id' => $idFour]);
            $fournisseur = $stmt->fetch(PDO::FETCH_ASSOC);
            echo $fournisseur
                ? json_encode(['success' => true, 'fournisseur' => $fournisseur], JSON_UNESCAPED_UNICODE)
                : json_encode(['success' => false, 'message' => 'المورّد غير موجود'], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE); }
        exit();
    }

    // Récupération des données pour les selects
    $allSecteurs = $db->query("SELECT * FROM secteur ORDER BY numSecteur ASC")->fetchAll(PDO::FETCH_ASSOC);
    $allGouvernorats = $db->query("SELECT g.*, s.numSecteur FROM gouvernorat g LEFT JOIN secteur s ON g.idSecteur = s.idSecteur ORDER BY g.libGov ASC")->fetchAll(PDO::FETCH_ASSOC);
    $allMinisteres = $db->query("SELECT m.*, g.libGov FROM ministere m LEFT JOIN gouvernorat g ON m.idGov = g.idGov ORDER BY m.libMinistere ASC")->fetchAll(PDO::FETCH_ASSOC);
    $allEtablissements = $db->query("SELECT e.*, m.libMinistere, g.libGov FROM etablissement e LEFT JOIN ministere m ON e.idMinistere = m.idMinistere LEFT JOIN gouvernorat g ON e.idGouvernement = g.idGov ORDER BY e.libEtablissement ASC")->fetchAll(PDO::FETCH_ASSOC);
    $allFournisseurs   = $db->query("SELECT * FROM fournisseur ORDER BY nomFour ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Pagination pour Secteurs
    $pageSecteur = isset($_GET['page_secteur']) ? intval($_GET['page_secteur']) : 1;
    $itemsPerPage = 5;
    $offsetSecteur = ($pageSecteur - 1) * $itemsPerPage;
    $totalSecteurs = count($allSecteurs);
    $totalPagesSecteur = ceil($totalSecteurs / $itemsPerPage);
    $secteurs = array_slice($allSecteurs, $offsetSecteur, $itemsPerPage);

    // Pagination pour Gouvernorats
    $pageGouvernorat = isset($_GET['page_gouvernorat']) ? intval($_GET['page_gouvernorat']) : 1;
    $offsetGouvernorat = ($pageGouvernorat - 1) * $itemsPerPage;
    $totalGouvernorats = count($allGouvernorats);
    $totalPagesGouvernorat = ceil($totalGouvernorats / $itemsPerPage);
    $gouvernorats = array_slice($allGouvernorats, $offsetGouvernorat, $itemsPerPage);

    // Pagination pour Ministeres
    $pageMinistere = isset($_GET['page_ministere']) ? intval($_GET['page_ministere']) : 1;
    $offsetMinistere = ($pageMinistere - 1) * $itemsPerPage;
    $totalMinisteres = count($allMinisteres);
    $totalPagesMinistere = ceil($totalMinisteres / $itemsPerPage);
    $ministeres = array_slice($allMinisteres, $offsetMinistere, $itemsPerPage);

    // Pagination pour Etablissements
    $pageEtablissement = isset($_GET['page_etablissement']) ? intval($_GET['page_etablissement']) : 1;
    $offsetEtablissement = ($pageEtablissement - 1) * $itemsPerPage;
    $totalEtablissements = count($allEtablissements);
    $totalPagesEtablissement = ceil($totalEtablissements / $itemsPerPage);
    $etablissements = array_slice($allEtablissements, $offsetEtablissement, $itemsPerPage);

    // Pagination pour Fournisseurs
    $pageFournisseur = isset($_GET['page_fournisseur']) ? intval($_GET['page_fournisseur']) : 1;
    $offsetFournisseur = ($pageFournisseur - 1) * $itemsPerPage;
    $totalFournisseurs = count($allFournisseurs);
    $totalPagesFournisseur = ceil($totalFournisseurs / $itemsPerPage);
    $fournisseurs = array_slice($allFournisseurs, $offsetFournisseur, $itemsPerPage);

    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    
    if (!Permissions::canCreateProjet()) {
        header('Location: ../Public/projets.php');
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
            direction: rtl;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
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
        
        .section-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .section-title {
            font-size: 24px;
            font-weight: 700;
            color: #333;
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
            background: linear-gradient(135deg, #4a90e2 0%, #357abd 100%);
            color: white;
        }
        
        .btn-success {
            background: #4caf50;
            color: white;
        }
        
        .btn-success:hover {
            background: #45a049;
            transform: translateY(-2px);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background: linear-gradient(135deg, #4a90e2 0%, #357abd 100%);
            color: white;
        }
        
        th, td {
            padding: 12px;
            text-align: center;
        }
        
        td {
            border-bottom: 1px solid #f0f0f0;
        }
        
        tbody tr:hover {
            background: #f8f9fa;
        }
        
        .btn-action {
            padding: 6px 12px !important;
            border-radius: 6px;
            font-size: 12px !important;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
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
            max-width: 600px;
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
            background: linear-gradient(135deg, #4a90e2 0%, #357abd 100%);
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
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        
        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #4a90e2;
        }
        
        .required {
            color: #dc3545;
        }
        
        .modal-footer {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 600;
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
        
        .delete-modal {
            display: none;
            position: fixed;
            z-index: 100000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
            animation: fadeIn 0.3s;
        }
        
        .delete-modal.show {
            display: flex !important;
            align-items: center;
            justify-content: center;
        }
        
        .delete-modal-content {
            background: white;
            border-radius: 16px;
            width: 90%;
            max-width: 420px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            animation: scaleIn 0.3s ease-out;
            overflow: hidden;
            border: 3px solid #e53e3e;
        }
        
        @keyframes scaleIn {
            from {
                transform: scale(0.9);
                opacity: 0;
            }
            to {
                transform: scale(1);
                opacity: 1;
            }
        }
        
        .delete-modal-header {
            background: #fff;
            border-bottom: 1px solid #f0f0f0;
            padding: 20px 25px;
            text-align: center;
        }
        
        .delete-icon {
            width: 60px;
            height: 60px;
            margin: 0 auto 12px;
            background: linear-gradient(135deg, #fff5f5 0%, #ffe5e5 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
        }
        
        .delete-modal-header h3 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
            color: #2d3748;
        }
        
        .delete-modal-body {
            padding: 20px 25px;
        }
        
        .delete-modal-body p {
            font-size: 15px;
            color: #4a5568;
            margin-bottom: 8px;
            line-height: 1.5;
        }
        
        .delete-user-info {
            background: linear-gradient(135deg, #f7fafc 0%, #edf2f7 100%);
            border-left: 3px solid #4a90e2;
            border-radius: 8px;
            padding: 12px 15px;
            margin: 15px 0;
            font-size: 14px;
            color: #2d3748;
        }
        
        .delete-warning {
            background: linear-gradient(135deg, #fff5f5 0%, #fed7d7 100%);
            border-left: 3px solid #fc8181;
            border-radius: 8px;
            padding: 12px 15px;
            margin: 15px 0 0 0;
            font-size: 13px;
            color: #742a2a;
            line-height: 1.6;
        }
        
        .delete-modal-footer {
            padding: 20px 25px;
            background: #f8f9fa;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            border-top: 1px solid #e9ecef;
        }
        
        .btn-confirm-delete {
            padding: 10px 24px;
            background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 2px 8px rgba(229, 62, 62, 0.3);
        }
        
        .btn-confirm-delete:hover {
            background: linear-gradient(135deg, #c53030 0%, #9b2c2c 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(229, 62, 62, 0.4);
        }
        
        .btn-cancel-delete {
            padding: 10px 24px;
            background: white;
            color: #4a5568;
            border: 1px solid #cbd5e0;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-cancel-delete:hover {
            background: #f7fafc;
            border-color: #a0aec0;
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .tables-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 30px;
            margin-bottom: 30px;
        }
        
        @media (max-width: 1024px) {
            .tables-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .pagination-container {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 20px;
            padding: 15px;
            gap: 10px;
        }

        .pagination {
            display: flex;
            gap: 5px;
            align-items: center;
        }

        .pagination a, .pagination span {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 6px;
            text-decoration: none;
            color: #4a90e2;
            background: #f8f9fa;
            transition: all 0.3s;
            font-weight: 500;
            font-size: 13px;
            min-width: 36px;
            text-align: center;
        }

        .pagination a:hover {
            background: linear-gradient(135deg, #4a90e2 0%, #357abd 100%);
            color: white;
            transform: translateY(-1px);
        }

        .pagination .active span {
            background: linear-gradient(135deg, #4a90e2 0%, #357abd 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(74, 144, 226, 0.4);
        }

        .pagination .disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <section class="content-section" style="padding: 40px 0;">
        <div class="container">
            <div class="admin-header">
                <h2>📊 إدارة البيانات المتنوعة</h2>
                <p>إدارة الأقاليم، الولايات، الوزارات والمؤسسات</p>
            </div>
            
            <!-- First Row: Secteurs and Gouvernorats -->
            <div class="tables-grid">
                <!-- SECTEURS -->
                <div class="section-card">
                    <div class="section-header">
                        <h3 class="section-title"> الأقاليم</h3>
                        <button class="btn btn-success" onclick="openModal('secteur', 'add')">➕ إقليم </button>
                    </div>
                    <?php if (count($secteurs) > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>رقم الإقليم</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($secteurs as $secteur): ?>
                                    <tr>
                                        <td style="font-weight: 600;">الإقليم رقم <?php echo htmlspecialchars($secteur['numSecteur']); ?></td>
                                        <td>
                                            <button onclick="openModal('secteur', 'edit', <?php echo $secteur['idSecteur']; ?>)" class="btn-action btn-edit">تعديل</button>
                                            <button onclick="confirmDelete('secteur', <?php echo $secteur['idSecteur']; ?>, 'القطاع رقم <?php echo $secteur['numSecteur']; ?>')" class="btn-action btn-delete">حذف</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <?php if ($totalPagesSecteur > 1): ?>
                            <div class="pagination-container">
                                <div class="pagination">
                                    <?php if ($pageSecteur > 1): ?>
                                        <a href="?page_secteur=<?php echo $pageSecteur - 1; ?>">السابق</a>
                                    <?php else: ?>
                                        <span class="disabled">السابق</span>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = 1; $i <= $totalPagesSecteur; $i++): ?>
                                        <?php if ($i == $pageSecteur): ?>
                                            <span class="active"><span><?php echo $i; ?></span></span>
                                        <?php else: ?>
                                            <a href="?page_secteur=<?php echo $i; ?>"><?php echo $i; ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                    
                                    <?php if ($pageSecteur < $totalPagesSecteur): ?>
                                        <a href="?page_secteur=<?php echo $pageSecteur + 1; ?>">التالي</a>
                                    <?php else: ?>
                                        <span class="disabled">التالي</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">🏢</div>
                            <p>لا توجد قطاعات</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- GOUVERNORATS -->
                <div class="section-card">
                    <div class="section-header">
                        <h3 class="section-title"> الولايات</h3>
                        <button class="btn btn-success" onclick="openModal('gouvernorat', 'add')">➕ ولاية </button>
                    </div>
                    <?php if (count($gouvernorats) > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>الولاية</th>
                                    <th>الموقع</th>
                                    <th>القطاع</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($gouvernorats as $gov): ?>
                                    <tr>
                                        <td style="font-weight: 600;"><?php echo htmlspecialchars($gov['libGov']); ?></td>
                                        <td style="font-size: 12px;"><?php echo htmlspecialchars($gov['positionGov']); ?></td>
                                        <td style="font-size: 12px;">القطاع <?php echo htmlspecialchars($gov['numSecteur'] ?? '-'); ?></td>
                                        <td>
                                            <button onclick="openModal('gouvernorat', 'edit', <?php echo $gov['idGov']; ?>)" class="btn-action btn-edit">تعديل</button>
                                            <button onclick="confirmDelete('gouvernorat', <?php echo $gov['idGov']; ?>, '<?php echo htmlspecialchars($gov['libGov']); ?>')" class="btn-action btn-delete">حذف</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <?php if ($totalPagesGouvernorat > 1): ?>
                            <div class="pagination-container">
                                <div class="pagination">
                                    <?php if ($pageGouvernorat > 1): ?>
                                        <a href="?page_gouvernorat=<?php echo $pageGouvernorat - 1; ?>">السابق</a>
                                    <?php else: ?>
                                        <span class="disabled">السابق</span>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = 1; $i <= $totalPagesGouvernorat; $i++): ?>
                                        <?php if ($i == $pageGouvernorat): ?>
                                            <span class="active"><span><?php echo $i; ?></span></span>
                                        <?php else: ?>
                                            <a href="?page_gouvernorat=<?php echo $i; ?>"><?php echo $i; ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                    
                                    <?php if ($pageGouvernorat < $totalPagesGouvernorat): ?>
                                        <a href="?page_gouvernorat=<?php echo $pageGouvernorat + 1; ?>">التالي</a>
                                    <?php else: ?>
                                        <span class="disabled">التالي</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">🗺️</div>
                            <p>لا توجد ولايات</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Second Row: Ministeres and Etablissements -->
            <div class="tables-grid">
                <!-- MINISTERES -->
                <div class="section-card">
                    <div class="section-header">
                        <h3 class="section-title"> الوزارات</h3>
                        <button class="btn btn-success" onclick="openModal('ministere', 'add')">➕ وزارة </button>
                    </div>
                    <?php if (count($ministeres) > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>الوزارة</th>
                                    <th>العنوان</th>
                                    <th>الولاية</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ministeres as $min): ?>
                                    <tr>
                                        <td style="font-weight: 600; font-size: 12px;"><?php echo htmlspecialchars(mb_substr($min['libMinistere'], 0, 25)) . (mb_strlen($min['libMinistere']) > 25 ? '...' : ''); ?></td>
                                        <td style="font-size: 11px;"><?php echo htmlspecialchars(mb_substr($min['adresseMinistere'], 0, 30)) . (mb_strlen($min['adresseMinistere']) > 30 ? '...' : ''); ?></td>
                                        <td style="font-size: 12px;"><?php echo htmlspecialchars($min['libGov'] ?? '-'); ?></td>
                                        <td>
                                            <button onclick="openModal('ministere', 'edit', <?php echo $min['idMinistere']; ?>)" class="btn-action btn-edit">تعديل</button>
                                            <button onclick="confirmDelete('ministere', <?php echo $min['idMinistere']; ?>, '<?php echo htmlspecialchars($min['libMinistere']); ?>')" class="btn-action btn-delete">حذف</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <?php if ($totalPagesMinistere > 1): ?>
                            <div class="pagination-container">
                                <div class="pagination">
                                    <?php if ($pageMinistere > 1): ?>
                                        <a href="?page_ministere=<?php echo $pageMinistere - 1; ?>">السابق</a>
                                    <?php else: ?>
                                        <span class="disabled">السابق</span>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = 1; $i <= $totalPagesMinistere; $i++): ?>
                                        <?php if ($i == $pageMinistere): ?>
                                            <span class="active"><span><?php echo $i; ?></span></span>
                                        <?php else: ?>
                                            <a href="?page_ministere=<?php echo $i; ?>"><?php echo $i; ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                    
                                    <?php if ($pageMinistere < $totalPagesMinistere): ?>
                                        <a href="?page_ministere=<?php echo $pageMinistere + 1; ?>">التالي</a>
                                    <?php else: ?>
                                        <span class="disabled">التالي</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">🏛️</div>
                            <p>لا توجد وزارات</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ETABLISSEMENTS -->
                <div class="section-card">
                    <div class="section-header">
                        <h3 class="section-title"> المؤسسات</h3>
                        <button class="btn btn-success" onclick="openModal('etablissement', 'add')">➕ مؤسسة </button>
                    </div>
                    <?php if (count($etablissements) > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>المؤسسة</th>
                                    <th>العنوان</th>
                                    <th>الوزارة</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($etablissements as $etab): ?>
                                    <tr>
                                        <td style="font-weight: 600; font-size: 12px;"><?php echo htmlspecialchars(mb_substr($etab['libEtablissement'], 0, 25)) . (mb_strlen($etab['libEtablissement']) > 25 ? '...' : ''); ?></td>
                                        <td style="font-size: 11px;"><?php echo htmlspecialchars(mb_substr($etab['adrEtablissement'], 0, 25)) . (mb_strlen($etab['adrEtablissement']) > 25 ? '...' : ''); ?></td>
                                        <td style="font-size: 11px;"><?php echo htmlspecialchars(mb_substr($etab['libMinistere'] ?? '-', 0, 20)); ?></td>
                                        <td>
                                            <button onclick="openModal('etablissement', 'edit', <?php echo $etab['idEtablissement']; ?>)" class="btn-action btn-edit">تعديل</button>
                                            <button onclick="confirmDelete('etablissement', <?php echo $etab['idEtablissement']; ?>, '<?php echo htmlspecialchars($etab['libEtablissement']); ?>')" class="btn-action btn-delete">حذف</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <?php if ($totalPagesEtablissement > 1): ?>
                            <div class="pagination-container">
                                <div class="pagination">
                                    <?php if ($pageEtablissement > 1): ?>
                                        <a href="?page_etablissement=<?php echo $pageEtablissement - 1; ?>">السابق</a>
                                    <?php else: ?>
                                        <span class="disabled">السابق</span>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = 1; $i <= $totalPagesEtablissement; $i++): ?>
                                        <?php if ($i == $pageEtablissement): ?>
                                            <span class="active"><span><?php echo $i; ?></span></span>
                                        <?php else: ?>
                                            <a href="?page_etablissement=<?php echo $i; ?>"><?php echo $i; ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                    
                                    <?php if ($pageEtablissement < $totalPagesEtablissement): ?>
                                        <a href="?page_etablissement=<?php echo $pageEtablissement + 1; ?>">التالي</a>
                                    <?php else: ?>
                                        <span class="disabled">التالي</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">🏢</div>
                            <p>لا توجد مؤسسات</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ===== الموردون ===== -->
                <div class="section-card">
                    <div class="section-header">
                        <h3 class="section-title"> أصحاب الصفقة</h3>
                        <button class="btn btn-success" onclick="openModal('fournisseur', 'add')">➕ مورّد</button>
                    </div>
                    <?php if (count($fournisseurs) > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>الاسم</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($fournisseurs as $four): ?>
                                    <tr>
                                        <td style="font-weight:600; font-size:12px;"><?php echo htmlspecialchars(mb_substr($four['nomFour'], 0, 30)) . (mb_strlen($four['nomFour']) > 30 ? '...' : ''); ?></td>
                                        <td>
                                            <button onclick="openModal('fournisseur', 'edit', <?php echo $four['idFour']; ?>)" class="btn-action btn-edit">تعديل</button>
                                            <button onclick="confirmDelete('fournisseur', <?php echo $four['idFour']; ?>, '<?php echo htmlspecialchars($four['nomFour']); ?>')" class="btn-action btn-delete">حذف</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php if ($totalPagesFournisseur > 1): ?>
                            <div class="pagination-container">
                                <div class="pagination">
                                    <?php if ($pageFournisseur > 1): ?>
                                        <a href="?page_fournisseur=<?php echo $pageFournisseur - 1; ?>">السابق</a>
                                    <?php else: ?>
                                        <span class="disabled">السابق</span>
                                    <?php endif; ?>
                                    <?php for ($i = 1; $i <= $totalPagesFournisseur; $i++): ?>
                                        <?php if ($i == $pageFournisseur): ?>
                                            <span class="active"><span><?php echo $i; ?></span></span>
                                        <?php else: ?>
                                            <a href="?page_fournisseur=<?php echo $i; ?>"><?php echo $i; ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                    <?php if ($pageFournisseur < $totalPagesFournisseur): ?>
                                        <a href="?page_fournisseur=<?php echo $pageFournisseur + 1; ?>">التالي</a>
                                    <?php else: ?>
                                        <span class="disabled">التالي</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">🏭</div>
                            <p>لا يوجد موردون</p>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </section>

    <!-- Modal Secteur -->
    <div id="modalSecteur" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalSecteurTitle">قطاع</h2>
                <span class="close" onclick="closeModal('secteur')">&times;</span>
            </div>
            <div class="modal-body">
                <div id="modalSecteurAlert"></div>
                <form id="formSecteur">
                    <input type="hidden" name="action" id="secteurAction">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="idSecteur" id="secteurId">
                    
                    <div class="form-group">
                        <label>رقم القطاع <span class="required">*</span></label>
                        <input type="number" name="numSecteur" id="secteurNum" class="form-control" required min="1">
                    </div>
                    
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-success">💾 حفظ</button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal('secteur')">إلغاء</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Gouvernorat -->
    <div id="modalGouvernorat" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalGouvernoratTitle">ولاية</h2>
                <span class="close" onclick="closeModal('gouvernorat')">&times;</span>
            </div>
            <div class="modal-body">
                <div id="modalGouvernoratAlert"></div>
                <form id="formGouvernorat">
                    <input type="hidden" name="action" id="gouvernoratAction">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="idGov" id="gouvernoratId">
                    
                    <div class="form-group">
                        <label>اسم الولاية <span class="required">*</span></label>
                        <input type="text" name="libGov" id="gouvernoratLib" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label>الموقع <span class="required">*</span></label>
                        <input type="text" name="positionGov" id="gouvernoratPosition" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label>القطاع <span class="required">*</span></label>
                        <select name="idSecteur" id="gouvernoratSecteur" class="form-control" required>
                            <option value="">اختر القطاع</option>
                            <?php foreach ($allSecteurs as $s): ?>
                                <option value="<?php echo $s['idSecteur']; ?>">القطاع رقم <?php echo $s['numSecteur']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-success">💾 حفظ</button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal('gouvernorat')">إلغاء</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Ministere -->
    <div id="modalMinistere" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalMinistereTitle">وزارة</h2>
                <span class="close" onclick="closeModal('ministere')">&times;</span>
            </div>
            <div class="modal-body">
                <div id="modalMinistereAlert"></div>
                <form id="formMinistere">
                    <input type="hidden" name="action" id="ministereAction">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="idMinistere" id="ministereId">
                    
                    <div class="form-group">
                        <label>اسم الوزارة <span class="required">*</span></label>
                        <input type="text" name="libMinistere" id="ministereLib" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label>العنوان <span class="required">*</span></label>
                        <textarea name="adresseMinistere" id="ministereAdresse" class="form-control" required rows="3"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>الولاية <span class="required">*</span></label>
                        <select name="idGov" id="ministereGov" class="form-control" required>
                            <option value="">اختر الولاية</option>
                            <?php foreach ($allGouvernorats as $g): ?>
                                <option value="<?php echo $g['idGov']; ?>"><?php echo htmlspecialchars($g['libGov']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-success">💾 حفظ</button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal('ministere')">إلغاء</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Etablissement -->
    <div id="modalEtablissement" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalEtablissementTitle">مؤسسة</h2>
                <span class="close" onclick="closeModal('etablissement')">&times;</span>
            </div>
            <div class="modal-body">
                <div id="modalEtablissementAlert"></div>
                <form id="formEtablissement">
                    <input type="hidden" name="action" id="etablissementAction">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="idEtablissement" id="etablissementId">
                    
                    <div class="form-group">
                        <label>اسم المؤسسة <span class="required">*</span></label>
                        <input type="text" name="libEtablissement" id="etablissementLib" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label>العنوان <span class="required">*</span></label>
                        <textarea name="adrEtablissement" id="etablissementAdr" class="form-control" required rows="3"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>الوزارة <span class="required">*</span></label>
                        <select name="idMinistere" id="etablissementMinistere" class="form-control" required>
                            <option value="">اختر الوزارة</option>
                            <?php foreach ($allMinisteres as $m): ?>
                                <option value="<?php echo $m['idMinistere']; ?>"><?php echo htmlspecialchars($m['libMinistere']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>الولاية <span class="required">*</span></label>
                        <select name="idGouvernement" id="etablissementGov" class="form-control" required>
                            <option value="">اختر الولاية</option>
                            <?php foreach ($allGouvernorats as $g): ?>
                                <option value="<?php echo $g['idGov']; ?>"><?php echo htmlspecialchars($g['libGov']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-success">💾 حفظ</button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal('etablissement')">إلغاء</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Fournisseur -->
    <div id="modalFournisseur" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalFournisseurTitle">مورّد</h2>
                <span class="close" onclick="closeModal('fournisseur')">&times;</span>
            </div>
            <div class="modal-body">
                <div id="modalFournisseurAlert"></div>
                <form id="formFournisseur">
                    <input type="hidden" name="action"       id="fournisseurAction">
                    <input type="hidden" name="csrf_token"   value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="idFournisseur" id="fournisseurId">

                    <div class="form-group">
                        <label>اسم المورّد <span class="required">*</span></label>
                        <input type="text" name="nomFour" id="fournisseurNom" class="form-control" required placeholder="أدخل اسم المورّد">
                    </div>
                    <div class="form-group">
                        <label>العنوان</label>
                        <input type="text" name="adresseFour" id="fournisseurAdr" class="form-control" placeholder="عنوان المورّد">
                    </div>
                    <div class="form-group">
                        <label>الهاتف</label>
                        <input type="text" name="telFour" id="fournisseurTel" class="form-control" placeholder="رقم الهاتف">
                    </div>
                    <div class="form-group">
                        <label>البريد الإلكتروني</label>
                        <input type="email" name="emailFour" id="fournisseurEmail" class="form-control" placeholder="البريد الإلكتروني">
                    </div>
                    <div class="form-group">
                        <label>RIB</label>
                        <input type="text" name="rib" id="fournisseurRib" class="form-control" placeholder="رقم الحساب البنكي">
                    </div>

                    <div class="modal-footer">
                        <button type="submit" class="btn btn-success">💾 حفظ</button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal('fournisseur')">إلغاء</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Delete -->
    <div id="deleteModal" class="delete-modal">
        <div class="delete-modal-content">
            <div class="delete-modal-header">
                <div class="delete-icon">🗑️</div>
                <h3>تأكيد الحذف</h3>
            </div>
            <div class="delete-modal-body">
                <p style="font-size: 14px; font-weight: 500; margin-bottom: 15px; color: #718096;">
                    هل أنت متأكد من حذف هذا العنصر؟
                </p>
                <div class="delete-user-info" id="deleteInfo"></div>
                <div class="delete-warning">
                    ⚠️ لا يمكن التراجع عن هذا الإجراء
                </div>
            </div>
            <div class="delete-modal-footer">
                <button class="btn-cancel-delete" onclick="closeDeleteModal()">إلغاء</button>
                <button class="btn-confirm-delete" id="confirmDeleteBtn">حذف</button>
            </div>
        </div>
    </div>

    <script>
        let deleteType = '';
        let deleteId = 0;

        function openModal(type, mode, id = null) {
            const modal = document.getElementById('modal' + capitalize(type));
            const form = document.getElementById('form' + capitalize(type));
            const titleEl = document.getElementById('modal' + capitalize(type) + 'Title');
            const actionInput = document.getElementById(type + 'Action');
            const alertDiv = document.getElementById('modal' + capitalize(type) + 'Alert');
            
            form.reset();
            alertDiv.innerHTML = '';
            
            if (mode === 'add') {
                titleEl.textContent = '➕ إضافة ' + getEntityName(type);
                actionInput.value = 'add_' + type;
            } else {
                titleEl.textContent = '✏️ تعديل ' + getEntityName(type);
                actionInput.value = 'edit_' + type;
                loadData(type, id);
            }
            
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(type) {
            const modal = document.getElementById('modal' + capitalize(type));
            modal.classList.remove('show');
            document.body.style.overflow = 'auto';
        }

        function capitalize(str) {
            return str.charAt(0).toUpperCase() + str.slice(1);
        }

        function getEntityName(type) {
            const names = {
                'secteur': 'قطاع',
                'gouvernorat': 'ولاية',
                'ministere': 'وزارة',
                'etablissement': 'مؤسسة',
                'fournisseur': 'مورّد'
            };
            return names[type] || type;
        }

        function loadData(type, id) {
            fetch(`divers.php?action=get_${type}&id${capitalize(type)}=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const item = data[type];
                        
                        if (type === 'secteur') {
                            document.getElementById('secteurId').value = item.idSecteur;
                            document.getElementById('secteurNum').value = item.numSecteur;
                        } else if (type === 'gouvernorat') {
                            document.getElementById('gouvernoratId').value = item.idGov;
                            document.getElementById('gouvernoratLib').value = item.libGov;
                            document.getElementById('gouvernoratPosition').value = item.positionGov;
                            document.getElementById('gouvernoratSecteur').value = item.idSecteur;
                        } else if (type === 'ministere') {
                            document.getElementById('ministereId').value = item.idMinistere;
                            document.getElementById('ministereLib').value = item.libMinistere;
                            document.getElementById('ministereAdresse').value = item.adresseMinistere;
                            document.getElementById('ministereGov').value = item.idGov;
                        } else if (type === 'etablissement') {
                            document.getElementById('etablissementId').value = item.idEtablissement;
                            document.getElementById('etablissementLib').value = item.libEtablissement;
                            document.getElementById('etablissementAdr').value = item.adrEtablissement;
                            document.getElementById('etablissementMinistere').value = item.idMinistere;
                            document.getElementById('etablissementGov').value = item.idGouvernement;
                        } else if (type === 'fournisseur') {
                            document.getElementById('fournisseurId').value    = item.idFour;
                            document.getElementById('fournisseurNom').value   = item.nomFour;
                            document.getElementById('fournisseurAdr').value   = item.adresseFour;
                            document.getElementById('fournisseurTel').value   = item.telFour;
                            document.getElementById('fournisseurEmail').value = item.emailFour;
                            document.getElementById('fournisseurRib').value   = item.rib;
                        }
                    }
                })
                .catch(error => console.error('Error:', error));
        }

        // Form submissions
        ['Secteur', 'Gouvernorat', 'Ministere', 'Etablissement', 'Fournisseur'].forEach(type => {
            document.getElementById('form' + type)?.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                const alertDiv = document.getElementById('modal' + type + 'Alert');
                
                alertDiv.innerHTML = '<div style="text-align: center; padding: 15px;"><div style="display: inline-block; border: 3px solid #f3f3f3; border-top: 3px solid #667eea; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite;"></div><p style="margin-top: 10px;">جاري الحفظ...</p></div>';
                
                fetch('divers.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alertDiv.innerHTML = '<div class="alert alert-success">✓ ' + data.message + '</div>';
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        alertDiv.innerHTML = '<div class="alert alert-error">✕ ' + data.message + '</div>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alertDiv.innerHTML = '<div class="alert alert-error">✕ حدث خطأ في الاتصال</div>';
                });
            });
        });

        function confirmDelete(type, id, name) {
            deleteType = type;
            deleteId = id;
            
            document.getElementById('deleteInfo').innerHTML = `
                <div style="text-align: right; line-height: 1.8;">
                    <strong>${name}</strong>
                </div>
            `;
            
            document.getElementById('deleteModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('show');
            document.body.style.overflow = 'auto';
            deleteType = '';
            deleteId = 0;
        }

        document.getElementById('confirmDeleteBtn')?.addEventListener('click', function() {
            if (deleteType && deleteId) {
                const btn = this;
                btn.disabled = true;
                btn.innerHTML = '<div style="display: inline-block; border: 2px solid rgba(255,255,255,0.3); border-top: 2px solid white; border-radius: 50%; width: 14px; height: 14px; animation: spin 1s linear infinite; margin-left: 8px;"></div> جاري الحذف...';
                
                const formData = new FormData();
                formData.append('action', 'delete_' + deleteType);
                formData.append('id' + capitalize(deleteType), deleteId);
                formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
                
                fetch('divers.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.querySelector('.delete-modal-content').innerHTML = `
                            <div style="padding: 40px 30px; text-align: center;">
                                <div style="width: 70px; height: 70px; margin: 0 auto 15px; background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 36px; color: white; animation: scaleIn 0.5s;">
                                    ✓
                                </div>
                                <h3 style="color: #2d3748; margin-bottom: 8px; font-size: 18px; font-weight: 600;">تم الحذف بنجاح</h3>
                                <p style="color: #718096; font-size: 14px;">${data.message}</p>
                            </div>
                        `;
                        
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        btn.disabled = false;
                        btn.innerHTML = 'حذف';
                        alert('✕ ' + data.message);
                        closeDeleteModal();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    btn.disabled = false;
                    btn.innerHTML = 'حذف';
                    alert('✕ حدث خطأ في الاتصال');
                    closeDeleteModal();
                });
            }
        });

        window.addEventListener('click', function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('show');
                document.body.style.overflow = 'auto';
            }
        });
    </script>
</body>
</html>