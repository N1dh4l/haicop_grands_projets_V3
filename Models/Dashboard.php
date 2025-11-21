<?php
class Dashboard {
    private $conn;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    // Statistiques principales
    public function getStats() {
        $stats = array();
        
        // Nombre total de projets
        $query = "SELECT COUNT(*) as total FROM projet";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $stats['total_projets'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Projets en attente
        $query = "SELECT COUNT(*) as total FROM projet WHERE etat = 0";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $stats['projets_attente'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Projets en cours
        $query = "SELECT COUNT(*) as total FROM projet WHERE etat = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $stats['projets_encours'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Appels d'offres
        $query = "SELECT COUNT(*) as total FROM appeloffre";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $stats['appels_offre'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Commissions
        $query = "SELECT COUNT(*) as total FROM commission";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $stats['commissions'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        return $stats;
    }
    
    // Liste des projets de la commission
    public function getCommissionProjects() {
        $query = "SELECT COUNT(*) as total_programme, 
                         COUNT(*) as total_extraordinaire 
                  FROM projetcommission";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>