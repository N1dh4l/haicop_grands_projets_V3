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
                    <li><a href="appels_d_offres.php">الصفقات</a></li>
                    <li class="has-submenu">
                        <a href="javascript:void(0)" class="submenu-toggle">الإدارة ▼</a>
                        <ul class="submenu-content">

                            <li><a href="administration.php">📊 سجل الأنشطة</a></li>
                            <li><a href="gestion_users.php">👥  المستخدمين</a></li>
                            <li><a href="gestion_users.php"> الوزارات </a></li>
                            <li><a href="gestion_users.php">المؤسسات</a></li>
                            <li><a href="parametres.php">⚙️ الإعدادات</a></li>
                        </ul>
                    </li>
                </ul>
            </nav>
            <div class="user-menu">
                <span class="user-name"><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                <a href="../logout.php" class="btn-logout">تسجيل الخروج</a>
            </div>
        </div>
    </div>
</header>

<style>
    /* ========================================== */
    /* STYLES POUR LE MENU DÉROULANT */
    /* ========================================== */

    .main-header {
        background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        position: sticky;
        top: 0;
        z-index: 1000;
    }

    .container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 0 20px;
    }

    .header-content {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px 0;
    }

    .logo h1 {
        color: #ffd700;
        font-size: 22px;
        margin: 0 0 5px 0;
        font-weight: bold;
    }

    .logo h3 {
        color: white;
        font-size: 18px;
        margin: 0 0 3px 0;
    }

    .logo p {
        color: #e0e0e0;
        font-size: 14px;
        margin: 0;
    }

    /* NAVIGATION PRINCIPALE */
    .main-nav ul {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        gap: 5px;
    }

    .main-nav > ul > li {
        position: relative;
    }

    .main-nav a {
        color: white;
        text-decoration: none;
        padding: 12px 20px;
        display: block;
        font-size: 16px;
        font-weight: 500;
        border-radius: 8px;
        transition: all 0.3s ease;
    }

    .main-nav > ul > li > a:hover {
        background: rgba(255, 255, 255, 0.15);
        transform: translateY(-2px);
    }

    /* MENU DÉROULANT */
    .has-submenu {
        position: relative;
    }

    .submenu-toggle {
        cursor: pointer;
        user-select: none;
    }

    .submenu-content {
        position: absolute;
        top: 100%;
        right: 0;
        background: white;
        min-width: 250px;
        border-radius: 8px;
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        opacity: 0;
        visibility: hidden;
        transform: translateY(-10px);
        transition: all 0.3s ease;
        margin-top: 5px;
        padding: 8px 0;
        list-style: none;
        z-index: 1000;
    }

    .has-submenu:hover .submenu-content {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
    }

    .submenu-content li {
        margin: 0;
        padding: 0;
    }

    .submenu-content a {
        color: #333;
        padding: 12px 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 15px;
        border-radius: 0;
        transition: all 0.2s ease;
    }

    .submenu-content a:hover {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        transform: translateX(-5px);
    }

    /* Flèche indicative */
    .submenu-content::before {
        content: '';
        position: absolute;
        top: -8px;
        right: 20px;
        width: 0;
        height: 0;
        border-left: 8px solid transparent;
        border-right: 8px solid transparent;
        border-bottom: 8px solid white;
    }

    /* USER MENU */
    .user-menu {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .user-name {
        color: white;
        font-weight: 500;
        padding: 8px 15px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 20px;
        font-size: 14px;
    }

    .btn-logout {
        background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
        color: white;
        padding: 10px 20px;
        border-radius: 8px;
        text-decoration: none;
        font-weight: 600;
        font-size: 14px;
        transition: all 0.3s ease;
        box-shadow: 0 4px 15px rgba(255, 107, 107, 0.3);
    }

    .btn-logout:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(255, 107, 107, 0.4);
    }

    /* RESPONSIVE */
    @media (max-width: 1200px) {
        .header-content {
            flex-direction: column;
            gap: 15px;
        }
        
        .main-nav ul {
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .main-nav a {
            padding: 10px 15px;
            font-size: 14px;
        }
    }

    @media (max-width: 768px) {
        .logo h1 {
            font-size: 18px;
        }
        
        .logo h3 {
            font-size: 16px;
        }
        
        .main-nav ul {
            flex-direction: column;
            width: 100%;
        }
        
        .main-nav > ul > li {
            width: 100%;
        }
        
        .main-nav a {
            text-align: center;
        }
        
        .submenu-content {
            position: static;
            transform: none;
            margin-top: 5px;
            width: 100%;
        }
        
        .has-submenu:hover .submenu-content {
            transform: none;
        }
        
        .submenu-content::before {
            display: none;
        }
    }

    /* ANIMATION D'ENTRÉE */
    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .has-submenu:hover .submenu-content {
        animation: slideDown 0.3s ease;
    }
</style>

<script>
    // JavaScript optionnel pour améliorer l'accessibilité mobile
    document.addEventListener('DOMContentLoaded', function() {
        const submenuToggles = document.querySelectorAll('.submenu-toggle');
        
        submenuToggles.forEach(toggle => {
            toggle.addEventListener('click', function(e) {
                // Sur mobile, empêcher le comportement par défaut
                if (window.innerWidth <= 768) {
                    e.preventDefault();
                    const submenu = this.nextElementSibling;
                    
                    // Toggle la visibilité du sous-menu
                    if (submenu.style.display === 'block') {
                        submenu.style.display = 'none';
                    } else {
                        // Fermer les autres sous-menus
                        document.querySelectorAll('.submenu-content').forEach(sm => {
                            sm.style.display = 'none';
                        });
                        submenu.style.display = 'block';
                    }
                }
            });
        });
        
        // Fermer les sous-menus en cliquant ailleurs
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.has-submenu')) {
                document.querySelectorAll('.submenu-content').forEach(sm => {
                    sm.style.display = '';
                });
            }
        });
    });
</script>