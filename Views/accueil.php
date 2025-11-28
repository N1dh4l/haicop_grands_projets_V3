<?php
    require_once '../Config/Database.php';
    require_once '../Config/Security.php';
    require_once '../Models/Dashboard.php';

        Security::startSecureSession();
        Security::requireLogin();

        // Timeout session (30 minutes)
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
            Security::logout();
        }
        $_SESSION['last_activity'] = time();

        $database = new Database();
        $db = $database->getConnection();
        $dashboard = new Dashboard($db);

        $stats = $dashboard->getStats();
        $commission_data = $dashboard->getCommissionProjects();

        $page_title = "لوحة التحكم - نظام إدارة المشاريع";
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
        .submenu {
            position: relative;
            display: inline-block;
        }
        
        .submenu-content {
            display: none;
            position: absolute;
            background-color: white;
            min-width: 200px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.2);
            border-radius: 8px;
            z-index: 1;
            top: 100%;
            right: 0;
            margin-top: 5px;
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
            background-color: #f1f1f1;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <?php include 'includes/header.php'; ?>

    <!-- Hero Section with Stats -->
    <section class="hero-section">
        <div class="container">
            <div class="stats-grid">
                <div class="stat-card red">
                    <div class="stat-number"><?php echo $stats['total_projets']; ?></div>
                    <div class="stat-label">العدد الجملي للمقترحات</div>
                </div>
                <div class="stat-card cyan">
                    <div class="stat-number"><?php echo $stats['projets_attente']; ?></div>
                    <div class="stat-label">بصدد الدرس</div>
                </div>
                <div class="stat-card pink">
                    <div class="stat-number"><?php echo $stats['projets_encours']; ?></div>
                    <div class="stat-label">الإحالة على اللجنة</div>
                </div>
                <div class="stat-card yellow">
                    <div class="stat-number"><?php echo $stats['appels_offre']; ?></div>
                    <div class="stat-label">الموافقة</div>
                </div>
                <div class="stat-card orange">
                    <div class="stat-number"><?php echo $stats['commissions']; ?></div>
                    <div class="stat-label">عدم الموافقة</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Commission Table Section -->
    <section class="content-section">
        <div class="container">
            <h2 class="section-title">مقرري اللجنة</h2>
            <div class="commission-table">
                <table>
                    <thead>
                        <tr>
                            <th>الوزارة</th>
                            <th>المؤسسة</th>
                            <th>العدد</th>
                            <th>العدد الجملي</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>-</td>
                            <td>-</td>
                            <td><?php echo $commission_data['total_programme']; ?></td>
                            <td>14</td>
                        </tr>
                        <tr>
                            <td>-</td>
                            <td>-</td>
                            <td><?php echo $commission_data['total_extraordinaire']; ?></td>
                            <td>30</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="content-section" style="background: white; padding: 80px 0;">
        <div class="container">
            <h2 class="section-title">القائمات</h2>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-number">01</div>
                    <div class="feature-icon">📋</div>
                    <h3 class="feature-title">المقترحات</h3>
                    <p class="feature-desc"> المقترحات المعروضة على اللجنة</p>
                </div>
                <div class="feature-card">
                    <div class="feature-number">02</div>
                    <div class="feature-icon">🏢</div>
                    <h3 class="feature-title">الجلسات</h3>
                    <p class="feature-desc"> الجلسات المعروضة على اللجنة</p>
                </div>
                <div class="feature-card">
                    <div class="feature-number">03</div>
                    <div class="feature-icon">🛒</div>
                    <h3 class="feature-title">الصفقات</h3>
                    <p class="feature-desc"> الصفقات التي تم ابرامها</p>
                </div>
                <div class="feature-card">
                    <div class="feature-number">04</div>
                    <div class="feature-icon">🚚</div>
                    <h3 class="feature-title">المتابعة</h3>
                    <p class="feature-desc">متابعة المقترحات</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <?php include 'includes/footer.php'; ?>

    <script>
        // Timeout automatique après 30 minutes d'inactivité
        let inactivityTime = function () {
            let time;
            window.onload = resetTimer;
            document.onmousemove = resetTimer;
            document.onkeypress = resetTimer;
            document.onclick = resetTimer;
            document.onscroll = resetTimer;

            function logout() {
                window.location.href = '../logout.php';
            }

            function resetTimer() {
                clearTimeout(time);
                time = setTimeout(logout, 1800000); // 30 minutes
            }
        };

        inactivityTime();

        // Animation au chargement
        window.addEventListener('load', function() {
            document.querySelectorAll('.stat-card').forEach((card, index) => {
                setTimeout(() => {
                    card.style.animation = 'slideUp 0.5s ease forwards';
                }, index * 100);
            });
        });
    </script>
</body>
</html>