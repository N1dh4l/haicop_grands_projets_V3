<?php
    error_reporting(E_ALL);
    ini_set('display_errors', 0); // Désactivé pour ne pas casser le JSON
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
    $page_title = "إدارة المستخدمين - رئاسة الحكومة";

    // Traitement de l'ajout d'utilisateur
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_user') {
        // Nettoyer le buffer et définir les headers en premier
        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_start();
        
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            // Vérifier que l'utilisateur est admin
            if (!Permissions::canCreateProjet()) {
                throw new Exception('ليس لديك صلاحية لإضافة مستخدمين');
            }
            
            // Validation CSRF
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                throw new Exception('خطأ في رمز الأمان');
            }
            
            // Récupérer les données
            $nomUser = isset($_POST['nomUser']) ? trim($_POST['nomUser']) : '';
            $emailUser = isset($_POST['emailUser']) ? trim($_POST['emailUser']) : '';
            $login = isset($_POST['login']) ? trim($_POST['login']) : '';
            $password = isset($_POST['password']) ? $_POST['password'] : '';
            $typeCpt = isset($_POST['typeCpt']) ? intval($_POST['typeCpt']) : 2;
            
            // Validation des champs obligatoires
            if (empty($nomUser)) {
                throw new Exception('اسم المستخدم مطلوب');
            }
            if (empty($emailUser)) {
                throw new Exception('البريد الإلكتروني مطلوب');
            }
            if (!filter_var($emailUser, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('البريد الإلكتروني غير صالح');
            }
            if (empty($login)) {
                throw new Exception('اسم الدخول مطلوب');
            }
            if (strlen($login) > 20) {
                throw new Exception('اسم الدخول يجب أن يكون أقل من 20 حرفًا');
            }
            if (empty($password)) {
                throw new Exception('كلمة المرور مطلوبة');
            }
            if (strlen($password) < 6) {
                throw new Exception('كلمة المرور يجب أن تكون 6 أحرف على الأقل');
            }
            
            // Vérifier si le login existe déjà
            $checkQuery = "SELECT COUNT(*) as count FROM user WHERE login = :login";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->bindParam(':login', $login);
            $checkStmt->execute();
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                throw new Exception('اسم الدخول موجود مسبقا');
            }
            
            // Vérifier si l'email existe déjà
            $checkEmailQuery = "SELECT COUNT(*) as count FROM user WHERE emailUser = :email";
            $checkEmailStmt = $db->prepare($checkEmailQuery);
            $checkEmailStmt->bindParam(':email', $emailUser);
            $checkEmailStmt->execute();
            $resultEmail = $checkEmailStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($resultEmail['count'] > 0) {
                throw new Exception('البريد الإلكتروني موجود مسبقا');
            }
            
            // Hasher le mot de passe avec Security::hashPassword()
            $hashedPassword = Security::hashPassword($password);
            
            // Insérer l'utilisateur
            $queryUser = "INSERT INTO user (nomUser, emailUser, typeCpt, login, pw) 
                         VALUES (:nomUser, :emailUser, :typeCpt, :login, :pw)";
            $stmtUser = $db->prepare($queryUser);
            $stmtUser->bindParam(':nomUser', $nomUser);
            $stmtUser->bindParam(':emailUser', $emailUser);
            $stmtUser->bindParam(':typeCpt', $typeCpt);
            $stmtUser->bindParam(':login', $login);
            $stmtUser->bindParam(':pw', $hashedPassword);
            $stmtUser->execute();
            
            // Journal
            $action = "إضافة مستخدم جديد: " . $nomUser;
            $idUserLog = $_SESSION['user_id'] ?? 0;
            
            $queryJournal = "INSERT INTO journal (idUser, action, date) VALUES (:idUser, :action, NOW())";
            $stmtJournal = $db->prepare($queryJournal);
            $stmtJournal->bindParam(':idUser', $idUserLog);
            $stmtJournal->bindParam(':action', $action);
            $stmtJournal->execute();
            
            ob_end_clean();
            echo json_encode([
                'success' => true,
                'message' => 'تمت إضافة المستخدم بنجاح'
            ], JSON_UNESCAPED_UNICODE);
            
        } catch (PDOException $e) {
            ob_end_clean();
            echo json_encode([
                'success' => false,
                'message' => 'خطأ في قاعدة البيانات: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            ob_end_clean();
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            ob_end_clean();
            echo json_encode([
                'success' => false,
                'message' => 'خطأ غير متوقع: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    // Traitement de la modification d'utilisateur
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_user') {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            // Vérifier que l'utilisateur est admin
            if (!Permissions::canEditProjet($_SESSION['user_id'])) {
                throw new Exception('ليس لديك صلاحية لتعديل المستخدمين');
            }
            
            // Validation CSRF
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                throw new Exception('خطأ في رمز الأمان');
            }
            
            $idUser = isset($_POST['idUser']) ? intval($_POST['idUser']) : 0;
            $nomUser = isset($_POST['nomUser']) ? trim($_POST['nomUser']) : '';
            $emailUser = isset($_POST['emailUser']) ? trim($_POST['emailUser']) : '';
            $login = isset($_POST['login']) ? trim($_POST['login']) : '';
            $password = isset($_POST['password']) ? $_POST['password'] : '';
            $typeCpt = isset($_POST['typeCpt']) ? intval($_POST['typeCpt']) : 2;
            
            // Validation
            if ($idUser <= 0) {
                throw new Exception('معرف المستخدم غير صالح');
            }
            if (empty($nomUser)) {
                throw new Exception('اسم المستخدم مطلوب');
            }
            if (empty($emailUser)) {
                throw new Exception('البريد الإلكتروني مطلوب');
            }
            if (!filter_var($emailUser, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('البريد الإلكتروني غير صالح');
            }
            if (empty($login)) {
                throw new Exception('اسم الدخول مطلوب');
            }
            
            // Vérifier que l'utilisateur existe
            $checkQuery = "SELECT login, emailUser FROM user WHERE idUser = :idUser";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->bindParam(':idUser', $idUser);
            $checkStmt->execute();
            $existingUser = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$existingUser) {
                throw new Exception('المستخدم غير موجود');
            }
            
            // Vérifier si le login existe pour un autre utilisateur
            if ($login != $existingUser['login']) {
                $checkLoginQuery = "SELECT COUNT(*) as count FROM user WHERE login = :login AND idUser != :idUser";
                $checkLoginStmt = $db->prepare($checkLoginQuery);
                $checkLoginStmt->bindParam(':login', $login);
                $checkLoginStmt->bindParam(':idUser', $idUser);
                $checkLoginStmt->execute();
                $result = $checkLoginStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result['count'] > 0) {
                    throw new Exception('اسم الدخول موجود مسبقا');
                }
            }
            
            // Vérifier si l'email existe pour un autre utilisateur
            if ($emailUser != $existingUser['emailUser']) {
                $checkEmailQuery = "SELECT COUNT(*) as count FROM user WHERE emailUser = :email AND idUser != :idUser";
                $checkEmailStmt = $db->prepare($checkEmailQuery);
                $checkEmailStmt->bindParam(':email', $emailUser);
                $checkEmailStmt->bindParam(':idUser', $idUser);
                $checkEmailStmt->execute();
                $resultEmail = $checkEmailStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($resultEmail['count'] > 0) {
                    throw new Exception('البريد الإلكتروني موجود مسبقا');
                }
            }
            
            // Mettre à jour l'utilisateur
            if (!empty($password)) {
                // Avec nouveau mot de passe - utiliser Security::hashPassword()
                $hashedPassword = Security::hashPassword($password);
                $queryUpdate = "UPDATE user SET nomUser = :nomUser, emailUser = :emailUser, 
                               typeCpt = :typeCpt, login = :login, pw = :pw WHERE idUser = :idUser";
                $stmtUpdate = $db->prepare($queryUpdate);
                $stmtUpdate->bindParam(':pw', $hashedPassword);
            } else {
                // Sans changer le mot de passe
                $queryUpdate = "UPDATE user SET nomUser = :nomUser, emailUser = :emailUser, 
                               typeCpt = :typeCpt, login = :login WHERE idUser = :idUser";
                $stmtUpdate = $db->prepare($queryUpdate);
            }
            
            $stmtUpdate->bindParam(':nomUser', $nomUser);
            $stmtUpdate->bindParam(':emailUser', $emailUser);
            $stmtUpdate->bindParam(':typeCpt', $typeCpt);
            $stmtUpdate->bindParam(':login', $login);
            $stmtUpdate->bindParam(':idUser', $idUser);
            $stmtUpdate->execute();
            
            // Journal
            $action = "تعديل بيانات المستخدم: " . $nomUser;
            $idUserLog = $_SESSION['user_id'] ?? 0;
            
            $queryJournal = "INSERT INTO journal (idUser, action, date) VALUES (:idUser, :action, NOW())";
            $stmtJournal = $db->prepare($queryJournal);
            $stmtJournal->bindParam(':idUser', $idUserLog);
            $stmtJournal->bindParam(':action', $action);
            $stmtJournal->execute();
            
            echo json_encode([
                'success' => true,
                'message' => 'تم تعديل المستخدم بنجاح'
            ], JSON_UNESCAPED_UNICODE);
            
        } catch (PDOException $e) {
            echo json_encode([
                'success' => false,
                'message' => 'خطأ في قاعدة البيانات: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    // Traitement de la suppression d'utilisateur
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_user') {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            // Vérifier que l'utilisateur est admin
            if (!Permissions::canDeleteProjet($_SESSION['user_id'])) {
                throw new Exception('ليس لديك صلاحية لحذف المستخدمين');
            }
            
            // Validation CSRF
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                throw new Exception('خطأ في رمز الأمان');
            }
            
            $idUser = isset($_POST['idUser']) ? intval($_POST['idUser']) : 0;
            
            if ($idUser <= 0) {
                throw new Exception('معرف المستخدم غير صالح');
            }
            
            // Empêcher la suppression de son propre compte
            if ($idUser == $_SESSION['user_id']) {
                throw new Exception('لا يمكنك حذف حسابك الخاص');
            }
            
            // Récupérer le nom de l'utilisateur avant la suppression
            $getUserQuery = "SELECT nomUser FROM user WHERE idUser = :idUser";
            $getUserStmt = $db->prepare($getUserQuery);
            $getUserStmt->bindParam(':idUser', $idUser);
            $getUserStmt->execute();
            $userData = $getUserStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$userData) {
                throw new Exception('المستخدم غير موجود');
            }
            
            $nomUser = $userData['nomUser'];
            
            // Supprimer l'utilisateur
            $queryDelete = "DELETE FROM user WHERE idUser = :idUser";
            $stmtDelete = $db->prepare($queryDelete);
            $stmtDelete->bindParam(':idUser', $idUser);
            $stmtDelete->execute();
            
            // Journal
            $action = "حذف المستخدم: " . $nomUser;
            $idUserLog = $_SESSION['user_id'] ?? 0;
            
            $queryJournal = "INSERT INTO journal (idUser, action, date) VALUES (:idUser, :action, NOW())";
            $stmtJournal = $db->prepare($queryJournal);
            $stmtJournal->bindParam(':idUser', $idUserLog);
            $stmtJournal->bindParam(':action', $action);
            $stmtJournal->execute();
            
            echo json_encode([
                'success' => true,
                'message' => 'تم حذف المستخدم بنجاح'
            ], JSON_UNESCAPED_UNICODE);
            
        } catch (PDOException $e) {
            echo json_encode([
                'success' => false,
                'message' => 'خطأ في قاعدة البيانات: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    // Récupération de l'utilisateur pour l'édition
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_user') {
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            $idUser = isset($_GET['idUser']) ? intval($_GET['idUser']) : 0;
            
            if ($idUser <= 0) {
                throw new Exception('معرف المستخدم غير صالح');
            }
            
            $query = "SELECT idUser, nomUser, emailUser, typeCpt, login FROM user WHERE idUser = :idUser";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':idUser', $idUser);
            $stmt->execute();
            
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception('المستخدم غير موجود');
            }
            
            echo json_encode([
                'success' => true,
                'user' => $user
            ], JSON_UNESCAPED_UNICODE);
            
        } catch (PDOException $e) {
            echo json_encode([
                'success' => false,
                'message' => 'خطأ في قاعدة البيانات: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    // Pagination et filtres
    $itemsPerPage = 10;
    $currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $offset = ($currentPage - 1) * $itemsPerPage;

    $filterSearch = isset($_GET['search']) ? trim($_GET['search']) : '';
    $filterType = isset($_GET['type']) ? intval($_GET['type']) : '';

    // Compter le total d'utilisateurs
    $sqlCount = "SELECT COUNT(*) as total FROM user WHERE 1=1";
    
    if (!empty($filterSearch)) {
        $sqlCount .= " AND (nomUser LIKE :search OR emailUser LIKE :search OR login LIKE :search)";
    }
    if ($filterType !== '') {
        $sqlCount .= " AND typeCpt = :type";
    }

    $stmtCount = $db->prepare($sqlCount);
    if (!empty($filterSearch)) {
        $searchParam = "%{$filterSearch}%";
        $stmtCount->bindParam(':search', $searchParam);
    }
    if ($filterType !== '') {
        $stmtCount->bindParam(':type', $filterType, PDO::PARAM_INT);
    }
    $stmtCount->execute();
    $totalItems = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalItems / $itemsPerPage);

    // Récupérer les utilisateurs
    $sqlUsers = "SELECT idUser, nomUser, emailUser, typeCpt, login FROM user WHERE 1=1";
    
    if (!empty($filterSearch)) {
        $sqlUsers .= " AND (nomUser LIKE :search OR emailUser LIKE :search OR login LIKE :search)";
    }
    if ($filterType !== '') {
        $sqlUsers .= " AND typeCpt = :type";
    }

    $sqlUsers .= " ORDER BY nomUser ASC LIMIT :limit OFFSET :offset";

    $stmtUsers = $db->prepare($sqlUsers);
    if (!empty($filterSearch)) {
        $searchParam = "%{$filterSearch}%";
        $stmtUsers->bindParam(':search', $searchParam);
    }
    if ($filterType !== '') {
        $stmtUsers->bindParam(':type', $filterType, PDO::PARAM_INT);
    }
    $stmtUsers->bindParam(':limit', $itemsPerPage, PDO::PARAM_INT);
    $stmtUsers->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmtUsers->execute();
    $users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

    // Générer le token CSRF
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    
    // Vérifier que l'utilisateur a les permissions pour cette page
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
        
        .filters-section {
            background: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            float: right;
            width: 100%;
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
            direction: rtl;
            text-align: right;
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
            justify-content: flex-start;
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
        
        /* Styles du tableau */
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
        
        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-admin { 
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }
        
        .badge-user { 
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
        }
        
        /* Boutons d'action */
        .btn-action {
            padding: 8px 16px !important;
            border-radius: 6px;
            font-size: 13px !important;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            min-width: 70px !important;
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
        
        /* Boutons d'export */
        .btn-export {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
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
        
        .form-group {
            margin-bottom: 20px;
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
        
        .modal-footer {
            padding: 20px 30px;
            border-top: 1px solid #e0e0e0;
            display: flex;
            gap: 15px;
            justify-content: center;
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
        
        /* Pagination */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 30px;
            padding: 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
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
        
        /* Delete Confirmation Modal */
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
            border-left: 3px solid #667eea;
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
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <section class="content-section" style="padding: 40px 0;">
        <div class="container">
            <div class="admin-header">
                <h2>👥 إدارة المستخدمين</h2>
                <p>إدارة حسابات المستخدمين والصلاحيات</p>
            </div>
            
            <div class="filters-section">
                <form method="GET">
                    <div class="filters-grid">
                        <!-- Recherche -->
                        <div class="filter-group">
                            <label>البحث</label>
                            <input type="text" name="search" placeholder="ابحث عن اسم، بريد أو اسم دخول..." value="<?php echo htmlspecialchars($filterSearch); ?>">
                        </div>
                        
                        <!-- النوع -->
                        <div class="filter-group">
                            <label>نوع الحساب</label>
                            <select name="type">
                                <option value="">جميع الأنواع</option>
                                <option value="1" <?php echo $filterType === 1 ? 'selected' : ''; ?>>مدير</option>
                                <option value="2" <?php echo $filterType === 2 ? 'selected' : ''; ?>>مستخدم</option>
                            </select>
                        </div>
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">🔍 بحث</button>
                        <a href="users.php" class="btn btn-secondary">🔄 إعادة تعيين</a>
                        <button type="button" class="btn btn-success" id="btnOpenModal">➕مستخدم</button>
                    </div>
                </form>
            </div>

            <div class="projects-table">
                <?php if (count($users) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>الاسم</th>
                                <th>البريد الإلكتروني</th>
                                <th>اسم الدخول</th>
                                <th>نوع الحساب</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td style="font-weight: 600;"><?php echo htmlspecialchars($user['nomUser']); ?></td>
                                    <td><?php echo htmlspecialchars($user['emailUser']); ?></td>
                                    <td style="font-family: 'Courier New', monospace; color: #667eea;"><?php echo htmlspecialchars($user['login']); ?></td>
                                    <td>
                                        <span class="badge <?php echo $user['typeCpt'] == 1 ? 'badge-admin' : 'badge-user'; ?>">
                                            <?php echo $user['typeCpt'] == 1 ? 'مدير' : 'مستخدم'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 3px; justify-content: center; flex-wrap: nowrap;">
                                            <button onclick="openEditModal(<?php echo $user['idUser']; ?>)" 
                                                    class="btn-action btn-edit"
                                                    title="تعديل المستخدم">
                                                تعديل
                                            </button>
                                            <?php if ($user['idUser'] != $_SESSION['user_id']): ?>
                                                <button onclick="confirmDelete(<?php echo $user['idUser']; ?>)" 
                                                        class="btn-action btn-delete"
                                                        title="حذف المستخدم">
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
                    <div style="text-align: center; padding: 60px 20px;">
                        <div style="font-size: 48px; color: #ddd; margin-bottom: 20px;">👥</div>
                        <h3 style="color: #999; margin-bottom: 10px;">لا يوجد مستخدمون</h3>
                        <p style="color: #bbb;">لم يتم إنشاء أي مستخدم بعد</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if ($totalPages > 1): ?>
                <div class="pagination-container">
                    <div class="pagination-info">
                        عرض <?php echo (($currentPage - 1) * $itemsPerPage) + 1; ?> - 
                        <?php echo min($currentPage * $itemsPerPage, $totalItems); ?> 
                        من أصل <?php echo $totalItems; ?> مستخدم
                    </div>
                    
                    <ul class="pagination">
                        <?php if ($currentPage > 1): ?>
                            <li><a href="?page=<?php echo $currentPage - 1; ?><?php echo !empty($filterSearch) ? '&search=' . urlencode($filterSearch) : ''; ?><?php echo $filterType !== '' ? '&type=' . $filterType : ''; ?>">السابق</a></li>
                        <?php else: ?>
                            <li class="disabled"><span>السابق</span></li>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <?php if ($i == $currentPage): ?>
                                <li class="active"><span><?php echo $i; ?></span></li>
                            <?php else: ?>
                                <li><a href="?page=<?php echo $i; ?><?php echo !empty($filterSearch) ? '&search=' . urlencode($filterSearch) : ''; ?><?php echo $filterType !== '' ? '&type=' . $filterType : ''; ?>"><?php echo $i; ?></a></li>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($currentPage < $totalPages): ?>
                            <li><a href="?page=<?php echo $currentPage + 1; ?><?php echo !empty($filterSearch) ? '&search=' . urlencode($filterSearch) : ''; ?><?php echo $filterType !== '' ? '&type=' . $filterType : ''; ?>">التالي</a></li>
                        <?php else: ?>
                            <li class="disabled"><span>التالي</span></li>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Modal Ajout -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>➕ إضافة مستخدم جديد</h2>
                <span class="close" id="btnCloseAdd">&times;</span>
            </div>
            <div class="modal-body">
                <div id="modalAlert"></div>
                <form id="addUserForm">
                    <input type="hidden" name="action" value="add_user">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div class="form-group">
                        <label>الاسم <span class="required">*</span></label>
                        <input type="text" name="nomUser" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label>البريد الإلكتروني <span class="required">*</span></label>
                        <input type="email" name="emailUser" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label>اسم الدخول <span class="required">*</span></label>
                        <input type="text" name="login" class="form-control" maxlength="20" required>
                    </div>
                    
                    <div class="form-group">
                        <label>كلمة المرور <span class="required">*</span></label>
                        <input type="password" name="password" class="form-control" minlength="6" required>
                    </div>
                    
                    <div class="form-group">
                        <label>نوع الحساب <span class="required">*</span></label>
                        <select name="typeCpt" class="form-control" required>
                            <option value="2">مستخدم</option>
                            <option value="1">مدير</option>
                        </select>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-success">💾 حفظ</button>
                        <button type="button" class="btn btn-secondary" id="btnCancelAdd">إلغاء</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Modification -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>✏️ تعديل مستخدم</h2>
                <span class="close" id="btnCloseEdit">&times;</span>
            </div>
            <div class="modal-body">
                <div id="editModalAlert"></div>
                <form id="editUserForm">
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="idUser" id="editIdUser">
                    
                    <div class="form-group">
                        <label>الاسم <span class="required">*</span></label>
                        <input type="text" name="nomUser" id="editNomUser" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label>البريد الإلكتروني <span class="required">*</span></label>
                        <input type="email" name="emailUser" id="editEmailUser" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label>اسم الدخول <span class="required">*</span></label>
                        <input type="text" name="login" id="editLogin" class="form-control" maxlength="20" required>
                    </div>
                    
                    <div class="form-group">
                        <label>كلمة المرور الجديدة <small>(اتركها فارغة للاحتفاظ بالقديمة)</small></label>
                        <input type="password" name="password" id="editPassword" class="form-control" minlength="6">
                    </div>
                    
                    <div class="form-group">
                        <label>نوع الحساب <span class="required">*</span></label>
                        <select name="typeCpt" id="editTypeCpt" class="form-control" required>
                            <option value="2">مستخدم</option>
                            <option value="1">مدير</option>
                        </select>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-success">💾 حفظ</button>
                        <button type="button" class="btn btn-secondary" id="btnCancelEdit">إلغاء</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de Confirmation de Suppression -->
    <div id="deleteModal" class="delete-modal">
        <div class="delete-modal-content">
            <div class="delete-modal-header">
                <div class="delete-icon">🗑️</div>
                <h3>تأكيد حذف المستخدم</h3>
            </div>
            <div class="delete-modal-body">
                <p style="font-size: 14px; font-weight: 500; margin-bottom: 15px; color: #718096;">
                    هل أنت متأكد من حذف هذا المستخدم؟
                </p>
                <div class="delete-user-info" id="deleteUserInfo">
                    <!-- User info will be inserted here -->
                </div>
                <div class="delete-warning">
                    ⚠️ لا يمكن التراجع عن هذا الإجراء
                </div>
            </div>
            <div class="delete-modal-footer">
                <button class="btn-cancel-delete" onclick="closeDeleteModal()">
                    إلغاء
                </button>
                <button class="btn-confirm-delete" id="confirmDeleteBtn">
                    حذف
                </button>
            </div>
        </div>
    </div>

    <script>
        // Variables globales
        const addModal = document.getElementById('addModal');
        const editModal = document.getElementById('editModal');
        const deleteModal = document.getElementById('deleteModal');
        const btnOpenModal = document.getElementById('btnOpenModal');
        const btnCloseAdd = document.getElementById('btnCloseAdd');
        const btnCancelAdd = document.getElementById('btnCancelAdd');
        const btnCloseEdit = document.getElementById('btnCloseEdit');
        const btnCancelEdit = document.getElementById('btnCancelEdit');
        
        let userToDelete = null;

        // Ouvrir le modal d'ajout
        if (btnOpenModal) {
            btnOpenModal.onclick = function() {
                addModal.classList.add('show');
                document.body.style.overflow = 'hidden';
                document.getElementById('addUserForm').reset();
                document.getElementById('modalAlert').innerHTML = '';
            }
        }

        // Fermer le modal d'ajout
        function fermerAddModal() {
            addModal.classList.remove('show');
            document.body.style.overflow = 'auto';
            document.getElementById('addUserForm').reset();
            document.getElementById('modalAlert').innerHTML = '';
        }

        if (btnCloseAdd) {
            btnCloseAdd.onclick = fermerAddModal;
        }
        if (btnCancelAdd) {
            btnCancelAdd.onclick = fermerAddModal;
        }

        // Soumettre le formulaire d'ajout
        document.getElementById('addUserForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            
            var formData = new FormData(this);
            var alertDiv = document.getElementById('modalAlert');
            
            // Afficher le loader
            alertDiv.innerHTML = '<div style="text-align: center; padding: 15px;"><div style="display: inline-block; border: 3px solid #f3f3f3; border-top: 3px solid #667eea; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite;"></div><p style="margin-top: 10px;">جاري الحفظ...</p></div>';
            
            fetch('users.php', {
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

        // Ouvrir le modal de modification
        function openEditModal(idUser) {
            fetch('users.php?action=get_user&idUser=' + idUser)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('editIdUser').value = data.user.idUser;
                        document.getElementById('editNomUser').value = data.user.nomUser;
                        document.getElementById('editEmailUser').value = data.user.emailUser;
                        document.getElementById('editLogin').value = data.user.login;
                        document.getElementById('editTypeCpt').value = data.user.typeCpt;
                        document.getElementById('editPassword').value = '';
                        
                        editModal.classList.add('show');
                        document.body.style.overflow = 'hidden';
                        document.getElementById('editModalAlert').innerHTML = '';
                    } else {
                        alert('خطأ في تحميل بيانات المستخدم: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('حدث خطأ في تحميل البيانات');
                });
        }

        // Fermer le modal de modification
        function fermerEditModal() {
            editModal.classList.remove('show');
            document.body.style.overflow = 'auto';
            document.getElementById('editUserForm').reset();
            document.getElementById('editModalAlert').innerHTML = '';
        }

        if (btnCloseEdit) {
            btnCloseEdit.onclick = fermerEditModal;
        }
        if (btnCancelEdit) {
            btnCancelEdit.onclick = fermerEditModal;
        }

        // Soumettre le formulaire de modification
        document.getElementById('editUserForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            
            var formData = new FormData(this);
            var alertDiv = document.getElementById('editModalAlert');
            
            // Afficher le loader
            alertDiv.innerHTML = '<div style="text-align: center; padding: 15px;"><div style="display: inline-block; border: 3px solid #f3f3f3; border-top: 3px solid #667eea; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite;"></div><p style="margin-top: 10px;">جاري الحفظ...</p></div>';
            
            fetch('users.php', {
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

        // Confirmer la suppression - Nouvelle version avec modal personnalisé
        function confirmDelete(idUser) {
            // Récupérer les informations de l'utilisateur depuis le tableau
            const row = event.target.closest('tr');
            const userName = row.cells[0].textContent.trim();
            const userEmail = row.cells[1].textContent.trim();
            const userLogin = row.cells[2].textContent.trim();
            
            // Stocker l'ID de l'utilisateur à supprimer
            userToDelete = idUser;
            
            // Afficher les informations de l'utilisateur dans le modal
            document.getElementById('deleteUserInfo').innerHTML = `
                <div style="text-align: right; line-height: 1.8;">
                    <div><strong>الاسم:</strong> ${userName}</div>
                    <div><strong>البريد الإلكتروني:</strong> ${userEmail}</div>
                    <div><strong>اسم الدخول:</strong> ${userLogin}</div>
                </div>
            `;
            
            // Afficher le modal
            deleteModal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        // Fermer le modal de suppression
        function closeDeleteModal() {
            deleteModal.classList.remove('show');
            document.body.style.overflow = 'auto';
            userToDelete = null;
        }
        
        // Confirmer la suppression définitive
        document.getElementById('confirmDeleteBtn')?.addEventListener('click', function() {
            if (userToDelete) {
                // Désactiver le bouton et afficher un loader
                const btn = this;
                btn.disabled = true;
                btn.innerHTML = '<div style="display: inline-block; border: 2px solid rgba(255,255,255,0.3); border-top: 2px solid white; border-radius: 50%; width: 14px; height: 14px; animation: spin 1s linear infinite; margin-left: 8px;"></div> جاري الحذف...';
                
                var formData = new FormData();
                formData.append('action', 'delete_user');
                formData.append('idUser', userToDelete);
                formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
                
                fetch('users.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Succès - Afficher une animation de succès
                        document.querySelector('.delete-modal-content').innerHTML = `
                            <div style="padding: 40px 30px; text-align: center;">
                                <div style="width: 70px; height: 70px; margin: 0 auto 15px; background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 36px; color: white; animation: scaleIn 0.5s;">
                                    ✓
                                </div>
                                <h3 style="color: #2d3748; margin-bottom: 8px; font-size: 18px; font-weight: 600;">تم الحذف بنجاح</h3>
                                <p style="color: #718096; font-size: 14px;">${data.message}</p>
                            </div>
                        `;
                        
                        // Recharger automatiquement après 1.5 secondes
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else {
                        // Erreur
                        btn.disabled = false;
                        btn.innerHTML = 'حذف';
                        alert('✕ ' + data.message);
                        closeDeleteModal();
                    }
                })
                .catch(function(error) {
                    console.error('Error:', error);
                    btn.disabled = false;
                    btn.innerHTML = 'حذف';
                    alert('✕ حدث خطأ في الاتصال');
                    closeDeleteModal();
                });
            }
        });

        // Fermer en cliquant à l'extérieur
        window.addEventListener('click', function(event) {
            if (event.target == addModal) {
                fermerAddModal();
            }
            if (event.target == editModal) {
                fermerEditModal();
            }
            if (event.target == deleteModal) {
                closeDeleteModal();
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
            form.action = 'export_users.php';
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
            
            const typeSelect = document.querySelector('select[name="type"]');
            if (typeSelect && typeSelect.value) {
                const type = document.createElement('input');
                type.type = 'hidden';
                type.name = 'type';
                type.value = typeSelect.value;
                form.appendChild(type);
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