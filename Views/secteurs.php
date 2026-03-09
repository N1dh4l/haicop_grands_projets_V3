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
    $page_title = "إدارة القطاعات - رئاسة الحكومة";

    // Traitement de l'ajout
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_secteur') {
        while (ob_get_level()) {
            ob_end_clean();
        }
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
            
            // Vérifier si le numéro existe déjà
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
            echo json_encode([
                'success' => true,
                'message' => 'تمت إضافة القطاع بنجاح'
            ], JSON_UNESCAPED_UNICODE);
            
        } catch (Exception $e) {
            ob_end_clean();
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    // Traitement de la modification
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
            
            if ($idSecteur <= 0) {
                throw new Exception('معرف القطاع غير صالح');
            }
            if ($numSecteur <= 0) {
                throw new Exception('رقم القطاع مطلوب');
            }
            
            $checkQuery = "SELECT COUNT(*) as count FROM secteur WHERE numSecteur = :numSecteur AND idSecteur != :idSecteur";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->bindParam(':numSecteur', $numSecteur);
            $checkStmt->bindParam(':idSecteur', $idSecteur);
            $checkStmt->execute();
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                throw new Exception('رقم القطاع موجود مسبقا');
            }
            
            $query = "UPDATE secteur SET numSecteur = :numSecteur WHERE idSecteur = :idSecteur";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':numSecteur', $numSecteur);
            $stmt->bindParam(':idSecteur', $idSecteur);
            $stmt->execute();
            
            $action = "تعديل القطاع رقم: " . $numSecteur;
            $idUserLog = $_SESSION['user_id'] ?? 0;
            
            $queryJournal = "INSERT INTO journal (idUser, action, date) VALUES (:idUser, :action, NOW())";
            $stmtJournal = $db->prepare($queryJournal);
            $stmtJournal->bindParam(':idUser', $idUserLog);
            $stmtJournal->bindParam(':action', $action);
            $stmtJournal->execute();
            
            ob_end_clean();
            echo json_encode([
                'success' => true,
                'message' => 'تم تعديل القطاع بنجاح'
            ], JSON_UNESCAPED_UNICODE);
            
        } catch (Exception $e) {
            ob_end_clean();
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    // Traitement de la suppression
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
            
            $action = "حذف قطاع";
            $idUserLog = $_SESSION['user_id'] ?? 0;
            
            $queryJournal = "INSERT INTO journal (idUser, action, date) VALUES (:idUser, :action, NOW())";
            $stmtJournal = $db->prepare($queryJournal);
            $stmtJournal->bindParam(':idUser', $idUserLog);
            $stmtJournal->bindParam(':action', $action);
            $stmtJournal->execute();
            
            ob_end_clean();
            echo json_encode([
                'success' => true,
                'message' => 'تم حذف القطاع بنجاح'
            ], JSON_UNESCAPED_UNICODE);
            
        } catch (Exception $e) {
            ob_end_clean();
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    // Récupération d'un secteur pour modification
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
                echo json_encode([
                    'success' => true,
                    'secteur' => $secteur
                ], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'القطاع غير موجود'
                ], JSON_UNESCAPED_UNICODE);
            }
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    // Pagination
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $itemsPerPage = 10;
    $offset = ($page - 1) * $itemsPerPage;

    $filterSearch = isset($_GET['search']) ? trim($_GET['search']) : '';

    $sqlCount = "SELECT COUNT(*) as total FROM secteur WHERE 1=1";
    
    if (!empty($filterSearch)) {
        $sqlCount .= " AND numSecteur LIKE :search";
    }

    $stmtCount = $db->prepare($sqlCount);
    if (!empty($filterSearch)) {
        $searchParam = "%{$filterSearch}%";
        $stmtCount->bindParam(':search', $searchParam);
    }
    $stmtCount->execute();
    $totalItems = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalItems / $itemsPerPage);

    $sqlSecteurs = "SELECT * FROM secteur WHERE 1=1";
    
    if (!empty($filterSearch)) {
        $sqlSecteurs .= " AND numSecteur LIKE :search";
    }

    $sqlSecteurs .= " ORDER BY numSecteur ASC LIMIT :limit OFFSET :offset";

    $stmtSecteurs = $db->prepare($sqlSecteurs);
    if (!empty($filterSearch)) {
        $searchParam = "%{$filterSearch}%";
        $stmtSecteurs->bindParam(':search', $searchParam);
    }
    $stmtSecteurs->bindParam(':limit', $itemsPerPage, PDO::PARAM_INT);
    $stmtSecteurs->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmtSecteurs->execute();
    $secteurs = $stmtSecteurs->fetchAll(PDO::FETCH_ASSOC);

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
    <link rel="stylesheet" href="../css/style.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            direction: rtl;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .admin-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            border-color: #667eea;
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
        
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 30px;
            padding: 20px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }

        .pagination-info {
            font-size: 14px;
            color: #666;
            font-weight: 600;
        }

        .pagination {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .pagination a, .pagination span {
            display: inline-block;
            padding: 10px 16px;
            border-radius: 8px;
            text-decoration: none;
            color: #667eea;
            background: #f8f9fa;
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
                <h2>🏢 إدارة القطاعات</h2>
                <p>إدارة القطاعات والمناطق</p>
            </div>
            
            <div class="filters-section">
                <form method="GET">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label>البحث</label>
                            <input type="text" name="search" placeholder="ابحث عن رقم القطاع..." value="<?php echo htmlspecialchars($filterSearch); ?>">
                        </div>
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">🔍 بحث</button>
                        <a href="secteurs.php" class="btn btn-secondary">🔄 إعادة تعيين</a>
                        <button type="button" class="btn btn-success" id="btnOpenModal">➕ قطاع جديد</button>
                    </div>
                </form>
            </div>

            <div class="projects-table">
                <?php if (count($secteurs) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>رقم القطاع</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($secteurs as $secteur): ?>
                                <tr>
                                    <td style="font-weight: 600;">القطاع رقم <?php echo htmlspecialchars($secteur['numSecteur']); ?></td>
                                    <td>
                                        <div style="display: flex; gap: 3px; justify-content: center; flex-wrap: nowrap;">
                                            <button onclick="openEditModal(<?php echo $secteur['idSecteur']; ?>)" 
                                                    class="btn-action btn-edit"
                                                    title="تعديل القطاع">
                                                تعديل
                                            </button>
                                            <button onclick="confirmDelete(<?php echo $secteur['idSecteur']; ?>)" 
                                                    class="btn-action btn-delete"
                                                    title="حذف القطاع">
                                                حذف
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div style="text-align: center; padding: 60px 20px;">
                        <div style="font-size: 48px; color: #ddd; margin-bottom: 20px;">🏢</div>
                        <h3 style="color: #999; margin-bottom: 10px;">لا توجد قطاعات</h3>
                        <p style="color: #bbb;">لم يتم إنشاء أي قطاع بعد</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if ($totalPages > 1): ?>
                <div class="pagination-container">
                    <div class="pagination-info">
                        عرض <?php echo $offset + 1; ?> إلى <?php echo min($offset + $itemsPerPage, $totalItems); ?> من أصل <?php echo $totalItems; ?> قطاع
                    </div>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?><?php echo !empty($filterSearch) ? '&search=' . urlencode($filterSearch) : ''; ?>">السابق</a>
                        <?php else: ?>
                            <span class="disabled">السابق</span>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <?php if ($i == $page): ?>
                                <span class="active"><span><?php echo $i; ?></span></span>
                            <?php else: ?>
                                <a href="?page=<?php echo $i; ?><?php echo !empty($filterSearch) ? '&search=' . urlencode($filterSearch) : ''; ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?php echo $page + 1; ?><?php echo !empty($filterSearch) ? '&search=' . urlencode($filterSearch) : ''; ?>">التالي</a>
                        <?php else: ?>
                            <span class="disabled">التالي</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Modal Ajout -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>➕ إضافة قطاع جديد</h2>
                <span class="close" id="btnCloseAdd">&times;</span>
            </div>
            <div class="modal-body">
                <div id="modalAlert"></div>
                <form id="addForm">
                    <input type="hidden" name="action" value="add_secteur">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div class="form-group">
                        <label>رقم القطاع <span class="required">*</span></label>
                        <input type="number" name="numSecteur" class="form-control" required min="1">
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
                <h2>✏️ تعديل قطاع</h2>
                <span class="close" id="btnCloseEdit">&times;</span>
            </div>
            <div class="modal-body">
                <div id="editModalAlert"></div>
                <form id="editForm">
                    <input type="hidden" name="action" value="edit_secteur">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="idSecteur" id="editIdSecteur">
                    
                    <div class="form-group">
                        <label>رقم القطاع <span class="required">*</span></label>
                        <input type="number" name="numSecteur" id="editNumSecteur" class="form-control" required min="1">
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
                <h3>تأكيد حذف القطاع</h3>
            </div>
            <div class="delete-modal-body">
                <p style="font-size: 14px; font-weight: 500; margin-bottom: 15px; color: #718096;">
                    هل أنت متأكد من حذف هذا القطاع؟
                </p>
                <div class="delete-user-info" id="deleteInfo">
                    <!-- Info will be inserted here -->
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
        const addModal = document.getElementById('addModal');
        const editModal = document.getElementById('editModal');
        const deleteModal = document.getElementById('deleteModal');
        const btnOpenModal = document.getElementById('btnOpenModal');
        const btnCloseAdd = document.getElementById('btnCloseAdd');
        const btnCancelAdd = document.getElementById('btnCancelAdd');
        const btnCloseEdit = document.getElementById('btnCloseEdit');
        const btnCancelEdit = document.getElementById('btnCancelEdit');
        
        let itemToDelete = null;

        if (btnOpenModal) {
            btnOpenModal.onclick = function() {
                addModal.classList.add('show');
                document.body.style.overflow = 'hidden';
                document.getElementById('addForm').reset();
                document.getElementById('modalAlert').innerHTML = '';
            }
        }

        function fermerAddModal() {
            addModal.classList.remove('show');
            document.body.style.overflow = 'auto';
            document.getElementById('addForm').reset();
            document.getElementById('modalAlert').innerHTML = '';
        }

        if (btnCloseAdd) {
            btnCloseAdd.onclick = fermerAddModal;
        }
        if (btnCancelAdd) {
            btnCancelAdd.onclick = fermerAddModal;
        }

        document.getElementById('addForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            
            var formData = new FormData(this);
            var alertDiv = document.getElementById('modalAlert');
            
            alertDiv.innerHTML = '<div style="text-align: center; padding: 15px;"><div style="display: inline-block; border: 3px solid #f3f3f3; border-top: 3px solid #667eea; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite;"></div><p style="margin-top: 10px;">جاري الحفظ...</p></div>';
            
            fetch('secteurs.php', {
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

        function openEditModal(idSecteur) {
            fetch('secteurs.php?action=get_secteur&idSecteur=' + idSecteur)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('editIdSecteur').value = data.secteur.idSecteur;
                        document.getElementById('editNumSecteur').value = data.secteur.numSecteur;
                        
                        editModal.classList.add('show');
                        document.body.style.overflow = 'hidden';
                        document.getElementById('editModalAlert').innerHTML = '';
                    } else {
                        alert('خطأ في تحميل البيانات: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('حدث خطأ في تحميل البيانات');
                });
        }

        function fermerEditModal() {
            editModal.classList.remove('show');
            document.body.style.overflow = 'auto';
            document.getElementById('editForm').reset();
            document.getElementById('editModalAlert').innerHTML = '';
        }

        if (btnCloseEdit) {
            btnCloseEdit.onclick = fermerEditModal;
        }
        if (btnCancelEdit) {
            btnCancelEdit.onclick = fermerEditModal;
        }

        document.getElementById('editForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            
            var formData = new FormData(this);
            var alertDiv = document.getElementById('editModalAlert');
            
            alertDiv.innerHTML = '<div style="text-align: center; padding: 15px;"><div style="display: inline-block; border: 3px solid #f3f3f3; border-top: 3px solid #667eea; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite;"></div><p style="margin-top: 10px;">جاري الحفظ...</p></div>';
            
            fetch('secteurs.php', {
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

        function confirmDelete(idSecteur) {
            const row = event.target.closest('tr');
            const secteurNum = row.cells[0].textContent.trim();
            
            itemToDelete = idSecteur;
            
            document.getElementById('deleteInfo').innerHTML = `
                <div style="text-align: right; line-height: 1.8;">
                    <strong>${secteurNum}</strong>
                </div>
            `;
            
            deleteModal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closeDeleteModal() {
            deleteModal.classList.remove('show');
            document.body.style.overflow = 'auto';
            itemToDelete = null;
        }
        
        document.getElementById('confirmDeleteBtn')?.addEventListener('click', function() {
            if (itemToDelete) {
                const btn = this;
                btn.disabled = true;
                btn.innerHTML = '<div style="display: inline-block; border: 2px solid rgba(255,255,255,0.3); border-top: 2px solid white; border-radius: 50%; width: 14px; height: 14px; animation: spin 1s linear infinite; margin-left: 8px;"></div> جاري الحذف...';
                
                var formData = new FormData();
                formData.append('action', 'delete_secteur');
                formData.append('idSecteur', itemToDelete);
                formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
                
                fetch('secteurs.php', {
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
                        
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else {
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
    </script>
</body>
</html>
