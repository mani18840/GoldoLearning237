<?php
ini_set('display_errors', 0);
error_reporting(0);
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');

$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';

if ($action === 'login') {
    require_once 'config.php';
    $email    = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    
    $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user || !password_verify($password, $user['mot_de_passe'])) {
        echo json_encode(['success' => false, 'message' => 'Email ou mot de passe incorrect']);
        exit;
    }
    
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['nom']     = $user['nom'];
    $_SESSION['role']    = $user['role'];
    
    echo json_encode([
        'success' => true,
        'user'    => ['id' => $user['id'], 'nom' => $user['nom'], 'role' => $user['role']]
    ]);
    exit;
}

if ($action === 'check_session') {
    if (isset($_SESSION['user_id'])) {
        echo json_encode([
            'success'   => true,
            'connected' => true,
            'user'      => ['id' => $_SESSION['user_id'], 'nom' => $_SESSION['nom'], 'role' => $_SESSION['role']]
        ]);
    } else {
        echo json_encode(['success' => true, 'connected' => false]);
    }
    exit;
}

if ($action === 'logout') {
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'register') {
    require_once 'config.php';
    $nom      = trim($data['nom'] ?? '');
    $email    = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    $role     = $data['role'] ?? '';

    if (empty($nom) || empty($email) || empty($password) || empty($role)) {
        echo json_encode(['success' => false, 'message' => 'Tous les champs sont obligatoires']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Cet email est déjà utilisé']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT INTO utilisateurs (nom, email, mot_de_passe, role) VALUES (?, ?, ?, ?)");
    $stmt->execute([$nom, $email, $hash, $role]);

    echo json_encode(['success' => true, 'message' => 'Inscription réussie']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Action non reconnue']);
?>