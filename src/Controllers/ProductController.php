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

        return $this->view->render($response, 'products.twig', ['products' => $products]);
    }

    public function getByCategory(Request $request, Response $response, $args) {
        $queryParams = $request->getQueryParams();
        $category = $queryParams['category'] ?? null;
        $sortOrder = $queryParams['order'] ?? null;

        if (!$category) {
            return $this->view->render($response->withStatus(404), 'error_not_found.twig', ['message' => 'Product not found']);
        }
        

        $sql = 'SELECT * FROM products WHERE category = ?';

        if ($sortOrder) {
            $sql .= ' ORDER BY ' . $sortOrder;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$category]);
        $products = $stmt->fetchAll();

        return $this->view->render($response, 'products.twig', ['products' => $products]);
    }

    public function create(Request $request, Response $response, $args) {
        print_r($this->token);
        if ($this->token['userId'] == NULL) {
            return $this->view->render($response->withStatus(401), 'error_not_found.twig', ['message' => 'User is not authenticated!']);
        }

        $data = $request->getParsedBody();
    
        $name = $data['name'];
        $category = $data['category'];
        $photoUrl = $data['photoUrl'];
        $quantity = floatval($data['quantity']);
        $description = $data['description'];
        $price = floatVal($data['price']);
        $discount = floatVal($data['discount']);

        $sql = 'SELECT * FROM products WHERE name = ?';
        $sql .=  $name;

        if ($sql) {
            return $this->view->render($response->withStatus(422), 'error_not_found.twig', ['message' => 'Product already exists!']);
        } 
    
        $stmt = $this->db->prepare('INSERT INTO products (name, category, photoUrl, quantity, description, price, discount) VALUES (?, ?, ?, ?, ?, ?, ?)');
        
        $stmt->execute([$name, $category, $photoUrl, $quantity, $description, $price, $discount]);
    
        return $this->view->render($response, 'create_success.twig');
    }

    public function update(Request $request, Response $response, $args) {
        $productId = $args['productId'];
        $data = $request->getParsedBody();

        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            return $this->view->render($response->withStatus(404), 'error_update.twig', ['message' => 'Product not found']);
        } 

        

        $stmt = $this->db->prepare('UPDATE products SET name = ?, category = ?, quantity = ?, description = ?, price = ?, photoUrl = ?, discount = ? WHERE id = ?');
        $stmt->execute([$product['name'], $product['category'], $product['quantity'], $product['description'], $product['price'], $product['photoUrl'], $product['discount'], $productId]);

        return $this->view->render($response, 'update_product_success.twig', ['message' => 'Product updated successfully']);
    } 

    public function delete(Request $request, Response $response, $args) {
        $productId = $args['productId'];

        $stmt = $this->db->prepare('DELETE FROM products WHERE id = ?');
        $stmt->execute([$productId]);

        return $this->view->render($response, 'update_product_success.twig', ['message' => 'Producct deleted successfuly!']);
    }
}

?>