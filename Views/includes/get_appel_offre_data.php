<?php
/**
 * Fichier: get_appel_offre_data.php
 * Description: Récupère les données d'un appel d'offre pour le modal d'édition
 */

require_once '../Config/Database.php';
require_once '../Config/Security.php';
require_once '../Config/Permissions.php';

// Démarrer la session sécurisée
Security::startSecureSession();
Security::requireLogin();

// Définir le type de contenu JSON
header('Content-Type: application/json; charset=utf-8');

try {
    // Vérifier que l'ID est fourni
    if (!isset($_GET['id']) || empty($_GET['id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'معرف الصفقة غير موجود'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    $idAppel = intval($_GET['id']);
    
    // Connexion à la base de données
    $database = new Database();
    $db = $database->getConnection();
    
    // ========================================
    // 1. RÉCUPÉRER L'APPEL D'OFFRE
    // ========================================
    $sqlAppel = "SELECT 
                    ao.idApp,
                    ao.idPro,
                    ao.dateCreation,
                    p.sujet AS projetSujet,
                    p.idUser AS projetUserId,
                    d.cheminAcces AS documentPath,
                    d.libDoc AS documentName
                 FROM appeloffre ao
                 INNER JOIN projet p ON ao.idPro = p.idPro
                 LEFT JOIN document d ON d.idExterne = ao.idApp AND d.type = 30
                 WHERE ao.idApp = :idApp";
    
    $stmtAppel = $db->prepare($sqlAppel);
    $stmtAppel->bindParam(':idApp', $idAppel, PDO::PARAM_INT);
    $stmtAppel->execute();
    $appelOffre = $stmtAppel->fetch(PDO::FETCH_ASSOC);
    
    // Vérifier que l'appel d'offre existe
    if (!$appelOffre) {
        echo json_encode([
            'success' => false,
            'message' => 'الصفقة غير موجودة'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // Vérifier les permissions
    if (!Permissions::canEditProjet($appelOffre['projetUserId'])) {
        echo json_encode([
            'success' => false,
            'message' => 'ليس لديك صلاحية لتعديل هذه الصفقة'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // ========================================
    // 2. RÉCUPÉRER LES LOTS
    // ========================================
    $sqlLots = "SELECT 
                    l.lidLot,
                    l.sujetLot,
                    l.idFournisseur,
                    l.somme,
                    f.nomFour AS fournisseurNom
                FROM lot l
                INNER JOIN fournisseur f ON l.idFournisseur = f.idFour
                WHERE l.idAppelOffre = :idApp
                ORDER BY l.lidLot";
    
    $stmtLots = $db->prepare($sqlLots);
    $stmtLots->bindParam(':idApp', $idAppel, PDO::PARAM_INT);
    $stmtLots->execute();
    $lots = $stmtLots->fetchAll(PDO::FETCH_ASSOC);
    
    // Vérifier qu'il y a au moins un lot
    if (empty($lots)) {
        echo json_encode([
            'success' => false,
            'message' => 'لا توجد أقساط لهذه الصفقة'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // ========================================
    // 3. RÉCUPÉRER LA LISTE DES PROJETS
    // ========================================
    $sqlProjets = "SELECT 
                      p.idPro,
                      p.sujet,
                      m.libMinistere,
                      e.libEtablissement
                   FROM projet p
                   LEFT JOIN ministere m ON p.idMinistere = m.idMinistere
                   LEFT JOIN etablissement e ON p.idEtab = e.idEtablissement
                   ORDER BY p.dateCreation DESC";
    
    $stmtProjets = $db->prepare($sqlProjets);
    $stmtProjets->execute();
    $projets = $stmtProjets->fetchAll(PDO::FETCH_ASSOC);
    
    // ========================================
    // 4. RÉCUPÉRER LA LISTE DES FOURNISSEURS
    // ========================================
    $sqlFournisseurs = "SELECT 
                           idFour AS idFournisseur,
                           nomFour AS nomFournisseur
                        FROM fournisseur
                        ORDER BY nomFour";
    
    $stmtFournisseurs = $db->prepare($sqlFournisseurs);
    $stmtFournisseurs->execute();
    $fournisseurs = $stmtFournisseurs->fetchAll(PDO::FETCH_ASSOC);
    
    // ========================================
    // 5. RETOURNER LES DONNÉES
    // ========================================
    echo json_encode([
        'success' => true,
        'appelOffre' => [
            'idApp' => $appelOffre['idApp'],
            'idPro' => $appelOffre['idPro'],
            'dateCreation' => $appelOffre['dateCreation'],
            'projetSujet' => $appelOffre['projetSujet'],
            'documentPath' => $appelOffre['documentPath'],
            'documentName' => $appelOffre['documentName']
        ],
        'lots' => $lots,
        'projets' => $projets,
        'fournisseurs' => $fournisseurs
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    // Erreur de base de données
    error_log("Erreur DB dans get_appel_offre_data.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ في قاعدة البيانات'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // Autre erreur
    error_log("Erreur dans get_appel_offre_data.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ غير متوقع'
    ], JSON_UNESCAPED_UNICODE);
}
?>