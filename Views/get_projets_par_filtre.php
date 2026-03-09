<?php
/**
 * get_projects_details.php
 * Endpoint AJAX — retourne les projets filtrés en JSON
 *
 * Paramètres GET :
 *   type  = gouvernorat | secteur | etablissement | ministere | fournisseur
 *   value = nom de la valeur filtrée (ex: "تونس")
 */

require_once '../Config/Database.php';
require_once '../Config/Security.php';

Security::startSecureSession();
Security::requireLogin();

header('Content-Type: application/json; charset=UTF-8');

$type  = $_GET['type']  ?? '';
$value = $_GET['value'] ?? '';

if (!$type || !$value) {
    echo json_encode([]);
    exit;
}

$database = new Database();
$db = $database->getConnection();

try {
    switch ($type) {

        case 'gouvernorat':
            $sql = "SELECT 
                        p.idPro,
                        p.sujet,
                        p.cout        AS montant,
                        CASE p.etat
                            WHEN 1  THEN 'بصدد الدرس'
                            WHEN 11 THEN 'الإحالة على اللجنة'
                            WHEN 21 THEN 'الإحالة على اللجنة'
                            WHEN 30 THEN 'الموافقة'
                            ELSE 'غير معروف'
                        END AS statut
                    FROM projet p
                    INNER JOIN gouvernorat g ON g.idGov = p.id_Gov
                    WHERE g.libGov = :value
                    ORDER BY p.dateCreation DESC
                    LIMIT 100";
            break;

        case 'secteur':
            $sql = "SELECT 
                        p.idPro,
                        p.sujet,
                        p.cout        AS montant,
                        CASE p.etat
                            WHEN 1  THEN 'بصدد الدرس'
                            WHEN 11 THEN 'الإحالة على اللجنة'
                            WHEN 21 THEN 'الإحالة على اللجنة'
                            WHEN 30 THEN 'الموافقة'
                            ELSE 'غير معروف'
                        END AS statut
                    FROM projet p
                    INNER JOIN gouvernorat g ON g.idGov = p.id_Gov
                    INNER JOIN secteur s     ON s.idSecteur = g.idSecteur
                    WHERE CONCAT('الإقليم ', s.numSecteur) = :value
                    ORDER BY p.dateCreation DESC
                    LIMIT 100";
            break;

        case 'etablissement':
            $sql = "SELECT 
                        p.idPro,
                        p.sujet,
                        p.cout        AS montant,
                        CASE p.etat
                            WHEN 1  THEN 'بصدد الدرس'
                            WHEN 11 THEN 'الإحالة على اللجنة'
                            WHEN 21 THEN 'الإحالة على اللجنة'
                            WHEN 30 THEN 'الموافقة'
                            ELSE 'غير معروف'
                        END AS statut
                    FROM projet p
                    INNER JOIN etablissement e ON e.idEtablissement = p.idEtab
                    WHERE e.libEtablissement = :value
                    ORDER BY p.dateCreation DESC
                    LIMIT 100";
            break;

        case 'ministere':
            $sql = "SELECT 
                        p.idPro,
                        p.sujet,
                        p.cout        AS montant,
                        CASE p.etat
                            WHEN 1  THEN 'بصدد الدرس'
                            WHEN 11 THEN 'الإحالة على اللجنة'
                            WHEN 21 THEN 'الإحالة على اللجنة'
                            WHEN 30 THEN 'الموافقة'
                            ELSE 'غير معروف'
                        END AS statut
                    FROM projet p
                    INNER JOIN ministere m ON m.idMinistere = p.idMinistere
                    WHERE m.libMinistere = :value
                    ORDER BY p.dateCreation DESC
                    LIMIT 100";
            break;

        case 'fournisseur':
            $sql = "SELECT 
                        p.idPro,
                        p.sujet,
                        l.somme       AS montant,
                        CASE p.etat
                            WHEN 1  THEN 'بصدد الدرس'
                            WHEN 11 THEN 'الإحالة على اللجنة'
                            WHEN 21 THEN 'الإحالة على اللجنة'
                            WHEN 30 THEN 'الموافقة'
                            ELSE 'غير معروف'
                        END AS statut
                    FROM projet p
                    INNER JOIN appeloffre ao ON ao.idPro = p.idPro
                    INNER JOIN lot l         ON l.idAppelOffre = ao.idApp
                    INNER JOIN fournisseur f ON f.idFour = l.idFournisseur
                    WHERE f.nomFour = :value
                    ORDER BY p.dateCreation DESC
                    LIMIT 100";
            break;

        default:
            echo json_encode([]);
            exit;
    }

    $stmt = $db->prepare($sql);
    $stmt->bindParam(':value', $value, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([]);
}
?>
