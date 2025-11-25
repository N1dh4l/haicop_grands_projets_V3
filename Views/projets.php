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

// Traitement de l'upload du التقرير الرقابي
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_taqrir') {
    // Nettoyer tout buffer de sortie
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        if (!Security::validateCSRFToken($_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'خطأ في التحقق من الأمان'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        
        $projetId = intval($_POST['projetId']);
        $libDoc = Security::sanitizeInput($_POST['libDoc']);
        
        if (empty($libDoc)) {
            echo json_encode(['success' => false, 'message' => 'يرجى إدخال عنوان التقرير'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        
        // Vérifier le projet
        $sqlCheck = "SELECT idUser FROM projet WHERE idPro = :projetId";
        $stmtCheck = $db->prepare($sqlCheck);
        $stmtCheck->bindParam(':projetId', $projetId, PDO::PARAM_INT);
        $stmtCheck->execute();
        $projetCheck = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        
        if (!$projetCheck) {
            echo json_encode(['success' => false, 'message' => 'المشروع غير موجود. الرجاء التحقق من رقم المشروع'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        
        if (!Permissions::canEditProjet($projetCheck['idUser'])) {
            echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        
        if (!isset($_FILES['fichier_taqrir']) || $_FILES['fichier_taqrir']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'لم يتم اختيار ملف أو حدث خطأ في الرفع'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        
        $uploadDir = dirname(__DIR__) . '/uploads/documents/';
        
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $fileName = $_FILES['fichier_taqrir']['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];
        
        if (!in_array($fileExtension, $allowedExtensions)) {
            echo json_encode(['success' => false, 'message' => 'نوع الملف غير مقبول. استخدم PDF, Word أو Excel'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        
        $newFileName = 'taqrir_' . $projetId . '_' . time() . '.' . $fileExtension;
        $filePath = $uploadDir . $newFileName;
        $filePathDB = '../uploads/documents/' . $newFileName;
        
        if (!move_uploaded_file($_FILES['fichier_taqrir']['tmp_name'], $filePath)) {
            echo json_encode(['success' => false, 'message' => 'فشل في رفع الملف'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        
        $db->beginTransaction();
        
        // Insertion du document type 11
        $sqlDoc = "INSERT INTO document (idPro, libDoc, cheminAcces, type, idExterne) 
                   VALUES (:idPro, :libDoc, :cheminAcces, 11, :idExterne)";
        $stmtDoc = $db->prepare($sqlDoc);
        $stmtDoc->bindParam(':idPro', $projetId, PDO::PARAM_INT);
        $stmtDoc->bindParam(':libDoc', $libDoc);
        $stmtDoc->bindParam(':cheminAcces', $filePathDB);
        $stmtDoc->bindParam(':idExterne', $projetId, PDO::PARAM_INT);
        $stmtDoc->execute();
        
        // Log l'action
        $logSql = "INSERT INTO journal (idUser, action, date) VALUES (:idUser, :action, CURDATE())";
        $logStmt = $db->prepare($logSql);
        $logStmt->bindParam(':idUser', $_SESSION['user_id']);
        $action = "إضافة التقرير الرقابي للمقترح رقم " . $projetId;
        $logStmt->bindParam(':action', $action);
        $logStmt->execute();
        
        $db->commit();
        
        echo json_encode(['success' => true, 'message' => 'تم إضافة التقرير الرقابي بنجاح'], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        if (isset($db)) {
            $db->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'حدث خطأ: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Vérifier la permission de création
if (!Permissions::canCreateProjet() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية لإضافة مقترحات']);
    exit();
}

// Traitement AJAX pour l'ajout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_projet') {
    header('Content-Type: application/json');
    
    if (!Security::validateCSRFToken($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'خطأ في التحقق من الأمان']);
        exit();
    }
    
    $idMinistere = Security::sanitizeInput($_POST['idMinistere']);
    $idEtab = Security::sanitizeInput($_POST['idEtab']);
    $sujet = Security::sanitizeInput($_POST['sujet']);
    $dateArrive = Security::sanitizeInput($_POST['dateArrive']);
    $procedurePro = Security::sanitizeInput($_POST['procedurePro']);
    $cout = Security::sanitizeInput($_POST['cout']);
    $proposition = Security::sanitizeInput($_POST['proposition']);
    $idRapporteur = Security::sanitizeInput($_POST['idRapporteur']);
    $libDoc = Security::sanitizeInput($_POST['libDoc']);
    
    // Si pas d'établissement sélectionné, mettre NULL
    if (empty($idEtab) || $idEtab === 'الوزارة') {
        $idEtab = null;
    }
    
    try {
        $db->beginTransaction();
        
        // Insertion du projet
        $sql = "INSERT INTO projet (idMinistere, idEtab, sujet, dateArrive, procedurePro, cout, proposition, idUser, etat, dateCreation) 
                VALUES (:idMinistere, :idEtab, :sujet, :dateArrive, :procedurePro, :cout, :proposition, :idRapporteur, 0, NOW())";
        
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':idMinistere', $idMinistere);
        $stmt->bindParam(':idEtab', $idEtab);
        $stmt->bindParam(':sujet', $sujet);
        $stmt->bindParam(':dateArrive', $dateArrive);
        $stmt->bindParam(':procedurePro', $procedurePro);
        $stmt->bindParam(':cout', $cout);
        $stmt->bindParam(':proposition', $proposition);
        $stmt->bindParam(':idRapporteur', $idRapporteur);
        
        if ($stmt->execute()) {
            $projetId = $db->lastInsertId();
            
            // Gestion du fichier
            if (isset($_FILES['fichier']) && $_FILES['fichier']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = '../uploads/documents/';
                
                // Créer le dossier s'il n'existe pas
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                $fileName = $_FILES['fichier']['name'];
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];
                
                if (in_array($fileExtension, $allowedExtensions)) {
                    $newFileName = 'doc_' . $projetId . '_' . time() . '.' . $fileExtension;
                    $filePath = $uploadDir . $newFileName;
                    
                    if (move_uploaded_file($_FILES['fichier']['tmp_name'], $filePath)) {
                        // Insertion du document dans la table avec libDoc
                        $sqlDoc = "INSERT INTO document (idPro, libDoc, cheminAcces, type, idExterne) 
                                   VALUES (:idPro, :libDoc, :cheminAcces, 1, :idExterne)";
                        $stmtDoc = $db->prepare($sqlDoc);
                        $stmtDoc->bindParam(':idPro', $projetId);
                        $stmtDoc->bindParam(':libDoc', $libDoc);
                        $stmtDoc->bindParam(':cheminAcces', $filePath);
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
        (SELECT libDoc FROM document WHERE idPro = p.idPro AND type = 1 LIMIT 1) as docMuqtarah,
        (SELECT idDoc FROM document WHERE idPro = p.idPro AND type = 1 LIMIT 1) as docMuqtarahId,
        (SELECT libDoc FROM document WHERE idPro = p.idPro AND type = 11 LIMIT 1) as docTaqrir,
        (SELECT idDoc FROM document WHERE idPro = p.idPro AND type = 11 LIMIT 1) as docTaqrirId
        FROM projet p
        LEFT JOIN ministere m ON p.idMinistere = m.idMinistere
        LEFT JOIN etablissement e ON p.idEtab = e.idEtablissement
        LEFT JOIN user u ON p.idUser = u.idUser
        WHERE 1=1";

// Filtre selon le rôle
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

$csrf_token = Security::generateCSRFToken();
$page_title = "قائمة المقترحات - نظام إدارة المشاريع";
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
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <header class="main-header">
        <div class="container">
            <div class="header-content">
                <div class="logo">
                    <h1>الجمهورية التونسية</h1>
                    <h3>رئاسة الحكومة</h3>
                    <p>لجنة المشاريع الكبري</p>
                </div>
                <nav class="main-nav">
                    <ul>
                        <li><a href="accueil.php">الرئيسية</a></li>
                        <li><a href="projets.php" style="color: #ffd700;">المقترحات</a></li>
                        <li><a href="commissions.php">الجلسات</a></li>
                        <li><a href="appels_offres.php">الصفقات</a></li>
                        <li><a href="statistiques.php">الإحصائيات</a></li>
                        <li><a href="administration.php">الإدارة</a></li>
                    </ul>
                </nav>
                <div class="user-menu">
                    <span class="user-name"><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                    <a href="../logout.php" class="btn-logout">تسجيل الخروج</a>
                </div>
            </div>
        </div>
    </header>

    <section class="content-section" style="padding: 40px 0;">
        <div class="container">
            <h2 class="section-title">قائمة المقترحات</h2>
            
            <div class="stats-summary">
                <div class="stat-box">
                    <div class="number" style="color: #ffc107;">
                        <?php echo count(array_filter($projets, function($p) { return $p['etat'] == 0; })); ?>
                    </div>
                    <div class="label">بصدد الدرس</div>
                </div>
                <div class="stat-box">
                    <div class="number" style="color: #17a2b8;">
                        <?php echo count(array_filter($projets, function($p) { return $p['etat'] == 1; })); ?>
                    </div>
                    <div class="label">الإحالة على اللجنة</div>
                </div>
                <div class="stat-box">
                    <div class="number" style="color: #4caf50;">
                        <?php echo count(array_filter($projets, function($p) { return $p['etat'] == 2; })); ?>
                    </div>
                    <div class="label">الموافقة</div>
                </div>
                <div class="stat-box">
                    <div class="number" style="color: #dc3545;">
                        <?php echo count(array_filter($projets, function($p) { return $p['etat'] == 3; })); ?>
                    </div>
                    <div class="label">عدم الموافقة</div>
                </div>
            </div>
            
            <div class="filters-section">
                <form method="GET">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label>البحث</label>
                            <input type="text" name="search" placeholder="ابحث عن مقترح..." value="<?php echo htmlspecialchars($searchQuery); ?>">
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
                        <div class="filter-group">
                            <label>الموسسات</label>
                            <select name="ministere">
                                <option value="">جميع المؤسسات</option>
                                <option value=""> </option>
                            </select>
                        </div>
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
                        
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">🔍 بحث</button>
                        <a href="projets.php" class="btn btn-secondary">🔄 إعادة تعيين</a>
                        <?php if (Permissions::canCreateProjet()): ?>
                            <button type="button" class="btn btn-success" id="btnOpenModal">➕ إضافة مقترح جديد</button>
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
                                <th>تاريخ الوصول</th>
                                <th>الكلفة (د.ت)</th>
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
                                    <td style="text-align: right;"><?php echo htmlspecialchars(substr($projet['sujet'], 0, 200)); ?></td>
                                    <td><?php echo htmlspecialchars($projet['libMinistere']); ?></td>
                                    <td><?php echo htmlspecialchars($projet['libEtablissement']); ?></td>
                                    <td><?php echo date('Y/m/d', strtotime($projet['dateArrive'])); ?></td>
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
                                            <a href="view_document.php?id=<?php echo $projet['docMuqtarahId']; ?>" 
                                               target="_blank" style="color: #4caf50; text-decoration: none;">
                                                📄 <?php echo htmlspecialchars(substr($projet['docMuqtarah'], 0, 20)); ?>
                                            </a>
                                        <?php else: ?>
                                            <span style="color: #999;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($projet['docTaqrirId']): ?>
                                            <a href="view_document.php?id=<?php echo $projet['docTaqrirId']; ?>" 
                                               target="_blank" style="color: #ff9800; text-decoration: none;">
                                                📊 <?php echo htmlspecialchars(substr($projet['docTaqrir'], 0, 20)); ?>
                                            </a>
                                        <?php else: ?>
                                            <?php if (Permissions::canEditProjet($projet['idUser'])): ?>
                                                <button onclick="openTaqrirModal(<?php echo $projet['idPro']; ?>)" 
                                                        style="background: #ff9800; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px;">
                                                    ➕ إضافة
                                                </button>
                                            <?php else: ?>
                                                <span style="color: #999;">-</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (Permissions::canEditProjet($projet['idUser'])): ?>
                                            <a href="modifier_projet.php?id=<?php echo $projet['idPro']; ?>" class="btn-action btn-edit">تعديل</a>
                                        <?php endif; ?>
                                        <?php if (Permissions::canDeleteProjet($projet['idUser'])): ?>
                                            <a href="#" class="btn-action btn-delete" 
                                               onclick="return confirm('هل أنت متأكد من حذف هذا المقترح؟');">حذف</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="text-align: center; padding: 40px; color: #666;">لا توجد مقترحات</p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- MODAL -->
    <div id="addProjetModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>➕ إضافة مقترح جديد</h2>
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
                        <div class="form-group form-group-full">
                            <label>الكلفة التقديرية (د.ت) <span class="required">*</span></label>
                            <input type="number" name="cout" class="form-control" required 
                                   step="0.01" min="0" placeholder="0.00">
                        </div>
                        
                        <!-- 7. المقترح -->
                        <div class="form-group form-group-full">
                            <label>المقترح <span class="required">*</span></label>
                            <textarea name="proposition" class="form-control" required 
                                      placeholder="أدخل تفاصيل المقترح والتوصيات..."></textarea>
                        </div>
                        
                        <!-- 8. المقرر -->
                        <div class="form-group">
                            <label>المقرر (الإداري/المقرر) <span class="required">*</span></label>
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
                        
                        <!-- 9. عنوان الملف -->
                        <div class="form-group">
                            <label>عنوان المقترح <span class="required">*</span></label>
                            <input type="text" name="libDoc" class="form-control" required 
                                   placeholder="أدخل عنوان المقترح">
                        </div>
                        
                        <!-- 10. الملف -->
                        <div class="form-group form-group-full">
                            <label>الملف (PDF, Word, Excel) <span class="required">*</span></label>
                            <input type="file" name="fichier" id="fichier" class="form-control" 
                                   accept=".pdf,.doc,.docx,.xls,.xlsx" required>
                            <small style="color: #666; font-size: 12px; display: block; margin-top: 5px;">
                                الحجم الأقصى: 5MB - الأنواع المقبولة: PDF, Word, Excel
                            </small>
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
                
                if (fileSize > 5) {
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
                
                if (fileSize > 5) {
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
    </script>
</body>
</html>