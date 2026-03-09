<?php
class Dashboard {
    private $conn;
    private $table_projet = "projet";
    private $table_gouvernorat = "gouvernorat";
    private $table_secteur = "secteur";
    private $table_fournisseur = "fournisseur";
    private $table_lot = "lot";
    private $table_appeloffre = "appeloffre";

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getStats() {
    $stats = array();

    // العدد الجملي للمقترحات
    $query = "SELECT COUNT(*) as total FROM " . $this->table_projet;
    $stmt = $this->conn->prepare($query);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_projets'] = $row['total'];

    // الإحالة على اللجنة 
    $query = "SELECT COUNT(*) as total FROM " . $this->table_projet . " WHERE etat = 2 ";
    $stmt = $this->conn->prepare($query);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['projets_encours'] = $row['total'];

    // commissions - reste inchangé
    $query = "SELECT COUNT(*) as total FROM " . $this->table_projet . " WHERE etat = 3 ";
    $stmt = $this->conn->prepare($query);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['commissions'] = $row['total'];

    // الموافقة (naturePc = 23)
    $query = "SELECT COUNT(*) as total FROM " . $this->table_projet . " WHERE etat = 4 ";
    $stmt = $this->conn->prepare($query);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['appels_offre'] = $row['total'];
    
    // بصدد الدرس = total_projets - الإحالة على اللجنة - الموافقة
    $stats['projets_attente'] = $stats['total_projets'] - $stats['projets_encours'] - $stats['appels_offre']- $stats['commissions'];

    

    return $stats;
}


    public function getProjetsByGouvernorat() {
        $query = "SELECT g.libGov as nomGouvernorat, COUNT(p.idPro) as nombre_projets
                  FROM " . $this->table_gouvernorat . " g
                  LEFT JOIN " . $this->table_projet . " p ON g.idGov = p.id_Gov
                  GROUP BY g.idGov, g.libGov
                  ORDER BY nombre_projets DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        $data = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $data[] = array(
                'gouvernorat' => $row['nomGouvernorat'],
                'nombre_projets' => (int)$row['nombre_projets']
            );
        }
        
        return $data;
    }

    public function getProjetsBySecteur() {
        $query = "SELECT 
                    CONCAT('الإقليم ', s.numSecteur) as libSecteur,
                    COUNT(DISTINCT p.idPro) as nombre_projets
                  FROM " . $this->table_secteur . " s
                  LEFT JOIN " . $this->table_gouvernorat . " g ON s.idSecteur = g.idSecteur
                  LEFT JOIN " . $this->table_projet . " p ON g.idGov = p.id_Gov
                  GROUP BY s.idSecteur, s.numSecteur
                  ORDER BY nombre_projets DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        $data = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $data[] = array(
                'secteur' => $row['libSecteur'],
                'nombre_projets' => (int)$row['nombre_projets']
            );
        }
        
        return $data;
    }

    public function getFournisseursProjets() {
        $query = "SELECT 
                    f.nomFour as nomFournisseur,
                    COUNT(DISTINCT l.lidLot) as nombre_lots,
                    COUNT(DISTINCT a.idPro) as nombre_projets
                  FROM " . $this->table_fournisseur . " f
                  LEFT JOIN " . $this->table_lot . " l ON f.idFour = l.idFournisseur
                  LEFT JOIN " . $this->table_appeloffre . " a ON l.idAppelOffre = a.idApp
                  GROUP BY f.idFour, f.nomFour
                  HAVING nombre_projets > 0
                  ORDER BY nombre_projets DESC
                  LIMIT 10";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        $data = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $data[] = array(
                'fournisseur' => $row['nomFournisseur'],
                'nombre_projets' => (int)$row['nombre_projets'],
                'nombre_lots' => (int)$row['nombre_lots']
            );
        }
        
        return $data;
    }

    public function getCommissionProjects() {
        $query = "SELECT 
                    p.sujet as libPro,
                    c.numCommission,
                    c.dateCommission,
                    pc.naturePc as decision
                  FROM projetcommission pc
                  INNER JOIN " . $this->table_projet . " p ON pc.idPro = p.idPro
                  INNER JOIN commission c ON pc.idCom = c.idCom
                  ORDER BY c.dateCommission DESC
                  LIMIT 10";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getProjetsByEtablissement() {
        $query = "SELECT 
                    e.libEtablissement,
                    COUNT(p.idPro) as nombre_projets
                  FROM etablissement e
                  LEFT JOIN projet p ON e.idEtablissement = p.idEtab
                  WHERE e.libEtablissement != 'الوزارة'
                  GROUP BY e.idEtablissement, e.libEtablissement
                  HAVING nombre_projets > 0
                  ORDER BY nombre_projets DESC
                  LIMIT 10";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        $data = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $data[] = array(
                'etablissement' => $row['libEtablissement'],
                'nombre_projets' => (int)$row['nombre_projets']
            );
        }
        
        return $data;
    }

    public function getProjetsByMinistere() {
        $query = "SELECT 
                    m.libMinistere,
                    COUNT(p.idPro) as nombre_projets
                  FROM ministere m
                  LEFT JOIN projet p ON m.idMinistere = p.idMinistere
                  GROUP BY m.idMinistere, m.libMinistere
                  HAVING nombre_projets > 0
                  ORDER BY nombre_projets DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        $data = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $data[] = array(
                'ministere' => $row['libMinistere'],
                'nombre_projets' => (int)$row['nombre_projets']
            );
        }
        
        return $data;
    }
}
?>