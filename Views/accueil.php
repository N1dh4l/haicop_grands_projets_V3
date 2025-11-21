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
</head>
<body>
    <!-- Header -->
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
                        <li><a href="projets.php">المقترحات</a></li>
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