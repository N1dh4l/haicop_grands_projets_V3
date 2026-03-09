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
    $page_title = "إعدادات الحساب - رئاسة الحكومة";
    
    $idUser = $_SESSION['user_id'];

    // Traitement de la modification du profil
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                throw new Exception('خطأ في رمز الأمان');
            }
            
            $nomUser   = isset($_POST['nomUser'])   ? trim($_POST['nomUser'])   : '';
            $emailUser = isset($_POST['emailUser']) ? trim($_POST['emailUser']) : '';
            $login     = isset($_POST['login'])     ? trim($_POST['login'])     : '';
            $typeCpt   = isset($_POST['typeCpt'])   ? intval($_POST['typeCpt']) : null;

            if (empty($nomUser)) throw new Exception('الاسم مطلوب');
            if (empty($emailUser)) throw new Exception('البريد الإلكتروني مطلوب');
            if (!filter_var($emailUser, FILTER_VALIDATE_EMAIL)) throw new Exception('البريد الإلكتروني غير صالح');
            if (empty($login)) throw new Exception('اسم الدخول مطلوب');
            if (strlen($login) > 20) throw new Exception('اسم الدخول يجب أن يكون أقل من 20 حرفًا');

            // Valider typeCpt si fourni (1=directeur, 2=admin, 3=rapporteur)
            $allowedTypes = [1, 2, 3];
            if ($typeCpt !== null && !in_array($typeCpt, $allowedTypes)) {
                throw new Exception('نوع الحساب غير صالح');
            }

            // Vérifier login unique
            $checkStmt = $db->prepare("SELECT COUNT(*) as count FROM user WHERE login = :login AND idUser != :idUser");
            $checkStmt->execute([':login' => $login, ':idUser' => $idUser]);
            if ($checkStmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) throw new Exception('اسم الدخول موجود مسبقا');

            // Vérifier email unique
            $checkEmailStmt = $db->prepare("SELECT COUNT(*) as count FROM user WHERE emailUser = :email AND idUser != :idUser");
            $checkEmailStmt->execute([':email' => $emailUser, ':idUser' => $idUser]);
            if ($checkEmailStmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) throw new Exception('البريد الإلكتروني موجود مسبقا');

            // Construire la requête UPDATE selon si typeCpt est modifié
            if ($typeCpt !== null) {
                $query = "UPDATE user SET nomUser=:nomUser, emailUser=:emailUser, login=:login, typeCpt=:typeCpt WHERE idUser=:idUser";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':typeCpt', $typeCpt, PDO::PARAM_INT);
            } else {
                $query = "UPDATE user SET nomUser=:nomUser, emailUser=:emailUser, login=:login WHERE idUser=:idUser";
                $stmt = $db->prepare($query);
            }
            $stmt->bindParam(':nomUser',   $nomUser);
            $stmt->bindParam(':emailUser', $emailUser);
            $stmt->bindParam(':login',     $login);
            $stmt->bindParam(':idUser',    $idUser);
            $stmt->execute();

            // Journal
            $action = "تحديث معلومات الملف الشخصي" . ($typeCpt !== null ? " (تغيير نوع الحساب)" : "");
            $stmtJournal = $db->prepare("INSERT INTO journal (idUser, action, date) VALUES (:idUser, :action, NOW())");
            $stmtJournal->bindParam(':idUser',  $idUser);
            $stmtJournal->bindParam(':action',  $action);
            $stmtJournal->execute();

            // Mettre à jour la session
            $_SESSION['user_name']  = $nomUser;
            $_SESSION['user_email'] = $emailUser;
            $_SESSION['user_login'] = $login;
            if ($typeCpt !== null) {
                $_SESSION['user_type'] = $typeCpt;
            }

            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'تم تحديث معلومات الملف الشخصي بنجاح'], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    // Traitement du changement de mot de passe
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                throw new Exception('خطأ في رمز الأمان');
            }
            
            $currentPassword = isset($_POST['currentPassword']) ? $_POST['currentPassword'] : '';
            $newPassword = isset($_POST['newPassword']) ? $_POST['newPassword'] : '';
            $confirmPassword = isset($_POST['confirmPassword']) ? $_POST['confirmPassword'] : '';
            
            if (empty($currentPassword)) {
                throw new Exception('كلمة المرور الحالية مطلوبة');
            }
            if (empty($newPassword)) {
                throw new Exception('كلمة المرور الجديدة مطلوبة');
            }
            if (strlen($newPassword) < 6) {
                throw new Exception('كلمة المرور الجديدة يجب أن تكون 6 أحرف على الأقل');
            }
            if ($newPassword !== $confirmPassword) {
                throw new Exception('كلمة المرور الجديدة وتأكيدها غير متطابقين');
            }
            
            // Vérifier le mot de passe actuel
            $query = "SELECT pw FROM user WHERE idUser = :idUser";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':idUser', $idUser);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user || !Security::verifyPassword($currentPassword, $user['pw'])) {
                throw new Exception('كلمة المرور الحالية غير صحيحة');
            }
            
            // Mettre à jour le mot de passe
            $hashedPassword = Security::hashPassword($newPassword);
            $updateQuery = "UPDATE user SET pw = :pw WHERE idUser = :idUser";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->bindParam(':pw', $hashedPassword);
            $updateStmt->bindParam(':idUser', $idUser);
            $updateStmt->execute();
            
            // Journal
            $action = "تغيير كلمة المرور";
            $queryJournal = "INSERT INTO journal (idUser, action, date) VALUES (:idUser, :action, NOW())";
            $stmtJournal = $db->prepare($queryJournal);
            $stmtJournal->bindParam(':idUser', $idUser);
            $stmtJournal->bindParam(':action', $action);
            $stmtJournal->execute();
            
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'تم تغيير كلمة المرور بنجاح'], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit();
    }

    // Récupérer les informations de l'utilisateur
    $query = "SELECT nomUser, emailUser, login, typeCpt FROM user WHERE idUser = :idUser";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':idUser', $idUser);
    $stmt->execute();
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
            direction: rtl;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .admin-header {
            background: linear-gradient(135deg, #4a90e2 0%, #357abd 100%);
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
        
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 30px;
            margin-bottom: 30px;
        }
        
        @media (max-width: 768px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .settings-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        
        .card-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .card-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #4a90e2 0%, #357abd 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .card-title {
            font-size: 22px;
            font-weight: 700;
            color: #333;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #4a90e2;
            box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.1);
        }
        
        .form-control:disabled {
            background: #f5f5f5;
            cursor: not-allowed;
        }
        
        .required {
            color: #dc3545;
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
            background: linear-gradient(135deg, #4a90e2 0%, #357abd 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(74, 144, 226, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(74, 144, 226, 0.4);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #4caf50 0%, #45a049 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.3);
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(76, 175, 80, 0.4);
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
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
        
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .info-box {
            background: white;
            border-left: 4px solid #4a90e2;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .info-box h3 {
            color: #2c3e50;
            font-size: 18px;
            margin-bottom: 10px;
        }
        
        .info-box p {
            color: #34495e;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .user-type-badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            margin-top: 10px;
        }
        
        .badge-admin {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }
        
        .badge-user {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
        }
        
        .password-requirements {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 15px;
            margin-top: 10px;
            font-size: 13px;
            color: #856404;
        }
        
        .password-requirements ul {
            margin: 10px 0 0 20px;
            padding: 0;
        }
        
        .password-requirements li {
            margin: 5px 0;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <section class="content-section" style="padding: 40px 0;">
        <div class="container">
            <div class="admin-header">
                <h2>⚙️ إعدادات الحساب</h2>
                <p>إدارة معلومات ملفك الشخصي وكلمة المرور</p>
            </div>
            <div class="settings-grid">
                <!-- Card 1: Informations du profil -->
                <div class="settings-card">
                    <div class="card-header">
                        <div class="card-icon">📝</div>
                        <h3 class="card-title">تحديث المعلومات الشخصية</h3>
                    </div>
                    
                    <div id="profileAlert"></div>
                    
                    <form id="profileForm">
                        <input type="hidden" name="action" value="update_profile">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <div class="form-group">
                            <label>الاسم الكامل <span class="required">*</span></label>
                            <input type="text" name="nomUser" class="form-control" value="<?php echo htmlspecialchars($userData['nomUser']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>البريد الإلكتروني <span class="required">*</span></label>
                            <input type="email" name="emailUser" class="form-control" value="<?php echo htmlspecialchars($userData['emailUser']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>اسم الدخول <span class="required">*</span></label>
                            <input type="text" name="login" class="form-control" value="<?php echo htmlspecialchars($userData['login']); ?>" maxlength="20" required>
                        </div>
                        
                        <div class="form-group">
                            <label>نوع الحساب</label>
                            <select name="typeCpt" class="form-control">
                                <option value="1" <?php echo $userData['typeCpt'] == 1 ? 'selected' : ''; ?>>مدير (صلاحيات كاملة)</option>
                                <option value="2" <?php echo $userData['typeCpt'] == 2 ? 'selected' : ''; ?>>مسؤول</option>
                                <option value="3" <?php echo $userData['typeCpt'] == 3 ? 'selected' : ''; ?>>مقرر (مشاهدة فقط)</option>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">💾 حفظ التغييرات</button>
                    </form>
                </div>
                
                <!-- Card 2: Changement de mot de passe -->
                <div class="settings-card">
                    <div class="card-header">
                        <div class="card-icon">🔐</div>
                        <h3 class="card-title">تغيير كلمة المرور</h3>
                    </div>
                    
                    <div id="passwordAlert"></div>
                    
                    <form id="passwordForm">
                        <input type="hidden" name="action" value="change_password">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <div class="form-group">
                            <label>كلمة المرور الحالية <span class="required">*</span></label>
                            <input type="password" name="currentPassword" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label>كلمة المرور الجديدة <span class="required">*</span></label>
                            <input type="password" name="newPassword" id="newPassword" class="form-control" minlength="6" required>
                        </div>
                        
                        <div class="form-group">
                            <label>تأكيد كلمة المرور الجديدة <span class="required">*</span></label>
                            <input type="password" name="confirmPassword" id="confirmPassword" class="form-control" minlength="6" required>
                        </div>
                        
                        <div class="password-requirements ">
                            <strong>⚠️ متطلبات كلمة المرور:</strong>
                            <ul>
                                <li>يجب أن تكون 6 أحرف على الأقل</li>
                                <li>يُنصح باستخدام مزيج من الأحرف والأرقام</li>
                                <li>تجنب استخدام كلمات مرور سهلة التخمين</li>
                            </ul>
                        </div>
                        <div class="form-group" style="margin-top: 20px;">
                            <button type="submit" class="btn btn-success">🔒 تغيير كلمة المرور</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <script>
        // Form de mise à jour du profil
        document.getElementById('profileForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            
            var formData = new FormData(this);
            var alertDiv = document.getElementById('profileAlert');
            
            alertDiv.innerHTML = '<div style="text-align: center; padding: 15px;"><div style="display: inline-block; border: 3px solid #f3f3f3; border-top: 3px solid #4a90e2; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite;"></div><p style="margin-top: 10px;">جاري الحفظ...</p></div>';
            
            fetch('parametres.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alertDiv.innerHTML = '<div class="alert alert-success">✓ ' + data.message + '</div>';
                    setTimeout(function() {
                        alertDiv.innerHTML = '';
                    }, 3000);
                } else {
                    alertDiv.innerHTML = '<div class="alert alert-error">✕ ' + data.message + '</div>';
                }
            })
            .catch(function(error) {
                console.error('Error:', error);
                alertDiv.innerHTML = '<div class="alert alert-error">✕ حدث خطأ في الاتصال</div>';
            });
        });

        // Form de changement de mot de passe
        document.getElementById('passwordForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            
            var newPassword = document.getElementById('newPassword').value;
            var confirmPassword = document.getElementById('confirmPassword').value;
            var alertDiv = document.getElementById('passwordAlert');
            
            if (newPassword !== confirmPassword) {
                alertDiv.innerHTML = '<div class="alert alert-error">✕ كلمة المرور الجديدة وتأكيدها غير متطابقين</div>';
                return;
            }
            
            var formData = new FormData(this);
            
            alertDiv.innerHTML = '<div style="text-align: center; padding: 15px;"><div style="display: inline-block; border: 3px solid #f3f3f3; border-top: 3px solid #4caf50; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite;"></div><p style="margin-top: 10px;">جاري التحديث...</p></div>';
            
            fetch('parametres.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alertDiv.innerHTML = '<div class="alert alert-success">✓ ' + data.message + '</div>';
                    document.getElementById('passwordForm').reset();
                    setTimeout(function() {
                        alertDiv.innerHTML = '';
                    }, 3000);
                } else {
                    alertDiv.innerHTML = '<div class="alert alert-error">✕ ' + data.message + '</div>';
                }
            })
            .catch(function(error) {
                console.error('Error:', error);
                alertDiv.innerHTML = '<div class="alert alert-error">✕ حدث خطأ في الاتصال</div>';
            });
        });
        
        // Validation en temps réel des mots de passe
        document.getElementById('confirmPassword')?.addEventListener('input', function() {
            var newPassword = document.getElementById('newPassword').value;
            var confirmPassword = this.value;
            
            if (confirmPassword && newPassword !== confirmPassword) {
                this.style.borderColor = '#dc3545';
            } else {
                this.style.borderColor = '#4caf50';
            }
        });
    </script>
</body>
</html>