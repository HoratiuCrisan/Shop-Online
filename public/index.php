<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use App\Controllers\HomeController;
use App\Controllers\ProductController;
use App\Controllers\UserController;
use App\Controllers\OrderController;
use App\Controllers\CartController;
use App\Middleware\SessionMiddleware;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/Controllers/HomeController.php';
require __DIR__ . '/../src/Controllers/ProductController.php';
require __DIR__ . '/../src/Controllers/UserController.php';
require __DIR__ . '/../src/Middleware/SessionMiddleware.php';
require __DIR__ . '/../src/Controllers/OrderController.php';
require __DIR__ . '/../src/Controllers/CartController.php';
$dbConfig = require __DIR__ . '/../config/db_config.php';

$hostname = $dbConfig['host'];
$username = $dbConfig['username'];
$password = $dbConfig['password'];
$dbname = $dbConfig['dbname'];

try {
    $dsn = "mysql:host={$hostname};dbname={$dbname}";
    $db = new PDO($dsn, $username, $password);

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $app = AppFactory::create();
    $twig = Twig::create('../templates', ['cache' => false]);
    $app->add(new SessionMiddleware());
    $app->get('/', [HomeController::class, 'index']);

    $userController = new UserController($db, $twig); // Instantiate UserControlle

    // User routes
    $app->post('/login', [$userController, 'login']); // Route for user login
    $app->post('/register', [$userController, 'createUser']); // Route for user register
    $app->post('/logout', [$userController, 'logout']); // Route for user logout
    $app->get('/users', [$userController, 'getAll']); // Route to get all users
    $app->get('/users/{username}', [$userController, 'getUserByUsername']);
    $app->put('/users/{id}', [$userController, 'updateUserById']);
    $app->delete('/users/{username}', [$userController, 'deleteUserByUsername']);

    $productController = new ProductController($db, $twig, $_SESSION);

    // Products routes
    $app->get('/products', [$productController, 'getAll']);
    $app->get('/product/sortByQuery', [$productController, 'getByCategory']);
    $app->post('/products', [$productController, 'create']);
    $app->put('/products/{productId}', [$productController, 'update']);
    $app->delete('/products/{productId}', [$productController, 'delete']);

    $orderController = new OrderController($db, $twig, $_SESSION);
    // Orders routes
    $app->get('/orders', [$orderController, 'getOrders']);
    $app->get('/orders/{user_Id}', [$orderController, 'getByUserId']);
    $app->post('/orders', [$orderController, 'createOrder']);
    $app->put('/orders/{orderId}', [$orderController, 'updateOrder']);
    $app->delete('/orders/{orderId}', [$orderController, 'deleteOrder']);

    $cartController = new CartController($db, $twig, $_SESSION);
    $app->post('/users/{username}/shopping_cart', [$cartController, 'createCartbyUser']);
    $app->post('/users/{username}/shopping_cart/{productId}', [$cartController, 'addItemToShoppingCart']);
    $app->delete('/users/{username}/shopping_cart/{productId}', [$cartController, 'deleteItemFromShoppingCart']);
    $app->get('/users/{username}/shopping_cart', [$cartController, 'getShoppingCart']);
    $app->get('/all_carts', [$cartController, 'getAllShoppingCarts']);
    $app->add(TwigMiddleware::create($app, $twig));

    $app->run();
} catch (PDOException $e) {
    echo 'Connection failed: ' . $e->getMessage();
}

?>