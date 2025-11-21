<?php
class User {
    private $conn;
    private $table = "user";
    
    public $idUser;
    public $nomUser;
    public $emailUser;
    public $typeCpt;
    public $login;
    public $pw;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    // Authentification utilisateur
    public function authenticate($login, $password) {
        $query = "SELECT idUser, nomUser, emailUser, typeCpt, login, pw 
                  FROM " . $this->table . " 
                  WHERE login = :login 
                  LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":login", $login);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (Security::verifyPassword($password, $row['pw'])) {
                $this->idUser = $row['idUser'];
                $this->nomUser = $row['nomUser'];
                $this->emailUser = $row['emailUser'];
                $this->typeCpt = $row['typeCpt'];
                $this->login = $row['login'];
                return true;
            }
        }
        return false;
    }
    
    // Enregistrer action dans journal
    public function logAction($action) {
        $query = "INSERT INTO journal (idUser, action, date) 
                  VALUES (:idUser, :action, CURDATE())";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":idUser", $this->idUser);
        $stmt->bindParam(":action", $action);
        return $stmt->execute();
    }
}
?>