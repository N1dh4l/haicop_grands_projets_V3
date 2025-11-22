<?php
require_once '../Config/Database.php';
require_once '../Config/Security.php';

Security::startSecureSession();

// Vérifier l'authentification
if (!Security::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Non authentifié']);
    exit();
}

header('Content-Type: application/json');

$database = new Database();
$db = $database->getConnection();

$ministereId = isset($_GET['ministere']) ? intval($_GET['ministere']) : 0;

if ($ministereId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID ministère invalide']);
    exit();
}

try {
    $sql = "SELECT idEtablissement, libEtablissement, adrEtablissement 
            FROM etablissement 
            WHERE idMinistere = :ministereId 
            ORDER BY libEtablissement";
    
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':ministereId', $ministereId, PDO::PARAM_INT);
    $stmt->execute();
    
    $etablissements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'etablissements' => $etablissements
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Erreur de base de données'
    ]);
}
?>