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

// Filtres
$filterUser = isset($_GET['user']) ? Security::sanitizeInput($_GET['user']) : '';
$filterAction = isset($_GET['action_type']) ? Security::sanitizeInput($_GET['action_type']) : '';
$filterDate = isset($_GET['date']) ? Security::sanitizeInput($_GET['date']) : '';
$search = isset($_GET['search']) ? Security::sanitizeInput($_GET['search']) : '';

// Pagination
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Requête principale
$sql = "SELECT j.*, u.nomUser, u.emailUser 
        FROM journal j 
        LEFT JOIN user u ON j.idUser = u.idUser 
        WHERE 1=1";

$params = [];

if (!empty($filterUser)) {
    $sql .= " AND j.idUser = :userId";
    $params[':userId'] = $filterUser;
}

if (!empty($filterDate)) {
    $sql .= " AND j.date = :date";
    $params[':date'] = $filterDate;
}

if (!empty($search)) {
    $sql .= " AND (j.action LIKE :search OR u.nomUser LIKE :search)";
    $params[':search'] = "%{$search}%";
}

if (!empty($filterAction)) {
    $sql .= " AND j.action LIKE :actionType";
    $params[':actionType'] = "%{$filterAction}%";
}

// Count total
$countSql = "SELECT COUNT(*) as total FROM ({$sql}) as subquery";
$countStmt = $db->prepare($countSql);
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value);
}
$countStmt->execute();
$totalRecords = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalRecords / $perPage);

// Get records
$sql .= " ORDER BY j.idJournal DESC LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Liste des utilisateurs pour le filtre
$sqlUsers = "SELECT DISTINCT u.idUser, u.nomUser FROM user u 
             INNER JOIN journal j ON u.idUser = j.idUser 
             ORDER BY u.nomUser";
$stmtUsers = $db->prepare($sqlUsers);
$stmtUsers->execute();
$users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

// Statistiques
$statsLogin = $db->query("SELECT COUNT(*) as total FROM journal WHERE action LIKE '%تسجيل الدخول%'")->fetch()['total'];
$statsLogout = $db->query("SELECT COUNT(*) as total FROM journal WHERE action LIKE '%تسجيل الخروج%'")->fetch()['total'];
$statsAjout = $db->query("SELECT COUNT(*) as total FROM journal WHERE action LIKE '%إضافة%'")->fetch()['total'];
$statsSupp = $db->query("SELECT COUNT(*) as total FROM journal WHERE action LIKE '%حذف%'")->fetch()['total'];
$statsModif = $db->query("SELECT COUNT(*) as total FROM journal WHERE action LIKE '%تعديل%'")->fetch()['total'];

