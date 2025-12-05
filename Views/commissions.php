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
                
                if ($fileSize > 10242880) { // 5MB
                    throw new Exception('حجم ملف المحضر يجب أن يكون أقل من 5 ميغابايت');
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
                    $type = 1; // Type pour محضر الجلسة
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
    $queryProjets = "SELECT idPro, sujet FROM projet WHERE etat = 1 OR 21 OR 22 OR 23 ORDER BY dateCreation DESC";
    $stmtProjets = $db->prepare($queryProjets);
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
    $sqlCount = "SELECT COUNT(DISTINCT c.idCom) as total
                FROM commission c
                LEFT JOIN projetcommission pc ON c.idCom = pc.idCom
                LEFT JOIN projet p ON pc.idPro = p.idPro
                WHERE 1=1";

    if (!empty($filterSearch)) {
        $sqlCount .= " AND (p.sujet LIKE :search OR c.numCommission LIKE :search)";
    }
    if (!empty($filterYear)) {
        $sqlCount .= " AND YEAR(c.dateCommission) = :year";
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

    // Récupérer les commissions avec pagination
    $sqlCommissions = "SELECT c.idCom, c.numCommission, c.dateCommission,
                    GROUP_CONCAT(DISTINCT p.sujet SEPARATOR ' | ') as projets,
                    GROUP_CONCAT(DISTINCT 
                        CASE pc.naturePc
                            WHEN 20 THEN 'إدراج وقتي'
                            WHEN 21 THEN 'إدراج نهائي'
                            WHEN 22 THEN 'إسناد وقتي'
                            WHEN 23 THEN 'إسناد نهائي'
                        END
                    SEPARATOR ' | ') as natures,
                    (SELECT cheminAcces FROM document WHERE type = 1 AND idExterne = c.idCom LIMIT 1) as mahdarPath,
                    (SELECT idDoc FROM document WHERE type = 1 AND idExterne = c.idCom LIMIT 1) as mahdarId
                FROM commission c
                LEFT JOIN projetcommission pc ON c.idCom = pc.idCom
                LEFT JOIN projet p ON pc.idPro = p.idPro
                WHERE 1=1";

    if (!empty($filterSearch)) {
        $sqlCommissions .= " AND (p.sujet LIKE :search OR c.numCommission LIKE :search)";
    }
    if (!empty($filterYear)) {
        $sqlCommissions .= " AND YEAR(c.dateCommission) = :year";
    }

    $sqlCommissions .= " GROUP BY c.idCom, c.numCommission, c.dateCommission
                        ORDER BY c.dateCommission DESC, c.numCommission DESC
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
        
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <section class="content-section" style="padding: 40px 0;">
        <div class="container">
            <h2 class="section-title">قائمة الجلسات</h2>
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
                        <button type="submit" class="btn btn-primary">🔍 بحث</button>
                        <a href="commissions.php" class="btn btn-secondary">🔄 إعادة تعيين</a>
                        <?php if (Permissions::canCreateProjet()): ?>
                            <button type="button" class="btn btn-success" id="btnOpenModal">➕ إضافة جلسة
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <div class="projects-table">
                <?php if (count($commissions) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>عدد الجلسة</th>
                                <th>تاريخ الجلسة</th>
                                <th>المقترحات المعروضة</th>
                                <th>نوعية المقترح</th>
                                <th>محضر الجلسة</th>
                                <th> قرار اللجنة</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($commissions as $commission): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($commission['numCommission']); ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($commission['dateCommission'])); ?></td>
                                    <td style="text-align: right; padding: 10px;">
                                        <?php 
                                            $projets_list = explode(' | ', $commission['projets']);
                                            foreach ($projets_list as $index => $projet_sujet) {
                                                if ($index > 0) echo '<hr style="margin: 8px 0; border: none; border-top: 1px solid #e0e0e0;">';
                                                echo '<div style="padding: 5px 0;">' . htmlspecialchars(substr($projet_sujet, 0, 300)) . '</div>';
                                            }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                            $natures_list = explode(' | ', $commission['natures']);
                                            foreach ($natures_list as $nature) {
                                                $badgeClass = '';
                                                if (strpos($nature, 'إدراج') !== false) {
                                                    $badgeClass = 'badge-processing';
                                                } else if (strpos($nature, 'إسناد') !== false) {
                                                    $badgeClass = 'badge-approved';
                                                }
                                                echo '<span class="badge ' . $badgeClass . '" style="display: block; margin: 3px 0;">' . htmlspecialchars($nature) . '</span>';
                                            }
                                        ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($commission['mahdarPath']) && $commission['mahdarId']): ?>
                                            <a href="<?php echo htmlspecialchars($commission['mahdarPath']); ?>" 
                                               target="_blank" 
                                               class="btn-action btn-view"
                                               style="display: inline-flex; align-items: center; gap: 5px; padding: 8px 15px;">
                                                👁️ عرض
                                            </a>
                                        <?php else: ?>
                                            <span style="color: #999;">لا يوجد</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($commission['mahdarPath']) && $commission['mahdarId']): ?>
                                            <a href="<?php echo htmlspecialchars($commission['mahdarPath']); ?>" 
                                               target="_blank" 
                                               class="btn-action btn-view"
                                               style="display: inline-flex; align-items: center; gap: 5px; padding: 8px 15px;">
                                                👁️ عرض
                                            </a>
                                        <?php else: ?>
                                            <span style="color: #999;">لا يوجد</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (Permissions::canEditProjet($_SESSION['user_id'])): ?>
                                            <a href="modifier_commission.php?id=<?php echo $commission['idCom']; ?>" 
                                               class="btn-action btn-edit">تعديل</a>
                                            <a href="javascript:void(0)" 
                                               onclick="confirmDeleteCommission(<?php echo $commission['idCom']; ?>)" 
                                               class="btn-action btn-delete">حذف</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="text-align: center; padding: 40px; color: #666;">لا توجد جلسات</p>
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
            <!-- ==========================================
                OPTION: Sélecteur du nombre d'éléments par page
                ========================================== -->
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

    <!-- MODAL -->
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
                                الحجم الأقصى: 5MB - الأنواع المقبولة: PDF, Word, Excel
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
                
                if (fileSize > 5) {
                    alert('حجم الملف يجب أن يكون أقل من 5 ميغابايت');
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
    </script>
</body>
</html>