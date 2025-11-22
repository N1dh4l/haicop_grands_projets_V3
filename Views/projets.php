<?php
require_once '../Config/Database.php';
require_once '../Config/Security.php';

Security::startSecureSession();
Security::requireLogin();

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
    Security::logout();
}
$_SESSION['last_activity'] = time();

$database = new Database();
$db = $database->getConnection();

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
    
    try {
        $sql = "INSERT INTO projet (idMinistere, idEtab, sujet, dateArrive, procedurePro, cout, proposition, idUser, etat, dateCreation) 
                VALUES (:idMinistere, :idEtab, :sujet, :dateArrive, :procedurePro, :cout, :proposition, :idUser, 1, NOW())";
        
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':idMinistere', $idMinistere);
        $stmt->bindParam(':idEtab', $idEtab);
        $stmt->bindParam(':sujet', $sujet);
        $stmt->bindParam(':dateArrive', $dateArrive);
        $stmt->bindParam(':procedurePro', $procedurePro);
        $stmt->bindParam(':cout', $cout);
        $stmt->bindParam(':proposition', $proposition);
        $stmt->bindParam(':idUser', $_SESSION['user_id']);
        
        if ($stmt->execute()) {
            $projetId = $db->lastInsertId();
            
            // Log l'action
            $logSql = "INSERT INTO journal (idUser, action, date) VALUES (:idUser, :action, CURDATE())";
            $logStmt = $db->prepare($logSql);
            $logStmt->bindParam(':idUser', $_SESSION['user_id']);
            $action = "إضافة مقترح جديد رقم {$projetId}: {$sujet}";
            $logStmt->bindParam(':action', $action);
            $logStmt->execute();
            
            echo json_encode(['success' => true, 'message' => 'تم إضافة المقترح بنجاح']);
        } else {
            echo json_encode(['success' => false, 'message' => 'فشل في إضافة المقترح']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'حدث خطأ: ' . $e->getMessage()]);
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
        END as etatLib
        FROM projet p
        LEFT JOIN ministere m ON p.idMinistere = m.idMinistere
        LEFT JOIN etablissement e ON p.idEtab = e.idEtablissement
        LEFT JOIN user u ON p.idUser = u.idUser
        WHERE 1=1";

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
        
        /* MODAL STYLES */
        .modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.7);
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
        
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .modal-content {
                width: 95%;
                margin: 5% auto;
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
            
            <!-- Statistics -->
            <div class="stats-summary">
                <div class="stat-box">
                    <div class="number" style="color: #667eea;"><?php echo count($projets); ?></div>
                    <div class="label">إجمالي المقترحات</div>
                </div>
                <div class="stat-box">
                    <div class="number" style="color: #ffc107;"><?php echo count(array_filter($projets, fn($p) => $p['etat'] == 0)); ?></div>
                    <div class="label">بصدد الدرس</div>
                </div>
                <div class="stat-box">
                    <div class="number" style="color: #17a2b8;"><?php echo count(array_filter($projets, fn($p) => $p['etat'] == 1)); ?></div>
                    <div class="label">الإحالة على اللجنة</div>
                </div>
                <div class="stat-box">
                    <div class="number" style="color: #4caf50;"><?php echo count(array_filter($projets, fn($p) => $p['etat'] == 2)); ?></div>
                    <div class="label">الموافقة</div>
                </div>
                <div class="stat-box">
                    <div class="number" style="color: #dc3545;"><?php echo count(array_filter($projets, fn($p) => $p['etat'] == 3)); ?></div>
                    <div class="label">عدم الموافقة</div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="filters-section">
                <form method="GET">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label>البحث</label>
                            <input type="text" name="search" placeholder="ابحث عن مقترح..." value="<?php echo htmlspecialchars($searchQuery); ?>">
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
                        <button type="submit" class="btn btn-primary">🔍 بحث</button>
                        <a href="projets.php" class="btn btn-secondary">🔄 إعادة تعيين</a>
                        <button type="button" class="btn btn-success" id="btnOpenModal">➕ إضافة مقترح جديد</button>
                    </div>
                </form>
            </div>
            
            <!-- Table -->
            <div class="projects-table">
                <?php if (count($projets) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>رقم</th>
                                <th>الموضوع</th>
                                <th>الوزارة</th>
                                <th>المؤسسة</th>
                                <th>تاريخ الوصول</th>
                                <th>الكلفة (د.ت)</th>
                                <th>الحالة</th>
                                <th>المستخدم</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($projets as $projet): ?>
                                <tr>
                                    <td><?php echo $projet['idPro']; ?></td>
                                    <td style="text-align: right;"><?php echo htmlspecialchars($projet['sujet']); ?></td>
                                    <td><?php echo htmlspecialchars($projet['libMinistere']); ?></td>
                                    <td><?php echo htmlspecialchars($projet['libEtablissement']); ?></td>
                                    <td><?php echo date('Y/m/d', strtotime($projet['dateArrive'])); ?></td>
                                    <td><?php echo number_format($projet['cout'], 2, '.', ' '); ?></td>
                                    <td>
                                        <span class="badge <?php echo match($projet['etat']) {
                                            0 => 'badge-pending', 1 => 'badge-processing',
                                            2 => 'badge-approved', 3 => 'badge-rejected', default => 'badge-pending'
                                        }; ?>">
                                            <?php echo $projet['etatLib']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($projet['nomUser']); ?></td>
                                    <td>
                                        <a href="voir_projet.php?id=<?php echo $projet['idPro']; ?>" class="btn-action btn-view">عرض</a>
                                        <a href="modifier_projet.php?id=<?php echo $projet['idPro']; ?>" class="btn-action btn-edit">تعديل</a>
                                        <a href="#" class="btn-action btn-delete" 
                                           onclick="return confirm('هل أنت متأكد من حذف هذا المقترح؟');">حذف</a>
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
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
                <div id="modalAlert"></div>
                
                <div class="info-box">
                    ℹ️ سيتم تعيين حالة المقترح تلقائياً إلى "الإحالة على اللجنة"
                </div>
                
                <form id="addProjetForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="add_projet">
                    
                    <div class="form-grid">
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
                        
                        <div class="form-group">
                            <label>المؤسسة <span class="required">*</span></label>
                            <select name="idEtab" id="modalEtab" class="form-control" required disabled>
                                <option value="">-- اختر الوزارة أولاً --</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>تاريخ الوصول <span class="required">*</span></label>
                            <input type="date" name="dateArrive" class="form-control" required 
                                   value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>الإجراء <span class="required">*</span></label>
                            <select name="procedurePro" class="form-control" required>
                                <option value="">-- اختر الإجراء --</option>
                                <option value="استشارة">استشارة</option>
                                <option value="طلب عروض">طلب عروض</option>
                                <option value="التفاوض المباشر">التفاوض المباشر</option>
                                <option value="أمر شراء">أمر شراء</option>
                            </select>
                        </div>
                        
                        <div class="form-group form-group-full">
                            <label>الكلفة التقديرية (د.ت) <span class="required">*</span></label>
                            <input type="number" name="cout" class="form-control" required 
                                   step="0.01" min="0" placeholder="0.00">
                        </div>
                        
                        <div class="form-group form-group-full">
                            <label>الموضوع <span class="required">*</span></label>
                            <textarea name="sujet" class="form-control" required 
                                      placeholder="أدخل موضوع المقترح بالتفصيل..."></textarea>
                        </div>
                        
                        <div class="form-group form-group-full">
                            <label>المقترح <span class="required">*</span></label>
                            <textarea name="proposition" class="form-control" required 
                                      placeholder="أدخل تفاصيل المقترح والتوصيات..."></textarea>
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

    <?php include 'includes/footer.php'; ?>

    <script>
        // Modal Functions
        const modal = document.getElementById('addProjetModal');
        const btnOpenModal = document.getElementById('btnOpenModal');
        const btnCloseModal = document.querySelector('.close');
        
        // Open modal
        btnOpenModal.addEventListener('click', function() {
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        });
        
        // Close modal
        btnCloseModal.addEventListener('click', function() {
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
            document.getElementById('addProjetForm').reset();
            document.getElementById('modalEtab').disabled = true;
            document.getElementById('modalAlert').innerHTML = '';
        });
        
        // Close modal on outside click
        window.addEventListener('click', function(event) {
            if (event.target == modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
                document.getElementById('addProjetForm').reset();
                document.getElementById('modalEtab').disabled = true;
                document.getElementById('modalAlert').innerHTML = '';
            }
        });
        
        // Close on cancel button
        document.getElementById('btnCancelModal').addEventListener('click', function() {
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
            document.getElementById('addProjetForm').reset();
            document.getElementById('modalEtab').disabled = true;
            document.getElementById('modalAlert').innerHTML = '';
        });
        
        // Load etablissements based on ministere
        document.getElementById('modalMinistere').addEventListener('change', function() {
            const ministereId = this.value;
            const etabSelect = document.getElementById('modalEtab');
            
            etabSelect.innerHTML = '<option value="">جاري التحميل...</option>';
            etabSelect.disabled = true;
            
            if (ministereId) {
                fetch('get_etablissements.php?ministere=' + ministereId)
                    .then(response => response.json())
                    .then(data => {
                        etabSelect.innerHTML = '<option value="">-- اختر المؤسسة --</option>';
                        
                        if (data.success && data.etablissements.length > 0) {
                            data.etablissements.forEach(etab => {
                                const option = document.createElement('option');
                                option.value = etab.idEtablissement;
                                option.textContent = etab.libEtablissement;
                                etabSelect.appendChild(option);
                            });