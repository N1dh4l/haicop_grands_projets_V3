<?php
    require_once '../Config/Database.php';
    require_once '../Config/Security.php';
    require_once '../Models/User.php';
    require_once '../Controllers/AuthController.php';

    Security::startSecureSession();

    // Si déjà connecté, rediriger
    if (Security::isLoggedIn()) {
        header("Location: accueil.php");
        exit();
    }

    $error_message = '';
    $success_message = '';

    // Traitement formulaire
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $authController = new AuthController();
        $result = $authController->login(
            $_POST['login'],
            $_POST['password'],
            $_POST['csrf_token']
        );
        
        if ($result['success']) {
            header("Location: accueil.php");
            exit();
        } else {
            $error_message = $result['message'];
        }
    }

    $csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول - رئاسة الحكومة</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-header">
            <h1>الجمهورية التونسية</h1>
            <h4>الهيئة العليا للطلب العمومي</h4>
            <p>لجنة المشاريع الكبري</p>
        </div>
        <div class="login-body">
            <?php if ($error_message): ?>
                <div class="alert alert-error"><?php echo $error_message; ?></div>
            <?php endif; ?>

            <form method="POST" action="" id="loginForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <div class="form-group">
                    <label for="login">اسم المستخدم</label>
                    <input type="text" 
                           class="form-control" 
                           id="login" 
                           name="login" 
                           required 
                           autocomplete="username"
                           placeholder="أدخل اسم المستخدم">
                </div>

                <div class="form-group">
                    <label for="password">كلمة المرور</label>
                    <input type="password" 
                           class="form-control" 
                           id="password" 
                           name="password" 
                           required 
                           autocomplete="current-password"
                           placeholder="أدخل كلمة المرور">
                </div>

                <div class="remember-forgot">
                    <label>
                        <input type="checkbox" name="remember"> تذكرني
                    </label>
                    <a href="forgot-password.php">نسيت كلمة المرور؟</a>
                </div>

                <button type="submit" class="btn-login">تسجيل الدخول</button>

                <div class="security-note">
                    <i>🔒</i> اتصال آمن ومشفر
                </div>
            </form>
        </div>
    </div>

    <script>
        // Protection XSS basique côté client
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const login = document.getElementById('login').value;
            const password = document.getElementById('password').value;
            
            if (login.length < 3 || password.length < 6) {
                e.preventDefault();
                alert('يرجى إدخال بيانات صحيحة');
            }
        });
    </script>
</body>
</html>