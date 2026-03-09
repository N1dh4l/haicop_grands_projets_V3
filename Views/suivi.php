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

    // Générer le token CSRF
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrf_token = $_SESSION['csrf_token'];

    $page_title = "متابعة المشاريع - نظام إدارة المشاريع";

    // ==========================================
    // TRAITEMENT UPLOAD FICHIER
    // ==========================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_suivi_doc') {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');

        try {
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                throw new Exception('Token de sécurité invalide');
            }

            $idPro   = isset($_POST['idPro'])   ? intval($_POST['idPro'])          : 0;
            $libDoc  = isset($_POST['libDoc'])   ? trim($_POST['libDoc'])           : '';
            $typeDoc = isset($_POST['typeDoc'])  ? intval($_POST['typeDoc'])        : 0;

            if ($idPro <= 0)      throw new Exception('معرف المشروع غير صالح');
            if (empty($libDoc))   throw new Exception('اسم الملف مطلوب');
            if ($typeDoc <= 0)    throw new Exception('نوع الملف مطلوب');

            // Vérifier que le projet existe et a le bon état
            $checkStmt = $db->prepare("SELECT p.idPro FROM projet p INNER JOIN appeloffre a ON a.idPro = p.idPro WHERE p.idPro = :idPro");
            $checkStmt->bindParam(':idPro', $idPro);
            $checkStmt->execute();
            if ($checkStmt->rowCount() == 0) throw new Exception('المشروع غير موجود');

            if (!isset($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('يرجى اختيار ملف');
            }

            $fileTmpPath   = $_FILES['fichier']['tmp_name'];
            $fileName      = $_FILES['fichier']['name'];
            $fileSize      = $_FILES['fichier']['size'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
            if (!in_array($fileExtension, $allowedExtensions)) {
                throw new Exception('نوع الملف غير مقبول');
            }
            if ($fileSize > 10485760) {
                throw new Exception('حجم الملف يجب أن يكون أقل من 10 ميغابايت');
            }

            $uploadFileDir = '../uploads/suivi/';
            if (!file_exists($uploadFileDir)) mkdir($uploadFileDir, 0755, true);

            $newFileName = 'suivi_' . $idPro . '_' . time() . '.' . $fileExtension;
            $dest_path   = $uploadFileDir . $newFileName;

            if (!move_uploaded_file($fileTmpPath, $dest_path)) {
                throw new Exception('فشل في رفع الملف');
            }

            $queryDoc = "INSERT INTO document (idPro, libDoc, cheminAcces, type) 
                         VALUES (:idPro, :libDoc, :cheminAcces, :type)";
            $stmtDoc = $db->prepare($queryDoc);
            $stmtDoc->bindParam(':idPro',       $idPro);
            $stmtDoc->bindParam(':libDoc',       $libDoc);
            $stmtDoc->bindParam(':cheminAcces',  $dest_path);
            $stmtDoc->bindParam(':type',         $typeDoc);
            $stmtDoc->execute();

            // Journal
            $action  = "إضافة وثيقة متابعة للمشروع رقم " . $idPro . " : " . $libDoc;
            $idUser  = $_SESSION['user_id'] ?? 0;
            $stmtJ   = $db->prepare("INSERT INTO journal (idUser, action, date) VALUES (:idUser, :action, NOW())");
            $stmtJ->execute([':idUser' => $idUser, ':action' => $action]);

            echo json_encode(['success' => true, 'message' => 'تم رفع الملف بنجاح']);

        } catch (Exception $e) {
            if (isset($dest_path) && file_exists($dest_path)) unlink($dest_path);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // Filtres
    $searchQuery     = isset($_GET['search'])    ? Security::sanitizeInput($_GET['search'])    : '';
    $filterMinistere = isset($_GET['ministere']) ? Security::sanitizeInput($_GET['ministere']) : '';
    $filterEtat      = isset($_GET['etat'])      ? Security::sanitizeInput($_GET['etat'])      : '';
    $filterYear      = isset($_GET['year'])      ? Security::sanitizeInput($_GET['year'])      : '';

    // Années disponibles
    $sqlYears = "SELECT DISTINCT YEAR(dateArrive) as year FROM projet WHERE dateArrive IS NOT NULL ORDER BY year DESC";
    $stmtYears = $db->prepare($sqlYears);
    $stmtYears->execute();
    $years = $stmtYears->fetchAll(PDO::FETCH_ASSOC);

    // Ministères
    $sqlMin = "SELECT idMinistere, libMinistere FROM ministere ORDER BY libMinistere";
    $stmtMin = $db->prepare($sqlMin);
    $stmtMin->execute();
    $ministeres = $stmtMin->fetchAll(PDO::FETCH_ASSOC);

    // Pagination
    if (isset($_GET['items_per_page']) && $_GET['items_per_page'] === 'all') {
        $itemsPerPage = 999999;
    } else {
        $itemsPerPage = isset($_GET['items_per_page']) ? min(100, max(10, intval($_GET['items_per_page']))) : 10;
    }
    $currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $offset = ($currentPage - 1) * $itemsPerPage;

    // Requête COUNT — basée sur la table appeloffre
    $sqlCount = "SELECT COUNT(DISTINCT a.idApp) as total
                 FROM appeloffre a
                 INNER JOIN projet p ON a.idPro = p.idPro
                 LEFT JOIN ministere m ON p.idMinistere = m.idMinistere
                 WHERE 1=1";
    if (!empty($searchQuery))     $sqlCount .= " AND (p.sujet LIKE :search OR m.libMinistere LIKE :search)";
    if (!empty($filterMinistere)) $sqlCount .= " AND p.idMinistere = :ministere";
    if (!empty($filterEtat))      $sqlCount .= " AND p.etat = :etat";
    if (!empty($filterYear))      $sqlCount .= " AND YEAR(p.dateArrive) = :year";

    $stmtCount = $db->prepare($sqlCount);
    if (!empty($searchQuery))     { $sp = "%{$searchQuery}%"; $stmtCount->bindParam(':search', $sp); }
    if (!empty($filterMinistere)) $stmtCount->bindParam(':ministere', $filterMinistere);
    if (!empty($filterEtat))      $stmtCount->bindParam(':etat', $filterEtat);
    if (!empty($filterYear))      $stmtCount->bindParam(':year', $filterYear);
    $stmtCount->execute();
    $totalItems = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalItems / $itemsPerPage);

    // Requête principale — basée sur la table appeloffre
    $sql = "SELECT a.idApp, p.idPro, p.sujet, p.cout, p.dateArrive, p.etat, p.idUser,
                   m.libMinistere, e.libEtablissement, u.nomUser,
                   MAX(c.numCommission) as numCommission,
                   MAX(c.dateCommission) as dateCommission,
                   MAX(pc.naturePc) as naturePc,
                   CASE p.etat
                       WHEN 3 THEN 'إسناد وقتي'
                       WHEN 4 THEN 'إسناد نهائي'
                       ELSE 'بصدد الإجراءات'
                   END as etatLib
            FROM appeloffre a
            INNER JOIN projet p       ON a.idPro = p.idPro
            LEFT JOIN ministere m     ON p.idMinistere = m.idMinistere
            LEFT JOIN etablissement e ON p.idEtab = e.idEtablissement
            LEFT JOIN user u          ON p.idUser = u.idUser
            LEFT JOIN projetcommission pc ON p.idPro = pc.idPro
            LEFT JOIN commission c    ON pc.idCom = c.idCom
            WHERE 1=1";

    if (!empty($searchQuery))     $sql .= " AND (p.sujet LIKE :search OR m.libMinistere LIKE :search)";
    if (!empty($filterMinistere)) $sql .= " AND p.idMinistere = :ministere";
    if (!empty($filterEtat))      $sql .= " AND p.etat = :etat";
    if (!empty($filterYear))      $sql .= " AND YEAR(p.dateArrive) = :year";
    $sql .= " GROUP BY a.idApp, p.idPro, p.sujet, p.cout, p.dateArrive, p.etat, p.idUser,
                       m.libMinistere, e.libEtablissement, u.nomUser, etatLib
              ORDER BY MAX(c.dateCommission) DESC, p.dateArrive DESC LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($sql);
    if (!empty($searchQuery))     { $sp = "%{$searchQuery}%"; $stmt->bindParam(':search', $sp); }
    if (!empty($filterMinistere)) $stmt->bindParam(':ministere', $filterMinistere);
    if (!empty($filterEtat))      $stmt->bindParam(':etat', $filterEtat);
    if (!empty($filterYear))      $stmt->bindParam(':year', $filterYear);
    $stmt->bindParam(':limit',  $itemsPerPage, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset,       PDO::PARAM_INT);
    $stmt->execute();
    $projets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Statistiques — basées sur la table appeloffre
    $statsStmt = $db->prepare("SELECT 
        COUNT(DISTINCT CASE WHEN p.etat = 3 THEN a.idApp END) as total_3,
        COUNT(DISTINCT CASE WHEN p.etat = 4 THEN a.idApp END) as total_4
        FROM appeloffre a
        INNER JOIN projet p ON a.idPro = p.idPro");
    $statsStmt->execute();
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    function buildPaginationUrl($page) {
        $params = $_GET;
        $params['page'] = $page;
        return 'suivi.php?' . http_build_query($params);
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
        .admin-header {
            background: linear-gradient(135deg, #4a90e2 0%, #357abd 100%);
            color: white; padding: 40px; border-radius: 15px;
            margin-bottom: 30px; text-align: center;
            box-shadow: 0 8px 25px rgba(74,144,226,0.3);
        }
        .admin-header h2 { font-size: 32px; margin-bottom: 10px; }
        .admin-header p  { font-size: 16px; opacity: 0.9; }

        .stats-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px; margin-bottom: 30px;
        }
        .stat-box {
            background: white; padding: 20px; border-radius: 12px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08); text-align: center; border-top: 4px solid;
        }
        .stat-box.total  { border-color: #4a90e2; }
        .stat-box.isnad  { border-color: #8b5cf6; }
        .stat-box.isnad2 { border-color: #10b981; }
        .stat-box .number { font-size: 36px; font-weight: bold; margin-bottom: 8px; }
        .stat-box.total  .number { color: #4a90e2; }
        .stat-box.isnad  .number { color: #8b5cf6; }
        .stat-box.isnad2 .number { color: #10b981; }
        .stat-box .label { color: #666; font-size: 14px; font-weight: 600; }

        .filters-section {
            background: white; padding: 25px; border-radius: 15px;
            margin-bottom: 30px; box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        .filters-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px; margin-bottom: 20px;
        }
        .filter-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #333; }
        .filter-group input, .filter-group select {
            width: 100%; padding: 12px; border: 2px solid #e0e0e0;
            border-radius: 8px; font-size: 14px; font-family: inherit; transition: border-color 0.3s;
        }
        .filter-group input:focus, .filter-group select:focus { outline: none; border-color: #4a90e2; }
        .filter-actions { display: flex; gap: 15px; justify-content: flex-end; }
        .btn {
            padding: 12px 30px; border: none; border-radius: 8px;
            font-size: 14px; font-weight: 600; cursor: pointer;
            transition: all 0.3s; text-decoration: none; display: inline-block;
        }
        .btn-primary   { background: linear-gradient(135deg, #4a90e2 0%, #357abd 100%); color: white; }
        .btn-secondary { background: #f5f7fa; color: #333; }
        .btn-success   { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; }
        .btn:hover     { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }

        .projects-table {
            background: white; border-radius: 15px; padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        .projects-table table { width: 100%; border-collapse: collapse; }
        thead { background: linear-gradient(135deg, #4a90e2 0%, #357abd 100%); color: white; }
        th, td { padding: 14px 15px; text-align: center; }
        td { border-bottom: 1px solid #f0f0f0; }
        tbody tr:hover { background: #f0f6ff; }
        tbody tr:nth-child(even) { background: #f8fafd; }
        tbody tr:nth-child(even):hover { background: #f0f6ff; }

        .badge {
            padding: 6px 14px; border-radius: 20px; font-size: 12px;
            font-weight: 700; display: inline-block; white-space: nowrap;
        }
        .badge-isnad  { background: #f3e8ff; color: #6b21a8; border: 1px solid #d8b4fe; }
        .badge-isnad2 { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }

        .commission-badge {
            background: #e8f4fd; color: #1a6fa8; border: 1px solid #b8d9f0;
            padding: 4px 10px; border-radius: 12px; font-size: 12px;
            font-weight: 600; display: inline-block;
        }

        /* Bouton ajouter fichier */
        .btn-action {
            padding: 5px 10px !important; border-radius: 6px; font-size: 12px !important;
            font-weight: 600; cursor: pointer; transition: all 0.2s ease; border: none;
            display: inline-flex; align-items: center; justify-content: center;
            gap: 3px; min-width: 56px !important; box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            white-space: nowrap;
        }
        .btn-action:hover { transform: translateY(-1px); box-shadow: 0 2px 5px rgba(0,0,0,0.15); }
        .btn-add-file {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%); color: white;
        }
        .btn-add-file:hover { background: linear-gradient(135deg, #138496 0%, #0f6674 100%); }

        /* MODAL */
        .modal {
            display: none; position: fixed; z-index: 99999;
            left: 0; top: 0; width: 100%; height: 100%;
            background-color: rgba(0,0,0,0.7);
        }
        .modal.show { display: block; animation: fadeIn 0.3s; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        .modal-content {
            background: white; margin: 5% auto; border-radius: 15px;
            width: 90%; max-width: 550px;
            box-shadow: 0 10px 50px rgba(0,0,0,0.4);
            animation: slideDown 0.3s;
        }
        @keyframes slideDown {
            from { transform: translateY(-60px); opacity: 0; }
            to   { transform: translateY(0);     opacity: 1; }
        }
        .modal-header {
            background: linear-gradient(135deg, #4a90e2 0%, #357abd 100%);
            color: white; padding: 22px 28px; border-radius: 15px 15px 0 0;
            display: flex; justify-content: space-between; align-items: center;
        }
        .modal-header h2 { margin: 0; font-size: 20px; }
        .close {
            color: white; font-size: 30px; font-weight: bold;
            cursor: pointer; line-height: 1; transition: transform 0.3s;
        }
        .close:hover { transform: scale(1.2); }
        .modal-body { padding: 28px; }
        .modal-footer {
            padding: 18px 28px; border-top: 1px solid #e0e0e0;
            display: flex; gap: 12px; justify-content: center;
        }
        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block; margin-bottom: 8px;
            font-weight: 600; color: #333;
        }
        .form-group label .required { color: #dc3545; }
        .form-control {
            width: 100%; padding: 12px; border: 2px solid #e0e0e0;
            border-radius: 8px; font-size: 14px; font-family: inherit;
            transition: border-color 0.3s; box-sizing: border-box;
        }
        .form-control:focus { outline: none; border-color: #4a90e2; box-shadow: 0 0 0 3px rgba(74,144,226,0.1); }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 15px; font-size: 14px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        /* Pagination */
        .pagination-container {
            display: flex; justify-content: space-between; align-items: center;
            margin-top: 30px; padding: 20px; background: white;
            border-radius: 12px; box-shadow: 0 3px 10px rgba(0,0,0,0.08);
        }
        .pagination-info { color: #666; font-size: 14px; }
        .pagination { display: flex; gap: 8px; list-style: none; padding: 0; margin: 0; }
        .pagination a, .pagination span {
            display: inline-block; padding: 10px 16px; border-radius: 8px;
            text-decoration: none; color: #333; background: #f5f7fa;
            transition: all 0.3s; font-weight: 500; min-width: 44px; text-align: center;
        }
        .pagination a:hover { background: linear-gradient(135deg, #4a90e2 0%, #357abd 100%); color: white; }
        .pagination .active span { background: linear-gradient(135deg, #4a90e2 0%, #357abd 100%); color: white; }
        .pagination .disabled { opacity: 0.5; pointer-events: none; }
        .pagination .dots { padding: 10px 8px; background: transparent; color: #999; }

        .items-per-page { margin-top: 15px; text-align: center; }
        .items-per-page select { padding: 8px 12px; border: 2px solid #e0e0e0; border-radius: 6px; font-size: 14px; }

        .btn-export {
            display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px;
            border: none; border-radius: 8px; font-weight: 600; font-size: 14px;
            cursor: pointer; transition: all 0.3s; box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .btn-export:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
        .btn-export-excel { background: linear-gradient(135deg, #217346, #2d9a5a); color: white; }
        .btn-export-word  { background: linear-gradient(135deg, #2b579a, #3d6fc4); color: white; }
        .btn-export-pdf   { background: linear-gradient(135deg, #d32f2f, #f44336); color: white; }

        @media (max-width: 768px) {
            .filters-grid { grid-template-columns: 1fr; }
            .pagination-container { flex-direction: column; gap: 15px; }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <section class="content-section" style="padding: 40px 0;">
        <div class="container">

            <!-- Header -->
            <div class="admin-header">
                <h2>📊 متابعة المشاريع</h2>
                <p>المشاريع في مرحلة الإسناد الوقتي والإسناد النهائي</p>
            </div>

            <!-- Statistiques -->
            <div class="stats-summary">
                <div class="stat-box total">
                    <div class="number"><?php echo $stats['total_3'] + $stats['total_4']; ?></div>
                    <div class="label">العدد الجملي</div>
                </div>
                <div class="stat-box isnad">
                    <div class="number"><?php echo $stats['total_3']; ?></div>
                    <div class="label">إسناد وقتي</div>
                </div>
                <div class="stat-box isnad2">
                    <div class="number"><?php echo $stats['total_4']; ?></div>
                    <div class="label">إسناد نهائي</div>
                </div>
            </div>

            <!-- Filtres -->
            <div class="filters-section">
                <form method="GET">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label>البحث</label>
                            <input type="text" name="search" placeholder="ابحث عن مشروع..."
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
                        <div class="filter-group">
                            <label>الحالة</label>
                            <select name="etat">
                                <option value="">جميع الحالات</option>
                                <option value="22" <?php echo $filterEtat === '22' ? 'selected' : ''; ?>>إسناد وقتي</option>
                                <option value="23" <?php echo $filterEtat === '23' ? 'selected' : ''; ?>>إسناد نهائي</option>
                            </select>
                        </div>
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
                        <a href="suivi.php" class="btn btn-secondary">🔄 إعادة تعيين</a>
                    </div>
                </form>
            </div>

            <!-- Tableau -->
            <div class="projects-table">
                <!-- Export -->
                <div style="margin-bottom: 20px; text-align: left; direction: ltr;">
                    <div style="display: inline-flex; gap: 10px; background: white; padding: 15px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                        <button onclick="exportData('excel')" class="btn-export btn-export-excel">Excel</button>
                        <button onclick="exportData('word')"  class="btn-export btn-export-word">Word</button>
                        <button onclick="exportData('pdf')"   class="btn-export btn-export-pdf">PDF</button>
                        <span style="color:#666; font-size:12px; align-self:center; margin-right:10px; direction:rtl;">📥 تحميل</span>
                    </div>
                </div>

                <?php if (count($projets) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th style="width:35%;">موضوع المشروع</th>
                            <th>الوزارة</th>
                            <th>الحالة</th>
                            <th>المقرر</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($projets as $projet): ?>
                        <tr>
                            <td style="text-align:right;">
                                <span style="font-weight:600; color:#333; line-height:1.5;">
                                    <?php echo htmlspecialchars(substr($projet['sujet'], 0, 200)); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($projet['libMinistere'] ?? '-'); ?></td>
                           <td>
                                <span class="badge <?php echo $projet['etat'] == 3 ? 'badge-isnad' : 'badge-isnad2'; ?>">
                                    <?php echo htmlspecialchars($projet['etatLib']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($projet['nomUser'] ?? '-'); ?></td>
                            <td>
                                <button onclick="openAddFileModal(<?php echo $projet['idPro']; ?>, '<?php echo addslashes(substr($projet['sujet'], 0, 60)); ?>')"
                                        class="btn-action btn-add-file"
                                        title="إضافة ملف">
                                    📎 ملف
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php else: ?>
                <div style="text-align:center; padding:60px 20px;">
                    <div style="font-size:48px; color:#ddd; margin-bottom:20px;">📊</div>
                    <h3 style="color:#999; margin-bottom:10px;">لا توجد مشاريع</h3>
                    <p style="color:#bbb;">لا توجد مشاريع في مرحلة الإسناد الوقتي أو الإسناد النهائي</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination-container">
                <div class="pagination-info">
                    عرض <?php echo (($currentPage - 1) * $itemsPerPage) + 1; ?> -
                    <?php echo min($currentPage * $itemsPerPage, $totalItems); ?>
                    من أصل <?php echo $totalItems; ?> مشروع
                </div>
                <ul class="pagination">
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
                        if ($currentPage > $range + 2) echo '<li><span class="dots">...</span></li>';
                    }
                    for ($i = max(1, $currentPage - $range); $i <= min($totalPages, $currentPage + $range); $i++) {
                        if ($i == $currentPage) echo '<li class="active"><span>' . $i . '</span></li>';
                        else echo '<li><a href="' . buildPaginationUrl($i) . '">' . $i . '</a></li>';
                    }
                    if ($currentPage < $totalPages - $range) {
                        if ($currentPage < $totalPages - $range - 1) echo '<li><span class="dots">...</span></li>';
                        echo '<li><a href="' . buildPaginationUrl($totalPages) . '">' . $totalPages . '</a></li>';
                    }
                    ?>
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

            <!-- Items per page -->
            <div class="items-per-page">
                <label style="color:#666; font-size:14px; margin-left:10px;">عدد المشاريع في الصفحة:</label>
                <select id="itemsPerPageSelect">
                    <option value="all">الكل</option>
                    <option value="10"  <?php echo $itemsPerPage == 10  ? 'selected' : ''; ?>>10</option>
                    <option value="25"  <?php echo $itemsPerPage == 25  ? 'selected' : ''; ?>>25</option>
                    <option value="50"  <?php echo $itemsPerPage == 50  ? 'selected' : ''; ?>>50</option>
                    <option value="100" <?php echo $itemsPerPage == 100 ? 'selected' : ''; ?>>100</option>
                </select>
            </div>

        </div>
    </section>

    <!-- ===== MODAL AJOUT FICHIER ===== -->
    <div id="addFileModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>📎 إضافة ملف</h2>
                <span class="close" id="btnCloseFileModal">&times;</span>
            </div>
            <div class="modal-body">
                <div id="fileModalAlert"></div>

                <!-- Nom du projet -->
                <div id="projetSujetDisplay" style="background:#f0f6ff; padding:12px 15px; border-radius:8px; margin-bottom:18px; color:#335F8A; font-weight:600; font-size:14px; border-right:4px solid #4a90e2;">
                </div>

                <form id="addFileForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action"     value="upload_suivi_doc">
                    <input type="hidden" name="idPro"      id="fileIdPro">

                    <!-- Nom du fichier -->
                    <div class="form-group">
                        <label>اسم الملف <span class="required">*</span></label>
                        <input type="text" name="libDoc" id="libDoc" class="form-control"
                               placeholder="أدخل اسم الملف" required>
                    </div>

                    <!-- Type de fichier -->
                    <div class="form-group">
                        <label>نوع الملف <span class="required">*</span></label>
                        <select name="typeDoc" id="typeDoc" class="form-control" required>
                            <option value="">-- اختر نوع الملف --</option>
                            <option value="30">مراسلة</option>
                            <option value="31">أخرى</option>
                        </select>
                    </div>

                    <!-- Fichier -->
                    <div class="form-group">
                        <label>الملف <span class="required">*</span></label>
                        <input type="file" name="fichier" id="fichier" class="form-control"
                               accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" required>
                        <small style="color:#666; font-size:12px; display:block; margin-top:5px;">
                            الحجم الأقصى: 10MB — PDF, Word, Excel, صور
                        </small>
                    </div>

                    <div class="modal-footer">
                        <button type="submit" class="btn btn-success">✓ حفظ الملف</button>
                        <button type="button" class="btn btn-secondary" id="btnCancelFileModal">✕ إلغاء</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include 'includes/footer.php'; ?>

    <script>
        // ===== MODAL FICHIER =====
        var fileModal   = document.getElementById('addFileModal');
        var btnClose    = document.getElementById('btnCloseFileModal');
        var btnCancel   = document.getElementById('btnCancelFileModal');

        function openAddFileModal(idPro, sujet) {
            document.getElementById('fileIdPro').value       = idPro;
            document.getElementById('projetSujetDisplay').textContent = sujet;
            document.getElementById('addFileForm').reset();
            document.getElementById('fileIdPro').value       = idPro;
            document.getElementById('fileModalAlert').innerHTML = '';
            fileModal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function fermerFileModal() {
            fileModal.classList.remove('show');
            document.body.style.overflow = 'auto';
            document.getElementById('addFileForm').reset();
            document.getElementById('fileModalAlert').innerHTML = '';
        }

        btnClose.onclick  = fermerFileModal;
        btnCancel.onclick = fermerFileModal;
        window.addEventListener('click', function(e) {
            if (e.target == fileModal) fermerFileModal();
        });

        // Soumettre le formulaire
        document.getElementById('addFileForm').addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);
            var alertDiv = document.getElementById('fileModalAlert');

            alertDiv.innerHTML = '<div style="text-align:center; padding:15px;"><div style="display:inline-block; border:3px solid #f3f3f3; border-top:3px solid #4a90e2; border-radius:50%; width:30px; height:30px; animation:spin 1s linear infinite;"></div><p style="margin-top:10px;">جاري الرفع...</p></div>';

            fetch('suivi.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        alertDiv.innerHTML = '<div class="alert alert-success">✓ ' + data.message + '</div>';
                        setTimeout(() => fermerFileModal(), 1500);
                    } else {
                        alertDiv.innerHTML = '<div class="alert alert-error">✕ ' + data.message + '</div>';
                    }
                })
                .catch(() => {
                    alertDiv.innerHTML = '<div class="alert alert-error">✕ حدث خطأ في الاتصال</div>';
                });
        });

        // Items per page
        document.getElementById('itemsPerPageSelect')?.addEventListener('change', function() {
            var params = new URLSearchParams(window.location.search);
            params.set('items_per_page', this.value);
            params.delete('page');
            window.location.href = 'suivi.php?' + params.toString();
        });

        // Export
        function exportData(format) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'export_suivi.php';
            form.target = '_blank';
            const inputs = {
                format:    format,
                search:    '<?php echo addslashes($searchQuery); ?>',
                ministere: '<?php echo addslashes($filterMinistere); ?>',
                etat:      '<?php echo addslashes($filterEtat); ?>',
                year:      '<?php echo addslashes($filterYear); ?>'
            };
            Object.entries(inputs).forEach(([name, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden'; input.name = name; input.value = value;
                form.appendChild(input);
            });
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }

        // Timeout inactivité
        let inactivityTime = function () {
            let time;
            window.onload = resetTimer;
            document.onmousemove = resetTimer;
            document.onkeypress = resetTimer;
            document.onclick = resetTimer;
            document.onscroll = resetTimer;
            function logout() { window.location.href = '../logout.php'; }
            function resetTimer() { clearTimeout(time); time = setTimeout(logout, 1800000); }
        };
        inactivityTime();

    </script>
</body>
</html>