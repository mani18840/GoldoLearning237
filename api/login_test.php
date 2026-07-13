<?php
ini_set('display_errors', 1);
session_start();
header('Content-Type: application/json');

require_once 'config.php';

// Simuler un login direct
$stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE email = ?");
$stmt->execute(['igormaniawono@gmail.com']);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['role']    = $user['role'];
    $_SESSION['nom']     = $user['nom'];
    
    echo json_encode([
        'session_set' => true,
        'user_id'     => $_SESSION['user_id'],
        'role'        => $_SESSION['role'],
        'nom'         => $_SESSION['nom']
    ]);
} else {
    echo json_encode(['erreur' => 'Utilisateur non trouvé en base']);
}
?>