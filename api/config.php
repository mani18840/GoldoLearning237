<?php
$host     = '127.0.0.1'; // Utilise 127.0.0.1 au lieu de localhost pour éviter les temps d'attente DNS de Windows
$dbname   = 'goldolearning237'; // ⚠️ REMPLACE PAR LE NOM EXACT DE TA BASE DE DONNÉES SUR PHPMYADMIN
$username = 'root';
$password = ''; // Sur XAMPP par défaut, le mot de passe est VIDE

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3 // Abandonne au bout de 3 secondes si MySQL ne répond pas
        ]
    );
} catch (PDOException $e) {
    // Si la connexion à la BDD échoue, on renvoie une erreur propre en JSON
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Impossible de se connecter à MySQL : ' . $e->getMessage()
    ]);
    exit();
}
?>