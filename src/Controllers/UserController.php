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
        session_start();
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
    // Store session token and other user data in session
    $_SESSION['userId'] = $user['id']; // Store the user ID in session
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
    // Store session token and user ID in session
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
    // Get the user ID from the session
    $userId = $_SESSION['userId'];

    // Get the user status from the database
    $stmt = $this->db->prepare('SELECT userStatus FROM users WHERE id =?');
    $stmt->execute([$userId]);
    $userStatus = $stmt->fetchColumn();

    // Check if the user has permission to view all users
    if ($userStatus!= 2) {
        return $this->view->render($response, 'no_perms.twig', ['message' => 'You do not have permission to view all users']);
    }

    $stmt = $this->db->query('SELECT * FROM users');
    $users = $stmt->fetchAll();

    return $this->view->render($response, 'users.twig', ['users' => $users]);
}

    public function getUserByUsername(Request $request, Response $response, $args)
{
    // Get the user ID from the session
    $userId = $_SESSION['userId'];

    // Get the user status from the database
    $stmt = $this->db->prepare('SELECT userStatus FROM users WHERE id =?');
    $stmt->execute([$userId]);
    $userStatus = $stmt->fetchColumn();

    // Check if the user has permission to view other users
    if ($userStatus != 2) {
        return $this->view->render($response->withStatus(403), 'no_perms.twig', ['message' => 'You do not have permission to view other users']);
    }

    // Get the username from the URL parameters
    $username = $args['username'];

    // Prepare and execute the SQL query to select user data by username
    $stmt = $this->db->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // Check if user exists
    if (!$user) {
        // User not found, render an error message
        return $this->view->render($response->withStatus(404), 'user_not_found.twig', ['message' => 'User not found']);
    }

    // User found, render the user information
    return $this->view->render($response, 'user_details.twig', ['user' => $user]);
}

// Method to update user information based on username
public function updateUserById(Request $request, Response $response, $args)
{
    // Get the user ID from the session
    $userId = $_SESSION['userId'];

    // Get the user status from the database
    $stmt = $this->db->prepare('SELECT userStatus FROM users WHERE id =?');
    $stmt->execute([$userId]);
    $userStatus = $stmt->fetchColumn();

    // Check if the user has permission to update other users
    if ($userStatus != 2) {
        return $this->view->render($response->withStatus(403), 'no_perms.twig', ['message' => 'You do not have permission to update other users']);
    }

    // Get the user ID from the URL parameters
    $id = $args['id'];

    // Retrieve updated user data from the request body
    // Retrieve updated user data from the request body
    $requestBody = $request->getBody()->getContents();
error_log('Request Body: ' . $requestBody);

// Try to parse the request body manually
parse_str($requestBody, $parsedBody);
error_log('Parsed Body: ' . print_r($parsedBody, true));

// Check if $parsedBody is empty or not an array
if (empty($parsedBody) || !is_array($parsedBody)) {
    error_log('Received invalid data for update: ' . print_r($parsedBody, true));
    return $this->view->render($response->withStatus(400), 'update_user_error.twig', ['message' => 'Invalid data sent for update']);
}

// Use the parsed body as user data
$userData = $parsedBody;


    // Check if $userData is null or not an array
    if ($userData === null || !is_array($userData)) {
        // Log the received data
        error_log('Invalid data sent for update. Received: ' . print_r($userData, true));
        
        // Prepare error message
        $errorMessage = 'Invalid data sent for update. Please check the error log for details.';
        return $this->view->render($response->withStatus(400), 'update_user_error.twig', ['message' => $errorMessage]);
    }

    // Initialize arrays to hold placeholders and values for the SQL query
    $placeholders = [];
    $values = [];

    // Iterate over the fields in $userData
    foreach ($userData as $field => $value) {
        // Check if the field is valid and not empty
        if (!empty($value)) {
            // Add the field to the placeholders array
            $placeholders[] = "$field = ?";
            // Add the value to the values array
            $values[] = $field === 'password' ? password_hash($value, PASSWORD_BCRYPT) : $value;
        }
    }

    // Check if any fields were provided for update
    if (empty($placeholders)) {
        // No fields provided for update, return an error
        return $this->view->render($response->withStatus(400), 'update_user_error.twig', ['message' => 'No fields provided for update']);
    }

    // Prepare the SQL query with dynamic placeholders
    $sql = 'UPDATE users SET ' . implode(', ', $placeholders) . ' WHERE id = ?';
    $values[] = $id;

    // Execute the SQL query
    $stmt = $this->db->prepare($sql);
    $stmt->execute($values);

    // Render a success message
    return $this->view->render($response, 'update_user_success.twig', ['message' => 'User updated successfully']);
}


// Method to delete user based on username
public function deleteUserByUsername(Request $request, Response $response, $args)
{
    // Get the user ID from the session
    $userId = $_SESSION['userId'];

    // Get the user status from the database
    $stmt = $this->db->prepare('SELECT userStatus FROM users WHERE id =?');
    $stmt->execute([$userId]);
    $userStatus = $stmt->fetchColumn();

    // Check if the user has permission to delete other users
    if ($userStatus != 2) {
        return $this->view->render($response->withStatus(403), 'no_perms.twig', ['message' => 'You do not have permission to delete other users']);
    }

    // Get the username from the URL parameters
    $username = $args['username'];

    // Prepare and execute the SQL query to delete the user
    $stmt = $this->db->prepare('DELETE FROM users WHERE username = ?');
    $stmt->execute([$username]);

    // Render a success message
    return $this->view->render($response, 'delete_user_success.twig', ['message' => 'User deleted successfully']);
}


}
?>