<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use PDO;

class CartController
{
    private PDO $db;
    private Twig $view;
    private array $token;

    public function __construct(PDO $db, Twig $view, array $token) {
        $this->db = $db;
        $this->view = $view;
        $this->token = $token;
    }

    public function createCartbyUser(Request $request, Response $response, $args)
    {
        $username = $args['username'];

        // Check if a cart already exists for the user
        $stmt = $this->db->prepare('SELECT * FROM carts WHERE username = ?');
        $stmt->execute([$username]);
        $existingCart = $stmt->fetch();
        if ($this->token['userId'] == NULL) {
            return $this->view->render($response->withStatus(401), 'error_user_not_found.twig', ['message' => 'User is not authenticated!']);
        }

        $userId = $_SESSION['userId'];

        // Get the user status from the database
        $stmt = $this->db->prepare('SELECT userStatus FROM users WHERE id =?');
        $stmt->execute([$userId]);
        $userStatus = $stmt->fetchColumn();
    
        // Check if the user has permission to delete other users
        if ($userStatus!= 2 || $userStatus!= 1) {
            return $this->view->render($response, 'no_perms.twig', ['message' => 'You do not have permission to delete other users']);
        }

        if ($existingCart) {
            return $this->view->render($response->withStatus(400), 'create_shopping_cart_error.twig', [
                'message' => 'A shopping cart already exists for this user'
            ])->withHeader('Content-Type', 'application/json');
        }

        // Get current date
        $creationDate = date('Y-m-d');

        // Calculate expiration date (1 month after creation)
        $expirationDate = date('Y-m-d', strtotime($creationDate . ' +1 month'));

        // Insert new shopping cart into the database
        $stmt = $this->db->prepare('INSERT INTO carts (username, creation_date, expiration_date, total_price) VALUES (?, ?, ?, 0)');
        $stmt->execute([$username, $creationDate, $expirationDate]);

        // Return success response
        return $this->view->render($response->withStatus(201), 'create_shopping_cart_success.twig', [
            'message' => 'Shopping cart created successfully'
        ])->withHeader('Content-Type', 'application/json');
    }

    public function addItemToShoppingCart(Request $request, Response $response, $args)
    {
        $username = $args['username'];
        $productId = $args['productId'];

        // Check if the user exists
        $stmt = $this->db->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $userId = $stmt->fetchColumn();

        $userId = $_SESSION['userId'];

        // Get the user status from the database
        $stmt = $this->db->prepare('SELECT userStatus FROM users WHERE id =?');
        $stmt->execute([$userId]);
        $userStatus = $stmt->fetchColumn();

        if (!$userId) {
            return $this->view->render($response->withStatus(404), 'user_not_found.twig', [
                'message' => 'User not found'
            ])->withHeader('Content-Type', 'application/json');
        }

        // Check if the user has a shopping cart
        $stmt = $this->db->prepare('SELECT id FROM carts WHERE username = ?');
        $stmt->execute([$username]);
        $cartId = $stmt->fetchColumn();

        // If user doesn't have a shopping cart, throw an error
        if (!$cartId) {
            return $this->view->render($response->withStatus(400), 'no_shopping_cart.twig', [
                'message' => 'User does not have a shopping cart'
            ])->withHeader('Content-Type', 'application/json');
        }

        // Check if the product is already in the shopping cart
        $stmt = $this->db->prepare('SELECT id, quantity FROM products_carts WHERE product_id = ? AND cart_id = ?');
        $stmt->execute([$productId, $cartId]);
        $existingProduct = $stmt->fetch();

        if ($existingProduct) {
            // If the product exists in the cart, increase the quantity
            $newQuantity = $existingProduct['quantity'] + 1;
            $stmt = $this->db->prepare('UPDATE products_carts SET quantity = ? WHERE id = ?');
            $stmt->execute([$newQuantity, $existingProduct['id']]);
        } else {
            // If the product is not in the cart, add it with quantity 1
            $stmt = $this->db->prepare('INSERT INTO products_carts (cart_id, product_id, quantity) VALUES (?, ?, 1)');
            $stmt->execute([$cartId, $productId]);
        }

        return $this->view->render($response->withStatus(201), 'add_item_to_cart_success.twig', [
            'message' => 'Product added to the shopping cart successfully'
        ])->withHeader('Content-Type', 'application/json');
    }

