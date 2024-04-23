<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use PDO;

class UserController
{
    private PDO $db;
    private Twig $view;
    private int $sessionDuration; 

    public function __construct(PDO $db, Twig $view)
    {
        $this->db = $db;
        $this->view = $view;
        $this->sessionDuration = 1209600; //14 zile
        $this->checkSessionExpiration();
    }
    
    //Verifica token
    private function checkSessionExpiration()
    {
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $this->sessionDuration) {
            // Session expired, destroy session
            session_unset();
            session_destroy();
        }
        $_SESSION['last_activity'] = time(); // Update last activity time
    }

    // Login
    // Login
    public function login(Request $request, Response $response, $args)
{
    // Retrieve username and password from the request
    $loginData = $request->getParsedBody();
    $username = isset($loginData['username']) ? $loginData['username'] : null;
    $password = isset($loginData['password']) ? $loginData['password'] : null;

    // Check if both username and password are provided
    if (!$username || !$password) {
        error_log('Error: Username and password are required');
        return $this->view->render($response->withStatus(400), 'login_query_error.twig', [
            'error' => 'Username and password are required',
            'username' => $username,
            'password' => $password
        ])->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    // Perform database query to fetch user data based on username
    $stmt = $this->db->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // Check if user exists
    if (!$user) {
        error_log('Error: Invalid username');
        return $this->view->render($response->withStatus(401), 'login_user_error.twig', [
            'error' => 'Invalid username',
            'username' => $username,
            'password' => $password
        ])->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    // Get the hashed password from the database
    $passwordFromDb = $user['password'];
    // Manually verify the password
    if ($password !== $passwordFromDb) {
        // Passwords don't match, render error message
        error_log('Error: Invalid password');
        return $this->view->render($response->withStatus(401), 'login_query_error.twig', [
            'error' => 'Invalid password',
            'username' => $username,
            'password' => $password
        ])->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    // Passwords match, proceed with login
    $sessionToken = uniqid();

    // Store session token and other user data in session
    $_SESSION['sessionToken'] = $sessionToken;
    $_SESSION['userId'] = $user['id']; // Store the user ID in session
    print_r($_SESSION);
    // Redirect to dashboard on successful login
    return $this->view->render($response, 'login_user_success.twig', [
        'userId' => $user['id'], // Pass the user ID to the template
        'username' => $username
    ])->withHeader('Content-Type', 'text/html; charset=UTF-8');
}

    // Method for user registration
    public function createUser(Request $request, Response $response, $args)
{
    $registerData = $request->getParsedBody();

    // Extract registration data
    $username = $registerData['username'];
    $email = $registerData['email'];
    $password = $registerData['password'];
    $phone = $registerData['phone'];
    $address = $registerData['address'];
    $firstName = $registerData['firstName'];
    $lastName = $registerData['lastName'];

    // Check if username already exists
    $stmt = $this->db->prepare('SELECT COUNT(*) AS count FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $resultUsername = $stmt->fetch();

    // Check if email already exists
    $stmt = $this->db->prepare('SELECT COUNT(*) AS count FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $resultEmail = $stmt->fetch();

    if ($resultUsername['count'] > 0) {
        // Username already exists, return an error
        return $this->view->render($response, 'register_user_error.twig', [
            'error' => 'Username already exists in the database'
        ]);
    }

    if ($resultEmail['count'] > 0) {
        // Email already exists, return an error
        return $this->view->render($response, 'register_user_error.twig', [
            'error' => 'Email already exists in the database'
        ]);
    }

    // If no errors, proceed with creating the user

    // Prepare and execute the SQL query to insert the new user
    $stmt = $this->db->prepare('INSERT INTO users (username, email, password, phone, address, userStatus, firstName, lastName) VALUES (?, ?, ?, ?, ?, 1, ?, ?)');
    $stmt->execute([$username, $email, $password, $phone, $address, $firstName, $lastName]);

    // Get the user ID of the newly created user
    $userId = $this->db->lastInsertId();

    // Generate a session token
    $sessionToken = uniqid();

    // Store session token and user ID in session
    $_SESSION['sessionToken'] = $sessionToken;
    $_SESSION['userId'] = $userId;

    // Redirect to dashboard or any other appropriate page
    return $this->view->render($response, 'register_user_success.twig', [
        'username' => $username,
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
        'userStatus' => 1, // Set userStatus to 1 by default
        'firstName' => $firstName,
        'lastName' => $lastName,
        'userId' => $userId // Pass the user ID to the template
    ]);
}



    // Logout
    public function logout(Request $request, Response $response, $args)
{
    // Check if session is active before attempting to destroy it
    if (session_status() === PHP_SESSION_ACTIVE) {
        // Invalidate session token and clear session data
        session_unset();
        session_destroy();
    }

    // Redirect to login page after logout
    return $this->view->render($response, 'logout.twig', ['message' => 'Logged out successfully']);
}

    // Method to get all users
    public function getAll(Request $request, Response $response, $args)
    {
        $stmt = $this->db->query('SELECT * FROM users');
        $users = $stmt->fetchAll();

        return $this->view->render($response, 'users.twig', ['users' => $users]);
    }

    // Method to update an existing user
    public function updateUser(Request $request, Response $response, $args)
    {
        $username = $args['username'];
        $userData = $request->getParsedBody();

        $stmt = $this->db->prepare('UPDATE users SET email = ?, password = ?, phone = ?, address = ?, userStatus = ?, firstName = ?, lastName = ? WHERE username = ?');

        $stmt->execute([
            $userData['email'],
            password_hash($userData['password'], PASSWORD_BCRYPT), // Hash the password
            $userData['phone'],
            $userData['address'],
            $userData['userStatus'],
            $userData['firstName'],
            $userData['lastName'],
            $username
        ]);

        return $this->view->render($response, 'update_user_success.twig', ['message' => 'User updated successfully']);
    }

    // Method to delete an existing user
    public function deleteUser(Request $request, Response $response, $args)
    {
        $username = $args['username'];

        $stmt = $this->db->prepare('DELETE FROM users WHERE username = ?');
        $stmt->execute([$username]);

        return $this->view->render($response, 'delete_user_success.twig', ['message' => 'User deleted successfully']);
    }
}
?>