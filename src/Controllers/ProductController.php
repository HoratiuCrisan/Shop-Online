<?php
declare(strict_types = 1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Middleware\SessionMiddleware;
use Slim\Views\Twig;
use PDO;

class ProductController {
    private PDO $db;
    private Twig $view;
    private array $token;

    public function __construct(PDO $db, Twig $view, array $token) {
        $this->db = $db;
        $this->view = $view;
        $this->token = $token;
    }



    public function getAll(Request $request, Response $response, $args) {
        $stmt = $this->db->query('SELECT * FROM products');
        $products = $stmt->fetchAll();

        //return a twig file with the products
        return $this->view->render($response, 'products.twig', ['products' => $products]);
    }

    public function getByCategory(Request $request, Response $response, $args) {
        $queryParams = $request->getQueryParams();
        
        // Get category from query parameters
        $category = isset($queryParams['category']) ? $queryParams['category'] : NULL;
    
        // Get order from query parameters
        $order = isset($queryParams['order']) ? $queryParams['order'] : NULL;
    
        if (!$category && !$order) {
            return $this->view->render($response->withStatus(404), 'error_not_found.twig', ['message' => 'Product not found']);
        }
        
        $sql = 'SELECT * FROM products WHERE category = ?';
        if ($order) {
            $sql .= ' ORDER BY ' . $order;
        }
        
        // Prepare and execute the query
        $stmt = $this->db->prepare($sql);
        if (!$stmt->execute([$category])) {
            return $this->view->render($response->withStatus(420), './opeartion_not_executed.twig', ['products' => 'Could not get filtered products']);
        }
    
        // Fetch the products
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
        return $this->view->render($response->withStatus(200), 'products.twig', ['products' => $products]);
    }

    public function create(Request $request, Response $response, $args) {
        if ($this->checkAuthorization($request, $response) == FALSE) {
            return $this->view->render($response->withStatus(401), './user/unauthorized_user.twig', ['message' => 'Error: Unauthorized user']);
        }

        $data = $request->getParsedBody();
        // Get body elements 
        $name = $data['name'];
        $category = $data['category'];
        $photoUrl = $data['photoUrl'];
        $quantity = floatval($data['quantity']);
        $description = $data['description'];
        $price = floatVal($data['price']);
        $discount = floatVal($data['discount']);

        // check to see if a product with this name already exists
        $stmt = $this->db->prepare('SELECT * FROM products WHERE name = ?');
        $stmt->execute([$name]);
        $existingProduct = $stmt->fetch(PDO::FETCH_ASSOC);

        // if product exists return error message and twig file
        if ($existingProduct) {
            return $this->view->render($response->withStatus(409), './products/product_exists.twig', ['message' => 'Product already exists!']);
        }
        
        // proceed to create the product
        $stmt = $this->db->prepare('INSERT INTO products (name, category, photoUrl, quantity, description, price, discount) VALUES (?, ?, ?, ?, ?, ?, ?)');
        
        // try creating the product
        if ($stmt->execute([$name, $category, $photoUrl, $quantity, $description, $price, $discount])) {
            return $this->view->render($response->withStatus(200), './products/create_product_successfully.twig', ['message' => 'Product created successfully']);
        } else {
            return $this->view->render($response->withStatus(420), './products/error_create_product.twig', ['message' => 'Validation exception']);
        }
    }

    public function update(Request $request, Response $response, $args) {
        // Checks for the user token in order to get the 
        if ($this->checkAuthorization($request, $response) == FALSE) {
            return $this->view->render($response->withStatus(401), './user/unauthorized_user.twig', ['message' => 'Error: Unauthorized user']);
        }

        $productId = $args['productId'];
        $data = $request->getBody()->getContents();

        // get product by id
        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            return $this->view->render($response->withStatus(404), './products/product_not_found.twig', ['message' => 'Product not found']);
        } 

        $name = isset($data['name']) ? $data['name'] : $product['name'];
        $category = isset($data['category']) ? $data['category'] : $product['category'];
        $quantity = isset($data['quantity']) ? $data['quantity'] : $product['quantity'];
        $price = isset($data['price']) ? $data['price'] : $product['price'];
        $description = isset($data['description']) ? $data['description'] : $product['description'];
        $discount = isset($data['discount']) ? $data['discount'] : $product['discount'];
        $photoUrl = isset($data['photoUrl']) ? $data['photoUrl'] : $product['photoUrl'];

        //print_r($data);
    
        $stmt = $this->db->prepare('UPDATE products SET name = ?, category = ?, quantity = ?, description = ?, price = ?, photoUrl = ?, discount = ? WHERE id = ?');
        $stmt->execute([$name, $category, $quantity, $description, $price, $photoUrl, $discount, $productId]);
        
        return $this->view->render($response->withStatus(200), 'update_product_success.twig', ['message' => 'Product updated successfully']);
    } 

    public function delete(Request $request, Response $response, $args) {
        // Checks for the user token in order to get the 
        if ($this->checkAuthorization($request, $response) == FALSE) {
            return $this->view->render($response->withStatus(401), './user/unauthorized_user.twig', ['message' => 'Error: Unauthorized user']);
        }

        $productId = $args['productId'];

        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$productId]);

        if ($stmt->fetchAll() == NULL) {
            return $this->view->render($response->withStatus(404), './products/error_product_not_found.twig', ['message' => 'Error: Product not found']);
        } 

        $stmt = $this->db->prepare('DELETE FROM products WHERE id = ?');
        $stmt->execute([$productId]);

        return $this->view->render($response->withStatus(200), 'update_product_success.twig', ['message' => 'Producct deleted successfuly!']);
    }


    public function checkAuthorization(Request $request, Response $response) {
        $userToken = $this->token['userId']; // geting the user id from the token
        // if the id is null return unauthorized twig template and error
        if ($userToken != NULL) {
            //
            
            // if user is logged in check for the status (1 - user, 2 - admin)
            $stmt = $this->db->prepare('SELECT userStatus FROM users WHERE id = ?');
            $stmt->execute([$userToken]); 

            $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

            // if no user status was found, return unauthorized twig template and error
            if (!$existingUser) {
               // return $this->view->render($response->withStatus(401), './user/unauthorized_user.twig', ['message' => 'Error: Unauthorized user']);
                return FALSE;
            }

            // if the user satatus is 1, return unauthorized tiwg template and error
            if ($existingUser['userStatus'] == 1) {
                //return $this->view->render($response->withStatus(401), './user/unauthorized_user.twig', ['message' => 'Error: Unauthorized user']);
                return FALSE;
            
            }
        } else {
            return FALSE;
        }

        return TRUE;
    }
}


?>