$page_title = "لوحة الإدارة - سجل الأنشطة";
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
            background: linear-gradient(135deg, #FF6B35 0%, #F7931E 100%);
            color: white;
            padding: 40px;
            border-radius: 15px;
            margin-bottom: 30px;
            text-align: center;
            box-shadow: 0 8px 25px rgba(255, 107, 53, 0.3);
        }
        
        .admin-header h2 {
            font-size: 32px;
            margin-bottom: 10px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .admin-header p {
            font-size: 16px;
            opacity: 0.95;
        }
        
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card-admin {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 3px 15px rgba(0,0,0,0.08);
            text-align: center;
            transition: transform 0.3s;
        }
        
        .stat-card-admin:hover {
            transform: translateY(-5px);
        }
        
        .stat-card-admin .icon {
            font-size: 40px;
            margin-bottom: 15px;
        }
        
        .stat-card-admin .number {
            font-size: 36px;
            font-weight: bold;
            margin-bottom: 8px;
        }
        
        .stat-card-admin .label {
            color: #666;
            font-size: 14px;
        }
        
        .stat-card-admin.login { border-top: 4px solid #4caf50; }
        .stat-card-admin.login .icon { color: #4caf50; }
        .stat-card-admin.login .number { color: #4caf50; }
        
        .stat-card-admin.logout { border-top: 4px solid #ff9800; }
        .stat-card-admin.logout .icon { color: #ff9800; }
        .stat-card-admin.logout .number { color: #ff9800; }
        
        .stat-card-admin.add { border-top: 4px solid #2196F3; }
        .stat-card-admin.add .icon { color: #2196F3; }
        .stat-card-admin.add .number { color: #2196F3; }
        
        .stat-card-admin.delete { border-top: 4px solid #f44336; }
        .stat-card-admin.delete .icon { color: #f44336; }
        .stat-card-admin.delete .number { color: #f44336; }
        
        .stat-card-admin.edit { border-top: 4px solid #9c27b0; }
        .stat-card-admin.edit .icon { color: #9c27b0; }
        .stat-card-admin.edit .number { color: #9c27b0; }
        
        .filters-panel {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 3px 15px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }
        
        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #FF6B35;
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        .btn {
            padding: 10px 25px;
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
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 107, 53, 0.4);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .btn-export {
            background: #28a745;
            color: white;
        }
        
        .btn-export:hover {
            background: #218838;
        }
        
        .logs-table {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 3px 15px rgba(0,0,0,0.08);
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
        
        th {
            padding: 15px;
            text-align: center;
            font-weight: 600;
        }
        
        td {
            padding: 12px;
            text-align: center;
            border-bottom: 1px solid #f0f0f0;
        }
        
        tbody tr:hover {
            background: #f8f9fa;
        }
        
        .action-badge {
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .action-login {
            background: #d4edda;
            color: #155724;
        }
        
        .action-logout {
            background: #fff3cd;
            color: #856404;
        }
        
        .action-add {
            background: #cce5ff;
            color: #004085;
        }
        
        .action-delete {
            background: #f8d7da;
            color: #721c24;
        }
        
        .action-edit {
            background: #e2d9f3;
            color: #6f42c1;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }
        
        .pagination a,
        .pagination span {
            padding: 8px 15px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            text-decoration: none;
            color: #333;
            transition: all 0.3s;
        }
        
        .pagination a:hover {
            background: #FF6B35;
            color: white;
            border-color: #FF6B35;
        }
        
        .pagination .active {
            background: #FF6B35;
            color: white;
            border-color: #FF6B35;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #999;
        }
        
        .submenu {
            position: relative;
            display: inline-block;
        }
        
        .submenu-content {
            display: none;
            position: absolute;
            background-color: white;
            min-width: 200px;
            box-shadow: 0 8px 16px rgba(255, 107, 53, 0.2);
            border-radius: 8px;
            z-index: 1;
            top: 100%;
            right: 0;
            margin-top: 5px;
            border-top: 3px solid #FF6B35;
        }
        
        .submenu:hover .submenu-content {
            display: block;
        }
        
        .submenu-content a {
            color: #333;
            padding: 12px 16px;
            text-decoration: none;
            display: block;
            transition: background 0.3s;
        }
        
        .submenu-content a:hover {
            background: linear-gradient(135deg, #FF6B35 0%, #F7931E 100%);
            color: white;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <section class="content-section" style="padding: 40px 0;">
        <div class="container">
            <div class="admin-header">
                <h2>📋 لوحة الإدارة</h2>
                <p>سجل جميع الأنشطة والعمليات في النظام</p>
            </div>
            
            <!-- Statistics Cards -->
            <div class="stats-cards">
                <div class="stat-card-admin login">
                    <div class="icon">🔓</div>
                    <div class="number"><?php echo $statsLogin; ?></div>
                    <div class="label">عمليات الدخول</div>
                </div>
                <div class="stat-card-admin logout">
                    <div class="icon">🔒</div>
                    <div class="number"><?php echo $statsLogout; ?></div>
                    <div class="label">عمليات الخروج</div>
                </div>
                <div class="stat-card-admin add">
                    <div class="icon">➕</div>
                    <div class="number"><?php echo $statsAjout; ?></div>
                    <div class="label">عمليات الإضافة</div>
                </div>
                <div class="stat-card-admin delete">
                    <div class="icon">🗑️</div>
                    <div class="number"><?php echo $statsSupp; ?></div>
                    <div class="label">عمليات الحذف</div>
                </div>
                <div class="stat-card-admin edit">
                    <div class="icon">✏️</div>
                    <div class="number"><?php echo $statsModif; ?></div>
                    <div class="label">عمليات التعديل</div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="filters-panel">
                <form method="GET" action="">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label>🔍 البحث</label>
                            <input type="text" name="search" placeholder="ابحث في الأنشطة..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="filter-group">
                            <label>👤 المستخدم</label>
                            <select name="user">
                                <option value="">جميع المستخدمين</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo $user['idUser']; ?>" 
                                            <?php echo $filterUser == $user['idUser'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($user['nomUser']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>📅 التاريخ</label>
                            <input type="date" name="date" value="<?php echo htmlspecialchars($filterDate); ?>">
                        </div>
                        <div class="filter-group">
                            <label>🏷️ نوع العملية</label>
                            <select name="action_type">
                                <option value="">جميع العمليات</option>
                                <option value="تسجيل الدخول" <?php echo $filterAction == 'تسجيل الدخول' ? 'selected' : ''; ?>>
                                    تسجيل الدخول
                                </option>
                                <option value="تسجيل الخروج" <?php echo $filterAction == 'تسجيل الخروج' ? 'selected' : ''; ?>>
                                    تسجيل الخروج
                                </option>
                                <option value="إضافة" <?php echo $filterAction == 'إضافة' ? 'selected' : ''; ?>>
                                    إضافة
                                </option>
                                <option value="حذف" <?php echo $filterAction == 'حذف' ? 'selected' : ''; ?>>
                                    حذف
                                </option>
                                <option value="تعديل" <?php echo $filterAction == 'تعديل' ? 'selected' : ''; ?>>
                                    تعديل
                                </option>
                            </select>
                        </div>
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">🔍 بحث</button>
                        <a href="administration.php" class="btn btn-secondary">🔄 إعادة تعيين</a>
                        <a href="export_logs.php" class="btn btn-export">📥 تصدير Excel</a>
                    </div>
                </form>
            </div>
            
            <!-- Logs Table -->
            <div class="logs-table">
                <h3 style="margin-bottom: 20px;">سجل الأنشطة (<?php echo $totalRecords; ?> عملية)</h3>
                
                <?php if (count($logs) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>المستخدم</th>
                                <th>البريد الإلكتروني</th>
                                <th>العملية</th>
                                <th>التاريخ</th>
                                <th>النوع</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?php echo $log['idJournal']; ?></td>
                                    <td><?php echo htmlspecialchars($log['nomUser']); ?></td>
                                    <td><?php echo htmlspecialchars($log['emailUser']); ?></td>
                                    <td style="text-align: right;">
                                        <?php echo htmlspecialchars($log['action']); ?>
                                    </td>
                                    <td><?php echo date('Y/m/d', strtotime($log['date'])); ?></td>
                                    <td>
                                        <?php
                                        $badgeClass = 'action-login';
                                        $badgeText = 'عملية';
                                        
                                        if (strpos($log['action'], 'تسجيل الدخول') !== false) {
                                            $badgeClass = 'action-login';
                                            $badgeText = 'دخول';
                                        } elseif (strpos($log['action'], 'تسجيل الخروج') !== false) {
                                            $badgeClass = 'action-logout';
                                            $badgeText = 'خروج';
                                        } elseif (strpos($log['action'], 'إضافة') !== false) {
                                            $badgeClass = 'action-add';
                                            $badgeText = 'إضافة';
                                        } elseif (strpos($log['action'], 'حذف') !== false) {
                                            $badgeClass = 'action-delete';
                                            $badgeText = 'حذف';
                                        } elseif (strpos($log['action'], 'تعديل') !== false) {
                                            $badgeClass = 'action-edit';
                                            $badgeText = 'تعديل';
                                        }
                                        ?>
                                        <span class="action-badge <?php echo $badgeClass; ?>">
                                            <?php echo $badgeText; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?page=1<?php echo !empty($search) ? '&search='.$search : ''; 
                                    echo !empty($filterUser) ? '&user='.$filterUser : ''; 
                                    echo !empty($filterDate) ? '&date='.$filterDate : ''; 
                                    echo !empty($filterAction) ? '&action_type='.$filterAction : ''; ?>">
                                    « الأولى
                                </a>
                                <a href="?page=<?php echo $page-1; ?><?php echo !empty($search) ? '&search='.$search : ''; 
                                    echo !empty($filterUser) ? '&user='.$filterUser : ''; 
                                    echo !empty($filterDate) ? '&date='.$filterDate : ''; 
                                    echo !empty($filterAction) ? '&action_type='.$filterAction : ''; ?>">
                                    ‹ السابق
                                </a>
                            <?php endif; ?>
                            
                            <?php
                            $start = max(1, $page - 2);
                            $end = min($totalPages, $page + 2);
                            
                            for ($i = $start; $i <= $end; $i++):
                            ?>
                                <?php if ($i == $page): ?>
                                    <span class="active"><?php echo $i; ?></span>
                                <?php else: ?>
                                    <a href="?page=<?php echo $i; ?><?php echo !empty($search) ? '&search='.$search : ''; 
                                        echo !empty($filterUser) ? '&user='.$filterUser : ''; 
                                        echo !empty($filterDate) ? '&date='.$filterDate : ''; 
                                        echo !empty($filterAction) ? '&action_type='.$filterAction : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endif; ?>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?php echo $page+1; ?><?php echo !empty($search) ? '&search='.$search : ''; 
                                    echo !empty($filterUser) ? '&user='.$filterUser : ''; 
                                    echo !empty($filterDate) ? '&date='.$filterDate : ''; 
                                    echo !empty($filterAction) ? '&action_type='.$filterAction : ''; ?>">
                                    التالي ›
                                </a>
                                <a href="?page=<?php echo $totalPages; ?><?php echo !empty($search) ? '&search='.$search : ''; 
                                    echo !empty($filterUser) ? '&user='.$filterUser : ''; 
                                    echo !empty($filterDate) ? '&date='.$filterDate : ''; 
                                    echo !empty($filterAction) ? '&action_type='.$filterAction : ''; ?>">
                                    الأخيرة »
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <div class="no-data">
                        <p>🔭 لا توجد سجلات لعرضها</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php include 'includes/footer.php'; ?>

    <script>
        let inactivityTime = function () {
            let time;
            window.onload = resetTimer;
            document.onmousemove = resetTimer;
            document.onkeypress = resetTimer;

            function logout() {
                window.location.href = '../logout.php';
            }

            function resetTimer() {
                clearTimeout(time);
                time = setTimeout(logout, 1800000);
            }
        };
        inactivityTime();
    </script>
</body>
</html>