    public function deleteItemFromShoppingCart(Request $request, Response $response, $args)
{
    $username = $args['username'];
    $productId = $args['productId'];

    // Check if the user exists
    $stmt = $this->db->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $userId = $stmt->fetchColumn();

    // Get the user status from the database
    $stmt = $this->db->prepare('SELECT userStatus FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $userStatus = $stmt->fetchColumn();

    if (!$userId) {
        return $this->view->render($response->withStatus(404), 'user_not_found.twig', [
            'message' => 'User not found'
        ])->withHeader('Content-Type', 'application/json');
    }

    // Check if the user has a shopping cart
    $stmt = $this->db->prepare('SELECT id FROM carts WHERE username = ?');
    $stmt->execute([$username]);
    $cartId = $stmt->fetchColumn();

    // If user doesn't have a shopping cart, throw an error
    if (!$cartId) {
        return $this->view->render($response->withStatus(400), 'no_shopping_cart.twig', [
            'message' => 'User does not have a shopping cart'
        ])->withHeader('Content-Type', 'application/json');
    }

    // Check if the product is in the shopping cart
    $stmt = $this->db->prepare('SELECT id, quantity FROM products_carts WHERE product_id = ? AND cart_id = ?');
    $stmt->execute([$productId, $cartId]);
    $existingProduct = $stmt->fetch();

    if (!$existingProduct) {
        return $this->view->render($response->withStatus(404), 'product_not_found.twig', [
            'message' => 'Product not found in the shopping cart'
        ])->withHeader('Content-Type', 'application/json');
    }

    // If the quantity is greater than 1, decrement the quantity
    if ($existingProduct['quantity'] > 1) {
        $newQuantity = $existingProduct['quantity'] - 1;
        $stmt = $this->db->prepare('UPDATE products_carts SET quantity = ? WHERE id = ?');
        $stmt->execute([$newQuantity, $existingProduct['id']]);
    } else {
        // If the quantity is 1, remove the item from the shopping cart
        $stmt = $this->db->prepare('DELETE FROM products_carts WHERE id = ?');
        $stmt->execute([$existingProduct['id']]);
    }

    return $this->view->render($response->withStatus(200), 'delete_item_from_cart_success.twig', [
        'message' => 'Product quantity decreased in the shopping cart successfully'
    ])->withHeader('Content-Type', 'application/json');
}

    public function getShoppingCart(Request $request, Response $response, $args)
{
    $username = $args['username'];

    // Check if the user exists
    $userId = $_SESSION['userId'];
    if (!$userId) {
        return $this->view->render($response->withStatus(404), 'user_not_found.twig', [
            'message' => 'User not found'
        ])->withHeader('Content-Type', 'application/json');
    }

    // Check if the user has a shopping cart
    $stmt = $this->db->prepare('SELECT * FROM carts WHERE username = ?');
    $stmt->execute([$username]);
    $shoppingCart = $stmt->fetch();
    
    if (!$shoppingCart) {
        return $this->view->render($response->withStatus(404), 'no_shopping_cart.twig', [
            'message' => 'No shopping cart found for this user'
        ])->withHeader('Content-Type', 'application/json');
    }

    // Retrieve shopping cart details
    $stmt = $this->db->prepare('SELECT pc.quantity, p.name AS product_name, p.price FROM products_carts pc INNER JOIN products p ON pc.product_id = p.id WHERE pc.cart_id = ?');
    $stmt->execute([$shoppingCart['id']]);
    $cartItems = $stmt->fetchAll();

    // Calculate total price
    $totalPrice = 0;
    foreach ($cartItems as $item) {
        $totalPrice += $item['price'] * $item['quantity'];
    }

    // Format total price to display as currency with two decimal places
    $formattedTotalPrice = number_format($totalPrice, 2);

    // Render success response with shopping cart details
    return $this->view->render($response, 'get_shopping_cart.twig', [
        'username' => $username,
        'creation_date' => $shoppingCart['creation_date'],
        'expiration_date' => $shoppingCart['expiration_date'],
        'total_price' => $formattedTotalPrice,
        'cart_items' => $cartItems
    ])->withHeader('Content-Type', 'application/json');
}

public function getAllShoppingCarts(Request $request, Response $response, $args)
{
    // Retrieve all shopping carts
    $stmt = $this->db->query('SELECT * FROM carts');
    $shoppingCarts = $stmt->fetchAll();

    $userId = $_SESSION['userId'];

    // Get the user status from the database
    $stmt = $this->db->prepare('SELECT userStatus FROM users WHERE id =?');
    $stmt->execute([$userId]);
    $userStatus = $stmt->fetchColumn();

    // Check if the user has permission to view other users
    if ($userStatus != 2) {
        return $this->view->render($response->withStatus(403), 'no_perms.twig', ['message' => 'You do not have permission to view other users']);
    }

    // Check if any shopping carts exist
    if (!$shoppingCarts) {
        return $this->view->render($response->withStatus(404), 'no_shopping_carts.twig', [
            'message' => 'No shopping carts found'
        ])->withHeader('Content-Type', 'application/json');
    }

    // Iterate through shopping carts to retrieve cart items for each
    foreach ($shoppingCarts as &$shoppingCart) {
        $stmt = $this->db->prepare('SELECT pc.quantity, p.name AS product_name, p.price FROM products_carts pc INNER JOIN products p ON pc.product_id = p.id WHERE pc.cart_id = ?');
        $stmt->execute([$shoppingCart['id']]);
        $cartItems = $stmt->fetchAll();

        // Calculate total price for the shopping cart
        $totalPrice = 0;
        foreach ($cartItems as $item) {
            $totalPrice += $item['price'] * $item['quantity'];
        }

        // Format total price to display as currency with two decimal places
        $formattedTotalPrice = number_format($totalPrice, 2);

        // Add cart items and total price to shopping cart data
        $shoppingCart['total_price'] = $formattedTotalPrice;
        $shoppingCart['cart_items'] = $cartItems;
    }

    // Render success response with all shopping cart details
    return $this->view->render($response, 'get_all_shopping_carts.twig', [
        'shopping_carts' => $shoppingCarts
    ])->withHeader('Content-Type', 'application/json');
}


    public function createCart(Request $request, Response $response, $args) {
        print_r($this->token);
        if ($this->token['userId'] == NULL) {
            return $this->view->render($response->withStatus(401), 'error_user_not_found.twig', ['message' => 'User is not authenticated!']);
        }

        $userId = $_SESSION['userId'];

        // Get the user status from the database
        $stmt = $this->db->prepare('SELECT userStatus FROM users WHERE id =?');
        $stmt->execute([$userId]);
        $userStatus = $stmt->fetchColumn();
    
        // Check if the user has permission to delete other users
        if ($userStatus!= 2) {
            return $this->view->render($response, 'no_perms.twig', ['message' => 'You do not have permission to delete other users']);
        }

        $data = $request->getParsedBody();
        print_r($data);
        
        $username = $data['username'] ?? null;
        $productIds = isset($data['productIds']) ? explode(',', $data['productIds']) : null;
        $total_price = $data['total_price'] ?? null;
    
        if ($username === null || $total_price === null) {
            return $this->view->render($response->withStatus(400), 'error.twig', ['message' => 'Missing required data']);
        }
    
        $stmt = $this->db->prepare('INSERT INTO cart (username) VALUES (?)');
        $stmt->execute([$username]);
    
        $cartId = $this->db->lastInsertId();
        print_r($cartId);
    
        foreach ($productIds as $key => $productId) {
            $quantity = isset($quantities[$key]) ? $quantities[$key] : 1;
            
            $pricePerItemStmt = $this->db->prepare('SELECT price FROM products WHERE id = ?');
            $pricePerItemStmt->execute([$productId]);
            $pricePerItem = $pricePerItemStmt->fetchColumn();
            
            $insertStmt = $this->db->prepare('INSERT INTO products_carts (cart_id, product_id, quantity) VALUES (?, ?, ?)');
            $insertStmt->execute([$cartId, $productId, $quantity]);
        }
    
        return $this->view->render($response, 'create_cart_success.twig');
    }
    
    
    

    public function updateCart(Request $request, Response $response, $args) {

        print_r($this->token);
        if ($this->token['userId'] == NULL) {
            return $this->view->render($response->withStatus(401), 'error_user_not_found.twig', ['message' => 'User is not authenticated!']);
        }

        $userId = $_SESSION['userId'];

        // Get the user status from the database
        $stmt = $this->db->prepare('SELECT userStatus FROM users WHERE id =?');
        $stmt->execute([$userId]);
        $userStatus = $stmt->fetchColumn();
    
        // Check if the user has permission to delete other users
        if ($userStatus!= 2) {
            return $this->view->render($response, 'no_perms.twig', ['message' => 'You do not have permission to delete other users']);
        }

        $cartId = $args['orderId'];
        $data = $request->getParsedBody();
    
        $stmt = $this->db->prepare('SELECT * FROM carts WHERE id = ?');
        $stmt->execute([$cartId]);
        $cart = $stmt->fetch(PDO::FETCH_ASSOC);
    
        if (!$cart) {
            return $this->view->render($response->withStatus(404), 'error_update.twig', ['message' => 'Cart not found']);
        } else {
            $username = $data['username'] ?? $cart['username'];
            $creation_date = $data['creation_date'] ?? $cart['creation_date'];
            $expiration_date = $data['expiration_date'] ?? $cart['expiration_date'];
            $totalPrice = $data['total_price'] ?? $cart['total_price'];


            $stmt = $this->db->prepare('UPDATE carts SET username = ?, creation_date = ?, expiration_date = ?, total_price = ?');
            $stmt->execute([$username, $creation_date, $expiration_date, $totalPrice]);
    
            return $this->view->render($response, 'update_cart_success.twig', ['message' => 'Cart updated successfully']);
        }
    }
    

    public function deleteCart(Request $request, Response $response, $args) {
        print_r($this->token);
        if ($this->token['userId'] == NULL) {
            return $this->view->render($response->withStatus(401), 'error_user_not_found.twig', ['message' => 'User is not authenticated!']);
        }

        $userId = $_SESSION['userId'];

        // Get the user status from the database
        $stmt = $this->db->prepare('SELECT userStatus FROM users WHERE id =?');
        $stmt->execute([$userId]);
        $userStatus = $stmt->fetchColumn();
    
        // Check if the user has permission to delete other users
        if ($userStatus!= 2) {
            return $this->view->render($response, 'no_perms.twig', ['message' => 'You do not have permission to delete other users']);
        }

        $cartId = $args['cartId'] ?? null;

        if ($cartId === null) {
            return $this->view->render($response->withStatus(400), 'error_cart_not_found.twig', ['message' => 'Cart ID is missing!']);
        }

        $stmt_products = $this->db->prepare('DELETE FROM products_carts WHERE id = ?');
        $stmt_products->execute([$cartId]);
        
        $stmt = $this->db->prepare('DELETE FROM carts WHERE id = ?');
        $stmt->execute([$cartId]);
        
        return $this->view->render($response, 'delete_cart_success.twig', ['message' => 'Cart deleted successfully!']);
    }


}